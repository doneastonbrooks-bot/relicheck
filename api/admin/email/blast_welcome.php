<?php
// POST /api/admin/email/blast_welcome.php
// Body: { dry_run: 1|0, batch_tag: "welcome_reblast:2026-05-10", limit: 0 }
//
// One-shot admin tool: queues the Account Confirmed template for every
// active customer (last_login_at IS NOT NULL AND locked_at IS NULL).
//
// dry_run = 1 returns the count of recipients without queueing anything.
// dry_run = 0 actually queues. The cron worker (api/email/queue_run.php)
// drains the queue at the normal rate, so a 1000-user blast takes roughly
// 40 minutes of cron ticks at 25 sends per minute.
//
// Each blast carries a unique batch_tag (default = today's date) so the
// dispatcher's idempotency key differs from the user's original signup
// confirmation. Re-running with the same tag is a no-op for any address
// that was already queued under that tag.
//
// Audit row written per dispatch via the dispatcher's normal logging.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_email_dispatcher.php';

require_method('POST');
check_origin();

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in required.', 401);
if (!is_admin_user($user)) fail('forbidden', 'Admins only.', 403);

$body     = read_json_body();
$dry_run  = !empty($body['dry_run']);
$tag      = clean_string((string)($body['batch_tag'] ?? ''), 64);
$limit    = (int)($body['limit'] ?? 0);
if ($tag === '') $tag = 'welcome_reblast:' . date('Y-m-d');

$pdo = db();

// Active customer filter.
$sql = "SELECT id, email, name
        FROM users
        WHERE last_login_at IS NOT NULL
          AND locked_at IS NULL
          AND email <> ''
        ORDER BY id ASC";
if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;

$stmt = $pdo->query($sql);
$users = $stmt->fetchAll();
$total = count($users);

if ($dry_run) {
    json_out([
        'ok'        => true,
        'dry_run'   => true,
        'batch_tag' => $tag,
        'total'     => $total,
        'message'   => "Would queue $total emails. Re-run with dry_run=0 to commit.",
    ]);
}

$queued     = 0;
$skipped    = 0;
$violations = 0;

foreach ($users as $u) {
    $first = trim(explode(' ', (string)$u['name'])[0] ?? '');
    if ($first === '') $first = 'there';

    $result = relicheck_email_dispatch('user.email_verified', [
        'user_id'               => (int)$u['id'],
        'account_id'            => (int)$u['id'],
        'idempotency_entity_id' => $tag,
        'payload'               => [
            'first_name' => $first,
            'email'      => (string)$u['email'],
        ],
    ]);
    $queued     += (int)($result['queued']  ?? 0);
    $skipped    += (int)($result['skipped'] ?? 0);
    $violations += count($result['privacy_violations'] ?? []);
}

relicheck_email_audit((int)$user['id'], 'email.blast', 'email_logs', null, null, [
    'event_key' => 'user.email_verified',
    'template'  => 'customer.welcome.account_confirmed',
    'batch_tag' => $tag,
    'total'     => $total,
    'queued'    => $queued,
    'skipped'   => $skipped,
]);

json_out([
    'ok'                 => true,
    'batch_tag'          => $tag,
    'total_candidates'   => $total,
    'queued'             => $queued,
    'skipped'            => $skipped,
    'privacy_violations' => $violations,
    'message'            => "Queued $queued of $total. The cron worker will deliver at ~25/min.",
]);
