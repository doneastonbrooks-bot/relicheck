<?php
// GET/POST /api/cron-regenerate-reports.php?key=<email_cron_key>
//
// Phase 148 scheduled-report worker. Drains reports where schedule_cadence
// is weekly or monthly AND schedule_next_at <= NOW(). For each due row:
//   1. Rebuild the snapshot from the source survey's live data.
//   2. Update snapshot_json + last_generated_at.
//   3. Advance schedule_next_at by the cadence interval.
//   4. Best-effort: email the owner with a heads-up + the share link if any
//      active share exists. Failures don't roll back the regeneration.
//
// Configure cron-job.org to hit this URL once per hour. Protected by the
// email_cron_key from _config.php.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_reports_snapshot.php';
require_once __DIR__ . '/_cron.php';

require_method('GET', 'POST');

$cfg = relicheck_config();
$expected = (string)($cfg['email_cron_key'] ?? '');
if ($expected !== '') {
    $given = (string)($_GET['key'] ?? $_POST['key'] ?? '');
    if (!hash_equals($expected, $given)) {
        fail('forbidden', 'Invalid cron key.', 403);
    }
}

cron_heartbeat_start('regenerate_reports');

$pdo = db();
$processed   = 0;
$regenerated = 0;
$errors      = 0;
$details     = [];

// Pull every due row in one shot. Reports are typically few per user; a
// worst-case org with hundreds still fits in memory.
$dueStmt = $pdo->prepare(
    "SELECT id, user_id, source_survey_id, schedule_cadence, title
       FROM reports
      WHERE schedule_cadence IN ('weekly', 'monthly')
        AND schedule_next_at IS NOT NULL
        AND schedule_next_at <= NOW()
      ORDER BY schedule_next_at ASC
      LIMIT 200"
);
try { $dueStmt->execute(); } catch (Throwable $e) {
    json_out(['ok' => true, 'note' => 'Phase 148 migration pending; no reports table yet.']);
}
$due = $dueStmt->fetchAll();

foreach ($due as $row) {
    $processed++;
    $rid = (int)$row['id'];
    $sid = (int)$row['source_survey_id'];
    $cad = (string)$row['schedule_cadence'];
    try {
        $snap = reports_build_snapshot($sid);
        $snapJson = json_encode($snap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $interval = $cad === 'weekly' ? '7 DAY' : '1 MONTH';
        $pdo->prepare(
            "UPDATE reports
                SET snapshot_json     = :snap,
                    last_generated_at = NOW(),
                    schedule_next_at  = DATE_ADD(NOW(), INTERVAL " . $interval . ")
              WHERE id = :id"
        )->execute([':snap' => $snapJson, ':id' => $rid]);
        $regenerated++;
        $details[] = ['id' => $rid, 'ok' => true];
    } catch (Throwable $e) {
        $errors++;
        $details[] = ['id' => $rid, 'ok' => false, 'error' => $e->getMessage()];
    }
}

$_cronSummary = [
    'ok'          => true,
    'processed'   => $processed,
    'regenerated' => $regenerated,
    'errors'      => $errors,
    'details'     => $details,
];
cron_heartbeat_done('regenerate_reports', $_cronSummary, $errors > 0 ? ($errors . ' report(s) failed') : null);
json_out($_cronSummary);
