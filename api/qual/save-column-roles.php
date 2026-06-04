<?php
// POST /api/qual/save-column-roles.php
// Body: { project_id: N, columns: [{name, qual_role}] }
// qual_role one of: open_ended | participant_id | participant_info | skip
//
// Writes qual_role into datasets.column_meta for the project's linked dataset,
// then triggers rematerialization so the segment table reflects the new roles.
// Returns: { ok, seg_count, documents_processed }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
$user = require_auth();
release_session_lock();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId = (int)($body['project_id'] ?? 0);
$incoming  = is_array($body['columns'] ?? null) ? $body['columns'] : [];
if ($projectId <= 0) fail('bad_input', 'project_id required.');
if (!$incoming)      fail('bad_input', 'columns array required.');

$VALID_ROLES = ['open_ended', 'participant_id', 'participant_info', 'skip'];

// Normalise and index the incoming roles by column name.
$roleMap = [];
foreach ($incoming as $col) {
    $name = trim((string)($col['name'] ?? ''));
    $role = (string)($col['qual_role'] ?? '');
    if ($name === '' || !in_array($role, $VALID_ROLES, true)) continue;
    $roleMap[$name] = $role;
}
if (!$roleMap) fail('bad_input', 'No valid column roles supplied.');

$project = qual_require_project($pdo, $uid, $projectId);

// Find the linked dataset (via qual_documents).
$docStmt = $pdo->prepare(
    'SELECT id, dataset_id FROM qual_documents
      WHERE project_id=:p AND dataset_id IS NOT NULL AND status="active"
      ORDER BY id LIMIT 1'
);
$docStmt->execute([':p' => $projectId]);
$doc = $docStmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) fail('not_found', 'No linked dataset found. Upload a dataset first.');

$datasetId = (int)$doc['dataset_id'];

// Load column_meta from the dataset.
$dsStmt = $pdo->prepare('SELECT column_meta FROM datasets WHERE id=:id LIMIT 1');
$dsStmt->execute([':id' => $datasetId]);
$dsRow = $dsStmt->fetch(PDO::FETCH_ASSOC);
if (!$dsRow) fail('not_found', 'Dataset not found.');

$colMeta = $dsRow['column_meta'];
if (is_string($colMeta)) $colMeta = json_decode($colMeta, true);
if (!is_array($colMeta))  $colMeta = [];

// Apply qual_role to each matching column.
foreach ($colMeta as &$col) {
    $name = (string)($col['name'] ?? '');
    if (!isset($roleMap[$name])) continue;
    $col['qual_role'] = $roleMap[$name];
}
unset($col);

// Persist back.
$pdo->prepare('UPDATE datasets SET column_meta=:cm WHERE id=:id')
    ->execute([':cm' => json_encode(array_values($colMeta)), ':id' => $datasetId]);

// Rematerialize all documents for this project.
$docsStmt = $pdo->prepare(
    'SELECT id, dataset_id, title FROM qual_documents
      WHERE project_id=:p AND dataset_id IS NOT NULL AND status="active"
      ORDER BY id'
);
$docsStmt->execute([':p' => $projectId]);
$docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalSeg = 0;
$processed = 0;
foreach ($docs as $d) {
    try {
        $totalSeg += qual_materialize_segments($pdo, $projectId, (int)$d['id'], (int)$d['dataset_id']);
        $processed++;
    } catch (Throwable $e) {
        error_log('save-column-roles rematerialize doc ' . $d['id'] . ': ' . $e->getMessage());
    }
}

json_out([
    'ok'                  => true,
    'seg_count'           => $totalSeg,
    'documents_processed' => $processed,
]);
