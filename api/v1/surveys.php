<?php
// GET /api/v1/surveys                - list all surveys owned by the token's user
// GET /api/v1/surveys?id=<id>        - get one survey (questions + settings)
//
// Auth: Bearer token. Tier-gated to plans whose features include api_access.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_api_auth.php';

require_method('GET');
$user = require_api_token();
$pdo = db();

$idParam = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($idParam > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, slug, title, description, settings, questions, is_published,
                created_at, updated_at
           FROM surveys WHERE id = :id AND owner_id = :u LIMIT 1'
    );
    $stmt->execute([':id' => $idParam, ':u' => $user['id']]);
    $row = $stmt->fetch();
    if (!$row) fail('not_found', 'No survey with that id is owned by this token.', 404);
    json_out([
        'survey' => [
            'id'           => (int)$row['id'],
            'slug'         => $row['slug'],
            'title'        => $row['title'],
            'description'  => $row['description'],
            'settings'     => json_decode((string)$row['settings'], true) ?: [],
            'questions'    => json_decode((string)$row['questions'], true) ?: [],
            'is_published' => (bool)$row['is_published'],
            'created_at'   => $row['created_at'],
            'updated_at'   => $row['updated_at'],
        ],
    ]);
}

// List
$stmt = $pdo->prepare(
    'SELECT id, slug, title, description, is_published, created_at, updated_at
       FROM surveys WHERE owner_id = :u ORDER BY updated_at DESC'
);
$stmt->execute([':u' => $user['id']]);
$out = [];
foreach ($stmt->fetchAll() as $r) {
    $out[] = [
        'id'           => (int)$r['id'],
        'slug'         => $r['slug'],
        'title'        => $r['title'],
        'description'  => $r['description'],
        'is_published' => (bool)$r['is_published'],
        'created_at'   => $r['created_at'],
        'updated_at'   => $r['updated_at'],
    ];
}
json_out(['count' => count($out), 'surveys' => $out]);
