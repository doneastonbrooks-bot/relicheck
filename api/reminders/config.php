<?php
// /api/reminders/config.php?survey_id=N
// GET  -> returns the schedule (or sensible defaults if none set)
// POST -> saves { survey_id, enabled, days_until_first, days_between, max_reminders }
//
// One row per survey, upserted via the unique key on survey_id.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_invitations.php';

require_method('GET', 'POST');
$user = require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $sid = (int)($_GET['survey_id'] ?? 0);
    if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);
    invitations_require_survey_owned_by($sid, (int)$user['id']);

    try {
        $stmt = $pdo->prepare(
            'SELECT enabled, days_until_first, days_between, max_reminders, updated_at
               FROM survey_reminder_schedules WHERE survey_id = :sid LIMIT 1'
        );
        $stmt->execute([':sid' => $sid]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        json_out([
            'schedule' => ['enabled' => true, 'days_until_first' => 3, 'days_between' => 4, 'max_reminders' => 2],
            'note' => 'phase38_pending',
        ]);
    }

    if (!$row) {
        json_out([
            'schedule' => [
                'enabled'          => true,
                'days_until_first' => 3,
                'days_between'     => 4,
                'max_reminders'    => 2,
                'updated_at'       => null,
            ],
        ]);
    }
    json_out([
        'schedule' => [
            'enabled'          => (bool)$row['enabled'],
            'days_until_first' => (int)$row['days_until_first'],
            'days_between'     => (int)$row['days_between'],
            'max_reminders'    => (int)$row['max_reminders'],
            'updated_at'       => $row['updated_at'],
        ],
    ]);
}

// POST -------------------------------------------------------------------
check_origin();
$body = read_json_body();
$sid  = (int)($body['survey_id'] ?? 0);
if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);
invitations_require_survey_owned_by($sid, (int)$user['id']);

$enabled        = !empty($body['enabled']) ? 1 : 0;
$daysUntilFirst = max(1, min(60,  (int)($body['days_until_first'] ?? 3)));
$daysBetween    = max(1, min(60,  (int)($body['days_between']     ?? 4)));
$maxReminders   = max(0, min(10,  (int)($body['max_reminders']    ?? 2)));

try {
    $stmt = $pdo->prepare(
        'INSERT INTO survey_reminder_schedules
            (survey_id, enabled, days_until_first, days_between, max_reminders)
         VALUES (:sid, :en, :duf, :db, :mx)
         ON DUPLICATE KEY UPDATE
            enabled = VALUES(enabled),
            days_until_first = VALUES(days_until_first),
            days_between = VALUES(days_between),
            max_reminders = VALUES(max_reminders)'
    );
    $stmt->execute([
        ':sid' => $sid,
        ':en'  => $enabled,
        ':duf' => $daysUntilFirst,
        ':db'  => $daysBetween,
        ':mx'  => $maxReminders,
    ]);
} catch (Throwable $e) {
    fail('migration_pending', 'Phase 38 migration has not been applied yet.', 503);
}

json_out([
    'schedule' => [
        'enabled'          => (bool)$enabled,
        'days_until_first' => $daysUntilFirst,
        'days_between'     => $daysBetween,
        'max_reminders'    => $maxReminders,
    ],
]);
