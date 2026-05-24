<?php
// GET /api/tests/get.php?id=...
// Returns the test plus all student responses for analytics.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) fail('bad_input', 'Missing test id.');

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id, user_id, title, description, num_items, answer_key, item_labels, skill_tags, pass_threshold,
            slug, is_published,
            created_at, updated_at, archived_at
       FROM tests
      WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$test = $stmt->fetch();
if (!$test) fail('not_found', 'Test not found.', 404);
if ((int)$test['user_id'] !== (int)$user['id']) fail('forbidden', 'You do not have access to this test.', 403);

$respStmt = $pdo->prepare(
    'SELECT id, student_id, responses, score, percent_correct, submitted_at
       FROM test_responses
      WHERE test_id = :id
      ORDER BY submitted_at ASC, id ASC'
);
$respStmt->execute([':id' => $id]);
$responses = [];
while ($r = $respStmt->fetch()) {
    $responses[] = [
        'id'              => (int)$r['id'],
        'student_id'      => $r['student_id'],
        'responses'       => json_decode((string)$r['responses'], true) ?: [],
        'score'           => (int)$r['score'],
        'percent_correct' => (float)$r['percent_correct'],
        'submitted_at'    => $r['submitted_at'],
    ];
}

json_out([
    'test' => [
        'id'             => (int)$test['id'],
        'title'          => $test['title'],
        'description'    => $test['description'],
        'num_items'      => (int)$test['num_items'],
        'answer_key'     => json_decode((string)$test['answer_key'], true) ?: [],
        'item_labels'    => json_decode((string)$test['item_labels'], true) ?: null,
        'skill_tags'     => json_decode((string)$test['skill_tags'], true) ?: null,
        'pass_threshold' => (float)$test['pass_threshold'],
        'slug'           => $test['slug'] !== null ? (string)$test['slug'] : null,
        'is_published'   => (int)$test['is_published'] === 1,
        'created_at'     => $test['created_at'],
        'updated_at'     => $test['updated_at'],
        'archived_at'    => $test['archived_at'],
    ],
    'responses' => $responses,
]);
