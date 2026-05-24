<?php
// POST /api/admin/customers/reset_password.php
// Body: { customer_id?: int, customer_email?: string, reason?: string }
//
// Admin-triggered password reset. Generates a reset token using the same
// password_resets table as the public forgot-password flow, sends the
// reset email, and writes a row to admin_audit. Either customer_id or
// customer_email must be provided.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';
require_once __DIR__ . '/../../_mailer.php';

require_method('POST');
check_origin();
$admin = require_admin();

$body          = read_json_body();
$customerId    = (int)($body['customer_id'] ?? 0);
$customerEmail = strtolower(clean_string((string)($body['customer_email'] ?? ''), 255));
$reason        = clean_string((string)($body['reason'] ?? ''), 500);

if ($customerId <= 0 && $customerEmail === '') {
    fail('bad_input', 'Provide customer_id or customer_email.');
}

$pdo = db();
if ($customerId > 0) {
    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
} else {
    if (!valid_email($customerEmail)) fail('bad_email', 'Invalid customer_email.');
    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE email = :em');
    $stmt->execute([':em' => $customerEmail]);
}
$customer = $stmt->fetch();
if (!$customer) fail('not_found', 'Customer not found.', 404);

// Generate the same kind of token the public forgot-password flow uses.
$token   = bin2hex(random_bytes(32));
$expires = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');

$pdo->prepare(
    'INSERT INTO password_resets (token, user_id, expires_at, ip_hash)
     VALUES (:t, :uid, :exp, :ip)'
)->execute([
    ':t'   => $token,
    ':uid' => (int)$customer['id'],
    ':exp' => $expires,
    ':ip'  => ip_hash(),
]);

$cfg     = relicheck_config();
$siteUrl = rtrim((string)($cfg['site_url'] ?? ''), '/');
if ($siteUrl === '') {
    $siteUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$resetLink = $siteUrl . '/reset.html?token=' . $token;

$name = $customer['name'] ?: 'there';
$text =
    "Hi $name,\n\n" .
    "A ReliCheck administrator started a password reset for your account at your request.\n\n" .
    "Use this link to set a new password. It expires in 24 hours:\n" .
    $resetLink . "\n\n" .
    "If you did not ask for this, please contact support@relichecksurvey.com right away.\n\n" .
    "- ReliCheck\n";

$html =
    '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES) . ',</p>' .
    '<p>A ReliCheck administrator started a password reset for your account at your request.</p>' .
    '<p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES) . '" ' .
    'style="display:inline-block;padding:10px 18px;background:#e85d3a;color:white;border-radius:6px;text-decoration:none;font-weight:600;">' .
    'Set a new password</a></p>' .
    '<p style="color:#5a607a;font-size:12px;">This link expires in 24 hours. ' .
    'If you did not ask for this, please contact <a href="mailto:support@relichecksurvey.com">support@relichecksurvey.com</a>.</p>' .
    '<p style="color:#8a92ad;font-size:12px;">- ReliCheck</p>';

$emailSent = true;
try {
    // Admin-triggered reset: still goes from support@ since the customer
    // sees it as a support action, not an HR/security action.
    send_mail($customer['email'], 'A password reset was started for your ReliCheck account', $text, $html, [
        'from'      => 'support@relichecksurvey.com',
        'from_name' => 'ReliCheck Support',
    ]);
} catch (Throwable $e) {
    $emailSent = false;
    error_log('[relicheck] admin-triggered password reset email failed: ' . $e->getMessage());
}

// Audit. Logged whether or not the email actually delivered: the admin did
// take the action, and the reset token was stored. The "after" value reflects
// the email outcome so the audit trail tells the truth.
admin_audit_log(
    [
        'id'    => (int)$admin['id'],
        'email' => $admin['email'],
        'role'  => 'owner', // The mockup roles live client-side; backend currently treats every allowlisted email as owner. When phase-21 staff_users lands, replace with the row's role.
    ],
    'Reset password',
    'auth',
    [
        'severity'     => 'info',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . (int)$customer['id'],
        'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
        'before'       => '-',
        'after'        => $emailSent ? 'Reset email sent' : 'Reset token stored, email send failed (see error log)',
        'reason'       => $reason !== '' ? $reason : null,
    ]
);

json_out([
    'ok'         => true,
    'email_sent' => $emailSent,
    'message'    => $emailSent
        ? 'Password reset email sent to ' . $customer['email']
        : 'Reset token created. Email send failed; please ask the customer to check spam or contact support.',
]);
