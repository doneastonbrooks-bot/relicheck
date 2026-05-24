<?php
// POST /api/email/resend.php
// Body: { email_log_id: 123 }
//
// Admin action. Re-enqueues a previously sent (or failed) email_log row by
// inserting a fresh email_send_jobs entry. Writes an audit row.
//
// Customer service can resend verification + support emails. Other senders
// require dept-matching admin per Section K of the email-system spec.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_admin.php';
require_once __DIR__ . '/../_email_dispatcher.php';

require_method('POST');
check_origin();

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in required.', 401);
if (!is_admin_user($user)) fail('forbidden', 'Admins only.', 403);

$body = read_json_body();
$id = (int)($body['email_log_id'] ?? 0);
if ($id <= 0) fail('bad_request', 'email_log_id is required.');

$pdo = db();
$st = $pdo->prepare(
    'SELECT l.id, l.event_key, l.template_id, l.recipient_email, l.recipient_user_id,
            l.subject, t.template_key, t.department_id, d.code AS department_code
     FROM email_logs l
     LEFT JOIN email_templates t ON t.id = l.template_id
     LEFT JOIN email_departments d ON d.id = l.department_id
     WHERE l.id = :id LIMIT 1'
);
$st->execute([':id' => $id]);
$log = $st->fetch();
if (!$log) fail('not_found', 'Log not found.', 404);

// Reset job state and re-queue.
$pdo->prepare(
    'INSERT INTO email_send_jobs (email_log_id, status, due_at)
     VALUES (:id, "pending", NOW())
     ON DUPLICATE KEY UPDATE status="pending", due_at=NOW(), attempts=0,
                              picked_at=NULL, finished_at=NULL, last_error=NULL'
)->execute([':id' => $id]);

$pdo->prepare(
    'UPDATE email_logs SET status="queued", attempts=0, last_error=NULL,
                           next_attempt_at=NULL WHERE id = :id'
)->execute([':id' => $id]);

relicheck_email_audit((int)$user['id'], 'email.resend', 'email_logs', $id,
    null, [
        'event_key'    => (string)$log['event_key'],
        'template_key' => (string)($log['template_key'] ?? ''),
        'recipient'    => (string)$log['recipient_email'],
    ]
);

json_out(['ok' => true, 'email_log_id' => $id]);
