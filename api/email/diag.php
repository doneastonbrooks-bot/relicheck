<?php
// GET /api/email/diag.php
//
// One-shot health check for the ReliCheck email system. Hit this URL while
// signed in as admin and you'll get back a JSON report telling you:
//   - Which tables exist (Phase 31 and Phase 32 migrations).
//   - Whether the seed ran (departments, templates, events).
//   - Whether any jobs are pending in email_send_jobs and how old the
//     oldest pending one is.
//   - The last successful send timestamp.
//   - Whether SMTP and cron config keys are present.
//   - Whether the file artifacts the system depends on are on disk.
//
// Use this whenever something looks off (no emails arriving, the admin
// panel loops to login, etc.) to tell us in one call which layer is broken.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_admin.php';

require_method('GET');

// Two ways to auth: signed-in admin, OR ?key=<email_cron_key> in the URL.
// The key fallback lets the owner check system health from any device
// without an admin session (useful when the admin login itself is broken).
$cfg = relicheck_config();
$expected_key = (string)($cfg['email_cron_key'] ?? '');
$given_key    = (string)($_GET['key'] ?? '');
$key_ok       = $expected_key !== '' && hash_equals($expected_key, $given_key);

if (!$key_ok) {
    $user = current_user();
    if (!$user) fail('not_signed_in', 'Sign in required, or pass ?key=<email_cron_key>.', 401);
    if (!is_admin_user($user)) fail('forbidden', 'Admins only, or pass ?key=<email_cron_key>.', 403);
}

$pdo = db();

// ---- Table existence ----
$expected_tables = [
    // Phase 31
    'email_departments','email_templates','email_template_versions',
    'email_events','email_logs','email_audit_logs','email_suppression_list',
    // Phase 32
    'email_preferences','employee_notification_preferences',
    'role_required_notifications','unsubscribe_tokens',
    'email_delivery_failures','email_open_events','email_click_events',
    'email_event_buffer','email_send_jobs',
];
$tables = [];
foreach ($expected_tables as $t) {
    // Direct SHOW TABLES LIKE with sanitized name (no placeholders, per the
    // IONOS MySQL constraint).
    $safe = preg_replace('/[^a-z_]/', '', $t);
    $r = $pdo->query("SHOW TABLES LIKE '$safe'")->fetch();
    $tables[$t] = (bool)$r;
}

// ---- Seed counts ----
$counts = ['departments' => null, 'templates' => null, 'events' => null,
           'logs' => null, 'send_jobs_pending' => null, 'send_jobs_failed' => null];
try { $counts['departments']        = (int)$pdo->query('SELECT COUNT(*) FROM email_departments')->fetchColumn(); } catch (Throwable $e) {}
try { $counts['templates']          = (int)$pdo->query('SELECT COUNT(*) FROM email_templates')->fetchColumn(); } catch (Throwable $e) {}
try { $counts['events']             = (int)$pdo->query('SELECT COUNT(*) FROM email_events')->fetchColumn(); } catch (Throwable $e) {}
try { $counts['logs']               = (int)$pdo->query('SELECT COUNT(*) FROM email_logs')->fetchColumn(); } catch (Throwable $e) {}
try { $counts['send_jobs_pending']  = (int)$pdo->query("SELECT COUNT(*) FROM email_send_jobs WHERE status='pending'")->fetchColumn(); } catch (Throwable $e) {}
try { $counts['send_jobs_failed']   = (int)$pdo->query("SELECT COUNT(*) FROM email_send_jobs WHERE status='failed'")->fetchColumn(); } catch (Throwable $e) {}

// ---- Queue freshness ----
$queue = ['oldest_pending_age_seconds' => null, 'last_sent_at' => null, 'last_failed_at' => null];
try {
    $r = $pdo->query("SELECT TIMESTAMPDIFF(SECOND, MIN(due_at), NOW()) AS age FROM email_send_jobs WHERE status='pending'")->fetch();
    $queue['oldest_pending_age_seconds'] = $r ? (int)$r['age'] : null;
} catch (Throwable $e) {}
try {
    $queue['last_sent_at']   = $pdo->query("SELECT MAX(sent_at) FROM email_logs WHERE status IN ('sent','delivered','opened','clicked')")->fetchColumn();
    $queue['last_failed_at'] = $pdo->query("SELECT MAX(failed_at) FROM email_delivery_failures")->fetchColumn();
} catch (Throwable $e) {}

// ---- File presence ----
$root = realpath(__DIR__ . '/..');
$files = [
    'admin-email.html'                          => is_file(dirname($root) . '/admin-email.html'),
    'api/_email_dispatcher.php'                 => is_file($root . '/_email_dispatcher.php'),
    'api/_email_renderer.php'                   => is_file($root . '/_email_renderer.php'),
    'api/_email_resolver.php'                   => is_file($root . '/_email_resolver.php'),
    'api/_email_compose.php'                    => is_file($root . '/_email_compose.php'),
    'api/email/queue_run.php'                   => is_file($root . '/email/queue_run.php'),
    'api/email/preferences.php'                 => is_file($root . '/email/preferences.php'),
    'api/email/unsubscribe.php'                 => is_file($root . '/email/unsubscribe.php'),
    'api/email/resend.php'                      => is_file($root . '/email/resend.php'),
    'api/email/suppression.php'                 => is_file($root . '/email/suppression.php'),
    'api/webhooks/email.php'                    => is_file($root . '/webhooks/email.php'),
    'api/admin/email/templates.php'             => is_file($root . '/admin/email/templates.php'),
    'api/admin/email/logs.php'                  => is_file($root . '/admin/email/logs.php'),
    'api/admin/email/failures.php'              => is_file($root . '/admin/email/failures.php'),
    'api/admin/email/audit.php'                 => is_file($root . '/admin/email/audit.php'),
    'api/admin/email/compose.php'               => is_file($root . '/admin/email/compose.php'),
    'api/admin/email/customer_search.php'       => is_file($root . '/admin/email/customer_search.php'),
    'api/admin/email/blast_welcome.php'         => is_file($root . '/admin/email/blast_welcome.php'),
];

// ---- Config keys ----
$config_check = [
    'smtp_host'              => !empty($cfg['smtp_host']),
    'smtp_user'              => !empty($cfg['smtp_user']),
    'smtp_pass'              => !empty($cfg['smtp_pass']),
    'mail_from'              => !empty($cfg['mail_from']),
    'site_url'               => !empty($cfg['site_url']),
    'email_cron_key'         => !empty($cfg['email_cron_key']),
    'email_webhook_secret'   => !empty($cfg['email_webhook_secret']),
    'email_test_send_domains'=> !empty($cfg['email_test_send_domains']),
];

// ---- Recent signups and their email trail ----
$recent_signups = [];
try {
    $rs = $pdo->query("SELECT id, email, name, created_at, last_login_at
                       FROM users ORDER BY id DESC LIMIT 5")->fetchAll();
    foreach ($rs as $u) {
        $logs = $pdo->prepare(
            "SELECT id, event_key, status, sent_at, last_error, created_at
             FROM email_logs WHERE recipient_user_id = :uid ORDER BY id DESC LIMIT 5"
        );
        $logs->execute([':uid' => (int)$u['id']]);
        $recent_signups[] = [
            'user_id'       => (int)$u['id'],
            'email'         => (string)$u['email'],
            'name'          => (string)$u['name'],
            'created_at'    => $u['created_at'],
            'last_login_at' => $u['last_login_at'],
            'email_logs'    => $logs->fetchAll(),
        ];
    }
} catch (Throwable $e) {}

// ---- Most recent email_logs across all recipients ----
$recent_logs = [];
try {
    $recent_logs = $pdo->query(
        "SELECT id, event_key, recipient_email, subject, status, sent_at,
                last_error, created_at
         FROM email_logs ORDER BY id DESC LIMIT 10"
    )->fetchAll();
} catch (Throwable $e) {}

// ---- Recent delivery failures ----
$recent_failures = [];
try {
    $recent_failures = $pdo->query(
        "SELECT f.id, f.email_log_id, f.error_message, f.failed_at,
                l.recipient_email, l.event_key
         FROM email_delivery_failures f
         JOIN email_logs l ON l.id = f.email_log_id
         ORDER BY f.id DESC LIMIT 10"
    )->fetchAll();
} catch (Throwable $e) {}

// ---- Diagnosis summary ----
$problems = [];
$missing_tables = array_keys(array_filter($tables, fn($v) => !$v));
if ($missing_tables) {
    $problems[] = 'Missing DB tables: ' . implode(', ', $missing_tables) .
                  '. Run schema_phase31.sql, schema_phase32.sql, then schema_phase31b.sql in phpMyAdmin.';
}
if (($counts['departments'] ?? 0) < 11) {
    $problems[] = 'Only ' . ($counts['departments'] ?? 0) . ' of 11 departments seeded. Run schema_phase31b.sql.';
}
if (($counts['templates'] ?? 0) < 43) {
    $problems[] = 'Only ' . ($counts['templates'] ?? 0) . ' of 43 launch templates seeded. Run schema_phase31b.sql.';
}
$missing_files = array_keys(array_filter($files, fn($v) => !$v));
if ($missing_files) {
    $problems[] = 'Missing files on disk: ' . implode(', ', $missing_files) .
                  '. Upload via FileZilla.';
}
if (!$config_check['email_cron_key']) {
    $problems[] = 'email_cron_key is not set in api/_config.php. The cron worker URL will refuse to run.';
}
if (($queue['oldest_pending_age_seconds'] ?? 0) > 180) {
    $problems[] = 'Oldest pending send is ' . $queue['oldest_pending_age_seconds'] . ' seconds old. ' .
                  'The cron job is probably not configured. Add the cron line that hits ' .
                  '/api/email/queue_run.php?key=YOUR_KEY every minute.';
}
if (!$problems) {
    $problems[] = 'No problems detected. If sends still are not arriving, check the sender domain SPF/DKIM and the recipient spam folder.';
}

// ---- Did the most recent signup get a welcome email? ----
$signup_diag = null;
if (!empty($recent_signups)) {
    $latest = $recent_signups[0];
    $welcome_logs = array_filter($latest['email_logs'], function($l) {
        return in_array($l['event_key'], ['user.email_verified', 'user.created'], true);
    });
    if (empty($welcome_logs)) {
        $signup_diag = "User #{$latest['user_id']} ({$latest['email']}) signed up at {$latest['created_at']} but NO welcome email was queued. " .
                       "The dispatch call in api/auth/signup.php is not firing. " .
                       "Likely cause: signup.php was not re-uploaded after the welcome-on-signup edit, or api/_email_dispatcher.php is missing.";
        $problems[] = $signup_diag;
    } else {
        $w = reset($welcome_logs);
        if ($w['status'] === 'queued') {
            $signup_diag = "User #{$latest['user_id']} got a welcome queued (log #{$w['id']}) but it has not sent yet. Cron worker has not drained it.";
            $problems[] = $signup_diag;
        } elseif (in_array($w['status'], ['failed', 'failed_permanent', 'bounced'], true)) {
            $signup_diag = "User #{$latest['user_id']} welcome email log #{$w['id']} is in status '{$w['status']}'. Last error: " . ($w['last_error'] ?: '(none)');
            $problems[] = $signup_diag;
        } elseif (in_array($w['status'], ['sent', 'delivered', 'opened', 'clicked'], true)) {
            $signup_diag = "User #{$latest['user_id']} welcome email log #{$w['id']} reports status '{$w['status']}' at {$w['sent_at']}. SMTP accepted it. If the recipient does not see it, the receiving mail server filtered or silently dropped it.";
        }
    }
}

json_out([
    'ok'                  => true,
    'tables'              => $tables,
    'seed_counts'         => $counts,
    'queue'               => $queue,
    'files_on_disk'       => $files,
    'config_keys_set'     => $config_check,
    'recent_signups'      => $recent_signups,
    'recent_logs'         => $recent_logs,
    'recent_failures'     => $recent_failures,
    'latest_signup_status'=> $signup_diag,
    'diagnosis'           => $problems,
]);
