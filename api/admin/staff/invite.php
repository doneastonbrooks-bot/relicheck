<?php
// POST /api/admin/staff/invite.php
// Body: { email: string, name?: string, role: string, message?: string }
//
// Creates or refreshes a staff_users row in 'invited' status, generates
// a one-time token, and sends an invitation email containing the
// acceptance link. The invitee accepts via /accept-staff-invite.html
// which calls accept.php.
//
// If a staff_users row already exists for the email:
//   - active   -> refused (already a staff member; use update.php to change role)
//   - invited  -> token + expiry are refreshed and the email is resent
//   - suspended/removed -> re-invitation flips them back to 'invited'

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';
require_once __DIR__ . '/../../_mailer.php';

require_method('POST');
check_origin();
$admin = require_admin();

$body    = read_json_body();
$email   = strtolower(clean_string((string)($body['email'] ?? ''), 190));
$name    = clean_string((string)($body['name']    ?? ''), 120);
$role    = clean_string((string)($body['role']    ?? 'cs'), 32);
$message = clean_string((string)($body['message'] ?? ''), 500);

if (!valid_email($email)) fail('bad_email', 'Invalid email.');

$validRoles = ['upper', 'supervisor', 'cs', 'tech', 'readonly'];
if (!in_array($role, $validRoles, true)) {
    fail('bad_role', 'Role must be one of: ' . implode(', ', $validRoles) . '. Owner is reserved.');
}

$pdo = db();
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'staff_users'")->fetchColumn();
} catch (Throwable $e) {
    fail('schema_error', 'Could not check schema: ' . $e->getMessage(), 500);
}
if (!$tableExists) fail('migration_missing', 'Phase 26 migration not applied. The staff_users table does not exist yet.', 500);

// Refuse to invite a permanent allowlist admin (already has access).
if (in_array($email, relicheck_admin_emails(), true)) {
    fail('already_admin', 'That email is already a permanent admin via the allowlist.');
}

// Look up any existing row for this email.
$find = $pdo->prepare('SELECT id, status FROM staff_users WHERE email = :em');
$find->execute([':em' => $email]);
$existing = $find->fetch();

if ($existing && $existing['status'] === 'active') {
    fail('already_active', 'That email is already an active staff member. Use Change role instead.');
}

$token   = bin2hex(random_bytes(32));
$expires = (new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');

if ($existing) {
    // Refresh the existing invite (or revive a suspended/removed account).
    $up = $pdo->prepare(
        "UPDATE staff_users
            SET name = :n,
                role = :r,
                status = 'invited',
                invite_token = :t,
                invite_expires = :exp,
                added_by_user_id = :ab,
                suspended_at = NULL,
                removed_at = NULL
          WHERE id = :id"
    );
    $up->execute([
        ':n'   => $name,
        ':r'   => $role,
        ':t'   => $token,
        ':exp' => $expires,
        ':ab'  => (int)$admin['id'],
        ':id'  => (int)$existing['id'],
    ]);
    $staffId = (int)$existing['id'];
} else {
    $ins = $pdo->prepare(
        'INSERT INTO staff_users (email, name, role, status, invite_token, invite_expires, added_by_user_id)
         VALUES (:em, :n, :r, "invited", :t, :exp, :ab)'
    );
    $ins->execute([
        ':em'  => $email,
        ':n'   => $name,
        ':r'   => $role,
        ':t'   => $token,
        ':exp' => $expires,
        ':ab'  => (int)$admin['id'],
    ]);
    $staffId = (int)$pdo->lastInsertId();
}

// Build the acceptance link.
$cfg     = relicheck_config();
$siteUrl = rtrim((string)($cfg['site_url'] ?? ''), '/');
if ($siteUrl === '') {
    $siteUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$acceptLink = $siteUrl . '/accept-staff-invite.html?token=' . $token;

$displayName = $name !== '' ? $name : 'there';
$roleLabels = [
    'upper'      => 'Upper Management',
    'supervisor' => 'Supervisor',
    'cs'         => 'Customer Service',
    'tech'       => 'Technical Services',
    'readonly'   => 'Read-only Admin',
];
$roleLabel = $roleLabels[$role] ?? $role;

$text =
    "Hi $displayName,\n\n" .
    "You've been invited to join the ReliCheck admin team as $roleLabel.\n\n" .
    ($message !== '' ? "Note from " . $admin['email'] . ":\n$message\n\n" : '') .
    "Accept the invitation here. The link is valid for 7 days:\n" .
    $acceptLink . "\n\n" .
    "If you weren't expecting this, you can ignore this email.\n\n" .
    "- ReliCheck\n";

$html =
    '<p>Hi ' . htmlspecialchars($displayName, ENT_QUOTES) . ',</p>' .
    '<p>You\'ve been invited to join the ReliCheck admin team as <strong>' . htmlspecialchars($roleLabel, ENT_QUOTES) . '</strong>.</p>' .
    ($message !== ''
        ? '<p style="background:#f5f7fb;padding:12px 14px;border-radius:6px;border-left:3px solid #e85d3a;"><em>Note from ' . htmlspecialchars($admin['email'], ENT_QUOTES) . ':</em><br>' . nl2br(htmlspecialchars($message, ENT_QUOTES)) . '</p>'
        : '') .
    '<p><a href="' . htmlspecialchars($acceptLink, ENT_QUOTES) . '" ' .
    'style="display:inline-block;padding:10px 18px;background:#e85d3a;color:white;border-radius:6px;text-decoration:none;font-weight:600;">' .
    'Accept the invitation</a></p>' .
    '<p style="color:#5a607a;font-size:12px;">This link expires in 7 days. If you weren\'t expecting this email, you can ignore it.</p>' .
    '<p style="color:#8a92ad;font-size:12px;">- ReliCheck</p>';

$emailSent = true;
try {
    // Staff invites are HR correspondence; replies should route to hr@
    // so the inviting admin doesn't get them in their personal inbox.
    send_mail($email, 'You\'ve been invited to join the ReliCheck admin team', $text, $html, [
        'from'      => 'hr@relichecksurvey.com',
        'from_name' => 'ReliCheck HR',
    ]);
} catch (Throwable $e) {
    $emailSent = false;
    error_log('[relicheck] staff invite email failed: ' . $e->getMessage());
}

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => 'owner'],
    $existing ? 'Re-invited staff member' : 'Invited staff member',
    'employee',
    [
        'severity'     => 'info',
        'target_type'  => 'employee',
        'target_id'    => 'staff:' . $staffId,
        'target_label' => trim($name . ' (' . $email . ')'),
        'before'       => $existing ? ($existing['status'] ?? '-') : '-',
        'after'        => 'invited * role: ' . $role . ($emailSent ? '' : ' (email send failed)'),
        'reason'       => null,
    ]
);

json_out([
    'ok'         => true,
    'staff_id'   => $staffId,
    'email_sent' => $emailSent,
    'message'    => $emailSent
        ? 'Invitation sent to ' . $email . '. Link valid for 7 days.'
        : 'Invitation recorded. Email send failed; copy the acceptance link directly: ' . $acceptLink,
    'accept_link' => $emailSent ? null : $acceptLink, // surface only when email failed
]);
