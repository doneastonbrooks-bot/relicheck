<?php
// POST /api/auth/reset.php
// Body: { token, password }
// Validates the token and sets the user's new password.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();

// Cap reset attempts at 20 per IP per hour to slow token brute force.
check_rate_limit(ip_bucket_key('reset'), 20, 3600);

$body     = read_json_body();
$token    = is_string($body['token'] ?? null) ? trim($body['token']) : '';
$password = is_string($body['password'] ?? null) ? $body['password'] : '';

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    fail('bad_token', 'This reset link is invalid or expired.', 400);
}
if (!valid_password($password)) {
    fail('bad_password', 'Password must be at least 8 characters and include a number.');
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT pr.user_id, pr.expires_at, pr.used_at, u.email, u.name
       FROM password_resets pr
       JOIN users u ON u.id = pr.user_id
      WHERE pr.token = :t LIMIT 1'
);
$stmt->execute([':t' => $token]);
$row = $stmt->fetch();

if (!$row) {
    fail('bad_token', 'This reset link is invalid or expired.', 400);
}
if ($row['used_at'] !== null) {
    fail('used_token', 'This reset link has already been used.', 400);
}
$expires = strtotime((string)$row['expires_at'] . ' UTC');
if ($expires === false || $expires < time()) {
    fail('expired_token', 'This reset link has expired. Please request a new one.', 400);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
        ->execute([':h' => $hash, ':id' => (int)$row['user_id']]);
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE token = :t')
        ->execute([':t' => $token]);
    // Invalidate other outstanding reset tokens for this user
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL AND token <> :t')
        ->execute([':uid' => (int)$row['user_id'], ':t' => $token]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

// Sign the user in immediately so the next page lands cleanly.
login_user((int)$row['user_id']);

json_out([
    'ok' => true,
    'user' => [
        'id'    => (int)$row['user_id'],
        'email' => $row['email'],
        'name'  => $row['name'],
    ],
]);
