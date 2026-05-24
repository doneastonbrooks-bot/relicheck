<?php
// POST /api/admin/staff/accept.php
// Body: { token: string, name?: string, password: string }
//
// Consumes a staff invitation token and sets the staff member's admin
// password. Admin staff are independent identities from customer
// accounts: a staff member can also have a customer account at the same
// email (or a different one), and the two never share a password.
//
// On success:
//   - staff_users row flips to status='active' with the password hash set
//   - the inviter's identifier is preserved in added_by_user_id
//   - an admin session is started (the new staff member is signed in to
//     the admin panel immediately)
//   - audit row is written

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_admin_audit.php';
require_once __DIR__ . '/../../_admin_session.php';

require_method('POST');
check_origin();

$body     = read_json_body();
$token    = is_string($body['token']    ?? null) ? trim((string)$body['token']) : '';
$name     = clean_string((string)($body['name'] ?? ''), 120);
$password = is_string($body['password'] ?? null) ? $body['password'] : '';

if (!preg_match('/^[a-f0-9]{64}$/', $token)) fail('bad_token', 'This invitation link is invalid.', 400);
if (!valid_password($password))              fail('bad_password', 'Password must be at least 8 characters and include a number.');

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id, email, name, role, status, invite_expires
       FROM staff_users WHERE invite_token = :t'
);
$stmt->execute([':t' => $token]);
$staff = $stmt->fetch();
if (!$staff)                                                                          fail('bad_token', 'This invitation link is invalid or has been used.', 400);
if ($staff['status'] === 'active')                                                    fail('already_active', 'This invitation has already been accepted.', 400);
if ($staff['status'] === 'suspended')                                                 fail('suspended', 'This staff account has been suspended.', 403);
if ($staff['status'] === 'removed')                                                   fail('removed', 'This staff account has been removed.', 403);
if (empty($staff['invite_expires']) || strtotime((string)$staff['invite_expires']) < time()) {
    fail('expired_token', 'This invitation has expired. Ask the admin who invited you to send a new one.', 400);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo->prepare(
    "UPDATE staff_users
        SET status = 'active',
            invite_token = NULL,
            invite_expires = NULL,
            activated_at = NOW(),
            password_hash = :h,
            name = COALESCE(NULLIF(:n, ''), name)
      WHERE id = :id"
)->execute([
    ':h'  => $hash,
    ':n'  => $name,
    ':id' => (int)$staff['id'],
]);

// Sign the new staff member in immediately so the next page lands on /admin.html cleanly.
admin_login_session((int)$staff['id']);

admin_audit_log(
    [
        'id'    => (int)$staff['id'],          // staff_users.id (the actor IS the new staff member)
        'email' => (string)$staff['email'],
        'role'  => (string)$staff['role'],
    ],
    'Accepted staff invitation',
    'employee',
    [
        'severity'     => 'info',
        'target_type'  => 'employee',
        'target_id'    => 'staff:' . (int)$staff['id'],
        'target_label' => trim(($name ?: ($staff['name'] ?? '')) . ' (' . $staff['email'] . ')'),
        'before'       => 'invited',
        'after'        => 'active * role: ' . $staff['role'],
        'reason'       => null,
    ]
);

json_out([
    'ok'      => true,
    'role'    => $staff['role'],
    'message' => 'Welcome to the ReliCheck admin team. You\'re now signed in.',
]);
