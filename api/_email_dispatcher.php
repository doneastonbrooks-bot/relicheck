<?php
// ReliCheck email dispatcher.
//
// Single entry point used by the rest of the application:
//
//   relicheck_email_dispatch('survey.published', [
//       'user_id'    => 42,
//       'account_id' => 42,
//       'payload'    => [
//           'first_name'        => 'Donald',
//           'survey_name'       => 'Spring 2026 Climate Check',
//           'survey_id'         => 1234,
//           'public_survey_link'=> 'https://relichecksurvey.com/r/abc',
//       ],
//   ]);
//
// The dispatcher:
//   1. Looks up the event row in email_events.
//   2. Picks the matching template (customer or employee, or both).
//   3. Applies dedupe (idempotency_key + dedupe_window_minutes).
//   4. Resolves recipients via the named resolver.
//   5. Filters by suppression list and (for non-required emails) preferences.
//   6. Refuses to send any employee template that references restricted vars.
//   7. Inserts an email_logs row + an email_send_jobs row per recipient.
//   8. Returns a summary.
//
// The actual SMTP send happens in api/email/queue_run.php (cron worker), so a
// burst of dispatches never blocks the request thread.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_email_renderer.php';
require_once __DIR__ . '/_email_resolver.php';

function relicheck_email_dispatch(string $event_key, array $context): array
{
    $pdo = db();
    $cfg = relicheck_config();

    $event = relicheck_email_event($event_key);
    if (!$event) {
        return ['ok' => false, 'reason' => 'unknown_event', 'event_key' => $event_key, 'queued' => 0];
    }
    if (!(int)$event['is_active']) {
        return ['ok' => true, 'reason' => 'event_inactive', 'queued' => 0];
    }

    $audiences = [];
    if (!empty($event['customer_template_id'])) $audiences[] = ['customer', (int)$event['customer_template_id']];
    if (!empty($event['employee_template_id'])) $audiences[] = ['employee', (int)$event['employee_template_id']];

    $payload = (array)($context['payload'] ?? []);
    $payload['site_url'] = $payload['site_url'] ?? rtrim((string)($cfg['site_url'] ?? ''), '/');

    $queued     = 0;
    $skipped    = 0;
    $violations = [];

    foreach ($audiences as [$audience_kind, $template_id]) {
        $tpl = relicheck_email_load_template_by_id($template_id);
        if (!$tpl) { $skipped++; continue; }

        // Privacy guard: refuse the send if any restricted var is referenced
        // by an employee template, even if the variable was not in payload.
        if ($audience_kind === 'employee') {
            $bad = relicheck_email_template_violates_privacy($tpl);
            if ($bad !== null) {
                $violations[] = ['template_key' => $tpl['template_key'], 'variable' => $bad];
                error_log("[relicheck-email] privacy violation: template {$tpl['template_key']} references restricted variable {{{$bad}}}");
                continue;
            }
        }

        // Resolve recipients for this audience.
        $resolver = $audience_kind === 'customer'
            ? 'customer_self'
            : (string)$event['recipient_resolver'];
        $recipients = relicheck_email_resolve_recipients($resolver, $context);
        if (!$recipients) { $skipped++; continue; }

        foreach ($recipients as $r) {
            if (!relicheck_email_recipient_eligible($r, $tpl, $event)) {
                $skipped++;
                continue;
            }

            $rendered = relicheck_email_render($tpl, array_merge($payload, [
                'first_name' => $payload['first_name'] ?? relicheck_first_name((string)($r['name'] ?? '')),
                'email'      => $payload['email']      ?? (string)$r['email'],
            ]));

            $idem = relicheck_email_idem_key($event_key, $tpl['template_key'], (int)($r['user_id'] ?? 0), (string)$r['email'], $context, (int)$event['dedupe_window_minutes']);
            if (relicheck_email_already_sent($idem)) {
                $skipped++;
                continue;
            }

            $log_id = relicheck_email_insert_log($tpl, $r, $rendered, $idem, $event_key, $context);
            relicheck_email_enqueue($log_id);
            $queued++;
        }
    }

    return [
        'ok'                 => true,
        'event_key'          => $event_key,
        'queued'             => $queued,
        'skipped'            => $skipped,
        'privacy_violations' => $violations,
    ];
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function relicheck_email_event(string $event_key): ?array
{
    $stmt = db()->prepare('SELECT * FROM email_events WHERE event_key = :k LIMIT 1');
    $stmt->execute([':k' => $event_key]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function relicheck_email_load_template_by_id(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT t.*, d.code AS department_code, d.display_name AS sender_display_name,
                d.sender_email AS sender_email
         FROM email_templates t
         JOIN email_departments d ON d.id = t.department_id
         WHERE t.id = :id AND t.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function relicheck_email_recipient_eligible(array $r, array $tpl, array $event): bool
{
    $email = strtolower((string)$r['email']);
    if ($email === '') return false;

    // Suppression list: never send to a hard-bounced or complained address.
    $stmt = db()->prepare('SELECT 1 FROM email_suppression_list WHERE email = :e LIMIT 1');
    $stmt->execute([':e' => $email]);
    if ($stmt->fetch()) return false;

    // Required transactional / billing / privacy / legal / security emails
    // are always sent regardless of preferences.
    if ((int)$tpl['is_required'] === 1) return true;

    $group = (string)($tpl['unsubscribe_group'] ?? '');
    if ($group === '') return true; // no group = no opt-out path = always send

    // Customer or employee preference table?
    $uid = (int)($r['user_id'] ?? 0);
    if ($uid <= 0) return true;

    if (($r['audience'] ?? '') === 'employee') {
        $sql = 'SELECT is_enabled FROM employee_notification_preferences
                WHERE employee_user_id = :u AND preference_group = :g LIMIT 1';
    } else {
        $sql = 'SELECT is_enabled FROM email_preferences
                WHERE user_id = :u AND preference_group = :g LIMIT 1';
    }
    $st = db()->prepare($sql);
    $st->execute([':u' => $uid, ':g' => $group]);
    $row = $st->fetch();
    if (!$row) return true; // no row = default-enabled
    return (int)$row['is_enabled'] === 1;
}

function relicheck_email_idem_key(string $event_key, string $template_key, int $uid, string $email, array $ctx, int $dedupe_minutes): string
{
    // Bucket the timestamp so we can dedupe within a window. Bucket 0 = single use.
    $bucket = '0';
    if ($dedupe_minutes > 0) {
        $bucket = (string)floor(time() / ($dedupe_minutes * 60));
    }
    $entity = (string)($ctx['idempotency_entity_id'] ?? '');
    $raw    = $event_key . '|' . $template_key . '|' . $uid . '|' . strtolower($email) . '|' . $entity . '|' . $bucket;
    return substr(hash('sha256', $raw), 0, 64);
}

function relicheck_email_already_sent(string $idempotency_key): bool
{
    $st = db()->prepare('SELECT 1 FROM email_logs WHERE idempotency_key = :k LIMIT 1');
    $st->execute([':k' => $idempotency_key]);
    return (bool)$st->fetch();
}

function relicheck_email_insert_log(array $tpl, array $r, array $rendered, string $idempotency_key, string $event_key, array $ctx): int
{
    $pdo = db();
    $hash = hash('sha256', (string)$rendered['html']);
    $sanitized = substr((string)$rendered['html'], 0, 524288); // ~512KB cap
    $payload = json_encode($rendered['sanitized_payload'], JSON_UNESCAPED_UNICODE);

    $sql = 'INSERT INTO email_logs
        (event_key, template_id, template_version, department_id,
         sender_email, sender_display_name,
         recipient_user_id, recipient_email, recipient_role,
         customer_account_id, subject, preview,
         body_snapshot_hash, sanitized_body, dynamic_payload,
         idempotency_key, status)
        VALUES
        (:event_key, :tpl_id, :tpl_ver, :dept_id,
         :sender_email, :sender_name,
         :ruid, :remail, :rrole,
         :acct_id, :subject, :preview,
         :hash, :body, :payload,
         :idem, "queued")';
    $st = $pdo->prepare($sql);
    $st->execute([
        ':event_key'    => $event_key,
        ':tpl_id'       => (int)$tpl['id'],
        ':tpl_ver'      => (int)$tpl['current_version'],
        ':dept_id'      => (int)$tpl['department_id'],
        ':sender_email' => (string)$rendered['sender_email'],
        ':sender_name'  => (string)$rendered['sender_display_name'],
        ':ruid'         => $r['user_id'] !== null ? (int)$r['user_id'] : null,
        ':remail'       => (string)$r['email'],
        ':rrole'        => (string)($r['role'] ?? ''),
        ':acct_id'      => isset($r['account_id']) && $r['account_id'] !== null ? (int)$r['account_id'] : null,
        ':subject'      => (string)$rendered['subject'],
        ':preview'      => (string)$rendered['preview'],
        ':hash'         => $hash,
        ':body'         => $sanitized,
        ':payload'      => $payload,
        ':idem'         => $idempotency_key,
    ]);
    return (int)$pdo->lastInsertId();
}

function relicheck_email_enqueue(int $email_log_id): void
{
    // due_at uses MySQL NOW() to avoid the IONOS PHP/MySQL timezone mismatch
    // (PHP DateTimeImmutable here would drift relative to the DB clock).
    $sql = 'INSERT INTO email_send_jobs (email_log_id, status, due_at)
            VALUES (:id, "pending", NOW())
            ON DUPLICATE KEY UPDATE status = "pending", due_at = NOW(), attempts = 0';
    db()->prepare($sql)->execute([':id' => $email_log_id]);
}

function relicheck_first_name(string $full_name): string
{
    $full_name = trim($full_name);
    if ($full_name === '') return 'there';
    $parts = preg_split('/\s+/', $full_name);
    return $parts[0] ?: 'there';
}

// ---------------------------------------------------------------------------
// Public helper for code that needs to write an audit row.
// ---------------------------------------------------------------------------
function relicheck_email_audit(?int $actor_user_id, string $action, string $target_type, ?int $target_id, ?array $before = null, ?array $after = null): void
{
    $sql = 'INSERT INTO email_audit_logs
            (actor_user_id, action, target_type, target_id, before_json, after_json, ip_hash)
            VALUES (:a, :act, :tt, :tid, :bj, :aj, :ip)';
    $st = db()->prepare($sql);
    $st->execute([
        ':a'   => $actor_user_id,
        ':act' => $action,
        ':tt'  => $target_type,
        ':tid' => $target_id,
        ':bj'  => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
        ':aj'  => $after  ? json_encode($after,  JSON_UNESCAPED_UNICODE) : null,
        ':ip'  => function_exists('ip_hash') ? ip_hash() : null,
    ]);
}
