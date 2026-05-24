<?php
// POST /api/admin/customers/lock.php
// Body: { customer_id?: int, customer_email?: string,
//         locked: bool, reason?: string }
//
// Locks or unlocks a customer account. When locked, sign-in is refused
// at the login endpoint until an admin unlocks. Audit row written with
// before/after state.
//
// Requires the Phase 21 migration (users.locked_at + users.locked_reason).

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';

require_method('POST');
check_origin();
$admin = require_admin();

$body          = read_json_body();
$customerId    = (int)($body['customer_id'] ?? 0);
$customerEmail = strtolower(clean_string((string)($body['customer_email'] ?? ''), 255));
$lock          = !empty($body['locked']);
$reason        = clean_string((string)($body['reason'] ?? ''), 500);

if ($customerId <= 0 && $customerEmail === '') fail('bad_input', 'Provide customer_id or customer_email.');
if ($lock && $reason === '') fail('reason_required', 'A reason is required to lock an account.');

$pdo = db();

// Bail clearly if the migration hasn't been run, instead of crashing.
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'locked_at'")->fetchColumn();
} catch (Throwable $e) {
    fail('schema_error', 'Could not check schema: ' . $e->getMessage(), 500);
}
if (!$col) fail('migration_missing', 'Phase 21 migration not applied. The users.locked_at column does not exist yet.', 500);

if ($customerId > 0) {
    $stmt = $pdo->prepare('SELECT id, email, name, locked_at FROM users WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
} else {
    if (!valid_email($customerEmail)) fail('bad_email', 'Invalid customer_email.');
    $stmt = $pdo->prepare('SELECT id, email, name, locked_at FROM users WHERE email = :em');
    $stmt->execute([':em' => $customerEmail]);
}
$customer = $stmt->fetch();
if (!$customer) fail('not_found', 'Customer not found.', 404);
$cuid = (int)$customer['id'];

$alreadyLocked = !empty($customer['locked_at']);
if ($lock === $alreadyLocked) {
    fail('no_change', $lock ? 'Account is already locked.' : 'Account is already unlocked.');
}

if ($lock) {
    $up = $pdo->prepare('UPDATE users SET locked_at = NOW(), locked_reason = :r WHERE id = :id');
    $up->execute([':r' => $reason, ':id' => $cuid]);
} else {
    $up = $pdo->prepare('UPDATE users SET locked_at = NULL, locked_reason = NULL WHERE id = :id');
    $up->execute([':id' => $cuid]);
}

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    $lock ? 'Locked account' : 'Unlocked account',
    'security',
    [
        'severity'     => $lock ? 'warn' : 'info',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . $cuid,
        'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
        'before'       => $alreadyLocked ? 'Locked' : 'Unlocked',
        'after'        => $lock ? 'Locked' : 'Unlocked',
        'reason'       => $reason !== '' ? $reason : null,
    ]
);

json_out([
    'ok'      => true,
    'locked'  => $lock,
    'message' => $lock
        ? 'Locked ' . $customer['email'] . '. They cannot sign in until unlocked.'
        : 'Unlocked ' . $customer['email'] . '. Sign-in restored.',
]);
