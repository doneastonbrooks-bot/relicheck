<?php
// POST /api/admin/auth/setup_owner.php
// Body: { password: string }
//
// One-time owner bootstrap. The signed-in customer must be on the
// permanent allowlist (api/_admin.php). On success, a staff_users row
// is created (or updated) for that email with role='owner', status='active',
// and the supplied password as their admin-side password hash.
//
// After this, the owner can sign in to /admin-login.html with their email +
// admin password (separate from their customer password), use the Forgot
// password flow, enroll in 2FA, etc. The allowlist backdoor remains in
// place as a safety net.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';

require_method('POST');
check_origin();

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in to your customer ReliCheck account first (the one whose email is on the admin allowlist), then come back to this page.', 401);

$email = strtolower(trim((string)($user['email'] ?? '')));
if ($email === '' || !in_array($email, relicheck_admin_emails(), true)) {
    fail('not_allowlisted', 'Your account is not on the admin allowlist. This setup is only for permanent admins defined in api/_admin.php.', 403);
}

$body     = read_json_body();
$password = is_string($body['password'] ?? null) ? $body['password'] : '';
if (!valid_password($password)) {
    fail('bad_password', 'Password must be at least 8 characters and include a number.');
}

$pdo = db();

try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'staff_users'")->fetchColumn();
} catch (Throwable $e) {
    fail('schema_error', 'Could not check schema: ' . $e->getMessage(), 500);
}
if (!$tableExists) fail('migration_missing', 'Phase 26 migration not applied. The staff_users table does not exist yet.', 500);

// See if a row already exists.
$find = $pdo->prepare('SELECT id, status, password_hash FROM staff_users WHERE email = :em');
$find->execute([':em' => $email]);
$existing = $find->fetch();

$hash = password_hash($password, PASSWORD_DEFAULT);

if ($existing) {
    if ($existing['status'] === 'active' && !empty($existing['password_hash'])) {
        // Already set up. Refuse - they should use the Forgot password
        // flow if they've forgotten the password instead of reusing setup.
        fail('already_setup', 'An admin password is already set for this email. Use Forgot password on /admin-login.html if you need to reset it.');
    }
    // Otherwise upgrade the row to active+password.
    $pdo->prepare(
        "UPDATE staff_users
            SET role = 'owner',
                status = 'active',
                password_hash = :h,
                activated_at = COALESCE(activated_at, NOW()),
                invite_token = NULL,
                invite_expires = NULL,
                suspended_at = NULL,
                removed_at = NULL,
                name = COALESCE(NULLIF(name, ''), :n)
          WHERE id = :id"
    )->execute([
        ':h'  => $hash,
        ':n'  => (string)($user['name'] ?? ''),
        ':id' => (int)$existing['id'],
    ]);
    $staffId = (int)$existing['id'];
    $action = 'Upgraded existing staff row to owner';
} else {
    $pdo->prepare(
        "INSERT INTO staff_users
            (email, name, role, status, password_hash, added_by_user_id, activated_at)
            VALUES (:em, :n, 'owner', 'active', :h, :ab, NOW())"
    )->execute([
        ':em' => $email,
        ':n'  => (string)($user['name'] ?? ''),
        ':h'  => $hash,
        ':ab' => (int)($user['id'] ?? 0),
    ]);
    $staffId = (int)$pdo->lastInsertId();
    $action = 'Bootstrapped owner staff row';
}

admin_audit_log(
    [
        'id'    => (int)($user['id'] ?? 0),
        'email' => $email,
        'role'  => 'owner',
    ],
    $action,
    'employee',
    [
        'severity'     => 'critical',
        'target_type'  => 'employee',
        'target_id'    => 'staff:' . $staffId,
        'target_label' => trim(((string)($user['name'] ?? '')) . ' (' . $email . ')'),
        'before'       => $existing ? ($existing['status'] . (empty($existing['password_hash']) ? ' * no password' : ' * had password')) : '-',
        'after'        => 'active * role: owner * admin password set',
        'reason'       => 'One-time owner setup via /admin-setup-owner.html',
    ]
);

json_out([
    'ok'      => true,
    'staff_id' => $staffId,
    'message' => 'Admin password set for ' . $email . '. Sign in at /admin-login.html with this email + the password you just chose. Two-factor authentication can be enrolled on first sign-in.',
]);
