<?php
// POST /api/admin/customers/extend_trial.php
// Body: { customer_id?: int, customer_email?: string, days: int, reason: string }
//
// Adds N days to the customer's tier_expires_at. Works for any tier with an
// expiration window (free trial, promo-granted access, etc.). The new expiry
// is computed as max(now, current_expires_at) + days, so admins can extend
// even if the trial has already lapsed.
//
// If the customer's current tier has no expiry (e.g., a paid Stripe
// subscription), this endpoint refuses with a clear message; "extending"
// a non-expiring tier is meaningless.

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
$days          = (int)($body['days'] ?? 0);
$reason        = clean_string((string)($body['reason'] ?? ''), 500);

if ($customerId <= 0 && $customerEmail === '') fail('bad_input', 'Provide customer_id or customer_email.');
if ($days < 1 || $days > 365) fail('bad_days', 'days must be between 1 and 365.');
if ($reason === '') fail('reason_required', 'A reason is required for sensitive admin actions.');

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

$current     = tier_for_user($cuid);
$currentTier = (string)($current['tier'] ?? 'free');
$currentExp  = $current['tier_expires_at'] ?? null;

// Refuse to "extend" a tier that has no expiry - that's a Stripe subscription
// or a permanent grant. Use Change Plan or comp via promo instead.
if ($currentTier !== 'free' && empty($currentExp)) {
    fail('no_expiry', 'This account is on ' . $current['tier_label'] . ' with no expiration. Use Change plan or apply a promo instead.');
}

$now = time();
$base = $currentExp ? max($now, strtotime((string)$currentExp)) : $now;
$newExp = date('Y-m-d H:i:s', $base + ($days * 86400));

$beforeLabel = $currentExp ? ('Expires ' . substr((string)$currentExp, 0, 10)) : 'No expiry';
$afterLabel  = 'Expires ' . substr($newExp, 0, 10);

set_user_tier(
    $cuid,
    $currentTier === 'free' ? 'researcher' : $currentTier, // free trial extension assumes a trial of the next-up tier
    $newExp,
    'admin_trial_extend',
    'admin:' . $admin['email'] . ' days:' . $days
);

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    'Extended trial',
    'customer',
    [
        'severity'     => 'info',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . $cuid,
        'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
        'before'       => $beforeLabel,
        'after'        => $afterLabel . ' (+' . $days . ' days)',
        'reason'       => $reason,
    ]
);

json_out([
    'ok'          => true,
    'days'        => $days,
    'new_expires' => $newExp,
    'message'     => 'Extended ' . $customer['email'] . ' by ' . $days . ' days. New expiry: ' . substr($newExp, 0, 10) . '.',
]);
