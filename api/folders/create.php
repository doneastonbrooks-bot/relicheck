<?php
// POST /api/folders/create.php
// Body: { name: string, color?: string }
// Creates a new per-user folder. Returns the inserted row.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body  = read_json_body();
$name  = trim((string)($body['name']  ?? ''));
$color = trim((string)($body['color'] ?? 'slate'));

if ($name === '' || mb_strlen($name) > 120) {
    fail('bad_name', 'Folder name is required (max 120 characters).', 400);
}

$allowedColors = ['slate', 'coral', 'navy', 'teal', 'amber', 'plum', 'forest'];
if (!in_array($color, $allowedColors, true)) {
    $color = 'slate';
}

$pdo = db();

// One folder name per user. Avoid duplicates that would confuse the rail.
$dupe = $pdo->prepare('SELECT id FROM folders WHERE owner_id = :uid AND name = :n LIMIT 1');
$dupe->execute([':uid' => (int)$user['id'], ':n' => $name]);
if ($dupe->fetch()) {
    fail('duplicate_name', 'You already have a folder with that name.', 409);
}

// Place new folder at the end of the rail.
$next = (int)$pdo->query(
    'SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM folders WHERE owner_id = ' . (int)$user['id']
)->fetch()['n'];

$stmt = $pdo->prepare(
    'INSERT INTO folders (owner_id, name, color, sort_order)
     VALUES (:uid, :n, :c, :s)'
);
$stmt->execute([
    ':uid' => (int)$user['id'],
    ':n'   => $name,
    ':c'   => $color,
    ':s'   => $next,
]);
$id = (int)$pdo->lastInsertId();

json_out([
    'folder' => [
        'id'           => $id,
        'name'         => $name,
        'color'        => $color,
        'sort_order'   => $next,
        'survey_count' => 0,
    ],
], 201);
