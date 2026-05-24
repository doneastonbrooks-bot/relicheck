// Phase 5: HTTPS client for relichecksurvey.com plus macOS Keychain
// token storage.
//
// Exposes four Tauri commands the webview can invoke:
//
//   survey_start()                   -> { user_code, device_code,
//                                         verification_url, expires_in,
//                                         interval }
//   survey_poll(device_code)         -> { status: "pending" | "ok" | "expired",
//                                         user?: { id, email, name } }
//                                         (on "ok" we save the token to
//                                         the keychain, the webview never
//                                         sees it)
//   survey_list()                    -> { surveys: [{ id, slug, title,
//                                                     response_count }] }
//   survey_responses(survey_id)      -> { csv: "..." }   (CSV text)
//
// Token lives in macOS Keychain under service `com.relicheck.mm-studio`,
// account `default`. Survives app restarts. Removed by survey_logout().

use std::sync::OnceLock;

use security_framework::passwords;
use serde::{Deserialize, Serialize};

const KEYCHAIN_SERVICE: &str = "com.relicheck.mm-studio";
const KEYCHAIN_ACCOUNT: &str = "default";
const BASE_URL: &str = "https://relichecksurvey.com";

// ---------------------------------------------------------------------------
// One shared reqwest client. Lazily initialized.
// ---------------------------------------------------------------------------

fn http() -> reqwest::Client {
    static CLIENT: OnceLock<reqwest::Client> = OnceLock::new();
    CLIENT
        .get_or_init(|| {
            reqwest::Client::builder()
                .user_agent("ReliCheck-MM-Studio-Mac/0.1")
                .timeout(std::time::Duration::from_secs(30))
                .build()
                .expect("http client init")
        })
        .clone()
}

// ---------------------------------------------------------------------------
// Keychain wrappers
// ---------------------------------------------------------------------------

fn keychain_get_token() -> Option<String> {
    passwords::get_generic_password(KEYCHAIN_SERVICE, KEYCHAIN_ACCOUNT)
        .ok()
        .and_then(|bytes| String::from_utf8(bytes).ok())
}

fn keychain_set_token(token: &str) -> Result<(), String> {
    passwords::set_generic_password(KEYCHAIN_SERVICE, KEYCHAIN_ACCOUNT, token.as_bytes())
        .map_err(|e| format!("keychain write: {}", e))
}

fn keychain_clear_token() -> Result<(), String> {
    match passwords::delete_generic_password(KEYCHAIN_SERVICE, KEYCHAIN_ACCOUNT) {
        Ok(_) => Ok(()),
        Err(e) => {
            // "item not found" is success for our purposes
            let msg = format!("{}", e);
            if msg.contains("not found") || msg.contains("-25300") {
                Ok(())
            } else {
                Err(format!("keychain delete: {}", e))
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Wire shapes
// ---------------------------------------------------------------------------

#[derive(Debug, Serialize, Deserialize)]
pub struct DeviceStart {
    pub user_code: String,
    pub device_code: String,
    pub verification_url: String,
    pub expires_in: u32,
    pub interval: u32,
}

#[derive(Debug, Serialize)]
pub struct PollOut {
    pub status: String, // "pending" | "ok" | "expired" | "error"
    pub user: Option<UserSummary>,
    pub message: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct UserSummary {
    pub id: i64,
    pub email: String,
    pub name: String,
}

#[derive(Debug, Serialize)]
pub struct SurveyListOut {
    pub surveys: Vec<SurveyItem>,
}

#[derive(Debug, Serialize, Deserialize)]
pub struct SurveyItem {
    pub id: i64,
    pub slug: String,
    pub title: String,
    #[serde(default)]
    pub response_count: i64,
}

#[derive(Debug, Serialize)]
pub struct ResponsesOut {
    /// Temp filesystem path the CSV was written to. Callers feed this
    /// to engine_call(op="ingest.sniff", args={path}) the same way they
    /// would a user-selected file.
    pub csv_path: String,
    pub row_count: usize,
}

#[derive(Debug, Serialize)]
pub struct StatusOut {
    pub signed_in: bool,
    pub user: Option<UserSummary>,
}

// ---------------------------------------------------------------------------
// Public Tauri commands
// ---------------------------------------------------------------------------

#[tauri::command]
pub async fn survey_start(client_label: Option<String>) -> Result<DeviceStart, String> {
    let body = serde_json::json!({
        "client_label": client_label.unwrap_or_else(|| "ReliCheck MM Studio".into())
    });
    let resp = http()
        .post(format!("{}/api/v1/device/start.php", BASE_URL))
        .json(&body)
        .send()
        .await
        .map_err(|e| format!("network: {}", e))?;
    let status = resp.status();
    let text = resp.text().await.unwrap_or_default();
    if !status.is_success() {
        return Err(format!("device/start.php {}: {}", status, truncate(&text, 200)));
    }
    let json: serde_json::Value = serde_json::from_str(&text)
        .map_err(|e| format!("parse start.php: {}: {}", e, truncate(&text, 200)))?;
    if json.get("ok").and_then(|v| v.as_bool()) != Some(true) {
        let msg = json.get("message").and_then(|v| v.as_str()).unwrap_or("unknown");
        return Err(format!("start.php refused: {}", msg));
    }
    Ok(DeviceStart {
        user_code: json["user_code"].as_str().unwrap_or_default().to_string(),
        device_code: json["device_code"].as_str().unwrap_or_default().to_string(),
        verification_url: json["verification_url"].as_str().unwrap_or_default().to_string(),
        expires_in: json["expires_in"].as_u64().unwrap_or(300) as u32,
        interval: json["interval"].as_u64().unwrap_or(3) as u32,
    })
}

#[tauri::command]
pub async fn survey_poll(device_code: String) -> Result<PollOut, String> {
    let body = serde_json::json!({ "device_code": device_code });
    let resp = http()
        .post(format!("{}/api/v1/device/poll.php", BASE_URL))
        .json(&body)
        .send()
        .await
        .map_err(|e| format!("network: {}", e))?;
    let text = resp.text().await.unwrap_or_default();
    let json: serde_json::Value = serde_json::from_str(&text)
        .map_err(|e| format!("parse poll.php: {}: {}", e, truncate(&text, 200)))?;

    let status_str = json.get("status").and_then(|v| v.as_str()).unwrap_or("error").to_string();

    if json.get("ok").and_then(|v| v.as_bool()) == Some(true) && status_str == "ok" {
        // Save token to Keychain. Never expose it to the webview.
        let token = json["access_token"].as_str().unwrap_or("").to_string();
        if token.is_empty() {
            return Err("poll.php returned ok without access_token".into());
        }
        keychain_set_token(&token)?;
        let user = serde_json::from_value::<UserSummary>(json["user"].clone()).ok();
        return Ok(PollOut { status: "ok".into(), user, message: None });
    }

    // Pending or various failure modes — pass them through with the user-
    // facing message intact.
    let msg = json.get("message").and_then(|v| v.as_str()).map(|s| s.to_string());
    Ok(PollOut { status: status_str, user: None, message: msg })
}

#[tauri::command]
pub async fn survey_status() -> Result<StatusOut, String> {
    let Some(token) = keychain_get_token() else {
        return Ok(StatusOut { signed_in: false, user: None });
    };
    // Sanity-check the token against /api/v1/me. If it 401s, treat as
    // not signed in and clear the bad token from the keychain.
    let resp = http()
        .get(format!("{}/api/v1/me.php", BASE_URL))
        .bearer_auth(&token)
        .send()
        .await
        .map_err(|e| format!("network: {}", e))?;
    if resp.status() == reqwest::StatusCode::UNAUTHORIZED {
        let _ = keychain_clear_token();
        return Ok(StatusOut { signed_in: false, user: None });
    }
    if !resp.status().is_success() {
        return Err(format!("/api/v1/me.php {}", resp.status()));
    }
    let json: serde_json::Value = resp.json().await.map_err(|e| format!("parse me: {}", e))?;
    let user = serde_json::from_value::<UserSummary>(json["user"].clone()).ok();
    Ok(StatusOut { signed_in: user.is_some(), user })
}

#[tauri::command]
pub async fn survey_logout() -> Result<(), String> {
    keychain_clear_token()
}

#[tauri::command]
pub async fn survey_list() -> Result<SurveyListOut, String> {
    let Some(token) = keychain_get_token() else {
        return Err("not_signed_in".into());
    };
    // Save the bearer token length for debugging without ever logging
    // the secret itself.
    let token_len = token.len();
    let token_prefix = if token.len() >= 11 { token[..11].to_string() } else { token.clone() };

    let resp = http()
        .get(format!("{}/api/v1/surveys.php", BASE_URL))
        .bearer_auth(&token)
        .send()
        .await
        .map_err(|e| format!("network: {}", e))?;
    let status = resp.status();
    if !status.is_success() {
        let body = resp.text().await.unwrap_or_default();
        return Err(format!(
            "/api/v1/surveys.php {} (token prefix={}, len={}) body: {}",
            status, token_prefix, token_len, truncate(&body, 400)
        ));
    }
    let json: serde_json::Value = resp.json().await.map_err(|e| format!("parse surveys: {}", e))?;

    // The web API may shape the list under a few possible keys depending
    // on app-2026 era. Try the obvious ones.
    let arr = json.get("surveys")
        .or_else(|| json.get("data"))
        .or_else(|| json.get("items"))
        .cloned()
        .unwrap_or(serde_json::Value::Array(vec![]));

    let surveys: Vec<SurveyItem> = if arr.is_array() {
        arr.as_array()
            .unwrap()
            .iter()
            .filter_map(|v| serde_json::from_value::<SurveyItem>(v.clone()).ok())
            .collect()
    } else {
        vec![]
    };
    Ok(SurveyListOut { surveys })
}

#[tauri::command]
pub async fn survey_responses(survey_id: i64) -> Result<ResponsesOut, String> {
    let Some(token) = keychain_get_token() else {
        return Err("not_signed_in".into());
    };
    // Existing /api/v1/responses returns JSON. We'll fetch JSON and
    // convert to CSV here so the desktop can drop the result into the
    // same ingest pipeline as a normal CSV.
    let resp = http()
        .get(format!("{}/api/v1/responses.php?survey_id={}", BASE_URL, survey_id))
        .bearer_auth(&token)
        .send()
        .await
        .map_err(|e| format!("network: {}", e))?;
    if !resp.status().is_success() {
        return Err(format!("/api/v1/responses.php {}", resp.status()));
    }
    let json: serde_json::Value = resp.json().await.map_err(|e| format!("parse responses: {}", e))?;

    // Try a few likely shapes for the response array. Each item is a
    // dict of {question_label_or_id -> answer}.
    let rows = json.get("responses")
        .or_else(|| json.get("data"))
        .or_else(|| json.get("items"))
        .cloned()
        .unwrap_or(serde_json::Value::Array(vec![]));

    let rows_array = rows.as_array().cloned().unwrap_or_default();
    let csv = json_rows_to_csv(&rows_array);

    // Write to a tmp file so we can flow it through the existing ingest
    // pipeline (which takes a filesystem path, not raw bytes).
    let tmp_dir = std::env::temp_dir();
    let filename = format!("relicheck_survey_{}.csv", survey_id);
    let tmp_path = tmp_dir.join(filename);
    std::fs::write(&tmp_path, csv.as_bytes())
        .map_err(|e| format!("write tmp csv: {}", e))?;

    Ok(ResponsesOut {
        row_count: rows_array.len(),
        csv_path: tmp_path.to_string_lossy().into_owned(),
    })
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

fn truncate(s: &str, n: usize) -> &str {
    if s.len() <= n { s } else { &s[..n] }
}

/// Best-effort JSON-array-of-objects -> CSV converter. Quotes any cell
/// containing commas, quotes, or newlines per RFC 4180.
fn json_rows_to_csv(rows: &[serde_json::Value]) -> String {
    if rows.is_empty() {
        return String::new();
    }
    // Collect every key seen across rows. Use the first row's order as
    // the lead, then append any column we see later that wasn't already
    // there. Preserves the natural column order from the API.
    let mut columns: Vec<String> = Vec::new();
    let mut seen: std::collections::HashSet<String> = std::collections::HashSet::new();
    for row in rows {
        if let Some(obj) = row.as_object() {
            for k in obj.keys() {
                if !seen.contains(k) {
                    seen.insert(k.clone());
                    columns.push(k.clone());
                }
            }
        }
    }
    if columns.is_empty() {
        return String::new();
    }

    let mut out = String::new();
    out.push_str(&columns.iter().map(|c| csv_escape(c)).collect::<Vec<_>>().join(","));
    out.push('\n');

    for row in rows {
        let obj = row.as_object();
        let line = columns
            .iter()
            .map(|col| {
                let v = obj.and_then(|o| o.get(col)).cloned().unwrap_or(serde_json::Value::Null);
                csv_escape(&json_cell_to_string(&v))
            })
            .collect::<Vec<_>>()
            .join(",");
        out.push_str(&line);
        out.push('\n');
    }
    out
}

fn json_cell_to_string(v: &serde_json::Value) -> String {
    match v {
        serde_json::Value::Null => String::new(),
        serde_json::Value::Bool(b) => if *b { "true".into() } else { "false".into() },
        serde_json::Value::Number(n) => n.to_string(),
        serde_json::Value::String(s) => s.clone(),
        // Arrays/objects: best-effort JSON serialization
        _ => serde_json::to_string(v).unwrap_or_default(),
    }
}

fn csv_escape(s: &str) -> String {
    if s.contains(',') || s.contains('"') || s.contains('\n') || s.contains('\r') {
        let escaped = s.replace('"', "\"\"");
        format!("\"{}\"", escaped)
    } else {
        s.to_string()
    }
}
