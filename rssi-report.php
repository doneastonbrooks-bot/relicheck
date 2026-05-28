<?php
// rssi-report.php — Standalone RSSI dashboard for one survey project.
// Uses its OWN clean Apple-style template (no platform shell, no studio
// template). Per project-memory.md, the Studio Template is for analysis
// pages only; this is a polished deliverable app.
//
// URL: /rssi-report.php?id=N   (preferred)
//      /rssi-report.php?project_id=N   (backward compatible)

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /login.html?return=' . urlencode($_SERVER['SCRIPT_NAME'] . $qs));
    exit;
}
$rssi_user = current_user();
if (!$rssi_user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$surveyId = isset($_GET['id']) ? (int)$_GET['id']
          : (isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0);
if ($surveyId <= 0) { header('Location: /rssi-projects.php'); exit; }

$pdo  = db();
$stmt = $pdo->prepare('SELECT id, title, slug FROM surveys WHERE id = :id AND owner_id = :uid AND (archived_at IS NULL)');
$stmt->execute([':id' => $surveyId, ':uid' => $uid]);
$rssi_project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rssi_project) { header('Location: /rssi-projects.php'); exit; }

$rssi_back_url = '/rssi-projects.php';

// Cache-bust the CSS + JS so deploys land immediately in browsers.
$_css_ver = filemtime(__DIR__ . '/apps/rssi/rssi.css');
$_js_ver  = filemtime(__DIR__ . '/apps/rssi/rssi.js');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($rssi_project['title']) ?> · Strength Survey Index · ReliCheck</title>
  <link rel="icon" type="image/png" href="/RSSI-logo.png">
  <link rel="stylesheet" href="/apps/rssi/rssi.css?v=<?= $_css_ver ?>">
</head>
<body>
<script>
  window.RELICHECK_PROJECT_ID = <?= (int)$surveyId ?>;
  window.RELICHECK_STUDIO     = 'rssi';
</script>

<!-- Locked top-right ReliCheck wordmark — greyed, never blocks clicks -->
<div class="rssi-corner-brand" aria-hidden="true">
  <img src="/logo-brand.svg" alt="">
</div>

<?php include __DIR__ . '/apps/rssi/render.php'; ?>

<script src="/apps/rssi/rssi.js?v=<?= $_js_ver ?>" defer></script>
</body>
</html>
