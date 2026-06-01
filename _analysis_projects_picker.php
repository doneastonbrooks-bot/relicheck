<?php
// Shared project picker for the analysis studios (Descriptive + Inferential).
// v4 look, matching studio-mm-projects.php. A thin per-studio page sets:
//   $picker_kind      'descriptive' | 'inferential'
//   $picker_self      '/descriptive-analysis-projects.php'
//   $picker_workspace '/descriptive-analysis-workspace.php'
// then includes this file.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';
require_once __DIR__ . '/api/_analysis_studio.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode($picker_self . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

// Deep-link: ?project_id -> verify ownership (right kind) -> open workspace.
$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
if ($projectId > 0) {
  $pdo  = db();
  analysis_ensure_schema($pdo);
  $stmt = $pdo->prepare('SELECT id FROM analysis_projects WHERE id = :id AND user_id = :uid AND kind = :k AND status <> "archived"');
  $stmt->execute([':id' => $projectId, ':uid' => $uid, ':k' => $picker_kind]);
  if ($stmt->fetch(PDO::FETCH_ASSOC)) {
    header('Location: ' . $picker_workspace . '?project_id=' . $projectId);
    exit;
  }
}

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios[$picker_kind];

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$landing_title         = $studio['name'] . ' — Projects';
$landing_accent        = $studio['accent'];
$landing_accent_deep   = $studio['accent_deep'] ?? $studio['accent'];
$landing_accent_soft   = $studio['accent_soft'];
$landing_logo          = $studio['mark'];
$landing_logo_name     = $studio['name'];
$landing_pill_label    = $studio['status_label'];
$landing_show_back     = true;
$landing_user_initials = $initials;
$landing_user_full     = $user_full;

include __DIR__ . '/_landing_head.php';
?>

<style>
.lp-page { max-width: 1060px; margin: 0 auto; padding: 48px 40px 80px; }
.mp-head { margin-bottom: 32px; }
.mp-head h1 { font-size: 28px; font-weight: 800; letter-spacing: -.02em; color: #1d1d1f; margin-bottom: 6px; }
.mp-head p  { font-size: 15px; color: #6e6e73; margin: 0; }
.mp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.mp-card { background: #f5f5f7; border: 1px solid rgba(0,0,0,.07); border-radius: 16px; padding: 22px 22px 18px;
  text-decoration: none; display: flex; flex-direction: column; gap: 5px; transition: transform .16s, box-shadow .16s, border-color .16s; cursor: pointer; }
.mp-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,.09); border-color: var(--accent); }
.mp-card-label { font-size: 10px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; color: var(--accent); }
.mp-card-title { font-size: 16px; font-weight: 700; color: #1d1d1f; line-height: 1.35; }
.mp-card-meta  { font-size: 12px; color: #86868b; margin-top: 4px; }
.mp-card-new { border: 1.5px dashed rgba(0,0,0,.15); background: transparent; align-items: center; justify-content: center; text-align: center; min-height: 110px; gap: 8px; }
.mp-card-new:hover { border-color: var(--accent); transform: translateY(-3px); box-shadow: none; }
.mp-new-icon  { font-size: 22px; color: var(--accent); }
.mp-new-label { font-size: 14px; font-weight: 700; color: var(--accent); }
.mp-new-sub   { font-size: 12px; color: #86868b; }
.mp-empty, .mp-loading, .mp-error { grid-column: 1 / -1; padding: 28px 24px; border-radius: 14px; font-size: 14px; text-align: center; color: #86868b; background: #f5f5f7; border: 1.5px dashed rgba(0,0,0,.1); }
.mp-error { color: #c2492f; background: #fff5f3; border-color: #f3c4b4; }
</style>

<div class="mp-head">
  <h1>Your <?= htmlspecialchars($picker_kind === 'descriptive' ? 'descriptive analyses' : 'inferential analyses') ?></h1>
  <p>Pick a project to reopen it with its data and saved results, or start a new one.</p>
</div>

<div class="mp-grid" id="mpGrid"><p class="mp-loading">Loading your projects…</p></div>

<script>
(function(){
  var host = document.getElementById('mpGrid');
  if (!host) return;
  var KIND      = <?= json_encode($picker_kind) ?>;
  var WORKSPACE = <?= json_encode($picker_workspace) ?>;
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }

  var newCard = '<a class="mp-card mp-card-new" id="mpNew">'
    + '<span class="mp-new-icon">+</span>'
    + '<span class="mp-new-label">New analysis</span>'
    + '<span class="mp-new-sub">Create a project, then add data</span>'
    + '</a>';

  function bindNew(){
    var el = document.getElementById('mpNew');
    if (!el) return;
    el.addEventListener('click', function(){
      var title = window.prompt('Name this analysis project:', '');
      if (title === null) return; // cancelled
      el.style.pointerEvents = 'none';
      fetch('/api/analysis/projects.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ kind: KIND, title: (title || '').trim() })
      })
      .then(function(r){ return r.ok ? r.json() : Promise.reject('HTTP ' + r.status); })
      .then(function(d){
        if (d && d.ok && d.project) { window.location.href = WORKSPACE + '?project_id=' + encodeURIComponent(d.project.id); }
        else { el.style.pointerEvents = ''; alert('Could not create the project.'); }
      })
      .catch(function(){ el.style.pointerEvents = ''; alert('Could not create the project.'); });
    });
  }

  fetch('/api/analysis/projects.php?kind=' + encodeURIComponent(KIND), { credentials: 'same-origin', headers: { Accept: 'application/json' }})
    .then(function(r){ return r.ok ? r.json() : Promise.reject('HTTP ' + r.status); })
    .then(function(data){
      var projects = (data && data.ok && Array.isArray(data.projects)) ? data.projects : [];
      if (!projects.length) {
        host.innerHTML = newCard + '<p class="mp-empty">No projects yet. Create your first one.</p>';
        bindNew(); return;
      }
      host.innerHTML = projects.map(function(p){
        var dataMeta = p.has_data ? 'Data loaded' : 'No data yet';
        return '<a class="mp-card" href="' + WORKSPACE + '?project_id=' + encodeURIComponent(p.id) + '">'
          + '<span class="mp-card-label">' + esc(dataMeta) + '</span>'
          + '<span class="mp-card-title">'  + esc(p.title || 'Untitled project') + '</span>'
          + '<span class="mp-card-meta">Updated ' + esc((p.updated_at||'').slice(0,10)||'—') + '</span>'
          + '</a>';
      }).join('') + newCard;
      bindNew();
    })
    .catch(function(err){
      host.innerHTML = '<p class="mp-error">Could not load your projects: ' + esc(String(err)) + '</p>';
    });
})();
</script>

<?php
$landing_tagline = 'Pick a project and begin.';
include __DIR__ . '/_landing_foot.php';
