<?php
// POST /api/webhooks/update.php
// Body: { id: number, name?: string, url?: string, events?: string[], active?: bool }
// Updates editable fields on a webhook. Only owner can update.

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

// Confirm the webhook exists and belongs to the current user.
$check = db()->prepare('SELECT id FROM webhooks WHERE id = :id AND owner_id = :o LIMIT 1');
$check->execute([':id' => $id, ':o' => (int)$user['id']]);
if (!$check->fetch()) fail('not_found', 'Webhook not found.', 404);

$updates = [];
$params  = [':id' => $id];

if (array_key_exists('name', $body)) {
    $name = trim((string)$body['name']);
    if ($name === '' || mb_strlen($name) > 120) fail('bad_name', 'Name is required (max 120 characters).', 400);
    $updates[] = 'name = :n';
    $params[':n'] = $name;
}
if (array_key_exists('url', $body)) {
    $url = trim((string)$body['url']);
    if ($url === '' || mb_strlen($url) > 2048) fail('bad_url', 'URL is required (max 2048 characters).', 400);
    if (!filter_var($url, FILTER_VALIDATE_URL)) fail('bad_url', 'URL must be a valid URL.', 400);
    if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') fail('bad_url', 'Webhook URLs must use HTTPS.', 400);
    $updates[] = 'url = :u';
    $params[':u'] = $url;
}
if (array_key_exists('events', $body) && is_array($body['events'])) {
    $cleanEvents = webhooks_validate_events($body['events']);
    if ($cleanEvents === null) fail('bad_events', 'Pick at least one supported event.', 400, ['known_events' => WEBHOOK_KNOWN_EVENTS]);
    $updates[] = 'events = :e';
    $params[':e'] = json_encode($cleanEvents, JSON_UNESCAPED_SLASHES);
}
if (array_key_exists('active', $body)) {
    $updates[] = 'active = :a';
    $params[':a'] = $body['active'] ? 1 : 0;
}

if (!$updates) fail('nothing_to_update', 'No editable fields were provided.', 400);

$sql = 'UPDATE webhooks SET ' . implode(', ', $updates) . ' WHERE id = :id';
$stmt = db()->prepare($sql);
$stmt->execute($params);

json_out(['ok' => true, 'id' => $id]);
