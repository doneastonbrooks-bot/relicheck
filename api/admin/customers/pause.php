<?php
// POST /api/admin/customers/pause.php
// Body: { customer_id?: int, customer_email?: string,
//         paused: bool, reason?: string }
//
// Pauses or reactivates a customer account. Like Lock, a paused account
// cannot sign in, but the sign-in error message is gentler ("paused"
// instead of "locked") and points the customer at support to reactivate.
//
// Use Pause for customer-requested breaks / vacation / billing freezes.
// Use Lock for security incidents and TOS violations.
//
// Requires the Phase 24 migration (users.paused_at + users.paused_reason).

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
$pause         = !empty($body['paused']);
$reason        = clean_string((string)($body['reason'] ?? ''), 500);

if ($customerId <= 0 && $customerEmail === '') fail('bad_input', 'Provide customer_id or customer_email.');
if ($pause && $reason === '') fail('reason_required', 'A reason is required to pause an account.');

$pdo = db();

try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'paused_at'")->fetchColumn();
} catch (Throwable $e) {
    fail('schema_error', 'Could not check schema: ' . $e->getMessage(), 500);
}
if (!$col) fail('migration_missing', 'Phase 24 migration not applied. The users.paused_at column does not exist yet.', 500);

if ($customerId > 0) {
    $stmt = $pdo->prepare('SELECT id, email, name, paused_at FROM users WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
} else {
    if (!valid_email($customerEmail)) fail('bad_email', 'Invalid customer_email.');
    $stmt = $pdo->prepare('SELECT id, email, name, paused_at FROM users WHERE email = :em');
    $stmt->execute([':em' => $customerEmail]);
}
$customer = $stmt->fetch();
if (!$customer) fail('not_found', 'Customer not found.', 404);
$cuid = (int)$customer['id'];

$alreadyPaused = !empty($customer['paused_at']);
if ($pause === $alreadyPaused) {
    fail('no_change', $pause ? 'Account is already paused.' : 'Account is already active.');
}

if ($pause) {
    $up = $pdo->prepare('UPDATE users SET paused_at = NOW(), paused_reason = :r WHERE id = :id');
    $up->execute([':r' => $reason, ':id' => $cuid]);
} else {
    $up = $pdo->prepare('UPDATE users SET paused_at = NULL, paused_reason = NULL WHERE id = :id');
    $up->execute([':id' => $cuid]);
}

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    $pause ? 'Paused account' : 'Reactivated account',
    'customer',
    [
        'severity'     => $pause ? 'warn' : 'info',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . $cuid,
        'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
        'before'       => $alreadyPaused ? 'Paused' : 'Active',
        'after'        => $pause ? 'Paused' : 'Active',
        'reason'       => $reason !== '' ? $reason : null,
    ]
);

json_out([
    'ok'      => true,
    'paused'  => $pause,
    'message' => $pause
        ? 'Paused ' . $customer['email'] . '. They cannot sign in until reactivated.'
        : 'Reactivated ' . $customer['email'] . '. Sign-in restored.',
]);
