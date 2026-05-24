<?php
// POST /api/admin/customers/remove_promo.php
// Body: { customer_id?: int, customer_email?: string, code?: string, reason: string }
//
// Removes a promo code from a customer. Specifically: drops their tier
// back to free (or to whatever an active subscription says, if one exists),
// and writes an admin_audit row recording the revoke.
//
// The promo_redemptions row itself is NOT deleted - it stays as the historical
// record of when the code was originally redeemed. We rely on the
// `tier_changes` table (written by set_user_tier) plus `admin_audit` for
// the revoke trail.
//
// If `code` is provided, only revoke if that specific code is the customer's
// most recent redemption. Otherwise just reset their tier regardless.

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
$code          = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', trim((string)($body['code'] ?? ''))) ?: '');
$reason        = clean_string((string)($body['reason'] ?? ''), 500);

if ($customerId <= 0 && $customerEmail === '') fail('bad_input', 'Provide customer_id or customer_email.');
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

// Find the most recent redemption (and which code).
$rstmt = $pdo->prepare(
    'SELECT pr.id, pr.code_id, pc.code, pr.tier_granted, pr.expires_at, pr.redeemed_at
       FROM promo_redemptions pr
       JOIN promo_codes pc ON pc.id = pr.code_id
      WHERE pr.user_id = :uid
      ORDER BY pr.redeemed_at DESC
      LIMIT 1'
);
$rstmt->execute([':uid' => $cuid]);
$lastRedemption = $rstmt->fetch();

if ($code !== '' && (!$lastRedemption || strtoupper($lastRedemption['code']) !== $code)) {
    fail('code_mismatch', 'That code is not the customer\'s most recent redemption.');
}
if (!$lastRedemption) fail('no_promo', 'This customer has no promo to remove.');

// Determine the natural fallback tier.
//   - If they have an active Stripe subscription, fall back to that tier.
//   - Otherwise, fall back to 'free' with no expiry.
$fallbackTier    = 'free';
$fallbackExpires = null;
try {
    $sstmt = $pdo->prepare(
        "SELECT tier, status, current_period_end
           FROM subscriptions
          WHERE user_id = :uid AND status IN ('active','trialing','past_due')
       ORDER BY (status = 'active') DESC, updated_at DESC LIMIT 1"
    );
    $sstmt->execute([':uid' => $cuid]);
    if ($sub = $sstmt->fetch()) {
        $fallbackTier    = (string)$sub['tier'];
        $fallbackExpires = $sub['current_period_end'] ?: null;
    }
} catch (Throwable $e) {
    // subscriptions table may be absent on this database; the default ('free') still works.
}

$beforeTierLabel = $lastRedemption['code'] . ' -> ' . $lastRedemption['tier_granted'] .
                   ($lastRedemption['expires_at'] ? ' (through ' . substr((string)$lastRedemption['expires_at'], 0, 10) . ')' : ' (permanent)');

set_user_tier($cuid, $fallbackTier, $fallbackExpires, 'admin_promo_revoke', 'admin:' . $admin['email'] . ' revoked:' . $lastRedemption['code']);

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    'Removed promo code',
    'promo',
    [
        'severity'     => 'warn',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . $cuid,
        'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
        'before'       => $beforeTierLabel,
        'after'        => $fallbackTier . ($fallbackExpires ? ' (through ' . substr($fallbackExpires, 0, 10) . ')' : ''),
        'reason'       => $reason,
    ]
);

json_out([
    'ok'             => true,
    'removed_code'   => $lastRedemption['code'],
    'fallback_tier'  => $fallbackTier,
    'fallback_until' => $fallbackExpires,
    'message'        => 'Removed ' . $lastRedemption['code'] . ' from ' . $customer['email'] . '. Tier now: ' . $fallbackTier . '.',
]);
