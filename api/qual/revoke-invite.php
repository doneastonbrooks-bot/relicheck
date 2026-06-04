<?php
// POST /api/qual/revoke-invite.php
// Revoke an active invite. Lead researcher only.
// Body: { project_id, invite_id }
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
$user = require_auth();
release_session_lock();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId = (int)($body['project_id'] ?? 0);
$inviteId  = (int)($body['invite_id']  ?? 0);
if ($projectId <= 0 || $inviteId <= 0) fail('bad_input', 'project_id and invite_id required.');

qual_require_project($pdo, $uid, $projectId);

$upd = $pdo->prepare(
    "UPDATE qual_coder_invites SET status='revoked'
     WHERE id=:i AND project_id=:p AND invited_by=:u"
);
$upd->execute([':i' => $inviteId, ':p' => $projectId, ':u' => $uid]);

qual_audit($pdo, $projectId, $uid, 'invite_revoked', 'invite', $inviteId);
json_out(['ok' => true]);
