<?php
// GET /api/admin/customers/list.php
//   ?search=&status=&plan=&limit=200
//
// Returns the customer list for the admin panel. Reads the canonical
// users table reliably, then enriches each row with optional data from
// subscriptions, promo_redemptions/promo_codes, and account_members.
// Any optional table that fails or doesn't exist is reported in the
// `warnings` field and skipped, instead of crashing the whole endpoint.
//
// Survey content is never returned: only counts are allowed if needed
// by future slices.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');
require_admin();

$search = clean_string((string)($_GET['search'] ?? ''), 200);
$status = clean_string((string)($_GET['status'] ?? ''), 32);
$plan   = clean_string((string)($_GET['plan']   ?? ''), 32);
$limit  = max(1, min(500, (int)($_GET['limit']  ?? 200)));

$pdo = db();
$warnings = [];

/* ---------- 1. Pull the core users list (must succeed) ---------- */
$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.email LIKE :s OR u.name LIKE :s OR CAST(u.id AS CHAR) = :sid)';
    $params[':s']   = '%' . $search . '%';
    $params[':sid'] = preg_replace('/[^0-9]/', '', $search) ?: '0';
}

$userSql = 'SELECT u.id, u.email, u.name, u.created_at, u.last_login_at';
// Optional tier columns (added in Phase 8). If they're missing, we'll detect
// later and just leave the field NULL.
$hasTierCol = column_exists($pdo, 'users', 'tier');
$hasTierExp = column_exists($pdo, 'users', 'tier_expires_at');
$hasLocked  = column_exists($pdo, 'users', 'locked_at');
$hasFlagged = column_exists($pdo, 'users', 'flagged_at');
$hasPaused  = column_exists($pdo, 'users', 'paused_at');
if ($hasTierCol) $userSql .= ', u.tier';
if ($hasTierExp) $userSql .= ', u.tier_expires_at';
if ($hasLocked)  $userSql .= ', u.locked_at';
if ($hasFlagged) $userSql .= ', u.flagged_at';
if ($hasPaused)  $userSql .= ', u.paused_at';

$userSql .= ' FROM users u';
if ($where) $userSql .= ' WHERE ' . implode(' AND ', $where);
$userSql .= ' ORDER BY u.created_at DESC LIMIT ' . $limit;

try {
    $stmt = $pdo->prepare($userSql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} catch (Throwable $e) {
    fail('users_query_failed', 'Could not list users: ' . $e->getMessage(), 500);
}

if (!$users) {
    json_out(['ok' => true, 'rows' => [], 'count' => 0, 'warnings' => $warnings]);
}

$ids = array_map(fn($u) => (int)$u['id'], $users);
$idIn = implode(',', $ids);

/* ---------- 2. Optional enrichment maps, each in its own try ---------- */

// subscriptions: latest per user (active first, then most recently updated).
$subsByUser = [];
if (table_exists($pdo, 'subscriptions')) {
    try {
        $sub = $pdo->query("
            SELECT s.user_id, s.tier, s.status, s.cycle, s.current_period_end, s.cancel_at_period_end
            FROM subscriptions s
            JOIN (
                SELECT user_id, MAX(updated_at) AS mx
                FROM subscriptions
                WHERE user_id IN ($idIn)
                GROUP BY user_id
            ) latest ON latest.user_id = s.user_id AND latest.mx = s.updated_at
        ")->fetchAll();
        foreach ($sub as $row) $subsByUser[(int)$row['user_id']] = $row;
    } catch (Throwable $e) {
        $warnings[] = 'subscriptions: ' . $e->getMessage();
    }
} else {
    $warnings[] = 'subscriptions table not found (skipped plan/billing enrichment)';
}

// Latest promo per user.
$promoByUser = [];
if (table_exists($pdo, 'promo_redemptions') && table_exists($pdo, 'promo_codes')) {
    try {
        $pr = $pdo->query("
            SELECT pr.user_id, pc.code
            FROM promo_redemptions pr
            JOIN promo_codes pc ON pc.id = pr.code_id
            JOIN (
                SELECT user_id, MAX(redeemed_at) AS mx
                FROM promo_redemptions
                WHERE user_id IN ($idIn)
                GROUP BY user_id
            ) latest ON latest.user_id = pr.user_id AND latest.mx = pr.redeemed_at
        ")->fetchAll();
        foreach ($pr as $row) $promoByUser[(int)$row['user_id']] = $row['code'];
    } catch (Throwable $e) {
        $warnings[] = 'promo_redemptions: ' . $e->getMessage();
    }
} else {
    $warnings[] = 'promo tables not found (skipped promo enrichment)';
}

// Member counts.
$membersByOwner = [];
if (table_exists($pdo, 'account_members')) {
    try {
        $mc = $pdo->query("
            SELECT owner_id, COUNT(*) AS cnt
            FROM account_members
            WHERE owner_id IN ($idIn)
            GROUP BY owner_id
        ")->fetchAll();
        foreach ($mc as $row) $membersByOwner[(int)$row['owner_id']] = (int)$row['cnt'];
    } catch (Throwable $e) {
        $warnings[] = 'account_members: ' . $e->getMessage();
    }
} else {
    $warnings[] = 'account_members table not found (treating every account as individual)';
}

/* ---------- 3. Map UI vocab ---------- */

function ui_status_for(?array $sub, ?string $tierExpires): string {
    if ($sub) {
        $s = $sub['status'] ?? null;
        if ($s === 'active')                                        return 'active';
        if ($s === 'trialing')                                      return 'trial';
        if (in_array($s, ['past_due','incomplete','unpaid'], true))  return 'payment';
        if ($s === 'canceled')                                      return 'canceled';
    }
    if (!empty($tierExpires) && strtotime($tierExpires) < time())   return 'expired';
    return 'active';
}
function ui_plan_for(?array $sub, ?string $userTier): string {
    $t = $sub['tier'] ?? $userTier ?? 'free';
    return ucwords(str_replace('_',' ', strtolower((string)$t)));
}
function ui_billing_for(?array $sub): string {
    if (!$sub) return 'free';
    $cyc = $sub['cycle'] ?? null;
    if ($cyc === 'annual')  return 'annual';
    if ($cyc === 'monthly') return 'monthly';
    if (($sub['status'] ?? null) === 'trialing') return 'trial';
    return 'free';
}

/* ---------- 4. Build rows in the shape admin.html already renders ---------- */

$out = [];
foreach ($users as $u) {
    $uid = (int)$u['id'];
    $sub = $subsByUser[$uid] ?? null;
    $tier = $hasTierCol ? ($u['tier'] ?? null) : null;
    $tierExp = $hasTierExp ? ($u['tier_expires_at'] ?? null) : null;
    $members = $membersByOwner[$uid] ?? 0;

    $row = [
        'id'           => 'cus_' . $uid,
        'user_id'      => $uid,
        'name'         => $u['name'] ?: '(no name)',
        'email'        => $u['email'],
        'org'          => $members > 0 ? ($u['name'] . ' (team)') : null,
        'plan'         => ui_plan_for($sub, $tier),
        'status'       => ui_status_for($sub, $tierExp),
        'billing'      => ui_billing_for($sub),
        'signup'       => $u['created_at'] ? substr((string)$u['created_at'], 0, 10) : null,
        'renewal'      => ($sub && !empty($sub['current_period_end'])) ? substr((string)$sub['current_period_end'], 0, 10) : null,
        'cancel_scheduled' => $sub && (int)($sub['cancel_at_period_end'] ?? 0) === 1,
        'users'        => 1 + $members,
        'lastLogin'    => $u['last_login_at'] ?: null,
        'flagged'      => $hasFlagged && !empty($u['flagged_at'] ?? null),
        'locked'       => $hasLocked  && !empty($u['locked_at']  ?? null),
        'paused'       => $hasPaused  && !empty($u['paused_at']  ?? null),
        'promo'        => $promoByUser[$uid] ?? null,
        'supportNotes' => 0,
    ];

    // Apply status/plan filter post-enrichment so we can still serve a filtered
    // list when the optional tables are missing (everyone falls back to 'active').
    if ($status !== '' && $row['status'] !== $status) continue;
    if ($plan   !== '' && strcasecmp($row['plan'], $plan) !== 0) continue;

    $out[] = $row;
}

json_out([
    'ok'       => true,
    'rows'     => $out,
    'count'    => count($out),
    'warnings' => $warnings,
]);

/* ---------- helpers ---------- */
// Direct queries (no prepared placeholders) because SHOW LIKE with a
// placeholder throws on some MySQL/PDO configurations. Names are filtered
// to alphanumeric+underscore so the inline interpolation is safe.
function table_exists(PDO $pdo, string $name): bool {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    if ($safe === '') return false;
    try {
        return (bool)$pdo->query("SHOW TABLES LIKE '" . $safe . "'")->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
    $tSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $cSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
    if ($tSafe === '' || $cSafe === '') return false;
    try {
        return (bool)$pdo->query("SHOW COLUMNS FROM `" . $tSafe . "` LIKE '" . $cSafe . "'")->fetchColumn();
    } catch (Throwable $e) { return false; }
}
