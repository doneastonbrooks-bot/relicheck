<?php
// POST /api/auth/forgot.php
// Body: { email }
// Always returns 200 with the same message regardless of whether the email
// exists, to avoid revealing which addresses have accounts.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_mailer.php';
require_once __DIR__ . '/../_ratelimit.php';

require_method('POST');
check_origin();

// Cap reset-email blasts: 5 per email per hour, 20 per IP per hour.
check_rate_limit(ip_bucket_key('forgot'), 20, 3600);

$body  = read_json_body();
$email = strtolower(clean_string($body['email'] ?? '', 255));

$genericResponse = [
    'ok' => true,
    'message' => 'If an account exists for that email, a reset link has been sent.',
];

if (!valid_email($email)) {
    json_out($genericResponse);
}

// Per-email cap so an attacker can't spam one address.
check_rate_limit('forgot:email:' . $email, 5, 3600);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE email = :email');
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

if (!$user) {
    // Pretend success so attackers cannot enumerate emails.
    json_out($genericResponse);
}

// Generate a token and store it. Tokens are 64 hex chars (~256 bits).
$token = bin2hex(random_bytes(32));
$expires = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');

$pdo->prepare(
    'INSERT INTO password_resets (token, user_id, expires_at, ip_hash)
     VALUES (:t, :uid, :exp, :ip)'
)->execute([
    ':t'   => $token,
    ':uid' => (int)$user['id'],
    ':exp' => $expires,
    ':ip'  => ip_hash(),
]);

// Dispatch through the new email system if available, otherwise fall back to
// the legacy inline send_mail path so this endpoint keeps working even if the
// dispatcher is partially deployed.
$dispatched = false;
try {
    if (is_file(__DIR__ . '/../_email_dispatcher.php')) {
        require_once __DIR__ . '/../_email_dispatcher.php';
        $r = relicheck_email_dispatch('password.reset_requested', [
            'user_id'    => (int)$user['id'],
            'account_id' => (int)$user['id'],
            'idempotency_entity_id' => 'reset-token:' . $token,
            'payload'    => [
                'first_name'  => trim(explode(' ', (string)($user['name'] ?? ''))[0] ?: 'there'),
                'email'       => $email,
                'reset_token' => $token,
            ],
        ]);
        $dispatched = !empty($r['queued']);
    }
} catch (Throwable $e) {
    error_log('[relicheck] password.reset_requested dispatch failed: ' . $e->getMessage());
}

if (!$dispatched) {
    // Legacy fallback path. Same SMTP, same content, no queue/dedupe.
    $cfg     = relicheck_config();
    $siteUrl = rtrim((string)($cfg['site_url'] ?? ''), '/');
    if ($siteUrl === '') $siteUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $resetLink = $siteUrl . '/reset.html?token=' . $token;
    $name = $user['name'] ?: 'there';
    $text =
        "Hi $name,\n\n" .
        "Someone (hopefully you) asked to reset the password for your ReliCheck account.\n\n" .
        "Use this link to set a new password. It expires in 24 hours:\n" .
        $resetLink . "\n\n" .
        "If you did not request this, you can ignore this email and your password will stay the same.\n\n" .
        "- ReliCheck\n";
    $html =
        '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES) . ',</p>' .
        '<p>Someone (hopefully you) asked to reset the password for your ReliCheck account.</p>' .
        '<p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES) . '" ' .
        'style="display:inline-block;padding:10px 18px;background:#3d57f5;color:white;border-radius:6px;text-decoration:none;font-weight:600;">' .
        'Set a new password</a></p>' .
        '<p style="color:#5a607a;font-size:12px;">This link expires in 24 hours. ' .
        'If you didn\'t request this, you can ignore this email.</p>' .
        '<p style="color:#8a92ad;font-size:12px;">- ReliCheck</p>';
    try {
        send_mail($email, 'Reset your ReliCheck password', $text, $html, [
            'from'      => 'support@relichecksurvey.com',
            'from_name' => 'ReliCheck Support',
        ]);
    } catch (Throwable $e) {
        error_log('[relicheck] password reset legacy fallback failed: ' . $e->getMessage());
    }
}

json_out($genericResponse);
