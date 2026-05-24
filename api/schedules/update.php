<?php
// POST /api/schedules/update.php
// Body: { id, action?: "pause"|"resume"|"complete", name?, wave_template?, end_at? }
//
// Pause, resume, or auto-complete a schedule. Optionally update label and
// end date.

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
    'SELECT s.*, sv.owner_id
       FROM survey_schedules s
       JOIN surveys sv ON sv.id = s.survey_id
      WHERE s.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row || (int)$row['owner_id'] !== (int)$user['id']) {
    fail('not_found', 'Schedule not found.', 404);
}

$action = isset($body['action']) ? (string)$body['action'] : '';
$updates = [];
$params  = [':id' => $id];

if ($action === 'pause' && $row['status'] === 'active') {
    $updates[] = 'status = "paused"';
} elseif ($action === 'resume' && $row['status'] === 'paused') {
    $updates[] = 'status = "active"';
    // If next_fire_at is in the past, push it to now so resume actually fires.
    $updates[] = 'next_fire_at = CASE WHEN next_fire_at < NOW() THEN NOW() ELSE next_fire_at END';
} elseif ($action === 'complete') {
    $updates[] = 'status = "completed"';
}

if (isset($body['name'])) {
    $name = trim((string)$body['name']);
    if ($name === '') $name = 'Pulse schedule';
    if (strlen($name) > 120) $name = substr($name, 0, 120);
    $updates[] = 'name = :name';
    $params[':name'] = $name;
}
if (isset($body['wave_template'])) {
    $wt = trim((string)$body['wave_template']);
    if ($wt === '') $wt = 'Pulse {n}';
    if (strlen($wt) > 120) $wt = substr($wt, 0, 120);
    $updates[] = 'wave_template = :wt';
    $params[':wt'] = $wt;
}
if (array_key_exists('end_at', $body)) {
    $endAt = trim((string)($body['end_at'] ?? ''));
    if ($endAt === '') $endAt = null;
    $updates[] = 'end_at = :end';
    $params[':end'] = $endAt;
}

if (empty($updates)) {
    json_out(['ok' => true, 'noop' => true]);
}

$sql = 'UPDATE survey_schedules SET ' . implode(', ', $updates) . ' WHERE id = :id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$out = $pdo->prepare('SELECT * FROM survey_schedules WHERE id = :id');
$out->execute([':id' => $id]);

json_out([
    'ok'       => true,
    'schedule' => $out->fetch(),
]);
