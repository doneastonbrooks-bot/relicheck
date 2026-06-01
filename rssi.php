<?php
// rssi.php — RSSI app LANDING page.
// Now on the SHARED landing chrome (_landing_head + _landing_foot), matching
// the Descriptive/Inferential/MM/TIA/360 studios: shared header, .lp-* hero +
// CTA tiles, shared footer + sticky studio dock. The RSSI-specific sample
// report card and the Upload/Saved modals stay as page-local components.
// See [[project_landing_alignment]].

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

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['rssi'];

$landing_title         = 'ReliCheck Survey Strength Index';
$landing_accent        = $studio['accent'];
$landing_accent_deep   = $studio['accent_deep'] ?? $studio['accent'];
$landing_accent_soft   = $studio['accent_soft'];
$landing_logo          = $studio['mark'];
$landing_logo_name     = $studio['name'];
$landing_pill_label    = $studio['status_label'];
$landing_show_back     = true;
$landing_user_initials = $rssi_initials;
$landing_user_full     = $rssi_user_full;
$landing_favicon       = '/RSSI-logo.png';

include __DIR__ . '/_landing_head.php';
?>

<!-- Page-local components: sample report card + Upload/Saved modals.
     Chrome (header, hero type scale, CTA tiles, footer, dock) is shared via landing.css. -->
<style>
  /* Alias the legacy RSSI blue tokens onto the shared accent system. */
  .rssi-lp { --blue: var(--accent); --blue-deep: var(--accent-deep); --blue-soft-bg: var(--accent-soft); }

  /* ───────────────── Sample report card ───────────────── */
  .sample-card {
    background: var(--surface);
    border: 1px solid var(--line, rgba(15,23,42,0.06));
    border-radius: 22px;
    padding: 42px 48px 44px;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
    max-width: 940px;
    margin: 0 auto;
  }
  .sample-eyebrow {
    text-align: center;
    font-size: 11.5px; font-weight: 700; letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--accent-deep);
    margin-bottom: 10px;
  }
  .sample-title {
    text-align: center;
    font-size: 22px; font-weight: 700; letter-spacing: -0.015em;
    color: var(--text);
    margin: 0 0 28px;
  }
  .sample-ring-wrap { display: flex; justify-content: center; margin: 0 auto 36px; }
  .sample-ring { position: relative; width: 220px; height: 220px; }
  .sample-ring svg { display: block; width: 100%; height: 100%; transform: rotate(-90deg); }
  .sample-ring-text { position: absolute; inset: 0; display: grid; place-items: center; text-align: center; }
  .sample-ring-text .num { font-size: 56px; font-weight: 700; letter-spacing: -0.04em; color: var(--text); line-height: 1; }
  .sample-ring-text .out { font-size: 13px; color: var(--text-2); margin-top: 6px; }
  .sample-lenses-cap {
    text-align: center;
    font-size: 12.5px; color: var(--text-3);
    font-weight: 500; letter-spacing: -0.005em;
    margin: -14px 0 18px;
  }
  .sample-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
  .metric-tag {
    display: inline-block;
    font-size: 9.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--accent-deep); background: var(--accent-soft);
    padding: 1px 6px; border-radius: 999px;
    vertical-align: middle; margin-left: 4px;
  }
  .metric-card {
    background: var(--surface-2, #FAFBFC);
    border: 1px solid var(--line, rgba(15,23,42,0.06));
    border-radius: 14px;
    padding: 16px 14px 18px;
  }
  .metric-icon { width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; margin-bottom: 12px; }
  .metric-icon.green  { background: rgba(52, 199, 89, 0.12);  color: #2BAE51; }
  .metric-icon.purple { background: rgba(120, 86, 220, 0.12); color: #7856DC; }
  .metric-icon.blue   { background: rgba(0, 122, 255, 0.12);  color: #0A6FE8; }
  .metric-icon.orange { background: rgba(255, 159, 10, 0.14); color: #E08800; }
  .metric-icon svg { width: 18px; height: 18px; }
  .metric-label { font-size: 13px; font-weight: 600; color: var(--text); line-height: 1.3; margin-bottom: 10px; min-height: 34px; }
  .metric-score { display: flex; align-items: baseline; gap: 4px; margin-bottom: 10px; }
  .metric-score .pct { font-size: 28px; font-weight: 700; letter-spacing: -0.025em; line-height: 1; }
  .metric-score .pct-sym { font-size: 13px; color: var(--text-3); font-weight: 500; }
  .metric-bar { height: 4px; border-radius: 999px; background: rgba(15, 23, 42, 0.06); overflow: hidden; }
  .metric-bar > i { display: block; height: 100%; border-radius: inherit; }
  .metric-bar.green  > i { background: linear-gradient(90deg, #34C759, #2BAE51); }
  .metric-bar.purple > i { background: linear-gradient(90deg, #9476ED, #7856DC); }
  .metric-bar.blue   > i { background: linear-gradient(90deg, #4FA3FF, #007AFF); }
  .metric-bar.orange > i { background: linear-gradient(90deg, #FFB23B, #FF9F0A); }

  /* ───────────────── Modals (Upload + Saved Reports) ───────────────── */
  .rssi-modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    z-index: 200;
    display: flex; align-items: center; justify-content: center;
    padding: 24px;
    animation: rssiFadeIn 0.18s ease;
  }
  .rssi-modal-backdrop[hidden] { display: none; }
  @keyframes rssiFadeIn { from { opacity: 0; } to { opacity: 1; } }
  @keyframes rssiPopIn { from { opacity: 0; transform: translateY(8px) scale(0.98); } to { opacity: 1; transform: none; } }
  .rssi-modal {
    width: 100%; max-width: 540px;
    background: #fff;
    border-radius: 22px;
    padding: 32px 32px 28px;
    box-shadow: 0 22px 60px rgba(15, 23, 42, 0.28);
    animation: rssiPopIn 0.22s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative;
  }
  .rssi-modal-close {
    position: absolute; top: 16px; right: 16px;
    width: 32px; height: 32px; border-radius: 50%;
    background: #F0F1F3; border: none; cursor: pointer;
    color: var(--text-2);
    display: grid; place-items: center;
    transition: background 0.15s;
  }
  .rssi-modal-close:hover { background: #E4E6EA; color: var(--text); }
  .rssi-modal-close svg { width: 14px; height: 14px; }
  .rssi-modal h2 { font-size: 22px; font-weight: 700; letter-spacing: -0.015em; margin: 0 0 6px; color: var(--text); }
  .rssi-modal p.modal-lede { font-size: 14px; color: var(--text-2); margin: 0 0 22px; line-height: 1.5; }
  .modal-dropzone {
    border: 2px dashed rgba(15, 23, 42, 0.10);
    border-radius: 16px;
    padding: 36px 24px;
    text-align: center; cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
    background: var(--surface-2, #FAFBFC);
  }
  .modal-dropzone:hover,
  .modal-dropzone.dragover { border-color: var(--accent); background: var(--accent-soft); }
  .modal-dropzone .dz-icon {
    width: 48px; height: 48px; border-radius: 14px;
    background: var(--accent-soft); color: var(--accent);
    display: inline-grid; place-items: center; margin-bottom: 14px;
  }
  .modal-dropzone .dz-title { font-size: 15px; font-weight: 600; color: var(--text); margin-bottom: 5px; }
  .modal-dropzone .dz-sub { font-size: 12.5px; color: var(--text-2); }
  .modal-dropzone .dz-sub strong { color: var(--accent); }
  .modal-status { margin-top: 14px; padding: 12px 14px; border-radius: 10px; font-size: 13px; display: none; }
  .modal-status.show { display: block; }
  .modal-status.info  { background: var(--accent-soft); border: 1px solid color-mix(in srgb, var(--accent) 35%, white); color: var(--accent-deep); }
  .modal-status.error { background: #FFF1F2; border: 1px solid #FECACA; color: #991B1B; }
  .modal-help { margin-top: 14px; font-size: 12.5px; color: var(--text-2); text-align: center; }
  .modal-help a { color: var(--accent); font-weight: 500; text-decoration: none; }
  .modal-help a:hover { text-decoration: underline; }
  .modal-saved-list { display: flex; flex-direction: column; gap: 8px; max-height: 50vh; overflow-y: auto; margin: 0 -8px; padding: 0 8px; }
  .saved-row {
    display: grid; grid-template-columns: 54px 1fr auto; align-items: center; gap: 14px;
    padding: 14px;
    background: var(--surface-2, #FAFBFC);
    border: 1px solid var(--line, rgba(15,23,42,0.06));
    border-radius: 12px;
    text-decoration: none; color: inherit;
    transition: background 0.15s, border-color 0.15s, transform 0.15s;
  }
  .saved-row:hover { background: #F2F4F7; border-color: rgba(15, 23, 42, 0.10); transform: translateX(2px); }
  .saved-score {
    width: 54px; height: 54px; border-radius: 12px;
    background: linear-gradient(160deg, var(--accent) 0%, var(--accent-deep) 100%);
    display: grid; place-items: center;
    font-size: 20px; font-weight: 700; color: #fff; letter-spacing: -0.02em;
  }
  .saved-name { font-size: 14px; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .saved-date { font-size: 12px; color: var(--text-2); margin-top: 2px; }
  .saved-row-arrow { width: 30px; height: 30px; border-radius: 50%; background: #EEF0F3; color: var(--text-2); display: grid; place-items: center; }
  .saved-row-arrow svg { width: 12px; height: 12px; }
  .saved-empty { text-align: center; padding: 36px 20px; color: var(--text-2); font-size: 13.5px; }
  .saved-empty strong { display: block; color: var(--text); margin-bottom: 4px; font-size: 15px; }

  @media (max-width: 880px) { .sample-metrics { grid-template-columns: 1fr 1fr; } .sample-card { padding: 32px 24px; } }
  @media (max-width: 560px) { .sample-metrics { grid-template-columns: 1fr; } }
</style>

<div class="rssi-lp">

<!-- ===== Hero ===== -->
<section class="lp-hero">
  <div class="eyebrow"><img src="<?= htmlspecialchars($studio['mark']) ?>" alt=""><?= htmlspecialchars($studio['status_label']) ?></div>
  <h1>Know if your survey <span class="accent">is strong enough to trust.</span></h1>
  <p class="lede">ReliCheck analyzes your survey's psychometric properties and gives you a clear, actionable strength score &mdash; before you rely on the results.</p>
</section>

<!-- ===== Primary actions ===== -->
<div class="lp-cta-row">
  <a class="lp-cta-tile primary" href="/rssi-app.php">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Upload Survey</div><div class="lp-cta-sub">Analyze a new survey</div></span>
  </a>
  <a class="lp-cta-tile" href="#" data-open-modal="saved">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Open Saved Report</div><div class="lp-cta-sub">Resume where you left off</div></span>
  </a>
  <a class="lp-cta-tile" href="/rssi-app.php?sample=1">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">View Sample Report</div><div class="lp-cta-sub">See what's possible</div></span>
  </a>
</div>

<!-- ===== Sample report card ===== -->
<section class="lp-section">
  <div class="sample-card">
    <div class="sample-eyebrow">Sample Report</div>
    <h2 class="sample-title">Survey Strength Index</h2>

    <div class="sample-ring-wrap">
      <div class="sample-ring">
        <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
          <defs>
            <linearGradient id="ringGradLight" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%"  stop-color="<?= htmlspecialchars($landing_accent) ?>"/>
              <stop offset="100%" stop-color="<?= htmlspecialchars($landing_accent_deep) ?>"/>
            </linearGradient>
          </defs>
          <circle cx="100" cy="100" r="86" fill="none" stroke="#EAEDF1" stroke-width="14"/>
          <!-- 82% sweep: dasharray ≈ 540.35, offset ≈ 97.26 -->
          <circle cx="100" cy="100" r="86" fill="none" stroke="url(#ringGradLight)" stroke-width="14"
                  stroke-linecap="round" stroke-dasharray="540.35" stroke-dashoffset="97.26"/>
        </svg>
        <div class="sample-ring-text">
          <div>
            <div class="num">82</div>
            <div class="out">/ 100</div>
          </div>
        </div>
      </div>
    </div>

    <div class="sample-lenses-cap">Three lenses on the same eight diagnostic domains</div>
    <div class="sample-metrics" style="grid-template-columns:repeat(3,minmax(0,1fr))">
      <div class="metric-card">
        <div class="metric-icon blue">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.5 4 5.5v6c0 4.5 3.5 8 8 9 4.5-1 8-4.5 8-9v-6z"/></svg>
        </div>
        <div class="metric-label">Psychometric<br>Core</div>
        <div class="metric-score"><span class="pct">84</span><span class="pct-sym">%</span></div>
        <div class="metric-bar blue"><i style="width:84%"></i></div>
      </div>
      <div class="metric-card">
        <div class="metric-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
        </div>
        <div class="metric-label">Respondent-Centered <span class="metric-tag">Headline</span></div>
        <div class="metric-score"><span class="pct">82</span><span class="pct-sym">%</span></div>
        <div class="metric-bar green"><i style="width:82%"></i></div>
      </div>
      <div class="metric-card">
        <div class="metric-icon orange">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2" fill="currentColor" stroke="none"/></svg>
        </div>
        <div class="metric-label">Validity-<br>Forward</div>
        <div class="metric-score"><span class="pct">71</span><span class="pct-sym">%</span></div>
        <div class="metric-bar orange"><i style="width:71%"></i></div>
      </div>
    </div>
  </div>
</section>

<!-- ─────────── MODAL: Upload Survey ─────────── -->
<div class="rssi-modal-backdrop" id="rssiUploadModal" hidden role="dialog" aria-modal="true" aria-labelledby="rssiUploadModalTitle">
  <div class="rssi-modal">
    <button class="rssi-modal-close" type="button" data-close-modal aria-label="Close">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
    </button>
    <h2 id="rssiUploadModalTitle">Upload Survey</h2>
    <p class="modal-lede">Drop a CSV or Excel file with your response data. The Strength Index will score your instrument in seconds.</p>

    <div class="modal-dropzone" id="modalDropzone">
      <input type="file" id="modalFileInput" hidden accept=".csv,.xlsx,.xls,.tsv,.txt">
      <div class="dz-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      </div>
      <div class="dz-title">Drag &amp; drop your file</div>
      <div class="dz-sub">or <strong>click to browse</strong> &mdash; CSV, XLSX, XLS, TSV</div>
    </div>

    <div class="modal-status" id="modalStatus"></div>

    <div class="modal-help">
      Don't have a file handy? <a href="/rssi-app.php?sample=1">View a sample report &rarr;</a>
    </div>
  </div>
</div>

<!-- ─────────── MODAL: Saved Reports ─────────── -->
<div class="rssi-modal-backdrop" id="rssiSavedModal" hidden role="dialog" aria-modal="true" aria-labelledby="rssiSavedModalTitle">
  <div class="rssi-modal">
    <button class="rssi-modal-close" type="button" data-close-modal aria-label="Close">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>
    </button>
    <h2 id="rssiSavedModalTitle">Saved Reports</h2>
    <p class="modal-lede">Previously scored surveys on this browser. Click any report to reopen it.</p>
    <div class="modal-saved-list" id="modalSavedList"></div>
  </div>
</div>

</div><!-- /.rssi-lp -->

<script>
(function () {
  'use strict';

  function openModal(name) {
    const id = name === 'upload' ? 'rssiUploadModal' : 'rssiSavedModal';
    const el = document.getElementById(id);
    if (!el) return;
    el.hidden = false;
    document.body.style.overflow = 'hidden';
    if (name === 'saved') populateSavedList();
    if (name === 'upload') {
      const status = document.getElementById('modalStatus');
      if (status) { status.className = 'modal-status'; status.textContent = ''; }
    }
  }
  function closeAllModals() {
    document.querySelectorAll('.rssi-modal-backdrop').forEach(function (m) { m.hidden = true; });
    document.body.style.overflow = '';
  }

  document.querySelectorAll('[data-open-modal]').forEach(function (el) {
    el.addEventListener('click', function (e) { e.preventDefault(); openModal(el.getAttribute('data-open-modal')); });
  });
  document.querySelectorAll('[data-close-modal]').forEach(function (el) { el.addEventListener('click', closeAllModals); });
  document.querySelectorAll('.rssi-modal-backdrop').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m) closeAllModals(); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAllModals(); });

  /* Upload modal: drag-drop → stage to sessionStorage → /rssi-upload.php */
  const dz   = document.getElementById('modalDropzone');
  const file = document.getElementById('modalFileInput');
  const status = document.getElementById('modalStatus');
  function setStatus(msg, kind) {
    if (!status) return;
    status.innerHTML = msg;
    status.className = 'modal-status show ' + (kind || 'info');
  }
  function handleFile(f) {
    if (!f) return;
    setStatus('<strong>Reading ' + escapeHtml(f.name) + '&hellip;</strong> ' + Math.round(f.size / 1024) + ' KB', 'info');
    const reader = new FileReader();
    reader.onload = function (ev) {
      try {
        sessionStorage.setItem('rssi.pendingFile', JSON.stringify({
          name: f.name, type: f.type || '', data: ev.target.result,
        }));
        setStatus('<strong>Opening report&hellip;</strong>', 'info');
        window.location = '/rssi-upload.php?frommodal=1';
      } catch (e) { setStatus('<strong>Could not stage file:</strong> ' + escapeHtml(e.message || String(e)), 'error'); }
    };
    reader.onerror = function () { setStatus('<strong>Could not read the file.</strong>', 'error'); };
    reader.readAsDataURL(f);
  }
  if (dz && file) {
    dz.addEventListener('click', function () { file.click(); });
    file.addEventListener('change', function () { if (file.files && file.files[0]) handleFile(file.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('dragover'); });
    });
    dz.addEventListener('drop', function (e) {
      const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) handleFile(f);
    });
  }

  function populateSavedList() {
    const list = document.getElementById('modalSavedList');
    if (!list) return;
    // Server-side saved datasets. Each row routes to
    // /rssi-app.php?dataset_id=N, which triggers _loadFromDataset on init.
    list.innerHTML = '<div class="saved-empty"><strong>Loading saved projects…</strong></div>';
    fetch('/api/datasets/list.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        if (!out.ok) {
          list.innerHTML = '<div class="saved-empty"><strong>Could not load your saved projects.</strong>Try refreshing the page.</div>';
          return;
        }
        const rows = (out.body && out.body.datasets) || [];
        if (!rows.length) {
          list.innerHTML = '<div class="saved-empty"><strong>No saved projects yet</strong>Upload your first survey to start building your library.</div>';
          return;
        }
        list.innerHTML = rows.map(function (r) {
          const when    = r.updated_at ? new Date(r.updated_at).toLocaleString() : '';
          const likert  = r.likert_count || 0;
          const rowCnt  = r.row_count    || 0;
          const meta    = (likert ? (likert + ' Likert items') : '') +
                          (likert && rowCnt ? ' · ' : '') +
                          (rowCnt ? (rowCnt + ' responses') : '') +
                          ((likert || rowCnt) && when ? ' · ' : '') + escapeHtml(when);
          const title   = r.title || r.source_filename || 'Untitled';
          const href    = '/rssi-app.php?dataset_id=' + encodeURIComponent(r.id);
          return '<a class="saved-row" href="' + href + '">' +
                   '<div class="saved-score" style="font-size:14px;">📄</div>' +
                   '<div><div class="saved-name">' + escapeHtml(title) + '</div>' +
                   '<div class="saved-date">' + meta + '</div></div>' +
                   '<span class="saved-row-arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg></span>' +
                 '</a>';
        }).join('');
      })
      .catch(function () {
        list.innerHTML = '<div class="saved-empty"><strong>Could not load your saved projects.</strong>Network error — try refreshing.</div>';
      });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
    });
  }
})();
</script>

<?php
$landing_tagline = 'Move from uncertainty to insight — in minutes.';
include __DIR__ . '/_landing_foot.php';
