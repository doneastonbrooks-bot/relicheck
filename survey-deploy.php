<?php
// Survey Deployment Workspace
// -------------------------------------------------------------------
// Uses the Platform Shell ONLY (top nav, no left rail). The Studio
// Template is reserved for analysis pages — see project-memory.md
// "Studio Template scope (load-bearing rule)".
//
// URL: /survey-deploy.php?id=N   (preferred)
//      /survey-deploy.php?project_id=N   (backward compatible)

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

// ---------- Auth ----------
start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode($_SERVER['SCRIPT_NAME'] . $qs));
  exit;
}
$mount_user = current_user();
if (!$mount_user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

// ---------- Validate survey ownership ----------
$surveyId = isset($_GET['id']) ? (int)$_GET['id']
          : (isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0);
if ($surveyId <= 0) { header('Location: /studio-survey-projects.php'); exit; }

$pdo = db();
$stmt = $pdo->prepare('SELECT id, title, slug, is_published FROM surveys WHERE id = :id AND owner_id = :uid AND (archived_at IS NULL)');
$stmt->execute([':id' => $surveyId, ':uid' => $uid]);
$survey = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$survey) { header('Location: /studio-survey-projects.php'); exit; }

// Expose for render.php
$projectId = (int)$survey['id'];

// ---------- Shell variables ----------
$shell_body_attrs    = 'data-project-id="' . $projectId . '" data-page="deploy"';
$shell_page_title    = 'Deploy · ' . $survey['title'] . ' · ReliCheck';
$shell_user_full     = $mount_user['name'] ?? $mount_user['email'] ?? 'You';
$shell_user_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $shell_user_full) ?: 'U', 0, 2));
$shell_project_label = (string)$survey['title'];
$shell_project_id    = (string)$projectId;

// ---------- App registry lookup (for css/js paths) ----------
$_apps = require __DIR__ . '/_app_registry.php';
$_app  = $_apps['deploy'];

// ---------- Render ----------
include __DIR__ . '/_platform_shell_header.php';
?>

<?php
  // Cache-bust deploy.css
  $_css_url  = $_app['css'];
  $_css_path = __DIR__ . $_app['css'];
  $_css_ver  = is_file($_css_path) ? filemtime($_css_path) : time();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($_css_url . '?v=' . $_css_ver) ?>">

<style>
  /* Force a clean white page for the deploy workspace */
  html, body { background: #ffffff !important; }

  /* Platform-shell page wrapper — the deploy app owns the rest */
  .deploy-page-wrap {
    max-width: 1280px;
    margin: 0 auto;
    padding: 28px 32px 64px;
    box-sizing: border-box;
    background: #ffffff;
  }
  @media (max-width: 700px) {
    .deploy-page-wrap { padding: 18px 14px 48px; }
  }

  /* Back link above the page header */
  .deploy-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 18px;
    font-size: 13px;
    font-weight: 600;
    color: #5f6368;
    text-decoration: none;
    transition: color 0.12s;
  }
  .deploy-back:hover { color: #e85d3a; }
  .deploy-back svg { width: 14px; height: 14px; }
</style>

<div class="deploy-page-wrap">

  <a class="deploy-back" href="/studio-survey-projects.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <polyline points="15 18 9 12 15 6"/>
    </svg>
    Back to surveys
  </a>

  <?php include $_app['render']; ?>

</div>

<?php
  // Cache-bust deploy.js
  $_js_url  = $_app['js'];
  $_js_path = __DIR__ . $_app['js'];
  $_js_ver  = is_file($_js_path) ? filemtime($_js_path) : time();
?>
<script src="<?= htmlspecialchars($_js_url . '?v=' . $_js_ver) ?>" defer></script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
