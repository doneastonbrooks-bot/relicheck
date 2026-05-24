<?php
// POST /api/tests/from_template.php
// Body: { template_key }
//
// Phase 134d. Creates a new test entity from one of the templates listed
// in test_templates(). The test ships with zero responses (the analytics
// dashboard's empty state will prompt for an upload or paste). Returns the
// new test id so the client can attach it to a suite or open it in the
// test view.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/templates.php';

require_method('POST');
check_origin();
$user = require_auth();
$userId = (int)$user['id'];

$body = read_json_body();
$key = trim((string)($body['template_key'] ?? ''));
if ($key === '') fail('bad_input', 'template_key is required.', 400);

$found = null;
foreach (test_templates() as $tpl) {
    if ((string)$tpl['key'] === $key) { $found = $tpl; break; }
}
if (!$found) fail('not_found', 'Unknown test template key.', 404);

$test = $found['test'] ?? [];
$title         = clean_string((string)($test['title'] ?? $found['name']), 255);
$description   = clean_string((string)($test['description'] ?? ''), 2000);
if ($description === '') $description = null;
$passThreshold = is_numeric($test['pass_threshold'] ?? null)
    ? max(0.0, min(100.0, (float)$test['pass_threshold']))
    : 70.0;

$answerKey = is_array($test['answer_key'] ?? null) ? $test['answer_key'] : [];
if (count($answerKey) < 3) fail('bad_template', 'Template answer_key is too short.', 500);
$cleanKey = [];
foreach ($answerKey as $k) {
    $s = trim((string)$k);
    if ($s === '') $s = 'A';
    if (strlen($s) > 32) $s = substr($s, 0, 32);
    $cleanKey[] = $s;
}
$numItems = count($cleanKey);

$itemLabels = null;
if (is_array($test['item_labels'] ?? null) && count($test['item_labels']) === $numItems) {
    $itemLabels = [];
    foreach ($test['item_labels'] as $lbl) {
        $s = clean_string((string)$lbl, 80);
        $itemLabels[] = $s;
    }
}

$pdo = db();
$ins = $pdo->prepare(
    'INSERT INTO tests (user_id, title, description, num_items, answer_key, item_labels, pass_threshold)
     VALUES (:uid, :title, :desc, :n, :key, :labels, :pass)'
);
$ins->execute([
    ':uid'    => $userId,
    ':title'  => $title,
    ':desc'   => $description,
    ':n'      => $numItems,
    ':key'    => json_encode($cleanKey, JSON_UNESCAPED_UNICODE),
    ':labels' => $itemLabels !== null ? json_encode($itemLabels, JSON_UNESCAPED_UNICODE) : null,
    ':pass'   => $passThreshold,
]);

json_out([
    'ok'      => true,
    'test_id' => (int)$pdo->lastInsertId(),
    'title'   => $title,
]);
