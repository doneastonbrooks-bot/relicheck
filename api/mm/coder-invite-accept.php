<?php
// Phase 181 — Accept a shareable-link invitation for second-coder access.
// GET /api/mm/coder-invite-accept.php?token=...
//
// Behavior:
//   - If invite is invalid / revoked / already accepted by someone else → fail.
//   - If user is NOT logged in → bounce to /login with ?next=<this URL>
//     so they land back here after authenticating.
//   - If user IS logged in:
//       * They become an accepted coder on the project (mm_project_coders).
//       * The invite row is marked accepted_at + accepted_user_id.
//       * Browser is redirected to /app-2026.html#/mm/<project_id> so they
//         land in the project's MM Studio.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET');

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
if ($token === '' || strlen($token) > 128) {
    fail('bad_token', 'Invitation link is missing or malformed.', 400);
}

$pdo = db();

// Look up the invite without authenticating yet so we can give an honest
// error before bouncing to login.
$stmt = $pdo->prepare(
    'SELECT id, project_id, invited_by, email, token, accepted_at, accepted_user_id, revoked_at ' .
    ' FROM mm_coder_invites WHERE token = :t LIMIT 1'
);
$stmt->execute([':t' => $token]);
$invite = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invite) {
    fail('invite_not_found', 'This invitation link is not valid.', 404);
}
if ($invite['revoked_at'] !== null) {
    fail('invite_revoked', 'This invitation has been revoked by the project owner.', 410);
}

// Auth check. If not logged in, redirect to login with a return URL so the
// user lands back here.
$uid = current_user_id();
if ($uid === null) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'relichecksurvey.com';
    $next   = $scheme . '://' . $host . '/api/mm/coder-invite-accept.php?token=' . urlencode($token);
    header('Location: /app-2026.html#/login?next=' . urlencode($next), true, 302);
    exit;
}

$projectId = (int)$invite['project_id'];

// If the invite was already accepted by a different user, refuse.
if ($invite['accepted_at'] !== null && (int)($invite['accepted_user_id'] ?? 0) !== $uid) {
    fail('invite_already_accepted', 'This invitation has already been claimed by another account.', 409);
}

// Owner cannot accept their own invite — they already have access.
if ((int)$invite['invited_by'] === $uid) {
    // Bounce them to the project; no row insertion needed.
    header('Location: /app-2026.html#/mm/' . $projectId, true, 302);
    exit;
}

// Mark the invite accepted (idempotent if same user re-clicks the link).
$pdo->prepare(
    'UPDATE mm_coder_invites SET accepted_at = COALESCE(accepted_at, NOW()), accepted_user_id = :u ' .
    ' WHERE id = :i'
)->execute([':u' => $uid, ':i' => (int)$invite['id']]);

// Add membership row. UNIQUE KEY uq_mm_project_coder (project_id, user_id)
// makes this idempotent — a re-click does not duplicate.
$pdo->prepare(
    'INSERT INTO mm_project_coders (project_id, user_id, role, invited_via_id, added_at) ' .
    ' VALUES (:p, :u, \'coder\', :i, NOW()) ' .
    ' ON DUPLICATE KEY UPDATE revoked_at = NULL'
)->execute([
    ':p' => $projectId,
    ':u' => $uid,
    ':i' => (int)$invite['id'],
]);

// Land the user in the project. Shell-V3 routes them to the Codebook surface
// where their non-owner role flips on the blind-coding UI.
header('Location: /app-2026.html#/mm/' . $projectId, true, 302);
exit;
