<?php
// POST /api/surveys/archive.php
// Body: { id: int, archived: bool }
// Sets archived_at to NOW() (archive) or NULL (unarchive). Only the survey
// owner can archive. archived rows still exist in the database; they are
// excluded from the default surveys list and from Home.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body     = read_json_body();
$id       = (int)($body['id'] ?? 0);
$archived = !!($body['archived'] ?? true);
if ($id <= 0) fail('bad_id', 'Missing survey id.', 400);

$pdo = db();

$stmt = $pdo->prepare('SELECT id, owner_id FROM surveys WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) {
    fail('forbidden', 'You can only archive your own surveys.', 403);
}

try {
    if ($archived) {
        // PHP/MySQL timezone mismatch lesson: let MySQL stamp NOW() inline.
        $pdo->prepare('UPDATE surveys SET archived_at = NOW() WHERE id = :id')
            ->execute([':id' => $id]);
    } else {
        $pdo->prepare('UPDATE surveys SET archived_at = NULL WHERE id = :id')
            ->execute([':id' => $id]);
    }
} catch (Throwable $e) {
    fail('migration_pending', 'Phase 37 migration has not been applied yet.', 503);
}

json_out(['ok' => true, 'id' => $id, 'archived' => $archived]);
