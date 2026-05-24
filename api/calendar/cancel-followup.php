<?php
// POST /api/calendar/cancel-followup.php
// Body: { id: int }
// Cancels a pending calendar_followups row so the hourly cron will skip it.
// Only the user who owns the survey can cancel.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_invitations.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing id.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, survey_id, status FROM calendar_followups WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Follow-up not found.', 404);

invitations_require_survey_owned_by((int)$row['survey_id'], (int)$user['id']);

if ($row['status'] !== 'pending') {
    json_out(['ok' => true, 'status' => (string)$row['status'], 'note' => 'not_pending']);
}

$pdo->prepare('UPDATE calendar_followups SET status = "cancelled" WHERE id = :id')
    ->execute([':id' => $id]);

json_out(['ok' => true, 'status' => 'cancelled']);
