<?php
// POST /api/qual/link-dataset.php
// Links a dataset (from the upload widget) to a qual_project.
// Creates a qual_document + materializes qual_segments from open columns.
// Body: { project_id, dataset_id, title? }
// Returns: { ok, document_id, seg_count }

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
$datasetId = (int)($body['dataset_id'] ?? 0);
if ($projectId <= 0 || $datasetId <= 0) fail('bad_input', 'project_id and dataset_id required.');

$project = qual_require_project($pdo, $uid, $projectId);

// Verify the dataset belongs to this user
$ds = $pdo->prepare('SELECT id, title FROM datasets WHERE id = :id AND owner_id = :u LIMIT 1');
$ds->execute([':id' => $datasetId, ':u' => $uid]);
$dsRow = $ds->fetch(PDO::FETCH_ASSOC);
if (!$dsRow) fail('not_found', 'Dataset not found.', 404);

$title = trim((string)($body['title'] ?? '')) ?: ($dsRow['title'] ?? 'Imported dataset');

$pdo->beginTransaction();
try {
    // Create or replace document for this dataset
    $existing = $pdo->prepare(
        'SELECT id FROM qual_documents WHERE project_id=:p AND dataset_id=:d LIMIT 1'
    );
    $existing->execute([':p' => $projectId, ':d' => $datasetId]);
    $existingDoc = $existing->fetch(PDO::FETCH_ASSOC);

    if ($existingDoc) {
        $documentId = (int)$existingDoc['id'];
        $pdo->prepare('UPDATE qual_documents SET title=:t WHERE id=:id')
            ->execute([':t' => $title, ':id' => $documentId]);
    } else {
        $pdo->prepare(
            'INSERT INTO qual_documents (project_id,dataset_id,title,source_type) VALUES (:p,:d,:t,"survey")'
        )->execute([':p' => $projectId, ':d' => $datasetId, ':t' => $title]);
        $documentId = (int)$pdo->lastInsertId();
    }

    // Materialize segments
    $segCount = qual_materialize_segments($pdo, $projectId, $documentId, $datasetId);

    qual_audit($pdo, $projectId, $uid, 'dataset_linked', 'document', $documentId, $title,
               '', "dataset_id:{$datasetId}, segments:{$segCount}");
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('qual/link-dataset: ' . $e->getMessage());
    fail('db_error', 'Could not link dataset: ' . $e->getMessage());
}

json_out(['ok' => true, 'document_id' => $documentId, 'seg_count' => $segCount]);
