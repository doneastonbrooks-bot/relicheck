<?php
// GET /api/surveys/get.php?id=<survey_id>
// Returns the full survey, including settings and questions, only if the
// caller owns it.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing or invalid survey id.');

$stmt = db()->prepare(
    'SELECT id, owner_id, slug, title, description, settings, questions,
            is_published, created_at, updated_at
       FROM surveys WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

$cstmt = db()->prepare('SELECT COUNT(*) AS c FROM responses WHERE survey_id = :sid');
$cstmt->execute([':sid' => $id]);
$count = (int)($cstmt->fetch()['c'] ?? 0);

json_out([
    'survey' => [
        'id'             => (int)$row['id'],
        'slug'           => $row['slug'],
        'title'          => $row['title'],
        'description'    => $row['description'],
        'is_published'   => (bool)$row['is_published'],
        'settings'       => json_decode((string)$row['settings'], true) ?: [],
        'questions'      => json_decode((string)$row['questions'], true) ?: [],
        'response_count' => $count,
        'created_at'     => $row['created_at'],
        'updated_at'     => $row['updated_at'],
    ],
]);
