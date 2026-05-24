<?php
// POST /api/admin/customers/flag.php
// Body: { customer_id?: int, customer_email?: string,
//         flagged: bool, reason?: string }
//
// Marks or clears the "flagged for review" state on a customer account.
// Unlike Lock, flagging does NOT prevent sign-in; it just signals to
// upper management that the account needs a closer look. Audit row
// written with before/after state.
//
// Requires the Phase 23 migration (users.flagged_at + users.flagged_reason).

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
$flag          = !empty($body['flagged']);
$reason        = clean_string((string)($body['reason'] ?? ''), 500);

if ($customerId <= 0 && $customerEmail === '') fail('bad_input', 'Provide customer_id or customer_email.');
if ($flag && $reason === '') fail('reason_required', 'A reason is required to flag an account.');

$pdo = db();

// Bail clearly if the migration hasn't been run.
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'flagged_at'")->fetchColumn();
} catch (Throwable $e) {
    fail('schema_error', 'Could not check schema: ' . $e->getMessage(), 500);
}
if (!$col) fail('migration_missing', 'Phase 23 migration not applied. The users.flagged_at column does not exist yet.', 500);

if ($customerId > 0) {
    $stmt = $pdo->prepare('SELECT id, email, name, flagged_at FROM users WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
} else {
    if (!valid_email($customerEmail)) fail('bad_email', 'Invalid customer_email.');
    $stmt = $pdo->prepare('SELECT id, email, name, flagged_at FROM users WHERE email = :em');
    $stmt->execute([':em' => $customerEmail]);
}
$customer = $stmt->fetch();
if (!$customer) fail('not_found', 'Customer not found.', 404);
$cuid = (int)$customer['id'];

$alreadyFlagged = !empty($customer['flagged_at']);
if ($flag === $alreadyFlagged) {
    fail('no_change', $flag ? 'Account is already flagged.' : 'Account is not flagged.');
}

if ($flag) {
    $up = $pdo->prepare('UPDATE users SET flagged_at = NOW(), flagged_reason = :r WHERE id = :id');
    $up->execute([':r' => $reason, ':id' => $cuid]);
} else {
    $up = $pdo->prepare('UPDATE users SET flagged_at = NULL, flagged_reason = NULL WHERE id = :id');
    $up->execute([':id' => $cuid]);
}

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    $flag ? 'Flagged account for review' : 'Cleared account flag',
    'customer',
    [
        'severity'     => 'warn',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . $cuid,
        'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
        'before'       => $alreadyFlagged ? 'Flagged' : 'Unflagged',
        'after'        => $flag ? 'Flagged' : 'Unflagged',
        'reason'       => $reason !== '' ? $reason : null,
    ]
);

json_out([
    'ok'      => true,
    'flagged' => $flag,
    'message' => $flag
        ? 'Flagged ' . $customer['email'] . ' for review.'
        : 'Cleared flag on ' . $customer['email'] . '.',
]);
