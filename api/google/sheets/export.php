<?php
// POST /api/google/sheets/export.php
// Body: { "survey_id": <int> }
// Creates a brand new Google Sheet in the user's Drive containing all
// responses for the given survey, plus a small summary tab. Returns the
// spreadsheet URL on success so the front-end can open it.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_google.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$surveyId = (int)($body['survey_id'] ?? 0);
if ($surveyId <= 0) fail('bad_id', 'Missing or invalid survey_id.');

$pdo = db();

// Owner check + load survey definition.
$stmt = $pdo->prepare(
    'SELECT id, owner_id, title, settings, questions FROM surveys WHERE id = :id'
);
$stmt->execute([':id' => $surveyId]);
$survey = $stmt->fetch();
if (!$survey)                                  fail('not_found', 'Survey not found.', 404);
if ((int)$survey['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

$questions = json_decode((string)$survey['questions'], true);
if (!is_array($questions)) $questions = [];

// Build the header row: id, submitted_at, then one column per question.
$header = ['response_id', 'submitted_at'];
$qIds = [];
foreach ($questions as $q) {
    $qid   = (string)($q['id']    ?? '');
    $title = (string)($q['title'] ?? $q['label'] ?? $qid);
    if ($qid === '') continue;
    $header[] = $title !== '' ? $title : $qid;
    $qIds[] = $qid;
}

// Pull all responses.
$rstmt = $pdo->prepare('SELECT id, submitted_at, answers FROM responses WHERE survey_id = :sid ORDER BY submitted_at ASC');
$rstmt->execute([':sid' => $surveyId]);

$rows = [$header];
$count = 0;
while ($r = $rstmt->fetch()) {
    $answers = json_decode((string)$r['answers'], true);
    if (!is_array($answers)) $answers = [];
    $row = [(int)$r['id'], (string)$r['submitted_at']];
    foreach ($qIds as $qid) {
        $val = $answers[$qid] ?? '';
        if (is_array($val)) $val = implode('; ', array_map('strval', $val));
        $row[] = is_scalar($val) ? (string)$val : '';
    }
    $rows[] = $row;
    $count++;
}

$title = (string)$survey['title'] !== '' ? (string)$survey['title'] : ('Survey ' . $surveyId);
$sheetTitle = $title . ' * ReliCheck export * ' . date('Y-m-d');

// 1. Create a fresh spreadsheet.
$createPayload = [
    'properties' => ['title' => $sheetTitle],
    'sheets'     => [
        ['properties' => ['title' => 'Responses', 'gridProperties' => ['frozenRowCount' => 1]]],
    ],
];
$created = google_api_post_json('https://sheets.googleapis.com/v4/spreadsheets', $createPayload, (int)$user['id']);

$spreadsheetId  = (string)($created['spreadsheetId']  ?? '');
$spreadsheetUrl = (string)($created['spreadsheetUrl'] ?? '');
if ($spreadsheetId === '') {
    fail('google_api_error', 'Sheets API did not return a spreadsheetId.', 502);
}

// 2. Write rows into the Responses tab. Use USER_ENTERED so dates and
//    numbers parse as the right types.
$values = ['values' => $rows];
$writeUrl = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId)
          . '/values/' . rawurlencode('Responses!A1') . '?valueInputOption=USER_ENTERED';
google_api_put_json($writeUrl, $values, (int)$user['id']);

json_out([
    'ok'              => true,
    'spreadsheet_id'  => $spreadsheetId,
    'spreadsheet_url' => $spreadsheetUrl,
    'rows_written'    => $count,
]);
