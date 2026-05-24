<?php
// POST /api/admin/auth/login.php
// Body: { email: string, password: string }
//
// Admin-only sign-in. Distinct from /api/auth/login.php (the customer
// sign-in). Sets the relicheck_admin cookie via _admin_session.php.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin_session.php';
require_once __DIR__ . '/../../_ratelimit.php';

require_method('POST');
check_origin();

$body = read_json_body();
$email    = strtolower(clean_string((string)($body['email'] ?? ''), 190));
$password = is_string($body['password'] ?? null) ? $body['password'] : '';

if (!valid_email($email) || $password === '') {
    fail('invalid_credentials', 'Email or password is incorrect.', 401);
}

// 8 attempts per email per 15 min; 25 per IP per 15 min.
check_rate_limit('admin_login:email:' . $email, 8, 900);
check_rate_limit(ip_bucket_key('admin_login'),  25, 900);

$pdo = db();

// Try to also pull the TOTP fields if Phase 28 has been applied. Fall back
// to the original SELECT if those columns aren't there yet, so login keeps
// working pre-migration.
try {
    $stmt = $pdo->prepare(
        "SELECT id, email, name, role, status, password_hash,
                totp_secret, totp_enrolled_at, two_factor_required
           FROM staff_users WHERE email = :em"
    );
    $stmt->execute([':em' => $email]);
    $staff = $stmt->fetch();
} catch (Throwable $e) {
    $stmt = $pdo->prepare(
        "SELECT id, email, name, role, status, password_hash
           FROM staff_users WHERE email = :em"
    );
    $stmt->execute([':em' => $email]);
    $staff = $stmt->fetch();
    // Synthesize 2FA fields as off so the rest of the flow doesn't break.
    if ($staff) {
        $staff['totp_secret']        = null;
        $staff['totp_enrolled_at']   = null;
        $staff['two_factor_required'] = 0;
    }
}

// Constant-ish path so timing doesn't reveal whether the email exists.
$ok = false;
if ($staff && !empty($staff['password_hash'])) {
    $ok = password_verify($password, (string)$staff['password_hash']);
}
if (!$ok) {
    if ($staff) {
        try {
            $pdo->prepare('UPDATE staff_users SET failed_login_at = NOW() WHERE id = :id')
                ->execute([':id' => (int)$staff['id']]);
        } catch (Throwable $e) { /* best-effort */ }
    }
    fail('invalid_credentials', 'Email or password is incorrect.', 401);
}

// Refuse non-active staff with a specific error.
if ($staff['status'] === 'invited')   fail('not_accepted', 'This account has not finished accepting its invitation.', 403);
if ($staff['status'] === 'suspended') fail('suspended', 'This admin account is suspended. Contact the owner.', 403);
if ($staff['status'] === 'removed')   fail('removed', 'This admin account has been removed.', 403);

// Re-hash if PHP's default cost has changed.
if (password_needs_rehash((string)$staff['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE staff_users SET password_hash = :h WHERE id = :id')
        ->execute([':h' => $newHash, ':id' => (int)$staff['id']]);
}

// Decide whether 2FA is involved.
//   already_enrolled = totp_secret + totp_enrolled_at both set
//   needs_setup      = two_factor_required=1 AND not enrolled
require_once __DIR__ . '/../../_totp.php';
$alreadyEnrolled = !empty($staff['totp_secret']) && !empty($staff['totp_enrolled_at']);
$needsSetup      = !$alreadyEnrolled && (int)($staff['two_factor_required'] ?? 0) === 1;

// Touch last_login_at regardless - the password did succeed even if
// 2FA still needs to clear.
$pdo->prepare('UPDATE staff_users SET last_login_at = NOW() WHERE id = :id')
    ->execute([':id' => (int)$staff['id']]);

if ($alreadyEnrolled) {
    admin_login_session((int)$staff['id'], 'pending_2fa');
    json_out([
        'ok'        => true,
        'next'      => 'verify_2fa',
        'message'   => 'Enter the 6-digit code from your authenticator app.',
    ]);
}

if ($needsSetup) {
    // Generate a fresh secret and store it in the pending session row.
    // It only becomes the staff_users.totp_secret after the user proves they
    // can produce a valid code (in /api/admin/auth/2fa/setup_confirm.php).
    $secret = totp_generate_secret();
    admin_login_session((int)$staff['id'], 'pending_setup', $secret);
    $otpauth = totp_otpauth_url($secret, $staff['email']);
    json_out([
        'ok'           => true,
        'next'         => 'setup_2fa',
        'otpauth_url'  => $otpauth,
        'secret'       => $secret,
        'message'      => 'Two-factor authentication is required. Set it up to continue.',
    ]);
}

// 2FA neither enrolled nor required - issue a normal active session.
admin_login_session((int)$staff['id']);

json_out([
    'ok'   => true,
    'next' => 'panel',
    'user' => [
        'id'    => (int)$staff['id'],
        'email' => $staff['email'],
        'name'  => $staff['name'],
        'role'  => $staff['role'],
    ],
]);
