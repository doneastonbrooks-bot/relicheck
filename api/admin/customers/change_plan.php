<?php
// POST /api/admin/customers/change_plan.php
// Body: { customer_id?: int, customer_email?: string,
//         tier_key: string, expires_at?: 'YYYY-MM-DD'|null,
//         reason: string }
//
// Admin-applied tier change. Sets the customer's tier directly via
// set_user_tier(), with an optional expiration. No promo_redemptions row
// is touched (this is not a code redemption - it's a manual override).
// Audit row is written with the before/after tier label and the reason.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';
require_once __DIR__ . '/../../_tiers.php';

require_method('POST');
check_origin();
$admin = require_admin();

$body          = read_json_body();
$customerId    = (int)($body['customer_id'] ?? 0);
$customerEmail = strtolower(clean_string((string)($body['customer_email'] ?? ''), 255));
$newTierKey    = clean_string((string)($body['tier_key'] ?? ''), 40);
$expiresIn     = trim((string)($body['expires_at'] ?? ''));
$reason        = clean_string((string)($body['reason'] ?? ''), 500);

if ($customerId <= 0 && $customerEmail === '') fail('bad_input', 'Provide customer_id or customer_email.');
if (!tier_known($newTierKey))                  fail('bad_tier', 'Unknown tier key.');
if ($reason === '')                            fail('reason_required', 'A reason is required for plan changes.');

$expiresAt = null;
if ($expiresIn !== '') {
    $ts = strtotime($expiresIn);
    if ($ts === false || $ts < time()) fail('bad_expires_at', 'expires_at must be a future date.');
    $expiresAt = date('Y-m-d H:i:s', $ts);
}

$pdo = db();

if ($customerId > 0) {
    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
} else {
    if (!valid_email($customerEmail)) fail('bad_email', 'Invalid customer_email.');
    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE email = :em');
    $stmt->execute([':em' => $customerEmail]);
}
$customer = $stmt->fetch();
if (!$customer) fail('not_found', 'Customer not found.', 404);
$cuid = (int)$customer['id'];

$catalog     = tier_catalog();
$current     = tier_for_user($cuid);
$beforeLabel = $current['tier_label']
    . (!empty($current['tier_expires_at']) ? ' (through ' . substr((string)$current['tier_expires_at'], 0, 10) . ')' : '');
$afterLabel  = $catalog[$newTierKey]['name']
    . ($expiresAt ? ' (through ' . substr($expiresAt, 0, 10) . ')' : ' (no expiry)');

// Refuse no-op changes - keeps the audit log meaningful.
if ($current['tier'] === $newTierKey
    && ($current['tier_expires_at'] ?? null) === $expiresAt) {
    fail('no_change', 'Customer is already on ' . $afterLabel . '.');
}

set_user_tier($cuid, $newTierKey, $expiresAt, 'admin_change_plan', 'admin:' . $admin['email']);

// Severity: downgrade is more sensitive than upgrade; both go to audit.
$beforeRank = (int)($current['rank'] ?? 0);
$afterRank  = (int)$catalog[$newTierKey]['rank'];
$severity   = ($afterRank < $beforeRank) ? 'warn' : 'info';

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    'Changed plan',
    'membership',
    [
        'severity'     => $severity,
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . $cuid,
        'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
        'before'       => $beforeLabel,
        'after'        => $afterLabel,
        'reason'       => $reason,
    ]
);

json_out([
    'ok'         => true,
    'tier'       => $newTierKey,
    'tier_label' => $catalog[$newTierKey]['name'],
    'expires_at' => $expiresAt,
    'message'    => 'Plan changed: ' . $beforeLabel . ' -> ' . $afterLabel,
]);
