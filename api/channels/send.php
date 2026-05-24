<?php
// POST /api/channels/send.php
// Body: { id, note? }
//
// Fires the survey-take card to the given channel right now. Used by the
// "Send now" button in the Distribute UI and by the "Test" button for new
// channels.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_channels.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing channel id.', 400);
$note = trim((string)($body['note'] ?? ''));
if (strlen($note) > 280) $note = substr($note, 0, 280);

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT c.*, sv.title AS survey_title, sv.description AS survey_description,
            sv.slug AS survey_slug, sv.is_published AS survey_is_published,
            sv.owner_id
       FROM survey_channels c
       JOIN surveys sv ON sv.id = c.survey_id
      WHERE c.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row || (int)$row['owner_id'] !== (int)$user['id']) {
    fail('not_found', 'Channel not found.', 404);
}
if ((string)$row['status'] !== 'active') {
    fail('paused', 'This channel is paused.', 400);
}
if (!(int)$row['survey_is_published']) {
    fail('not_published', 'Publish the survey before sending it to a channel.', 400);
}

$cfg = relicheck_config();
$base = rtrim((string)($cfg['site_url'] ?? 'https://relichecksurvey.com'), '/');
$shareLink = $base . '/s/' . rawurlencode((string)$row['survey_slug']);

$survey = [
    'title'       => (string)$row['survey_title'],
    'description' => (string)$row['survey_description'],
];

$channel = [
    'id'          => (int)$row['id'],
    'kind'        => (string)$row['kind'],
    'webhook_url' => (string)$row['webhook_url'],
];

$result = channels_dispatch_to_channel($pdo, $channel, $survey, $shareLink, $note);
json_out([
    'ok'      => !!$result['ok'],
    'http'    => $result['http'],
    'status'  => $result['status'],
    'body'    => $result['body'],
]);
