<?php
// POST /api/admin/auth/2fa/verify.php
// Body: { code: string }
//
// Completes admin sign-in for a session in 'pending_2fa' state.
// Reads the staff member's stored TOTP secret and verifies the 6-digit
// code. On success, flips the session to 'active'.

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
if (!$pending || $pending['status'] !== 'pending_2fa') {
    fail('no_pending_session', 'No pending sign-in to verify. Start the sign-in flow over.', 401);
}

// Per-staff rate limit so brute-forcing a 6-digit code is throttled.
check_rate_limit('admin_2fa:staff:' . (int)$pending['staff_id'], 8, 600);

// +/-60-second tolerance for everyday sign-in.
if (!totp_verify_code((string)$pending['totp_secret'], $code, 2)) {
    $secretBytes = totp_base32_decode((string)$pending['totp_secret']);
    $serverNow   = time();
    $expectedNow = $secretBytes !== '' ? totp_code_at($secretBytes, (int)floor($serverNow / 30)) : '?';
    fail('bad_code',
        'That code is not correct. Server time: ' . date('Y-m-d H:i:s', $serverNow) . ' UTC. ' .
        'Try the next code your app shows. If multiple codes in a row don\'t work, your authenticator clock is out of sync.',
        401);
}

activate_admin_session((string)$pending['token']);

json_out([
    'ok'      => true,
    'message' => 'Signed in.',
    'user'    => [
        'id'    => (int)$pending['staff_id'],
        'email' => $pending['email'],
        'name'  => $pending['name'],
        'role'  => $pending['role'],
    ],
]);
