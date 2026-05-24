<?php
// POST /api/admin/customers/apply_promo.php
// Body: { customer_id?: int, customer_email?: string, code: string, reason?: string }
//
// Admin-applied promo code. Mirrors the user-side redeem flow but with
// staff overrides:
//   - "Already redeemed" still blocks (we never double-grant the same code).
//   - "Higher tier active" is overridden: staff can comp a customer.
//   - The redemption is recorded in promo_redemptions just like user redemptions.
//   - Tier is moved via set_user_tier() with reason='admin_promo'.
//   - admin_audit row is written with before/after tier and the reason field.

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
$rawCode       = strtoupper(trim((string)($body['code'] ?? '')));
$code          = preg_replace('/[^A-Z0-9_-]/', '', $rawCode) ?? '';
$reason        = clean_string((string)($body['reason'] ?? ''), 500);

if ($customerId <= 0 && $customerEmail === '') fail('bad_input', 'Provide customer_id or customer_email.');
if ($code === '' || strlen($code) < 3 || strlen($code) > 40) fail('bad_code', 'Enter a valid promo code.');
if ($reason === '') fail('reason_required', 'A reason is required for sensitive admin actions.');

$pdo = db();

// Resolve the customer.
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

$pdo->beginTransaction();
try {
    // Lock the promo row.
    $cstmt = $pdo->prepare(
        'SELECT id, code, tier_key, duration_days, max_uses, uses_count,
                expires_at, is_active
           FROM promo_codes WHERE code = :c FOR UPDATE'
    );
    $cstmt->execute([':c' => $code]);
    $promo = $cstmt->fetch();
    if (!$promo)                                                                   { $pdo->rollBack(); fail('not_found', 'Promo code not found.', 404); }
    if (!(int)$promo['is_active'])                                                 { $pdo->rollBack(); fail('inactive', 'That code has been deactivated.'); }
    if (!empty($promo['expires_at']) && strtotime((string)$promo['expires_at']) < time()) { $pdo->rollBack(); fail('expired', 'That code has expired.'); }
    if ($promo['max_uses'] !== null && (int)$promo['uses_count'] >= (int)$promo['max_uses']) { $pdo->rollBack(); fail('exhausted', 'That code has reached its maximum number of redemptions.'); }

    // No double-grant: even staff cannot apply the same code twice to the same customer.
    $check = $pdo->prepare('SELECT id FROM promo_redemptions WHERE code_id = :c AND user_id = :u');
    $check->execute([':c' => $promo['id'], ':u' => $cuid]);
    if ($check->fetch()) {
        $pdo->rollBack();
        fail('already_redeemed', 'This customer has already redeemed this code.');
    }

    $catalog = tier_catalog();
    $newTierKey = (string)$promo['tier_key'];
    if (!isset($catalog[$newTierKey])) {
        $pdo->rollBack();
        fail('bad_tier', 'This code references an unknown tier.');
    }

    $current = tier_for_user($cuid);
    $beforeTier = $current['tier_label'] ?? ($current['tier'] ?? 'free');

    // Compute new expiry. Stack on existing same-tier expiry as the user-side flow does.
    $now = time();
    $newExpiresAt = null;
    if ($promo['duration_days'] !== null) {
        $base = $now;
        if (((int)$current['rank'] === (int)$catalog[$newTierKey]['rank']) && !empty($current['tier_expires_at'])) {
            $curTs = strtotime((string)$current['tier_expires_at']);
            if ($curTs && $curTs > $now) $base = $curTs;
        }
        $newExpiresAt = date('Y-m-d H:i:s', $base + ((int)$promo['duration_days'] * 86400));
    }

    set_user_tier($cuid, $newTierKey, $newExpiresAt, 'admin_promo', 'admin:' . $admin['email'] . ' code:' . $code);

    $pdo->prepare(
        'INSERT INTO promo_redemptions (code_id, user_id, tier_granted, expires_at)
         VALUES (:c, :u, :t, :e)'
    )->execute([
        ':c' => $promo['id'],
        ':u' => $cuid,
        ':t' => $newTierKey,
        ':e' => $newExpiresAt,
    ]);
    $pdo->prepare('UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = :id')
        ->execute([':id' => $promo['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$afterLabel = $catalog[$newTierKey]['name'] . ($newExpiresAt ? ' (through ' . date('M j, Y', strtotime($newExpiresAt)) . ')' : ' (permanent)');

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    'Applied promo code',
    'promo',
    [
        'severity'     => 'info',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . $cuid,
        'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
        'before'       => $beforeTier,
        'after'        => $code . ' -> ' . $afterLabel,
        'reason'       => $reason,
    ]
);

json_out([
    'ok'         => true,
    'code'       => $code,
    'tier'       => $newTierKey,
    'expires_at' => $newExpiresAt,
    'message'    => 'Applied ' . $code . ' to ' . $customer['email'] . '. ' . $afterLabel,
]);
