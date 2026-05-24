<?php
// POST /api/admin/customers/org/invite.php
// Body: {
//   owner_id?: int, owner_email?: string,    (the workspace owner)
//   email: string,                            (invitee email)
//   role: 'editor'|'viewer',
//   message?: string,                         (optional note included in email)
//   reason?: string                           (audit reason)
// }
//
// Admin-side workspace invitation. Creates an account_invitations row
// (Phase 18 schema), generates a one-time token, and emails the invitee
// from support@. The invitee accepts via /accept-org-invite.html, which
// converts the invitation to an account_members row.
//
// If a row already exists for this owner+email:
//   - already accepted   -> refuses (use Remove user instead, then re-invite)
//   - pending invitation -> refreshes the token and resends the email

declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../_session.php';
require_once __DIR__ . '/../../../_admin.php';
require_once __DIR__ . '/../../../_admin_audit.php';
require_once __DIR__ . '/../../../_mailer.php';

require_method('POST');
check_origin();

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $admin = require_admin();

    $body        = read_json_body();
    $ownerId     = (int)($body['owner_id'] ?? 0);
    $ownerEmail  = strtolower(clean_string((string)($body['owner_email'] ?? ''), 255));
    $invEmail    = strtolower(clean_string((string)($body['email']       ?? ''), 190));
    $role        = clean_string((string)($body['role']    ?? 'viewer'), 16);
    $message     = clean_string((string)($body['message'] ?? ''), 500);
    $reason      = clean_string((string)($body['reason']  ?? ''), 500);

    if ($ownerId <= 0 && $ownerEmail === '') fail('bad_input', 'Provide owner_id or owner_email.');
    if (!valid_email($invEmail))             fail('bad_email', 'Invalid invitee email.');
    if (!in_array($role, ['editor', 'viewer'], true)) {
        fail('bad_role', 'role must be "editor" or "viewer".');
    }

    $pdo = db();

    try {
        $hasInvTable = (bool)$pdo->query("SHOW TABLES LIKE 'account_invitations'")->fetchColumn();
        $hasMemTable = (bool)$pdo->query("SHOW TABLES LIKE 'account_members'")->fetchColumn();
    } catch (Throwable $e) { $hasInvTable = false; $hasMemTable = false; }
    if (!$hasInvTable || !$hasMemTable) {
        fail('migration_missing', 'Phase 18 migration not applied. account_invitations and account_members must exist.', 500);
    }

    // Resolve owner.
    if ($ownerId > 0) {
        $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id');
        $stmt->execute([':id' => $ownerId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE email = :em');
        $stmt->execute([':em' => $ownerEmail]);
    }
    $owner = $stmt->fetch();
    if (!$owner) fail('not_found', 'Workspace owner not found.', 404);
    $ouid = (int)$owner['id'];

    // Refuse self-invite (the owner is implicitly always in their own workspace).
    if (strtolower((string)$owner['email']) === $invEmail) {
        fail('self_invite', 'The workspace owner is already a member of their own org. No invite needed.');
    }

    // If the invitee is already an active member, refuse cleanly.
    $alreadyMember = $pdo->prepare(
        'SELECT 1 FROM account_members am
           JOIN users u ON u.id = am.member_id
          WHERE am.owner_id = :o AND u.email = :em LIMIT 1'
    );
    $alreadyMember->execute([':o' => $ouid, ':em' => $invEmail]);
    if ($alreadyMember->fetchColumn()) {
        fail('already_member', $invEmail . ' is already a member of this workspace. Remove them first to re-invite.');
    }

    $token   = bin2hex(random_bytes(32));
    // 14 days matches the user-facing flow in api/account/team.php so admin-
    // initiated invites don't expire sooner than self-service invites.
    $expires = (new DateTimeImmutable('+14 days'))->format('Y-m-d H:i:s');

    // Refresh an outstanding invite for this email if one exists; otherwise insert.
    $find = $pdo->prepare(
        'SELECT id FROM account_invitations
          WHERE owner_id = :o AND email = :em AND accepted_at IS NULL AND declined_at IS NULL
          LIMIT 1'
    );
    $find->execute([':o' => $ouid, ':em' => $invEmail]);
    $existing = $find->fetch();

    if ($existing) {
        $pdo->prepare(
            'UPDATE account_invitations
                SET role = :r,
                    token = :t,
                    expires_at = :exp,
                    invited_by = :ib
              WHERE id = :id'
        )->execute([
            ':r'   => $role,
            ':t'   => $token,
            ':exp' => $expires,
            ':ib'  => (int)$admin['id'],
            ':id'  => (int)$existing['id'],
        ]);
        $invId = (int)$existing['id'];
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO account_invitations (owner_id, email, role, token, invited_by, expires_at)
             VALUES (:o, :em, :r, :t, :ib, :exp)'
        );
        $ins->execute([
            ':o'   => $ouid,
            ':em'  => $invEmail,
            ':r'   => $role,
            ':t'   => $token,
            ':ib'  => (int)$admin['id'],
            ':exp' => $expires,
        ]);
        $invId = (int)$pdo->lastInsertId();
    }

    // Build acceptance URL.
    $cfg     = relicheck_config();
    $siteUrl = rtrim((string)($cfg['site_url'] ?? ''), '/');
    if ($siteUrl === '') {
        $siteUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $acceptLink = $siteUrl . '/accept-org-invite.html?token=' . $token;

    $ownerLabel = ($owner['name'] ?: $owner['email']);
    $roleLabel  = $role === 'editor' ? 'Editor' : 'Viewer';

    $text =
        "Hi,\n\n" .
        $ownerLabel . " has invited you to join their ReliCheck workspace as a $roleLabel.\n\n" .
        ($message !== '' ? "Note from the workspace owner:\n$message\n\n" : '') .
        "Accept the invitation here. The link is valid for 14 days:\n" .
        $acceptLink . "\n\n" .
        "If you weren't expecting this, you can ignore this email.\n\n" .
        "- ReliCheck\n";

    $html =
        '<p>Hi,</p>' .
        '<p><strong>' . htmlspecialchars($ownerLabel, ENT_QUOTES) . '</strong> has invited you to join their ReliCheck workspace as a <strong>' . htmlspecialchars($roleLabel, ENT_QUOTES) . '</strong>.</p>' .
        ($message !== ''
            ? '<p style="background:#f5f7fb;padding:12px 14px;border-radius:6px;border-left:3px solid #e85d3a;"><em>Note from the workspace owner:</em><br>' . nl2br(htmlspecialchars($message, ENT_QUOTES)) . '</p>'
            : '') .
        '<p><a href="' . htmlspecialchars($acceptLink, ENT_QUOTES) . '" ' .
        'style="display:inline-block;padding:10px 18px;background:#e85d3a;color:white;border-radius:6px;text-decoration:none;font-weight:600;">' .
        'Accept the invitation</a></p>' .
        '<p style="color:#5a607a;font-size:12px;">This link expires in 14 days. If you weren\'t expecting this, you can ignore the email.</p>' .
        '<p style="color:#8a92ad;font-size:12px;">- ReliCheck</p>';

    $emailSent = true;
    try {
        send_mail($invEmail, $ownerLabel . ' invited you to a ReliCheck workspace', $text, $html, [
            'from'      => 'support@relichecksurvey.com',
            'from_name' => 'ReliCheck Support',
        ]);
    } catch (Throwable $e) {
        $emailSent = false;
        error_log('[relicheck] org invite email failed: ' . $e->getMessage());
    }

    admin_audit_log(
        ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => $admin['role'] ?? 'owner'],
        $existing ? 'Re-invited org user' : 'Invited org user',
        'customer',
        [
            'severity'     => 'info',
            'target_type'  => 'customer',
            'target_id'    => 'cus_' . $ouid,
            'target_label' => trim(($owner['name'] ?: '') . ' (' . $owner['email'] . ')'),
            'before'       => '-',
            'after'        => 'invited ' . $invEmail . ' as ' . $role . ($emailSent ? '' : ' (email send failed)'),
            'reason'       => $reason !== '' ? $reason : null,
        ]
    );

    json_out([
        'ok'          => true,
        'invitation'  => [
            'id'      => $invId,
            'email'   => $invEmail,
            'role'    => $role,
            'expires' => $expires,
        ],
        'email_sent'  => $emailSent,
        'message'     => $emailSent
            ? 'Invitation sent to ' . $invEmail . '. Link valid for 14 days.'
            : 'Invitation recorded. Email send failed; copy the acceptance link directly: ' . $acceptLink,
        'accept_link' => $emailSent ? null : $acceptLink,
    ]);

} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error'   => 'invite_uncaught',
        'message' => $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine(),
        'class'   => get_class($e),
    ]);
    exit;
}
