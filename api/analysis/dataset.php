<?php
// GET /api/analysis/dataset.php?project_id=N
// Return the dataset the workspace should load for this analysis project.
// Resolution order:
//   1. The verbatim Evidence-Intake payload saved on the project
//      (analysis_projects.dataset_payload) — the engine's native shape.
//   2. A linked generic `datasets` row (dataset_id), converted to the
//      engine's { variables:[{name, types, values}], rowCount } shape.
//   3. none → { ok:true, has_data:false }.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_analysis_studio.php';

require_method('GET');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
analysis_ensure_schema($pdo);

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($projectId <= 0) fail('bad_input', 'project_id is required.');
$project = analysis_require_project($pdo, $uid, $projectId);

// 1. Verbatim saved payload.
if (!empty($project['dataset_payload'])) {
    $payload = json_decode((string)$project['dataset_payload'], true);
    if (is_array($payload)) {
        $dataset = $payload['dataset'] ?? $payload;
        json_out(['ok' => true, 'has_data' => true, 'source' => 'saved', 'payload' => $payload, 'dataset' => $dataset]);
    }
}

// 2. Linked generic dataset → convert to engine shape.
if (!empty($project['dataset_id'])) {
    $ds = $pdo->prepare('SELECT id, title, column_meta, data, row_count FROM datasets WHERE id = :id AND owner_id = :uid LIMIT 1');
    $ds->execute([':id' => (int)$project['dataset_id'], ':uid' => $uid]);
    $drow = $ds->fetch(PDO::FETCH_ASSOC);
    if ($drow) {
        $cols = json_decode((string)$drow['column_meta'], true) ?: [];
        $rows = json_decode((string)$drow['data'], true) ?: []; // 2-D array, column order == column_meta
        $variables = [];
        foreach ($cols as $colIdx => $cm) {
            $vals = [];
            foreach ($rows as $r) { $vals[] = isset($r[$colIdx]) ? $r[$colIdx] : ''; }
            $variables[] = [
                'name'   => (string)($cm['name'] ?? ('Column ' . ($colIdx + 1))),
                'types'  => [(string)($cm['type'] ?? 'open')],
                'values' => $vals,
            ];
        }
        $dataset = ['source' => (string)$drow['title'], 'variables' => $variables, 'rowCount' => (int)$drow['row_count']];
        // Look up any survey_projects row linked to the same dataset so the Studio
        // can show the RSSI badge even when the project was opened from the RSSI handoff.
        $spRow = $pdo->prepare('SELECT id FROM survey_projects WHERE dataset_id = :did AND user_id = :uid LIMIT 1');
        $spRow->execute([':did' => (int)$project['dataset_id'], ':uid' => $uid]);
        $spId = $spRow->fetchColumn();
        $extra = $spId ? ['survey_project_id' => (int)$spId] : [];
        json_out(array_merge(['ok' => true, 'has_data' => true, 'source' => 'dataset', 'dataset' => $dataset, 'payload' => ['studio' => (string)$project['kind'], 'dataset' => $dataset]], $extra));
    }
}

json_out(['ok' => true, 'has_data' => false]);
