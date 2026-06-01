<?php
// POST /api/analysis/save-dataset.php  { project_id, payload }
// Persist an Evidence-Intake dataset payload VERBATIM onto an analysis
// project, so reopening the project reloads the exact data the engines
// already consume (the `{ dataset: { variables:[...], rowCount } }`
// shape) — no lossy conversion. The optional generic `datasets` link
// (dataset_id) is handled separately by link-dataset.php for SIRI /
// pre-existing datasets.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_analysis_studio.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
analysis_ensure_schema($pdo);

$body      = read_json_body();
$projectId = isset($body['project_id']) ? (int)$body['project_id'] : 0;
$payload   = $body['payload'] ?? null;
if ($projectId <= 0) fail('bad_input', 'project_id is required.');
if (!is_array($payload)) fail('bad_input', 'payload (the intake dataset) is required.');

analysis_require_project($pdo, $uid, $projectId); // ownership

// Normalize to the engine shape: payload may be the full intake object
// ({ studio, dataset:{variables,rowCount} }) or a bare dataset.
$dataset = $payload['dataset'] ?? $payload;
if (!isset($dataset['variables']) || !is_array($dataset['variables'])) {
    fail('bad_payload', 'payload must contain a dataset with a variables array.');
}
$rowCount = (int)($dataset['rowCount'] ?? (isset($dataset['variables'][0]['values']) ? count($dataset['variables'][0]['values']) : 0));

$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) fail('bad_payload', 'payload could not be encoded.');
if (strlen($json) > 8 * 1024 * 1024) fail('payload_too_large', 'Dataset is too large to save (8 MB limit).', 413);

$stmt = $pdo->prepare('UPDATE analysis_projects SET dataset_payload = :p WHERE id = :id AND user_id = :uid');
$stmt->execute([':p' => $json, ':id' => $projectId, ':uid' => $uid]);

json_out(['ok' => true, 'project_id' => $projectId, 'row_count' => $rowCount]);
