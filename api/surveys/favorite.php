<?php
// POST /api/surveys/favorite.php
// Body: { id: int, is_favorite: bool }
// Toggles the star on a single survey owned by the caller.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing survey id.', 400);

$fav = !!($body['is_favorite'] ?? false);

$pdo = db();

$stmt = $pdo->prepare('SELECT id, owner_id FROM surveys WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) {
    fail('forbidden', 'You can only favorite your own surveys.', 403);
}

try {
    $pdo->prepare('UPDATE surveys SET is_favorite = :f WHERE id = :id')->execute([
        ':f'  => $fav ? 1 : 0,
        ':id' => $id,
    ]);
} catch (Throwable $e) {
    // Pre-migration column missing. Surface a clear error for the client.
    fail('migration_pending', 'Phase 37 migration has not been applied yet.', 503);
}

json_out(['ok' => true, 'id' => $id, 'is_favorite' => $fav]);
