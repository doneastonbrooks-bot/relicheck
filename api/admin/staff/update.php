<?php
// POST /api/admin/staff/update.php
// Body: { staff_id: int, action: string, role?: string, reason?: string }
//
// Single endpoint covering the post-invite staff lifecycle:
//   action = 'role'        - change a staff member's role (requires `role`)
//   action = 'suspend'     - block sign-in but keep the row (reason required)
//   action = 'reactivate'  - restore sign-in for a suspended staff member
//   action = 'remove'      - soft-remove (reason required, severity=critical)
//   action = 'reset_pw'    - generate a temporary password and return it
//
// Guards:
//   - The actor cannot operate on themselves (no self-suspend / self-remove).
//   - Owners cannot have their role changed via this endpoint (they're
//     defined by the allowlist in _admin.php). To "promote" someone to
//     owner, add them to the allowlist there.
//   - When a staff member is suspended/removed, all their existing admin
//     sessions are revoked (admin_sessions rows deleted).

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';

require_method('POST');
check_origin();
$admin = require_admin();

$body    = read_json_body();
$staffId = (int)($body['staff_id'] ?? 0);
$action  = clean_string((string)($body['action'] ?? ''), 32);
$reason  = clean_string((string)($body['reason'] ?? ''), 500);

if ($staffId <= 0) fail('bad_id', 'Missing or invalid staff_id.');

$validActions = ['role', 'suspend', 'reactivate', 'remove', 'reset_pw'];
if (!in_array($action, $validActions, true)) {
    fail('bad_action', 'action must be one of: ' . implode(', ', $validActions) . '.');
}

$pdo = db();

// staff_users lives in Phase 26.
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'staff_users'")->fetchColumn();
} catch (Throwable $e) {
    fail('schema_error', 'Could not check schema: ' . $e->getMessage(), 500);
}
if (!$tableExists) fail('migration_missing', 'Phase 26 migration not applied.', 500);

$stmt = $pdo->prepare('SELECT id, email, name, role, status FROM staff_users WHERE id = :id');
$stmt->execute([':id' => $staffId]);
$staff = $stmt->fetch();
if (!$staff) fail('not_found', 'Staff member not found.', 404);

// Refuse self-operations for destructive actions.
if ($admin['source'] === 'staff' && (int)$admin['id'] === $staffId) {
    if (in_array($action, ['suspend', 'remove'], true)) {
        fail('cannot_self', 'You cannot ' . $action . ' your own admin account.');
    }
}

// Refuse to operate on owners through this endpoint. Owners are defined
// by the allowlist in _admin.php; promoting/demoting them is a config
// change, not a DB action.
if ($staff['role'] === 'owner' && in_array($action, ['role', 'remove', 'suspend'], true)) {
    fail('owner_protected', 'Owner accounts cannot be modified through the admin panel. Edit the allowlist in api/_admin.php instead.');
}

$beforeLabel = '';
$afterLabel  = '';
$auditAction = '';
$severity    = 'warn';
$revokeSessions = false;
$tempPassword = null;

switch ($action) {
    case 'role':
        $newRole = clean_string((string)($body['role'] ?? ''), 32);
        $valid = ['upper', 'supervisor', 'cs', 'tech', 'readonly'];
        if (!in_array($newRole, $valid, true)) {
            fail('bad_role', 'Role must be one of: ' . implode(', ', $valid) . '. Owner is reserved.');
        }
        if ($staff['role'] === $newRole) fail('no_change', 'Role is already ' . $newRole . '.');
        if ($reason === '') fail('reason_required', 'Reason is required for role changes.');

        $pdo->prepare('UPDATE staff_users SET role = :r WHERE id = :id')
            ->execute([':r' => $newRole, ':id' => $staffId]);

        $beforeLabel = $staff['role'];
        $afterLabel  = $newRole;
        $auditAction = 'Changed staff role';
        $severity    = 'critical';
        // Don't kill sessions on role change - the new role takes effect on the next page load.
        break;

    case 'suspend':
        if ($staff['status'] === 'suspended') fail('no_change', 'Staff is already suspended.');
        if ($staff['status'] === 'removed')   fail('cannot_suspend_removed', 'Removed staff cannot be suspended. Re-invite them instead.');
        if ($reason === '') fail('reason_required', 'Reason is required to suspend a staff account.');

        $pdo->prepare("UPDATE staff_users SET status='suspended', suspended_at=NOW() WHERE id = :id")
            ->execute([':id' => $staffId]);

        $beforeLabel = $staff['status'];
        $afterLabel  = 'suspended';
        $auditAction = 'Suspended staff';
        $severity    = 'critical';
        $revokeSessions = true;
        break;

    case 'reactivate':
        if ($staff['status'] === 'active') fail('no_change', 'Staff is already active.');
        if (empty($staff['name']) || $staff['status'] === 'invited') {
            fail('not_accepted', 'This staff member has not yet accepted their invitation. Re-send the invite instead.');
        }

        $pdo->prepare("UPDATE staff_users SET status='active', suspended_at=NULL, removed_at=NULL WHERE id = :id")
            ->execute([':id' => $staffId]);

        $beforeLabel = $staff['status'];
        $afterLabel  = 'active';
        $auditAction = 'Reactivated staff';
        $severity    = 'info';
        break;

    case 'remove':
        if ($staff['status'] === 'removed') fail('no_change', 'Staff is already removed.');
        if ($reason === '') fail('reason_required', 'Reason is required to remove a staff account.');

        $pdo->prepare("UPDATE staff_users SET status='removed', removed_at=NOW() WHERE id = :id")
            ->execute([':id' => $staffId]);

        $beforeLabel = $staff['status'];
        $afterLabel  = 'removed';
        $auditAction = 'Removed staff';
        $severity    = 'critical';
        $revokeSessions = true;
        break;

    case 'reset_pw':
        // Generate a temp password the owner can hand to the staff member.
        // The staff member should change it on first sign-in (we don't
        // enforce that yet - future slice).
        if ($staff['status'] === 'invited') {
            fail('not_accepted', 'This staff member has not yet accepted their invitation; resend the invite instead.');
        }
        $tempPassword = bin2hex(random_bytes(6)); // 12 hex chars, easy to read aloud
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE staff_users SET password_hash = :h WHERE id = :id')
            ->execute([':h' => $hash, ':id' => $staffId]);

        $beforeLabel = '(unchanged)';
        $afterLabel  = 'password reset * temp password generated';
        $auditAction = 'Reset staff password';
        $severity    = 'critical';
        $revokeSessions = true;
        break;
}

// Revoke admin sessions if needed (suspend / remove / reset_pw).
if ($revokeSessions) {
    try {
        $pdo->prepare('DELETE FROM admin_sessions WHERE staff_id = :id')
            ->execute([':id' => $staffId]);
    } catch (Throwable $e) {
        // admin_sessions might not exist (Phase 27 not run); harmless to skip.
    }
}

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => $admin['role'] ?? 'owner'],
    $auditAction,
    'employee',
    [
        'severity'     => $severity,
        'target_type'  => 'employee',
        'target_id'    => 'staff:' . $staffId,
        'target_label' => trim(($staff['name'] ?: '') . ' (' . $staff['email'] . ')'),
        'before'       => $beforeLabel,
        'after'        => $afterLabel,
        'reason'       => $reason !== '' ? $reason : null,
    ]
);

$response = [
    'ok'      => true,
    'action'  => $action,
    'staff'   => [
        'id'     => $staffId,
        'email'  => $staff['email'],
        'name'   => $staff['name'],
        'role'   => $action === 'role' ? $afterLabel : $staff['role'],
        'status' => in_array($action, ['suspend','reactivate','remove'], true) ? $afterLabel : $staff['status'],
    ],
    'message' => $auditAction . ' * ' . ($staff['email'] ?? ''),
];

if ($tempPassword !== null) {
    // Surface the temp password ONCE to the owner. They should hand it
    // to the staff member out-of-band. We don't email it for security.
    $response['temp_password'] = $tempPassword;
    $response['message']      .= '. Temporary password: ' . $tempPassword;
}

json_out($response);
