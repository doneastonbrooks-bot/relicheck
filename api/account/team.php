<?php
// /api/account/team.php - manage team members and invitations for the
// current user's workspace.
//
// GET                         -> list members + pending invitations
// POST  action=invite         -> email an invitation { email, role }
// POST  action=cancel_invite  -> revoke a pending invite { id }
// POST  action=remove_member  -> remove a member { member_id }
// POST  action=change_role    -> update a member's role { member_id, role }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/../_team.php';
require_once __DIR__ . '/../_mailer.php';

require_method('GET', 'POST');
$user = require_auth();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$ownerId = (int)$user['id'];

function load_team_state(PDO $pdo, int $ownerId): array
{
    $m = $pdo->prepare(
        'SELECT am.member_id, am.role, am.created_at, u.email, u.name
           FROM account_members am
      LEFT JOIN users u ON u.id = am.member_id
          WHERE am.owner_id = :o
       ORDER BY am.created_at ASC'
    );
    $m->execute([':o' => $ownerId]);
    $members = [];
    foreach ($m->fetchAll() as $r) {
        $members[] = [
            'member_id' => (int)$r['member_id'],
            'email'     => $r['email'],
            'name'      => $r['name'],
            'role'      => $r['role'],
            'added_at'  => $r['created_at'],
        ];
    }

    $i = $pdo->prepare(
        'SELECT id, email, role, created_at, expires_at
           FROM account_invitations
          WHERE owner_id = :o AND accepted_at IS NULL AND declined_at IS NULL AND expires_at > NOW()
       ORDER BY created_at ASC'
    );
    $i->execute([':o' => $ownerId]);
    $invites = [];
    foreach ($i->fetchAll() as $r) {
        $invites[] = [
            'id'         => (int)$r['id'],
            'email'      => $r['email'],
            'role'       => $r['role'],
            'created_at' => $r['created_at'],
            'expires_at' => $r['expires_at'],
        ];
    }

    $tier = tier_for_user($ownerId);
    $cap = (int)($tier['features']['team_sharing'] ?? 0);
    return [
        'members'       => $members,
        'invitations'   => $invites,
        'seats_used'    => count_seats_used($ownerId),
        'seat_cap'      => $cap,
        'feature_label' => $tier['tier_label'],
    ];
}

if ($method === 'GET') {
    json_out(load_team_state($pdo, $ownerId));
}

check_origin();
$body = read_json_body();
$action = (string)($body['action'] ?? '');

$tier = tier_for_user($ownerId);
$seatCap = (int)($tier['features']['team_sharing'] ?? 0);
if ($seatCap < 1) {
    fail('plan_required', 'Team seats are included on the Professional and Business plans. Upgrade to share your workspace.', 402, [
        'feature' => 'team_sharing',
    ]);
}

if ($action === 'invite') {
    // Block invites from unverified accounts. Stops spam-style abuse where
    // an unverified account uses our mailer to send arbitrary invite emails.
    try {
        $vstmt = db()->prepare('SELECT email_verified_at FROM users WHERE id = :i LIMIT 1');
        $vstmt->execute([':i' => (int)$user['id']]);
        $vrow = $vstmt->fetch();
        if ($vrow && $vrow['email_verified_at'] === null) {
            fail('email_not_verified',
                 'Please verify your email before inviting teammates. Check your inbox for the verification link.',
                 403);
        }
    } catch (Throwable $e) {
        error_log('[relicheck] team invite email-verify gate skipped: ' . $e->getMessage());
    }
    $email = strtolower(clean_string($body['email'] ?? '', 255));
    if (!valid_email($email)) fail('bad_email', 'Enter a valid email address.');
    $role = (string)($body['role'] ?? 'viewer');
    if (!in_array($role, [ROLE_EDITOR, ROLE_VIEWER], true)) {
        fail('bad_role', 'Role must be "editor" or "viewer".');
    }
    if ($email === strtolower((string)$user['email'])) {
        fail('cant_invite_self', 'You cannot invite yourself.');
    }

    // Check seat cap (active members + pending invites)
    $used = count_seats_used($ownerId);
    if ($used >= $seatCap) {
        fail('seat_cap_reached', 'You have reached your plan\'s team seat limit (' . $seatCap . '). Remove a member or upgrade.', 402, [
            'limit' => $seatCap, 'current' => $used,
        ]);
    }

    // Block duplicate active invites + duplicate existing memberships
    $dup = $pdo->prepare(
        'SELECT id FROM account_invitations
          WHERE owner_id = :o AND email = :e AND accepted_at IS NULL AND declined_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $dup->execute([':o' => $ownerId, ':e' => $email]);
    if ($dup->fetch()) fail('already_invited', 'There is already a pending invitation to that email.', 409);

    $existsStmt = $pdo->prepare(
        'SELECT u.id FROM users u
      JOIN account_members am ON am.member_id = u.id
         WHERE u.email = :e AND am.owner_id = :o LIMIT 1'
    );
    $existsStmt->execute([':e' => $email, ':o' => $ownerId]);
    if ($existsStmt->fetch()) fail('already_member', 'That email is already a member of your workspace.', 409);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO account_invitations (owner_id, email, role, token, invited_by, expires_at)
         VALUES (:o, :e, :r, :t, :ib, DATE_ADD(NOW(), INTERVAL 14 DAY))'
    )->execute([':o' => $ownerId, ':e' => $email, ':r' => $role, ':t' => $token, ':ib' => $ownerId]);

    // Try to send the invite email; do not fail the API call if SMTP is misconfigured.
    $cfg = relicheck_config();
    $base = (string)($cfg['public_base_url'] ?? 'https://relicheck.com');
    $link = rtrim($base, '/') . '/accept-invite.html?token=' . urlencode($token);
    try {
        $subject = ($user['name'] ?: $user['email']) . ' invited you to a ReliCheck workspace';
        $bodyHtml = '<p>' . htmlspecialchars((string)($user['name'] ?: $user['email'])) . ' added you as a <strong>' . $role . '</strong> on their ReliCheck workspace.</p>'
                  . '<p><a href="' . htmlspecialchars($link) . '">Accept the invitation</a></p>'
                  . '<p style="color:#888;font-size:12px;">Link expires in 14 days. If you didn\'t expect this, you can safely ignore the email.</p>';
        send_mail($email, $subject, $bodyHtml);
    } catch (Throwable $e) {
        // Swallow: surface the link in the response so the inviter can copy it manually
    }

    json_out(['ok' => true, 'invite_link' => $link, 'team' => load_team_state($pdo, $ownerId)]);
}

if ($action === 'cancel_invite') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) fail('bad_id', 'Missing invite id.');
    $upd = $pdo->prepare('DELETE FROM account_invitations WHERE id = :id AND owner_id = :o AND accepted_at IS NULL');
    $upd->execute([':id' => $id, ':o' => $ownerId]);
    json_out(['ok' => true, 'team' => load_team_state($pdo, $ownerId)]);
}

if ($action === 'remove_member') {
    $mid = (int)($body['member_id'] ?? 0);
    if ($mid <= 0) fail('bad_id', 'Missing member_id.');
    $pdo->prepare('DELETE FROM account_members WHERE owner_id = :o AND member_id = :m')
        ->execute([':o' => $ownerId, ':m' => $mid]);
    json_out(['ok' => true, 'team' => load_team_state($pdo, $ownerId)]);
}

if ($action === 'change_role') {
    $mid = (int)($body['member_id'] ?? 0);
    $role = (string)($body['role'] ?? '');
    if ($mid <= 0) fail('bad_id', 'Missing member_id.');
    if (!in_array($role, [ROLE_EDITOR, ROLE_VIEWER], true)) fail('bad_role', 'Role must be "editor" or "viewer".');
    $upd = $pdo->prepare('UPDATE account_members SET role = :r WHERE owner_id = :o AND member_id = :m');
    $upd->execute([':r' => $role, ':o' => $ownerId, ':m' => $mid]);
    if ($upd->rowCount() === 0) fail('not_found', 'No such member in your workspace.', 404);
    json_out(['ok' => true, 'team' => load_team_state($pdo, $ownerId)]);
}

fail('bad_action', 'Unknown action. Use invite, cancel_invite, remove_member, or change_role.');
