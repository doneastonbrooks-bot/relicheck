// Python sidecar for ReliCheck MM Studio (Phase 2).
//
// Spawns a long-running Python process on app startup and exposes one
// Tauri command, `engine_call`, that round-trips JSON over its stdin/
// stdout. The Python side lives in engine/mm_engine/ipc.py.
//
// Wire protocol (one JSON object per line, both directions):
//
//   request:  {"id": <int>, "op": "<name>", "args": {...}}
//   response: {"id": <int>, "ok": true,  "result": {...}}
//             {"id": <int>, "ok": false, "error": "<reason>"}
//
// The first line out of the sidecar is a greeting:
//   {"event": "ready", "version": "0.1.0"}
// We swallow it during startup so callers never see it.
//
// In dev mode (cargo tauri dev) we spawn the system `python3` with
// PYTHONPATH pointing at ../engine. In a release bundle we spawn the
// bundled interpreter under Resources/python and add Resources/engine
// to PYTHONPATH. The two paths are resolved by `resolve_python_command`.

use std::io::{BufRead, BufReader, Write};
use std::path::PathBuf;
use std::process::{Child, ChildStdin, ChildStdout, Command, Stdio};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Mutex;

use serde::{Deserialize, Serialize};
use serde_json::Value;
use tauri::{AppHandle, Manager, State};

// ---------------------------------------------------------------------------
// State held by Tauri for the lifetime of the app
// ---------------------------------------------------------------------------

pub struct SidecarState {
    inner: Mutex<SidecarInner>,
    next_id: AtomicU64,
}

struct SidecarInner {
    _child: Child,
    stdin: ChildStdin,
    stdout: BufReader<ChildStdout>,
}

impl SidecarState {
    pub fn new(app: &AppHandle) -> Result<Self, String> {
        let (program, args, pythonpath) = resolve_python_command(app)?;
        let mut cmd = Command::new(&program);
        cmd.args(&args)
            .env("PYTHONPATH", &pythonpath)
            .env("PYTHONUNBUFFERED", "1")
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped());

        let mut child = cmd
            .spawn()
            .map_err(|e| format!("failed to spawn python sidecar ({}): {}", program.display(), e))?;

        let stdin = child
            .stdin
            .take()
            .ok_or_else(|| "failed to capture sidecar stdin".to_string())?;
        let stdout = child
            .stdout
            .take()
            .ok_or_else(|| "failed to capture sidecar stdout".to_string())?;
        let mut stdout = BufReader::new(stdout);

        // Swallow the "ready" greeting so the first real request sees a clean channel.
        let mut greeting = String::new();
        stdout
            .read_line(&mut greeting)
            .map_err(|e| format!("failed to read sidecar greeting: {}", e))?;
        if greeting.trim().is_empty() {
            return Err("sidecar exited before sending greeting".into());
        }

        Ok(Self {
            inner: Mutex::new(SidecarInner {
                _child: child,
                stdin,
                stdout,
            }),
            next_id: AtomicU64::new(1),
        })
    }

    fn call(&self, op: &str, args: Value) -> Result<Value, String> {
        let id = self.next_id.fetch_add(1, Ordering::SeqCst);
        let req = serde_json::json!({ "id": id, "op": op, "args": args });
        let line = serde_json::to_string(&req).map_err(|e| format!("serialize: {}", e))?;

        let mut guard = self
            .inner
            .lock()
            .map_err(|_| "sidecar mutex poisoned".to_string())?;

        writeln!(guard.stdin, "{}", line).map_err(|e| format!("write to sidecar: {}", e))?;
        guard.stdin.flush().map_err(|e| format!("flush sidecar: {}", e))?;

        let mut resp_line = String::new();
        guard
            .stdout
            .read_line(&mut resp_line)
            .map_err(|e| format!("read from sidecar: {}", e))?;
        if resp_line.trim().is_empty() {
            return Err("sidecar returned empty response (process may have exited)".into());
        }

        let resp: SidecarResponse =
            serde_json::from_str(resp_line.trim()).map_err(|e| format!("parse sidecar response: {} (raw: {})", e, resp_line.trim()))?;

        if !resp.ok {
            return Err(resp.error.unwrap_or_else(|| "unknown sidecar error".into()));
        }
        Ok(resp.result.unwrap_or(Value::Null))
    }
}

#[derive(Deserialize)]
struct SidecarResponse {
    ok: bool,
    #[serde(default)]
    result: Option<Value>,
    #[serde(default)]
    error: Option<String>,
}

// ---------------------------------------------------------------------------
// Tauri command exposed to the webview
// ---------------------------------------------------------------------------

#[derive(Serialize)]
pub struct EngineResult {
    pub ok: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub result: Option<Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub error: Option<String>,
}

#[tauri::command]
pub fn engine_call(
    op: String,
    args: Option<Value>,
    state: State<'_, SidecarState>,
) -> EngineResult {
    match state.call(&op, args.unwrap_or(Value::Null)) {
        Ok(v) => EngineResult { ok: true, result: Some(v), error: None },
        Err(e) => EngineResult { ok: false, result: None, error: Some(e) },
    }
}

// ---------------------------------------------------------------------------
// Path resolution: dev vs. bundled
// ---------------------------------------------------------------------------

/// Returns (program, args, PYTHONPATH).
///
/// Dev mode: looks for `python3` on PATH and points PYTHONPATH at
/// `<project-root>/engine` (one level up from src-tauri/).
///
/// Bundled mode: prefers `Resources/python/bin/python3` inside the .app
/// and points PYTHONPATH at `Resources/engine`. Falls back to the dev
/// layout if those resources don't exist, so this still works in
/// `cargo tauri dev` from a release-built tree.
fn resolve_python_command(app: &AppHandle) -> Result<(PathBuf, Vec<String>, String), String> {
    let resource_dir = app
        .path()
        .resource_dir()
        .map_err(|e| format!("resource dir: {}", e))?;

    // Bundled layout: Tauri bundler preserves the relative path declared
    // in tauri.conf.json's `bundle.resources` ("resources/python"), so
    // the on-disk layout inside the .app is:
    //   Contents/Resources/resources/python/bin/python3
    //   Contents/Resources/resources/python/lib/python3.12/site-packages/mm_engine/
    //
    // We probe both the nested layout (production .app from the bundler)
    // and the flat layout (any future tauri config that puts the python
    // dir directly under Resources/), so this code keeps working if the
    // bundle.resources declaration changes.
    let candidates = [
        resource_dir.join("resources").join("python"), // production .app
        resource_dir.join("python"),                    // flat fallback
    ];

    for python_root in &candidates {
        let bundled_python = python_root.join("bin").join("python3");
        let bundled_site = python_root
            .join("lib")
            .join("python3.12")
            .join("site-packages");
        if bundled_python.exists() && bundled_site.join("mm_engine").exists() {
            return Ok((
                bundled_python,
                vec!["-u".into(), "-m".into(), "mm_engine.ipc".into()],
                bundled_site.to_string_lossy().into_owned(),
            ));
        }
    }

    // Dev fallback: project root is the parent of src-tauri's parent.
    // (CARGO_MANIFEST_DIR is src-tauri/, so ../engine is the engine dir.)
    let manifest_dir = PathBuf::from(env!("CARGO_MANIFEST_DIR"));
    let dev_engine = manifest_dir
        .parent()
        .map(|p| p.join("engine"))
        .ok_or_else(|| "could not resolve project root".to_string())?;

    if !dev_engine.exists() {
        return Err(format!(
            "engine dir not found at {} (and no bundled python/engine under {})",
            dev_engine.display(),
            resource_dir.display()
        ));
    }

    // Prefer the project venv's Python in dev mode (set up via
    // `python3.12 -m venv engine/.venv` during install). Falls back to
    // bare `python3` on PATH only if the venv isn't present.
    let venv_python = dev_engine.join(".venv").join("bin").join("python");
    let program = if venv_python.exists() {
        venv_python
    } else {
        PathBuf::from("python3")
    };

    Ok((
        program,
        vec!["-u".into(), "-m".into(), "mm_engine.ipc".into()],
        dev_engine.to_string_lossy().into_owned(),
    ))
}
