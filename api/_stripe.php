<?php
// Minimal Stripe REST client for ReliCheck.
// We only use a few endpoints (checkout sessions, billing portal,
// customers, subscriptions) and webhook signature verification. No
// external dependencies; cURL ships with every Ionos PHP install.

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

const STRIPE_API_VERSION = '2024-06-20';
const STRIPE_BASE = 'https://api.stripe.com/v1';

class StripeError extends RuntimeException {
    public ?array $body = null;
    public int $http = 0;
}

function stripe_request(string $method, string $path, array $params = []): array
{
    $cfg = relicheck_config();
    $key = (string)($cfg['stripe_secret_key'] ?? '');
    if ($key === '') {
        $err = new StripeError('Stripe is not configured. Add stripe_secret_key to _config.php.');
        $err->http = 503;
        throw $err;
    }
    $url = STRIPE_BASE . $path;
    $body = '';
    if ($method === 'GET' && $params) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    } elseif ($method !== 'GET' && $params) {
        $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Stripe-Version: ' . STRIPE_API_VERSION,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);
    if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        $e = new StripeError('Stripe network error: ' . $err);
        $e->http = 502;
        throw $e;
    }
    $decoded = json_decode((string)$resp, true);
    if (!is_array($decoded)) $decoded = ['raw' => $resp];

    if ($http >= 400) {
        $message = $decoded['error']['message'] ?? ('Stripe HTTP ' . $http);
        $e = new StripeError('Stripe error: ' . $message);
        $e->http = $http;
        $e->body = $decoded;
        throw $e;
    }
    return $decoded;
}

function stripe_post(string $path, array $params = []): array { return stripe_request('POST', $path, $params); }
function stripe_get (string $path, array $params = []): array { return stripe_request('GET',  $path, $params); }

/**
 * Verify a Stripe webhook signature header.
 * Throws StripeError on any mismatch. Tolerance defaults to 5 minutes.
 */
function stripe_verify_signature(string $payload, string $sigHeader, string $secret, int $tolerance = 300): array
{
    if ($sigHeader === '' || $secret === '') {
        $e = new StripeError('Missing webhook signature or secret.'); $e->http = 400; throw $e;
    }
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) === 2) $parts[trim($kv[0])][] = trim($kv[1]);
    }
    $timestamp = (int)($parts['t'][0] ?? 0);
    $sigs      = $parts['v1'] ?? [];
    if (!$timestamp || !$sigs) {
        $e = new StripeError('Malformed Stripe signature header.'); $e->http = 400; throw $e;
    }
    if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
        $e = new StripeError('Webhook timestamp outside tolerance.'); $e->http = 400; throw $e;
    }
    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    foreach ($sigs as $s) {
        if (hash_equals($expected, $s)) {
            $event = json_decode($payload, true);
            if (!is_array($event)) {
                $e = new StripeError('Webhook payload is not valid JSON.'); $e->http = 400; throw $e;
            }
            return $event;
        }
    }
    $e = new StripeError('Webhook signature mismatch.'); $e->http = 400; throw $e;
}

/* ---------- Helpers tied to our domain model ---------- */

/**
 * Look up or create a Stripe customer for the given user. Returns the
 * stripe_customer_id string. Always pass a verified email/name.
 */
function stripe_customer_for(int $userId, string $email, string $name): string
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT stripe_customer_id FROM stripe_customers WHERE user_id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if ($row) return (string)$row['stripe_customer_id'];

    $created = stripe_post('/customers', [
        'email' => $email,
        'name'  => $name,
        'metadata[user_id]' => (string)$userId,
    ]);
    $cid = (string)($created['id'] ?? '');
    if ($cid === '') {
        $e = new StripeError('Stripe did not return a customer id.'); $e->http = 502; throw $e;
    }
    $pdo->prepare('INSERT INTO stripe_customers (user_id, stripe_customer_id) VALUES (:u, :c)')
        ->execute([':u' => $userId, ':c' => $cid]);
    return $cid;
}

/** Look up the Price ID for a (tier, cycle) pair from config. */
function stripe_price_id(string $tier, string $cycle): string
{
    $cfg = relicheck_config();
    $key = 'stripe_price_' . $tier . '_' . $cycle;
    return (string)($cfg[$key] ?? '');
}

/** Reverse-map a Price ID back to (tier, cycle). Used by webhook handler. */
function stripe_price_to_tier(string $priceId): ?array
{
    $cfg = relicheck_config();
    foreach (['researcher','professional','business'] as $tier) {
        foreach (['monthly','annual'] as $cycle) {
            $key = 'stripe_price_' . $tier . '_' . $cycle;
            if (!empty($cfg[$key]) && (string)$cfg[$key] === $priceId) {
                return ['tier' => $tier, 'cycle' => $cycle];
            }
        }
    }
    return null;
}

function stripe_event_seen(string $eventId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM stripe_events WHERE event_id = :e LIMIT 1');
    $stmt->execute([':e' => $eventId]);
    return (bool)$stmt->fetch();
}

function stripe_event_record(string $eventId, string $type, ?int $userId): void
{
    db()->prepare(
        'INSERT IGNORE INTO stripe_events (event_id, event_type, user_id) VALUES (:e, :t, :u)'
    )->execute([':e' => $eventId, ':t' => $type, ':u' => $userId]);
}
