<?php
// GET /api/public/contact_unsubscribe.php?t=<invitation_token>
//
// Per-contact unsubscribe handler for survey invitations and reminders.
// Distinct from Phase 31's api/email/unsubscribe.php (which works on
// unsubscribe_tokens keyed to user_id). Survey contacts are emails, not
// users, so we key the unsubscribe on the invitation_token instead.
//
// When called with a valid token:
//   1. Sets the contact's status to 'unsubscribed' (no future invitations
//      or reminders will be queued for this survey).
//   2. Adds the contact's email to the email_suppression_list under
//      reason='survey_distribution_unsubscribe' so future Phase 31 sends
//      with the same group respect the choice.
//
// Renders a tiny HTML page since this is hit from a mail client.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';

require_method('GET');

$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(400);
    echo contact_unsub_page('Invalid link', 'This unsubscribe link is no longer valid.');
    exit;
}

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT inv.id AS inv_id, inv.survey_id, c.id AS contact_id, c.email, c.status
       FROM survey_invitations inv
       JOIN survey_contacts c ON c.id = inv.contact_id
      WHERE inv.invitation_token = :t LIMIT 1'
);
$stmt->execute([':t' => $token]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo contact_unsub_page('Link not found', 'This unsubscribe link could not be located.');
    exit;
}

if ($row['status'] === 'unsubscribed') {
    echo contact_unsub_page('Already unsubscribed', 'This email has already been unsubscribed.');
    exit;
}

try {
    $pdo->prepare(
        'UPDATE survey_contacts SET status = "unsubscribed" WHERE id = :id'
    )->execute([':id' => (int)$row['contact_id']]);

    // Best-effort suppression-list add. email_suppression_list exists from
    // Phase 31; the schema lives there. If it is missing, the contact
    // unsubscribe still landed via the survey_contacts.status update.
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO email_suppression_list (email, reason, source)
             VALUES (:em, "survey_distribution_unsubscribe", "contact")'
        )->execute([':em' => (string)$row['email']]);
    } catch (Throwable $_) { /* best-effort */ }
} catch (Throwable $e) {
    http_response_code(500);
    echo contact_unsub_page('Something went wrong', 'We could not complete the unsubscribe just now. Please try again in a minute.');
    exit;
}

echo contact_unsub_page(
    'Unsubscribed',
    'You have been unsubscribed from this survey. You will not receive any more invitations or reminders for it.'
);
exit;

function contact_unsub_page(string $title, string $body): string
{
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $b = htmlspecialchars($body,  ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">' .
           '<meta name="viewport" content="width=device-width,initial-scale=1">' .
           '<title>' . $t . ' - ReliCheck</title>' .
           '<style>body{font-family:-apple-system,Segoe UI,Roboto,Inter,sans-serif;background:#f5f7fb;color:#1a2238;margin:0;padding:48px 20px;line-height:1.55;}' .
           '.card{max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2e4ea;border-radius:12px;padding:28px 30px;box-shadow:0 1px 2px rgba(20,28,60,.04);}' .
           'h1{margin:0 0 10px;font-size:22px;letter-spacing:-0.015em;}' .
           'p{margin:0 0 14px;font-size:15px;color:#4a5168;}' .
           'a{color:#e85d3a;text-decoration:none;font-weight:600;}' .
           'a:hover{text-decoration:underline;}</style></head><body>' .
           '<div class="card"><h1>' . $t . '</h1><p>' . $b . '</p>' .
           '<p><a href="https://relichecksurvey.com/">Back to ReliCheck</a></p>' .
           '</div></body></html>';
}
