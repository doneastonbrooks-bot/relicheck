<?php
// POST /api/tests/add-responses.php
// Body: {
//   "test_id":   <int>,
//   "responses": [
//     { "student_id": <string>, "answers": [<string>, ...] },
//     ...
//   ]
// }
//
// Phase 74. Adds student responses to an existing test. Used after a teacher
// builds a test inside ReliCheck (which creates the test empty) or to append
// late responses to an uploaded test. Validates ownership and that every
// response row matches the test's existing answer-key length. Computes per
// student score and percent correct, then inserts. Skips malformed rows.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$testId = (int)($body['test_id'] ?? 0);
if ($testId < 1) fail('bad_input', 'Missing test id.');

$responsesIn = $body['responses'] ?? null;
if (!is_array($responsesIn) || count($responsesIn) < 1) {
    fail('bad_input', 'At least one student response is required.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, user_id, num_items, answer_key FROM tests WHERE id = :id');
$stmt->execute([':id' => $testId]);
$test = $stmt->fetch();
if (!$test) fail('not_found', 'Test not found.', 404);
if ((int)$test['user_id'] !== (int)$user['id']) fail('forbidden', 'You do not have access to this test.', 403);

$cleanKey = json_decode((string)$test['answer_key'], true);
if (!is_array($cleanKey) || count($cleanKey) < 1) fail('server_error', 'Test answer key is corrupted.', 500);
$numItems = (int)$test['num_items'];
if (count($cleanKey) !== $numItems) fail('server_error', 'Test answer key length mismatch.', 500);

try {
    $pdo->beginTransaction();
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
            continue;
        }
        $cleanAnswers = [];
        $score = 0;
        for ($i = 0; $i < $numItems; $i++) {
            $a = $answers[$i];
            $s = is_scalar($a) ? trim((string)$a) : '';
            if (strlen($s) > 32) $s = substr($s, 0, 32);
            $cleanAnswers[] = $s;
            if ($s !== '' && strcasecmp($s, (string)$cleanKey[$i]) === 0) $score++;
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

    if ($written < 1) {
        $pdo->rollBack();
        fail('bad_input', 'No valid student rows found. Each row must have the same number of answers as the test\'s answer key (' . $numItems . ' items).');
    }
    // Bump the test\'s updated_at so it surfaces at the top of My Tests.
    $pdo->prepare('UPDATE tests SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([':id' => $testId]);
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not save responses: ' . $e->getMessage(), 500);
}

json_out([
    'ok'              => true,
    'test_id'         => $testId,
    'responses_saved' => $written,
]);
