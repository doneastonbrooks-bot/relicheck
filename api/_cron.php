<?php
// Cron heartbeat helpers (Phase 168).
//
// Every cron script calls cron_heartbeat_start() at the top and
// cron_heartbeat_done() right before its final json_out(). One row per
// job is upserted into cron_runs. The admin endpoint at
// /api/admin/cron/heartbeat.php reads the table so a dead cron is visible.
//
// All helpers are wrapped in try/catch and never throw upstream: if the
// cron_runs table is missing (Phase 168 migration not yet run) the cron
// still runs normally, just without a recorded heartbeat.

declare(strict_types=1);

if (!function_exists('cron_heartbeat_start')) {

    // Holds the start time per job name, in microseconds, so cron_heartbeat_done
    // can compute the duration without the caller passing it through.
    function &_cron_heartbeat_state(): array
    {
        static $state = [];
        return $state;
    }

    /**
     * Mark a cron run as started. Call at the top of the cron script (after
     * the key check). Safe to call when the cron_runs table is missing.
     *
     * Also registers a shutdown function so a fatal error (uncaught throw,
     * timeout) still records an 'error' status instead of leaving the row
     * stuck at 'running'.
     */
    function cron_heartbeat_start(string $jobName): void
    {
        $jobName = substr(trim($jobName), 0, 80);
        if ($jobName === '') return;
        $state =& _cron_heartbeat_state();
        $state[$jobName] = microtime(true);
        $state['_completed:' . $jobName] = false;

        try {
            $pdo = db();
            $pdo->prepare(
                'INSERT INTO cron_runs (job_name, last_started_at, last_status, run_count)
                 VALUES (:n, NOW(), \'running\', 1)
                 ON DUPLICATE KEY UPDATE
                     last_started_at = VALUES(last_started_at),
                     last_status     = \'running\',
                     run_count       = run_count + 1'
            )->execute([':n' => $jobName]);
        } catch (Throwable $e) {
            error_log('[relicheck] cron_heartbeat_start skipped for ' . $jobName . ': ' . $e->getMessage());
        }

        register_shutdown_function(function () use ($jobName) {
            $st =& _cron_heartbeat_state();
            if (!empty($st['_completed:' . $jobName])) return;
            $err = error_get_last();
            $msg = $err ? ($err['message'] ?? 'shutdown without completion') : 'shutdown without completion';
            cron_heartbeat_done($jobName, [], 'fatal: ' . $msg);
        });
    }

    /**
     * Mark a cron run as finished. Pass the same job name used at start, the
     * summary array the cron returns via json_out, and optionally an error
     * message string if the job hit a fatal failure path.
     */
    function cron_heartbeat_done(string $jobName, array $summary = [], ?string $errorMsg = null): void
    {
        $jobName = substr(trim($jobName), 0, 80);
        if ($jobName === '') return;
        $state =& _cron_heartbeat_state();
        $startedAt = $state[$jobName] ?? null;
        $durMs = $startedAt !== null ? (int)round((microtime(true) - $startedAt) * 1000) : null;

        // Cap summary JSON so a chatty job doesn't bloat the table. Drop noisy
        // detail fields if they exceed the cap, then re-encode.
        $sumJson = null;
        try {
            $clone = $summary;
            if (isset($clone['details']) && is_array($clone['details']) && count($clone['details']) > 20) {
                $clone['details_count'] = count($clone['details']);
                $clone['details'] = array_slice($clone['details'], 0, 20);
            }
            $sumJson = json_encode($clone, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($sumJson !== false && strlen($sumJson) > 4000) {
                $sumJson = substr($sumJson, 0, 4000);
            }
        } catch (Throwable $e) {
            $sumJson = null;
        }

        $status = $errorMsg ? 'error' : 'ok';
        $err    = $errorMsg !== null ? substr($errorMsg, 0, 500) : null;

        // Mark complete so the shutdown function doesn't double-record.
        $state['_completed:' . $jobName] = true;

        try {
            $pdo = db();
            $pdo->prepare(
                'INSERT INTO cron_runs
                    (job_name, last_started_at, last_finished_at, last_status,
                     last_duration_ms, last_summary_json, last_error,
                     run_count, error_count)
                 VALUES (:n, COALESCE(:st, NOW()), NOW(), :status, :dur, :sum, :err, 1, :ec)
                 ON DUPLICATE KEY UPDATE
                     last_finished_at  = VALUES(last_finished_at),
                     last_status       = VALUES(last_status),
                     last_duration_ms  = VALUES(last_duration_ms),
                     last_summary_json = VALUES(last_summary_json),
                     last_error        = VALUES(last_error),
                     error_count       = error_count + :ec'
            )->execute([
                ':n'      => $jobName,
                ':st'     => $startedAt !== null ? date('Y-m-d H:i:s', (int)$startedAt) : null,
                ':status' => $status,
                ':dur'    => $durMs,
                ':sum'    => $sumJson,
                ':err'    => $err,
                ':ec'     => $errorMsg ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            error_log('[relicheck] cron_heartbeat_done skipped for ' . $jobName . ': ' . $e->getMessage());
        }

        // Free the start-time entry so a long-running PHP process doesn't grow.
        unset($state[$jobName]);
    }
}
