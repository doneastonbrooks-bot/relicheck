<?php
// ReliCheck Basic — LANDING / start page.
// A free ENTRY PRODUCT, not a studio. Explains Basic, then sends the user into
// the guided Basic workspace. Renders no analysis. Gives the score, not the
// full explanation system. See [[project_relicheck_basic]].

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/relicheck-basic.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$user_full  = $user['name'] ?? $user['email'] ?? 'You';
$initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$shell_page_title    = 'ReliCheck Basic';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = '';
$shell_body_attrs    = 'data-relicheck-basic="landing"';

include __DIR__ . '/_platform_shell_header.php';
?>
<link rel="stylesheet" href="/apps/basic/basic.css?v=<?= @filemtime(__DIR__ . '/apps/basic/basic.css') ?: time() ?>">

<section class="rb-hero">
  <a class="rb-back" href="/app-2026v4.php">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    All studios
  </a>
  <div class="rb-eyebrow">ReliCheck Basic · Free</div>
  <h1>Get two quick scores for a simple survey.</h1>
  <p class="rb-lede">Build a short survey, collect up to 25 responses, and see a Basic SIRI readiness score and a Basic RSSI strength score. When you want the full review, upgrade any time.</p>
  <a class="rb-cta-primary" href="/relicheck-basic-workspace.php">Start a Basic survey
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
  </a>
</section>

<section class="rb-projects" id="rbProjectsSection" hidden>
  <h3 class="rb-h3">Your Basic surveys</h3>
  <div id="rbProjects" class="rb-proj-grid"></div>
</section>

<div class="rb-cols">
  <div>
    <h3 class="rb-h3">What ReliCheck Basic does</h3>
    <ol class="rb-steps-list">
      <li><span>1</span> Create a simple survey and add a few items.</li>
      <li><span>2</span> Get a <strong>Basic SIRI score</strong> — is it ready to send?</li>
      <li><span>3</span> Publish and share your link.</li>
      <li><span>4</span> Collect up to <strong>25 responses</strong>.</li>
      <li><span>5</span> Get a <strong>Basic RSSI score</strong> — are the results strong?</li>
    </ol>
  </div>
  <div>
    <h3 class="rb-h3">What Basic is not</h3>
    <p class="rb-note">Basic gives you the score, not the full explanation system. For item review, construct alignment, readiness guidance, publishing and deployment tools, open <strong>full SIRI</strong>. For reliability, validity, item analysis, data quality, scenario testing, and a defensible report, open <strong>RSSI</strong>. For descriptive and statistical analysis, use the <strong>Descriptive</strong> and <strong>Inferential</strong> studios.</p>
    <p class="rb-note">Basic is capped at 25 responses. Upgrade any time to lift the cap and unlock the full tools — your survey keeps the same identity.</p>
  </div>
</div>

<script>
(function () {
  var section = document.getElementById('rbProjectsSection');
  var host = document.getElementById('rbProjects');
  if (!host) return;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }
  fetch('/api/dev/project-list.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (data) {
      var list = (data && data.ok && Array.isArray(data.projects)) ? data.projects : [];
      list = list.filter(function (p) { return p.tier === 'basic'; });
      if (!list.length) { return; } // no Basic surveys yet — keep section hidden
      section.hidden = false;
      host.innerHTML = list.map(function (p) {
        var rc = p.response_count || 0;
        var status = p.status === 'published' ? 'Published' : 'Draft';
        return '<a class="rb-proj-card" href="/relicheck-basic-workspace.php?project_id=' + encodeURIComponent(p.id) + '">'
          + '<div class="rb-proj-title">' + esc(p.title || 'Untitled survey') + '</div>'
          + '<div class="rb-proj-meta">' + status + ' · ' + (p.items || 0) + ' item' + (p.items === 1 ? '' : 's') + ' · ' + rc + ' / 25 responses</div>'
          + '</a>';
      }).join('');
    })
    .catch(function () { /* leave hidden on error */ });
})();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
