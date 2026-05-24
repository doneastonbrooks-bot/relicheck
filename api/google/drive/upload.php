<?php
// POST /api/google/drive/upload.php
// Body: { "survey_id": <int>, "format": "csv" | "json", "filename": "..."(optional) }
//
// Generates a report file from the given survey's responses and uploads it
// to the user's Google Drive in the root of My Drive. Returns the Drive URL.
//
// "csv"  : same long-form responses matrix that the Sheets export uses.
// "json" : machine-readable dump of survey + all responses.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_google.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$surveyId = (int)($body['survey_id'] ?? 0);
$format   = strtolower((string)($body['format'] ?? 'csv'));
$nameIn   = clean_string($body['filename'] ?? '', 200);

if ($surveyId <= 0) fail('bad_id', 'Missing or invalid survey_id.');
if (!in_array($format, ['csv', 'json'], true)) fail('bad_format', 'Format must be "csv" or "json".');

$pdo = db();

$stmt = $pdo->prepare('SELECT id, owner_id, title, slug, settings, questions FROM surveys WHERE id = :id');
$stmt->execute([':id' => $surveyId]);
$survey = $stmt->fetch();
if (!$survey)                                  fail('not_found', 'Survey not found.', 404);
if ((int)$survey['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

$questions = json_decode((string)$survey['questions'], true);
if (!is_array($questions)) $questions = [];

$rstmt = $pdo->prepare('SELECT id, submitted_at, answers FROM responses WHERE survey_id = :sid ORDER BY submitted_at ASC');
$rstmt->execute([':sid' => $surveyId]);
$responses = [];
while ($r = $rstmt->fetch()) {
    $a = json_decode((string)$r['answers'], true);
    $responses[] = [
        'id'           => (int)$r['id'],
        'submitted_at' => (string)$r['submitted_at'],
        'answers'      => is_array($a) ? $a : [],
    ];
}

$baseTitle = (string)$survey['title'] !== '' ? (string)$survey['title'] : ('survey-' . $surveyId);
$slugTitle = preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseTitle) ?: ('survey-' . $surveyId);
$dateTag   = date('Y-m-d');

if ($nameIn !== '') {
    $finalName = $nameIn;
} else {
    $finalName = $slugTitle . '_responses_' . $dateTag . '.' . $format;
}

if ($format === 'csv') {
    $bodyMime  = 'text/csv';
    $bodyBytes = build_responses_csv($questions, $responses);
} else {
    $bodyMime  = 'application/json';
    $bodyBytes = json_encode([
        'survey'    => [
            'id'        => (int)$survey['id'],
            'slug'      => $survey['slug'],
            'title'     => $survey['title'],
            'questions' => $questions,
        ],
        'responses' => $responses,
        'exported_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

$metadata = [
    'name'     => $finalName,
    'mimeType' => $bodyMime,
    // No 'parents': defaults to root of My Drive.
];

$created = google_drive_multipart_upload($metadata, $bodyBytes, $bodyMime, (int)$user['id']);

$fileId  = (string)($created['id']           ?? '');
$webView = (string)($created['webViewLink']  ?? '');
if ($fileId === '') {
    fail('google_api_error', 'Drive API did not return a file id.', 502);
}

json_out([
    'ok'           => true,
    'file_id'      => $fileId,
    'file_name'    => (string)($created['name'] ?? $finalName),
    'web_view_url' => $webView !== '' ? $webView : ('https://drive.google.com/file/d/' . rawurlencode($fileId) . '/view'),
    'rows_written' => count($responses),
]);

// -----------------------------------------------------------------------

function build_responses_csv(array $questions, array $responses): string
{
    $header = ['response_id', 'submitted_at'];
    $qIds   = [];
    foreach ($questions as $q) {
        $qid   = (string)($q['id']    ?? '');
        $title = (string)($q['title'] ?? $q['label'] ?? $qid);
        if ($qid === '') continue;
        $header[] = $title !== '' ? $title : $qid;
        $qIds[] = $qid;
    }

    $fp = fopen('php://temp', 'w+');
    if (!$fp) return '';
    fputcsv($fp, $header);
    foreach ($responses as $r) {
        $row = [$r['id'], $r['submitted_at']];
        foreach ($qIds as $qid) {
            $val = $r['answers'][$qid] ?? '';
            if (is_array($val)) $val = implode('; ', array_map('strval', $val));
            $row[] = is_scalar($val) ? (string)$val : '';
        }
        fputcsv($fp, $row);
    }
    rewind($fp);
    $out = stream_get_contents($fp) ?: '';
    fclose($fp);
    return $out;
}
