<?php
// GET  /api/auth/verify_email.php?token=...
// Lands here from the verification email. Single-use token. On success,
// sets users.email_verified_at = NOW() and returns ok=true. The verify.html
// page calls this and renders the result.
//
// POST /api/auth/verify_email.php   { token }
// Same behavior; provided so the landing page can POST instead of leaving
// the token in a GET URL after click-through.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_ratelimit.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    fail('method_not_allowed', 'This endpoint only accepts GET or POST.', 405);
}

$token = '';
if ($method === 'GET') {
    $token = trim((string)($_GET['token'] ?? ''));
} else {
    $body = read_json_body();
    $token = trim((string)($body['token'] ?? ''));
}

if ($token === '' || !preg_match('/^[A-Za-z0-9]{32,128}$/', $token)) {
    fail('bad_token', 'Verification link is invalid.', 400);
}

// Light rate-limit so the token can't be brute-forced cheaply.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
check_rate_limit('verify_email:ip:' . $ip, 30, 3600);

$tokenHash = hash('sha256', $token);
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT id, user_id, email, expires_at, used_at
       FROM email_verifications
      WHERE token_hash = :h LIMIT 1'
);
$stmt->execute([':h' => $tokenHash]);
$row = $stmt->fetch();
if (!$row) {
    fail('not_found', 'This verification link is not recognized. Request a new one.', 404);
}
if ($row['used_at'] !== null) {
    fail('already_used', 'This verification link was already used. Sign in normally.', 410);
}
if (strtotime((string)$row['expires_at']) < time()) {
    fail('expired', 'This verification link has expired. Request a new one.', 410);
}

// Mark token used + flip the user to verified. Both in one transaction so
// a partial failure doesn't leave a half-applied state.
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = :i')
        ->execute([':i' => (int)$row['id']]);
    $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :u')
        ->execute([':u' => (int)$row['user_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('verify_failed', 'Could not verify your email. Try again in a moment.', 500);
}

json_out([
    'ok'    => true,
    'email' => (string)$row['email'],
]);
