<?php
// rssi.php — RSSI app entry point.
// Single page: upload zone first, dashboard renders inline after parse.
// Uses the RSSI template ONLY. No studio template. No platform shell.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    header('Location: /login.html?return=' . urlencode('/rssi.php'));
    exit;
}
$rssi_user = current_user();
if (!$rssi_user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$rssi_user_full = $rssi_user['name'] ?? $rssi_user['email'] ?? 'You';
$rssi_initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $rssi_user_full) ?: 'U', 0, 2));

// "Project" stub for the template (no DB project yet — created on upload).
$rssi_project = ['title' => 'Upload a survey to begin', 'id' => 0];
$rssi_back_url = '/app-2026v4.php';

$_css_ver  = filemtime(__DIR__ . '/apps/rssi/rssi.css');
$_js_ver   = filemtime(__DIR__ . '/apps/rssi/rssi.js');
$_tag_ver  = file_exists(__DIR__ . '/apps/rssi/rssi-tag-core.js') ? filemtime(__DIR__ . '/apps/rssi/rssi-tag-core.js') : time();
$_up_ver   = file_exists(__DIR__ . '/apps/rssi/rssi-upload.js') ? filemtime(__DIR__ . '/apps/rssi/rssi-upload.js') : time();
$_rel_ver  = file_exists(__DIR__ . '/apps/rssi/rssi-reliability.js') ? filemtime(__DIR__ . '/apps/rssi/rssi-reliability.js') : time();
$_ana_ver  = file_exists(__DIR__ . '/apps/rssi/rssi-analyses.js')    ? filemtime(__DIR__ . '/apps/rssi/rssi-analyses.js')    : time();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Strength Survey Index · ReliCheck</title>
  <link rel="icon" type="image/png" href="/RSSI-logo.png">
  <link rel="stylesheet" href="/apps/rssi/rssi.css?v=<?= $_css_ver ?>">
  <style>
    /* Upload-screen-only styles, scoped to .rssi-app */
    .rssi-app .upload-stage {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-start;
      padding-top: 16px;
      min-height: calc(100vh - 200px);
    }
    .rssi-app .upload-card {
      background: var(--surface);
      border: 1px solid var(--hairline);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-md);
      padding: 44px 52px;
      max-width: 600px;
      width: 100%;
      text-align: center;
    }
    .rssi-app .upload-card h2 {
      font-size: 24px;
      font-weight: 600;
      letter-spacing: -0.02em;
      margin: 0 0 10px;
    }
    .rssi-app .upload-card p.lede {
      color: var(--text-2);
      font-size: 14.5px;
      line-height: 1.55;
      margin: 0 0 28px;
      max-width: 48ch;
      margin-left: auto;
      margin-right: auto;
    }
    .rssi-app .dropzone {
      border: 2px dashed var(--hairline-strong);
      border-radius: 16px;
      padding: 36px 24px;
      cursor: pointer;
      transition: border-color 0.15s, background 0.15s, transform 0.15s;
      background: #FAFAFC;
    }
    .rssi-app .dropzone:hover,
    .rssi-app .dropzone.dragover {
      border-color: var(--blue);
      background: var(--blue-soft-bg);
      transform: translateY(-1px);
    }
    .rssi-app .dropzone-icon {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      background: var(--blue-tint);
      color: var(--blue);
      display: inline-grid;
      place-items: center;
      margin-bottom: 14px;
    }
    .rssi-app .dropzone-title {
      font-size: 16px;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 6px;
    }
    .rssi-app .dropzone-sub {
      font-size: 13px;
      color: var(--text-2);
    }
    .rssi-app .dropzone-sub strong {
      color: var(--blue);
      font-weight: 600;
    }
    .rssi-app .upload-help {
      margin-top: 22px;
      font-size: 12.5px;
      color: var(--text-3);
      line-height: 1.55;
    }
    .rssi-app .upload-help a {
      color: var(--blue);
      font-weight: 500;
    }
    .rssi-app .upload-status {
      display: none;
      margin-top: 18px;
      padding: 14px 18px;
      background: var(--blue-soft-bg);
      border: 1px solid var(--blue-tint-2);
      border-radius: 10px;
      font-size: 13px;
      color: var(--text);
      text-align: left;
    }
    .rssi-app .upload-status.show { display: block; }
    .rssi-app .upload-status.error {
      background: #FFF1F2;
      border-color: #FECACA;
      color: #991B1B;
    }
    .rssi-app .upload-status .meta {
      font-size: 12px;
      color: var(--text-2);
      margin-top: 4px;
    }

    /* Stage visibility rules live in apps/rssi/rssi.css (§16 M2,
       single source of truth across upload / tag / dashboard). */

    /* ── Import affordance: "or pull from a saved project" ───────── */
    .rssi-app .upload-or-divider {
      display: flex; align-items: center; gap: 12px;
      margin: 20px 0 14px;
      font-size: 12px;
      color: var(--text-3);
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    .rssi-app .upload-or-divider::before,
    .rssi-app .upload-or-divider::after {
      content: ''; flex: 1; height: 1px; background: var(--hairline);
    }
    .rssi-app .upload-pull-btn {
      width: 100%;
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      padding: 12px 18px;
      background: var(--surface);
      border: 1px solid var(--hairline-strong);
      border-radius: 12px;
      font: inherit; font-weight: 600; font-size: 14px;
      color: var(--text);
      cursor: pointer;
      transition: border-color 0.15s, color 0.15s, background 0.15s;
    }
    .rssi-app .upload-pull-btn:hover {
      border-color: var(--blue);
      color: var(--blue);
      background: var(--blue-soft-bg);
    }

    /* ── Picker modal ──────────────────────────────────────────── */
    .rssi-picker-modal { position: fixed; inset: 0; z-index: 1000; }
    .rssi-picker-modal[hidden] { display: none; }
    .rssi-picker-backdrop {
      position: absolute; inset: 0;
      background: rgba(15, 23, 42, 0.32);
      backdrop-filter: blur(2px); -webkit-backdrop-filter: blur(2px);
    }
    .rssi-picker-dialog {
      position: relative;
      max-width: 640px;
      width: calc(100% - 32px);
      max-height: calc(100vh - 64px);
      margin: 40px auto;
      background: var(--surface);
      border: 1px solid var(--hairline);
      border-radius: 16px;
      box-shadow: 0 20px 60px rgba(15, 23, 42, 0.18);
      display: flex; flex-direction: column;
      overflow: hidden;
    }
    .rssi-picker-head {
      display: flex; align-items: center; justify-content: space-between;
      padding: 16px 22px 12px;
      border-bottom: 1px solid var(--hairline);
    }
    .rssi-picker-head h3 {
      margin: 0;
      font-size: 17px;
      font-weight: 600;
      letter-spacing: -0.015em;
    }
    .rssi-picker-close {
      appearance: none; -webkit-appearance: none;
      background: transparent; border: 0; padding: 0;
      font-size: 22px; line-height: 1;
      color: var(--text-2); cursor: pointer;
      width: 28px; height: 28px;
      display: grid; place-items: center;
      border-radius: 6px;
    }
    .rssi-picker-close:hover { background: var(--surface-muted); color: var(--text); }
    .rssi-picker-tabs {
      display: flex; gap: 4px;
      padding: 8px 16px 0;
      border-bottom: 1px solid var(--hairline);
    }
    .rssi-picker-tab {
      appearance: none; -webkit-appearance: none;
      background: transparent; border: 0;
      padding: 10px 14px;
      font: inherit; font-size: 13.5px; font-weight: 500;
      color: var(--text-2); cursor: pointer;
      border-bottom: 2px solid transparent;
      transition: color 0.15s, border-color 0.15s;
    }
    .rssi-picker-tab:hover { color: var(--text); }
    .rssi-picker-tab.is-active {
      color: var(--blue);
      border-bottom-color: var(--blue);
      font-weight: 600;
    }
    .rssi-picker-body {
      flex: 1; overflow-y: auto;
      padding: 8px 0;
      min-height: 200px;
    }
    .rssi-picker-pane[hidden] { display: none; }
    .rssi-picker-loading,
    .rssi-picker-empty,
    .rssi-picker-err {
      padding: 32px 22px;
      text-align: center;
      color: var(--text-2);
      font-size: 13.5px;
    }
    .rssi-picker-err { color: var(--weak); }
    .rssi-picker-list {
      list-style: none; margin: 0; padding: 0;
    }
    .rssi-picker-row {
      width: 100%;
      display: grid;
      grid-template-columns: 1fr auto;
      grid-template-areas: "title arrow" "meta arrow";
      gap: 2px 14px;
      align-items: center;
      padding: 12px 22px;
      background: transparent;
      border: 0;
      border-top: 1px solid var(--hairline);
      text-align: left;
      cursor: pointer;
      font: inherit;
      color: inherit;
      transition: background 0.12s;
    }
    .rssi-picker-row:hover { background: var(--blue-soft-bg); }
    .rssi-picker-row-title {
      grid-area: title;
      font-size: 14px; font-weight: 600; color: var(--text);
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .rssi-picker-row-meta {
      grid-area: meta;
      font-size: 12px; color: var(--text-3);
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .rssi-picker-row-arrow {
      grid-area: arrow;
      font-size: 16px; color: var(--text-3);
    }
    .rssi-picker-row:hover .rssi-picker-row-arrow { color: var(--blue); }
  </style>
</head>
<body>

<!-- Locked top-right ReliCheck wordmark — greyed, never blocks clicks -->
<div class="rssi-corner-brand" aria-hidden="true">
  <img src="/logo-brand.svg" alt="">
</div>

<div class="rssi-app" id="rssiAppRoot" data-stage="upload" data-project-id="0">
<div class="app">

  <!-- =================== SIDEBAR =================== -->
  <!-- Matches /RSSI App/ReliCheck Survey Strength Index.html exactly:
       brand + search + Diagnostic + Improve + project card.
       Diagnostic items are anchor links into the dashboard cards (they
       become live the moment the user uploads data). -->
  <aside class="sidebar">
    <div class="brand brand-logo-only">
      <img src="/RSSI-logo.png" alt="ReliCheck Strength Survey Index" class="brand-logo-full">
    </div>

    <div class="search">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="5"/><path d="m11 11 3 3" stroke-linecap="round"/></svg>
      <span>Search</span>
      <span class="kbd">&#8984;K</span>
    </div>

    <div class="nav-group">
      <div class="nav-label">Diagnostic</div>

      <a class="nav-item active" data-view="overview" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2.5 4 5.5v6c0 4.5 3.5 8 8 9 4.5-1 8-4.5 8-9v-6z"/><path d="M9 12l2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        ReliCheck Strength Score
      </a>
    </div>

    <div class="nav-group">
      <div class="nav-label">Instrument Quality</div>

      <a class="nav-item" data-view="detail" data-dimension="reliability_readiness" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5 2.5 3.8v3.7c0 3.4 2.4 6.2 5.5 7 3.1-.8 5.5-3.6 5.5-7V3.8L8 1.5Z" stroke-linejoin="round"/></svg></span>
        Reliability
      </a>
      <a class="nav-item" data-view="detail" data-dimension="validity_alignment" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M5.5 8 7 9.5 10.5 6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        Validity
      </a>
      <a class="nav-item" data-view="detail" data-dimension="construct_alignment" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="3" rx="1"/><rect x="2" y="7" width="9" height="3" rx="1"/><rect x="2" y="11" width="6" height="3" rx="1"/></svg></span>
        Construct Alignment
      </a>
      <a class="nav-item" data-view="detail" data-dimension="question_quality" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="5.5"/><path d="M6 8.5 7.5 10 10 6.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        Item / Prompt Quality
      </a>
      <a class="nav-item" data-view="detail" data-dimension="bias_clarity" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="7" r="4"/><path d="M2 14l5-5M14 14l-2-2"/></svg></span>
        Bias &amp; Clarity Review
      </a>
      <a class="nav-item" data-view="detail" data-dimension="scale_strength" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 13V3M3 13h10M5.5 11V8M8 11V5M10.5 11V9" stroke-linecap="round"/></svg></span>
        Scale Structure
      </a>
      <a class="nav-item" data-view="detail" data-dimension="factor_readiness" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="5" cy="5" r="2"/><circle cx="11" cy="5" r="2"/><circle cx="5" cy="11" r="2"/><circle cx="11" cy="11" r="2"/><path d="M5 7v2M11 7v2M7 5h2M7 11h2" stroke-linecap="round"/></svg></span>
        Factor Readiness
      </a>
      <a class="nav-item" data-view="detail" data-dimension="response_risk" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8h10M3 4h10M3 12h6"/><circle cx="6" cy="8" r="1" fill="currentColor"/><circle cx="10" cy="4" r="1" fill="currentColor"/></svg></span>
        Response Scale Review
      </a>
    </div>

    <div class="nav-group">
      <div class="nav-label">Qualitative</div>

      <a class="nav-item" data-view="detail" data-dimension="trustworthiness" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5 2.5 3.8v3.7c0 3.4 2.4 6.2 5.5 7 3.1-.8 5.5-3.6 5.5-7V3.8L8 1.5Z"/><circle cx="8" cy="8" r="1.5" fill="currentColor"/></svg></span>
        Trustworthiness
      </a>
      <a class="nav-item" data-view="detail" data-dimension="inter_rater_agreement" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="5" cy="6" r="2.5"/><circle cx="11" cy="6" r="2.5"/><path d="M2 14a3 3 0 0 1 6 0M8 14a3 3 0 0 1 6 0"/></svg></span>
        Inter-rater Agreement
      </a>
    </div>

    <div class="nav-group">
      <div class="nav-label">Improve</div>
      <a class="nav-item" data-view="recommendations" href="#">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5a4.5 4.5 0 0 0-2.7 8.1V11h5.4v-1.4A4.5 4.5 0 0 0 8 1.5ZM6 13h4M7 14.5h2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        Recommendations
      </a>
      <a class="nav-item" data-view="print" href="javascript:window.print()">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2v8m0 0L5 7m3 3 3-3M3 12v1a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        Export Report
      </a>
    </div>

    <div class="sidebar-footer">
      <div class="project-card">
        <div class="label">Current survey</div>
        <div class="name" id="rssiSidebarSurveyName">No survey uploaded yet</div>
        <div class="meta">
          <span id="rssiSidebarMetaItems">— items</span><span class="dot"></span><span id="rssiSidebarMetaResp">— responses</span>
        </div>
        <a class="switch" href="/rssi.php">
          <span>Switch survey</span>
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m4 2 4 4-4 4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
      </div>
    </div>
  </aside>

  <!-- =================== MAIN =================== -->
  <main class="main">

    <div class="topbar">
      <div class="crumbs">
        <span>ReliCheck</span><span class="sep">›</span>
        <span>Apps</span><span class="sep">›</span>
        <span class="here">Strength Survey Index</span>
      </div>
    </div>

    <!-- ============ STAGE 1: UPLOAD ============ -->
    <div class="upload-stage" id="rssiUploadStage">

      <!-- Welcome panel — sits above the upload card on the data-upload page -->
      <section class="card" id="rssiHomeWelcome" style="padding:28px 32px;margin-bottom:22px;max-width:780px;width:100%;background:linear-gradient(165deg,#F1F6FF 0%,#FFFFFF 70%);border:1px solid rgba(0,122,255,0.18);">
        <div style="display:flex;align-items:flex-start;gap:18px;flex-wrap:wrap;">
          <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(160deg,#2D8DFF 0%,#006FE6 100%);display:grid;place-items:center;flex-shrink:0;box-shadow:0 2px 6px rgba(0,80,180,0.25);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/></svg>
          </div>
          <div style="flex:1;min-width:0;">
            <h2 style="font-size:22px;font-weight:600;letter-spacing:-0.02em;margin:0 0 6px;color:var(--text);">Welcome to the <em style="font-style:normal;background:linear-gradient(180deg,#0A6FE8 0%,#2D8DFF 100%);-webkit-background-clip:text;background-clip:text;color:transparent;">ReliCheck Strength Survey Index</em></h2>
            <p style="margin:0 0 14px;color:var(--text-2);font-size:14.5px;line-height:1.6;max-width:62ch;">
              RSSI turns any Likert-based survey into a polished, one-page credibility report.
              We score six diagnostic dimensions of instrument quality, flag the highest-impact issues to fix,
              and give you a print-ready deliverable to share with stakeholders, attach to a proposal, or hand to a client.
            </p>
            <div style="display:flex;gap:24px;flex-wrap:wrap;font-size:13px;color:var(--text-2);">
              <div style="display:inline-flex;align-items:center;gap:6px;">
                <span style="width:6px;height:6px;border-radius:50%;background:var(--blue);"></span>
                <span><strong style="color:var(--text);font-weight:600;">Score</strong> &mdash; one headline 0&ndash;100 number with a clear band</span>
              </div>
              <div style="display:inline-flex;align-items:center;gap:6px;">
                <span style="width:6px;height:6px;border-radius:50%;background:var(--blue);"></span>
                <span><strong style="color:var(--text);font-weight:600;">Diagnose</strong> &mdash; six dimensions of instrument quality</span>
              </div>
              <div style="display:inline-flex;align-items:center;gap:6px;">
                <span style="width:6px;height:6px;border-radius:50%;background:var(--blue);"></span>
                <span><strong style="color:var(--text);font-weight:600;">Fix</strong> &mdash; ranked, actionable recommendations</span>
              </div>
              <div style="display:inline-flex;align-items:center;gap:6px;">
                <span style="width:6px;height:6px;border-radius:50%;background:var(--blue);"></span>
                <span><strong style="color:var(--text);font-weight:600;">Deliver</strong> &mdash; Cmd+P for a clean PDF</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div class="upload-card">
        <h2>Upload your survey data</h2>
        <p class="lede">
          Drop a CSV or Excel file with your response data.
          The Strength Survey Index will score your instrument in seconds — six dimensions, top issues to fix, ready to print or share.
        </p>

        <div class="dropzone" id="rssiDropzone">
          <input type="file" id="rssiFileInput" accept=".csv,.xls,.xlsx,.tsv,.txt" style="display:none;">
          <div class="dropzone-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          </div>
          <div class="dropzone-title">Drag &amp; drop your file</div>
          <div class="dropzone-sub">or <strong>click to browse</strong> — CSV, XLSX, XLS, TSV</div>
        </div>

        <div class="upload-status" id="rssiUploadStatus"></div>

        <!-- Or pull from a saved project. Opens a modal listing the
             user's Datasets, Surveys, and MM Projects (Phase 1 Q4–Q5).
             Click a row → routes to /rssi-upload.php?<id_param>=N which
             triggers _loadFromDataset / _loadFromSurvey / _loadFromMMProject
             on init. Persistence + import are sibling affordances on the
             same upload screen. -->
        <div class="upload-or-divider"><span>or</span></div>
        <button type="button" class="upload-pull-btn" id="rssiOpenPicker">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 4l9 5.5"/><path d="M3 14.5 12 20l9-5.5"/><path d="M3 9.5v5"/><path d="M21 9.5v5"/></svg>
          Pull from a saved project
        </button>

        <div class="upload-help">
          Need a sample to test the report?
          <a href="#" id="rssiTryDemo">Try with sample data →</a>
        </div>
      </div>
    </div>

    <!-- ============ PROJECT IMPORT MODAL ============ -->
    <!-- Three tabs: Datasets (raw uploads), Surveys (deployed surveys
         with collected responses), MM Projects. Each tab lazy-loads
         its list endpoint on first activation. Clicking a row routes
         to ?<id_param>=N on this same page. -->
    <div class="rssi-picker-modal" id="rssiPickerModal" hidden aria-hidden="true" role="dialog" aria-label="Pull from a saved project">
      <div class="rssi-picker-backdrop" id="rssiPickerBackdrop"></div>
      <div class="rssi-picker-dialog">
        <div class="rssi-picker-head">
          <h3>Pull from a saved project</h3>
          <button type="button" class="rssi-picker-close" id="rssiPickerClose" aria-label="Close">&times;</button>
        </div>
        <div class="rssi-picker-tabs" role="tablist">
          <button type="button" class="rssi-picker-tab is-active" data-tab="datasets" role="tab" aria-selected="true">Datasets</button>
          <button type="button" class="rssi-picker-tab"           data-tab="surveys"  role="tab" aria-selected="false">Surveys</button>
          <button type="button" class="rssi-picker-tab"           data-tab="mm"       role="tab" aria-selected="false">MM Projects</button>
        </div>
        <div class="rssi-picker-body">
          <div class="rssi-picker-pane is-active" data-pane="datasets" role="tabpanel">
            <div class="rssi-picker-loading">Loading…</div>
          </div>
          <div class="rssi-picker-pane"           data-pane="surveys"  role="tabpanel" hidden>
            <div class="rssi-picker-loading">Loading…</div>
          </div>
          <div class="rssi-picker-pane"           data-pane="mm"       role="tabpanel" hidden>
            <div class="rssi-picker-loading">Loading…</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ STAGE 2: TAG ============ -->
    <!-- (Hidden until a file is parsed; KNOWN_ISSUES §16 M2.) -->
    <div class="tag-stage" id="rssiTagStage">
      <h2>Tag your columns</h2>
      <p class="tag-lede">
        We auto-detected a starting role for each column. Adjust where needed,
        give each Likert item a construct name, and confirm reverse-coding.
        Your work auto-saves to this browser.
      </p>

      <!-- Shared datalist: live-rebuilt from constructs already used in the file. -->
      <datalist id="rssiConstructsUsed"></datalist>

      <table class="tag-table" id="rssiTagTable">
        <thead>
          <tr>
            <th>Column</th>
            <th>Role</th>
            <th>Construct</th>
            <th>Reverse</th>
            <th>Anchors</th>
            <th>Sample values</th>
          </tr>
        </thead>
        <tbody id="rssiTagTbody"><!-- rows injected by rssi-upload.js --></tbody>
      </table>

      <div class="tag-bottom-bar">
        <!-- Survey-level reverse-coding confirmation. Copy mirrors
             survey-wizard.php Step 3 verbatim per Phase 1 Q5. Required for
             §4E Scale Structure sub-2 to evaluate reverse-coded balance. -->
        <div class="reverse-confirm">
          <label>
            <input type="checkbox" id="rssiReverseConfirmed">
            <span class="copy">
              <strong>I&rsquo;ve reviewed every item for reverse-coding.</strong>
              <span class="muted">Confirms the reverse checkboxes above are complete.
              Required for &sect;4E Scale Structure to evaluate reverse-coded
              balance; without this confirmation the sub-component is skipped.</span>
            </span>
          </label>
        </div>

        <div class="tag-actions">
          <div class="tag-blocker-msg" id="rssiTagBlockerMsg"></div>
          <button class="btn btn-primary" type="button" id="rssiScoreBtn" disabled>
            Score my data
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 7h8m0 0L8 4m3 3-3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- ============ STAGE 3: DASHBOARD ============ -->
    <!-- (Hidden until upload completes; render.php sections injected below) -->
    <div class="dashboard-stage" id="rssiDashboardStage">

    <!-- ─── VIEW: OVERVIEW (default) ─────────────────────────── -->
    <div class="rssi-view" id="rssiViewOverview" data-view="overview">

      <div class="title-row">
        <div>
          <h1 class="h1" id="rssiDashTitle">Your survey</h1>
          <div class="subtitle-row">
            <span class="pill pill-blue" id="rssiVerdictPill">—</span>
            <span id="rssiItemCount">— items</span><span class="dot"></span>
            <span id="rssiRespCount">— responses</span><span class="dot"></span>
            <span id="rssiComputedAt">Just scored</span><span class="dot"></span>
            <span>By <strong style="color:var(--text); font-weight:500;"><?= htmlspecialchars($rssi_user_full) ?></strong></span>
          </div>
        </div>
        <div class="actions">
          <button class="btn" type="button" id="rssiRetagBtn">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 5v3a3 3 0 0 0 3 3h5m0 0L8 8m3 3-3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Re-tag columns
          </button>
          <button class="btn" type="button" onclick="window.print()">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 8.5V11a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V8.5M7 2v6.5m0 0L4.5 6M7 8.5 9.5 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Print / Save PDF
          </button>
          <button class="btn btn-primary" type="button" id="rssiUploadAgain">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M7 2v8m0 0L4 7m3 3 3-3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Score another
          </button>
        </div>
      </div>

      <!-- Two-column hero row: RSSI score on the left, interactive Cronbach
           analyzer on the right. Stacks vertically on screens below 1100px. -->
      <div class="rssi-hero-row">
        <section class="card hero rssi-hero-score">
          <div class="ring-wrap">
            <svg viewBox="0 0 120 120">
              <circle class="ring-bg" cx="60" cy="60" r="52" fill="none" stroke-width="10"/>
              <circle class="ring-fg" id="rssiRingFg" cx="60" cy="60" r="52" fill="none" stroke-width="10"
                      stroke-dasharray="326.7" stroke-dashoffset="326.7"/>
            </svg>
            <div class="ring-center">
              <div>
                <div class="score" id="rssiScore">—</div>
                <div class="out-of">out of 100</div>
                <div class="badge" id="rssiBadge">Pending</div>
              </div>
            </div>
          </div>
          <div class="hero-copy">
            <h2 id="rssiHeroH2">Scoring your survey...</h2>
            <p id="rssiHeroP">A plain-language read of the score will appear here.</p>

            <!-- v2 three-lens triplet (Spec §3.2). All three lens scores
                 render here; the ring shows the headline (Respondent-
                 Centered, Spec §3.4). The "?" affordance is mandated by
                 Spec §3.5 and opens the explainer on click. The "Limited
                 evidence" pill on Validity-Forward fires when the
                 criterion sub-component was skipped (Spec §3.6 cap). -->
            <div class="lens-triplet" id="rssiLensTriplet" role="group" aria-label="Three RSSI lens scores">
              <div class="lens-chip" data-lens="psychometric_core">
                <span class="lens-chip-label">Psychometric Core
                  <button type="button" class="lens-info-icon" data-lens-info="psychometric_core" aria-label="About Psychometric Core" aria-expanded="false">ⓘ</button>
                </span>
                <span class="lens-chip-score" id="rssiLens_psychometric_core">—</span>
              </div>
              <div class="lens-chip lens-chip-headline" data-lens="respondent_centered">
                <span class="lens-chip-label">Respondent-Centered <span class="lens-chip-badge">Headline</span>
                  <button type="button" class="lens-info-icon" data-lens-info="respondent_centered" aria-label="About Respondent-Centered" aria-expanded="false">ⓘ</button>
                </span>
                <span class="lens-chip-score" id="rssiLens_respondent_centered">—</span>
              </div>
              <div class="lens-chip" data-lens="validity_forward">
                <span class="lens-chip-label">Validity-Forward <span class="lens-cap-pill" id="rssiLensCapPill" hidden>Limited evidence</span>
                  <button type="button" class="lens-info-icon" data-lens-info="validity_forward" aria-label="About Validity-Forward" aria-expanded="false">ⓘ</button>
                </span>
                <span class="lens-chip-score" id="rssiLens_validity_forward">—</span>
              </div>

              <!-- Single shared popover element for per-chip info icons.
                   Reuses the custom popover pattern (NOT browser-native title)
                   for consistency with the existing "?" affordance, no 1s
                   delay, and reliable touch support. Content populated by JS
                   based on which info icon was clicked. -->
              <div class="lens-info-popover" id="rssiLensInfoPopover" role="dialog" hidden></div>
              <button type="button" class="lens-help" id="rssiLensHelp"
                      aria-label="About the three RSSI lenses"
                      aria-expanded="false" aria-controls="rssiLensHelpPopover">?</button>
              <div class="lens-help-popover" id="rssiLensHelpPopover" role="dialog" aria-label="About the three RSSI lenses" hidden>
                <p><strong>Three lenses, one set of sub-scores.</strong> Each lens applies a different weight vector to the same eight domain scores (Spec §3.2):</p>
                <ul>
                  <li><strong>Psychometric Core</strong> — favors Reliability, Validity, and Factor Readiness. Reads "how statistically sound is this instrument?"</li>
                  <li><strong>Respondent-Centered (headline)</strong> — favors Item / Prompt Quality, Bias &amp; Clarity, and Response Scale Review. Reads "how well does this survey work for the people taking it?"</li>
                  <li><strong>Validity-Forward</strong> — favors Validity, Construct Alignment, and Bias &amp; Clarity. Reads "is there evidence this instrument measures what it claims to?"</li>
                </ul>
                <p>When the three lenses disagree by more than 10 points, an interpretation appears at the top of <em>What Do These Numbers Mean?</em> explaining why.</p>
              </div>
            </div>

            <div class="hero-meta">
              <span class="item"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="5.5"/><path d="M5 7.5 6.5 9 9.5 5.5" stroke-linecap="round" stroke-linejoin="round"/></svg> Confidence <strong id="rssiConfidence">—</strong></span>
              <span class="item"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 11V3h10v8M2 11h10M5 11v2h4v-2" stroke-linejoin="round"/></svg> <span id="rssiHeroMetaItems">— items · — scales</span></span>
              <span class="item"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 11V3m0 0 3 3M3 3l-3 3" transform="translate(4 0)" stroke-linecap="round" stroke-linejoin="round"/></svg> <span id="rssiHeroMetaSource">Uploaded data</span></span>
            </div>
          </div>

          <div class="tier">
            <div class="tier-track">
              <div class="tier-marker" id="rssiTierMarker" style="left: 0%;"></div>
            </div>
            <div class="tier-labels" id="rssiTierLabels">
              <span data-band="weak">Weak</span>
              <span data-band="fair">Fair</span>
              <span data-band="good">Good</span>
              <span data-band="strong">Strong</span>
              <span data-band="excellent">Excellent</span>
            </div>
          </div>
        </section>

        <!-- Right-column: live plain-language explanations of every score.
             Title + 1 paragraph per dimension; updates in real time. -->
        <section class="card rssi-hero-side" aria-labelledby="explainTitle">
          <h3 class="explain-title" id="explainTitle">What Do These Numbers Mean?</h3>
          <div class="explain-scroll">
            <!-- Overall = the headline (Respondent-Centered) interpretation. -->
            <div class="explain-row" data-explain="overall">
              <div class="explain-head">
                <span class="explain-label">Overall Strength Score</span>
                <span class="explain-band" id="explainBand_overall">—</span>
              </div>
              <p class="explain-text" id="explain_overall">Upload a survey to see your live explanation here.</p>
            </div>

            <!-- Disagreement readout slot. JS owns visibility — the row
                 is created and inserted here only when the engine
                 returns a non-null rssi.disagreement_readout (Spec §3.5).
                 When null, no row exists; no empty container, no
                 "lenses agree" filler. Built to the engine's null contract. -->
            <div id="rssiDisagreementSlot"></div>

            <!-- Three lens explanation rows. Body copy is static (per Spec
                 §3.2 weight-vector descriptions); band pill updates with
                 bandFor() against each lens's current score. -->
            <div class="explain-row explain-lens" data-explain="lens_psychometric_core">
              <div class="explain-head"><span class="explain-label">Psychometric Core lens</span><span class="explain-band" id="explainBand_lens_psychometric_core">—</span></div>
              <p class="explain-text">Weights reliability, validity, factor structure, and construct alignment most, with the respondent-side domains contributing less. Captures how statistically sound the instrument is. A high score means scales hold together, constructs separate cleanly, and the data is factorable.</p>
            </div>
            <div class="explain-row explain-lens" data-explain="lens_respondent_centered">
              <div class="explain-head"><span class="explain-label">Respondent-Centered lens <span class="lens-chip-badge">Headline</span></span><span class="explain-band" id="explainBand_lens_respondent_centered">—</span></div>
              <p class="explain-text">Weights item quality, bias and clarity, and response design most, with the statistical domains contributing less. Captures how well the survey works for the people taking it. A high score means items read clearly, response scales are well-designed, and respondents engage seriously.</p>
            </div>
            <div class="explain-row explain-lens" data-explain="lens_validity_forward">
              <div class="explain-head"><span class="explain-label">Validity-Forward lens <span class="lens-cap-pill" id="explainCapPill_validity_forward" hidden>Limited evidence</span></span><span class="explain-band" id="explainBand_lens_validity_forward">—</span></div>
              <p class="explain-text">Treats validity as the most important property, weighting validity, construct alignment, and bias and clarity most. Captures whether there is evidence the survey measures what it claims. A high score means convergent and discriminant validity hold, scales align with their constructs, and items are unbiased.</p>
            </div>

            <!-- Eight canonical domain rows, Spec §2 order.
                 JS populates band + interpretation copy. -->
            <div class="explain-row" data-explain="reliability">
              <div class="explain-head"><span class="explain-label">Reliability</span><span class="explain-band" id="explainBand_reliability">—</span></div>
              <p class="explain-text" id="explain_reliability">—</p>
            </div>
            <div class="explain-row" data-explain="validity">
              <div class="explain-head"><span class="explain-label">Validity</span><span class="explain-band" id="explainBand_validity">—</span></div>
              <p class="explain-text" id="explain_validity">—</p>
            </div>
            <div class="explain-row" data-explain="construct_alignment">
              <div class="explain-head"><span class="explain-label">Construct Alignment</span><span class="explain-band" id="explainBand_construct_alignment">—</span></div>
              <p class="explain-text" id="explain_construct_alignment">—</p>
            </div>
            <div class="explain-row" data-explain="item_prompt_quality">
              <div class="explain-head"><span class="explain-label">Item / Prompt Quality</span><span class="explain-band" id="explainBand_item_prompt_quality">—</span></div>
              <p class="explain-text" id="explain_item_prompt_quality">—</p>
            </div>
            <div class="explain-row" data-explain="bias_clarity">
              <div class="explain-head"><span class="explain-label">Bias &amp; Clarity Review</span><span class="explain-band" id="explainBand_bias_clarity">—</span></div>
              <p class="explain-text" id="explain_bias_clarity">—</p>
            </div>
            <div class="explain-row" data-explain="scale_structure">
              <div class="explain-head"><span class="explain-label">Scale Structure</span><span class="explain-band" id="explainBand_scale_structure">—</span></div>
              <p class="explain-text" id="explain_scale_structure">—</p>
            </div>
            <div class="explain-row" data-explain="factor_readiness">
              <div class="explain-head"><span class="explain-label">Factor Readiness</span><span class="explain-band" id="explainBand_factor_readiness">—</span></div>
              <p class="explain-text" id="explain_factor_readiness">—</p>
            </div>
            <div class="explain-row" data-explain="response_scale_review">
              <div class="explain-head"><span class="explain-label">Response Scale Review</span><span class="explain-band" id="explainBand_response_scale_review">—</span></div>
              <p class="explain-text" id="explain_response_scale_review">—</p>
            </div>
          </div>
        </section>
      </div>

      <style>
        .rssi-hero-row {
          display: grid;
          grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
          gap: 18px;
          margin-bottom: 6px;
        }
        .rssi-hero-side {
          padding: 22px 24px;
          min-width: 0;
          min-height: 100%;
          display: flex; flex-direction: column;
        }
        .explain-title {
          font-size: 16px;
          font-weight: 700;
          letter-spacing: -0.01em;
          margin: 0 0 14px;
          color: var(--ink-1, #15171a);
        }
        .explain-scroll {
          flex: 1;
          overflow-y: auto;
          padding-right: 4px;
          max-height: 360px;
        }
        .explain-row { padding: 10px 0; border-top: 1px solid rgba(15,23,42,0.06); }
        .explain-row:first-child { border-top: none; padding-top: 0; }
        .explain-head {
          display: flex; align-items: center; justify-content: space-between;
          gap: 8px;
          margin-bottom: 4px;
        }
        .explain-label {
          font-size: 12.5px; font-weight: 700;
          color: var(--ink-1, #15171a);
          letter-spacing: -0.005em;
        }
        .explain-band {
          font-size: 10px; font-weight: 700;
          text-transform: uppercase; letter-spacing: 0.04em;
          padding: 2px 7px; border-radius: 999px;
          background: #F0F1F3; color: #8E8E93;
        }
        .explain-band.status-strong   { background: rgba(52,199,89,0.12);  color: #1A7E36; }
        .explain-band.status-good     { background: rgba(0,122,255,0.10);  color: #1A6FD9; }
        .explain-band.status-fair     { background: rgba(255,159,10,0.14); color: #B26A00; }
        .explain-band.status-weak     { background: rgba(255,59,48,0.12);  color: #B82318; }
        .explain-band.status-skipped  { background: #F0F1F3;               color: #8E8E93; }
        .explain-text {
          font-size: 12.5px; line-height: 1.55;
          color: var(--ink-2, #5f6368);
          margin: 0;
        }
        /* Special highlight on the Overall row so it reads as the headline */
        .explain-row[data-explain="overall"] { background: #FAFBFC; border-radius: 10px; padding: 12px; margin-bottom: 6px; }
        .explain-row[data-explain="overall"] .explain-label { font-size: 13.5px; }
        .explain-row[data-explain="overall"] .explain-text { color: var(--ink-1, #15171a); font-weight: 500; }
        /* Trim the bottom of the hero card so the tier scale sits close
           to the dimension strip immediately below. */
        .rssi-app .rssi-hero-score.hero { padding-bottom: 14px; }
        .rssi-app .rssi-hero-score .tier { margin-top: 14px; }
        /* Dimension strip — tight under the hero row */
        #rssiDimGrid { margin-bottom: 22px; }
        /* Cronbach analyzer — full width below the dimensions */
        .rssi-overview-analyzer {
          background: var(--surface, #fff);
          border: 1px solid var(--hairline, rgba(15,23,42,0.06));
          border-radius: 18px;
          padding: 22px 24px;
        }
        @media (max-width: 1100px) {
          .rssi-hero-row { grid-template-columns: 1fr; }
        }
      </style>

      <!-- ────────────────────────────────────────────────────────────
           Floating score display.
           Engages on scroll past the inline hero (intersection observer
           in rssi.js). Tier 1 (ring + 3 lens chips) always visible when
           engaged; Tier 2 (8 domain dots) expanded by default with a
           collapse toggle. Both surfaces — inline AND float — are
           written by the same _paintLensSummary helper in the same
           render() call, so values cannot drift.
           ──────────────────────────────────────────────────────────── -->
      <div class="rssi-score-float" id="rssiScoreFloat" hidden aria-hidden="true">
        <div class="rssi-score-float-inner">

          <div class="rssi-score-float-tier1">
            <!-- Ring is intentionally absent from the float: the ring's
                 number is the Respondent-Centered score, which already
                 shows in the lens chip below. Keeping it inline at the
                 top of the page; here it would be redundant. -->
            <div class="rssi-float-lens" data-lens="psychometric_core">
              <span class="rssi-float-lens-label">Psychometric Core</span>
              <span class="rssi-float-lens-score" id="rssiFloatLens_psychometric_core">—</span>
            </div>
            <div class="rssi-float-lens rssi-float-lens-headline" data-lens="respondent_centered">
              <span class="rssi-float-lens-label">Respondent-Centered</span>
              <span class="rssi-float-lens-score" id="rssiFloatLens_respondent_centered">—</span>
            </div>
            <div class="rssi-float-lens" data-lens="validity_forward">
              <span class="rssi-float-lens-label">Validity-Forward
                <span class="lens-cap-pill" id="rssiFloatCapPill" hidden>Limited evidence</span>
              </span>
              <span class="rssi-float-lens-score" id="rssiFloatLens_validity_forward">—</span>
            </div>

            <button type="button" class="rssi-float-collapse" id="rssiFloatCollapse"
                    aria-expanded="true" aria-controls="rssiFloatTier2"
                    title="Collapse domain cards">▴</button>
          </div>

          <div class="rssi-score-float-tier2" id="rssiFloatTier2">
            <!-- Eight compact domain dots. Order matches Spec §2:
                 core psychometrics first (Reliability, Validity, Construct
                 Alignment, Factor Readiness), then instrument design (Item
                 / Prompt Quality, Bias & Clarity, Scale Structure, Response
                 Scale Review). Each dot's color reflects status band; score
                 updates in real time on toggle. -->
            <div class="rssi-float-dot" data-domain="reliability" title="Reliability">
              <span class="rssi-float-dot-mark"></span>
              <span class="rssi-float-dot-label">Reliability</span>
              <span class="rssi-float-dot-score" id="rssiFloatDot_reliability">—</span>
            </div>
            <div class="rssi-float-dot" data-domain="validity" title="Validity">
              <span class="rssi-float-dot-mark"></span>
              <span class="rssi-float-dot-label">Validity</span>
              <span class="rssi-float-dot-score" id="rssiFloatDot_validity">—</span>
            </div>
            <div class="rssi-float-dot" data-domain="construct_alignment" title="Construct Alignment">
              <span class="rssi-float-dot-mark"></span>
              <span class="rssi-float-dot-label">Construct</span>
              <span class="rssi-float-dot-score" id="rssiFloatDot_construct_alignment">—</span>
            </div>
            <div class="rssi-float-dot" data-domain="factor_readiness" title="Factor Readiness">
              <span class="rssi-float-dot-mark"></span>
              <span class="rssi-float-dot-label">Factor</span>
              <span class="rssi-float-dot-score" id="rssiFloatDot_factor_readiness">—</span>
            </div>
            <div class="rssi-float-dot" data-domain="item_prompt_quality" title="Item / Prompt Quality">
              <span class="rssi-float-dot-mark"></span>
              <span class="rssi-float-dot-label">Item Q</span>
              <span class="rssi-float-dot-score" id="rssiFloatDot_item_prompt_quality">—</span>
            </div>
            <div class="rssi-float-dot" data-domain="bias_clarity" title="Bias &amp; Clarity Review">
              <span class="rssi-float-dot-mark"></span>
              <span class="rssi-float-dot-label">Bias</span>
              <span class="rssi-float-dot-score" id="rssiFloatDot_bias_clarity">—</span>
            </div>
            <div class="rssi-float-dot" data-domain="scale_structure" title="Scale Structure">
              <span class="rssi-float-dot-mark"></span>
              <span class="rssi-float-dot-label">Scale</span>
              <span class="rssi-float-dot-score" id="rssiFloatDot_scale_structure">—</span>
            </div>
            <div class="rssi-float-dot" data-domain="response_scale_review" title="Response Scale Review">
              <span class="rssi-float-dot-mark"></span>
              <span class="rssi-float-dot-label">Resp Scale</span>
              <span class="rssi-float-dot-score" id="rssiFloatDot_response_scale_review">—</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Mini diagnostic dimension graphs — sit directly under the tier scale -->
      <div class="dim-grid" id="rssiDimGrid"></div>

      <!-- Interactive Cronbach analyzer — full width below the dimension strip -->
      <section class="rssi-overview-analyzer">
        <div id="rssiOverviewAnalyzerMount"></div>
      </section>

      <!-- Print-only refined-scale summary. Hidden on screen; revealed
           by the @media print block only when JS populates content
           (beforeprint handler reads RSSI_RELIABILITY.getRefinedScale()
           and fills these slots if the user has excluded items). The
           "Save the refined version" outcome — sits in the report
           exactly where it belongs. -->
      <section class="rssi-refined-scale-print" id="rssiRefinedScalePrint" hidden aria-hidden="true">
        <h3 class="rsp-title">Refined scale</h3>
        <p class="rsp-lede">The interactive item analysis was used to refine this scale. Below is the comparison of the original and refined Likert set.</p>
        <div class="rsp-grid">
          <div class="rsp-cell"><div class="rsp-label">Items included</div><div class="rsp-value"><span id="rsp_item_count">—</span> of <span id="rsp_original_count">—</span></div></div>
          <div class="rsp-cell"><div class="rsp-label">Cronbach's α</div><div class="rsp-value"><span id="rsp_alpha">—</span> <span class="rsp-band" id="rsp_alpha_band">—</span></div></div>
          <div class="rsp-cell"><div class="rsp-label">Δ vs. original</div><div class="rsp-value"><span id="rsp_delta">—</span> <span class="rsp-sub">(orig <span id="rsp_orig_alpha">—</span>)</span></div></div>
          <div class="rsp-cell"><div class="rsp-label">Complete responses</div><div class="rsp-value" id="rsp_n">—</div></div>
        </div>
        <h4 class="rsp-subhead">Items removed</h4>
        <ul class="rsp-list" id="rsp_excluded"></ul>
        <h4 class="rsp-subhead">Items kept</h4>
        <ol class="rsp-list" id="rsp_included"></ol>
      </section>

      <!-- Top issues panel. Visible on-screen workflow tool; hidden on
           print (committee handouts lead with the result, not a to-do). -->
      <div class="section-head rssi-issues-block">
        <div>
          <h3>Top issues to fix</h3>
          <div class="section-sub">Highest-impact items first.</div>
        </div>
      </div>

      <div class="card issues rssi-issues-block" id="rssiIssues"></div>

      <div class="rssi-print-footer">
        ReliCheck Strength Survey Index · Generated <?= date('M j, Y \a\t g:i a') ?> · <span id="rssiPrintTitle">Survey</span>
      </div>
    </div><!-- /#rssiViewOverview -->

    <!-- ─── VIEW: DIMENSION DETAIL (populated by JS on sidebar click) ─── -->
    <div class="rssi-view" id="rssiViewDetail" data-view="detail" hidden>
      <div class="title-row">
        <div>
          <a href="#" class="back-to-overview" id="rssiBackToOverview" style="color:var(--blue);font-size:13px;font-weight:500;display:inline-flex;align-items:center;gap:4px;margin-bottom:8px;">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m8 2-4 4 4 4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Back to overview
          </a>
          <h1 class="h1" id="rssiDetailTitle">Dimension</h1>
          <div class="subtitle-row">
            <span class="pill pill-blue" id="rssiDetailBand">—</span>
            <span id="rssiDetailWeight">— of overall</span>
          </div>
        </div>
      </div>

      <section class="card hero">
        <div class="ring-wrap">
          <svg viewBox="0 0 120 120">
            <circle class="ring-bg" cx="60" cy="60" r="52" fill="none" stroke-width="10"/>
            <circle class="ring-fg" id="rssiDetailRingFg" cx="60" cy="60" r="52" fill="none" stroke-width="10"
                    stroke-dasharray="326.7" stroke-dashoffset="326.7"/>
          </svg>
          <div class="ring-center">
            <div>
              <div class="score" id="rssiDetailScore">—</div>
              <div class="out-of">out of 100</div>
              <div class="badge" id="rssiDetailBadge">—</div>
            </div>
          </div>
        </div>
        <div class="hero-copy">
          <h2 id="rssiDetailH2">What this measures</h2>
          <p id="rssiDetailDesc">A plain-language description of this dimension and what it tells you about your instrument.</p>
        </div>
      </section>

      <div class="section-head">
        <div>
          <h3>What we found</h3>
          <div class="section-sub" id="rssiDetailFinding">The engine's read on your data for this dimension.</div>
        </div>
      </div>

      <div class="card" id="rssiDetailStatsCard" style="padding:24px 28px;">
        <div id="rssiDetailStats"></div>
      </div>

      <div class="section-head">
        <div>
          <h3>How to improve this score</h3>
          <div class="section-sub">Concrete next steps tailored to this dimension.</div>
        </div>
      </div>

      <div class="card" id="rssiDetailRecsCard" style="padding:8px 0;">
        <div id="rssiDetailRecs"></div>
      </div>

    </div><!-- /#rssiViewDetail -->

    <!-- ─── VIEW: RECOMMENDATIONS (issues list) ─────────────── -->
    <div class="rssi-view" id="rssiViewRecs" data-view="recommendations" hidden>
      <div class="title-row">
        <div>
          <a href="#" class="back-to-overview" id="rssiBackToOverview2" style="color:var(--blue);font-size:13px;font-weight:500;display:inline-flex;align-items:center;gap:4px;margin-bottom:8px;">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m8 2-4 4 4 4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Back to overview
          </a>
          <h1 class="h1">Recommendations</h1>
          <div class="subtitle-row">
            <span>Highest-impact issues across every dimension.</span>
          </div>
        </div>
      </div>
      <div class="card issues" id="rssiRecsList"></div>
    </div><!-- /#rssiViewRecs -->

    </div><!-- /#rssiDashboardStage -->

    <!-- placeholder: hidden until dashboard stage -->
    <input type="hidden" id="rssiProjMetaItems"><input type="hidden" id="rssiProjMetaResp">

  </main>

  <!-- =================== RIGHT RAIL =================== -->
  <aside class="rail">
    <div class="block">
      <h4>How RSSI scores your survey</h4>
      <p class="lead" style="margin: 8px 0 0;">
        RSSI runs six diagnostic checks: <strong style="color:var(--text); font-weight:600;">reliability</strong> of Likert scales, <strong style="color:var(--text); font-weight:600;">item quality</strong>, <strong style="color:var(--text); font-weight:600;">factor structure</strong>, <strong style="color:var(--text); font-weight:600;">response quality</strong>, <strong style="color:var(--text); font-weight:600;">open-ended</strong> usage, and <strong style="color:var(--text); font-weight:600;">actionability</strong> of the design.
      </p>
    </div>

    <div class="block" id="rssiPrioritiesBlock" style="display:none;">
      <h4>Improvement priorities</h4>
      <div id="rssiPriorities" style="margin-top: 6px;"></div>
    </div>

    <div class="block cta-block">
      <h4>Want full drill-down?</h4>
      <p class="lead">For per-item rewrites, factor analysis, and scale clustering, open the Survey Studio.</p>
      <a class="cta-btn" href="/app-2026v4.php">
        <span>Open Survey Studio</span>
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7h8m0 0L8 4m3 3-3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
    </div>

    <div class="block" id="rssiWhatBlock" style="display:none;">
      <h4>What this means</h4>
      <p class="lead" id="rssiWhatThisMeans" style="margin: 8px 0 0;"></p>
    </div>
  </aside>

</div>
</div>

<?php // The canonical strength-index engine exposes window.RSSI_MATH
      // unconditionally; its render path early-returns when no STRENGTH_DATASET
      // is set (which is the case on this page). The standalone RSSI surface
      // consumes the math from here per v2 build spec §6. ?>
<script src="/apps/strength-index/strength-index.js?v=<?= file_exists(__DIR__ . '/apps/strength-index/strength-index.js') ? filemtime(__DIR__ . '/apps/strength-index/strength-index.js') : time() ?>" defer></script>
<?php // Pure tag-core functions (KNOWN_ISSUES §16). Loaded BEFORE rssi-upload.js
      // so window.RSSI_TAG_CORE is available when the upload module runs. ?>
<script src="/apps/rssi/rssi-tag-core.js?v=<?= $_tag_ver ?>" defer></script>
<script src="/apps/rssi/rssi-upload.js?v=<?= $_up_ver ?>" defer></script>
<script src="/apps/rssi/rssi-reliability.js?v=<?= $_rel_ver ?>" defer></script>
<?php // The instrument-quality engine exposes window.IQ_ENGINE so the Option-3
      // construct-alignment narrative API is available. The engine's IIFE
      // bails out gracefully when there's no IQ_DATASET, so no rendering
      // side effects on this page. ?>
<script src="/apps/instrument-quality/instrument-quality.js?v=<?= file_exists(__DIR__ . '/apps/instrument-quality/instrument-quality.js') ? filemtime(__DIR__ . '/apps/instrument-quality/instrument-quality.js') : time() ?>" defer></script>
<script src="/apps/rssi/rssi-analyses.js?v=<?= $_ana_ver ?>" defer></script>
<script src="/apps/rssi/rssi.js?v=<?= $_js_ver ?>" defer></script>

</body>
</html>
