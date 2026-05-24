<?php
// /api/account/2fa.php  - user-facing TOTP 2FA management.
//
// GET                       -> status: enrolled, enabled, email_otp_enabled,
//                              backup_codes_remaining
// POST  action=enroll-start -> mints a pending TOTP secret, returns the
//                              base32 secret + an otpauth:// URL the front
//                              end renders as a QR code via api.qrserver
// POST  action=enroll-verify-> body { code }. Confirms the 6-digit TOTP and
//                              flips totp_enabled to 1. Returns 10 fresh
//                              backup codes (shown once).
// POST  action=disable      -> body { password }. Re-auths and clears all
//                              2FA columns.
// POST  action=regenerate-backup -> body { code }. Verifies TOTP and
//                              returns a fresh set of 10 backup codes.
// POST  action=email-otp    -> body { enabled: bool }. Toggles the email
//                              recovery channel.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';
require_once __DIR__ . '/../_totp.php';

require_method('GET', 'POST');
$user = require_auth();
$pdo  = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function _2fa_load_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, email, password_hash, totp_secret, totp_enabled,
                totp_enrolled_at, backup_codes_json, email_otp_enabled
           FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) fail('not_found', 'User not found.', 404);
    return $row;
}

function _2fa_count_backup_codes(?string $json): int
{
    if (!$json) return 0;
    $arr = json_decode($json, true);
    if (!is_array($arr)) return 0;
    $left = 0;
    foreach ($arr as $r) if (is_array($r) && empty($r['used_at'])) $left++;
    return $left;
}

function _2fa_make_backup_codes(): array
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $raw = [];
    $stored = [];
    for ($i = 0; $i < 10; $i++) {
        $code = '';
        for ($j = 0; $j < 10; $j++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $code = substr($code, 0, 5) . '-' . substr($code, 5);
        $raw[] = $code;
        $stored[] = ['hash' => hash('sha256', $code), 'used_at' => null];
    }
    return ['raw' => $raw, 'stored' => $stored];
}

if ($method === 'GET') {
    $row = _2fa_load_user($pdo, (int)$user['id']);
    json_out([
        'ok'                      => true,
        'enrolled'                => !empty($row['totp_secret']),
        'enabled'                 => (int)($row['totp_enabled'] ?? 0) === 1,
        'enrolled_at'             => $row['totp_enrolled_at'] ?: null,
        'email_otp_enabled'       => (int)($row['email_otp_enabled'] ?? 0) === 1,
        'backup_codes_remaining'  => _2fa_count_backup_codes($row['backup_codes_json'] ?? null),
    ]);
}

check_origin();
$body = read_json_body();
$action = (string)($body['action'] ?? '');

if ($action === 'enroll-start') {
    $secret = totp_generate_secret();
    $pdo->prepare('UPDATE users SET totp_secret = :s, totp_enabled = 0 WHERE id = :id')
        ->execute([':s' => $secret, ':id' => (int)$user['id']]);
    $accountEmail = (string)$user['email'];
    $otpauth = totp_otpauth_url($secret, $accountEmail, 'ReliCheck');
    json_out([
        'ok'      => true,
        'secret'  => $secret,
        'otpauth' => $otpauth,
        'qr_url'  => 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=4&data=' . rawurlencode($otpauth),
    ]);
}

if ($action === 'enroll-verify') {
    $code = preg_replace('/\D/', '', (string)($body['code'] ?? ''));
    if (strlen((string)$code) !== 6) fail('bad_code', 'Enter the 6-digit code.', 400);
    $row = _2fa_load_user($pdo, (int)$user['id']);
    if (empty($row['totp_secret'])) fail('not_enrolled', 'Start enrollment first.', 400);
    if (!totp_verify_code((string)$row['totp_secret'], (string)$code, 1)) {
        fail('bad_code', 'That code did not match. Try again.', 400);
    }
    $codes = _2fa_make_backup_codes();
    $pdo->prepare(
        'UPDATE users
            SET totp_enabled = 1, totp_enrolled_at = NOW(), backup_codes_json = :bc
          WHERE id = :id'
    )->execute([
        ':bc' => json_encode($codes['stored'], JSON_UNESCAPED_SLASHES),
        ':id' => (int)$user['id'],
    ]);
    json_out([
        'ok'           => true,
        'backup_codes' => $codes['raw'],
        'note'         => 'Save these codes. Each one works for a single sign-in if you lose your authenticator.',
    ]);
}

if ($action === 'disable') {
    $password = (string)($body['password'] ?? '');
    if ($password === '') fail('bad_password', 'Confirm your password to disable 2FA.', 400);
    $row = _2fa_load_user($pdo, (int)$user['id']);
    if (!password_verify($password, (string)$row['password_hash'])) {
        fail('bad_password', 'Password did not match.', 401);
    }
    $pdo->prepare(
        'UPDATE users
            SET totp_secret = NULL, totp_enabled = 0, totp_enrolled_at = NULL,
                backup_codes_json = NULL, email_otp_enabled = 0
          WHERE id = :id'
    )->execute([':id' => (int)$user['id']]);
    json_out(['ok' => true]);
}

if ($action === 'regenerate-backup') {
    $code = preg_replace('/\D/', '', (string)($body['code'] ?? ''));
    if (strlen((string)$code) !== 6) fail('bad_code', 'Enter your current 6-digit code.', 400);
    $row = _2fa_load_user($pdo, (int)$user['id']);
    if (empty($row['totp_secret']) || (int)($row['totp_enabled'] ?? 0) !== 1) {
        fail('not_enrolled', '2FA is not active.', 400);
    }
    if (!totp_verify_code((string)$row['totp_secret'], (string)$code, 1)) {
        fail('bad_code', 'Code did not match.', 401);
    }
    $codes = _2fa_make_backup_codes();
    $pdo->prepare('UPDATE users SET backup_codes_json = :bc WHERE id = :id')
        ->execute([':bc' => json_encode($codes['stored'], JSON_UNESCAPED_SLASHES), ':id' => (int)$user['id']]);
    json_out(['ok' => true, 'backup_codes' => $codes['raw']]);
}

if ($action === 'email-otp') {
    $on = !empty($body['enabled']) ? 1 : 0;
    $pdo->prepare('UPDATE users SET email_otp_enabled = :v WHERE id = :id')
        ->execute([':v' => $on, ':id' => (int)$user['id']]);
    json_out(['ok' => true, 'email_otp_enabled' => $on === 1]);
}

fail('bad_action', 'Unknown action. Use enroll-start, enroll-verify, disable, regenerate-backup, or email-otp.');
