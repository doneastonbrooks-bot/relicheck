<?php
// POST /api/tests/update.php
// Body: {
//   "id":             <int>,
//   "title":          <string>,
//   "description":    <string|null>,
//   "pass_threshold": <float>,
//   "answer_key":     [<string>, ...],
//   "item_labels":    [<string>, ...] | null,
//   "skill_tags":     [<string>, ...] | null
// }
//
// Phase 78b. Updates an existing test in place. To keep scoring consistent
// we refuse the update when the test already has student responses; the
// teacher must delete those responses (or duplicate the test, edit, and
// re-publish) before structural edits are allowed. Title and pass-threshold
// could be edited even with responses, but for simplicity we currently
// require zero responses for any edit and surface a clear message.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id < 1) fail('bad_input', 'Missing test id.');

$pdo = db();
$stmt = $pdo->prepare('SELECT id, user_id, slug, is_published FROM tests WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$test = $stmt->fetch();
if (!$test) fail('not_found', 'Test not found.', 404);
if ((int)$test['user_id'] !== (int)$user['id']) fail('forbidden', 'You do not have access to this test.', 403);

// Refuse if responses exist.
$cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM test_responses WHERE test_id = :id');
$cnt->execute([':id' => $id]);
$haveResponses = (int)$cnt->fetch()['c'];
if ($haveResponses > 0) {
    fail('responses_exist', 'Cannot edit a test that already has student responses. Delete the responses first, or duplicate the test.', 409);
}

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
    if (array_filter($skillTags, function ($v) { return $v !== null; }) === []) {
        $skillTags = null;
    }
}

try {
    $pdo->prepare(
        'UPDATE tests
            SET title = :title,
                description = :desc,
                num_items = :num,
                answer_key = :key,
                item_labels = :labels,
                skill_tags = :skills,
                pass_threshold = :pass,
                updated_at = CURRENT_TIMESTAMP
          WHERE id = :id'
    )->execute([
        ':title'  => $title,
        ':desc'   => $description,
        ':num'    => $numItems,
        ':key'    => json_encode($cleanKey, JSON_UNESCAPED_UNICODE),
        ':labels' => $itemLabels !== null ? json_encode($itemLabels, JSON_UNESCAPED_UNICODE) : null,
        ':skills' => $skillTags  !== null ? json_encode($skillTags,  JSON_UNESCAPED_UNICODE) : null,
        ':pass'   => $passThreshold,
        ':id'     => $id,
    ]);
} catch (\Throwable $e) {
    fail('db_error', 'Could not save test: ' . $e->getMessage(), 500);
}

json_out([
    'ok'        => true,
    'test_id'   => $id,
    'num_items' => $numItems,
]);
