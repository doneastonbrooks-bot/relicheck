<?php
// POST /api/surveys/move_folder.php
// Body: { id: int, folder_id: int|null }
// Moves a single survey into a folder, or out of any folder when folder_id is null.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body  = read_json_body();
$id    = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing survey id.', 400);

$folderId = null;
if (array_key_exists('folder_id', $body) && $body['folder_id'] !== null && $body['folder_id'] !== '') {
    $folderId = (int)$body['folder_id'];
    if ($folderId <= 0) {
        fail('bad_folder', 'Folder id must be a positive integer or null.', 400);
    }
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id, owner_id FROM surveys WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) {
    fail('forbidden', 'You can only move your own surveys.', 403);
}

if ($folderId !== null) {
    $f = $pdo->prepare('SELECT id, owner_id FROM folders WHERE id = :id');
    $f->execute([':id' => $folderId]);
    $fr = $f->fetch();
    if (!$fr) fail('folder_not_found', 'Folder not found.', 404);
    if ((int)$fr['owner_id'] !== (int)$user['id']) {
        fail('forbidden', 'You can only move surveys into your own folders.', 403);
    }
}

try {
    $pdo->prepare('UPDATE surveys SET folder_id = :f WHERE id = :id')->execute([
        ':f'  => $folderId,
        ':id' => $id,
    ]);
} catch (Throwable $e) {
    fail('migration_pending', 'Phase 37 migration has not been applied yet.', 503);
}

json_out(['ok' => true, 'id' => $id, 'folder_id' => $folderId]);
