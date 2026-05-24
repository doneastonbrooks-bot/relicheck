<?php
// POST /api/v1/device/start
//
// Step 1 of the OAuth-style device-code flow used by the MM Studio
// desktop app. No auth required (the desktop has no token yet). The
// caller gets back a short human-readable user_code (which they
// display to the user, who then types it into relichecksurvey.com/
// connect.html while signed in to their account) and a long opaque
// device_code (which they poll with).
//
// Lifetime: 5 minutes. If the user does not approve in that window,
// the row expires and the desktop must call start again.
//
// Phase 5, Phase 18 additive rule observed (no existing files touched).

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_db.php';
require_once __DIR__ . '/../../_ratelimit.php';

require_method('POST');

// Light rate limiting per IP to discourage flood-pairing. 30 per minute
// is plenty for legitimate use (a human re-trying a few times) but
// blunts trivial abuse. check_rate_limit() exits via fail() on overflow.
check_rate_limit(ip_bucket_key('device_start'), 30, 60);

// Optional client_label for the user to recognize which device they
// approved (e.g. "Donald's Mac mini"). Free text, capped.
$body = read_json_body();
$label = isset($body['client_label']) ? mb_substr((string)$body['client_label'], 0, 120) : null;

// User code: 8 chars, dashed in the middle for readability when typed
// on the web ("ABCD-EFGH"). Alphabet excludes I/1/O/0 to reduce typos.
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$pick = function() use ($alphabet) {
    return $alphabet[random_int(0, strlen($alphabet) - 1)];
};
$user_code_plain = '';
for ($i = 0; $i < 8; $i++) {
    $user_code_plain .= $pick();
}
$user_code = substr($user_code_plain, 0, 4) . '-' . substr($user_code_plain, 4, 4);

// Device code: 32 chars, opaque. Only the desktop ever sees this.
$device_code = bin2hex(random_bytes(16));

// Submission id: a stable identifier for the desktop to recognize its
// own request in case it has to retry the poll across a network blip.
$submission_id = bin2hex(random_bytes(12));

$pdo = db();
// Insert with a 5-minute expiry. Try a small number of times in the
// (extremely unlikely) case the device_code collides.
$stmt = $pdo->prepare(
    'INSERT INTO device_authorizations
       (user_code, device_code, submission_id, client_label, expires_at)
     VALUES
       (:uc, :dc, :sid, :lbl, DATE_ADD(NOW(), INTERVAL 5 MINUTE))'
);
$attempts = 0;
while (true) {
    try {
        $stmt->execute([
            ':uc'  => $user_code,
            ':dc'  => $device_code,
            ':sid' => $submission_id,
            ':lbl' => $label,
        ]);
        break;
    } catch (PDOException $e) {
        $attempts++;
        if ($attempts >= 3) {
            json_out(['ok' => false, 'error' => 'server_error',
                      'message' => 'Could not create device authorization.'], 500);
        }
        $device_code = bin2hex(random_bytes(16));
        $stmt->bindValue(':dc', $device_code);
    }
}

$cfg = relicheck_config();
$site = rtrim((string)($cfg['site_url'] ?? 'https://relichecksurvey.com'), '/');

json_out([
    'ok' => true,
    'user_code'        => $user_code,
    'device_code'      => $device_code,
    'submission_id'    => $submission_id,
    'verification_url' => $site . '/connect.html',
    'expires_in'       => 300,
    'interval'         => 3,
]);
