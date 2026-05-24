<?php
// POST /api/webhooks/create.php
// Body: { name: string, url: string (https://...), events: string[] }
// Returns the created webhook including the freshly-generated secret.
// The secret is only returned in full on create; subsequent reads return
// a redacted preview ("****" + last 4 chars).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_webhooks.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$name   = trim((string)($body['name']   ?? ''));
$url    = trim((string)($body['url']    ?? ''));
$events = is_array($body['events'] ?? null) ? $body['events'] : [];

if ($name === '' || mb_strlen($name) > 120) {
    fail('bad_name', 'Name is required (max 120 characters).', 400);
}
if ($url === '' || mb_strlen($url) > 2048) {
    fail('bad_url', 'URL is required (max 2048 characters).', 400);
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    fail('bad_url', 'URL must be a valid URL.', 400);
}
$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
if ($scheme !== 'https') {
    fail('bad_url', 'Webhook URLs must use HTTPS.', 400);
}

// SSRF guard. Block hostnames that resolve to private, loopback, or
// link-local addresses so a webhook URL can't be used to probe IONOS
// internals or cloud-metadata endpoints.
$host = strtolower((string)parse_url($url, PHP_URL_HOST));
if ($host === '') {
    fail('bad_url', 'URL must include a host.', 400);
}
$blockedHosts = ['localhost', 'localhost.localdomain', '0.0.0.0', '169.254.169.254'];
if (in_array($host, $blockedHosts, true)) {
    fail('bad_url', 'That URL is not allowed as a webhook target.', 400);
}
// If host is already an IP, validate it directly; otherwise resolve and
// validate every returned address (one bad answer blocks the URL).
$ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
foreach ($ips as $ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        fail('bad_url', 'That host resolves to a private or reserved address and can\'t be used as a webhook target.', 400);
    }
}

$cleanEvents = webhooks_validate_events($events);
if ($cleanEvents === null) {
    fail('bad_events', 'Pick at least one supported event.', 400, ['known_events' => WEBHOOK_KNOWN_EVENTS]);
}

$secret = webhooks_generate_secret();

$stmt = db()->prepare(
    'INSERT INTO webhooks (owner_id, name, url, secret, events, active)
     VALUES (:o, :n, :u, :s, :e, 1)'
);
$stmt->execute([
    ':o' => (int)$user['id'],
    ':n' => $name,
    ':u' => $url,
    ':s' => $secret,
    ':e' => json_encode($cleanEvents, JSON_UNESCAPED_SLASHES),
]);
$id = (int)db()->lastInsertId();

json_out([
    'id'      => $id,
    'name'    => $name,
    'url'     => $url,
    'events'  => $cleanEvents,
    'active'  => true,
    'secret'  => $secret,
    'is_slack'=> strtolower((string)parse_url($url, PHP_URL_HOST)) === 'hooks.slack.com',
    'note'    => 'Save the secret somewhere safe; it will not be shown again.',
]);
