<?php
// POST /api/admin/auth/2fa/setup_confirm.php
// Body: { code: string }
//
// Completes first-time TOTP enrollment for a session in 'pending_setup'
// state. The pending secret was generated and stored on the session row
// when the user signed in. We verify the user's first 6-digit code
// against that pending secret; if it matches, we move the secret onto
// the staff_users row, mark them enrolled, and activate the session.

declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../_admin_session.php';
require_once __DIR__ . '/../../../_totp.php';
require_once __DIR__ . '/../../../_ratelimit.php';

require_method('POST');
check_origin();

$body = read_json_body();
$code = is_string($body['code'] ?? null) ? trim((string)$body['code']) : '';

$pending = pending_admin_session();
if (!$pending || $pending['status'] !== 'pending_setup') {
    fail('no_pending_session',
        'Your setup session expired (1 hour limit). Go to /admin-login.html, sign in with email + password again to start a fresh setup. The QR code will be NEW - your authenticator app will need to scan the new one.',
        401);
}
if (empty($pending['pending_secret'])) {
    fail('no_pending_secret', 'The setup session has no secret on file. Start sign-in over.', 401);
}

check_rate_limit('admin_2fa_setup:staff:' . (int)$pending['staff_id'], 8, 600);

// Wide window for first-time setup (+/-10 steps = +/-5 minutes) so clock drift
// on either side doesn't block enrollment. Once enrolled, verify.php uses
// the tighter default window for everyday sign-in.
if (!totp_verify_code((string)$pending['pending_secret'], $code, 10)) {
    // Build a diagnostic field so we can see, in the response body, exactly
    // why the code didn't match. Safe to surface during enrollment because
    // the user already has the secret on screen anyway.
    $secretBytes = totp_base32_decode((string)$pending['pending_secret']);
    $serverNow   = time();
    $expectedNow = $secretBytes !== '' ? totp_code_at($secretBytes, (int)floor($serverNow / 30)) : '?';
    fail('bad_code',
        'That code did not match. Server time is ' . date('Y-m-d H:i:s', $serverNow) . ' UTC. ' .
        'Server-expected code right now: ' . $expectedNow . '. ' .
        'You typed: ' . preg_replace('/\D/', '', $code) . '. ' .
        'If those differ by 1-2 digits, your authenticator clock is just off - sync it. ' .
        'If they\'re completely different, you scanned a different secret than the one shown on this page.',
        401);
}

$pdo = db();
$pdo->prepare(
    'UPDATE staff_users
        SET totp_secret = :s, totp_enrolled_at = NOW()
      WHERE id = :id'
)->execute([
    ':s'  => (string)$pending['pending_secret'],
    ':id' => (int)$pending['staff_id'],
]);

activate_admin_session((string)$pending['token']);

json_out([
    'ok'      => true,
    'message' => 'Two-factor authentication is now enabled. You\'re signed in.',
    'user'    => [
        'id'    => (int)$pending['staff_id'],
        'email' => $pending['email'],
        'name'  => $pending['name'],
        'role'  => $pending['role'],
    ],
]);
