<?php
// POST /api/auth/login_2fa.php
// Body: { challenge_token, code }
//
// Completes a two-step login. The challenge_token was returned by
// login.php after a successful password check on a 2FA-enabled account.
// We validate the token (single-use, 5-minute TTL) and the TOTP code,
// then mint the session.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ratelimit.php';
require_once __DIR__ . '/../_totp.php';

require_method('POST');
check_origin();

$body  = read_json_body();
$token = trim((string)($body['challenge_token'] ?? ''));
$code  = trim((string)($body['code'] ?? ''));

if ($token === '' || !preg_match('/^[A-Za-z0-9]{32,128}$/', $token)) {
    fail('bad_token', 'Invalid challenge token.', 400);
}
if ($code === '' || !preg_match('/^[0-9]{6,8}$/', $code)) {
    fail('bad_code', 'Enter the 6-digit code from your authenticator app.', 400);
}

// Rate-limit 2FA attempts so the 6-digit code can't be brute-forced cheaply.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
check_rate_limit('login_2fa:ip:' . $ip, 30, 900);

$tokenHash = hash('sha256', $token);
$pdo = db();

$row = null;
try {
    $stmt = $pdo->prepare(
        'SELECT id, user_id, expires_at, used_at
           FROM auth_2fa_challenges WHERE token_hash = :h LIMIT 1'
    );
    $stmt->execute([':h' => $tokenHash]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    fail('twofa_not_ready', '2FA is not configured on this server.', 500);
}
if (!$row) {
    fail('not_found', 'This 2FA challenge is not recognized. Sign in again.', 404);
}
if ($row['used_at'] !== null) {
    fail('already_used', 'This 2FA challenge was already used. Sign in again.', 410);
}
if (strtotime((string)$row['expires_at']) < time()) {
    fail('expired', 'This 2FA challenge expired. Sign in again.', 410);
}

$userId = (int)$row['user_id'];
check_rate_limit('login_2fa:user:' . $userId, 10, 900);

// Load the user's TOTP secret.
$secret = '';
try {
    $us = $pdo->prepare('SELECT totp_secret, totp_enabled, email, name FROM users WHERE id = :i LIMIT 1');
    $us->execute([':i' => $userId]);
    $u = $us->fetch();
    if (!$u || (int)$u['totp_enabled'] !== 1) {
        fail('twofa_not_enabled', '2FA is not enabled on this account.', 400);
    }
    $secret = (string)$u['totp_secret'];
} catch (Throwable $e) {
    fail('twofa_load_failed', 'Could not load 2FA settings.', 500);
}
if ($secret === '') {
    fail('twofa_no_secret', '2FA is not fully configured. Contact support.', 500);
}

// Verify TOTP. Accept the current window plus +/-1 (90 seconds total drift).
if (!totp_verify_code($secret, $code, 1)) {
    // Allow backup codes as a fallback. Each backup code is single-use.
    $backupOk = false;
    try {
        $bs = $pdo->prepare('SELECT backup_codes_json FROM users WHERE id = :i LIMIT 1');
        $bs->execute([':i' => $userId]);
        $br = $bs->fetch();
        $codes = $br ? (json_decode((string)($br['backup_codes_json'] ?? '[]'), true) ?: []) : [];
        $newCodes = [];
        foreach ($codes as $entry) {
            if (!is_array($entry) || empty($entry['hash'])) continue;
            if (empty($entry['used']) && hash_equals((string)$entry['hash'], hash('sha256', $code))) {
                $entry['used'] = true;
                $backupOk = true;
            }
            $newCodes[] = $entry;
        }
        if ($backupOk) {
            $pdo->prepare('UPDATE users SET backup_codes_json = :bc WHERE id = :i')
                ->execute([':bc' => json_encode($newCodes), ':i' => $userId]);
        }
    } catch (Throwable $e) { /* fall through to fail */ }
    if (!$backupOk) {
        fail('bad_code', 'That code did not match. Try again.', 401);
    }
}

// Mark the challenge used.
$pdo->prepare('UPDATE auth_2fa_challenges SET used_at = NOW() WHERE id = :i')
    ->execute([':i' => (int)$row['id']]);

// Update last_login bookkeeping (best-effort).
try {
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
        ->execute([':id' => $userId]);
    $cur_ip = ip_hash();
    if ($cur_ip !== null) {
        $pdo->prepare('UPDATE users SET last_login_ip_hash = :ip WHERE id = :id')
            ->execute([':ip' => $cur_ip, ':id' => $userId]);
    }
} catch (Throwable $e) {}

login_user($userId);

// Fetch the user row again for the response so we don't echo back stale info.
$urow = $pdo->prepare('SELECT email, name FROM users WHERE id = :i LIMIT 1');
$urow->execute([':i' => $userId]);
$ur = $urow->fetch() ?: ['email' => '', 'name' => ''];

json_out([
    'ok'   => true,
    'user' => ['id' => $userId, 'email' => (string)$ur['email'], 'name' => (string)$ur['name']],
]);
