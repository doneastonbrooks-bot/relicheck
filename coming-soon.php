<?php
// Coming Soon stub.
// -------------------------------------------------------------------
// Rail items whose engines are still under construction route here
// instead of dead-ending at "#". Reads ?studio= and ?item= so the page
// shows the right studio chrome and tells the user which analysis is
// coming. Wired by _studio_mount.php's fallback in the rail-route loop.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/coming-soon.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$valid_studios = ['survey', 'mm', 'tia', '360'];
$studio_slug   = $_GET['studio'] ?? 'survey';
if (!in_array($studio_slug, $valid_studios, true)) $studio_slug = 'survey';

$itemKey   = preg_replace('/[^a-z_]/', '', (string)($_GET['item'] ?? ''));
$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;

// Try to find a human label for the item from the catalog.
$itemLabel = ucwords(str_replace('_', ' ', $itemKey));
$catalog = require __DIR__ . '/_studio_items_catalog.php';
foreach ($catalog as $section) {
  foreach ($section['items'] as $it) {
    if (($it['key'] ?? '') === $itemKey) {
      $itemLabel = strip_tags((string)($it['label'] ?? $itemLabel));
      break 2;
    }
  }
}

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios[$studio_slug];

$shell_page_title    = $itemLabel . ' · Coming soon — ' . $studio['name'];
$shell_user_full     = $user['name'] ?? $user['email'] ?? 'You';
$shell_user_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $shell_user_full) ?: 'U', 0, 2));
$shell_project_label = $projectId ? ('Project ' . $projectId) : 'No project open';
$shell_body_attrs    = 'data-current-studio="' . $studio_slug . '" data-coming-soon="' . htmlspecialchars($itemKey) . '"';

include __DIR__ . '/_platform_shell_header.php';
?>

<style>
  :root {
    --landing-accent:      <?= htmlspecialchars($studio['accent']) ?>;
    --landing-accent-soft: <?= htmlspecialchars($studio['accent_soft']) ?>;
  }
  .cs-shell { max-width: 720px; margin: 80px auto; padding: 32px; background: #fff; border: 1px solid var(--line); border-radius: 14px; text-align: center; }
  .cs-pill { display: inline-block; padding: 6px 12px; background: var(--landing-accent-soft); color: var(--landing-accent); border-radius: 999px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 14px; }
  .cs-shell h1 { font-family: 'Fraunces', 'Georgia', serif; font-size: 28px; font-weight: 600; margin: 0 0 8px; color: var(--ink-1); }
  .cs-shell p { color: var(--ink-3); font-size: 15px; line-height: 1.55; margin: 0 0 18px; }
  .cs-actions { display: flex; gap: 10px; justify-content: center; margin-top: 22px; flex-wrap: wrap; }
  .cs-actions a { padding: 9px 18px; border-radius: 999px; font-weight: 600; font-size: 14px; text-decoration: none; }
  .cs-actions .btn-dark { background: var(--ink-1); color: #fff; }
  .cs-actions .btn-dark:hover { background: var(--landing-accent); }
  .cs-actions .btn-line { background: #fff; color: var(--ink-3); border: 1px solid var(--line); }
  .cs-actions .btn-line:hover { border-color: var(--landing-accent); color: var(--landing-accent); }
</style>

<div class="cs-shell">
  <div class="cs-pill">Coming soon</div>
  <h1><?= htmlspecialchars($itemLabel) ?></h1>
  <p>This analysis is on the roadmap for the <strong><?= htmlspecialchars($studio['name']) ?></strong>. The rail item is in place so the menu structure is complete; the engine is still being built. Your data is safe and will be ready when this lens ships.</p>
  <div class="cs-actions">
    <a class="btn-dark" href="<?= htmlspecialchars('/project-snapshot.php?studio=' . $studio_slug . ($projectId ? '&project_id=' . $projectId : '')) ?>">Back to Overview</a>
    <a class="btn-line" href="/studio-<?= htmlspecialchars($studio_slug) ?>.php">Studio home</a>
  </div>
</div>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
