<?php
// POST /api/webhooks/test.php
// Body: { id: number }
// Fires a synthetic test event to the webhook so the user can verify the
// destination is reachable and is parsing the payload as expected.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_webhooks.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'id is required.', 400);

$stmt = db()->prepare(
    'SELECT id, url, secret, events FROM webhooks
      WHERE id = :id AND owner_id = :o LIMIT 1'
);
$stmt->execute([':id' => $id, ':o' => (int)$user['id']]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Webhook not found.', 404);

// Fire a synthetic response.received with placeholder data. The destination
// will see a normal-looking payload, which is what most test workflows need.
// Phase 152: pass isTest=true so the delivery log can flag synthetic events.
webhooks_dispatch_one(
    (int)$row['id'],
    (string)$row['url'],
    (string)$row['secret'],
    'response.received',
    [
        'survey_id'      => 0,
        'survey_title'   => 'Test event from ReliCheck',
        'survey_url'     => null,
        'response_count' => 1,
        'test'           => true,
    ],
    true
);

// Re-read the row to return the updated last_status / last_error.
$stmt = db()->prepare('SELECT last_status, last_error, last_fired_at FROM webhooks WHERE id = :id');
$stmt->execute([':id' => $id]);
$after = $stmt->fetch() ?: [];

json_out([
    'ok'             => true,
    'id'             => $id,
    'last_status'    => isset($after['last_status']) ? (int)$after['last_status'] : null,
    'last_error'     => $after['last_error'] ?? null,
    'last_fired_at'  => $after['last_fired_at'] ?? null,
]);
