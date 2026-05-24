<?php
// POST /api/schedules/delete.php
// Body: { id }
//
// Permanently removes the schedule. Past invitations created by this
// schedule keep their schedule_id reference (the column stays nullable),
// so historical wave tagging on responses is preserved.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing schedule id.', 400);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT s.id, sv.owner_id
       FROM survey_schedules s
       JOIN surveys sv ON sv.id = s.survey_id
      WHERE s.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row || (int)$row['owner_id'] !== (int)$user['id']) {
    fail('not_found', 'Schedule not found.', 404);
}

$del = $pdo->prepare('DELETE FROM survey_schedules WHERE id = :id');
$del->execute([':id' => $id]);

json_out(['ok' => true]);
