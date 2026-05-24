<?php
// GET /api/email/suppression.php           -> list (admin)
// POST /api/email/suppression.php          -> add: { email, reason, notes }
// DELETE /api/email/suppression.php?email= -> remove
//
// Admin-only. Lets staff manually add/remove addresses on the suppression
// list. Hard bounces and complaints land here automatically via the webhook
// receiver, so this endpoint is mostly used to clean up after a typo or to
// preempt an address that should never be mailed.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_admin.php';
require_once __DIR__ . '/../_email_dispatcher.php';

require_method('GET', 'POST', 'DELETE');
check_origin();

$user = current_user();
if (!$user) fail('not_signed_in', 'Sign in required.', 401);
if (!is_admin_user($user)) fail('forbidden', 'Admins only.', 403);

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query(
        'SELECT id, email, reason, added_at, added_by_user_id, notes
         FROM email_suppression_list ORDER BY added_at DESC LIMIT 500'
    )->fetchAll();
    json_out(['ok' => true, 'rows' => $rows, 'count' => count($rows)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = read_json_body();
    $email  = strtolower(clean_string((string)($body['email']  ?? ''), 255));
    $reason = (string)($body['reason'] ?? 'manual');
    $notes  = clean_string((string)($body['notes'] ?? ''), 512);
    if (!valid_email($email)) fail('bad_email', 'Invalid email address.');
    if (!in_array($reason, ['hard_bounce','complaint','manual','invalid'], true)) {
        $reason = 'manual';
    }
    $pdo->prepare(
        'INSERT INTO email_suppression_list (email, reason, added_by_user_id, notes)
         VALUES (:e, :r, :u, :n)
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), notes = VALUES(notes)'
    )->execute([':e' => $email, ':r' => $reason, ':u' => (int)$user['id'], ':n' => $notes]);
    relicheck_email_audit((int)$user['id'], 'suppression.add', 'email_suppression_list', null,
        null, ['email' => $email, 'reason' => $reason, 'notes' => $notes]);
    json_out(['ok' => true, 'email' => $email, 'reason' => $reason]);
}

// DELETE
$email = strtolower(clean_string((string)($_GET['email'] ?? ''), 255));
if (!valid_email($email)) fail('bad_email', 'Invalid email address.');
$st = $pdo->prepare('DELETE FROM email_suppression_list WHERE email = :e');
$st->execute([':e' => $email]);
relicheck_email_audit((int)$user['id'], 'suppression.remove', 'email_suppression_list', null,
    ['email' => $email], null);
json_out(['ok' => true, 'removed' => $st->rowCount()]);
