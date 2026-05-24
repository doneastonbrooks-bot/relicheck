<?php
// POST /api/reports/schedule.php
// Body: { id, cadence: "off" | "weekly" | "monthly" }
// Turns scheduled re-generation on or off. When set to weekly/monthly,
// schedule_next_at is computed from NOW(). The cron worker
// (/api/cron/reports.php) picks up due rows and regenerates them.

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
$cad = clean_string((string)($body['cadence'] ?? 'off'), 16);
if (!in_array($cad, ['off', 'weekly', 'monthly'], true)) {
    fail('bad_input', 'cadence must be off, weekly, or monthly.', 400);
}

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

if ($cad === 'off') {
    $pdo->prepare(
        "UPDATE reports
            SET schedule_cadence = NULL, schedule_next_at = NULL,
                status = CASE WHEN status = 'scheduled' THEN 'draft' ELSE status END
          WHERE id = :id"
    )->execute([':id' => $id]);
    json_out(['ok' => true, 'cadence' => null, 'next_at' => null]);
}

// Compute the next fire time in MySQL so PHP/MySQL timezone drift doesn't bite.
$interval = $cad === 'weekly' ? '7 DAY' : '1 MONTH';
$pdo->prepare(
    "UPDATE reports
        SET schedule_cadence = :c,
            schedule_next_at = DATE_ADD(NOW(), INTERVAL " . $interval . "),
            status = 'scheduled'
      WHERE id = :id"
)->execute([':c' => $cad, ':id' => $id]);

$next = $pdo->prepare('SELECT schedule_next_at FROM reports WHERE id = :id');
$next->execute([':id' => $id]);
$nextAt = (string)$next->fetchColumn();

json_out(['ok' => true, 'cadence' => $cad, 'next_at' => $nextAt]);
