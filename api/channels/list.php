<?php
// GET /api/channels/list.php?survey_id=<id>
//
// Returns every Slack/Teams channel attached to the survey. The webhook URL
// is masked for display (Slack-style "***/last8" suffix) so a screenshot
// does not leak the full secret.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
check_origin();
$user = require_auth();

$sid = (int)($_GET['survey_id'] ?? 0);
if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, owner_id FROM surveys WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $sid]);
$survey = $stmt->fetch();
if (!$survey || (int)$survey['owner_id'] !== (int)$user['id']) {
    fail('not_found', 'Survey not found.', 404);
}

$rows = $pdo->prepare(
    'SELECT id, survey_id, label, kind, webhook_url, status,
            last_fired_at, last_status, fired_count, created_at
       FROM survey_channels
      WHERE survey_id = :sid
      ORDER BY id DESC'
);
$rows->execute([':sid' => $sid]);
$channels = $rows->fetchAll();

foreach ($channels as &$c) {
    $u = (string)$c['webhook_url'];
    $tail = substr($u, -8);
    $c['webhook_url_masked'] = '...' . $tail;
    unset($c['webhook_url']);
}
unset($c);

json_out(['ok' => true, 'channels' => $channels]);
