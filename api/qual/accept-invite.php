<?php
// POST /api/qual/accept-invite.php
// Accept a dual-coder invite. Called by the second coder on their first visit.
// Body: { token }
// Returns: { ok, project_id, project_title, already_accepted }
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
$user  = require_auth();
$pdo   = db();
$uid   = (int)$user['id'];
$body  = read_json_body();
$token = trim((string)($body['token'] ?? ''));
if ($token === '') fail('bad_input', 'Missing token.');

qual_ensure_schema($pdo);

$s = $pdo->prepare(
    "SELECT qi.*, qp.title AS project_title, qp.user_id AS owner_id
     FROM qual_coder_invites qi
     JOIN qual_projects qp ON qp.id=qi.project_id
     WHERE qi.token=:t LIMIT 1"
);
$s->execute([':t' => $token]);
$inv = $s->fetch(PDO::FETCH_ASSOC);

if (!$inv) fail('not_found', 'Invite not found or expired.', 404);
if ($inv['status'] === 'revoked') fail('forbidden', 'This invite has been revoked.', 403);
if ($inv['owner_id'] == $uid) fail('forbidden', 'You cannot code your own project as a second coder.', 403);

if ($inv['status'] === 'accepted') {
    if ((int)$inv['accepted_by'] !== $uid) {
        fail('conflict', 'This invite has already been accepted by someone else.', 409);
    }
    // Already accepted by this user — idempotent
    json_out([
        'ok'              => true,
        'project_id'      => (int)$inv['project_id'],
        'project_title'   => $inv['project_title'],
        'already_accepted'=> true,
    ]);
    return;
}

// Accept it
$upd = $pdo->prepare(
    "UPDATE qual_coder_invites
     SET status='accepted', accepted_by=:u, accepted_at=NOW()
     WHERE token=:t AND status='pending'"
);
$upd->execute([':u' => $uid, ':t' => $token]);

qual_audit($pdo, (int)$inv['project_id'], $uid, 'coder_accepted_invite', 'invite', (int)$inv['id']);

json_out([
    'ok'               => true,
    'project_id'       => (int)$inv['project_id'],
    'project_title'    => $inv['project_title'],
    'already_accepted' => false,
]);
