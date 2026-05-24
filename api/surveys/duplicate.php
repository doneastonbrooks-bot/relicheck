<?php
// POST /api/surveys/duplicate.php
// Body: { source: 'survey'|'template', id?, template_key? }
//
// 'survey'   - clones one of the user's existing surveys (id required).
// 'template' - instantiates a starter template (template_key required).
// Returns the new survey row, ready to open in the Builder.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/templates.php';   // defines survey_templates()

require_method('POST');
check_origin();
$user = require_auth();

$body   = read_json_body();
$source = (string)($body['source'] ?? 'survey');

if (!in_array($source, ['survey','template'], true)) {
    fail('bad_source', 'source must be "survey" or "template".', 400);
}

$pdo = db();

// Tier check: survey count limit
$current = (int)$pdo->query('SELECT COUNT(*) AS c FROM surveys WHERE owner_id = ' . (int)$user['id'])->fetch()['c'];
require_under_limit((int)$user['id'], 'max_surveys', $current);

// Pull the seed (title, description, settings, questions) from either an
// existing survey or a built-in template.
if ($source === 'survey') {
    $sourceId = (int)($body['id'] ?? 0);
    if ($sourceId <= 0) fail('bad_id', 'Missing source survey id.', 400);
    $stmt = $pdo->prepare('SELECT owner_id, title, description, settings, questions FROM surveys WHERE id = :id');
    $stmt->execute([':id' => $sourceId]);
    $row = $stmt->fetch();
    if (!$row) fail('not_found', 'Source survey not found.', 404);
    if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You can only duplicate your own surveys.', 403);
    $title       = trim('Copy of ' . $row['title']);
    $description = (string)$row['description'];
    $settings    = json_decode((string)$row['settings'],  true) ?: default_survey_settings();
    $questions   = json_decode((string)$row['questions'], true) ?: [];
} else {
    $tplKey = (string)($body['template_key'] ?? '');
    $tpl = null;
    foreach (survey_templates() as $t) {
        if ($t['key'] === $tplKey) { $tpl = $t['survey']; break; }
    }
    if (!$tpl) fail('bad_template', 'Unknown template key.', 400);
    $title       = (string)$tpl['title'];
    $description = (string)($tpl['description'] ?? '');
    $settings    = is_array($tpl['settings'] ?? null) ? $tpl['settings'] : default_survey_settings();
    $questions   = is_array($tpl['questions'] ?? null) ? $tpl['questions'] : [];
}

// Ensure each question has an id (templates ship without ids).
foreach ($questions as &$q) {
    if (!isset($q['id']) || !is_string($q['id']) || $q['id'] === '') {
        $q['id'] = bin2hex(random_bytes(4));
    }
}
unset($q);

// Tier check: question count
require_under_limit((int)$user['id'], 'max_questions_per_survey', 0, count($questions));

$slug = unique_survey_slug($pdo);

$stmt = $pdo->prepare(
    'INSERT INTO surveys (owner_id, slug, title, description, settings, questions, is_published)
     VALUES (:uid, :slug, :title, :desc, :settings, :questions, 0)'
);
$stmt->execute([
    ':uid'       => $user['id'],
    ':slug'      => $slug,
    ':title'     => clean_string($title, 255) ?: 'Untitled survey',
    ':desc'      => clean_string($description, 4000),
    ':settings'  => json_encode($settings,  JSON_UNESCAPED_UNICODE),
    ':questions' => json_encode($questions, JSON_UNESCAPED_UNICODE),
]);
$id = (int)$pdo->lastInsertId();

json_out([
    'survey' => [
        'id'             => $id,
        'slug'           => $slug,
        'title'          => $title,
        'description'    => $description,
        'is_published'   => false,
        'settings'       => $settings,
        'questions'      => $questions,
        'item_count'     => count($questions),
        'likert_count'   => count(array_filter($questions, fn($q) => ($q['type'] ?? '') === 'likert')),
        'response_count' => 0,
    ],
], 201);
