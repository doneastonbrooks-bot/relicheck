<?php
// GET /api/webhooks/list.php
// Returns the current user's registered webhooks.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_webhooks.php';

require_method('GET');
$user = require_auth();

$stmt = db()->prepare(
    'SELECT id, name, url, events, active, created_at, updated_at,
            last_fired_at, last_status, last_error, total_fires, failed_fires
       FROM webhooks
      WHERE owner_id = :o
      ORDER BY created_at DESC'
);
$stmt->execute([':o' => (int)$user['id']]);

$rows = [];
foreach ($stmt->fetchAll() as $r) {
    $rows[] = [
        'id'             => (int)$r['id'],
        'name'           => (string)$r['name'],
        'url'            => (string)$r['url'],
        'events'         => json_decode((string)$r['events'], true) ?: [],
        'active'         => (bool)(int)$r['active'],
        'created_at'     => $r['created_at'],
        'updated_at'     => $r['updated_at'],
        'last_fired_at'  => $r['last_fired_at'],
        'last_status'    => $r['last_status'] !== null ? (int)$r['last_status'] : null,
        'last_error'     => $r['last_error'],
        'total_fires'    => (int)$r['total_fires'],
        'failed_fires'   => (int)$r['failed_fires'],
        'is_slack'       => strtolower((string)parse_url((string)$r['url'], PHP_URL_HOST)) === 'hooks.slack.com',
    ];
}

json_out([
    'webhooks'      => $rows,
    'known_events'  => WEBHOOK_KNOWN_EVENTS,
]);
