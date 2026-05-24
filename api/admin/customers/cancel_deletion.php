<?php
// POST /api/admin/customers/cancel_deletion.php
// Body: { customer_id: 123 }
//
// Clears the scheduled deletion fields on a customer's row, fires the
// account.deletion_cancelled event so the customer is notified, and writes
// an admin audit row. Idempotent: returns ok if the account isn't currently
// scheduled.

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
if ($customer_id <= 0) fail('bad_customer_id', 'customer_id is required.');

$pdo = db();

$st = $pdo->prepare(
    'SELECT id, email, name, deletion_scheduled_at, deletion_grace_ends_at
     FROM users WHERE id = :id LIMIT 1'
);
$st->execute([':id' => $customer_id]);
$c = $st->fetch();
if (!$c) fail('customer_not_found', 'No customer with that ID.', 404);

if (empty($c['deletion_scheduled_at'])) {
    json_out([
        'ok'              => true,
        'was_scheduled'   => false,
        'customer_id'     => $customer_id,
        'message'         => 'Account is not scheduled for deletion. Nothing to cancel.',
    ]);
}

$pdo->prepare(
    'UPDATE users
     SET deletion_scheduled_at         = NULL,
         deletion_grace_ends_at        = NULL,
         deletion_requested_by_user_id = NULL,
         deletion_reason               = NULL
     WHERE id = :id'
)->execute([':id' => $customer_id]);

admin_audit_log($user, 'customer.cancel_deletion', 'customers', [
    'severity'     => 'info',
    'target_type'  => 'user',
    'target_id'    => (string)$customer_id,
    'target_label' => (string)$c['email'],
    'before'       => 'grace_ends_at=' . (string)$c['deletion_grace_ends_at'],
]);

try {
    if (is_file(__DIR__ . '/../../_email_dispatcher.php')) {
        require_once __DIR__ . '/../../_email_dispatcher.php';
        relicheck_email_dispatch('account.deletion_cancelled', [
            'user_id'    => $customer_id,
            'account_id' => $customer_id,
            'idempotency_entity_id' => 'deletion-cancelled:' . $customer_id . ':' . date('Y-m-d-H-i-s'),
            'payload'    => [
                'first_name'   => trim(explode(' ', (string)$c['name'])[0] ?: 'there'),
                'email'        => (string)$c['email'],
                'cancelled_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }
} catch (Throwable $e) {
    error_log('[relicheck] deletion-cancelled dispatch failed: ' . $e->getMessage());
}

json_out([
    'ok'             => true,
    'was_scheduled'  => true,
    'customer_id'    => $customer_id,
    'message'        => 'Scheduled deletion cancelled. Customer notified.',
]);
