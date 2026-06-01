<?php
// POST /api/dev/project-open.php
// Body: { project_id, open: true|false }
// Flips responses_open in deployment_settings.settings.
// Phase 3C — controls whether the public link serves the survey.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
sds_ensure_schema($pdo);

$body      = read_json_body();
$projectId = isset($body['project_id']) ? (int)$body['project_id'] : 0;
$open      = isset($body['open']) ? (bool)$body['open'] : false;

sds_require_project($pdo, (int)$user['id'], $projectId);

$dsStmt = $pdo->prepare('SELECT settings FROM deployment_settings WHERE project_id = :id');
$dsStmt->execute([':id' => $projectId]);
$dsRow = $dsStmt->fetch(PDO::FETCH_ASSOC);
if (!$dsRow) {
    fail('not_published', 'Generate a survey link first via the Publish Readiness step.', 422);
}

$ds = json_decode((string)$dsRow['settings'], true) ?: [];
if (empty($ds['link_key'])) {
    fail('not_published', 'Generate a survey link first via the Publish Readiness step.', 422);
}

$ds['responses_open'] = $open;
if ($open && empty($ds['opened_at'])) {
    $ds['opened_at'] = date('Y-m-d H:i:s');
}

$dsJson = json_encode($ds, JSON_UNESCAPED_UNICODE);
$pdo->prepare(
    'UPDATE deployment_settings SET settings = :s, updated_at = NOW() WHERE project_id = :id'
)->execute([':s' => $dsJson, ':id' => $projectId]);

json_out(['ok' => true, 'deployment' => $ds]);
