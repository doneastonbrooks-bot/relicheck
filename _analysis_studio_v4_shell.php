<?php
// v4 design-led workspace shell for the analysis studios (Descriptive +
// Inferential). Mirrors MM Studio's LOOK (4-panel layout, fonts, color
// system, Start section) WITHOUT touching any MM file — all CSS/JS here is
// a fresh, accent-parameterized reproduction. Data intake follows the
// SIRI / RSSI method (the generic datasets table + survey responses), not
// MM's cadence.
//
// The including page sets $studio_def (from _analysis_studio_defs.php) with
//   'self' => its own workspace route, then includes this file.
//
// Project model: ?project_id=N is an analysis_projects.id (this studio's
// own persistent project). Its dataset is loaded from the server via
// /api/analysis/dataset.php and exposed to the engines.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode(($studio_def['self'] ?? $_SERVER['SCRIPT_NAME']) . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$sd_slug   = $studio_def['slug'];
$sd_name   = $studio_def['name'];
$sd_quest  = $studio_def['question'];
$sd_lede   = $studio_def['lede'];
$sd_accent = $studio_def['accent'];
$sd_deep   = $studio_def['accent_deep'] ?? $studio_def['accent'];
$sd_soft   = $studio_def['accent_soft'];
$sd_mark   = $studio_def['mark'] ?? '/logo-brand.svg';
// Long-form studio wordmark for the topbar, sized like MM's (.brand img 70px).
$sd_longlogo = $sd_slug === 'descriptive' ? '/DA-Studio-long.png' : '/IS-Studio-long.png';
$sd_self   = $studio_def['self'];

$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;

// Verify the analysis project belongs to this user + studio kind, and fetch
// its title. (The dataset itself loads client-side from /api/analysis/dataset.php.)
$projectTitle = '';
if ($projectId > 0) {
  require_once __DIR__ . '/api/_analysis_studio.php';
  try {
    $pdo = db();
    analysis_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT title FROM analysis_projects WHERE id = :id AND user_id = :uid AND kind = :k AND status <> "archived"');
    $stmt->execute([':id' => $projectId, ':uid' => $uid, ':k' => $sd_slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $projectTitle = (string)$row['title']; } else { $projectId = 0; }
  } catch (Throwable $e) { $projectId = 0; }
}

$pipelines = require __DIR__ . '/_analysis_pipelines.php';
$pipeline  = $pipelines[$sd_slug] ?? [];

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

// Cache-bust the work-step presentation module by file mtime.
$_as_css = '/apps/analysis-studio/analysis-studio.css';
$_as_js  = '/apps/analysis-studio/analysis-studio.js';
$_au_js  = '/apps/analysis-studio/analysis-upload.js';
$_as_css_v = is_file(__DIR__ . $_as_css) ? filemtime(__DIR__ . $_as_css) : time();
$_as_js_v  = is_file(__DIR__ . $_as_js)  ? filemtime(__DIR__ . $_as_js)  : time();
$_au_js_v  = is_file(__DIR__ . $_au_js)  ? filemtime(__DIR__ . $_au_js)  : time();

$BOOT = [
  'slug'         => $sd_slug,
  'name'         => $sd_name,
  'projectId'    => $projectId,
  'projectLabel' => $projectTitle !== '' ? $projectTitle : 'Demo walkthrough',
  'canPersist'   => $projectId > 0,
  'projectsUrl'  => $sd_slug === 'descriptive' ? '/descriptive-analysis-projects.php' : '/inferential-statistics-projects.php',
  'pipeline'     => array_values($pipeline),
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($sd_name) ?> — ReliCheck</title>
<style>
:root{
  --ink:#15171a; --ink-2:#5f6368; --ink-3:#8a8f98;
  --bg:#f5f6f8; --panel:#fff; --line:#e6e8ec; --line-2:#eef0f3;
  --accent:#6b7280; --accent-hover:#565b63; --accent-soft:#eef0f3; --accent-ink:#2a2f3a;
  --btn:<?= htmlspecialchars($sd_accent) ?>; --btn-hover:<?= htmlspecialchars($sd_deep) ?>;
  --acc:<?= htmlspecialchars($sd_accent) ?>; --acc-soft:<?= htmlspecialchars($sd_soft) ?>; --acc-deep:<?= htmlspecialchars($sd_deep) ?>;
  --green:#1f9e44; --green-soft:#e9f7ee; --quan:#0A6FE8; --quan-soft:#EEF3FA; --quan-ink:#085fcc;
  --font:-apple-system,BlinkMacSystemFont,"SF Pro Text",Inter,system-ui,sans-serif;
  --rail:248px; --companion:320px;
  --shadow:0 1px 2px rgba(20,28,45,.04),0 4px 16px rgba(20,28,45,.05);
}
*{box-sizing:border-box}
html,body{margin:0;height:100%}
body{font-family:var(--font);color:var(--ink);background:var(--bg);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
.app{display:grid;grid-template-rows:auto 1fr auto;height:100vh}

/* Topbar */
.topbar{display:flex;align-items:center;gap:14px;height:90px;padding:0 22px;background:var(--panel);border-bottom:1px solid var(--line)}
.tb-logo{display:flex;align-items:center;text-decoration:none;color:var(--ink)}
.tb-logo img{height:70px;width:auto;display:block}
.tb-ctx{margin-left:8px;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-2)}
.tb-ctx .dot{width:8px;height:8px;border-radius:50%;background:#cdd6e4}
.tb-ctx.is-live .dot{background:var(--green)}
.tb-spacer{flex:1}
.tb-avatar{width:34px;height:34px;border-radius:50%;background:var(--acc-soft);color:var(--acc-deep);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px}

/* Body grid */
.body{display:grid;grid-template-columns:var(--rail) minmax(0,1fr) var(--companion);min-height:0;overflow:hidden}

/* Rail (numbered pipeline) — keeps the studio accent */
.rail{background:var(--panel);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:16px 12px;overflow-y:auto;--accent:var(--acc);--accent-soft:var(--acc-soft);--accent-ink:var(--acc-deep)}
.rail-h{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);padding:6px 10px 10px}
/* Rail step system mirrors SIRI (develop.php): circular num, right-side green
   tick when done, accent-soft pill when active. Same check treatment app-wide. */
.step{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:10px;color:var(--ink-2);font-size:14px;font-weight:600;border:1px solid transparent;transition:background .12s,color .12s;text-align:left;width:100%;background:none;cursor:pointer;font-family:inherit}
.step:hover{background:var(--bg);color:var(--ink)}
.step .num{width:24px;height:24px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;font-size:12px;font-weight:700;background:var(--bg);color:var(--ink-3);border:1px solid var(--line)}
.step .lbl{flex:1;font-size:14px;font-weight:600}
.step .tick{display:none;color:var(--green)}
.step[data-done="1"] .num{background:var(--green-soft);color:var(--green);border-color:transparent}
.step[data-done="1"] .tick{display:block}
.step[data-active="1"]{background:var(--acc-soft);color:var(--acc-deep);border-color:rgba(0,0,0,.06)}
.step[data-active="1"] .num{background:var(--acc);color:#fff;border-color:transparent}

/* Center */
.stage{display:flex;min-width:0;overflow:hidden}
.center{flex:1 1 auto;overflow-y:auto;min-width:0;padding:30px 36px 80px}
.center-inner{max-width:860px;margin:0 auto}
.ws-header{margin-bottom:20px}
.eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:10px}
.strand-chip{font-size:9.5px;font-weight:700;padding:2px 8px;border-radius:999px;letter-spacing:.02em;background:var(--quan-soft);color:var(--quan-ink)}
.title{font-size:26px;font-weight:700;letter-spacing:-.02em;margin:0 0 8px}
.lede{font-size:15px;color:var(--ink-2);margin:0;max-width:620px;line-height:1.55}

/* Start section */
.start-hero{font-size:30px;font-weight:700;letter-spacing:-.02em;margin:0 0 10px;line-height:1.12}
.start-hero .accent{color:var(--acc)}
.begin-loaded{display:flex;align-items:center;gap:10px;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin:18px 0;font-size:13.5px;box-shadow:var(--shadow)}
.begin-loaded .dot{width:9px;height:9px;border-radius:50%;background:var(--green)}
.begin-loaded .bl-k{font-weight:700;color:var(--ink-2)}
.proj-select{font-size:13.5px;font-weight:600;border:1px solid var(--line);border-radius:8px;padding:6px 10px;font-family:inherit;background:#fff}
.begin-feature{display:flex;gap:16px;align-items:flex-start;width:100%;text-align:left;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:20px;cursor:pointer;font-family:inherit;box-shadow:var(--shadow);transition:transform .14s,border-color .14s}
.begin-feature:hover{transform:translateY(-2px);border-color:var(--acc)}
.begin-feature h4{margin:0 0 4px;font-size:18px;font-weight:700}
.begin-feature p{margin:0 0 6px;font-size:13.5px;color:var(--ink-2);line-height:1.5}
.bc-ico{flex:none;width:44px;height:44px;border-radius:12px;background:var(--acc-soft);color:var(--acc-deep);display:flex;align-items:center;justify-content:center;font-size:20px}
.bc-go{font-size:13.5px;font-weight:700;color:var(--btn)}
.begin-sec{margin:24px 0 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3)}
.begin-grid2{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.begin-card2{text-align:left;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px;cursor:pointer;font-family:inherit;transition:transform .14s,border-color .14s}
.begin-card2:hover{transform:translateY(-2px);border-color:var(--acc)}
.begin-card2 h4{margin:8px 0 4px;font-size:15px;font-weight:700}
.begin-card2 p{margin:0;font-size:12.5px;color:var(--ink-2);line-height:1.45}
@media(max-width:720px){.begin-grid2{grid-template-columns:1fr}}

/* Generic panels / tables (mirrors MM dx-* presentation) */
.panel{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);margin-bottom:18px}
.panel-h{padding:16px 18px 0}
.panel-h h3{margin:0;font-size:15px;font-weight:700}
.ph-sub{font-size:12.5px;color:var(--ink-3);margin-top:3px}
.panel-b{padding:16px 18px}
.btn{display:inline-flex;align-items:center;gap:7px;border:none;border-radius:10px;padding:9px 16px;font-family:inherit;font-size:13.5px;font-weight:700;cursor:pointer;background:var(--acc-soft);color:var(--acc-deep)}
.btn.primary{background:var(--btn);color:#fff}
.btn.primary:hover{background:var(--btn-hover)}
.btn:disabled{opacity:.55;cursor:default}

/* Companion (ReliCheck Coach) */
.companion{background:var(--panel);border-left:1px solid var(--line);display:flex;flex-direction:column;min-height:0;overflow:hidden}
.comp-head{padding:16px 18px 10px;border-bottom:1px solid var(--line)}
.comp-head h3{margin:0;font-size:13px;font-weight:700;display:flex;align-items:center;gap:7px}
.comp-tabs{display:flex;gap:4px;padding:10px 14px 0}
.comp-tab{font-size:12px;font-weight:600;padding:6px 10px;border-radius:8px 8px 0 0;border:none;background:none;color:var(--ink-3);cursor:pointer;font-family:inherit}
.comp-tab.on{color:var(--acc-deep);background:var(--acc-soft)}
.comp-body{padding:16px 18px;overflow-y:auto;font-size:13.5px;color:var(--ink-2);line-height:1.55}

/* Studio dock — SIRI/RSSI data intake */
.studio-dock{position:relative;padding:12px 22px;background:rgba(255,255,255,.92);backdrop-filter:saturate(1.4) blur(12px);border-top:1px solid var(--line);box-shadow:0 -4px 22px rgba(15,23,42,.07)}
.studio-dock-logo{position:absolute;left:22px;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center;text-decoration:none}
.studio-dock-logo img{height:24px;width:auto;display:block}
.studio-dock-inner{display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;min-height:34px}
.dock-lbl{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-3)}
@media(max-width:820px){.studio-dock-logo{display:none}}
.dock-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:10px;border:1px solid var(--line);background:#fff;color:var(--ink);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.dock-btn:hover{border-color:var(--acc)}
.dock-chip{display:inline-flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-2);margin-left:auto}
.dock-chip .gdot{width:8px;height:8px;border-radius:50%;background:var(--green)}
.placeholder{padding:40px;text-align:center;color:var(--ink-3);border:1.5px dashed var(--line);border-radius:14px}
</style>
<link rel="stylesheet" href="<?= htmlspecialchars($_as_css . '?v=' . $_as_css_v) ?>">
<script src="<?= htmlspecialchars($_as_js . '?v=' . $_as_js_v) ?>"></script>
<script src="<?= htmlspecialchars($_au_js . '?v=' . $_au_js_v) ?>"></script>
</head>
<body>
<div class="app">
  <header class="topbar">
    <a class="tb-logo" href="/app-2026v4.php">
      <img src="<?= htmlspecialchars($sd_longlogo) ?>" alt="<?= htmlspecialchars($sd_name) ?>">
    </a>
    <div class="tb-ctx<?= $projectId ? ' is-live' : '' ?>">
      <span class="dot"></span>
      <span id="ctxLabel"><?= htmlspecialchars($projectTitle !== '' ? $projectTitle : 'No project') ?></span>
    </div>
    <div class="tb-spacer"></div>
    <a class="dock-btn" href="<?= htmlspecialchars($BOOT['projectsUrl']) ?>">All projects</a>
    <div class="tb-avatar"><?= htmlspecialchars($initials) ?></div>
  </header>

  <div class="body">
    <nav class="rail" id="rail"><div class="rail-h"><?= htmlspecialchars($sd_name) ?></div></nav>
    <div class="stage"><main class="center"><div class="center-inner" id="centerInner"></div></main></div>
    <aside class="companion">
      <div class="comp-head"><h3>&#9678; ReliCheck Coach</h3></div>
      <div class="comp-tabs">
        <button class="comp-tab on" data-tab="explain">Explain</button>
        <button class="comp-tab" data-tab="notes">Notes</button>
      </div>
      <div class="comp-body" id="compBody"></div>
    </aside>
  </div>

  <footer class="studio-dock">
    <a class="studio-dock-logo" href="/app-2026v4.php" aria-label="ReliCheck home"><img src="/logo-brand.svg" alt="ReliCheck"></a>
    <div class="studio-dock-inner">
      <span class="dock-lbl">Data</span>
      <button class="dock-btn" id="dkSiri">&#9889; Open from SIRI responses</button>
      <button class="dock-btn" id="dkUpload">&#8681; Upload data</button>
      <button class="dock-btn" id="dkSaved">&#9638; Open saved project</button>
      <span class="dock-chip" id="dkChip" hidden><span class="gdot"></span><span id="dkChipText"></span></span>
    </div>
  </footer>
</div>

<script>
const BOOT = <?= json_encode($BOOT, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
(function(){
  'use strict';
  const state = { stepId: 'start', compTab: 'explain', notes: {}, dataset: null };
  const CHECK = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
  function steps(){ return BOOT.pipeline; }
  function activeStep(){ return steps().find(s=>s.id===state.stepId) || steps()[0]; }
  function stepIndex(){ return steps().findIndex(s=>s.id===state.stepId); }

  // ---- Rail (numbered pipeline) ----
  function renderRail(){
    const rail = document.getElementById('rail');
    const idx = stepIndex();
    rail.innerHTML = '<div class="rail-h">' + esc(BOOT.name) + '</div>' + steps().map(function(s,i){
      const active = s.id===state.stepId ? '1':'0';
      const done = i<idx ? '1':'0';
      return '<button class="step" data-active="'+active+'" data-done="'+done+'" data-step="'+esc(s.id)+'">'
        + '<span class="num">'+(done==='1'?CHECK:(i+1))+'</span>'
        + '<span class="lbl">'+esc(s.label)+'</span>'
        + '<span class="tick">'+CHECK+'</span></button>';
    }).join('');
    rail.querySelectorAll('.step').forEach(function(b){
      b.addEventListener('click', function(){ state.stepId=b.getAttribute('data-step'); render(); });
    });
  }

  // ---- Center ----
  function renderCenter(){
    const host = document.getElementById('centerInner');
    const s = activeStep();
    if (s.mode==='start') return renderStart(host);
    if (s.mode==='overview') return renderOverview(host);
    if (s.mode==='report') return renderReport(host, s);
    return renderWork(host, s);
  }

  // Start is ALWAYS the data hub: bring in new data or open a saved project.
  function renderStart(host){
    const has = !!state.dataset;
    let html = '<div class="ws-header"><div class="eyebrow">'+(has?'Your data':'New analysis')+'</div>'
      + '<h1 class="start-hero">'
      + (BOOT.slug==='descriptive'
          ? 'See what is <span class="accent">in your data.</span>'
          : 'Test what your data <span class="accent">can support.</span>')
      + '</h1><p class="lede">' + esc(BOOT.slug==='descriptive'
          ? 'Frequencies, distributions, group summaries, and item rankings — a clear picture before any claims.'
          : 'Comparisons, relationships, regression, effect sizes, and assumptions — with supported interpretation.')
      + '</p></div>';

    if (has) {
      html += '<div class="begin-loaded"><span class="dot"></span><span class="bl-k">Loaded</span>'
        + '<span>' + esc(state.dataset.source || BOOT.projectLabel) + ' · ' + (state.dataset.rowCount||0) + ' rows</span>'
        + '<button class="btn primary" style="margin-left:auto" id="stOverview">Go to Overview &rarr;</button></div>';
    }
    html += '<button class="begin-feature" id="stUpload"><span class="bc-ico">&#8681;</span>'
      + '<div><h4>Upload data</h4><p>Drop an Excel (.xlsx), CSV, or TSV file and tag your columns.</p>'
      + '<span class="bc-go">Upload data &rarr;</span></div></button>';
    html += '<div class="begin-sec">Or</div><div class="begin-grid2">'
      + '<button class="begin-card2" id="stSiri"><span class="bc-ico">&#9889;</span><h4>Open from SIRI responses</h4><p>Analyze a published survey’s collected responses.</p></button>'
      + '<button class="begin-card2" id="stProjects"><span class="bc-ico">&#9638;</span><h4>Open a saved project</h4><p>Your saved data, from any ReliCheck studio.</p></button>'
      + '</div>';
    host.innerHTML = html;
    const ov = document.getElementById('stOverview'); if (ov) ov.addEventListener('click', function(){ state.stepId='overview'; render(); });
    const u = document.getElementById('stUpload'); if (u) u.addEventListener('click', openUpload);
    const si = document.getElementById('stSiri'); if (si) si.addEventListener('click', openSiri);
    const pr = document.getElementById('stProjects'); if (pr) pr.addEventListener('click', openSaved);
  }

  // Overview is the landing view once data is loaded (mirrors MM's Overview):
  // a quick read of what's in the dataset before any analysis.
  function renderOverview(host){
    const ds = state.dataset;
    if (!ds) {
      host.innerHTML = '<div class="ws-header"><div class="eyebrow">'+esc(BOOT.name)+'</div><h1 class="title">Overview</h1></div>'
        + '<div class="placeholder">No data yet. Go to <strong>Start</strong> to upload a file or open a saved project.</div>';
      return;
    }
    const vars = ds.variables || [];
    const isNum = function(v){ return /likert|numeric/.test((v.types||[]).join(',').toLowerCase()); };
    const valid = function(v){ return (v.values||[]).filter(function(x){ return x!=='' && x!=null; }).length; };
    const stat = function(n,l){ return '<div><div style="font-size:26px;font-weight:700;line-height:1">'+n+'</div><div style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.05em;margin-top:3px">'+l+'</div></div>'; };
    const rows = vars.map(function(v){ const n=valid(v), total=(v.values||[]).length;
      return '<tr><td class="dx-name">'+esc(v.name)+'</td><td class="l">'+esc((v.types||['—'])[0])+'</td><td>'+n+'</td><td>'+(total-n)+'</td></tr>'; }).join('');
    host.innerHTML = '<div class="ws-header"><div class="eyebrow">'+esc(BOOT.name)+'</div><h1 class="title">Overview</h1>'
      + '<p class="lede">What is in this dataset, before you analyze it.</p></div>'
      + (window.AnalysisStudio && window.AnalysisStudio.helpButton ? window.AnalysisStudio.helpButton('overview') : '')
      + '<div class="panel"><div class="panel-h"><h3>Dataset</h3></div><div class="panel-b">'
      + '<div style="display:flex;gap:34px;flex-wrap:wrap">' + stat(ds.rowCount||0,'Rows') + stat(vars.length,'Variables') + stat(vars.filter(isNum).length,'Numeric') + '</div>'
      + '<p style="margin:14px 0 0;color:var(--ink-3);font-size:13px">Source: '+esc(ds.source || BOOT.projectLabel)+'</p></div></div>'
      + '<div class="panel"><div class="panel-h"><h3>Variables</h3></div><div class="panel-b"><div class="dx-scroll"><table class="dx-table">'
      + '<thead><tr><th class="l">Variable</th><th class="l">Type</th><th>Valid n</th><th>Missing</th></tr></thead><tbody>'+rows+'</tbody></table></div></div></div>'
      + '<div style="margin-top:6px"><button class="btn primary" id="ovGo">Continue to analysis &rarr;</button></div>';
    const go = document.getElementById('ovGo'); if (go) go.addEventListener('click', function(){ const nx=steps()[2]; if(nx){ state.stepId=nx.id; render(); } });
  }

  function renderWork(host, s){
    if (!state.dataset) {
      host.innerHTML = '<div class="ws-header"><div class="eyebrow">'+esc(BOOT.name)+' <span class="strand-chip">QUAN</span></div>'
        + '<h1 class="title">'+esc(s.label)+'</h1></div>'
        + '<div class="placeholder">Load data to run <strong>'+esc(s.label)+'</strong>. Use the Data bar below.</div>';
      return;
    }
    // Descriptive tools render through the shared presentation module
    // (MM-style dx-table + interpretation layers).
    if (BOOT.slug === 'descriptive' && window.AnalysisStudio) {
      window.AnalysisStudio.renderWork(host, { kind: BOOT.slug, tool: s.tool, dataset: state.dataset });
      return;
    }
    // Inferential presentations land in the next build chunk.
    host.innerHTML = '<div class="ws-header"><div class="eyebrow">'+esc(BOOT.name)+' <span class="strand-chip">QUAN</span></div>'
      + '<h1 class="title">'+esc(s.label)+'</h1>'
      + '<p class="lede">Dataset loaded: '+ (state.dataset.rowCount||0) +' rows, '+ ((state.dataset.variables||[]).length) +' variables.</p></div>'
      + '<div class="placeholder">The <strong>'+esc(s.label)+'</strong> presentation (MM-style) is being built next.</div>';
  }

  function renderReport(host){
    host.innerHTML = '<div class="ws-header"><h1 class="title">Report</h1>'
      + '<p class="lede">Assemble your saved analyses into a shareable report.</p></div>'
      + '<div class="placeholder">Report builder lands in a later chunk.</div>';
  }

  function renderCompanion(){
    const body = document.getElementById('compBody');
    if (state.compTab==='notes') {
      const v = state.notes[state.stepId] || '';
      body.innerHTML = '<textarea id="noteBox" style="width:100%;min-height:200px;border:1px solid var(--line);border-radius:10px;padding:10px;font-family:inherit;font-size:13.5px" placeholder="Notes for this step…">'+esc(v)+'</textarea>';
      const t = document.getElementById('noteBox');
      if (t) t.addEventListener('input', function(){ state.notes[state.stepId]=t.value; });
      return;
    }
    const s = activeStep();
    body.innerHTML = '<p><strong>'+esc(s.label)+'</strong></p><p>'
      + (s.mode==='start' ? 'Pick a data source to begin. '+esc(BOOT.name)+' never computes reliability — that lives in RSSI.'
         : 'This step runs on your loaded dataset. Results you run can be saved to the project and recalled later.')
      + '</p>';
  }

  // ---- Data dock ----
  const WORKSPACE_ROUTE = BOOT.slug==='descriptive' ? '/descriptive-analysis-workspace.php' : '/inferential-statistics-workspace.php';
  function openUpload(){
    if (!window.AnalysisUpload) return;
    window.AnalysisUpload.open({
      kind: BOOT.slug,
      projectId: BOOT.projectId,
      onLoaded: openProject
    });
  }
  function openSaved(){
    if (!window.AnalysisUpload) return;
    window.AnalysisUpload.openSaved({ kind: BOOT.slug, projectId: BOOT.projectId, onLoaded: openProject });
  }
  // Data was saved/linked to project `pid` → open it project-scoped so the
  // workspace loads it from the server (and it persists on reopen).
  function openProject(_dataset, pid){
    if (!pid) return;
    window.location.href = WORKSPACE_ROUTE + '?v4=1&project_id=' + encodeURIComponent(pid);
  }
  function openSiri(){ alert('SIRI source picker — wired in the data-intake chunk.'); }

  // Most-recent localStorage dataset (engine shape), as a fallback when the
  // project has no server-saved data yet — lets uploads made elsewhere show
  // up immediately. Server data always wins.
  function findLocalDataset(){
    let best=null;
    try {
      for (let i=0;i<window.localStorage.length;i++){
        const k=window.localStorage.key(i);
        if(!k || k.indexOf('relicheck.dataset.')!==0) continue;
        const w=JSON.parse(window.localStorage.getItem(k));
        const dsv=w&&w.payload&&w.payload.dataset;
        if(dsv && (dsv.rowCount||0)>0 && (!best || (w.savedAt||0)>(best.savedAt||0))) best={savedAt:w.savedAt||0, ds:dsv};
      }
    } catch(e){}
    return best;
  }
  function applyDataset(ds){
    state.dataset = ds;
    showChip((ds.rowCount||0) + ' rows · ' + ((ds.variables||[]).length) + ' variables');
    // Once data is loaded, Overview becomes the landing view (Start stays the
    // data hub you can return to). Don't yank the user off a step they chose.
    if (state.stepId === 'start') state.stepId = 'overview';
    render();
  }
  let _loaded=false;
  function loadDataset(){
    if (_loaded) return; _loaded=true;
    function fallback(){ const local=findLocalDataset(); if(local) applyDataset(local.ds); else render(); }
    if (!BOOT.projectId){ fallback(); return; }
    fetch('/api/analysis/dataset.php?project_id=' + encodeURIComponent(BOOT.projectId), { credentials:'same-origin', headers:{Accept:'application/json'} })
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(d){
        if (d && d.ok && d.has_data && d.dataset) applyDataset(d.dataset);
        else fallback();
      })
      .catch(fallback);
  }
  function showChip(text){ const c=document.getElementById('dkChip'); const t=document.getElementById('dkChipText'); if(c&&t){c.hidden=false;t.textContent=text;} }

  // ---- Wiring ----
  document.getElementById('dkUpload').addEventListener('click', openUpload);
  document.getElementById('dkSiri').addEventListener('click', openSiri);
  document.getElementById('dkSaved').addEventListener('click', openSaved);
  document.querySelectorAll('.comp-tab').forEach(function(b){
    b.addEventListener('click', function(){ state.compTab=b.getAttribute('data-tab'); document.querySelectorAll('.comp-tab').forEach(function(x){x.classList.toggle('on', x===b);}); renderCompanion(); });
  });

  function render(){ renderRail(); renderCenter(); renderCompanion(); }
  render();
  loadDataset();
})();
</script>
</body>
</html>
