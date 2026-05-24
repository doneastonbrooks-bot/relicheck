<?php
// POST /api/schedules/create.php
// Body: { survey_id, name, cadence, interval_days?, wave_template?, start_at?, end_at? }
//
// Creates a pulse-cadence schedule that fires the survey to its active
// contact list on a recurring cadence. The cron worker
// /api/cron-fire-pulse.php drains due schedules.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$sid  = (int)($body['survey_id'] ?? 0);
if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, owner_id FROM surveys WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $sid]);
$survey = $stmt->fetch();
if (!$survey || (int)$survey['owner_id'] !== (int)$user['id']) {
    fail('not_found', 'Survey not found.', 404);
}

$name = trim((string)($body['name'] ?? ''));
if ($name === '') $name = 'Pulse schedule';
if (strlen($name) > 120) $name = substr($name, 0, 120);

$cadence = (string)($body['cadence'] ?? 'monthly');
$allowedCadences = ['weekly', 'biweekly', 'monthly', 'quarterly', 'custom'];
if (!in_array($cadence, $allowedCadences, true)) $cadence = 'monthly';

$intervalDays = null;
if ($cadence === 'custom') {
    $intervalDays = (int)($body['interval_days'] ?? 0);
    if ($intervalDays < 1) $intervalDays = 30;
    if ($intervalDays > 365) $intervalDays = 365;
}

$waveTemplate = trim((string)($body['wave_template'] ?? 'Pulse {n}'));
if ($waveTemplate === '') $waveTemplate = 'Pulse {n}';
if (strlen($waveTemplate) > 120) $waveTemplate = substr($waveTemplate, 0, 120);

// start_at: defaults to now if omitted or in the past. Stored as the SQL NOW().
$startAt = trim((string)($body['start_at'] ?? ''));
$useNow = ($startAt === '');

$endAt = trim((string)($body['end_at'] ?? ''));
if ($endAt === '') $endAt = null;

// Build the INSERT. Use SQL NOW() for start_at when omitted so PHP/MySQL
// timezone mismatches do not bite (feedback_php_mysql_timezone_mismatch).
if ($useNow) {
    $startExpr = 'NOW()';
    $nextExpr  = 'NOW()';
    $params = [
        ':sid'    => $sid,
        ':uid'    => (int)$user['id'],
        ':name'   => $name,
        ':cad'    => $cadence,
        ':iv'     => $intervalDays,
        ':wt'     => $waveTemplate,
        ':end'    => $endAt,
    ];
} else {
    $startExpr = ':start';
    $nextExpr  = ':start';
    $params = [
        ':sid'    => $sid,
        ':uid'    => (int)$user['id'],
        ':name'   => $name,
        ':cad'    => $cadence,
        ':iv'     => $intervalDays,
        ':wt'     => $waveTemplate,
        ':start'  => $startAt,
        ':end'    => $endAt,
    ];
}

$sql = 'INSERT INTO survey_schedules
        (survey_id, user_id, name, cadence, interval_days, wave_template,
         start_at, next_fire_at, end_at, status)
        VALUES (:sid, :uid, :name, :cad, :iv, :wt, '
        . $startExpr . ', ' . $nextExpr . ', :end, "active")';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$id = (int)$pdo->lastInsertId();

// Re-read to return the canonical row.
$out = $pdo->prepare('SELECT * FROM survey_schedules WHERE id = :id');
$out->execute([':id' => $id]);
$row = $out->fetch();

json_out([
    'ok'       => true,
    'schedule' => $row,
]);
