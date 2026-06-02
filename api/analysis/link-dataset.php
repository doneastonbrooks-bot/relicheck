<?php
// POST /api/analysis/link-dataset.php  { project_id, dataset_id }
// Link an existing (owned) dataset to an analysis project. Mirrors the
// generic half of api/mm/link-dataset.php without the MM-specific
// text-response materialization — Descriptive/Inferential read the
// dataset directly.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_analysis_studio.php';
require_once __DIR__ . '/../_dataset_helpers.php';
require_once __DIR__ . '/../_rc_projects.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
analysis_ensure_schema($pdo);

$body      = read_json_body();
$projectId = isset($body['project_id']) ? (int)$body['project_id'] : 0;
$datasetId = isset($body['dataset_id']) ? (int)$body['dataset_id'] : 0;
if ($projectId <= 0 || $datasetId <= 0) fail('bad_input', 'project_id and dataset_id are required.');

// Ownership: the project and the dataset must both belong to this user.
analysis_require_project($pdo, $uid, $projectId);
$ds = $pdo->prepare('SELECT id, row_count, column_count FROM datasets WHERE id = :id AND owner_id = :uid LIMIT 1');
$ds->execute([':id' => $datasetId, ':uid' => $uid]);
$drow = $ds->fetch(PDO::FETCH_ASSOC);
if (!$drow) fail('dataset_not_found', 'Dataset not found or not owned.', 404);

$stmt = $pdo->prepare('UPDATE analysis_projects SET dataset_id = :d WHERE id = :id AND user_id = :uid');
$stmt->execute([':d' => $datasetId, ':id' => $projectId, ':uid' => $uid]);

// RE Item 3: propagate dataset link to ecosystem project (if this project has one).
$rcId = rc_project_id_for_studio($pdo, 'analysis_projects', $projectId);
if ($rcId !== null) rc_set_project_dataset($pdo, $rcId, $datasetId);

rc_seed_var_meta_from_dataset($pdo, $projectId, 'analysis', $datasetId, $rcId);

json_out([
    'ok'           => true,
    'project_id'   => $projectId,
    'dataset_id'   => $datasetId,
    'row_count'    => (int)$drow['row_count'],
    'column_count' => (int)$drow['column_count'],
]);
