<?php
// GET /api/admin/cron/heartbeat.php
//
// Returns the cron_runs table so admin can see when each cron last fired.
// Includes a computed "stale_seconds" field (seconds since last_finished_at)
// and a "health" hint of ok / warn / down based on the job's expected
// cadence. The expected cadence is hard-coded here because cron-job.org
// owns the actual schedule and we don't have a way to query it.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');
require_admin();

$pdo = db();

// Expected seconds between successful runs for each known job. If a job
// goes longer than `warn` since last_finished_at, it's flagged warn; longer
// than `down` flags it down. Unknown jobs default to a generous 24h warn.
$expectations = [
    'fire_pulse'              => ['warn' => 4 * 3600,  'down' => 12 * 3600,  'every' => 'hourly'],
    'regenerate_reports'      => ['warn' => 26 * 3600, 'down' => 48 * 3600,  'every' => 'daily'],
    'fire_calendar_followups' => ['warn' => 4 * 3600,  'down' => 12 * 3600,  'every' => 'hourly'],
    'process_deletions'       => ['warn' => 26 * 3600, 'down' => 72 * 3600,  'every' => 'daily'],
    'recompute_health'        => ['warn' => 26 * 3600, 'down' => 48 * 3600,  'every' => 'daily'],
    'send_reminders'          => ['warn' => 4 * 3600,  'down' => 12 * 3600,  'every' => 'hourly'],
    'survey_activity'         => ['warn' => 26 * 3600, 'down' => 48 * 3600,  'every' => 'daily'],
    'trial_lifecycle'         => ['warn' => 26 * 3600, 'down' => 48 * 3600,  'every' => 'daily'],
];

try {
    $has = (bool)$pdo->query("SHOW TABLES LIKE 'cron_runs'")->fetchColumn();
} catch (Throwable $e) {
    $has = false;
}
if (!$has) {
    json_out([
        'ok'       => true,
        'jobs'     => [],
        'warning'  => 'cron_runs table is not present. Apply schema_phase168.sql.',
    ]);
}

$rows = $pdo->query(
    'SELECT job_name, last_started_at, last_finished_at, last_status,
            last_duration_ms, last_summary_json, last_error,
            run_count, error_count, updated_at
       FROM cron_runs
   ORDER BY job_name ASC'
)->fetchAll();

$now = time();
$jobs = [];
foreach ($rows as $r) {
    $job = (string)$r['job_name'];
    $finishedAt = $r['last_finished_at'];
    $finTs = $finishedAt ? strtotime((string)$finishedAt) : null;
    $stale = $finTs ? max(0, $now - $finTs) : null;

    $exp = $expectations[$job] ?? ['warn' => 24 * 3600, 'down' => 72 * 3600, 'every' => 'unknown'];

    $health = 'unknown';
    if ($stale === null) {
        $health = 'no_runs';
    } elseif ($stale > $exp['down']) {
        $health = 'down';
    } elseif ($stale > $exp['warn']) {
        $health = 'warn';
    } else {
        $health = ((string)$r['last_status'] === 'error') ? 'warn' : 'ok';
    }

    $summary = null;
    if (!empty($r['last_summary_json'])) {
        $decoded = json_decode((string)$r['last_summary_json'], true);
        if (is_array($decoded)) $summary = $decoded;
    }

    $jobs[] = [
        'job_name'          => $job,
        'last_started_at'   => $r['last_started_at'],
        'last_finished_at'  => $r['last_finished_at'],
        'last_status'       => (string)$r['last_status'],
        'last_duration_ms'  => $r['last_duration_ms'] !== null ? (int)$r['last_duration_ms'] : null,
        'last_summary'      => $summary,
        'last_error'        => $r['last_error'],
        'run_count'         => (int)$r['run_count'],
        'error_count'       => (int)$r['error_count'],
        'updated_at'        => $r['updated_at'],
        'stale_seconds'     => $stale,
        'expected_cadence'  => $exp['every'],
        'health'            => $health,
    ];
}

json_out([
    'ok'           => true,
    'now'          => date('Y-m-d H:i:s'),
    'jobs'         => $jobs,
    'known_jobs'   => array_keys($expectations),
]);
