<?php
// Phase 181 — Create a shareable-link invitation for a second coder.
// POST { project_id: int, email?: string } → { ok, invite_id, token, url }
// Only the project OWNER can create invites.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');

$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$payload = read_json_body();
$projectId = isset($payload['project_id']) ? (int)$payload['project_id'] : 0;
$email     = isset($payload['email']) && is_string($payload['email']) ? trim($payload['email']) : null;
if ($projectId <= 0) {
    fail('bad_request', 'project_id is required.', 400);
}
if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('bad_email', 'email is not a valid address.', 400);
}
if ($email === '') { $email = null; }

// Owner-only: invitations are not delegated to coders.
$project = mm_require_project($pdo, $uid, $projectId);

// Generate a unique token. Practically never collides at 256 bits, but loop
// defensively.
$token = null;
for ($i = 0; $i < 5; $i++) {
    $candidate = mm_generate_invite_token();
    $check = $pdo->prepare('SELECT id FROM mm_coder_invites WHERE token = :t LIMIT 1');
    $check->execute([':t' => $candidate]);
    if (!$check->fetch()) { $token = $candidate; break; }
}
if ($token === null) {
    fail('token_collision', 'Could not generate a unique invite token. Try again.', 500);
}

$ins = $pdo->prepare(
    'INSERT INTO mm_coder_invites (project_id, invited_by, email, token, created_at) ' .
    'VALUES (:p, :u, :e, :t, NOW())'
);
$ins->execute([
    ':p' => $projectId,
    ':u' => $uid,
    ':e' => $email,
    ':t' => $token,
]);
$inviteId = (int)$pdo->lastInsertId();

// Build the absolute accept URL honoring current scheme + host so the invite
// works whether the user is on relichecksurvey.com or a staging host.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'relichecksurvey.com';
$url    = $scheme . '://' . $host . '/api/mm/coder-invite-accept.php?token=' . urlencode($token);

json_out([
    'ok'         => true,
    'invite_id'  => $inviteId,
    'project_id' => $projectId,
    'token'      => $token,
    'url'        => $url,
    'email'      => $email,
    'created_at' => date('c'),
]);
