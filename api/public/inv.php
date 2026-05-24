<?php
// GET /api/public/inv.php?t=<token>
//
// Public-facing entry point for an invitation link. Records the open event,
// then 302-redirects to the survey share URL with the token attached so
// that submit.php can mark the invitation completed when the response is
// posted.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_invitations.php';

require_method('GET');

$token = (string)($_GET['t'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(400);
    echo "Invalid invitation link.";
    exit;
}

$pdo = db();

// Look up the invitation and the survey slug.
$stmt = $pdo->prepare(
    'SELECT inv.id, inv.invitation_token, inv.status, inv.opened_at,
            s.slug
       FROM survey_invitations inv
       JOIN surveys s ON s.id = inv.survey_id
      WHERE inv.invitation_token = :t LIMIT 1'
);
$stmt->execute([':t' => $token]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo "This invitation link is no longer valid.";
    exit;
}

// Record the open (idempotent).
try {
    invitations_mark_opened($token);
} catch (Throwable $_) { /* best-effort */ }

$cfg     = relicheck_config();
$siteUrl = rtrim((string)($cfg['site_url'] ?? 'https://relichecksurvey.com'), '/');
// Phase 41: tag email-channel arrivals so the response carries channel=email.
$dest    = $siteUrl . '/take.html?slug=' . urlencode((string)$row['slug']) . '&inv=' . urlencode($token) . '&ch=email';

header('Location: ' . $dest, true, 302);
exit;
