<?php
// POST /api/admin/email/compose.php
//
// Body shapes:
//
// Single:
//   { mode: "single",
//     recipient_email: "alice@acme.org",        // OR recipient_user_id
//     recipient_user_id: 123,
//     department: "support",
//     subject: "Following up",
//     preview: "Quick note about your recent ticket",
//     body_html: "<p>Hi Alice,</p>...",
//     body_text: "Hi Alice, ..." }
//
// Bulk:
//   { mode: "bulk",
//     audience: "all",                          // or "active" / future
//     department: "marketing",
//     subject: "Spring product roundup",
//     preview: "What's new in ReliCheck this season",
//     body_html: "<p>...</p>",
//     body_text: "...",
//     dry_run: 1|0,
//     limit: 0 }                                 // optional, for smoke tests
//
// All admins may use both modes (per Donald's choice). Every send writes one
// audit row capturing actor, mode, audience, and queued count.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_email_dispatcher.php';
require_once __DIR__ . '/../../_email_compose.php';

require_method('POST');
check_origin();

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in required.', 401);
if (!is_admin_user($user)) fail('forbidden', 'Admins only.', 403);

$body = read_json_body();

$mode      = (string)($body['mode'] ?? 'single');
$dept_code = clean_string((string)($body['department'] ?? ''), 32);
$subject   = clean_string((string)($body['subject']    ?? ''), 255);
$preview   = clean_string((string)($body['preview']    ?? ''), 255);
$body_html = (string)($body['body_html'] ?? '');
$body_text = (string)($body['body_text'] ?? '');

if ($subject === '')   fail('bad_subject', 'Subject is required.');
if ($body_html === '' && $body_text === '') fail('bad_body', 'Body is required.');
if (!in_array($mode, ['single', 'bulk'], true)) fail('bad_mode', 'mode must be "single" or "bulk".');

$dept = relicheck_email_compose_resolve_department($dept_code);
if (!$dept) fail('bad_department', 'Unknown department. Must be one of the eleven official ReliCheck senders.');

$batch_tag = clean_string((string)($body['batch_tag'] ?? ''), 64);
if ($batch_tag === '') {
    $batch_tag = $mode . ':' . date('Y-m-d-His') . ':' . substr(bin2hex(random_bytes(3)), 0, 6);
}

$pdo = db();
$queued = 0;
$skipped = 0;

if ($mode === 'single') {
    $recipient_email   = strtolower(clean_string((string)($body['recipient_email'] ?? ''), 255));
    $recipient_user_id = (int)($body['recipient_user_id'] ?? 0);
    $recipient_name    = '';

    // If a user id was supplied, prefer it as source of truth.
    if ($recipient_user_id > 0) {
        $st = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $recipient_user_id]);
        $u = $st->fetch();
        if (!$u) fail('user_not_found', 'No customer found with that ID.', 404);
        $recipient_email = (string)$u['email'];
        $recipient_name  = (string)$u['name'];
    } elseif ($recipient_email !== '') {
        // Look up by email so we can attach the user_id if it exists.
        $st = $pdo->prepare('SELECT id, email, name FROM users WHERE email = :e LIMIT 1');
        $st->execute([':e' => $recipient_email]);
        $u = $st->fetch();
        if ($u) {
            $recipient_user_id = (int)$u['id'];
            $recipient_name    = (string)$u['name'];
        }
    } else {
        fail('bad_recipient', 'recipient_email or recipient_user_id is required for single mode.');
    }

    $log_id = relicheck_email_compose_queue_one(
        $dept,
        $recipient_email,
        $recipient_user_id > 0 ? $recipient_user_id : null,
        $recipient_name !== '' ? $recipient_name : null,
        $subject, $preview, $body_html, $body_text,
        (int)$user['id'], $batch_tag
    );

    if ($log_id) {
        $queued = 1;
        relicheck_email_audit((int)$user['id'], 'email.compose.single', 'email_logs', $log_id, null, [
            'department' => $dept_code,
            'recipient'  => $recipient_email,
            'subject'    => $subject,
            'batch_tag'  => $batch_tag,
        ]);
    } else {
        $skipped = 1;
    }

    json_out([
        'ok'            => true,
        'mode'          => 'single',
        'queued'        => $queued,
        'skipped'       => $skipped,
        'email_log_id'  => $log_id,
        'batch_tag'     => $batch_tag,
    ]);
}

// ---- Bulk ----
$audience = (string)($body['audience'] ?? 'all');
$dry_run  = !empty($body['dry_run']);
$limit    = (int)($body['limit'] ?? 0);

$where = "email <> ''";
if ($audience === 'active') {
    $where .= ' AND last_login_at IS NOT NULL AND locked_at IS NULL';
}

$sql = "SELECT id, email, name FROM users WHERE $where ORDER BY id ASC";
if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;

$rows = $pdo->query($sql)->fetchAll();
$total = count($rows);

if ($dry_run) {
    json_out([
        'ok'        => true,
        'mode'      => 'bulk',
        'dry_run'   => true,
        'audience'  => $audience,
        'total'     => $total,
        'message'   => "Would queue $total emails. Re-run with dry_run=0 to commit.",
    ]);
}

foreach ($rows as $u) {
    $log_id = relicheck_email_compose_queue_one(
        $dept,
        (string)$u['email'],
        (int)$u['id'],
        (string)$u['name'],
        $subject, $preview, $body_html, $body_text,
        (int)$user['id'], $batch_tag
    );
    if ($log_id) $queued++;
    else         $skipped++;
}

relicheck_email_audit((int)$user['id'], 'email.compose.bulk', 'email_logs', null, null, [
    'department' => $dept_code,
    'audience'   => $audience,
    'subject'    => $subject,
    'total'      => $total,
    'queued'     => $queued,
    'skipped'    => $skipped,
    'batch_tag'  => $batch_tag,
]);

json_out([
    'ok'         => true,
    'mode'       => 'bulk',
    'audience'   => $audience,
    'total'      => $total,
    'queued'     => $queued,
    'skipped'    => $skipped,
    'batch_tag'  => $batch_tag,
    'message'    => "Queued $queued of $total. Cron worker drains at ~25/min.",
]);
