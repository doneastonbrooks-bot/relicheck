<?php
// PATCH /api/mm/dataset-cell.php
// Body: { project_id, dataset_id, response_id, variable_id, value }
//
// Edits a single cell value in the project's structured dataset
// (mm_dataset_cells). The cell row may or may not exist; we upsert.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('PATCH', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$body       = read_json_body();
$projectId  = (int)($body['project_id']  ?? 0);
$datasetId  = (int)($body['dataset_id']  ?? 0);
$responseId = (int)($body['response_id'] ?? 0);
$variableId = (int)($body['variable_id'] ?? 0);
$value      = clean_string((string)($body['value'] ?? ''), 400);

if ($projectId <= 0 || $datasetId <= 0 || $variableId <= 0 || $responseId <= 0) {
    fail('bad_input', 'project_id, dataset_id, response_id, and variable_id are required.');
}
mm_require_project($pdo, $uid, $projectId);

// Confirm dataset and variable belong to this project.
$ck = $pdo->prepare(
    'SELECT 1 FROM mm_structured_datasets ds
     INNER JOIN mm_generated_variables v ON v.project_id = ds.project_id
     WHERE ds.id = :d AND v.id = :v AND ds.project_id = :p'
);
$ck->execute([':d' => $datasetId, ':v' => $variableId, ':p' => $projectId]);
if (!$ck->fetch()) fail('mm_cell_scope_invalid', 'Dataset or variable not in this project.', 404);

// Look up the cell row.
$find = $pdo->prepare('SELECT id FROM mm_dataset_cells WHERE dataset_id = :d AND response_id = :r AND variable_id = :v');
$find->execute([':d' => $datasetId, ':r' => $responseId, ':v' => $variableId]);
$cellId = $find->fetchColumn();

if ($cellId) {
    $up = $pdo->prepare('UPDATE mm_dataset_cells SET cell_value = :c WHERE id = :i');
    $up->execute([':c' => $value, ':i' => (int)$cellId]);
} else {
    $ins = $pdo->prepare('INSERT INTO mm_dataset_cells (dataset_id, response_id, variable_id, cell_value) VALUES (:d, :r, :v, :c)');
    $ins->execute([':d' => $datasetId, ':r' => $responseId, ':v' => $variableId, ':c' => $value]);
    $cellId = (int)$pdo->lastInsertId();
}

json_out(['ok' => true, 'cell_id' => (int)$cellId, 'value' => $value]);
