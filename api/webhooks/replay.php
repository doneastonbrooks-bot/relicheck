<?php
// POST /api/webhooks/replay.php
// Body: { delivery_id }
// Re-fires the original payload from a stored delivery row through the
// dispatcher (which writes a fresh delivery row, tagged with is_test=1).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_webhooks.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['delivery_id'] ?? 0);
if ($id <= 0) fail('bad_id', 'delivery_id is required.', 400);

$pdo = db();
try {
    $stmt = $pdo->prepare(
        'SELECT d.event, d.payload_json,
                w.id AS webhook_id, w.owner_id, w.url, w.secret
           FROM webhook_deliveries d
           JOIN webhooks w ON w.id = d.webhook_id
          WHERE d.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    fail('migration_pending', 'Phase 152 migration has not been applied yet.', 503);
}
if (!$row) fail('not_found', 'Delivery not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) {
    fail('forbidden', 'Not your webhook.', 403);
}

// The stored payload_json is the exact body we sent (already formatted for
// Slack vs generic). To re-fire, decode and pass back through dispatch_one
// which will re-sign + re-format. We try to extract envelope.data; if the
// stored payload doesn't match that shape (e.g. Slack Block Kit), we fall
// back to a minimal test data block.
$decoded = json_decode((string)$row['payload_json'], true);
$replayData = (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']))
    ? $decoded['data']
    : ['replayed_from' => $id, 'note' => 'Original payload could not be decoded; re-fired with placeholder data.'];

$result = webhooks_dispatch_one(
    (int)$row['webhook_id'],
    (string)$row['url'],
    (string)$row['secret'],
    (string)$row['event'],
    $replayData,
    true
);

json_out([
    'ok'           => $result['ok'],
    'http_status'  => $result['status'],
    'error'        => $result['error'],
    'duration_ms'  => $result['duration_ms'],
]);
