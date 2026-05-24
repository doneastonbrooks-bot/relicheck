<?php
// GET  /api/admin/beta/cohort.php
//   List all beta-cohort promo codes with redemption counts.
//
// POST /api/admin/beta/cohort.php   action=create
//   { code?: string, tier_key?: 'researcher', duration_days?: 180, max_uses?: 25, notes?: string }
//   Defaults: code='BETA-MMSTUDIO-2026A', tier_key='researcher', duration_days=180, max_uses=25.
//
// POST /api/admin/beta/cohort.php   action=deactivate
//   { id: int }
//
// Phase 171. Admin-only. Beta codes are marked with is_beta_cohort=1 so
// they don't pollute the regular promo list.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET', 'POST');
$admin = require_admin();
$pdo   = db();

// Verify the promo table + new column exist; if not, return a friendly
// payload instead of HTML 500.
try {
    $has = (bool)$pdo->query("SHOW TABLES LIKE 'promo_codes'")->fetchColumn();
} catch (Throwable $e) { $has = false; }
if (!$has) {
    json_out([
        'ok'      => false,
        'codes'   => [],
        'warning' => 'promo_codes table is missing. Apply schema_phase14.sql.',
    ]);
}
try {
    $hasFlag = (bool)$pdo->query("SHOW COLUMNS FROM promo_codes LIKE 'is_beta_cohort'")->fetchColumn();
} catch (Throwable $e) { $hasFlag = false; }
if (!$hasFlag) {
    json_out([
        'ok'      => false,
        'codes'   => [],
        'warning' => 'is_beta_cohort column missing. Apply schema_phase171.sql.',
    ]);
}

// ===================== GET ===========================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $stmt = $pdo->query(
        "SELECT pc.id, pc.code, pc.tier_key, pc.duration_days, pc.max_uses,
                pc.uses_count, pc.expires_at, pc.notes, pc.is_active,
                pc.created_at,
                (SELECT COUNT(*) FROM promo_redemptions pr WHERE pr.code_id = pc.id) AS redeemed_count
           FROM promo_codes pc
          WHERE pc.is_beta_cohort = 1
       ORDER BY pc.created_at DESC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // For each cohort code, list the users who redeemed so admin can see
    // the cohort roster.
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $usersStmt = $pdo->prepare(
            'SELECT pr.id AS redemption_id, pr.user_id, pr.tier_granted,
                    pr.expires_at, pr.redeemed_at,
                    u.email, u.name
               FROM promo_redemptions pr
          LEFT JOIN users u ON u.id = pr.user_id
              WHERE pr.code_id = :c
           ORDER BY pr.redeemed_at DESC'
        );
        $usersStmt->execute([':c' => $id]);
        $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out[] = [
            'id'             => $id,
            'code'           => (string)$r['code'],
            'tier_key'       => (string)$r['tier_key'],
            'duration_days'  => $r['duration_days'] !== null ? (int)$r['duration_days'] : null,
            'max_uses'       => $r['max_uses']      !== null ? (int)$r['max_uses']      : null,
            'uses_count'     => (int)$r['uses_count'],
            'redeemed_count' => (int)$r['redeemed_count'],
            'expires_at'     => $r['expires_at'],
            'notes'          => $r['notes'],
            'is_active'      => (int)$r['is_active'] === 1,
            'created_at'     => $r['created_at'],
            'users'          => array_map(function ($u) {
                return [
                    'user_id'      => (int)$u['user_id'],
                    'email'        => $u['email'],
                    'name'         => $u['name'],
                    'tier_granted' => $u['tier_granted'],
                    'expires_at'   => $u['expires_at'],
                    'redeemed_at'  => $u['redeemed_at'],
                ];
            }, $users),
        ];
    }
    json_out(['ok' => true, 'codes' => $out]);
}

// ===================== POST ==========================================
check_origin();
$body   = read_json_body();
$action = (string)($body['action'] ?? '');

if ($action === 'create') {
    $codeRaw = isset($body['code']) ? (string)$body['code'] : '';
    $codeRaw = strtoupper(trim($codeRaw));
    $codeRaw = preg_replace('/[^A-Z0-9_-]/', '', $codeRaw) ?? '';
    if ($codeRaw === '') $codeRaw = 'BETA-MMSTUDIO-2026A';
    if (strlen($codeRaw) < 3 || strlen($codeRaw) > 40) {
        fail('bad_code', 'Code must be 3-40 chars, letters/digits/dashes only.', 400);
    }

    $tierKey = isset($body['tier_key']) ? clean_string((string)$body['tier_key'], 32) : 'researcher';
    if (!in_array($tierKey, ['researcher','professional','business'], true)) {
        fail('bad_tier', 'Tier must be researcher, professional, or business.', 400);
    }

    $duration = isset($body['duration_days']) ? (int)$body['duration_days'] : 180;
    if ($duration < 1 || $duration > 730) $duration = 180;

    $maxUses = isset($body['max_uses']) ? (int)$body['max_uses'] : 25;
    if ($maxUses < 1 || $maxUses > 1000) $maxUses = 25;

    $notes = isset($body['notes']) ? clean_string((string)$body['notes'], 500) : 'Closed beta cohort - Mixed-Methods Studio.';

    // Refuse to clobber an existing code with the same name.
    $dup = $pdo->prepare('SELECT id FROM promo_codes WHERE code = :c LIMIT 1');
    $dup->execute([':c' => $codeRaw]);
    if ($dup->fetch()) fail('code_exists', 'A promo code with that name already exists. Pick another name.', 409);

    $pdo->prepare(
        'INSERT INTO promo_codes
            (code, tier_key, duration_days, max_uses, uses_count, expires_at, notes,
             is_active, is_beta_cohort, created_by)
         VALUES
            (:c, :t, :d, :m, 0, NULL, :n, 1, 1, :cb)'
    )->execute([
        ':c'  => $codeRaw,
        ':t'  => $tierKey,
        ':d'  => $duration,
        ':m'  => $maxUses,
        ':n'  => $notes !== '' ? $notes : null,
        ':cb' => (int)$admin['id'],
    ]);
    $newId = (int)$pdo->lastInsertId();
    json_out([
        'ok'      => true,
        'created' => [
            'id'             => $newId,
            'code'           => $codeRaw,
            'tier_key'       => $tierKey,
            'duration_days'  => $duration,
            'max_uses'       => $maxUses,
            'notes'          => $notes,
            'signup_url'     => 'https://relichecksurvey.com/signup.html?promo=' . urlencode($codeRaw),
        ],
    ]);
}

if ($action === 'deactivate') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) fail('bad_id', 'Missing code id.', 400);
    $own = $pdo->prepare('SELECT id FROM promo_codes WHERE id = :i AND is_beta_cohort = 1 LIMIT 1');
    $own->execute([':i' => $id]);
    if (!$own->fetchColumn()) fail('not_found', 'Beta cohort code not found.', 404);
    $pdo->prepare('UPDATE promo_codes SET is_active = 0 WHERE id = :i')->execute([':i' => $id]);
    json_out(['ok' => true, 'deactivated' => $id]);
}

fail('bad_action', 'Unknown action. Use create or deactivate.', 400);
