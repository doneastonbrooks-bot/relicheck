<?php
// GET/POST /api/email/queue_run.php
//
// Cron worker. Drains pending rows from email_send_jobs, attempts the SMTP
// send via send_mail(), and records the outcome on email_logs.
//
// Configure IONOS cron to hit this URL once per minute (or whatever cadence
// fits your traffic). Optionally protect it with ?key=<email_cron_key> from
// _config.php so the URL is not openly callable.
//
// Limits per call:
//   - Up to 25 jobs per invocation (safe for a 1-minute cron tick).
//   - Each failed job backs off: 1m, 5m, 30m, 2h, 12h. Max 5 attempts.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_mailer.php';
require_once __DIR__ . '/../_email_dispatcher.php';

require_method('GET', 'POST');

$cfg = relicheck_config();
$expected = (string)($cfg['email_cron_key'] ?? '');
if ($expected !== '') {
    $given = (string)($_GET['key'] ?? $_POST['key'] ?? '');
    if (!hash_equals($expected, $given)) {
        fail('forbidden', 'Invalid cron key.', 403);
    }
}

$pdo = db();

// Pull a small batch of jobs that are due.
$batch = $pdo->query(
    "SELECT j.id AS job_id, j.email_log_id, j.attempts,
            l.recipient_email, l.subject, l.sanitized_body, l.sender_email,
            l.sender_display_name, l.template_id
     FROM email_send_jobs j
     JOIN email_logs l ON l.id = j.email_log_id
     WHERE j.status = 'pending' AND j.due_at <= NOW()
     ORDER BY j.due_at ASC
     LIMIT 25"
)->fetchAll();

$processed = 0; $sent = 0; $failed = 0; $permanent = 0;

foreach ($batch as $job) {
    $job_id   = (int)$job['job_id'];
    $log_id   = (int)$job['email_log_id'];
    $attempts = (int)$job['attempts'] + 1;

    // Mark running.
    $pdo->prepare(
        "UPDATE email_send_jobs SET status='running', picked_at=NOW(), attempts=:a WHERE id=:id"
    )->execute([':a' => $attempts, ':id' => $job_id]);
    $pdo->prepare("UPDATE email_logs SET status='sending', attempts=:a WHERE id=:lid")
        ->execute([':a' => $attempts, ':lid' => $log_id]);

    try {
        // Plain-text fallback: prefer body_text from email_logs.dynamic_payload
        // if present, otherwise derive from HTML.
        $text = trim(strip_tags((string)$job['sanitized_body']));
        $html = (string)$job['sanitized_body'];

        // Deliverability headers. List-Unsubscribe + One-Click are weighted
        // heavily by Gmail and iCloud. Reply-To routes recipient replies
        // back to the sending department's mailbox.
        $unsub_mailto = 'unsubscribe@relichecksurvey.com';
        $unsub_url    = 'https://relichecksurvey.com/api/email/unsubscribe.php?log=' . (int)$log_id;

        send_mail(
            (string)$job['recipient_email'],
            (string)$job['subject'],
            $text,
            $html,
            [
                'from'                  => (string)$job['sender_email'],
                'from_name'             => (string)$job['sender_display_name'],
                'reply_to'              => (string)$job['sender_email'],
                'list_unsubscribe'      => '<mailto:' . $unsub_mailto . '?subject=unsubscribe>, <' . $unsub_url . '>',
                'list_unsubscribe_post' => true,
                'extra_headers'         => [
                    'X-Relicheck-Log-ID: ' . (int)$log_id,
                    'Auto-Submitted: auto-generated',
                    'Precedence: bulk',
                ],
            ]
        );

        $pdo->prepare(
            "UPDATE email_logs SET status='sent', sent_at=NOW(), last_error=NULL WHERE id=:lid"
        )->execute([':lid' => $log_id]);
        $pdo->prepare(
            "UPDATE email_send_jobs SET status='done', finished_at=NOW(), last_error=NULL WHERE id=:id"
        )->execute([':id' => $job_id]);
        $sent++;
    } catch (Throwable $e) {
        $msg = substr($e->getMessage(), 0, 500);

        // Record failure detail row.
        $pdo->prepare(
            "INSERT INTO email_delivery_failures
             (email_log_id, attempt_number, error_code, error_message, provider_response)
             VALUES (:lid, :att, :ec, :em, :pr)"
        )->execute([
            ':lid' => $log_id,
            ':att' => $attempts,
            ':ec'  => 'smtp_send_failed',
            ':em'  => $msg,
            ':pr'  => $msg,
        ]);

        // Backoff schedule. Compute via SQL to dodge the IONOS PHP/MySQL
        // timezone mismatch.
        $minutes_by_attempt = [1, 5, 30, 120, 720];
        $idx = max(0, min(count($minutes_by_attempt) - 1, $attempts - 1));
        $next = $minutes_by_attempt[$idx];

        if ($attempts >= 5) {
            $pdo->prepare(
                "UPDATE email_logs SET status='failed_permanent', last_error=:e WHERE id=:lid"
            )->execute([':e' => $msg, ':lid' => $log_id]);
            $pdo->prepare(
                "UPDATE email_send_jobs SET status='failed', finished_at=NOW(), last_error=:e WHERE id=:id"
            )->execute([':e' => $msg, ':id' => $job_id]);
            $permanent++;
        } else {
            $pdo->prepare(
                "UPDATE email_logs SET status='queued', last_error=:e,
                 next_attempt_at = DATE_ADD(NOW(), INTERVAL :m MINUTE) WHERE id=:lid"
            )->execute([':e' => $msg, ':m' => $next, ':lid' => $log_id]);
            $pdo->prepare(
                "UPDATE email_send_jobs SET status='pending',
                 due_at = DATE_ADD(NOW(), INTERVAL :m MINUTE),
                 picked_at = NULL, last_error=:e WHERE id=:id"
            )->execute([':m' => $next, ':e' => $msg, ':id' => $job_id]);
            $failed++;
        }
    }

    $processed++;
}

json_out([
    'ok'        => true,
    'processed' => $processed,
    'sent'      => $sent,
    'retry'     => $failed,
    'permanent' => $permanent,
]);
