<?php
// descriptive-quick-analysis.php
// Auto-analysis results page. Linked to from the upload widget when a new
// analysis project is created. Runs all applicable analyses client-side and
// presents them without requiring the user to step through the pipeline.
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /login.html?return=' . urlencode('/descriptive-quick-analysis.php' . $qs));
    exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$project_id = (int)($_GET['project_id'] ?? 0);
if (!$project_id) { header('Location: /descriptive-analysis-projects.php'); exit; }

$pdo  = db();
$stmt = $pdo->prepare(
    'SELECT * FROM analysis_projects WHERE id = :id AND user_id = :uid AND status = "active" LIMIT 1'
);
$stmt->execute([':id' => $project_id, ':uid' => $uid]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) { header('Location: /descriptive-analysis-projects.php'); exit; }

$mode = ($_GET['mode'] ?? '') === 'report' ? 'report' : 'auto';

$user_full  = $user['name'] ?? $user['email'] ?? 'You';
$initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$shell_page_title    = 'Quick Analysis — Descriptive Studio';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = htmlspecialchars($project['title'] ?? '');
include __DIR__ . '/_platform_shell_header.php';

$v = (int)time();
?>
<script>
var QA_BOOT = {
  projectId:    <?= $project_id ?>,
  projectTitle: <?= json_encode($project['title'] ?? '') ?>,
  mode:         <?= json_encode($mode) ?>,
  workspaceUrl: '/descriptive-analysis-workspace.php?project_id=<?= $project_id ?>'
};
</script>

<div id="qa-root"></div>
<script src="/apps/analysis-studio/quick-analyze-core.js?v=<?= $v ?>"></script>
<script src="/apps/analysis-studio/quick-analyze.js?v=<?= $v ?>"></script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
