<?php
// Qualitative Analysis Studio — workspace
// Follows the studio template contract:
//   StudioHeader → rail (numbered .step) → .stage/.center → .companion → StudioFooter
// Mirrors the D/I shell structure without sharing the shell file (same approach as MM Studio).

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';
require_once __DIR__ . '/api/_qual_studio.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /login.html?return=' . urlencode('/qual-studio-workspace.php' . $qs));
    exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$pdo = db();
qual_ensure_schema($pdo);

$projectId  = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
$projectRow = null;
if ($projectId > 0) {
    try {
        $s = $pdo->prepare(
            "SELECT * FROM qual_projects WHERE id=:id AND user_id=:u AND status<>'archived' LIMIT 1"
        );
        $s->execute([':id' => $projectId, ':u' => $uid]);
        $projectRow = $s->fetch(PDO::FETCH_ASSOC);
        if (!$projectRow) $projectId = 0;
    } catch (Throwable $e) { $projectId = 0; }
}

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

// Qual Studio pipeline — follows the same shape as D/I pipelines.
// 'dot' => 'qual' uses the --qual CSS variable for the strand indicator.
$pipeline = [
    ['id' => 'start',       'label' => 'Start',            'dot' => 'qual', 'mode' => 'start'],
    ['id' => 'overview',    'label' => 'Overview',         'dot' => 'qual', 'mode' => 'overview'],
    ['id' => 'datamap',     'label' => 'Variable Map',     'dot' => 'qual', 'mode' => 'datamap'],
    ['id' => 'setup',       'label' => 'Project Setup',    'dot' => 'qual', 'mode' => 'work', 'tool' => 'setup'],
    ['id' => 'familiarize', 'label' => 'Familiarization',  'dot' => 'qual', 'mode' => 'work', 'tool' => 'familiarize'],
    ['id' => 'coding',      'label' => 'Coding Workspace', 'dot' => 'qual', 'mode' => 'work', 'tool' => 'coding'],
    ['id' => 'codebook',    'label' => 'Codebook Builder', 'dot' => 'qual', 'mode' => 'work', 'tool' => 'codebook'],
    ['id' => 'categories',  'label' => 'Category Builder', 'dot' => 'qual', 'mode' => 'work', 'tool' => 'categories', 'soon' => true],
    ['id' => 'themes',      'label' => 'Theme Builder',    'dot' => 'qual', 'mode' => 'work', 'tool' => 'themes',     'soon' => true],
    ['id' => 'quotes',      'label' => 'Quote Finder',     'dot' => 'qual', 'mode' => 'work', 'tool' => 'quotes',     'soon' => true],
    ['id' => 'trust',       'label' => 'Trustworthiness',  'dot' => 'qual', 'mode' => 'work', 'tool' => 'trust',      'soon' => true],
    ['id' => 'audit',       'label' => 'Audit Trail',      'dot' => 'qual', 'mode' => 'work', 'tool' => 'audit',      'soon' => true],
    ['id' => 'report',      'label' => 'Report Builder',   'dot' => 'qual', 'mode' => 'report'],
];

$BOOT = [
    'projectId'    => $projectId,
    'project'      => $projectRow,
    'projectLabel' => $projectRow ? $projectRow['title'] : '',
    'projectLive'  => $projectId > 0,
    'projectsUrl'  => '/qual-studio.php',
    'pipeline'     => array_values($pipeline),
    'initials'     => $initials,
];

// Script cache-busting
function _qsv(string $path): string {
    $full = __DIR__ . $path;
    return is_file($full) ? (string)filemtime($full) : (string)time();
}
if (!headers_sent()) {
    header('Cache-Control: no-store, must-revalidate');
    header('Pragma: no-cache');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $projectRow ? htmlspecialchars($projectRow['title']) . ' — ' : '' ?>Qualitative Analysis Studio — ReliCheck</title>
<link rel="icon" href="/logo-brand.svg">
<style>
:root{
  --ink:#15171a; --ink-2:#5f6368; --ink-3:#8a8f98;
  --bg:#f5f6f8; --panel:#fff; --line:#e6e8ec; --line-2:#eef0f3;
  --accent:#6b7280; --accent-soft:#eef0f3; --accent-ink:#2a2f3a;
  --btn:#1e5c3a;  --btn-hover:#174d30;
  --acc:#1e5c3a;  --acc-soft:#e8f5ee; --acc-deep:#174d30;
  --green:#1f9e44; --green-soft:#e9f7ee;
  --qual:#1e5c3a; --qual-soft:#e8f5ee; --qual-ink:#174d30;
  --quan:#0A6FE8; --quan-soft:#EEF3FA; --quan-ink:#085fcc;
  --font:-apple-system,BlinkMacSystemFont,"SF Pro Text",Inter,system-ui,sans-serif;
  --rail:214px; --companion:268px;
  --shadow:0 1px 2px rgba(20,28,45,.04),0 4px 16px rgba(20,28,45,.05);
}
*{box-sizing:border-box}
html,body{margin:0;height:100%}
body{font-family:var(--font);color:var(--ink);background:var(--bg);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
.app{display:grid;grid-template-rows:auto 1fr auto;height:100vh}
/* Body grid */
.body{display:grid;grid-template-columns:var(--rail) minmax(0,1fr) var(--companion);min-height:0;overflow:hidden}
/* Rail */
.rail{background:var(--panel);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:16px 12px;overflow-y:auto}
.rail-h{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);padding:6px 10px 10px}
.step{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:10px;color:var(--ink-2);font-size:14px;font-weight:600;border:1px solid transparent;transition:background .12s,color .12s;text-align:left;width:100%;background:none;cursor:pointer;font-family:inherit}
.step:hover:not(:disabled){background:var(--bg);color:var(--ink)}
.step:disabled{opacity:.4;cursor:default}
.step .num{width:24px;height:24px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;font-size:12px;font-weight:700;background:var(--bg);color:var(--ink-3);border:1px solid var(--line)}
.step .lbl{flex:1;font-size:14px;font-weight:600}
.step .tick{display:none;color:var(--green)}
.step[data-done="1"] .num{background:var(--green-soft);color:var(--green);border-color:transparent}
.step[data-done="1"] .tick{display:block}
.step[data-active="1"]{background:var(--acc-soft);color:var(--acc-deep);border-color:rgba(0,0,0,.06)}
.step[data-active="1"] .num{background:var(--acc);color:#fff;border-color:transparent}
.step-soon{font-size:9.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;padding:2px 6px;border-radius:999px;background:#f3f4f6;color:#9ca3af;margin-left:2px}
/* Center */
.stage{display:flex;min-width:0;overflow:hidden}
.center{flex:1 1 auto;overflow-y:auto;min-width:0;padding:30px 36px 80px}
.center-inner{max-width:1100px;margin:0 auto}
/* Companion */
.companion{background:var(--panel);border-left:1px solid var(--line);display:flex;flex-direction:column;min-height:0;overflow:hidden}
.comp-head{padding:16px 18px 10px;border-bottom:1px solid var(--line)}
.comp-head h3{margin:0;font-size:13px;font-weight:700;display:flex;align-items:center;gap:7px}
.comp-tabs{display:flex;gap:4px;padding:10px 14px 0}
.comp-tab{font-size:12px;font-weight:600;padding:6px 10px;border-radius:8px 8px 0 0;border:none;background:none;color:var(--ink-3);cursor:pointer;font-family:inherit}
.comp-tab.on{color:var(--acc-deep);background:var(--acc-soft)}
.comp-body{padding:16px 18px;overflow-y:auto;font-size:13.5px;color:var(--ink-2);line-height:1.55}
/* Generic */
.btn{display:inline-flex;align-items:center;gap:7px;border:none;border-radius:10px;padding:9px 16px;font-family:inherit;font-size:13.5px;font-weight:700;cursor:pointer;background:var(--acc-soft);color:var(--acc-deep)}
.btn.primary{background:var(--btn);color:#fff}
.btn.primary:hover{background:var(--btn-hover)}
.btn.outline{background:transparent;color:var(--acc);border:1.5px solid var(--acc)}
.btn:disabled{opacity:.55;cursor:default}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);margin-bottom:18px}
.panel-h{padding:16px 18px 0}
.panel-h h3{margin:0;font-size:15px;font-weight:700}
.panel-b{padding:16px 18px}
.placeholder{padding:40px;text-align:center;color:var(--ink-3);border:1.5px dashed var(--line);border-radius:14px}
.ws-header{margin-bottom:20px}
.eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:10px}
.title{font-size:26px;font-weight:700;letter-spacing:-.02em;margin:0 0 8px}
.lede{font-size:15px;color:var(--ink-2);margin:0;max-width:620px;line-height:1.55}
/* Form */
.field{margin-bottom:16px}
.field label{display:block;font-size:13px;font-weight:700;color:var(--ink-2);margin-bottom:6px}
.field label .hint{display:block;font-size:12px;font-weight:400;color:var(--ink-3);margin-top:2px}
.field input,.field select,.field textarea{width:100%;padding:9px 13px;font-size:14px;font-family:var(--font);border:1.5px solid #d1d5db;border-radius:10px;outline:none;transition:border-color .15s;background:#fff;color:var(--ink)}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--acc)}
.field textarea{resize:vertical;min-height:90px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:700px){.grid2{grid-template-columns:1fr}}
.btn-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:20px}
/* Start screen */
.start-hero{font-size:30px;font-weight:700;letter-spacing:-.02em;margin:0 0 10px;line-height:1.12}
.start-hero .accent{color:var(--acc)}
.begin-loaded{display:flex;align-items:center;gap:10px;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin:18px 0;font-size:13.5px;box-shadow:var(--shadow)}
.begin-loaded .dot{width:9px;height:9px;border-radius:50%;background:var(--green)}
.begin-feature{display:flex;gap:16px;align-items:flex-start;width:100%;text-align:left;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:20px;cursor:pointer;font-family:inherit;box-shadow:var(--shadow);transition:transform .14s,border-color .14s}
.begin-feature:hover{transform:translateY(-2px);border-color:var(--acc)}
.begin-feature h4{margin:0 0 4px;font-size:18px;font-weight:700}
.begin-feature p{margin:0 0 6px;font-size:13.5px;color:var(--ink-2);line-height:1.5}
.bc-ico{flex:none;width:44px;height:44px;border-radius:12px;background:var(--acc-soft);color:var(--acc-deep);display:flex;align-items:center;justify-content:center;font-size:22px}
.bc-go{font-size:13.5px;font-weight:700;color:var(--btn)}
.begin-sec{margin:24px 0 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3)}
.begin-grid2{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.begin-card2{text-align:left;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px;cursor:pointer;font-family:inherit;transition:transform .14s,border-color .14s}
.begin-card2:hover{transform:translateY(-2px);border-color:var(--acc)}
.begin-card2 h4{margin:8px 0 4px;font-size:15px;font-weight:700}
.begin-card2 p{margin:0;font-size:12.5px;color:var(--ink-2);line-height:1.45}
@media(max-width:720px){.begin-grid2{grid-template-columns:1fr}}
/* Stats */
.stat-row{display:flex;gap:34px;flex-wrap:wrap;margin-bottom:8px}
.stat-num{font-size:26px;font-weight:700;line-height:1}
.stat-lbl{font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.05em;margin-top:3px}
/* Segment cards */
.seg-list{display:flex;flex-direction:column;gap:10px}
.seg-card{background:var(--panel);border:1.5px solid var(--line);border-radius:12px;padding:16px 18px;transition:border-color .15s}
.seg-card.coded{border-color:color-mix(in srgb,var(--acc) 30%,white)}
.seg-card.overcoded{border-color:#f59e0b}
.seg-meta{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.seg-pid{font-size:11.5px;font-weight:700;color:var(--ink-3)}
.seg-q{font-size:11.5px;color:var(--ink-3);font-style:italic}
.seg-text{font-size:14.5px;color:var(--ink);line-height:1.7;margin-bottom:12px}
.code-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
.chip{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;background:var(--acc-soft);color:var(--acc-deep)}
.chip-x{background:none;border:none;cursor:pointer;padding:0;color:var(--acc-deep);font-size:13px;line-height:1;opacity:.7}
.chip-x:hover{opacity:1}
.seg-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.add-code-btn{font-size:12.5px;font-weight:700;color:var(--acc);background:var(--acc-soft);border:none;padding:5px 12px;border-radius:999px;cursor:pointer}
.add-code-btn:hover{background:color-mix(in srgb,var(--acc) 18%,white)}
.flag{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
.flag.uncoded{background:#f3f4f6;color:#9ca3af}
.flag.overcoded{background:#fff8ee;color:#b45309}
/* Picker */
.picker-wrap{position:relative;display:inline-block}
.picker{position:absolute;top:calc(100% + 6px);left:0;z-index:50;background:#fff;border:1.5px solid var(--line);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.12);min-width:220px;max-height:240px;overflow-y:auto;padding:6px}
.picker-item{display:block;width:100%;text-align:left;padding:8px 12px;border:none;background:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font)}
.picker-item:hover{background:var(--acc-soft);color:var(--acc-deep)}
.picker-empty{font-size:13px;color:var(--ink-3);padding:10px 12px}
.picker-new{border-top:1px solid var(--line);margin-top:4px;padding-top:4px}
.picker-new-btn{display:flex;align-items:center;gap:8px;width:100%;text-align:left;padding:8px 12px;border:none;background:none;cursor:pointer;border-radius:8px;font-size:13px;font-weight:700;color:var(--acc);font-family:var(--font)}
.picker-new-btn:hover{background:var(--acc-soft)}
/* Codebook */
.cb-table-wrap{overflow:auto;max-height:480px;border:1px solid var(--line);border-radius:12px}
.cb-table{width:100%;border-collapse:collapse}
.cb-table thead th{background:color-mix(in srgb,var(--acc) 7%,white);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--acc);padding:10px 14px;text-align:left;position:sticky;top:0;z-index:1;border-bottom:1px solid var(--line)}
.cb-table tbody td{padding:10px 14px;font-size:13px;border-top:1px solid var(--line);vertical-align:top}
.cb-table tbody tr:hover td{background:color-mix(in srgb,var(--acc) 3%,white)}
.status-chip{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:2px 8px;border-radius:999px;display:inline-block}
.status-chip.draft{background:#f3f4f6;color:#6b7280}
.status-chip.reviewed{background:#fff8ee;color:#b45309}
.status-chip.approved{background:var(--acc-soft);color:var(--acc-deep)}
/* Filters */
.filters{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.filter-btn{font-size:12.5px;font-weight:700;padding:6px 14px;border-radius:999px;border:1.5px solid var(--line);background:var(--panel);color:var(--ink-2);cursor:pointer;transition:.13s}
.filter-btn.active{border-color:var(--acc);background:var(--acc-soft);color:var(--acc-deep)}
.filter-btn:hover:not(.active){border-color:var(--acc);color:var(--acc)}
.search-input{flex:1;min-width:180px;padding:7px 13px;font-size:13.5px;border:1.5px solid var(--line);border-radius:10px;outline:none;font-family:var(--font)}
.search-input:focus{border-color:var(--acc)}
/* Notice */
.notice{padding:12px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px}
.notice.info{background:var(--acc-soft);color:var(--acc-deep)}
.notice.warn{background:#fff8ee;color:#92400e}
.notice.err{background:#fef2f2;color:#c0392b}
/* Stats grid */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:14px;margin-bottom:24px}
.stat-card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px 16px;text-align:center}
.stat-card .num{font-size:32px;font-weight:900;color:var(--acc);letter-spacing:-.03em;line-height:1}
.stat-card .lbl{font-size:12px;color:var(--ink-3);font-weight:600;margin-top:6px}
@media print{
  .rail,.companion,.studioHeader,.studioFooter{display:none!important}
  .app{display:block!important;height:auto!important}
  .body{display:block!important;overflow:visible!important}
  .center{overflow:visible!important;padding:0!important}
}
</style>
<script src="/apps/studio/studio-header.js?v=<?= _qsv('/apps/studio/studio-header.js') ?>"></script>
<script src="/apps/studio/studio-footer.js?v=<?= _qsv('/apps/studio/studio-footer.js') ?>"></script>
<script src="/apps/studio/type-taxonomy.js?v=<?= _qsv('/apps/studio/type-taxonomy.js') ?>"></script>
<script src="/apps/studio/data-map.js?v=<?= _qsv('/apps/studio/data-map.js') ?>"></script>
<script src="/apps/studio/dataset-upload.js?v=<?= _qsv('/apps/studio/dataset-upload.js') ?>"></script>
</head>
<body>
<div class="app">
  <div id="studioHeader"></div>

  <div class="body">
    <nav class="rail" id="rail">
      <div class="rail-h">Qualitative Analysis</div>
    </nav>

    <div class="stage">
      <main class="center">
        <div class="center-inner" id="centerInner"></div>
      </main>
    </div>

    <aside class="companion">
      <div class="comp-head">
        <h3>&#9678; ReliCheck Intelligence</h3>
      </div>
      <div class="comp-tabs">
        <button class="comp-tab on" data-tab="guidance">Guidance</button>
        <button class="comp-tab" data-tab="notes">Notes</button>
        <button class="comp-tab" data-tab="intelligence">Intelligence</button>
      </div>
      <div class="comp-body" id="compBody">
        <p style="color:var(--ink-3);">Select a step to begin.</p>
      </div>
    </aside>
  </div>

  <div id="studioFooter"></div>
</div>

<script>
const BOOT = <?= json_encode($BOOT, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/apps/qual/qual-studio.js?v=<?= _qsv('/apps/qual/qual-studio.js') ?>"></script>
</body>
</html>
