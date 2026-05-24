<?php
// POST /api/v1/device/approve
//
// Called by connect.html on relichecksurvey.com once a signed-in user
// types in the user_code shown on the desktop. We look up the matching
// device_authorization, mark it approved by this user, and tell the
// browser the pairing succeeded. The desktop's next poll.php call
// will then receive the access token.
//
// Phase 5, Phase 18 additive rule observed (no existing files touched).

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_db.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_ratelimit.php';

require_method('POST');

// Session auth (not Bearer-token). The user is signed into the web app
// to approve a device.
$user = require_auth();

check_rate_limit(ip_bucket_key('device_approve'), 20, 60);

$body = read_json_body();
$raw = isset($body['user_code']) ? strtoupper(trim((string)$body['user_code'])) : '';
// Accept "ABCD-EFGH" or "ABCDEFGH"; normalize to the dashed form we
// stored.
$raw = preg_replace('/[^A-Z0-9]/', '', $raw) ?? '';
if (strlen($raw) !== 8) {
    json_out(['ok' => false, 'error' => 'bad_code',
              'message' => 'Codes are 8 characters. Check what is shown in the desktop app.'], 400);
}
$user_code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id, approved_user_id, consumed_at, expires_at
       FROM device_authorizations
       WHERE user_code = :uc AND expires_at > NOW()
       ORDER BY id DESC
       LIMIT 1'
);
$stmt->execute([':uc' => $user_code]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    json_out(['ok' => false, 'error' => 'expired_or_unknown',
              'message' => 'That code is unknown or has expired. Check the desktop app for a fresh code.'], 404);
}
if ($row['consumed_at']) {
    json_out(['ok' => false, 'error' => 'already_consumed',
              'message' => 'That code was already used. Start a new pairing in the desktop app.'], 409);
}
if ($row['approved_user_id']) {
    // Already approved (possibly by this same user clicking twice).
    json_out(['ok' => true, 'status' => 'already_approved']);
}

$upd = $pdo->prepare(
    'UPDATE device_authorizations
       SET approved_user_id = :uid, approved_at = NOW()
       WHERE id = :id AND approved_user_id IS NULL'
);
$upd->execute([':uid' => (int)$user['id'], ':id' => (int)$row['id']]);

json_out([
    'ok' => true,
    'status' => 'approved',
    'message' => 'Pairing approved. Switch back to the desktop app.',
]);
