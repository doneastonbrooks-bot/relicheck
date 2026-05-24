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

// Active dataset state. Set by Commit; read by analysis tools.
window.mmState = window.mmState || { dataset: null, roles: {} };

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
    // Default every column to "neutral" (Either)
    window.mmState.roles = {};
    result.columns.forEach(c => { window.mmState.roles[c.name] = "neutral"; });
    setIngestStatus(`Active dataset: <strong>${escapeHtml(basename(result.path))}</strong> &middot; ${result.n_rows_total} rows &middot; ${result.n_cols} cols`, "ok");
    document.querySelector("#ingest-commit").textContent = "Committed";
    document.querySelector("#ingest-commit").disabled = true;
    showAnalysisCard();
    renderVarRoles();
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
  ["dragover", "drop"].forEach(evt => {
    window.addEventListener(evt, e => e.preventDefault());
  });
}

// ---------- Phase 4: variable roles + analysis ----------

function showAnalysisCard() {
  const card = document.querySelector("#analysis-card");
  if (card) card.style.display = "";
}

function roleChip(varName, role, label, current) {
  const on = role === current;
  const borderColor = on ? "#1f6feb" : "#d3d8de";
  const bg = on ? "#f0f7ff" : "#fff";
  return `<label data-var="${escapeHtml(varName)}" data-role="${role}" style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border:1px solid ${borderColor};background:${bg};border-radius:999px;font-size:12px;cursor:pointer;margin-right:6px;user-select:none;">
    <input type="radio" name="role_${escapeHtml(varName)}" ${on ? "checked" : ""} style="margin:0;pointer-events:none;" />
    <span>${escapeHtml(label)}</span>
  </label>`;
}

function renderVarRoles() {
  const host = document.querySelector("#analysis-roles");
  if (!host) return;
  const ds = window.mmState.dataset;
  if (!ds) { host.innerHTML = ""; return; }

  const rows = ds.columns.map(c => {
    const role = window.mmState.roles[c.name] || "neutral";
    const dtypeBadge = `<span style="display:inline-block;padding:1px 8px;border-radius:10px;background:#eef1f5;color:#5a6470;font-size:11px;font-weight:600;margin-left:6px;">${c.dtype}</span>`;
    const labelLine = c.label ? `<div style="font-size:11px;color:#8b95a3;margin-top:2px;">${escapeHtml(c.label)}</div>` : "";
    return `<tr>
      <td style="padding:10px 14px;border-bottom:1px solid #f0f2f4;vertical-align:top;">
        <strong style="font-size:13px;">${escapeHtml(c.name)}</strong>${dtypeBadge}
        ${labelLine}
      </td>
      <td style="padding:10px 14px;border-bottom:1px solid #f0f2f4;vertical-align:top;white-space:nowrap;">
        ${roleChip(c.name, "predictor", "Predictor", role)}
        ${roleChip(c.name, "outcome", "Outcome", role)}
        ${roleChip(c.name, "neutral", "Either", role)}
      </td>
    </tr>`;
  }).join("");

  host.innerHTML = `
    <div style="font-size:12px;color:#5a6470;margin-bottom:8px;">Mark each variable as Predictor, Outcome, or Either. Studio will pair every Predictor with every Outcome and pick the right test.</div>
    <div style="border:1px solid #e2e6ea;border-radius:8px;overflow:auto;max-height:300px;background:#fff;">
      <table style="border-collapse:collapse;width:100%;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">
        <thead><tr style="background:#f7f8fa;">
          <th style="text-align:left;padding:10px 14px;border-bottom:1px solid #e2e6ea;font-size:12px;font-weight:600;color:#5a6470;">Variable</th>
          <th style="text-align:left;padding:10px 14px;border-bottom:1px solid #e2e6ea;font-size:12px;font-weight:600;color:#5a6470;">Role</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  `;

  host.querySelectorAll("label[data-role]").forEach(lbl => {
    lbl.addEventListener("click", (e) => {
      e.preventDefault();
      const v = lbl.getAttribute("data-var");
      const r = lbl.getAttribute("data-role");
      window.mmState.roles[v] = r;
      renderVarRoles();
    });
  });
}

const TEST_LABEL = {
  chi_square: "Chi-square",
  t_test:     "Welch's t-test",
  anova:      "One-way ANOVA",
  pearson:    "Pearson r",
};

function fmtNum(v, d) {
  if (v == null || isNaN(v)) return "-";
  return Number(v).toFixed(d == null ? 2 : d);
}

function fmtP(pv) {
  if (pv == null || isNaN(pv)) return "-";
  if (pv < 0.0001) return "<.0001";
  if (pv < 0.001)  return "<.001";
  return Number(pv).toFixed(3);
}

function resultRowHTML(r) {
  if (!r) return "";
  const pvCell = `<strong>${fmtP(r.p_value)}</strong>`;
  const star = (r.p_value != null && r.p_value < 0.05) ? ' <span style="color:#1f6feb;font-weight:600;">*</span>' : "";
  const effLabel = (r.effect_label || "").replace(/_/g, " ");
  return `<div style="margin-top:6px;background:#f7faff;border:1px solid #d6e4ff;border-radius:8px;padding:10px 14px;font-size:13px;color:#23303d;">
    <div style="display:flex;flex-wrap:wrap;gap:8px 22px;">
      <span><span style="color:#5a6470;">test:</span> ${escapeHtml(TEST_LABEL[r.test_name] || r.test_name || "")}</span>
      <span><span style="color:#5a6470;">statistic:</span> ${fmtNum(r.statistic)}</span>
      <span><span style="color:#5a6470;">p-value:</span> ${pvCell}${star}</span>
      ${r.effect_size != null ? `<span><span style="color:#5a6470;">${escapeHtml(effLabel)}:</span> ${fmtNum(r.effect_size, 3)}</span>` : ""}
      <span><span style="color:#5a6470;">N:</span> ${r.n_total != null ? r.n_total : "-"}</span>
    </div>
    ${r.summary ? `<div style="margin-top:6px;color:#5a6470;font-size:12px;line-height:1.5;">${escapeHtml(r.summary)}</div>` : ""}
  </div>`;
}

async function loadAnalysisPairs() {
  const out = document.querySelector("#analysis-out");
  const msg = document.querySelector("#analysis-msg");
  if (!out) return;
  const ds = window.mmState.dataset;
  if (!ds) {
    out.innerHTML = `<div style="color:#a33;font-size:13px;">No active dataset. Open a file and commit it first.</div>`;
    return;
  }
  const predictors = [];
  const outcomes = [];
  for (const [name, role] of Object.entries(window.mmState.roles)) {
    if (role === "predictor") predictors.push(name);
    else if (role === "outcome") outcomes.push(name);
  }
  if (!predictors.length || !outcomes.length) {
    out.innerHTML = `<div style="color:#5a6470;font-size:13px;"><em>Assign at least one Predictor and one Outcome above, then click Load again.</em></div>`;
    return;
  }
  out.innerHTML = `<em style="color:#5a6470;">Loading analysis pairs...</em>`;
  if (msg) msg.textContent = "";
  try {
    const resp = await invoke("engine_call", {
      op: "analysis.suggest",
      args: {
        ingest_id: ds.ingest_id,
        predictor_names: predictors,
        outcome_names: outcomes,
      },
    });
    if (!resp.ok) {
      out.innerHTML = `<div style="color:#a33;font-size:13px;">${escapeHtml(resp.error || "Could not load suggestions.")}</div>`;
      return;
    }
    renderSuggestions(resp.result);
  } catch (e) {
    out.innerHTML = `<div style="color:#a33;font-size:13px;">${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
  }
}

function renderSuggestions(payload) {
  const out = document.querySelector("#analysis-out");
  const suggestions = payload.suggestions || [];
  const skipped = payload.skipped || [];

  if (!suggestions.length && !skipped.length) {
    out.innerHTML = `<div style="color:#5a6470;font-size:13px;"><em>No pairs to suggest. Try a different role assignment.</em></div>`;
    return;
  }

  const cellMeta = (name, type, distinct) =>
    `<strong style="overflow-wrap:anywhere;">${escapeHtml(name)}</strong>
     <div style="color:#5a6470;font-size:12px;margin-top:2px;">${escapeHtml(type)}${distinct != null ? ` &middot; ${distinct} distinct` : ""}</div>`;

  let html = "";

  if (suggestions.length) {
    html += `<div style="border:1px solid #e2e6ea;border-radius:8px;overflow-x:auto;background:#fff;">
      <table style="width:100%;min-width:760px;border-collapse:collapse;table-layout:fixed;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">
        <colgroup>
          <col style="width:25%;"><col style="width:25%;"><col style="width:160px;"><col>
        </colgroup>
        <thead><tr style="background:#f7f8fa;">
          <th style="text-align:left;padding:10px 14px;border-bottom:1px solid #e2e6ea;font-size:12px;font-weight:600;color:#5a6470;">Predictor</th>
          <th style="text-align:left;padding:10px 14px;border-bottom:1px solid #e2e6ea;font-size:12px;font-weight:600;color:#5a6470;">Outcome</th>
          <th style="text-align:left;padding:10px 14px;border-bottom:1px solid #e2e6ea;font-size:12px;font-weight:600;color:#5a6470;">Suggested test</th>
          <th style="text-align:left;padding:10px 20px 10px 14px;border-bottom:1px solid #e2e6ea;font-size:12px;font-weight:600;color:#5a6470;">Result</th>
        </tr></thead>
        <tbody>
          ${suggestions.map(s => {
            const key = `${s.predictor_name}::${s.outcome_name}::${s.test}`;
            return `<tr data-pair="${escapeHtml(key)}">
              <td style="padding:10px 14px;border-bottom:1px solid #f0f2f4;vertical-align:top;">${cellMeta(s.predictor_name, s.predictor_type, s.predictor_distinct)}</td>
              <td style="padding:10px 14px;border-bottom:1px solid #f0f2f4;vertical-align:top;">${cellMeta(s.outcome_name, s.outcome_type, s.outcome_distinct)}</td>
              <td style="padding:10px 14px;border-bottom:1px solid #f0f2f4;vertical-align:top;color:#5a6470;font-size:13px;">${escapeHtml(TEST_LABEL[s.test] || s.test)}</td>
              <td style="padding:10px 20px 10px 14px;border-bottom:1px solid #f0f2f4;vertical-align:top;">
                <button class="run-btn" data-pred="${escapeHtml(s.predictor_name)}" data-out="${escapeHtml(s.outcome_name)}" data-test="${escapeHtml(s.test)}" style="font-size:12px;padding:6px 14px;border:1px solid #5a6470;background:#fff;border-radius:6px;cursor:pointer;">Run</button>
                <div class="run-slot"></div>
              </td>
            </tr>`;
          }).join("")}
        </tbody>
      </table>
    </div>`;
  }

  if (skipped.length) {
    html += `<div style="margin-top:18px;border:1px dashed #d3d8de;border-radius:8px;background:#fafbfc;padding:14px 16px;">
      <div style="font-weight:600;color:#23303d;margin-bottom:4px;">Cannot run yet (${skipped.length})</div>
      <ul style="margin:0;padding-left:18px;color:#5a6470;font-size:13px;">
        ${skipped.map(s => `<li style="margin:4px 0;">
          <strong>${escapeHtml(s.predictor_name || "")}</strong> &rarr; <strong>${escapeHtml(s.outcome_name || "")}</strong>
          <br><span style="color:#8b95a3;">${escapeHtml(s.skip_reason || "")}</span>
        </li>`).join("")}
      </ul>
    </div>`;
  }

  out.innerHTML = html;

  out.querySelectorAll(".run-btn").forEach(btn => {
    btn.addEventListener("click", async () => {
      const pred = btn.getAttribute("data-pred");
      const outc = btn.getAttribute("data-out");
      const test = btn.getAttribute("data-test");
      const slot = btn.parentElement.querySelector(".run-slot");
      btn.disabled = true; btn.textContent = "Running...";
      try {
        const resp = await invoke("engine_call", {
          op: "analysis.run",
          args: {
            ingest_id: window.mmState.dataset.ingest_id,
            test, predictor_name: pred, outcome_name: outc,
          },
        });
        if (!resp.ok) {
          slot.innerHTML = `<div style="margin-top:6px;color:#a33;font-size:13px;">${escapeHtml(resp.error || "Test failed.")}</div>`;
          btn.textContent = "Run";
        } else {
          slot.innerHTML = resultRowHTML(resp.result);
          btn.textContent = "Re-run";
        }
      } catch (e) {
        slot.innerHTML = `<div style="margin-top:6px;color:#a33;font-size:13px;">${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
        btn.textContent = "Run";
      } finally {
        btn.disabled = false;
      }
    });
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
  if (sidecarBtn) sidecarBtn.addEventListener("click", runSidecarPearson);

  // Phase 3 wiring
  const openBtn = document.querySelector("#ingest-open");
  if (openBtn) openBtn.addEventListener("click", openFilePicker);
  setupDropZone();

  // Phase 4 wiring
  const loadBtn = document.querySelector("#analysis-load");
  if (loadBtn) loadBtn.addEventListener("click", loadAnalysisPairs);
});
