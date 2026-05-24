<?php
// GET /api/admin/customers/get.php?id=<users.id|cus_NNN>
//
// Returns one customer's account state plus organization members and
// recent admin audit entries that target this customer. No survey content.
//
// Defensive against missing optional tables: each enrichment step wraps
// its own try/catch and adds a string to the `warnings` array on failure
// instead of bringing down the whole response.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');
require_admin();

$raw = (string)($_GET['id'] ?? '');
$uid = (int)preg_replace('/[^0-9]/', '', $raw);
if ($uid <= 0) fail('bad_id', 'Missing or invalid customer id.');

$pdo = db();
$warnings = [];

/* Core user row (must succeed). */
$cols = 'u.id, u.email, u.name, u.created_at, u.last_login_at';
$hasTier    = column_exists($pdo, 'users', 'tier');
$hasTierExp = column_exists($pdo, 'users', 'tier_expires_at');
$hasLocked  = column_exists($pdo, 'users', 'locked_at');
$hasFlagged = column_exists($pdo, 'users', 'flagged_at');
$hasPaused  = column_exists($pdo, 'users', 'paused_at');
if ($hasTier)    $cols .= ', u.tier';
if ($hasTierExp) $cols .= ', u.tier_expires_at';
if ($hasLocked)  $cols .= ', u.locked_at, u.locked_reason';
if ($hasFlagged) $cols .= ', u.flagged_at, u.flagged_reason';
if ($hasPaused)  $cols .= ', u.paused_at, u.paused_reason';

try {
    $stmt = $pdo->prepare("SELECT $cols FROM users u WHERE u.id = :id");
    $stmt->execute([':id' => $uid]);
    $u = $stmt->fetch();
} catch (Throwable $e) {
    fail('users_query_failed', $e->getMessage(), 500);
}
if (!$u) fail('not_found', 'Customer not found.', 404);

/* Subscription. */
$sub = null;
if (table_exists($pdo, 'subscriptions')) {
    try {
        $sstmt = $pdo->prepare("
            SELECT tier, status, cycle, current_period_end, cancel_at_period_end
            FROM subscriptions WHERE user_id = :uid
            ORDER BY (status = 'active') DESC, updated_at DESC LIMIT 1
        ");
        $sstmt->execute([':uid' => $uid]);
        $sub = $sstmt->fetch() ?: null;
    } catch (Throwable $e) {
        $warnings[] = 'subscriptions: ' . $e->getMessage();
    }
} else {
    $warnings[] = 'subscriptions table not found';
}

/* Latest promo. */
$promoCode = null;
if (table_exists($pdo, 'promo_redemptions') && table_exists($pdo, 'promo_codes')) {
    try {
        $pstmt = $pdo->prepare("
            SELECT pc.code FROM promo_redemptions pr
            JOIN promo_codes pc ON pc.id = pr.code_id
            WHERE pr.user_id = :uid
            ORDER BY pr.redeemed_at DESC LIMIT 1
        ");
        $pstmt->execute([':uid' => $uid]);
        $promoCode = ($r = $pstmt->fetch()) ? $r['code'] : null;
    } catch (Throwable $e) {
        $warnings[] = 'promo lookup: ' . $e->getMessage();
    }
}

/* Org members. */
$members = [];
$memberCount = 0;
if (table_exists($pdo, 'account_members')) {
    try {
        $mstmt = $pdo->prepare("
            SELECT u2.id, u2.email, u2.name, am.role, am.created_at
            FROM account_members am
            JOIN users u2 ON u2.id = am.member_id
            WHERE am.owner_id = :oid
            ORDER BY am.created_at ASC
            LIMIT 100
        ");
        $mstmt->execute([':oid' => $uid]);
        foreach ($mstmt->fetchAll() as $m) {
            $members[] = [
                'id'    => (int)$m['id'],
                'name'  => $m['name'] ?: '(no name)',
                'email' => $m['email'],
                'role'  => $m['role'] ?: 'member',
                'added' => $m['created_at'] ? substr((string)$m['created_at'], 0, 10) : null,
            ];
        }
        $memberCount = count($members);
    } catch (Throwable $e) {
        $warnings[] = 'account_members: ' . $e->getMessage();
    }
}

/* Survey count (count only, no content access). */
$surveyCount = 0;
if (table_exists($pdo, 'surveys')) {
    try {
        $cstmt = $pdo->prepare("SELECT COUNT(*) FROM surveys WHERE owner_id = :uid");
        $cstmt->execute([':uid' => $uid]);
        $surveyCount = (int)$cstmt->fetchColumn();
    } catch (Throwable $e) {
        $warnings[] = 'surveys count: ' . $e->getMessage();
    }
}

/* Pending org invitations (Phase 18 account_invitations). */
$pendingInvites = [];
if (table_exists($pdo, 'account_invitations')) {
    try {
        $istmt = $pdo->prepare(
            "SELECT id, email, role, expires_at, created_at
               FROM account_invitations
              WHERE owner_id = :oid
                AND accepted_at IS NULL
                AND declined_at IS NULL
                AND expires_at > NOW()
              ORDER BY created_at DESC
              LIMIT 50"
        );
        $istmt->execute([':oid' => $uid]);
        foreach ($istmt->fetchAll() as $i) {
            $pendingInvites[] = [
                'id'         => (int)$i['id'],
                'email'      => $i['email'],
                'role'       => $i['role'],
                'expires_at' => $i['expires_at'],
                'created_at' => $i['created_at'],
            ];
        }
    } catch (Throwable $e) {
        $warnings[] = 'account_invitations: ' . $e->getMessage();
    }
}

/* Support notes (Phase 22). Added inline so the customer profile renders
   notes without an extra round-trip. */
$notes = [];
if (table_exists($pdo, 'admin_notes')) {
    try {
        $nstmt = $pdo->prepare("
            SELECT id, author_user_id, author_email, body, created_at
            FROM admin_notes
            WHERE customer_user_id = :uid
            ORDER BY created_at DESC
            LIMIT 100
        ");
        $nstmt->execute([':uid' => $uid]);
        foreach ($nstmt->fetchAll() as $n) {
            $notes[] = [
                'id'           => (int)$n['id'],
                'author_email' => $n['author_email'],
                'body'         => $n['body'],
                'created_at'   => $n['created_at'],
            ];
        }
    } catch (Throwable $e) {
        $warnings[] = 'admin_notes: ' . $e->getMessage();
    }
}

/* Admin audit entries targeting this customer. */
$activity = [];
if (table_exists($pdo, 'admin_audit')) {
    try {
        $astmt = $pdo->prepare("
            SELECT ts, actor_email, actor_role, action, category, severity,
                   target_label, before_value, after_value, reason, ip
            FROM admin_audit
            WHERE target_type = 'customer' AND target_id = :tid
            ORDER BY ts DESC
            LIMIT 25
        ");
        $astmt->execute([':tid' => 'cus_' . $uid]);
        foreach ($astmt->fetchAll() as $a) {
            $activity[] = [
                'ts'         => $a['ts'],
                'actor'      => $a['actor_email'],
                'actor_role' => $a['actor_role'],
                'action'     => $a['action'],
                'cat'        => $a['category'],
                'sev'        => $a['severity'],
                'target'     => $a['target_label'],
                'before'     => $a['before_value'] ?? '-',
                'after'      => $a['after_value']  ?? '-',
                'reason'     => $a['reason']       ?? '-',
                'ip'         => $a['ip']           ?? '-',
            ];
        }
    } catch (Throwable $e) {
        $warnings[] = 'admin_audit: ' . $e->getMessage();
    }
} else {
    $warnings[] = 'admin_audit table not found (run Phase 20 migration)';
}

/* UI vocab mapping (mirrors list.php). */
$uiStatus = (function () use ($sub, $u, $hasTierExp) {
    if ($sub) {
        $s = $sub['status'] ?? null;
        if ($s === 'active')                                        return 'active';
        if ($s === 'trialing')                                      return 'trial';
        if (in_array($s, ['past_due','incomplete','unpaid'], true))  return 'payment';
        if ($s === 'canceled')                                      return 'canceled';
    }
    if ($hasTierExp && !empty($u['tier_expires_at']) && strtotime((string)$u['tier_expires_at']) < time()) return 'expired';
    return 'active';
})();
$uiPlan    = ucwords(str_replace('_',' ', strtolower((string)($sub['tier'] ?? ($u['tier'] ?? 'free')))));
$uiBilling = $sub ? (($sub['cycle'] === 'annual') ? 'annual' : (($sub['cycle'] === 'monthly') ? 'monthly' : (($sub['status'] ?? null) === 'trialing' ? 'trial' : 'free'))) : 'free';

json_out([
    'ok'       => true,
    'customer' => [
        'id'           => 'cus_' . $uid,
        'user_id'      => $uid,
        'name'         => $u['name'] ?: '(no name)',
        'email'        => $u['email'],
        'org'          => $memberCount > 0 ? ($u['name'] . ' (team)') : null,
        'plan'         => $uiPlan,
        'status'       => $uiStatus,
        'billing'      => $uiBilling,
        'signup'       => $u['created_at']     ? substr((string)$u['created_at'], 0, 10) : null,
        'renewal'      => ($sub && !empty($sub['current_period_end'])) ? substr((string)$sub['current_period_end'], 0, 10) : null,
        'cancel_scheduled' => $sub && (int)($sub['cancel_at_period_end'] ?? 0) === 1,
        'users'        => 1 + $memberCount,
        'lastLogin'    => $u['last_login_at'] ?: null,
        'flagged'      => $hasFlagged && !empty($u['flagged_at'] ?? null),
        'flagged_at'   => $hasFlagged ? ($u['flagged_at']     ?? null) : null,
        'flagged_reason'=> $hasFlagged ? ($u['flagged_reason'] ?? null) : null,
        'locked'       => $hasLocked && !empty($u['locked_at'] ?? null),
        'locked_at'    => $hasLocked ? ($u['locked_at']     ?? null) : null,
        'locked_reason'=> $hasLocked ? ($u['locked_reason'] ?? null) : null,
        'paused'       => $hasPaused && !empty($u['paused_at'] ?? null),
        'paused_at'    => $hasPaused ? ($u['paused_at']     ?? null) : null,
        'paused_reason'=> $hasPaused ? ($u['paused_reason'] ?? null) : null,
        'promo'        => $promoCode,
        'supportNotes' => count($notes),
        'survey_count' => $surveyCount,
    ],
    'org_members'     => $members,
    'pending_invites' => $pendingInvites,
    'notes'           => $notes,
    'activity'        => $activity,
    'warnings'        => $warnings,
]);

// Direct queries (no prepared placeholders) because SHOW LIKE with a
// placeholder throws on some MySQL/PDO configurations.
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
