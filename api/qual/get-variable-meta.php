<?php
// GET /api/qual/get-variable-meta.php?project_id=N
// Returns variable_metadata rows for the qual project's linked dataset,
// shaped as rawVars for the shared DataMap component.

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user      = require_auth();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

$project = qual_require_project($pdo, $uid, $projectId);

// Find the linked dataset via the first qual_document
$doc = $pdo->prepare(
    'SELECT dataset_id FROM qual_documents WHERE project_id=:p AND dataset_id IS NOT NULL AND status="active" ORDER BY id LIMIT 1'
);
$doc->execute([':p' => $projectId]);
$docRow = $doc->fetch(PDO::FETCH_ASSOC);
if (!$docRow) {
    json_out(['ok' => true, 'variables' => [], 'dataset_id' => null, 'rc_project_id' => $project['rc_project_id']]);
}

$datasetId = (int)$docRow['dataset_id'];

// Load column_meta from the dataset to build rawVars
$ds = $pdo->prepare('SELECT column_meta FROM datasets WHERE id=:id LIMIT 1');
$ds->execute([':id' => $datasetId]);
$dsRow = $ds->fetch(PDO::FETCH_ASSOC);
if (!$dsRow) {
    json_out(['ok' => true, 'variables' => [], 'dataset_id' => $datasetId, 'rc_project_id' => $project['rc_project_id']]);
}

$colMeta = $dsRow['column_meta'];
if (is_string($colMeta)) $colMeta = json_decode($colMeta, true);
if (!is_array($colMeta)) $colMeta = [];

// Build rawVars in the shape DataMap expects: [{ name, analysis_type, values[] }]
// For DataMap, values is used to infer types — pass empty array (types come from column_meta).
$variables = array_values(array_map(function (array $col) {
    return [
        'name'          => (string)($col['name'] ?? ''),
        'analysis_type' => (string)($col['analysis_type'] ?? $col['type'] ?? ''),
        'values'        => [],
    ];
}, $colMeta));

json_out([
    'ok'           => true,
    'variables'    => $variables,
    'dataset_id'   => $datasetId,
    'rc_project_id'=> $project['rc_project_id'],
]);
