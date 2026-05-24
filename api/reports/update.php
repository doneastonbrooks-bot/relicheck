<?php
// PATCH /api/reports/update.php
// Body: { id, title?, template?, status? }
// Edits report-level fields. To replace the snapshot, use regenerate.php.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';

require_method('PATCH', 'POST');
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

$fields = [];
$params = [':id' => $id];
if (array_key_exists('title', $body)) {
    $t = clean_string((string)$body['title'], 240);
    if ($t === '') fail('bad_input', 'Title cannot be empty.', 400);
    $fields[] = 'title = :title';
    $params[':title'] = $t;
}
if (array_key_exists('template', $body)) {
    $tpl = clean_string((string)$body['template'], 40);
    if (!in_array($tpl, ['executive', 'methods', 'findings'], true)) {
        fail('bad_input', 'Invalid template.', 400);
    }
    $fields[] = 'template = :tpl';
    $params[':tpl'] = $tpl;
}
if (array_key_exists('status', $body)) {
    $st = clean_string((string)$body['status'], 20);
    if (!in_array($st, ['draft', 'shared', 'scheduled', 'final'], true)) {
        fail('bad_input', 'Invalid status.', 400);
    }
    $fields[] = 'status = :st';
    $params[':st'] = $st;
}
if (count($fields) === 0) {
    json_out(['ok' => true, 'noop' => true]);
}

$sql = 'UPDATE reports SET ' . implode(', ', $fields) . ' WHERE id = :id';
$pdo->prepare($sql)->execute($params);

json_out(['ok' => true]);
