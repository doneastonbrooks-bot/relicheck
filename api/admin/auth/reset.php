<?php
// POST /api/admin/auth/reset.php
// Body: { token: string, password: string }
//
// Consumes an admin password reset token and sets a new password.
// Does NOT sign the user in - they must go to /admin-login.html and
// sign in fresh, including their 2FA code if enrolled.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin_audit.php';
require_once __DIR__ . '/../../_ratelimit.php';

require_method('POST');
check_origin();

check_rate_limit(ip_bucket_key('admin_reset'), 20, 3600);

$body     = read_json_body();
$token    = is_string($body['token']    ?? null) ? trim((string)$body['token']) : '';
$password = is_string($body['password'] ?? null) ? $body['password'] : '';

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    fail('bad_token', 'This reset link is invalid or expired.', 400);
}
if (!valid_password($password)) {
    fail('bad_password', 'Password must be at least 8 characters and include a number.');
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT pr.staff_id, pr.expires_at, pr.used_at, s.email, s.name, s.status
       FROM admin_password_resets pr
       JOIN staff_users s ON s.id = pr.staff_id
      WHERE pr.token = :t LIMIT 1'
);
$stmt->execute([':t' => $token]);
$row = $stmt->fetch();

if (!$row)                                                                   fail('bad_token', 'This reset link is invalid or expired.', 400);
if ($row['used_at'] !== null)                                                 fail('used_token', 'This reset link has already been used.', 400);
$expires = strtotime((string)$row['expires_at'] . ' UTC');
if ($expires === false || $expires < time())                                   fail('expired_token', 'This reset link has expired. Request a new one.', 400);
if ($row['status'] === 'removed')                                             fail('account_removed', 'This admin account has been removed and cannot be restored via password reset.', 403);

$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE staff_users SET password_hash = :h WHERE id = :id')
        ->execute([':h' => $hash, ':id' => (int)$row['staff_id']]);
    $pdo->prepare('UPDATE admin_password_resets SET used_at = NOW() WHERE token = :t')
        ->execute([':t' => $token]);
    // Invalidate any other outstanding tokens for this staff member.
    $pdo->prepare(
        'UPDATE admin_password_resets SET used_at = NOW()
          WHERE staff_id = :sid AND used_at IS NULL AND token <> :t'
    )->execute([':sid' => (int)$row['staff_id'], ':t' => $token]);
    // Revoke all existing admin sessions for this staff member so a stolen
    // session can't survive a password reset.
    try {
        $pdo->prepare('DELETE FROM admin_sessions WHERE staff_id = :sid')
            ->execute([':sid' => (int)$row['staff_id']]);
    } catch (Throwable $e) { /* admin_sessions might be missing pre-Phase-27 */ }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

admin_audit_log(
    [
        'id'    => (int)$row['staff_id'],          // The staff member is the actor (self-service reset).
        'email' => (string)$row['email'],
        'role'  => 'self',
    ],
    'Admin password reset',
    'auth',
    [
        'severity'     => 'warn',
        'target_type'  => 'employee',
        'target_id'    => 'staff:' . (int)$row['staff_id'],
        'target_label' => trim(($row['name'] ?: '') . ' (' . $row['email'] . ')'),
        'before'       => '-',
        'after'        => 'password reset * existing sessions revoked',
        'reason'       => 'Self-service via /admin-reset.html',
    ]
);

json_out([
    'ok'      => true,
    'message' => 'Admin password updated. Sign in at /admin-login.html - including your 2FA code if you have one set up.',
]);
