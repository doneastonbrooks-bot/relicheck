<?php
// POST /api/surveys/create_from_dataset.php
// Body: { dataset_id, title? }
// Owner-only. Creates a brand-new survey whose questions mirror the dataset's
// column_meta, then imports every dataset row as a response. Returns the new
// survey's id and slug so the caller can navigate straight to its analytics.
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
if ($datasetId <= 0) fail('bad_dataset_id', 'Missing or invalid dataset_id.');

$pdo = db();

// Owner check on dataset
$dstmt = $pdo->prepare(
    'SELECT id, owner_id, title, column_meta, settings, data, row_count
       FROM datasets WHERE id = :id'
);
$dstmt->execute([':id' => $datasetId]);
$ds = $dstmt->fetch();
if (!$ds) fail('dataset_not_found', 'Dataset not found.', 404);
if ((int)$ds['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this dataset.', 403);

$columnMeta = json_decode((string)$ds['column_meta'], true);
$dsSettings = json_decode((string)$ds['settings'],    true);
$dataRows   = json_decode((string)$ds['data'],        true);
if (!is_array($columnMeta)) $columnMeta = [];
if (!is_array($dsSettings)) $dsSettings = [];
if (!is_array($dataRows))   $dataRows   = [];

$built     = dts_build_questions_and_index($columnMeta, $dataRows, $dsSettings);
$questions = $built['questions'];
$colIndex  = $built['col_index'];

if (count($questions) === 0) {
    fail('no_usable_columns',
         'This dataset has no Likert, single-choice, or open-text columns to convert into questions.', 422);
}

// Tier checks: total surveys + question count + response count
$current = (int)$pdo->query(
    'SELECT COUNT(*) AS c FROM surveys WHERE owner_id = ' . (int)$user['id']
)->fetch()['c'];
require_under_limit((int)$user['id'], 'max_surveys', $current);
require_under_limit((int)$user['id'], 'max_questions_per_survey', 0, count($questions));
require_under_limit((int)$user['id'], 'max_responses_per_survey', 0, count($dataRows));

// Survey settings: pull Likert defaults from the dataset's settings.
$kPoints = (int)($dsSettings['likertPoints'] ?? 5);
if ($kPoints < 2 || $kPoints > 11) $kPoints = 5;
$settings = [
    'likertPoints' => $kPoints,
    'likertLow'    => clean_string((string)($dsSettings['likertLow']  ?? 'Strongly disagree'), 80),
    'likertHigh'   => clean_string((string)($dsSettings['likertHigh'] ?? 'Strongly agree'),    80),
];

$title = clean_string((string)($body['title'] ?? ''), 255);
if ($title === '') {
    $title = clean_string((string)($ds['title'] ?? 'Imported dataset'), 255);
    if ($title === '') $title = 'Imported dataset';
}
$desc = 'Created from dataset #' . (int)$ds['id'] . '.';

$slug = unique_survey_slug($pdo);

$pdo->beginTransaction();
try {
    $insSurvey = $pdo->prepare(
        'INSERT INTO surveys (owner_id, slug, title, description, settings, questions, is_published)
         VALUES (:uid, :slug, :title, :desc, :settings, :questions, 0)'
    );
    $insSurvey->execute([
        ':uid'       => $user['id'],
        ':slug'      => $slug,
        ':title'     => $title,
        ':desc'      => $desc,
        ':settings'  => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ':questions' => json_encode($questions, JSON_UNESCAPED_UNICODE),
    ]);
    $surveyId = (int)$pdo->lastInsertId();

    $insResp = $pdo->prepare(
        'INSERT INTO responses (survey_id, ip_hash, user_agent, answers, arm_id)
         VALUES (:sid, NULL, :ua, :ans, NULL)'
    );
    $inserted = 0;
    $skipped  = 0;
    foreach ($dataRows as $row) {
        if (!is_array($row)) { $skipped++; continue; }
        $answers = dts_row_to_answers($row, $colIndex);
        if (count($answers) === 0) { $skipped++; continue; }
        $insResp->execute([
            ':sid' => $surveyId,
            ':ua'  => 'imported-from-dataset:' . $datasetId,
            ':ans' => json_encode($answers, JSON_UNESCAPED_UNICODE),
        ]);
        $inserted++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fail('create_failed', 'Could not create survey: ' . $e->getMessage(), 500);
}

json_out([
    'ok'        => true,
    'survey'    => [
        'id'             => $surveyId,
        'slug'           => $slug,
        'title'          => $title,
        'description'    => $desc,
        'is_published'   => false,
        'settings'       => $settings,
        'questions'      => $questions,
        'item_count'     => count($questions),
        'likert_count'   => count(array_filter($questions, fn($q) => ($q['type'] ?? null) === 'likert')),
        'response_count' => $inserted,
    ],
    'inserted' => $inserted,
    'skipped'  => $skipped,
], 201);
