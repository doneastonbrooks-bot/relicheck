<?php
// POST /api/channels/create.php
// Body: { survey_id, label, webhook_url }
//
// Adds a Slack or Teams distribution channel to the survey. The destination
// kind is auto-detected from the webhook URL host.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_channels.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$sid  = (int)($body['survey_id'] ?? 0);
$label = trim((string)($body['label'] ?? ''));
$url   = trim((string)($body['webhook_url'] ?? ''));

if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);
if ($label === '') fail('bad_label', 'Pick a label for this channel.', 400);
if (strlen($label) > 120) $label = substr($label, 0, 120);
if ($url === '') fail('bad_url', 'Paste a Slack or Teams webhook URL.', 400);
if (strlen($url) > 500) fail('bad_url', 'Webhook URL is too long.', 400);

$kind = channels_detect_kind($url);
if ($kind === null) {
    fail('bad_url', 'That does not look like a Slack incoming webhook (hooks.slack.com) or a Teams webhook (*.webhook.office.com).', 400);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, owner_id FROM surveys WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $sid]);
$survey = $stmt->fetch();
if (!$survey || (int)$survey['owner_id'] !== (int)$user['id']) {
    fail('not_found', 'Survey not found.', 404);
}

$ins = $pdo->prepare(
    'INSERT INTO survey_channels (survey_id, user_id, label, kind, webhook_url, status)
     VALUES (:sid, :uid, :lbl, :kind, :url, "active")'
);
$ins->execute([
    ':sid'  => $sid,
    ':uid'  => (int)$user['id'],
    ':lbl'  => $label,
    ':kind' => $kind,
    ':url'  => $url,
]);
$id = (int)$pdo->lastInsertId();

$out = $pdo->prepare('SELECT id, survey_id, label, kind, webhook_url, status, last_fired_at, last_status, fired_count, created_at FROM survey_channels WHERE id = :id');
$out->execute([':id' => $id]);
json_out(['ok' => true, 'channel' => $out->fetch()]);
