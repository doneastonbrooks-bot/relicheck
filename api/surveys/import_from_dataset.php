<?php
// POST /api/surveys/import_from_dataset.php
// Body: { dataset_id, survey_id }
// Owner-only on both. Adds one row in `responses` per dataset row, mapping
// dataset columns to existing survey questions by question id (auto-generated
// from the column name) and falling back to question prompt.
//
// Additive endpoint. Does not modify any existing list/get/update path.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/../_dataset_to_survey.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$datasetId = (int)($body['dataset_id'] ?? 0);
$surveyId  = (int)($body['survey_id']  ?? 0);
if ($datasetId <= 0) fail('bad_dataset_id', 'Missing or invalid dataset_id.');
if ($surveyId  <= 0) fail('bad_survey_id',  'Missing or invalid survey_id.');

$pdo = db();

// Owner check on dataset
$dstmt = $pdo->prepare(
    'SELECT id, owner_id, column_meta, data, row_count
       FROM datasets WHERE id = :id'
);
$dstmt->execute([':id' => $datasetId]);
$ds = $dstmt->fetch();
if (!$ds) fail('dataset_not_found', 'Dataset not found.', 404);
if ((int)$ds['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this dataset.', 403);

// Owner check on survey
$sstmt = $pdo->prepare(
    'SELECT id, owner_id, questions FROM surveys WHERE id = :id'
);
$sstmt->execute([':id' => $surveyId]);
$srv = $sstmt->fetch();
if (!$srv) fail('survey_not_found', 'Survey not found.', 404);
if ((int)$srv['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

$columnMeta = json_decode((string)$ds['column_meta'], true);
$dataRows   = json_decode((string)$ds['data'], true);
$questions  = json_decode((string)$srv['questions'], true);
if (!is_array($columnMeta)) $columnMeta = [];
if (!is_array($dataRows))   $dataRows   = [];
if (!is_array($questions))  $questions  = [];

if (count($questions) === 0) {
    fail('survey_empty', 'Target survey has no questions yet. Build the survey first or use create_from_dataset.', 422);
}

$colIndex = dts_match_existing_questions($columnMeta, $dataRows, $questions);
if (count($colIndex) === 0) {
    fail('no_matching_columns',
         'No dataset columns matched the survey questions by id or prompt.', 422);
}

// Tier check: total responses for this survey must stay under cap.
$cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM responses WHERE survey_id = :sid');
$cnt->execute([':sid' => $surveyId]);
$current = (int)$cnt->fetch()['c'];
require_under_limit((int)$user['id'], 'max_responses_per_survey', $current, count($dataRows));

$ins = $pdo->prepare(
    'INSERT INTO responses (survey_id, ip_hash, user_agent, answers, arm_id)
     VALUES (:sid, NULL, :ua, :ans, NULL)'
);

$pdo->beginTransaction();
$inserted = 0;
$skipped  = 0;
try {
    foreach ($dataRows as $row) {
        if (!is_array($row)) { $skipped++; continue; }
        $answers = dts_row_to_answers($row, $colIndex);
        if (count($answers) === 0) { $skipped++; continue; }
        $ins->execute([
            ':sid' => $surveyId,
            ':ua'  => 'imported-from-dataset:' . $datasetId,
            ':ans' => json_encode($answers, JSON_UNESCAPED_UNICODE),
        ]);
        $inserted++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fail('import_failed', 'Import failed: ' . $e->getMessage(), 500);
}

json_out([
    'ok'                => true,
    'survey_id'         => $surveyId,
    'dataset_id'        => $datasetId,
    'inserted'          => $inserted,
    'skipped'           => $skipped,
    'matched_columns'   => count($colIndex),
], 201);
