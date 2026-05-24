<?php
// POST /api/surveys/create.php
// Body: { title?: string }   (defaults to "Untitled survey")
// Creates an empty survey owned by the current user and returns its full record.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';

require_method('POST');
check_origin();
$user = require_auth();

$body  = read_json_body();
$title = clean_string($body['title'] ?? '', 255);
if ($title === '') $title = 'Untitled survey';

$pdo  = db();

// Enforce per-tier survey count limit.
$current = (int)$pdo->query('SELECT COUNT(*) AS c FROM surveys WHERE owner_id = ' . (int)$user['id'])->fetch()['c'];
require_under_limit((int)$user['id'], 'max_surveys', $current);
$slug = unique_survey_slug($pdo);

$settings  = default_survey_settings();
$questions = []; // empty to start

$stmt = $pdo->prepare(
    'INSERT INTO surveys (owner_id, slug, title, description, settings, questions, is_published)
     VALUES (:uid, :slug, :title, :desc, :settings, :questions, 0)'
);
$stmt->execute([
    ':uid'       => $user['id'],
    ':slug'      => $slug,
    ':title'     => $title,
    ':desc'      => '',
    ':settings'  => json_encode($settings, JSON_UNESCAPED_UNICODE),
    ':questions' => json_encode($questions),
]);
$id = (int)$pdo->lastInsertId();

// Fire "First Survey Created" if this is the user's first survey. The
// $current count was computed BEFORE this insert, so 0 means it's the first.
if ($current === 0) {
    try {
        if (is_file(__DIR__ . '/../_email_dispatcher.php')) {
            require_once __DIR__ . '/../_email_dispatcher.php';
            relicheck_email_dispatch('survey.first_created', [
                'user_id'    => (int)$user['id'],
                'account_id' => (int)$user['id'],
                'idempotency_entity_id' => 'first-survey:' . (int)$user['id'],
                'payload'    => [
                    'first_name'  => trim(explode(' ', (string)($user['name'] ?? ''))[0] ?: 'there'),
                    'survey_name' => $title,
                    'survey_id'   => (string)$id,
                ],
            ]);
        }
    } catch (Throwable $e) {
        error_log('[relicheck] survey.first_created dispatch failed: ' . $e->getMessage());
    }
}

json_out([
    'survey' => [
        'id'           => $id,
        'slug'         => $slug,
        'title'        => $title,
        'description'  => '',
        'is_published' => false,
        'settings'     => $settings,
        'questions'    => $questions,
        'item_count'   => 0,
        'likert_count' => 0,
        'response_count' => 0,
    ],
], 201);
