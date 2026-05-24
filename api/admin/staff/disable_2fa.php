<?php
// POST /api/admin/staff/disable_2fa.php
// Body: { staff_id: int, reason: string }
//
// Owner-only recovery: clears the staff member's TOTP secret so they can
// re-enroll on next sign-in (assuming two_factor_required is still on,
// they will be forced through setup again).
//
// Sensitive - bypassing 2FA for someone else needs to be auditable. We
// require a reason and log with critical severity.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';

require_method('POST');
check_origin();
$admin = require_admin();

// Only Owner role can do this. Other admin roles cannot remove someone
// else's 2FA - that would be a privilege-escalation footgun.
if (($admin['role'] ?? '') !== 'owner') {
    fail('owner_only', 'Only the owner can disable another staff member\'s 2FA. Suspend them instead if access needs to be blocked.', 403);
}

$body    = read_json_body();
$staffId = (int)($body['staff_id'] ?? 0);
$reason  = clean_string((string)($body['reason'] ?? ''), 500);

if ($staffId <= 0)   fail('bad_id', 'Missing or invalid staff_id.');
if ($reason === '')  fail('reason_required', 'A reason is required to disable a staff member\'s 2FA.');

$pdo = db();
$stmt = $pdo->prepare('SELECT id, email, name, totp_enrolled_at FROM staff_users WHERE id = :id');
$stmt->execute([':id' => $staffId]);
$staff = $stmt->fetch();
if (!$staff) fail('not_found', 'Staff member not found.', 404);
if (empty($staff['totp_enrolled_at'])) fail('not_enrolled', 'This staff member has no 2FA enrolled.');

$pdo->prepare(
    'UPDATE staff_users
        SET totp_secret = NULL, totp_enrolled_at = NULL
      WHERE id = :id'
)->execute([':id' => $staffId]);

// Revoke any active or pending sessions so they have to sign in fresh
// and re-enroll if 2FA is still required.
try {
    $pdo->prepare('DELETE FROM admin_sessions WHERE staff_id = :id')
        ->execute([':id' => $staffId]);
} catch (Throwable $e) { /* admin_sessions might not exist on pre-Phase-27 */ }

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    'Disabled staff 2FA',
    'employee',
    [
        'severity'     => 'critical',
        'target_type'  => 'employee',
        'target_id'    => 'staff:' . $staffId,
        'target_label' => trim(($staff['name'] ?: '') . ' (' . $staff['email'] . ')'),
        'before'       => 'enrolled at ' . $staff['totp_enrolled_at'],
        'after'        => 'cleared * sessions revoked * will re-enroll on next sign-in',
        'reason'       => $reason,
    ]
);

json_out([
    'ok'      => true,
    'message' => '2FA cleared for ' . $staff['email'] . '. Their next sign-in will force fresh enrollment.',
]);
