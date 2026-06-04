<?php
// text-analyzer.php — ReliCheck OpenText App
// Fast AI-powered first-pass thematic analysis of open-ended text.
// Stateless: no project table. Two outputs: suggested themes + quantify to variables.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    header('Location: /login.html?return=' . urlencode('/text-analyzer.php'));
    exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$BOOT = [
    'initials'    => $initials,
    'initialStep' => 'input',
];

function _ta_qsv(string $path): string {
    $full = __DIR__ . $path;
    return is_file($full) ? (string)filemtime($full) : (string)time();
}
header('Cache-Control: no-store, must-revalidate');
header('Pragma: no-cache');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>ReliCheck OpenText</title>
<link rel="icon" href="/logo-brand.svg">
<style>
/* ── Design tokens ── */
:root {
  --ink:#15171a; --ink-2:#5f6368; --ink-3:#8a8f98;
  --bg:#f5f6f8;  --panel:#fff;  --line:#e6e8ec; --line-2:#eef0f3;
  --btn:#D97706; --btn-hover:#B45309;
  --acc:#D97706; --acc-soft:#FEF3C7; --acc-deep:#92400E;
  --green:#1f9e44; --green-soft:#e9f7ee;
  --font: -apple-system, BlinkMacSystemFont, "SF Pro Text", Inter, system-ui, sans-serif;
  --rail:214px; --companion:268px;
}

/* ── Shell ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; background: var(--bg); }
body { font-family: var(--font); color: var(--ink); font-size: 14px; line-height: 1.5;
  -webkit-font-smoothing: antialiased; }
.app  { display: grid; grid-template-rows: auto 1fr auto; height: 100vh; }
.body { display: grid; grid-template-columns: var(--rail) minmax(0,1fr) var(--companion); min-height: 0; overflow: hidden; }

/* Rail */
.rail { border-right: 1px solid var(--line); background: var(--panel); overflow-y: auto; padding: 14px 0; }
.rail-h { padding: 6px 16px 10px; font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .06em; color: var(--ink-3); }
.step { display: flex; align-items: center; gap: 10px; padding: 9px 16px; cursor: pointer;
  border: none; background: none; font: inherit; color: var(--ink-2); font-size: 13px;
  font-weight: 500; width: 100%; text-align: left; transition: background .12s; }
.step:hover { background: var(--line-2); }
.step[data-active='1'] { background: var(--acc-soft); color: var(--acc-deep); font-weight: 700; }
.step[data-done='1']   { color: var(--ink-3); }
.step .sn { width: 22px; height: 22px; border-radius: 50%; background: var(--line); display: flex;
  align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex: none; }
.step[data-active='1'] .sn { background: var(--acc); color: #fff; }
.step[data-done='1']   .sn { background: var(--green-soft); color: var(--green); font-size: 0; }
.step[data-done='1']   .sn::after { content: '✓'; font-size: 12px; }
.step[data-done='1']::after { content: '✓'; margin-left: auto; font-size: 12px; font-weight: 700; color: var(--green); }

/* Stage */
.stage  { display: flex; flex-direction: column; overflow: hidden; }
.center { flex: 1; overflow-y: auto; padding: 28px 32px; }

/* Companion panel */
.companion { background:var(--panel);border-left:1px solid var(--line);display:flex;flex-direction:column;min-height:0;overflow:hidden;position:relative }
.comp-head { display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line-2) }
.comp-head .ch-ico { width:30px;height:30px;border-radius:9px;background:var(--acc-soft);color:var(--acc-deep);display:grid;place-items:center;font-size:15px;flex:none }
.comp-head h3 { font-size:14px;font-weight:700;margin:0 }
.comp-head .ch-sub { font-size:11px;color:var(--ink-3) }
.comp-toggle { margin-left:auto;width:26px;height:26px;border-radius:7px;border:1px solid var(--line);background:var(--panel);color:var(--ink-2);display:grid;place-items:center;flex:none;cursor:pointer }
.comp-tabs { display:flex;gap:4px;padding:10px 14px 0 }
.comp-tab { flex:1;text-align:center;padding:8px 6px;border-radius:9px;font-size:12px;font-weight:700;color:var(--ink-3);border:none;background:none;cursor:pointer;font-family:inherit }
.comp-tab.active { background:var(--acc-soft);color:var(--acc-deep) }
.comp-body { padding:16px;overflow-y:auto;flex:1 }
.comp-block { margin-bottom:16px }
.cb-k { font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:7px;color:var(--ink-3) }
.cb-k .i { width:16px;height:16px;border-radius:5px;display:grid;place-items:center;font-size:10px;color:#fff;background:var(--acc) }
.cb-t { font-size:13px;line-height:1.55;color:var(--ink-2) }
.cb-t b { color:var(--ink);font-weight:700 }
.notes-area { width:100%;min-height:200px;border:1px solid var(--line);border-radius:12px;padding:12px;font-family:inherit;font-size:13px;resize:vertical;color:var(--ink) }
.ai-prompt { border:1px solid var(--line);border-radius:12px;padding:12px;font-size:13px;color:var(--ink-3);background:var(--bg);margin-bottom:12px }
.ai-suggest { display:flex;flex-direction:column;gap:8px }
.ai-chip { text-align:left;border:1px solid var(--line);background:var(--panel);border-radius:10px;padding:10px 12px;font-size:12.5px;font-weight:600;color:var(--ink);cursor:pointer;font-family:inherit }
.ai-chip:hover { border-color:var(--acc);background:var(--acc-soft);color:var(--acc-deep) }
.ai-answer { border:1px solid rgba(217,119,6,.22);background:var(--acc-soft);border-radius:12px;padding:13px 14px;font-size:13px;line-height:1.55;color:var(--acc-deep);margin-top:12px }
.comp-collapsed-tab { display:none }
.ctab-vert { writing-mode:vertical-rl;transform:rotate(180deg);font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--acc-deep) }
body.companion-collapsed { --companion:46px }
body.companion-collapsed .comp-body, body.companion-collapsed .comp-tabs,
body.companion-collapsed .comp-head h3, body.companion-collapsed .comp-head .ch-meta { display:none }
body.companion-collapsed .comp-head { justify-content:center;padding:14px 6px }
body.companion-collapsed .comp-toggle { margin-left:0 }
body.companion-collapsed .comp-collapsed-tab { display:flex;flex-direction:column;align-items:center;gap:14px;padding-top:18px;cursor:pointer }
@media (max-width:1280px) { .body { grid-template-columns:var(--rail) minmax(0,1fr) } .companion { display:none } }
@media print { .rail, .companion, #studioHeader, #studioFooter { display:none !important } .body { display:block !important } .center { overflow:visible !important;padding:0 !important } }

/* ── Shared button ── */
.btn { display:inline-flex; align-items:center; gap:7px; padding:9px 18px; border-radius:10px;
  background:var(--btn); border:none; color:#fff; font:inherit; font-size:13.5px;
  font-weight:700; cursor:pointer; transition:background .15s; }
.btn:hover { background:var(--btn-hover); }
.btn:disabled { opacity:.6; cursor:default; }
.btn-ghost { background:none; color:var(--ink-2); border:1px solid var(--line); }
.btn-ghost:hover { background:var(--bg); border-color:var(--ink-3); color:var(--ink); }

/* ── Input step ── */
.ws-header { margin-bottom:24px }
.ws-eyebrow { font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);margin-bottom:10px }
.ws-title { font-size:26px;font-weight:800;line-height:1.2;margin-bottom:8px;color:var(--ink) }
.ws-title em { font-style:normal;color:var(--acc) }
.ws-lede { font-size:14px;line-height:1.6;color:var(--ink-2);max-width:560px }

.ta-tabs { display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid var(--line);padding-bottom:0 }
.ta-tab { padding:9px 16px 11px;font:inherit;font-size:13px;font-weight:600;border:none;background:none;
  cursor:pointer;color:var(--ink-3);border-bottom:2px solid transparent;margin-bottom:-2px }
.ta-tab.active { color:var(--acc-deep);border-bottom-color:var(--acc) }

.ta-paste-area { width:100%;min-height:220px;border:1px solid var(--line);border-radius:12px;
  padding:14px 16px;font:inherit;font-size:13.5px;line-height:1.6;resize:vertical;
  color:var(--ink);background:var(--panel);transition:border-color .15s }
.ta-paste-area:focus { outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-soft) }

.ta-format-row { display:flex;align-items:center;gap:10px;margin-top:10px;font-size:13px;color:var(--ink-2) }
.ta-format-row select { font:inherit;font-size:13px;padding:6px 10px;border:1px solid var(--line);
  border-radius:8px;background:var(--panel);color:var(--ink);cursor:pointer }

/* ── Upload zone (no data loaded) ── */
.ta-upload-zone { border:2px dashed var(--line);border-radius:16px;padding:40px 28px;
  text-align:center;background:var(--panel);transition:border-color .15s,background .15s }
.ta-upload-zone:hover { border-color:var(--acc);background:var(--acc-soft) }
.ta-uz-icon { width:46px;height:46px;border-radius:13px;background:var(--acc-soft);color:var(--acc);
  display:grid;place-items:center;font-size:22px;margin:0 auto 14px;transition:background .15s }
.ta-upload-zone:hover .ta-uz-icon { background:#fde68a }
.ta-uz-title { font-size:16px;font-weight:700;color:var(--ink);margin-bottom:6px }
.ta-uz-types { font-size:12.5px;color:var(--ink-3);margin-bottom:22px;line-height:1.6 }
.ta-uz-actions { display:flex;align-items:center;justify-content:center;gap:16px }
.ta-uz-btn { display:inline-flex;align-items:center;gap:7px;padding:9px 22px;border-radius:10px;
  background:var(--btn);border:none;color:#fff;font:inherit;font-size:13.5px;font-weight:700;
  cursor:pointer;transition:background .15s }
.ta-uz-btn:hover { background:var(--btn-hover) }
.ta-uz-saved { font:inherit;font-size:13px;font-weight:600;color:var(--ink-2);background:none;
  border:none;cursor:pointer;padding:0;transition:color .12s }
.ta-uz-saved:hover { color:var(--acc-deep) }

/* ── Dataset loaded card ── */
.ta-dataset-card { border:1px solid var(--line);border-radius:14px;background:var(--panel);overflow:hidden }
.ta-dc-head { display:flex;align-items:center;gap:10px;padding:13px 18px;
  border-bottom:1px solid var(--line-2);background:var(--green-soft) }
.ta-dc-dot { width:9px;height:9px;border-radius:50%;background:var(--green);flex:none }
.ta-dc-title { font-size:14px;font-weight:700;color:var(--ink);flex:1;min-width:0;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap }
.ta-dc-meta { font-size:12.5px;color:var(--ink-3);flex:none }
.ta-dc-change { font:inherit;font-size:12.5px;font-weight:600;color:var(--acc-deep);
  background:none;border:none;cursor:pointer;padding:0;flex:none;margin-left:8px }
.ta-dc-change:hover { text-decoration:underline }
.ta-dc-body { padding:16px 18px }
.ta-dc-label { font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;
  color:var(--ink-3);margin-bottom:8px }
.ta-dc-select { width:100%;padding:9px 34px 9px 12px;border:1px solid var(--line);border-radius:9px;
  font:inherit;font-size:13px;color:var(--ink);background:var(--bg);cursor:pointer;
  -webkit-appearance:none;appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238a8f98' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 12px center;transition:border-color .15s }
.ta-dc-select:focus { outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-soft) }
.ta-dc-count { margin-top:9px;font-size:12.5px;font-weight:600 }
.ta-dc-count.good { color:var(--green) }
.ta-dc-count.empty { color:var(--ink-3);font-weight:400 }

/* ── Paste count bar ── */
.ta-loaded-bar { display:flex;align-items:center;gap:10px;padding:11px 16px;
  border:1px solid var(--line);border-radius:10px;background:var(--green-soft);
  font-size:13px;margin-top:14px }
.ta-loaded-bar .tl-dot { width:8px;height:8px;border-radius:50%;background:var(--green);flex:none }
.ta-loaded-bar .tl-count { font-weight:700;color:var(--green) }
.ta-loaded-bar .tl-label { color:var(--ink-2) }

.ta-ctx-card { margin-top:20px;padding:18px 20px;border:1px solid var(--line);border-radius:12px;background:var(--panel) }
.ta-ctx-label { font-size:13px;font-weight:700;color:var(--ink);display:block;margin-bottom:4px }
.ta-ctx-hint { font-size:12.5px;color:var(--ink-3);margin-bottom:10px }
.ta-ctx-field { width:100%;min-height:72px;border:1px solid var(--line);border-radius:10px;
  padding:10px 12px;font:inherit;font-size:13px;resize:vertical;color:var(--ink);transition:border-color .15s }
.ta-ctx-field:focus { outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-soft) }

.ta-analyze-row { margin-top:22px;display:flex;align-items:center;gap:14px }
.ta-count-note { font-size:12.5px;color:var(--ink-3) }

/* ── Themes step ── */
.ta-step-head { margin-bottom:22px }
.ta-step-head h1 { font-size:24px;font-weight:800;margin-bottom:6px }
.ta-step-head p { font-size:14px;color:var(--ink-2) }

.ta-preliminary-badge { display:inline-flex;align-items:center;gap:6px;padding:5px 11px;
  border-radius:20px;background:var(--acc-soft);color:var(--acc-deep);font-size:11.5px;
  font-weight:700;letter-spacing:.03em;margin-bottom:16px }

.ta-summary-card { background:var(--panel);border:1px solid var(--line);border-radius:12px;
  padding:18px 20px;margin-bottom:20px }
.ta-summary-card .ts-label { font-size:10.5px;font-weight:800;letter-spacing:.07em;
  text-transform:uppercase;color:var(--ink-3);margin-bottom:8px }
.ta-summary-card p { font-size:14px;line-height:1.6;color:var(--ink-2) }

.ta-themes-grid { display:flex;flex-direction:column;gap:14px;margin-bottom:22px }

.ta-theme-card { background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:20px 22px }
.ta-theme-card .tc-head { display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px }
.ta-theme-card .tc-name { font-size:16px;font-weight:800;color:var(--ink) }
.ta-theme-card .tc-prom { display:inline-flex;align-items:center;padding:4px 10px;border-radius:20px;
  font-size:11px;font-weight:700;letter-spacing:.03em;flex:none }
.tc-prom-high { background:#fef3c7;color:#92400e }
.tc-prom-moderate { background:#fef9c3;color:#854d0e }
.tc-prom-low { background:var(--bg);color:var(--ink-3) }
.ta-theme-card .tc-desc { font-size:13.5px;line-height:1.6;color:var(--ink-2);margin-bottom:14px }
.ta-theme-card .tc-quotes-label { font-size:10.5px;font-weight:800;letter-spacing:.07em;
  text-transform:uppercase;color:var(--ink-3);margin-bottom:8px }
.ta-theme-card .tc-quotes { display:flex;flex-direction:column;gap:8px }
.ta-quote-candidate { background:var(--bg);border-left:3px solid var(--acc);border-radius:0 8px 8px 0;
  padding:10px 14px;font-size:13px;line-height:1.55;color:var(--ink-2);font-style:italic }
.ta-theme-card .tc-note { font-size:12px;color:var(--ink-3);margin-top:10px }

.ta-counter-card { background:var(--panel);border:1px solid var(--line);border-radius:12px;
  padding:18px 20px;margin-bottom:22px }
.ta-counter-card .tc-label { font-size:10.5px;font-weight:800;letter-spacing:.07em;
  text-transform:uppercase;color:var(--ink-3);margin-bottom:10px }
.ta-counter-list { display:flex;flex-direction:column;gap:8px }
.ta-counter-item { display:flex;align-items:flex-start;gap:10px;font-size:13px;color:var(--ink-2) }
.ta-counter-item::before { content:'↙';color:var(--acc);font-weight:700;flex:none }

.ta-transfer-row { display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:18px 0;
  border-top:1px solid var(--line-2) }
.ta-transfer-row .tr-label { font-size:12px;font-weight:700;color:var(--ink-3);
  text-transform:uppercase;letter-spacing:.05em;margin-right:4px }

.ta-loading { text-align:center;padding:60px 20px }
.ta-loading .tl-spinner { width:36px;height:36px;border-radius:50%;
  border:3px solid var(--acc-soft);border-top-color:var(--acc);
  animation:ta-spin .8s linear infinite;margin:0 auto 14px }
@keyframes ta-spin { to { transform:rotate(360deg) } }
.ta-loading p { font-size:14px;color:var(--ink-2) }
.ta-loading .tl-note { font-size:12.5px;color:var(--ink-3);margin-top:6px }

.ta-err { background:#fef2f2;border:1px solid #fecaca;border-radius:12px;
  padding:16px 18px;font-size:13.5px;color:#991b1b;margin-bottom:18px }

/* ── Save modal ── */
.ta-modal-backdrop { position:fixed;inset:0;background:rgba(0,0,0,.44);z-index:1000;
  display:flex;align-items:center;justify-content:center;padding:20px }
.ta-modal-box { background:var(--panel);border-radius:18px;width:100%;max-width:460px;
  box-shadow:0 12px 50px rgba(0,0,0,.2);display:flex;flex-direction:column }
.ta-modal-head { display:flex;align-items:center;justify-content:space-between;
  padding:20px 24px 16px;border-bottom:1px solid var(--line) }
.ta-modal-head h2 { font-size:18px;font-weight:800;margin:0 }
.ta-modal-close { width:30px;height:30px;border-radius:8px;border:1px solid var(--line);
  background:var(--panel);color:var(--ink-2);font-size:18px;cursor:pointer;
  display:grid;place-items:center;line-height:1;flex:none }
.ta-modal-close:hover { background:var(--bg) }
.ta-modal-body { padding:20px 24px }
.ta-modal-label { display:block;font-size:13px;font-weight:700;color:var(--ink);margin-bottom:6px }
.ta-modal-input { width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:9px;
  font:inherit;font-size:14px;color:var(--ink);transition:border-color .15s }
.ta-modal-input:focus { outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-soft) }
.ta-modal-summary { display:flex;gap:20px;margin-top:16px;padding:14px 16px;
  background:var(--bg);border-radius:10px }
.ta-ms-row { display:flex;flex-direction:column;gap:2px }
.ta-ms-n { font-size:20px;font-weight:800;color:var(--ink) }
.ta-ms-l { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3) }
.ta-modal-note { font-size:12.5px;color:var(--ink-3);margin-top:14px;line-height:1.55 }
.ta-modal-foot { padding:16px 24px;border-top:1px solid var(--line);
  display:flex;align-items:center;gap:10px }

/* ── Saving progress bar ── */
.ta-saving-bar { display:flex;align-items:center;padding:12px 18px;background:var(--acc-soft);
  border:1px solid #fde68a;border-radius:10px;font-size:13px;color:var(--acc-deep);
  margin-bottom:18px }

/* ── Save success ── */
.ta-success-card { text-align:center;padding:36px 24px 28px;background:var(--panel);
  border:1px solid var(--line);border-radius:16px;margin-bottom:22px }
.ta-sc-check { width:48px;height:48px;border-radius:50%;background:var(--green-soft);
  color:var(--green);display:grid;place-items:center;font-size:22px;font-weight:800;
  margin:0 auto 14px }
.ta-sc-title { font-size:22px;font-weight:800;margin-bottom:6px }
.ta-sc-name { font-size:15px;color:var(--ink-2);margin-bottom:4px }
.ta-sc-meta { font-size:12.5px;color:var(--ink-3) }
.ta-open-grid { margin-bottom:22px }
.ta-og-label { font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;
  color:var(--ink-3);margin-bottom:12px }
.ta-og-cards { display:grid;grid-template-columns:1fr 1fr;gap:12px }
.ta-og-card { display:flex;flex-direction:column;gap:5px;padding:16px 18px;
  border:1px solid var(--line);border-radius:13px;background:var(--panel);
  text-decoration:none;color:inherit;transition:border-color .15s,box-shadow .15s }
.ta-og-card:hover { border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-soft) }
.ta-og-name { font-size:14px;font-weight:700;color:var(--ink) }
.ta-og-desc { font-size:12.5px;color:var(--ink-3);line-height:1.45;flex:1 }
.ta-og-arrow { font-size:12.5px;font-weight:700;color:var(--acc);margin-top:6px }
.ta-sc-footer { display:flex;align-items:center;gap:12px }

/* ── Quantify step ── */
.ta-matrix-card { background:var(--panel);border:1px solid var(--line);border-radius:14px;
  overflow:hidden;margin-bottom:18px }
.ta-matrix-head { padding:16px 20px;border-bottom:1px solid var(--line-2);
  display:flex;align-items:center;justify-content:space-between }
.ta-matrix-head h3 { font-size:15px;font-weight:800;color:var(--ink) }
.ta-matrix-head p { font-size:12.5px;color:var(--ink-3) }
.ta-matrix-wrap { max-height:440px;overflow:auto }
.ta-matrix-table { width:100%;border-collapse:collapse;font-size:12.5px }
.ta-matrix-table th { position:sticky;top:0;background:var(--bg);padding:8px 12px;
  font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;
  color:var(--ink-3);text-align:left;border-bottom:1px solid var(--line);white-space:nowrap }
.ta-matrix-table th:not(:first-child) { text-align:center }
.ta-matrix-table td { padding:9px 12px;border-bottom:1px solid var(--line-2);
  color:var(--ink-2);vertical-align:middle }
.ta-matrix-table td:first-child { max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--ink) }
.ta-matrix-table td:not(:first-child) { text-align:center }
.ta-present { color:var(--acc);font-weight:800;font-size:14px }
.ta-absent  { color:var(--line);font-size:14px }

.ta-stats-row { display:flex;gap:20px;padding:14px 20px;background:var(--bg);
  border-top:1px solid var(--line-2);flex-wrap:wrap }
.ta-stat { display:flex;flex-direction:column;gap:2px }
.ta-stat .tst-n { font-size:20px;font-weight:800;color:var(--ink) }
.ta-stat .tst-l { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3) }
</style>

<script src="/apps/studio/studio-header.js?v=<?= _ta_qsv('/apps/studio/studio-header.js') ?>"></script>
<script src="/apps/studio/studio-footer.js?v=<?= _ta_qsv('/apps/studio/studio-footer.js') ?>"></script>
<script src="/apps/studio/dataset-upload.js?v=<?= _ta_qsv('/apps/studio/dataset-upload.js') ?>"></script>
</head>
<body>
<div class="app">

  <div id="studioHeader"></div>

  <div class="body">

    <nav class="rail" id="rail">
      <div class="rail-h">Steps</div>
      <button class="step" data-step="input">
        <span class="sn">01</span> Input
      </button>
      <button class="step" data-step="themes">
        <span class="sn">02</span> Suggested Themes
      </button>
      <button class="step" data-step="quantify">
        <span class="sn">03</span> Quantify
      </button>
    </nav>

    <div class="stage">
      <main class="center" id="centerInner">
        <p style="color:var(--ink-3);font-size:14px;">Loading&hellip;</p>
      </main>
    </div>

    <aside class="companion" id="companion">
      <div class="comp-collapsed-tab" onclick="toggleCompanion()">
        <span style="font-size:16px">✦</span>
        <span class="ctab-vert">Coach</span>
      </div>
      <div class="comp-head">
        <div class="ch-ico">✦</div>
        <div class="ch-meta">
          <h3>ReliCheck Coach</h3>
          <div class="ch-sub">Explain &middot; Notes &middot; Intelligence</div>
        </div>
        <button class="comp-toggle" onclick="toggleCompanion()" title="Collapse">&#10095;</button>
      </div>
      <div class="comp-tabs" id="compTabs"></div>
      <div class="comp-body" id="compBody"></div>
    </aside>

  </div>

  <div id="studioFooter"></div>

</div>

<script>const BOOT = <?= json_encode($BOOT, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="/apps/studio/text-analyzer.js?v=<?= _ta_qsv('/apps/studio/text-analyzer.js') ?>" defer></script>
</body>
</html>
