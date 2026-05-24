<?php
// POST /api/admin/customers/remove_org_user.php
// Body: { owner_id?: int, owner_email?: string,
//         member_id: int, reason: string }
//
// Removes a member from a workspace owner's team. Deletes the
// account_members row that grants the member access to the owner's
// workspace. The member's own user account is untouched - they keep
// their personal account, they just lose shared access to the owner's
// data.
//
// Audit row written with the removed member's email in the after field.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';

require_method('POST');
check_origin();
$admin = require_admin();

$body        = read_json_body();
$ownerId     = (int)($body['owner_id'] ?? 0);
$ownerEmail  = strtolower(clean_string((string)($body['owner_email'] ?? ''), 255));
$memberId    = (int)($body['member_id'] ?? 0);
$reason      = clean_string((string)($body['reason'] ?? ''), 500);

if ($ownerId <= 0 && $ownerEmail === '') fail('bad_input', 'Provide owner_id or owner_email.');
if ($memberId <= 0)                       fail('bad_member', 'Missing or invalid member_id.');
if ($reason === '')                       fail('reason_required', 'A reason is required to remove an org user.');

$pdo = db();

// account_members lives in Phase 18. Bail clearly if it's missing.
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'account_members'")->fetchColumn();
} catch (Throwable $e) {
    fail('schema_error', 'Could not check schema: ' . $e->getMessage(), 500);
}
if (!$tableExists) fail('migration_missing', 'Phase 18 migration not applied. The account_members table does not exist yet.', 500);

// Resolve the owner.
if ($ownerId > 0) {
    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id');
    $stmt->execute([':id' => $ownerId]);
} else {
    if (!valid_email($ownerEmail)) fail('bad_email', 'Invalid owner_email.');
    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE email = :em');
    $stmt->execute([':em' => $ownerEmail]);
}
$owner = $stmt->fetch();
if (!$owner) fail('not_found', 'Workspace owner not found.', 404);
$ouid = (int)$owner['id'];

// Refuse to remove the owner from their own workspace (defense against UI bugs).
if ($memberId === $ouid) fail('cannot_remove_owner', 'The workspace owner cannot be removed from their own org. Cancel the membership instead.');

// Look up the member to capture their email for the audit trail.
$mstmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id');
$mstmt->execute([':id' => $memberId]);
$member = $mstmt->fetch();
if (!$member) fail('member_not_found', 'Member account not found.', 404);

// Verify the membership row actually exists, so we can give a clean
// "already removed" message instead of silently doing nothing.
$check = $pdo->prepare(
    'SELECT id, role FROM account_members WHERE owner_id = :o AND member_id = :m'
);
$check->execute([':o' => $ouid, ':m' => $memberId]);
$row = $check->fetch();
if (!$row) fail('not_a_member', $member['email'] . ' is not a member of this workspace.', 404);

$pdo->prepare('DELETE FROM account_members WHERE owner_id = :o AND member_id = :m')
    ->execute([':o' => $ouid, ':m' => $memberId]);

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    'Removed organization user',
    'customer',
    [
        'severity'     => 'warn',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . $ouid,
        'target_label' => trim(($owner['name'] ?: '') . ' (' . $owner['email'] . ')'),
        'before'       => $member['email'] . ' (role: ' . ($row['role'] ?? 'member') . ')',
        'after'        => 'Removed',
        'reason'       => $reason,
    ]
);

json_out([
    'ok'      => true,
    'removed' => [
        'member_id'    => $memberId,
        'member_email' => $member['email'],
    ],
    'message' => 'Removed ' . $member['email'] . ' from ' . $owner['email'] . '\'s workspace.',
]);
