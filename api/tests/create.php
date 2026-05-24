<?php
// POST /api/tests/create.php
// Body: {
//   "title":          <string>,
//   "description":    <string|null>,
//   "pass_threshold": <float|null>,   // percent correct (default 70.0)
//   "answer_key":     [<string>, ...], // one entry per item
//   "item_labels":    [<string>, ...] | null, // optional, must match length
//   "responses":      [
//     { "student_id": <string>, "answers": [<string>, ...] },
//     ...
//   ]
// }
//
// Phase 71. Validates that responses parallel the answer key, computes
// each student's score and percent correct, and writes the test plus
// responses in a single transaction.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

// Enforce per-tier test count limit.
require_once __DIR__ . '/../_tiers.php';
$pdo = db();
$cs = $pdo->prepare('SELECT COUNT(*) AS c FROM tests WHERE user_id = :u');
$cs->execute([':u' => (int)$user['id']]);
$cur = (int)($cs->fetch()['c'] ?? 0);
require_under_limit((int)$user['id'], 'max_tests', $cur, 1);

$body = read_json_body();

$title = clean_string((string)($body['title'] ?? ''), 255);
if ($title === '') fail('bad_input', 'Test title is required.');

$description = clean_string((string)($body['description'] ?? ''), 2000);
if ($description === '') $description = null;

$passThreshold = is_numeric($body['pass_threshold'] ?? null)
    ? max(0.0, min(100.0, (float)$body['pass_threshold']))
    : 70.0;

$answerKey = $body['answer_key'] ?? null;
if (!is_array($answerKey) || count($answerKey) < 3) {
    fail('bad_input', 'Answer key must be an array with at least 3 items.');
}
$cleanKey = [];
foreach ($answerKey as $k) {
    if (!is_scalar($k)) fail('bad_input', 'Answer key entries must be scalar.');
    $s = trim((string)$k);
    if ($s === '') fail('bad_input', 'Answer key entries cannot be blank.');
    if (strlen($s) > 32) $s = substr($s, 0, 32);
    $cleanKey[] = $s;
}
$numItems = count($cleanKey);

$itemLabels = null;
if (isset($body['item_labels']) && is_array($body['item_labels'])) {
    if (count($body['item_labels']) !== $numItems) {
        fail('bad_input', 'item_labels length must equal answer_key length.');
    }
    $itemLabels = [];
    foreach ($body['item_labels'] as $lbl) {
        $s = clean_string((string)$lbl, 200);
        $itemLabels[] = ($s === '') ? null : $s;
    }
}

// Phase 73: optional per-item skill / standard tags.
$skillTags = null;
if (isset($body['skill_tags']) && is_array($body['skill_tags'])) {
    if (count($body['skill_tags']) !== $numItems) {
        fail('bad_input', 'skill_tags length must equal answer_key length.');
    }
    $skillTags = [];
    foreach ($body['skill_tags'] as $tag) {
        $s = clean_string((string)$tag, 80);
        $skillTags[] = ($s === '') ? null : $s;
    }
    // Collapse to null if every entry is null.
    if (array_filter($skillTags, function ($v) { return $v !== null; }) === []) {
        $skillTags = null;
    }
}

// Phase 74: responses are now optional. A built test can be saved empty and
// have responses added later via /api/tests/add-responses.php. We still
// require the field to be an array if present.
$responsesIn = $body['responses'] ?? [];
if (!is_array($responsesIn)) {
    fail('bad_input', 'responses must be an array.');
}

$pdo = db();
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO tests (user_id, title, description, num_items, answer_key, item_labels, skill_tags, pass_threshold)
         VALUES (:uid, :title, :desc, :num, :key, :labels, :skills, :pass)'
    );
    $stmt->execute([
        ':uid'    => $user['id'],
        ':title'  => $title,
        ':desc'   => $description,
        ':num'    => $numItems,
        ':key'    => json_encode($cleanKey, JSON_UNESCAPED_UNICODE),
        ':labels' => $itemLabels !== null ? json_encode($itemLabels, JSON_UNESCAPED_UNICODE) : null,
        ':skills' => $skillTags  !== null ? json_encode($skillTags,  JSON_UNESCAPED_UNICODE) : null,
        ':pass'   => $passThreshold,
    ]);
    $testId = (int)$pdo->lastInsertId();

    $insResp = $pdo->prepare(
        'INSERT INTO test_responses (test_id, student_id, responses, score, percent_correct)
         VALUES (:tid, :sid, :resp, :score, :pct)'
    );

    $written = 0;
    foreach ($responsesIn as $row) {
        if (!is_array($row)) continue;
        $studentId = clean_string((string)($row['student_id'] ?? ''), 120);
        if ($studentId === '') $studentId = 'Student_' . ($written + 1);
        $answers = $row['answers'] ?? null;
        if (!is_array($answers) || count($answers) !== $numItems) {
            continue; // skip malformed rows rather than fail the whole upload
        }
        $cleanAnswers = [];
        $score = 0;
        for ($i = 0; $i < $numItems; $i++) {
            $a = $answers[$i];
            $s = is_scalar($a) ? trim((string)$a) : '';
            if (strlen($s) > 32) $s = substr($s, 0, 32);
            $cleanAnswers[] = $s;
            // Compare case-insensitively. Empty answers count as incorrect.
            if ($s !== '' && strcasecmp($s, $cleanKey[$i]) === 0) $score++;
        }
        $pct = $numItems > 0 ? round(($score * 100.0) / $numItems, 2) : 0.0;
        $insResp->execute([
            ':tid'   => $testId,
            ':sid'   => $studentId,
            ':resp'  => json_encode($cleanAnswers, JSON_UNESCAPED_UNICODE),
            ':score' => $score,
            ':pct'   => $pct,
        ]);
        $written++;
    }

    // Phase 74: zero responses is allowed (built test, no responses yet).
    // Reject only if responses were supplied but all of them were malformed.
    if (count($responsesIn) > 0 && $written < 1) {
        $pdo->rollBack();
        fail('bad_input', 'No valid student rows found. Each row must have the same number of answers as the answer key.');
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not save test: ' . $e->getMessage(), 500);
}

json_out([
    'ok'             => true,
    'test_id'        => $testId,
    'responses_saved'=> $written,
    'num_items'      => $numItems,
]);
