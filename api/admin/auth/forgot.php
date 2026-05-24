<?php
// POST /api/admin/auth/forgot.php
// Body: { email }
//
// Admin-side password reset request. Always returns 200 with a generic
// message regardless of whether the email exists, so we don't leak which
// addresses are admins. Sends the reset email from hr@ since admin
// password resets are HR/staff-context, not customer-context.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_mailer.php';
require_once __DIR__ . '/../../_ratelimit.php';

require_method('POST');
check_origin();

// 5 admin-reset email blasts per email per hour, 20 per IP per hour.
check_rate_limit(ip_bucket_key('admin_forgot'), 20, 3600);

$body  = read_json_body();
$email = strtolower(clean_string((string)($body['email'] ?? ''), 255));

$genericResponse = [
    'ok'      => true,
    'message' => 'If an admin account exists for that email, a reset link has been sent.',
];

if (!valid_email($email)) {
    json_out($genericResponse);
}

check_rate_limit('admin_forgot:email:' . $email, 5, 3600);

$pdo = db();

// Schema check; bail-as-success if the staff_users table doesn't exist yet.
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'staff_users'")->fetchColumn();
} catch (Throwable $e) { $tableExists = false; }
if (!$tableExists)         json_out($genericResponse);

try {
    $resetTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_password_resets'")->fetchColumn();
} catch (Throwable $e) { $resetTableExists = false; }
if (!$resetTableExists) {
    // Configuration issue - log it but don't expose it to the requester.
    error_log('[relicheck] admin forgot called but admin_password_resets table missing (run Phase 29).');
    json_out($genericResponse);
}

$stmt = $pdo->prepare(
    "SELECT id, email, name, status FROM staff_users WHERE email = :em LIMIT 1"
);
$stmt->execute([':em' => $email]);
$staff = $stmt->fetch();

// Never reveal whether the email exists OR what the staff status is.
// Only invited/active/suspended/removed accounts that exist get an email;
// anything else is a silent no-op that still returns the generic message.
if (!$staff)                                  json_out($genericResponse);
if ($staff['status'] === 'invited')           json_out($genericResponse); // they should be using the invite link
if ($staff['status'] === 'removed')           json_out($genericResponse); // no recovery for removed accounts
// Suspended is allowed; the reset just won't help them sign in until reactivated. We still send so we don't leak suspended status.

$token   = bin2hex(random_bytes(32));
$expires = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');

$pdo->prepare(
    'INSERT INTO admin_password_resets (token, staff_id, expires_at, ip_hash)
     VALUES (:t, :sid, :exp, :ip)'
)->execute([
    ':t'   => $token,
    ':sid' => (int)$staff['id'],
    ':exp' => $expires,
    ':ip'  => ip_hash(),
]);

$cfg     = relicheck_config();
$siteUrl = rtrim((string)($cfg['site_url'] ?? ''), '/');
if ($siteUrl === '') {
    $siteUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$resetLink = $siteUrl . '/admin-reset.html?token=' . $token;

$displayName = $staff['name'] ?: 'there';

$text =
    "Hi $displayName,\n\n" .
    "Someone (hopefully you) asked to reset the password for your ReliCheck admin account.\n\n" .
    "Use this link to set a new admin password. It expires in 24 hours:\n" .
    $resetLink . "\n\n" .
    "If you have two-factor authentication enabled, you'll still need your authenticator code on the next sign-in. Resetting the password does NOT disable 2FA.\n\n" .
    "If you didn't request this, you can ignore this email and your password stays the same.\n\n" .
    "- ReliCheck HR\n";

$html =
    '<p>Hi ' . htmlspecialchars($displayName, ENT_QUOTES) . ',</p>' .
    '<p>Someone (hopefully you) asked to reset the password for your <strong>ReliCheck admin</strong> account.</p>' .
    '<p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES) . '" ' .
    'style="display:inline-block;padding:10px 18px;background:#e85d3a;color:white;border-radius:6px;text-decoration:none;font-weight:600;">' .
    'Set a new admin password</a></p>' .
    '<p style="color:#5a607a;font-size:12px;">This link expires in 24 hours. If you have two-factor authentication enabled, you\'ll still need your authenticator code on the next sign-in. Resetting the password does NOT disable 2FA.</p>' .
    '<p style="color:#5a607a;font-size:12px;">If you didn\'t request this, you can ignore this email.</p>' .
    '<p style="color:#8a92ad;font-size:12px;">- ReliCheck HR</p>';

try {
    send_mail($email, 'Reset your ReliCheck admin password', $text, $html, [
        'from'      => 'hr@relichecksurvey.com',
        'from_name' => 'ReliCheck HR',
    ]);
} catch (Throwable $e) {
    error_log('[relicheck] admin password reset email failed: ' . $e->getMessage());
    // Still return the generic success response so we don't leak that the
    // email failed (which would imply the account exists).
}

json_out($genericResponse);
