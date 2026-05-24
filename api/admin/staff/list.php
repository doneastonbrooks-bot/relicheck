<?php
// GET /api/admin/staff/list.php
//
// Returns the staff_users table reshaped for the admin panel's
// Employee Access view. Also includes the permanent allowlist
// admins (from api/_admin.php) so they appear at the top with a
// special "owner" role badge.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');
require_admin();

$pdo = db();
$out = [];
$warnings = [];

// 1. Permanent allowlist admins always appear first.
foreach (relicheck_admin_emails() as $email) {
    $out[] = [
        'id'           => 'allowlist:' . $email,
        'email'        => $email,
        'name'         => $email === 'don.eastonbrooks@gmail.com' ? 'Donald Easton-Brooks' : '(allowlist admin)',
        'role'         => 'owner',
        'status'       => 'active',
        'added'        => '-',
        'addedBy'      => 'config',
        'lastLogin'    => '-',
        'twoFactor'    => true,
        'permissionsNote' => 'Permanent admin via api/_admin.php / _config.php allowlist.',
        'is_allowlist' => true,
    ];
}

// 2. staff_users rows (if migration has run).
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'staff_users'")->fetchColumn();
} catch (Throwable $e) {
    $warnings[] = 'staff table check failed: ' . $e->getMessage();
    $tableExists = false;
}

if ($tableExists) {
    // Pull totp_enrolled_at if Phase 28 has run; fall back gracefully otherwise.
    $cols = 's.id, s.email, s.name, s.role, s.status, s.added_at, s.activated_at,
             s.suspended_at, s.removed_at, s.last_login_at, s.two_factor_required,
             s.added_by_user_id';
    try {
        if ($pdo->query("SHOW COLUMNS FROM staff_users LIKE 'totp_enrolled_at'")->fetchColumn()) {
            $cols .= ', s.totp_enrolled_at';
        }
    } catch (Throwable $e) { /* ignore */ }
    try {
        $stmt = $pdo->query(
            'SELECT ' . $cols . ', u.email AS added_by_email
               FROM staff_users s
          LEFT JOIN users u ON u.id = s.added_by_user_id
          ORDER BY s.added_at DESC'
        );
        foreach ($stmt->fetchAll() as $r) {
            // Skip emails that are also on the permanent allowlist (avoid
            // showing them twice).
            if (in_array(strtolower($r['email']), relicheck_admin_emails(), true)) continue;
            $out[] = [
                'id'           => 'staff:' . (int)$r['id'],
                'email'        => $r['email'],
                'name'         => $r['name'] ?: '(no name yet)',
                'role'         => $r['role'],
                'status'       => $r['status'],
                'added'        => $r['added_at']     ? substr((string)$r['added_at'], 0, 10) : null,
                'addedBy'      => $r['added_by_email'] ?: 'system',
                'lastLogin'    => $r['last_login_at'] ?: '-',
                'twoFactor'    => (bool)$r['two_factor_required'],
                'twoFactorEnrolled' => !empty($r['totp_enrolled_at'] ?? null),
                'permissionsNote' => $r['status'] === 'invited'
                    ? 'Invitation sent. Awaiting acceptance.'
                    : 'Database-backed staff role.',
                'is_allowlist' => false,
            ];
        }
    } catch (Throwable $e) {
        $warnings[] = 'staff list query failed: ' . $e->getMessage();
    }
} else {
    $warnings[] = 'staff_users table not found. Run the Phase 26 migration to enable invite flow.';
}

json_out([
    'ok'       => true,
    'rows'     => $out,
    'count'    => count($out),
    'warnings' => $warnings,
]);
