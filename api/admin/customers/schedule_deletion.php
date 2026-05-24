<?php
// POST /api/admin/customers/schedule_deletion.php
// Body: { customer_id: 123, reason: "Requested by user", grace_days: 30 }
//
// Schedules a customer account for hard deletion after a grace window
// (default 30 days). Refuses to schedule:
//   - Permanent admin emails (the owner allowlist).
//   - Active staff users (the staff_users table).
//   - Accounts already scheduled for deletion (idempotent: returns the
//     existing schedule unchanged).
//
// Fires the dispatcher event account.deletion_requested so the customer
// receives the privacy-class notification immediately.
//
// Audit row written via the existing admin audit helper.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';

require_method('POST');
check_origin();

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in required.', 401);
if (!is_admin_user($user)) fail('forbidden', 'Admins only.', 403);

$body = read_json_body();
$customer_id = (int)($body['customer_id'] ?? 0);
$reason      = clean_string((string)($body['reason'] ?? ''), 500);
$grace_days  = (int)($body['grace_days'] ?? 30);
if ($customer_id <= 0)        fail('bad_customer_id', 'customer_id is required.');
if ($grace_days < 1 || $grace_days > 365) $grace_days = 30;

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT id, email, name, deletion_scheduled_at, deletion_grace_ends_at
     FROM users WHERE id = :id LIMIT 1'
);
$stmt->execute([':id' => $customer_id]);
$customer = $stmt->fetch();
if (!$customer) fail('customer_not_found', 'No customer with that ID.', 404);

// Refuse permanent admins.
$customer_email = strtolower((string)$customer['email']);
if (in_array($customer_email, relicheck_admin_emails(), true)) {
    fail('cannot_delete_admin', 'This account is on the permanent admin allowlist and cannot be scheduled for deletion.', 422);
}

// Refuse active staff (Phase 26 staff_users table). Wrapped in try/catch so a
// missing-table install still works.
try {
    $st = $pdo->prepare("SELECT 1 FROM staff_users WHERE email = :em AND status = 'active' LIMIT 1");
    $st->execute([':em' => $customer_email]);
    if ($st->fetch()) {
        fail('cannot_delete_staff', 'This account is an active staff user. Remove staff access first.', 422);
    }
} catch (Throwable $e) {
    // staff_users table doesn't exist; fall through.
}

// Idempotent: if already scheduled, return the existing schedule.
if (!empty($customer['deletion_scheduled_at'])) {
    json_out([
        'ok'                => true,
        'already_scheduled' => true,
        'customer_id'       => $customer_id,
        'scheduled_at'      => $customer['deletion_scheduled_at'],
        'grace_ends_at'     => $customer['deletion_grace_ends_at'],
    ]);
}

// Use SQL DATE_ADD(NOW(), INTERVAL N DAY) so the timestamp matches the
// MySQL clock, dodging the IONOS PHP/MySQL timezone gap.
$pdo->prepare(
    'UPDATE users
     SET deletion_scheduled_at      = NOW(),
         deletion_grace_ends_at     = DATE_ADD(NOW(), INTERVAL :gd DAY),
         deletion_requested_by_user_id = :rb,
         deletion_reason            = :rsn
     WHERE id = :id'
)->execute([
    ':gd'  => $grace_days,
    ':rb'  => (int)$user['id'],
    ':rsn' => $reason,
    ':id'  => $customer_id,
]);

// Re-read so we send the customer the actual end-date string MySQL stored.
$g = $pdo->prepare('SELECT deletion_scheduled_at, deletion_grace_ends_at FROM users WHERE id = :id');
$g->execute([':id' => $customer_id]);
$gr = $g->fetch();

// Audit row (uses the existing admin_audit helper signature).
admin_audit_log($user, 'customer.schedule_deletion', 'customers', [
    'severity'    => 'warn',
    'target_type' => 'user',
    'target_id'   => (string)$customer_id,
    'target_label'=> (string)$customer['email'],
    'reason'      => $reason !== '' ? $reason : null,
    'after'       => 'grace_ends_at=' . (string)$gr['deletion_grace_ends_at'] . ' grace_days=' . $grace_days,
]);

// Fire the privacy notification email. Wrapped in try/catch so a mailer hiccup
// never blocks the schedule from taking effect.
try {
    if (is_file(__DIR__ . '/../../_email_dispatcher.php')) {
        require_once __DIR__ . '/../../_email_dispatcher.php';
        relicheck_email_dispatch('account.deletion_requested', [
            'user_id'    => $customer_id,
            'account_id' => $customer_id,
            'idempotency_entity_id' => 'deletion-scheduled:' . $customer_id . ':' . (string)$gr['deletion_scheduled_at'],
            'payload'    => [
                'first_name'    => trim(explode(' ', (string)$customer['name'])[0] ?: 'there'),
                'email'         => (string)$customer['email'],
                'grace_ends_at' => (string)$gr['deletion_grace_ends_at'],
            ],
        ]);
    }
} catch (Throwable $e) {
    error_log('[relicheck] deletion-requested dispatch failed: ' . $e->getMessage());
}

json_out([
    'ok'             => true,
    'customer_id'    => $customer_id,
    'scheduled_at'   => (string)$gr['deletion_scheduled_at'],
    'grace_ends_at'  => (string)$gr['deletion_grace_ends_at'],
    'message'        => 'Account scheduled for deletion. Customer notified.',
]);
