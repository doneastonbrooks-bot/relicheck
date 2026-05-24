<?php
// POST /api/admin/customers/org/accept.php
// Body: { token: string, action?: 'accept'|'decline' }
//
// Consumes a workspace invitation. The invitee must be signed in to a
// customer account whose email matches the invitation. On accept, an
// account_members row is created and account_invitations.accepted_at
// is set. On decline, only account_invitations.declined_at is set.
//
// This endpoint does NOT require admin auth - it's the invitee acting,
// not an admin. It uses the regular customer session (require_auth).

declare(strict_types=1);

require_once __DIR__ . '/../../../_helpers.php';
require_once __DIR__ . '/../../../_session.php';
require_once __DIR__ . '/../../../_admin_audit.php';
require_once __DIR__ . '/../../../_tiers.php';

require_method('POST');
check_origin();

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $user = current_user();
    if (!$user) fail('not_signed_in', 'Sign in to your ReliCheck account first, then click the invitation link again.', 401);

    $body   = read_json_body();
    $token  = is_string($body['token'] ?? null) ? trim((string)$body['token']) : '';
    $action = clean_string((string)($body['action'] ?? 'accept'), 16);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) fail('bad_token', 'This invitation link is invalid.', 400);
    if (!in_array($action, ['accept', 'decline'], true)) fail('bad_action', 'action must be "accept" or "decline".');

    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT id, owner_id, email, role, expires_at, accepted_at, declined_at
           FROM account_invitations WHERE token = :t LIMIT 1'
    );
    $stmt->execute([':t' => $token]);
    $inv = $stmt->fetch();
    if (!$inv) fail('bad_token', 'This invitation link is invalid or has been used.', 400);
    if (!empty($inv['accepted_at']))                                                            fail('already_accepted', 'This invitation has already been accepted.', 400);
    if (!empty($inv['declined_at']))                                                            fail('already_declined', 'This invitation has been declined and cannot be accepted now.', 400);
    if (empty($inv['expires_at']) || strtotime((string)$inv['expires_at']) < time())            fail('expired_token', 'This invitation has expired. Ask the workspace owner for a fresh one.', 400);

    $signedInEmail = strtolower(trim((string)($user['email'] ?? '')));
    $inviteEmail   = strtolower(trim((string)$inv['email']));
    if ($signedInEmail !== $inviteEmail) {
        fail('email_mismatch',
            'You\'re signed in as ' . $signedInEmail . ' but this invitation is for ' . $inviteEmail .
            '. Sign out and sign back in with the right account, then click the link again.', 403);
    }

    // Look up owner for the audit row.
    $ownerStmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id');
    $ownerStmt->execute([':id' => (int)$inv['owner_id']]);
    $owner = $ownerStmt->fetch() ?: ['id' => (int)$inv['owner_id'], 'email' => '?', 'name' => ''];

    if ($action === 'decline') {
        $pdo->prepare('UPDATE account_invitations SET declined_at = NOW() WHERE id = :id')
            ->execute([':id' => (int)$inv['id']]);

        admin_audit_log(
            ['id' => (int)$user['id'], 'email' => (string)$user['email'], 'role' => 'invitee'],
            'Declined org invitation',
            'customer',
            [
                'severity'     => 'info',
                'target_type'  => 'customer',
                'target_id'    => 'cus_' . (int)$inv['owner_id'],
                'target_label' => trim(($owner['name'] ?: '') . ' (' . $owner['email'] . ')'),
                'before'       => 'pending invite for ' . $inviteEmail,
                'after'        => 'declined',
                'reason'       => null,
            ]
        );

        json_out([
            'ok'      => true,
            'action'  => 'decline',
            'message' => 'Invitation declined. The workspace owner has been notified in their audit log.',
        ]);
    }

    // accept
    // Recheck the workspace owner's seat cap at accept time. The cap was
    // also checked when the admin sent the invite, but the owner may have
    // downgraded their plan in the meantime. We count seated members only;
    // other pending invites are not held against this user.
    $tier   = tier_for_user((int)$inv['owner_id']);
    $cap    = (int)($tier['features']['team_sharing'] ?? 0);
    $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM account_members WHERE owner_id = :o');
    $cntStmt->execute([':o' => (int)$inv['owner_id']]);
    $seated = (int)($cntStmt->fetch()['c'] ?? 0);
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
        // Create the membership row (idempotent via unique key).
        $pdo->prepare(
            'INSERT INTO account_members (owner_id, member_id, role, added_by)
             VALUES (:o, :m, :r, :ab)'
        )->execute([
            ':o'  => (int)$inv['owner_id'],
            ':m'  => (int)$user['id'],
            ':r'  => $inv['role'],
            ':ab' => (int)$inv['owner_id'], // workspace owner is the source of the invite chain
        ]);
        $pdo->prepare('UPDATE account_invitations SET accepted_at = NOW() WHERE id = :id')
            ->execute([':id' => (int)$inv['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // Unique-key violation on (owner_id, member_id) -> already a member.
        if (strpos($e->getMessage(), 'uniq_account_members') !== false) {
            // Mark invitation accepted anyway so it can't be reused.
            $pdo->prepare('UPDATE account_invitations SET accepted_at = NOW() WHERE id = :id')
                ->execute([':id' => (int)$inv['id']]);
            // Surface as success so the user lands on a friendly page.
        } else {
            throw $e;
        }
    }

    admin_audit_log(
        ['id' => (int)$user['id'], 'email' => (string)$user['email'], 'role' => 'invitee'],
        'Accepted org invitation',
        'customer',
        [
            'severity'     => 'info',
            'target_type'  => 'customer',
            'target_id'    => 'cus_' . (int)$inv['owner_id'],
            'target_label' => trim(($owner['name'] ?: '') . ' (' . $owner['email'] . ')'),
            'before'       => 'pending invite for ' . $inviteEmail,
            'after'        => 'joined as ' . $inv['role'],
            'reason'       => null,
        ]
    );

    json_out([
        'ok'      => true,
        'action'  => 'accept',
        'role'    => $inv['role'],
        'message' => 'You have joined ' . ($owner['name'] ?: $owner['email']) . '\'s ReliCheck workspace as a ' . ($inv['role'] === 'editor' ? 'Editor' : 'Viewer') . '.',
    ]);

} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error'   => 'accept_uncaught',
        'message' => $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine(),
        'class'   => get_class($e),
    ]);
    exit;
}
