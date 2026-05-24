<?php
// POST /api/webhooks/delete.php
// Body: { id: number }
// Removes the webhook. Only owner can delete.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'id is required.', 400);

$stmt = db()->prepare('DELETE FROM webhooks WHERE id = :id AND owner_id = :o');
$stmt->execute([':id' => $id, ':o' => (int)$user['id']]);

if ($stmt->rowCount() === 0) fail('not_found', 'Webhook not found.', 404);

json_out(['ok' => true, 'id' => $id]);
