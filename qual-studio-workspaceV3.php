<?php
// studio-app-template.php — ReliCheck STUDIO / APP TEMPLATE v5.
// Copy this file and rename it for each new studio. Fill in the CONFIG block.
//
// Shell contract:
//   StudioHeader (id="studioHeader") → .body → rail + .stage/.center + .companion → StudioFooter (id="studioFooter")
//   studio-header.js + studio-footer.js handle the uniform header and footer.
//   App-specific JS (studio-app-template.js) calls StudioHeader.init() + StudioFooter.init() on boot.
//   Companion panel (right column) has three tabs: Guidance | Notes | Intelligence.
//
// References: qual-studio-workspace.php, mmstudioV4.php

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /login.html?return=' . urlencode('/RENAME-ME.php' . $qs));
    exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$pdo = db();

// ─── PROJECT LOAD ────────────────────────────────────────────────────────────
// Uncomment and adapt once you have a project table for this studio.
// $projectId  = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
// $projectRow = null;
// if ($projectId > 0) {
//     $s = $pdo->prepare('SELECT * FROM YOUR_TABLE WHERE id=:id AND user_id=:u LIMIT 1');
//     $s->execute([':id' => $projectId, ':u' => $uid]);
//     $projectRow = $s->fetch(PDO::FETCH_ASSOC);
//     if (!$projectRow) $projectId = 0;
// }
$projectId  = 0;
$projectRow = null;

$datasetId = isset($_GET['dataset_id']) ? max(0, (int)$_GET['dataset_id']) : 0;

// ─── CONFIG ──────────────────────────────────────────────────────────────────

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

// The BOOT object is JSON-encoded into the page and read by the app JS.
// Add any other fields your app needs here.
$BOOT = [
    'projectId'    => $projectId,
    'project'      => $projectRow,
    'projectLabel' => $projectRow ? ($projectRow['title'] ?? '') : '',
    'projectLive'  => $datasetId > 0,
    'projectsUrl'  => '/RENAME-ME-projects.php',  // "All projects" link in the header
    'projectType'  => 'analysis',                  // TODO: set to rssi | survey | qual | mm | analysis
    'datasetId'    => $datasetId,
    'initials'     => $initials,
    // 'initialStep' => 'start',                  // optional: step to open on load
];

// ─── END CONFIG ──────────────────────────────────────────────────────────────

// Cache-busting helper
function _tpl_qsv(string $path): string {
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
<title><?= $projectRow ? htmlspecialchars($projectRow['title'] ?? '') . ' — ' : '' ?>App Name — ReliCheck</title>
<link rel="icon" href="/logo-brand.svg">
<style>
/* ── Design tokens — copy from an existing studio or override here ── */
:root {
  --ink:#15171a; --ink-2:#5f6368; --ink-3:#8a8f98;
  --bg:#f5f6f8;  --panel:#fff;  --line:#e6e8ec; --line-2:#eef0f3;
  --btn:#1e5c3a; --btn-hover:#174d30;
  --acc:#1e5c3a; --acc-soft:#e8f5ee; --acc-deep:#174d30;
  --green:#1f9e44; --green-soft:#e9f7ee;
  --qual:#1e5c3a; --qual-soft:#e8f5ee; --qual-ink:#174d30;
  --font: -apple-system, BlinkMacSystemFont, "SF Pro Text", Inter, system-ui, sans-serif;
  --rail:214px; --companion:268px;
}

/* ── App shell layout ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; background: var(--bg); }
body { font-family: var(--font); color: var(--ink); font-size: 14px; line-height: 1.5;
  -webkit-font-smoothing: antialiased; }
.app  { display: grid; grid-template-rows: auto 1fr auto; height: 100vh; }
.body { display: grid; grid-template-columns: var(--rail) minmax(0,1fr) var(--companion); min-height: 0; overflow: hidden; }

/* Rail (left step list) */
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

/* Stage (main content) */
.stage  { display: flex; flex-direction: column; overflow: hidden; }
.center { flex: 1; overflow-y: auto; padding: 28px 32px; }

/* Companion panel (right coaching column) — mirrors MM Studio */
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
.ai-answer { border:1px solid rgba(30,92,58,.22);background:var(--acc-soft);border-radius:12px;padding:13px 14px;font-size:13px;line-height:1.55;color:var(--acc-deep);margin-top:12px }

/* Collapsed state */
.comp-collapsed-tab { display:none }
.ctab-vert { writing-mode:vertical-rl;transform:rotate(180deg);font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--acc-deep) }
body.companion-collapsed { --companion:46px }
body.companion-collapsed .comp-body,
body.companion-collapsed .comp-tabs,
body.companion-collapsed .comp-head h3,
body.companion-collapsed .comp-head .ch-meta { display:none }
body.companion-collapsed .comp-head { justify-content:center;padding:14px 6px }
body.companion-collapsed .comp-toggle { margin-left:0 }
body.companion-collapsed .comp-collapsed-tab { display:flex;flex-direction:column;align-items:center;gap:14px;padding-top:18px;cursor:pointer }

@media (max-width:1280px) { .body { grid-template-columns:var(--rail) minmax(0,1fr) } .companion { display:none } }

@media print {
  .rail, .companion, #studioHeader, #studioFooter { display:none !important }
  .body   { display:block !important }
  .center { overflow:visible !important;padding:0 !important }
}

/* ── Shared button ── */
.btn { display:inline-flex; align-items:center; gap:7px; padding:9px 18px; border-radius:10px;
  background:var(--btn); border:none; color:#fff; font:inherit; font-size:13.5px;
  font-weight:700; cursor:pointer; transition:background .15s; }
.btn:hover { background:var(--btn-hover); }
.btn:disabled { opacity:.6; cursor:default; }

/* ── Overview workstation ── */
.ov-how { display:inline-flex; align-items:center; gap:7px; padding:8px 14px;
  border:1px solid var(--line); border-radius:9px; background:var(--panel);
  font:inherit; font-size:13px; font-weight:600; color:#1a6fb5; cursor:pointer;
  margin-bottom:20px; transition:border-color .12s; }
.ov-how:hover { border-color:#1a6fb5; background:#eef4fc; }

/* ── Help modal ── */
.shm-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000;
  display:flex; align-items:center; justify-content:center; padding:20px; }
.shm-box { background:var(--panel); border-radius:16px; width:100%; max-width:540px;
  box-shadow:0 8px 40px rgba(0,0,0,.18); display:flex; flex-direction:column; max-height:90vh; }
.shm-head { display:flex; align-items:center; justify-content:space-between;
  padding:20px 24px 16px; border-bottom:1px solid var(--line); }
.shm-title { font-size:18px; font-weight:800; color:var(--ink); margin:0; }
.shm-close { width:30px; height:30px; border-radius:8px; border:1px solid var(--line);
  background:var(--panel); color:var(--ink-2); font-size:18px; cursor:pointer;
  display:grid; place-items:center; font-family:inherit; line-height:1; flex:none; }
.shm-close:hover { background:var(--bg); }
.shm-body { padding:20px 24px; overflow-y:auto; font-size:14px; line-height:1.65;
  color:var(--ink-2); }
.shm-body p { margin:0 0 14px; }
.shm-body p:last-child { margin-bottom:0; }
.shm-body ol { padding-left:20px; margin:0 0 16px; display:flex; flex-direction:column; gap:10px; }
.shm-body li { color:var(--ink-2); }
.shm-body strong { color:var(--ink); font-weight:700; }
.shm-example { background:var(--bg); border:1px solid var(--line); border-radius:12px;
  padding:14px 16px; margin-top:16px; }
.shm-ex-label { font-size:10.5px; font-weight:800; letter-spacing:.06em; text-transform:uppercase;
  color:var(--ink-3); margin-bottom:8px; }
.shm-foot { padding:16px 24px; border-top:1px solid var(--line); display:flex;
  justify-content:flex-end; }
.ov-card { background:var(--panel); border:1px solid var(--line); border-radius:14px;
  padding:22px 24px; margin-bottom:16px; }
.ov-card-h { font-size:14px; font-weight:700; color:var(--ink); margin-bottom:16px; }
.ov-stats { display:flex; gap:32px; margin-bottom:14px; }
.ov-stat-n { font-size:30px; font-weight:800; color:var(--ink); line-height:1; }
.ov-stat-l { font-size:10.5px; font-weight:700; letter-spacing:.07em; text-transform:uppercase;
  color:var(--ink-3); margin-top:4px; }
.ov-source { font-size:12.5px; color:var(--ink-3); }
.ov-table { width:100%; border-collapse:collapse; font-size:13px; }
.ov-table thead tr { border-bottom:1px solid var(--line-2); }
.ov-table th { font-size:10.5px; font-weight:800; letter-spacing:.07em; text-transform:uppercase;
  color:var(--ink-3); padding:0 12px 10px 0; text-align:left; }
.ov-table th:last-child, .ov-table th:nth-child(3) { text-align:right; }
.ov-td { padding:10px 12px 10px 0; border-bottom:1px solid var(--line-2); color:var(--ink-2);
  vertical-align:middle; }
.ov-td:first-child { font-weight:600; color:var(--ink); }
.ov-td-type { color:var(--ink-3); font-size:12px; }
.ov-td-num { text-align:right; }
.ov-footer { margin-top:4px; }
.ov-placeholder { padding:32px; text-align:center; color:var(--ink-3); font-size:14px; }


/* ── Start workstation ── */
.ws-header { margin-bottom: 28px; }
.ws-eyebrow { font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase;
  color: var(--ink-3); margin-bottom: 10px; }
.ws-title { font-size: 28px; font-weight: 800; line-height: 1.15; margin-bottom: 10px; color: var(--ink); }
.ws-title em { font-style: normal; color: var(--acc); }
.ws-lede { font-size: 14px; line-height: 1.6; color: var(--ink-2); max-width: 560px; }

.loaded-bar { display: flex; align-items: center; gap: 12px; padding: 14px 18px;
  border: 1px solid var(--line); border-radius: 12px; background: var(--panel);
  margin-bottom: 16px; font-size: 13.5px; }
.loaded-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--green); flex: none; }
.loaded-label { font-weight: 700; color: var(--ink-2); }
.loaded-meta  { color: var(--ink-3); }
.loaded-bar .btn { margin-left: auto; }

.begin-feature { display: flex; align-items: flex-start; gap: 16px; width: 100%;
  border: 1px solid var(--line); border-radius: 14px; background: var(--panel);
  padding: 20px 22px; cursor: pointer; font: inherit; text-align: left;
  transition: border-color .15s, box-shadow .15s; margin-bottom: 16px; }
.begin-feature:hover { border-color: var(--acc); box-shadow: 0 0 0 3px var(--acc-soft); }
.bc-ico { width: 40px; height: 40px; border-radius: 10px; background: var(--acc-soft);
  color: var(--acc); display: grid; place-items: center; font-size: 20px; flex: none; }
.begin-feature h4 { font-size: 15px; font-weight: 700; margin-bottom: 4px; color: var(--ink); }
.begin-feature p  { font-size: 13px; color: var(--ink-2); line-height: 1.5; margin-bottom: 6px; }
.bc-go { font-size: 13px; font-weight: 700; color: var(--acc); }

.begin-or { font-size: 11px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase;
  color: var(--ink-3); margin: 4px 0 14px; }

.begin-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.begin-card2 { display: flex; flex-direction: column; gap: 8px; border: 1px solid var(--line);
  border-radius: 14px; background: var(--panel); padding: 20px 20px 18px; cursor: pointer;
  font: inherit; text-align: left; transition: border-color .15s, box-shadow .15s; }
.begin-card2:hover { border-color: var(--acc); box-shadow: 0 0 0 3px var(--acc-soft); }
.begin-card2 h4 { font-size: 14px; font-weight: 700; color: var(--ink); margin: 0; }
.begin-card2 p  { font-size: 13px; color: var(--ink-2); line-height: 1.5; margin: 0; }
.begin-card2 .bc-ico { font-size: 17px; }
</style>

<!-- Uniform header + footer plug-ins (always loaded first) -->
<script src="/apps/studio/studio-header.js?v=<?= _tpl_qsv('/apps/studio/studio-header.js') ?>"></script>
<script src="/apps/studio/studio-footer.js?v=<?= _tpl_qsv('/apps/studio/studio-footer.js') ?>"></script>
</head>
<body>
<div class="app">

  <!-- Uniform header renders here -->
  <div id="studioHeader"></div>

  <div class="body">

    <!-- Left step rail — add <button class="step"> for each step -->
    <nav class="rail" id="rail">
      <div class="rail-h">Steps</div>
      <button class="step" data-step="start">
        <span class="sn">01</span> Start
      </button>
      <button class="step" data-step="overview">
        <span class="sn">02</span> Overview
      </button>
      <button class="step" data-step="varmap">
        <span class="sn">03</span> Variable Map
      </button>
      <!-- Add more steps here following the same pattern -->
    </nav>

    <!-- Main content area -->
    <div class="stage">
      <main class="center" id="centerInner">
        <!-- App JS renders content here -->
        <p style="color:var(--ink-3);font-size:14px;">Loading&hellip;</p>
      </main>
    </div>

    <!-- ReliCheck Coach — right coaching panel (mirrors MM Studio) -->
    <aside class="companion" id="companion">
      <div class="comp-collapsed-tab" onclick="toggleCompanion()">
        <span style="font-size:16px">✦</span>
        <span class="ctab-vert">Coach</span>
      </div>
      <div class="comp-head">
        <div class="ch-ico">✦</div>
        <div class="ch-meta">
          <h3>ReliCheck Coach</h3>
          <div class="ch-sub">Explain · Notes · Intelligence</div>
        </div>
        <button class="comp-toggle" onclick="toggleCompanion()" title="Collapse">⟩</button>
      </div>
      <div class="comp-tabs" id="compTabs"></div>
      <div class="comp-body" id="compBody"></div>
    </aside>

  </div><!-- /.body -->

  <!-- Uniform footer renders here -->
  <div id="studioFooter"></div>

</div><!-- /.app -->

<!-- Boot data for the app JS -->
<script>const BOOT = <?= json_encode($BOOT, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>

<!-- Unified upload widget (required for Start workstation) -->
<script src="/apps/studio/dataset-upload.js?v=<?= _tpl_qsv('/apps/studio/dataset-upload.js') ?>"></script>

<!-- Variable map dependencies -->
<script src="/apps/studio/type-taxonomy.js?v=<?= _tpl_qsv('/apps/studio/type-taxonomy.js') ?>"></script>
<script src="/apps/studio/data-map.js?v=<?= _tpl_qsv('/apps/studio/data-map.js') ?>"></script>

<!-- App-specific JS — rename alongside this file -->
<script src="/apps/studio/qual-studio-workspaceV3.js?v=<?= _tpl_qsv('/apps/studio/qual-studio-workspaceV3.js') ?>" defer></script>
</body>
</html>
