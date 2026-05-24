const { invoke } = window.__TAURI__.core;
const { open: openDialog } = window.__TAURI__.dialog || {};
const { listen } = window.__TAURI__.event || {};

// ---------- Phase 1 leftover (kept harmless) ----------
let greetInputEl;
let greetMsgEl;

async function greet() {
  greetMsgEl.textContent = await invoke("greet", { name: greetInputEl.value });
}

// ---------- Phase 2: Python sidecar smoke test ----------
async function runSidecarPearson() {
  const outEl = document.querySelector("#sidecar-out");
  const btn = document.querySelector("#sidecar-test");
  outEl.textContent = "Running...";
  btn.disabled = true;
  try {
    const r = await invoke("engine_call", {
      op: "analysis.run",
      args: {
        test: "pearson",
        a: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        b: [2.1, 4.0, 5.8, 8.2, 9.9, 11.7, 14.3, 15.8, 18.4, 19.7, 22.1, 24.5],
      },
    });
    outEl.textContent = JSON.stringify(r, null, 2);
  } catch (e) {
    outEl.textContent = "ERROR: " + (e && e.message ? e.message : String(e));
  } finally {
    btn.disabled = false;
  }
}

// ---------- Phase 3: file ingest ----------

// Active dataset state. Set by Commit; read by analysis tools later.
window.mmState = window.mmState || { dataset: null };

const SUPPORTED_EXTS = ["csv", "tsv", "xlsx", "xls", "xlsm", "sav", "dta", "json"];

function setIngestStatus(html, kind) {
  const el = document.querySelector("#ingest-status");
  if (!el) return;
  el.innerHTML = html;
  el.dataset.kind = kind || "";
}

function renderPreview(result) {
  const host = document.querySelector("#ingest-preview");
  if (!host) return;
  if (!result || !result.columns || !result.columns.length) {
    host.innerHTML = "";
    return;
  }
  const cols = result.columns;
  const rows = result.rows_preview || [];
  const dtypeChip = (t) => {
    const colors = {
      numeric:  ["#1f8a4d", "#e7f6ed"],
      category: ["#5a4cc6", "#eeebfb"],
      boolean:  ["#b06a00", "#fbf0db"],
      datetime: ["#0769a8", "#e5f1f8"],
      text:     ["#5a6470", "#eef1f5"],
    };
    const [fg, bg] = colors[t] || colors.text;
    return `<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:${bg};color:${fg};font-size:11px;font-weight:600;letter-spacing:.02em;">${t}</span>`;
  };
  const header = cols.map(c => {
    const lab = c.label ? `<div style="font-size:11px;color:#8b95a3;font-weight:400;margin-top:2px;">${escapeHtml(c.label)}</div>` : "";
    const miss = c.n_missing > 0 ? `<div style="font-size:11px;color:#b06a00;font-weight:400;margin-top:2px;">${c.n_missing} missing</div>` : "";
    return `<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e6ea;vertical-align:top;">
      <div style="font-weight:600;font-size:13px;">${escapeHtml(c.name)}</div>
      <div style="margin-top:4px;">${dtypeChip(c.dtype)}</div>
      ${lab}${miss}
    </th>`;
  }).join("");
  const body = rows.map(row => {
    const cells = cols.map(c => {
      let v = row[c.name];
      if (v === null || v === undefined) v = '<span style="color:#b06a00;">·</span>';
      else v = escapeHtml(String(v));
      return `<td style="padding:6px 10px;border-bottom:1px solid #f0f2f5;font-size:12px;">${v}</td>`;
    }).join("");
    return `<tr>${cells}</tr>`;
  }).join("");
  const sheetInfo = result.sheets && result.sheets.length > 1
    ? `<div style="font-size:12px;color:#5a6470;margin-bottom:6px;">Sheet: <strong>${escapeHtml(result.sheet || result.sheets[0])}</strong> &middot; ${result.sheets.length} sheets total</div>`
    : "";
  host.innerHTML = `
    ${sheetInfo}
    <div style="font-size:12px;color:#5a6470;margin-bottom:10px;">
      ${result.n_rows_total.toLocaleString()} rows &middot; ${result.n_cols} columns &middot; format: <strong>${result.format}</strong>
      ${rows.length < result.n_rows_total ? `&middot; showing first ${rows.length}` : ""}
    </div>
    <div style="overflow:auto;max-height:340px;border:1px solid #e2e6ea;border-radius:8px;">
      <table style="border-collapse:collapse;width:100%;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">
        <thead><tr>${header}</tr></thead>
        <tbody>${body}</tbody>
      </table>
    </div>
    <button id="ingest-commit" style="margin-top:14px;padding:8px 16px;font-size:14px;border-radius:8px;border:1px solid #1f8a4d;background:#1f8a4d;color:#fff;cursor:pointer;font-weight:600;">Commit as active dataset</button>
  `;
  document.querySelector("#ingest-commit").addEventListener("click", () => {
    window.mmState.dataset = result;
    setIngestStatus(`Active dataset: <strong>${escapeHtml(basename(result.path))}</strong> &middot; ${result.n_rows_total} rows &middot; ${result.n_cols} cols`, "ok");
    document.querySelector("#ingest-commit").textContent = "Committed";
    document.querySelector("#ingest-commit").disabled = true;
  });
}

function escapeHtml(s) {
  return String(s)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function basename(p) {
  if (!p) return "";
  const i = Math.max(p.lastIndexOf("/"), p.lastIndexOf("\\"));
  return i >= 0 ? p.slice(i + 1) : p;
}

async function ingestPath(path) {
  setIngestStatus(`Reading <strong>${escapeHtml(basename(path))}</strong>...`, "busy");
  try {
    const resp = await invoke("engine_call", {
      op: "ingest.sniff",
      args: { path },
    });
    if (!resp.ok) {
      setIngestStatus(`Error: ${escapeHtml(resp.error || "ingest failed")}`, "err");
      renderPreview(null);
      return;
    }
    const result = resp.result;
    if (result && result.needs_sheet_pick) {
      // Multi-sheet Excel: ask which sheet
      const sheet = await pickSheet(result.sheets);
      if (!sheet) {
        setIngestStatus("Sheet selection cancelled.", "");
        return;
      }
      const resp2 = await invoke("engine_call", {
        op: "ingest.excel",
        args: { path, sheet },
      });
      if (!resp2.ok) {
        setIngestStatus(`Error: ${escapeHtml(resp2.error || "ingest failed")}`, "err");
        return;
      }
      setIngestStatus(`Loaded <strong>${escapeHtml(basename(path))}</strong> (${resp2.result.n_rows_total} rows)`, "ok");
      renderPreview(resp2.result);
      return;
    }
    setIngestStatus(`Loaded <strong>${escapeHtml(basename(path))}</strong> (${result.n_rows_total} rows)`, "ok");
    renderPreview(result);
  } catch (e) {
    setIngestStatus(`Error: ${escapeHtml(e && e.message ? e.message : String(e))}`, "err");
  }
}

async function pickSheet(sheets) {
  // Minimal sheet picker: native prompt() is blocked in some webviews,
  // so render a small inline picker.
  return new Promise(resolve => {
    const host = document.querySelector("#ingest-preview");
    host.innerHTML = `
      <div style="padding:14px;border:1px solid #e2e6ea;border-radius:8px;background:#fbfcfd;">
        <div style="font-size:13px;font-weight:600;margin-bottom:8px;">This workbook has multiple sheets. Pick one:</div>
        <div id="sheet-picker">
          ${sheets.map(s => `<button data-sheet="${escapeHtml(s)}" style="margin:4px 6px 0 0;padding:6px 12px;font-size:13px;border:1px solid #5a6470;background:#fff;border-radius:6px;cursor:pointer;">${escapeHtml(s)}</button>`).join("")}
        </div>
      </div>
    `;
    host.querySelectorAll("#sheet-picker button").forEach(btn => {
      btn.addEventListener("click", () => resolve(btn.dataset.sheet));
    });
  });
}

async function openFilePicker() {
  if (!openDialog) {
    setIngestStatus("File dialog not available (plugin missing).", "err");
    return;
  }
  try {
    const selected = await openDialog({
      multiple: false,
      filters: [
        { name: "Data files", extensions: SUPPORTED_EXTS },
        { name: "All files", extensions: ["*"] },
      ],
    });
    if (!selected) return;
    const path = Array.isArray(selected) ? selected[0] : selected;
    ingestPath(path);
  } catch (e) {
    setIngestStatus(`Error: ${escapeHtml(e && e.message ? e.message : String(e))}`, "err");
  }
}

function setupDropZone() {
  const zone = document.querySelector("#ingest-drop");
  if (!zone) return;

  // Listen for OS-level drops forwarded by Rust.
  if (listen) {
    listen("files-drag-enter", () => zone.classList.add("dragging"));
    listen("files-drag-leave", () => zone.classList.remove("dragging"));
    listen("files-dropped", (event) => {
      zone.classList.remove("dragging");
      const paths = event.payload || [];
      if (!paths.length) return;
      ingestPath(paths[0]);
    });
  }

  // Also block the webview's own drag-drop so the OS handler is the
  // only one that fires (otherwise some webviews would try to navigate
  // to the file:// URL).
  ["dragover", "drop"].forEach(evt => {
    window.addEventListener(evt, e => e.preventDefault());
  });
}

// ---------- DOM ready ----------

window.addEventListener("DOMContentLoaded", () => {
  // Phase 1 wiring (only attaches if those elements exist)
  greetInputEl = document.querySelector("#greet-input");
  greetMsgEl = document.querySelector("#greet-msg");
  const greetForm = document.querySelector("#greet-form");
  if (greetForm) {
    greetForm.addEventListener("submit", (e) => {
      e.preventDefault();
      greet();
    });
  }

  // Phase 2 wiring
  const sidecarBtn = document.querySelector("#sidecar-test");
  if (sidecarBtn) {
    sidecarBtn.addEventListener("click", runSidecarPearson);
  }

  // Phase 3 wiring
  const openBtn = document.querySelector("#ingest-open");
  if (openBtn) {
    openBtn.addEventListener("click", openFilePicker);
  }
  setupDropZone();
});
