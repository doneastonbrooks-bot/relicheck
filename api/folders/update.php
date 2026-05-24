<?php
// POST /api/folders/update.php
// Body: { id: int, name?: string, color?: string, sort_order?: int }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing folder id.', 400);

$pdo = db();

$row = (function () use ($pdo, $id, $user) {
    $stmt = $pdo->prepare('SELECT id, owner_id FROM folders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch();
})();
if (!$row) fail('not_found', 'Folder not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You can only edit your own folders.', 403);

$sets   = [];
$params = [':id' => $id];

if (array_key_exists('name', $body)) {
    $name = trim((string)$body['name']);
    if ($name === '' || mb_strlen($name) > 120) {
        fail('bad_name', 'Folder name is required (max 120 characters).', 400);
    }
    $sets[]            = 'name = :n';
    $params[':n']      = $name;
}
if (array_key_exists('color', $body)) {
    $color   = trim((string)$body['color']);
    $allowed = ['slate', 'coral', 'navy', 'teal', 'amber', 'plum', 'forest'];
    if (!in_array($color, $allowed, true)) $color = 'slate';
    $sets[]            = 'color = :c';
    $params[':c']      = $color;
}
if (array_key_exists('sort_order', $body)) {
    $sets[]            = 'sort_order = :s';
    $params[':s']      = (int)$body['sort_order'];
}

if (!$sets) {
    json_out(['ok' => true, 'note' => 'nothing_to_update']);
}

$sql = 'UPDATE folders SET ' . implode(', ', $sets) . ' WHERE id = :id';
$pdo->prepare($sql)->execute($params);

json_out(['ok' => true]);
