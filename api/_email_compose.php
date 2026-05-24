<?php
// ReliCheck ad-hoc email composer.
//
// Free-form sends from the admin Compose tab go through this helper, not the
// template-driven dispatcher. The helper:
//   - Validates the department is one of the eleven official senders.
//   - Wraps the body HTML in the standard ReliCheck shell so the look matches
//     templated mail.
//   - Inserts an email_logs row with event_key='admin.compose' and a fresh
//     idempotency_key per (sender, recipient, subject hash, time bucket) so
//     the same admin can re-send the same draft without being deduped.
//   - Enqueues an email_send_jobs row; the cron worker (queue_run.php) drains.
//
// Compose sends never carry restricted variables: the body is whatever the
// admin types, and the helper does not interpolate any template variables.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_email_renderer.php';

// Resolve a department code to the (id, sender_email, display_name) tuple,
// or null if not found / inactive. Compose refuses any other sender.
function relicheck_email_compose_resolve_department(string $code): ?array
{
    $st = db()->prepare(
        'SELECT id, code, display_name, sender_email
         FROM email_departments
         WHERE code = :c AND is_active = 1
         LIMIT 1'
    );
    $st->execute([':c' => $code]);
    $row = $st->fetch();
    return $row ?: null;
}

// Build the rendered HTML/text by wrapping the supplied inner body in the
// shared shell. Subject / preview substitution is intentionally NOT done so
// the admin sees exactly what they typed.
function relicheck_email_compose_render(array $department, string $subject, string $preview, string $body_html, string $body_text): array
{
    // Treat the department row as a partial template tuple so we can reuse
    // relicheck_email_wrap_html().
    $tpl = [
        'sender_display_name'  => $department['display_name'],
        'sender_email'         => $department['sender_email'],
        'subject_line'         => $subject,
        'preview_text'         => $preview,
        'primary_button_label' => null,
        'primary_button_url_template' => null,
    ];
    $full_html = relicheck_email_wrap_html($tpl, $body_html, '', []);

    // Plain-text fallback if the admin did not provide one.
    $text = trim($body_text);
    if ($text === '') {
        $text = trim(strip_tags($body_html));
    }

    return [
        'html'    => $full_html,
        'text'    => $text,
        'subject' => $subject,
        'preview' => $preview,
    ];
}

// Queue one send. Returns the new email_logs.id, or null if skipped
// (suppressed address, dedupe hit, missing recipient).
function relicheck_email_compose_queue_one(
    array $department,
    string $recipient_email,
    ?int $recipient_user_id,
    ?string $recipient_name,
    string $subject,
    string $preview,
    string $body_html,
    string $body_text,
    int $sender_user_id,
    string $batch_tag
): ?int {
    $email = strtolower(trim($recipient_email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

    $pdo = db();

    // Suppression list check.
    $sup = $pdo->prepare('SELECT 1 FROM email_suppression_list WHERE email = :e LIMIT 1');
    $sup->execute([':e' => $email]);
    if ($sup->fetch()) return null;

    $rendered = relicheck_email_compose_render($department, $subject, $preview, $body_html, $body_text);
    $hash = hash('sha256', $rendered['html']);

    // Idempotency: per sender, per recipient, per body hash, per minute bucket
    // (so a slip-clicked Send within the same minute is deduped, but a
    // legitimate re-send a minute later goes through).
    $bucket = (string)floor(time() / 60);
    $idem = substr(hash('sha256',
        'admin.compose|' . $sender_user_id . '|' . $email . '|' . $hash . '|' . $batch_tag . '|' . $bucket
    ), 0, 64);

    $existing = $pdo->prepare('SELECT id FROM email_logs WHERE idempotency_key = :k LIMIT 1');
    $existing->execute([':k' => $idem]);
    if ($existing->fetch()) return null;

    $sanitized = substr($rendered['html'], 0, 524288);
    $payload = json_encode([
        'sender_admin_user_id' => $sender_user_id,
        'batch_tag'            => $batch_tag,
    ], JSON_UNESCAPED_UNICODE);

    $pdo->prepare(
        'INSERT INTO email_logs
            (event_key, template_id, template_version, department_id,
             sender_email, sender_display_name,
             recipient_user_id, recipient_email, recipient_role,
             customer_account_id, subject, preview,
             body_snapshot_hash, sanitized_body, dynamic_payload,
             idempotency_key, status)
         VALUES
            ("admin.compose", NULL, NULL, :dept_id,
             :sender_email, :sender_name,
             :ruid, :remail, "customer",
             :acct_id, :subject, :preview,
             :hash, :body, :payload,
             :idem, "queued")'
    )->execute([
        ':dept_id'      => (int)$department['id'],
        ':sender_email' => (string)$department['sender_email'],
        ':sender_name'  => (string)$department['display_name'],
        ':ruid'         => $recipient_user_id,
        ':remail'       => $email,
        ':acct_id'      => $recipient_user_id,
        ':subject'      => $rendered['subject'],
        ':preview'      => $rendered['preview'],
        ':hash'         => $hash,
        ':body'         => $sanitized,
        ':payload'      => $payload,
        ':idem'         => $idem,
    ]);
    $log_id = (int)$pdo->lastInsertId();

    // Use SQL NOW() for due_at to dodge the IONOS PHP/MySQL timezone gap.
    $pdo->prepare(
        'INSERT INTO email_send_jobs (email_log_id, status, due_at)
         VALUES (:id, "pending", NOW())
         ON DUPLICATE KEY UPDATE status = "pending", due_at = NOW(), attempts = 0'
    )->execute([':id' => $log_id]);

    return $log_id;
}
