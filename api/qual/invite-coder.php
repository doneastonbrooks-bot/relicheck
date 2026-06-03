<?php
// POST /api/qual/invite-coder.php
// Creates a dual-coder invite for a project. Lead researcher only.
// Body: { project_id, email }
// Returns: { ok, invite_id, token, invite_url }
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId = (int)($body['project_id'] ?? 0);
$email     = trim(strtolower((string)($body['email'] ?? '')));
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('bad_input', 'Invalid email address.');

// Only the project owner can invite
qual_require_project($pdo, $uid, $projectId);

// Limit: 1 active invite per project (prevent invite spam)
$existing = $pdo->prepare(
    "SELECT id FROM qual_coder_invites
     WHERE project_id=:p AND status IN ('pending','accepted') LIMIT 1"
);
$existing->execute([':p' => $projectId]);
if ($existing->fetch()) {
    // Revoke the old one and replace — or just return the existing one
    // For simplicity: allow only one active invite; fail if one exists for a different email
    $existRow = $pdo->prepare(
        "SELECT id,email,token,status FROM qual_coder_invites
         WHERE project_id=:p AND status IN ('pending','accepted') LIMIT 1"
    );
    $existRow->execute([':p' => $projectId]);
    $inv = $existRow->fetch(PDO::FETCH_ASSOC);
    if ($inv && $inv['email'] === $email) {
        // Return the existing invite
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'relichecksurvey.com');
        json_out([
            'ok'         => true,
            'invite_id'  => (int)$inv['id'],
            'token'      => $inv['token'],
            'invite_url' => $baseUrl . '/qual-coder.php?token=' . urlencode($inv['token']),
            'reused'     => true,
        ]);
        return;
    }
    fail('conflict', 'An active invite already exists for this project. Revoke it before creating a new one.');
}

// Generate a cryptographically secure token
$token = bin2hex(random_bytes(24)); // 48 hex chars

$ins = $pdo->prepare(
    "INSERT INTO qual_coder_invites (project_id,invited_by,email,token,status)
     VALUES (:p,:u,:e,:t,'pending')"
);
$ins->execute([':p' => $projectId, ':u' => $uid, ':e' => $email, ':t' => $token]);
$inviteId = (int)$pdo->lastInsertId();

qual_audit($pdo, $projectId, $uid, 'coder_invited', 'invite', $inviteId, $email);

$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'relichecksurvey.com');
json_out([
    'ok'         => true,
    'invite_id'  => $inviteId,
    'token'      => $token,
    'invite_url' => $baseUrl . '/qual-coder.php?token=' . urlencode($token),
]);
