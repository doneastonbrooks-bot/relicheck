<?php
// POST /api/v1/device/poll
//
// Step 2 of the device-code flow. The desktop polls every few seconds
// with the opaque device_code it received from start.php. We return
// one of:
//   - status=pending     -> user has not approved yet, keep polling
//   - status=expired     -> the 5-minute window passed, start over
//   - status=ok          -> approved; payload includes access_token + user
//
// On success we issue a row in api_tokens (the existing table used by
// the dashboard's manual token UI) so subsequent requests through the
// normal Bearer-token path work identically to a manually-created
// token. The device_authorization row is marked consumed.
//
// Phase 5, Phase 18 additive rule observed.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_db.php';
require_once __DIR__ . '/../../_ratelimit.php';
require_once __DIR__ . '/../../_api_auth.php';

require_method('POST');

// Poll is called every few seconds, so the limit is generous, but
// still bounded. check_rate_limit() exits via fail() on overflow.
check_rate_limit(ip_bucket_key('device_poll'), 120, 60);

$body = read_json_body();
$device_code = isset($body['device_code']) ? (string)$body['device_code'] : '';
if ($device_code === '' || strlen($device_code) > 64) {
    json_out(['ok' => false, 'error' => 'bad_request',
              'message' => 'device_code is required.'], 400);
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id, user_code, approved_user_id, approved_at, consumed_at,
            expires_at, issued_token_id
       FROM device_authorizations
       WHERE device_code = :dc
       LIMIT 1'
);
$stmt->execute([':dc' => $device_code]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    json_out(['ok' => false, 'status' => 'expired',
              'error' => 'expired_or_unknown',
              'message' => 'This pairing code is no longer valid. Start a new device pairing in the desktop app.']);
}

// Use NOW() vs expires_at via the DB so we don't have to fight the
// PHP/MySQL timezone gap (memory: never compare PHP-computed time to
// MySQL NOW()).
$expCheck = $pdo->prepare('SELECT (NOW() > :exp) AS expired FROM dual');
$expCheck->execute([':exp' => $row['expires_at']]);
$exp = $expCheck->fetch(PDO::FETCH_ASSOC);
if (!empty($exp['expired'])) {
    // Clean up the row so we don't accumulate them.
    $pdo->prepare('DELETE FROM device_authorizations WHERE id = :id')
        ->execute([':id' => $row['id']]);
    json_out(['ok' => false, 'status' => 'expired',
              'error' => 'expired',
              'message' => 'This pairing code expired. Start a new pairing in the desktop app.']);
}

if (!$row['approved_user_id']) {
    json_out(['ok' => true, 'status' => 'pending']);
}

if ($row['consumed_at']) {
    // Desktop already received its token. We do not redeliver tokens;
    // if the desktop lost it, the user pairs again.
    json_out(['ok' => false, 'status' => 'consumed',
              'error' => 'already_consumed',
              'message' => 'This pairing was already completed.']);
}

// Approved and not yet consumed: issue a real api_tokens row.
$user_id = (int)$row['approved_user_id'];
$userStmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id LIMIT 1');
$userStmt->execute([':id' => $user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    json_out(['ok' => false, 'status' => 'expired',
              'error' => 'user_missing',
              'message' => 'The approving account is no longer active.']);
}

$raw_token = api_token_generate();
$hash = api_token_hash($raw_token);
$prefix = substr($raw_token, 0, 11);

$pdo->beginTransaction();
try {
    // Schema matches db/schema_phase17.sql api_tokens table:
    //   user_id, name, prefix, token_hash, last_used_at(null), created_at(default), revoked_at(null)
    $ins = $pdo->prepare(
        "INSERT INTO api_tokens (user_id, name, prefix, token_hash)
         VALUES (:uid, :name, :prefix, :hash)"
    );
    $tname = 'ReliCheck MM Studio desktop';
    $ins->execute([
        ':uid'    => $user_id,
        ':name'   => $tname,
        ':prefix' => $prefix,
        ':hash'   => $hash,
    ]);
    $token_id = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'UPDATE device_authorizations
           SET consumed_at = NOW(), issued_token_id = :tid
           WHERE id = :id'
    )->execute([':tid' => $token_id, ':id' => $row['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('device/poll.php finalize failed: ' . $e->getMessage()
              . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    json_out(['ok' => false, 'status' => 'error',
              'error' => 'server_error',
              'message' => 'Could not finalize the pairing.'], 500);
}

json_out([
    'ok' => true,
    'status' => 'ok',
    'access_token' => $raw_token,
    'token_prefix' => $prefix,
    'user' => [
        'id'    => (int)$user['id'],
        'email' => $user['email'],
        'name'  => $user['name'],
    ],
]);
