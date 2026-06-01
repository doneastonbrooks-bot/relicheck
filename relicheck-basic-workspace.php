<?php
// ReliCheck Basic — WORKSPACE (guided linear flow).
// A free ENTRY PRODUCT, not a studio. Reuses the survey_projects spine and the
// EXISTING SIRI (LaunchCheck) + RSSI engines, exposing only headline scores.
// Mounts NO studio engines, no labs, no full reports. See [[project_relicheck_basic]].

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/relicheck-basic-workspace.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;

$user_full  = $user['name'] ?? $user['email'] ?? 'You';
$initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$shell_page_title    = 'ReliCheck Basic';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = '';
$shell_body_attrs    = 'data-relicheck-basic="workspace"';

include __DIR__ . '/_platform_shell_header.php';

function rb_asset_v(string $p): string { $f = __DIR__ . $p; return (string)(@filemtime($f) ?: time()); }
?>
<link rel="stylesheet" href="/apps/basic/basic.css?v=<?= rb_asset_v('/apps/basic/basic.css') ?>">

<a class="rb-back" href="/relicheck-basic.php">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
  ReliCheck Basic
</a>

<div id="basicRoot" class="rb-root" data-project-id="<?= (int)$projectId ?>">
  <div class="rb-loading">Loading…</div>
</div>

<?php
// Existing, tested engines — same set develop.php loads. Basic runs them
// headlessly and reads ONLY the headline score. No engine is modified.
$__eng = ['/apps/sdsi/validity-lens-engine.js', '/apps/sdsi/buildcheck-engine.js', '/apps/sdsi/launchcheck-engine.js', '/apps/rssi/rssi-engine.js'];
foreach ($__eng as $__e) {
  echo '<script src="' . htmlspecialchars($__e . '?v=' . rb_asset_v($__e)) . '"></script>' . "\n";
}
?>
<script src="/apps/basic/basic.js?v=<?= rb_asset_v('/apps/basic/basic.js') ?>" defer></script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
