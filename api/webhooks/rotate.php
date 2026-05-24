<?php
// POST /api/webhooks/rotate.php
// Body: { id }
// Generates a new HMAC signing secret for the webhook and returns it once.
// The old secret is immediately invalidated; receivers should be ready to
// accept the new one before the next event fires.

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

$pdo = db();
$check = $pdo->prepare('SELECT id FROM webhooks WHERE id = :id AND owner_id = :o LIMIT 1');
$check->execute([':id' => $id, ':o' => (int)$user['id']]);
if (!$check->fetch()) fail('not_found', 'Webhook not found.', 404);

$newSecret = webhooks_generate_secret();
$pdo->prepare('UPDATE webhooks SET secret = :s WHERE id = :id')
    ->execute([':s' => $newSecret, ':id' => $id]);

json_out([
    'ok'     => true,
    'id'     => $id,
    'secret' => $newSecret,
    'note'   => 'Save this secret now. The old secret is no longer valid; update your receiver before the next event fires.',
]);
