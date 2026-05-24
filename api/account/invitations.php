<?php
// /api/account/invitations.php - view + act on invitations addressed to
// the signed-in user.
//
// GET                  -> list pending invitations whose email matches the user
// POST  action=accept  -> accept an invitation by token, creates an
//                         account_members row
// POST  action=decline -> mark an invitation declined

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_team.php';
require_once __DIR__ . '/../_tiers.php';

require_method('GET', 'POST');
$user = require_auth();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT i.id, i.token, i.role, i.created_at, i.expires_at,
                u.email AS owner_email, u.name AS owner_name, i.owner_id
           FROM account_invitations i
      LEFT JOIN users u ON u.id = i.owner_id
          WHERE LOWER(i.email) = LOWER(:e)
            AND i.accepted_at IS NULL AND i.declined_at IS NULL
            AND i.expires_at > NOW()
       ORDER BY i.created_at DESC'
    );
    $stmt->execute([':e' => $user['email']]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = [
            'id'          => (int)$r['id'],
            'token'       => $r['token'],
            'role'        => $r['role'],
            'created_at'  => $r['created_at'],
            'expires_at'  => $r['expires_at'],
            'owner_id'    => (int)$r['owner_id'],
            'owner_email' => $r['owner_email'],
            'owner_name'  => $r['owner_name'],
        ];
    }
    json_out(['invitations' => $rows]);
}

check_origin();
$body = read_json_body();
$action = (string)($body['action'] ?? '');
$token = (string)($body['token'] ?? '');
if ($token === '' || strlen($token) > 80) fail('bad_token', 'Missing or invalid invitation token.');

$find = $pdo->prepare(
    'SELECT id, owner_id, email, role, accepted_at, declined_at, expires_at
       FROM account_invitations WHERE token = :t LIMIT 1'
);
$find->execute([':t' => $token]);
$inv = $find->fetch();
if (!$inv) fail('not_found', 'That invitation could not be found.', 404);
if (!empty($inv['accepted_at'])) fail('already_accepted', 'That invitation has already been accepted.');
if (!empty($inv['declined_at']))  fail('already_declined', 'That invitation has already been declined.');
if (strtotime((string)$inv['expires_at'] . ' UTC') < time()) fail('expired', 'That invitation has expired. Ask the workspace owner to send a new one.');
if (strtolower((string)$inv['email']) !== strtolower((string)$user['email'])) {
    fail('email_mismatch', 'This invitation was sent to a different email address. Sign in with the email it was sent to.', 403);
}
if ((int)$inv['owner_id'] === (int)$user['id']) {
    fail('cant_accept_own', 'You cannot accept an invitation to your own workspace.');
}

if ($action === 'accept') {
    // Recheck the workspace owner's seat cap at accept time. The cap was
    // also checked when the invite was sent, but the owner may have
    // downgraded their plan or filled remaining seats with other invitees
    // in the meantime. We count seated members only (existing
    // account_members rows) and confirm there is room for one more. We
    // don't count other pending invites against the cap here, because
    // those may never accept and we shouldn't block this user on someone
    // else's outstanding invitation.
    $tier   = tier_for_user((int)$inv['owner_id']);
    $cap    = (int)($tier['features']['team_sharing'] ?? 0);
    $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM account_members WHERE owner_id = :o');
    $cntStmt->execute([':o' => (int)$inv['owner_id']]);
    $seated = (int)($cntStmt->fetch()['c'] ?? 0);

    // Idempotency: a duplicate click from someone already on the team
    // should land softly via ON DUPLICATE KEY UPDATE below, not get
    // blocked by the cap.
    $alreadyStmt = $pdo->prepare(
        'SELECT 1 FROM account_members WHERE owner_id = :o AND member_id = :m LIMIT 1'
    );
    $alreadyStmt->execute([':o' => (int)$inv['owner_id'], ':m' => (int)$user['id']]);
    $alreadyMember = (bool)$alreadyStmt->fetchColumn();

    if (!$alreadyMember) {
        if ($cap < 1) {
            fail('plan_required',
                 'The workspace owner\'s plan no longer includes team seats. Ask them to upgrade, then try the link again.',
                 402);
        }
        if ($seated >= $cap) {
            fail('seat_cap_reached',
                 'The workspace owner has reached their plan\'s team seat limit (' . $cap . '). Ask them to remove a member or upgrade, then try the link again.',
                 402,
                 ['limit' => $cap, 'current' => $seated]);
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO account_members (owner_id, member_id, role, added_by)
             VALUES (:o, :m, :r, :o)
             ON DUPLICATE KEY UPDATE role = VALUES(role)'
        )->execute([':o' => $inv['owner_id'], ':m' => $user['id'], ':r' => $inv['role']]);
        $pdo->prepare('UPDATE account_invitations SET accepted_at = NOW() WHERE id = :id')
            ->execute([':id' => $inv['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    json_out(['ok' => true, 'workspace_owner_id' => (int)$inv['owner_id'], 'role' => $inv['role']]);
}

if ($action === 'decline') {
    $pdo->prepare('UPDATE account_invitations SET declined_at = NOW() WHERE id = :id')
        ->execute([':id' => $inv['id']]);
    json_out(['ok' => true]);
}

fail('bad_action', 'Unknown action. Use "accept" or "decline".');
