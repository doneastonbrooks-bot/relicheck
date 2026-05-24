<?php
// GET /api/email/unsubscribe.php?t=<token>
//
// Honors a one-click unsubscribe link from a marketing-class email. The
// token resolves to a (user_id, preference_group) pair via unsubscribe_tokens.
// Refuses to operate on a required group.
//
// Returns a tiny HTML confirmation page (not JSON), since recipients hit this
// from inside a mail client.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_email_dispatcher.php';

require_method('GET');

$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo unsubscribe_html_page('Invalid link', 'This unsubscribe link is no longer valid.');
    exit;
}

$pdo = db();
$st = $pdo->prepare(
    'SELECT id, user_id, preference_group, used_at, expires_at
     FROM unsubscribe_tokens WHERE token = :t LIMIT 1'
);
$st->execute([':t' => $token]);
$row = $st->fetch();

if (!$row) {
    http_response_code(404);
    echo unsubscribe_html_page('Link not found', 'This unsubscribe link could not be located.');
    exit;
}

if ($row['used_at']) {
    echo unsubscribe_html_page('Already unsubscribed', 'You have already unsubscribed using this link.');
    exit;
}

// Hard guard: required groups can never be unsubscribed via token.
$required_groups = [
    'account_security','billing','membership','privacy_security',
    'terms_legal','support_ticket','required_service'
];
$group = (string)$row['preference_group'];
if (in_array($group, $required_groups, true)) {
    http_response_code(400);
    echo unsubscribe_html_page('Cannot unsubscribe', 'This category of email is required for your account and cannot be turned off.');
    exit;
}

$pdo->prepare(
    'INSERT INTO email_preferences (user_id, preference_group, is_enabled, updated_by)
     VALUES (:u, :g, 0, "system")
     ON DUPLICATE KEY UPDATE is_enabled = 0, updated_by = "system"'
)->execute([':u' => (int)$row['user_id'], ':g' => $group]);

$pdo->prepare('UPDATE unsubscribe_tokens SET used_at = NOW() WHERE id = :id')
    ->execute([':id' => (int)$row['id']]);

relicheck_email_audit((int)$row['user_id'], 'preference.unsubscribe', 'email_preferences', null,
    null, ['group' => $group, 'via' => 'token']);

echo unsubscribe_html_page(
    'Unsubscribed',
    'You will no longer receive emails in the "' . htmlspecialchars($group, ENT_QUOTES) . '" category. Required emails (account, billing, privacy, legal, support) will still be delivered.'
);

function unsubscribe_html_page(string $title, string $body): string
{
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">' .
           '<title>' . htmlspecialchars($title) . ' &middot; ReliCheck</title>' .
           '<meta name="viewport" content="width=device-width,initial-scale=1">' .
           '<style>body{font-family:-apple-system,Inter,Arial,sans-serif;background:#f4f5fb;margin:0;padding:48px 16px;color:#1a1d2e;}' .
           '.card{max-width:520px;margin:0 auto;background:#fff;border-radius:10px;padding:32px;}' .
           'h1{margin-top:0;color:#3d57f5;font-size:22px;}p{line-height:1.55;}</style></head>' .
           '<body><div class="card"><h1>' . htmlspecialchars($title) . '</h1>' .
           '<p>' . $body . '</p>' .
           '<p style="font-size:12px;color:#5a607a;margin-top:24px;">ReliCheck</p>' .
           '</div></body></html>';
}
