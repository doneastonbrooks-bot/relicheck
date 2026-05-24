<?php
// POST /api/promo/redeem.php
// Body: { "code": "<string>" }
//
// Redeems a promotional code and upgrades the user's tier accordingly.
// Rules:
//   * Code must exist, be active, not past its own expiry, and have remaining uses.
//   * Each user can redeem each code at most once.
//   * If the user is already on a paid tier of equal or higher rank, the
//     redemption is rejected (we don't let promos clobber paid plans).
//   * Otherwise the user is moved to the code's tier; expiry is computed
//     from duration_days (or NULL for permanent).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

// 20 redemption attempts per user per hour (covers typos + brute-force probes).
check_rate_limit('promo_redeem:user:' . (int)$user['id'], 20, 3600);

$body = read_json_body();
$code = strtoupper(trim((string)($body['code'] ?? '')));
$code = preg_replace('/[^A-Z0-9_-]/', '', $code) ?? '';
if ($code === '' || strlen($code) < 3 || strlen($code) > 40) {
    fail('bad_code', 'Enter a valid code.');
}

$pdo = db();
$pdo->beginTransaction();
try {
    // is_beta_cohort is a Phase 171 column; try to select it but tolerate
    // older installs where it doesn't exist.
    try {
        $stmt = $pdo->prepare(
            'SELECT id, code, tier_key, duration_days, max_uses, uses_count,
                    expires_at, is_active, is_beta_cohort
               FROM promo_codes
              WHERE code = :c
              FOR UPDATE'
        );
        $stmt->execute([':c' => $code]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $stmt = $pdo->prepare(
            'SELECT id, code, tier_key, duration_days, max_uses, uses_count,
                    expires_at, is_active
               FROM promo_codes
              WHERE code = :c
              FOR UPDATE'
        );
        $stmt->execute([':c' => $code]);
        $row = $stmt->fetch();
    }

    if (!$row) {
        $pdo->rollBack();
        fail('not_found', 'That code is not valid.', 404);
    }
    if (!(int)$row['is_active']) {
        $pdo->rollBack();
        fail('inactive', 'That code has been deactivated.');
    }
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
        $pdo->rollBack();
        fail('expired', 'That code has expired.');
    }
    if ($row['max_uses'] !== null && (int)$row['uses_count'] >= (int)$row['max_uses']) {
        $pdo->rollBack();
        fail('exhausted', 'That code has reached its maximum number of redemptions.');
    }

    // Has this user already redeemed this code?
    $check = $pdo->prepare('SELECT id FROM promo_redemptions WHERE code_id = :c AND user_id = :u');
    $check->execute([':c' => $row['id'], ':u' => $user['id']]);
    if ($check->fetch()) {
        $pdo->rollBack();
        fail('already_redeemed', 'You have already redeemed this code.');
    }

    // Tier comparison.
    $catalog = tier_catalog();
    $currentTier = tier_for_user((int)$user['id']);
    $newTierKey = (string)$row['tier_key'];
    if (!isset($catalog[$newTierKey])) {
        $pdo->rollBack();
        fail('bad_tier', 'This code references an unknown tier.');
    }

    // If the user is already at or above the promo tier rank with no expiry
    // (or expiry past the new code's duration), reject.
    $newRank = (int)$catalog[$newTierKey]['rank'];
    $curRank = (int)$currentTier['rank'];
    $curExpiresAt = $currentTier['tier_expires_at'];

    if ($curRank > $newRank) {
        $pdo->rollBack();
        fail('higher_tier_active', "You're already on a higher tier (" . $currentTier['tier_label'] . "). This code grants " . $catalog[$newTierKey]['name'] . ".");
    }

    // Compute the new expiry. Stack on existing expiry if same tier; otherwise
    // start fresh. Permanent (duration NULL) always wins.
    $now = time();
    $newExpiresAt = null;
    if ($row['duration_days'] !== null) {
        $base = $now;
        if ($curRank === $newRank && $curExpiresAt) {
            $curTs = strtotime((string)$curExpiresAt);
            if ($curTs && $curTs > $now) $base = $curTs;
        }
        $newExpiresAt = date('Y-m-d H:i:s', $base + ((int)$row['duration_days'] * 86400));
    }

    // Apply.
    set_user_tier((int)$user['id'], $newTierKey, $newExpiresAt, 'promo', $code);

    $pdo->prepare(
        'INSERT INTO promo_redemptions (code_id, user_id, tier_granted, expires_at)
         VALUES (:c, :u, :t, :e)'
    )->execute([
        ':c' => $row['id'],
        ':u' => $user['id'],
        ':t' => $newTierKey,
        ':e' => $newExpiresAt,
    ]);
    $pdo->prepare('UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = :id')
        ->execute([':id' => $row['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

json_out([
    'ok'             => true,
    'tier'           => $newTierKey,
    'tier_label'     => $catalog[$newTierKey]['name'],
    'expires_at'     => $newExpiresAt,                   // NULL = permanent
    // Phase 171: signal that this was a closed-beta cohort code, so the
    // signup flow can route the user into MM Studio rather than the legacy
    // app default landing page.
    'is_beta_cohort' => isset($row['is_beta_cohort']) ? ((int)$row['is_beta_cohort'] === 1) : false,
    'message'        => $newExpiresAt
        ? "Code redeemed. You're on " . $catalog[$newTierKey]['name'] . " through " . date('M j, Y', strtotime($newExpiresAt)) . "."
        : "Code redeemed. You're on " . $catalog[$newTierKey]['name'] . " permanently.",
]);
