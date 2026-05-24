<?php
// Admin gate. The owner is always admin (hardcoded below). The optional
// admin_emails list in _config.php can grant admin to additional teammates.
// Anyone not recognized gets a 403 from admin endpoints.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_session.php';

// Permanent admins. These addresses are admins regardless of what
// _config.php says, so a config edit, deploy mishap, or empty allow-list
// can never lock the owner out of admin features. Compared case-insensitively
// after trimming, so trailing whitespace can't sneak in.
function relicheck_permanent_admins(): array
{
    return [
        'don.eastonbrooks@gmail.com',
        'doneastonbrooks@mac.com',
    ];
}

function relicheck_admin_emails(): array
{
    $combined = relicheck_permanent_admins();

    // If _config.php is missing or unreadable, relicheck_config() exits early
    // with a 500 before we get here, so this call is safe.
    $cfg = function_exists('relicheck_config') ? relicheck_config() : [];
    $extra = $cfg['admin_emails'] ?? [];
    if (is_array($extra)) {
        foreach ($extra as $e) {
            $combined[] = (string)$e;
        }
    }

    // Normalize: trim, lowercase, drop empties, dedupe.
    $normalized = [];
    foreach ($combined as $e) {
        $clean = strtolower(trim((string)$e));
        if ($clean !== '') $normalized[$clean] = true;
    }
    return array_keys($normalized);
}

function is_admin_user(array $user): bool
{
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '') return false;

    // Permanent admins and config allowlist always win.
    if (in_array($email, relicheck_admin_emails(), true)) return true;

    // Database-backed staff (Phase 26). Active staff get admin access; any
    // other status (invited / suspended / removed) does not. Wrapped in
    // try/catch so a missing table (migration not run) never breaks the
    // existing email-allowlist path.
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            "SELECT 1 FROM staff_users WHERE email = :em AND status = 'active' LIMIT 1"
        );
        $stmt->execute([':em' => $email]);
        if ($stmt->fetchColumn()) return true;
    } catch (Throwable $e) {
        // Table missing or query failed; fall through to "not admin".
    }

    return false;
}

// Returns the staff role for a signed-in user, or 'owner' if they're a
// permanent/config admin, or null if they have no admin access at all.
function staff_role_for_user(array $user): ?string
{
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '') return null;
    if (in_array($email, relicheck_admin_emails(), true)) return 'owner';
    try {
        $stmt = db()->prepare(
            "SELECT role FROM staff_users WHERE email = :em AND status = 'active' LIMIT 1"
        );
        $stmt->execute([':em' => $email]);
        $r = $stmt->fetchColumn();
        return $r ? (string)$r : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Returns the current admin user from either:
 *   1. An admin session (Phase 27, the new dedicated admin login), OR
 *   2. A customer session whose email is on the permanent allowlist
 *      (the owner backdoor - keeps Donald able to access admin even
 *      if the staff_users system is broken or his admin password is lost).
 *
 * Returns null if neither path applies. Use require_admin() to bail with
 * 403 instead of returning null.
 */
function current_admin(): ?array
{
    require_once __DIR__ . '/_admin_session.php';

    // 1. Dedicated admin session.
    $staff = current_admin_staff();
    if ($staff) {
        return [
            'id'     => (int)$staff['id'],          // staff_users.id
            'email'  => $staff['email'],
            'name'   => $staff['name'] ?? '',
            'role'   => $staff['role'] ?? 'cs',
            'source' => 'staff',
        ];
    }

    // 2. Allowlist owner backdoor via customer session.
    $u = current_user();
    if ($u) {
        $email = strtolower(trim((string)($u['email'] ?? '')));
        if ($email !== '' && in_array($email, relicheck_admin_emails(), true)) {
            return [
                'id'     => (int)($u['id'] ?? 0),    // users.id
                'email'  => $u['email'],
                'name'   => $u['name'] ?? '',
                'role'   => 'owner',
                'source' => 'allowlist',
            ];
        }
    }

    return null;
}

function require_admin(): array
{
    $a = current_admin();
    if (!$a) fail('forbidden', 'Admin access required.', 403);
    return $a;
}
