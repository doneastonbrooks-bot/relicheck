<?php
// POST /api/folders/delete.php
// Body: { id: int }
// Surveys assigned to this folder become unfoldered (folder_id = NULL via FK).

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

$stmt = $pdo->prepare('SELECT id, owner_id FROM folders WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Folder not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You can only delete your own folders.', 403);

$pdo->prepare('DELETE FROM folders WHERE id = :id')->execute([':id' => $id]);

json_out(['ok' => true]);
