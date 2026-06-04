<?php
// POST /api/qual/rematerialize.php
// Body: { project_id }
// Re-runs segment materialization for every linked document in a qual project.
// Safe to call on any project: deletes and re-inserts segments per document.
// Returns: { ok, documents_processed, seg_count, detail[] }

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
if ($projectId <= 0) fail('bad_input', 'project_id required.');

$project = qual_require_project($pdo, $uid, $projectId);

// Load all active documents that have a linked dataset.
$docs = $pdo->prepare(
    'SELECT id, dataset_id, title FROM qual_documents
      WHERE project_id = :p AND dataset_id IS NOT NULL AND status = "active"
      ORDER BY id'
);
$docs->execute([':p' => $projectId]);
$rows = $docs->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    json_out(['ok' => true, 'documents_processed' => 0, 'seg_count' => 0,
              'detail' => [], 'message' => 'No linked documents found. Upload a dataset first.']);
}

$detail   = [];
$totalSeg = 0;

foreach ($rows as $doc) {
    $docId     = (int)$doc['id'];
    $datasetId = (int)$doc['dataset_id'];
    try {
        $segCount = qual_materialize_segments($pdo, $projectId, $docId, $datasetId);
        $detail[] = ['document_id' => $docId, 'title' => $doc['title'],
                     'dataset_id' => $datasetId, 'seg_count' => $segCount, 'ok' => true];
        $totalSeg += $segCount;
    } catch (Throwable $e) {
        $detail[] = ['document_id' => $docId, 'title' => $doc['title'],
                     'dataset_id' => $datasetId, 'seg_count' => 0, 'ok' => false,
                     'error' => $e->getMessage()];
        error_log('rematerialize doc ' . $docId . ': ' . $e->getMessage());
    }
}

json_out([
    'ok'                  => true,
    'documents_processed' => count($rows),
    'seg_count'           => $totalSeg,
    'detail'              => $detail,
]);
