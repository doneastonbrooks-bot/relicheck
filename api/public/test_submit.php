<?php
// POST /api/public/test_submit.php
// Body: { slug, student_id, answers: [string, ...] }
// Public endpoint. Validates a student submission, scores it against the
// saved answer key, and inserts into test_responses. Does NOT echo back
// the score or the correct answers; the take page only shows a "thanks"
// confirmation to keep the test reusable.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
// Deliberately not check_origin(); students may arrive via email links.

$body = read_json_body();
$slug = is_string($body['slug'] ?? null) ? $body['slug'] : '';
if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $slug)) {
    fail('bad_slug', 'Invalid slug.', 400);
}

// Rate limit: 60 submissions per IP per test slug per hour.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
check_rate_limit('test_submit:ip:' . $ip . ':slug:' . $slug, 60, 3600);

$studentId = clean_string((string)($body['student_id'] ?? ''), 120);
if ($studentId === '') $studentId = 'Anonymous';

$answers = $body['answers'] ?? null;
if (!is_array($answers)) fail('bad_input', 'Missing answers.');

$pdo = db();
$stmt = $pdo->prepare('SELECT id, num_items, answer_key, is_published FROM tests WHERE slug = :slug LIMIT 1');
$stmt->execute([':slug' => $slug]);
$test = $stmt->fetch();
if (!$test) fail('not_found', 'No test was found at that link.', 404);
if (!(int)$test['is_published']) fail('not_published', 'This test is not open for responses.', 410);

$numItems = (int)$test['num_items'];
$key = json_decode((string)$test['answer_key'], true);
if (!is_array($key) || count($key) !== $numItems) fail('server_error', 'Test data is corrupted.', 500);

// Normalize and score.
$cleanAnswers = [];
$score = 0;
for ($i = 0; $i < $numItems; $i++) {
    $a = $answers[$i] ?? null;
    $s = is_scalar($a) ? trim((string)$a) : '';
    if (strlen($s) > 32) $s = substr($s, 0, 32);
    $cleanAnswers[] = $s;
    if ($s !== '' && strcasecmp($s, (string)$key[$i]) === 0) $score++;
}
$pct = $numItems > 0 ? round(($score * 100.0) / $numItems, 2) : 0.0;

$ins = $pdo->prepare(
    'INSERT INTO test_responses (test_id, student_id, responses, score, percent_correct)
     VALUES (:tid, :sid, :resp, :score, :pct)'
);
$ins->execute([
    ':tid'   => $test['id'],
    ':sid'   => $studentId,
    ':resp'  => json_encode($cleanAnswers, JSON_UNESCAPED_UNICODE),
    ':score' => $score,
    ':pct'   => $pct,
]);
$pdo->prepare('UPDATE tests SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([':id' => $test['id']]);

json_out(['ok' => true]);
