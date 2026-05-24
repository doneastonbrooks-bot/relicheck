<?php
// Phase 181 — List the invites + coder memberships for a project.
// GET ?project_id=N → { ok, invites: [...], coders: [...] }
// POST { project_id, invite_id, action: 'revoke' } → revoke a pending invite.
// POST { project_id, coder_user_id, action: 'remove_coder' } → revoke a
// coder membership.
// Owner-only on all operations.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'POST');

$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = read_json_body();
    $projectId = isset($payload['project_id']) ? (int)$payload['project_id'] : 0;
    $action    = isset($payload['action']) ? (string)$payload['action'] : '';
    if ($projectId <= 0) fail('bad_request', 'project_id is required.', 400);
    mm_require_project($pdo, $uid, $projectId);

    if ($action === 'revoke') {
        $inviteId = isset($payload['invite_id']) ? (int)$payload['invite_id'] : 0;
        if ($inviteId <= 0) fail('bad_request', 'invite_id is required.', 400);
        $upd = $pdo->prepare(
            'UPDATE mm_coder_invites SET revoked_at = NOW() ' .
            ' WHERE id = :i AND project_id = :p AND revoked_at IS NULL'
        );
        $upd->execute([':i' => $inviteId, ':p' => $projectId]);
        if ($upd->rowCount() === 0) {
            fail('invite_not_found_or_revoked', 'No active invite with that id.', 404);
        }
    } elseif ($action === 'remove_coder') {
        $coderUid = isset($payload['coder_user_id']) ? (int)$payload['coder_user_id'] : 0;
        if ($coderUid <= 0) fail('bad_request', 'coder_user_id is required.', 400);
        // Cannot revoke the owner.
        $upd = $pdo->prepare(
            'UPDATE mm_project_coders SET revoked_at = NOW() ' .
            ' WHERE project_id = :p AND user_id = :u AND role = \'coder\' AND revoked_at IS NULL'
        );
        $upd->execute([':p' => $projectId, ':u' => $coderUid]);
        if ($upd->rowCount() === 0) {
            fail('coder_not_found_or_revoked', 'No active coder membership for that user.', 404);
        }
    } else {
        fail('bad_action', "Unknown action; expected 'revoke' or 'remove_coder'.", 400);
    }
    json_out(['ok' => true]);
}

// GET path.
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($projectId <= 0) fail('bad_request', 'project_id is required.', 400);
mm_require_project($pdo, $uid, $projectId);

$invitesStmt = $pdo->prepare(
    'SELECT id, project_id, invited_by, email, token, accepted_at, accepted_user_id, revoked_at, created_at ' .
    ' FROM mm_coder_invites WHERE project_id = :p ORDER BY created_at DESC'
);
$invitesStmt->execute([':p' => $projectId]);
$invitesRows = $invitesStmt->fetchAll(PDO::FETCH_ASSOC);

// Build the same absolute URL shape used at create-time so the owner can
// re-copy the link of any still-pending invite.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'relichecksurvey.com';
$invites = array_map(function ($r) use ($scheme, $host) {
    return [
        'id'                => (int)$r['id'],
        'project_id'        => (int)$r['project_id'],
        'invited_by'        => (int)$r['invited_by'],
        'email'             => $r['email'],
        'token'             => (string)$r['token'],
        'url'               => $scheme . '://' . $host . '/api/mm/coder-invite-accept.php?token=' . urlencode((string)$r['token']),
        'accepted_at'       => $r['accepted_at'],
        'accepted_user_id'  => $r['accepted_user_id'] !== null ? (int)$r['accepted_user_id'] : null,
        'revoked_at'        => $r['revoked_at'],
        'created_at'        => $r['created_at'],
        'status'            => $r['revoked_at'] ? 'revoked' : ($r['accepted_at'] ? 'accepted' : 'pending'),
    ];
}, $invitesRows);

$codersStmt = $pdo->prepare(
    'SELECT c.user_id, c.role, c.added_at, c.revoked_at, c.invited_via_id, ' .
    '       u.email AS coder_email, u.name AS coder_name ' .
    ' FROM mm_project_coders c ' .
    ' LEFT JOIN users u ON u.id = c.user_id ' .
    ' WHERE c.project_id = :p ' .
    ' ORDER BY c.role = \'owner\' DESC, c.added_at ASC'
);
$codersStmt->execute([':p' => $projectId]);
$codersRows = $codersStmt->fetchAll(PDO::FETCH_ASSOC);
$coders = array_map(function ($r) {
    return [
        'user_id'        => (int)$r['user_id'],
        'role'           => (string)$r['role'],
        'name'           => $r['coder_name'],
        'email'          => $r['coder_email'],
        'added_at'       => $r['added_at'],
        'revoked_at'     => $r['revoked_at'],
        'invited_via_id' => $r['invited_via_id'] !== null ? (int)$r['invited_via_id'] : null,
        'active'         => $r['revoked_at'] === null,
    ];
}, $codersRows);

json_out([
    'ok'      => true,
    'invites' => $invites,
    'coders'  => $coders,
]);
