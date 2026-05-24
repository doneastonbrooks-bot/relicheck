<?php
// POST /api/webhooks/email.php
//
// Receives provider callbacks (delivery, open, click, bounce, complaint).
// Designed to accept either:
//   - A direct ReliCheck event format: { event, message_id, email, timestamp, ... }
//   - A "list of events" wrapper: { events: [ {...}, {...} ] }
//
// If you swap providers later, adapt the parser at the top of the file; the
// rest of the body relies on the canonical fields below.
//
// Authenticated by a shared HMAC header (X-Relicheck-Signature) configured
// in _config.php as email_webhook_secret. If unset, the endpoint refuses.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_email_dispatcher.php';

require_method('POST');

$cfg = relicheck_config();
$secret = (string)($cfg['email_webhook_secret'] ?? '');
if ($secret === '') {
    fail('not_configured', 'email_webhook_secret is not set.', 500);
}

$raw = file_get_contents('php://input') ?: '';
$sig = (string)($_SERVER['HTTP_X_RELICHECK_SIGNATURE'] ?? '');
$expected = hash_hmac('sha256', $raw, $secret);
if (!hash_equals($expected, $sig)) {
    fail('bad_signature', 'Signature did not match.', 401);
}

try {
    $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fail('bad_json', 'Invalid JSON.', 400);
}

$events = isset($body['events']) && is_array($body['events']) ? $body['events'] : [$body];

$pdo = db();
$processed = 0; $unknown = 0;

foreach ($events as $ev) {
    if (!is_array($ev)) { $unknown++; continue; }
    $type    = strtolower((string)($ev['event']      ?? ''));
    $msg_id  = (string)($ev['message_id'] ?? '');
    $email   = strtolower((string)($ev['email']      ?? ''));
    $url     = (string)($ev['url']                   ?? '');
    $ua      = (string)($ev['user_agent']            ?? '');
    $reason  = (string)($ev['reason']                ?? '');

    // Locate the email_log either by provider message id or by recipient.
    $log = null;
    if ($msg_id !== '') {
        $st = $pdo->prepare('SELECT id, recipient_email FROM email_logs WHERE provider_message_id = :m LIMIT 1');
        $st->execute([':m' => $msg_id]);
        $log = $st->fetch();
    }
    if (!$log && $email !== '') {
        $st = $pdo->prepare(
            'SELECT id, recipient_email FROM email_logs
             WHERE recipient_email = :e
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute([':e' => $email]);
        $log = $st->fetch();
    }
    if (!$log) { $unknown++; continue; }

    $log_id = (int)$log['id'];
    $ip_h = function_exists('ip_hash') ? ip_hash() : null;

    switch ($type) {
        case 'delivered':
            $pdo->prepare("UPDATE email_logs SET status='delivered', delivered_at=NOW() WHERE id=:id")
                ->execute([':id' => $log_id]);
            break;
        case 'opened':
        case 'open':
            $pdo->prepare(
                'INSERT INTO email_open_events (email_log_id, user_agent, ip_hash) VALUES (:id, :ua, :ip)'
            )->execute([':id' => $log_id, ':ua' => $ua, ':ip' => $ip_h]);
            $pdo->prepare("UPDATE email_logs SET status='opened' WHERE id=:id AND status IN ('sent','delivered')")
                ->execute([':id' => $log_id]);
            break;
        case 'clicked':
        case 'click':
            $pdo->prepare(
                'INSERT INTO email_click_events (email_log_id, url, user_agent, ip_hash)
                 VALUES (:id, :u, :ua, :ip)'
            )->execute([':id' => $log_id, ':u' => $url, ':ua' => $ua, ':ip' => $ip_h]);
            $pdo->prepare("UPDATE email_logs SET status='clicked' WHERE id=:id AND status IN ('sent','delivered','opened')")
                ->execute([':id' => $log_id]);
            break;
        case 'bounced':
        case 'bounce':
            $pdo->prepare("UPDATE email_logs SET status='bounced', last_error=:e WHERE id=:id")
                ->execute([':e' => $reason, ':id' => $log_id]);
            // Hard bounces hit the suppression list immediately.
            $pdo->prepare(
                'INSERT IGNORE INTO email_suppression_list (email, reason, notes)
                 VALUES (:e, "hard_bounce", :n)'
            )->execute([':e' => (string)$log['recipient_email'], ':n' => $reason]);
            break;
        case 'complained':
        case 'complaint':
        case 'spam':
            $pdo->prepare("UPDATE email_logs SET status='complained' WHERE id=:id")
                ->execute([':id' => $log_id]);
            $pdo->prepare(
                'INSERT IGNORE INTO email_suppression_list (email, reason, notes)
                 VALUES (:e, "complaint", :n)'
            )->execute([':e' => (string)$log['recipient_email'], ':n' => 'Auto: spam complaint']);
            break;
        default:
            $unknown++;
            continue 2;
    }
    $processed++;
}

json_out(['ok' => true, 'processed' => $processed, 'unknown' => $unknown]);
