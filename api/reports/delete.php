<?php
// POST /api/reports/delete.php
// Body: { id }
// Deletes a report (cascades to its shares).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_input', 'Missing id.', 400);

$pdo = db();
$own = $pdo->prepare('SELECT user_id FROM reports WHERE id = :id LIMIT 1');
try { $own->execute([':id' => $id]); } catch (Throwable $e) {
    fail('migration_pending', 'Phase 148 migration has not been applied yet.', 503);
}
$row = $own->fetch();
if (!$row) fail('not_found', 'Report not found.', 404);
if ((int)$row['user_id'] !== (int)$user['id']) {
    fail('forbidden', 'Not your report.', 403);
}

$pdo->prepare('DELETE FROM reports WHERE id = :id')->execute([':id' => $id]);

json_out(['ok' => true]);
