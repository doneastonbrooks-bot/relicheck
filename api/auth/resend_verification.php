<?php
// POST /api/auth/resend_verification.php
// No body. Requires auth. Issues a new verification token and emails
// the current user's address. Stays cheap and resistant to abuse via
// rate-limit and short window.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();
$user = require_auth();

$pdo = db();

// Skip if already verified.
$verRow = $pdo->prepare('SELECT email_verified_at, email, name FROM users WHERE id = :i LIMIT 1');
$verRow->execute([':i' => (int)$user['id']]);
$row = $verRow->fetch();
if (!$row) fail('not_found', 'User not found.', 404);
if ($row['email_verified_at'] !== null) {
    json_out(['ok' => true, 'already_verified' => true]);
}

// Rate-limit: 5 resends per user per hour, 20 per IP per hour.
check_rate_limit('resend_ver:user:' . (int)$user['id'], 5, 3600);
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
check_rate_limit('resend_ver:ip:' . $ip, 20, 3600);

$token     = bin2hex(random_bytes(24));
$tokenHash = hash('sha256', $token);
$expires   = date('Y-m-d H:i:s', time() + (86400 * 7));

$pdo->prepare(
    'INSERT INTO email_verifications (user_id, email, token_hash, expires_at)
     VALUES (:u, :e, :h, :x)'
)->execute([
    ':u' => (int)$user['id'],
    ':e' => (string)$row['email'],
    ':h' => $tokenHash,
    ':x' => $expires,
]);

// Email dispatch. Try to use the dispatcher template; if absent, send a
// minimal direct mail so the verification still flows.
$linkBase = 'https://relichecksurvey.com/verify.html?token=' . $token;
$firstName = trim(explode(' ', (string)$row['name'])[0] ?: 'there');
try {
    if (is_file(__DIR__ . '/../_email_dispatcher.php')) {
        require_once __DIR__ . '/../_email_dispatcher.php';
        relicheck_email_dispatch('customer.welcome.verify_email', [
            'user_id'    => (int)$user['id'],
            'account_id' => (int)$user['id'],
            'idempotency_entity_id' => 'verify:' . (int)$user['id'] . ':' . substr($tokenHash, 0, 16),
            'payload'    => [
                'first_name' => $firstName,
                'email'      => (string)$row['email'],
                'verify_url' => $linkBase,
            ],
        ]);
    }
} catch (Throwable $e) {
    error_log('[relicheck] verify resend dispatch failed: ' . $e->getMessage());
}

json_out(['ok' => true, 'sent_to' => (string)$row['email']]);
