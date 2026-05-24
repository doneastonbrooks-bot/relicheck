<?php
// POST /api/auth/signup
// Body: { name, email, password }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();

// Cap signups at 8 per IP per hour to prevent automated account creation.
check_rate_limit(ip_bucket_key('signup'), 8, 3600);

$body = read_json_body();
$name     = clean_string($body['name']     ?? '', 120);
$email    = strtolower(clean_string($body['email'] ?? '', 255));
$password = is_string($body['password'] ?? null) ? $body['password'] : '';

if ($name === '')                    fail('bad_name',     'Please enter your name.');
if (!valid_email($email))            fail('bad_email',    'Please enter a valid email address.');
if (!valid_password($password))      fail('bad_password', 'Password must be at least 8 characters and include a number.');

$pdo = db();

// Check if email already exists
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute([':email' => $email]);
if ($stmt->fetch()) {
    fail('email_taken', 'An account with that email already exists.', 409);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare(
    'INSERT INTO users (email, password_hash, name) VALUES (:email, :hash, :name)'
);
$stmt->execute([
    ':email' => $email,
    ':hash'  => $hash,
    ':name'  => $name,
]);
$uid = (int)$pdo->lastInsertId();

login_user($uid);

// Issue a single-use verification token. The user is auto-logged in for
// convenience, but Stripe checkout and team-invite endpoints will refuse
// to run until email_verified_at is set.
$token     = bin2hex(random_bytes(24));
$tokenHash = hash('sha256', $token);
$expires   = date('Y-m-d H:i:s', time() + (86400 * 7));
try {
    $pdo->prepare(
        'INSERT INTO email_verifications (user_id, email, token_hash, expires_at)
         VALUES (:u, :e, :h, :x)'
    )->execute([
        ':u' => $uid,
        ':e' => $email,
        ':h' => $tokenHash,
        ':x' => $expires,
    ]);
} catch (Throwable $e) {
    // Table may not exist yet on installs that haven't run Phase 166. Log
    // and continue; the user still has an account, just no verify token.
    error_log('[relicheck] verify-token insert failed: ' . $e->getMessage());
}

// Welcome / verify email. Fired through the dispatcher so it follows the same
// queue, suppression, and audit path as every other system-generated email.
// Wrapped in try/catch so a mailer failure never breaks the signup itself.
try {
    if (is_file(__DIR__ . '/../_email_dispatcher.php')) {
        require_once __DIR__ . '/../_email_dispatcher.php';
        relicheck_email_dispatch('customer.welcome.verify_email', [
            'user_id'    => $uid,
            'account_id' => $uid,
            'idempotency_entity_id' => 'signup-verify:' . $uid,
            'payload'    => [
                'first_name' => trim(explode(' ', $name)[0] ?: 'there'),
                'email'      => $email,
                'verify_url' => 'https://relichecksurvey.com/verify.html?token=' . $token,
            ],
        ]);
    }
} catch (Throwable $e) {
    error_log('[relicheck] signup verify dispatch failed: ' . $e->getMessage());
}

json_out([
    'ok'   => true,
    'user' => ['id' => $uid, 'email' => $email, 'name' => $name, 'needs_verification' => true],
], 201);
