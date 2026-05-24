<?php
// POST /api/billing/webhook.php
//
// Stripe -> ReliCheck. Handles subscription lifecycle events and updates
// users.tier / tier_expires_at and the subscriptions table accordingly.
// Verifies the Stripe-Signature header against the webhook secret.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_stripe.php';
require_once __DIR__ . '/../_tiers.php';

// Note: NO check_origin() - Stripe is the origin. Signature is the auth.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    header('Content-Type: text/plain');
    echo 'webhook ok'; exit; // Stripe sends a HEAD/GET sometimes for endpoint validation
}

$cfg = relicheck_config();
$secret = (string)($cfg['stripe_webhook_secret'] ?? '');
if ($secret === '') {
    error_log('[relicheck] webhook called but no stripe_webhook_secret configured');
    http_response_code(500); echo 'webhook secret not configured'; exit;
}

$payload   = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = stripe_verify_signature($payload, $sigHeader, $secret);
} catch (StripeError $e) {
    error_log('[relicheck] webhook signature verify failed: ' . $e->getMessage());
    http_response_code(400); echo 'invalid signature'; exit;
}

$eventId   = (string)($event['id']   ?? '');
$eventType = (string)($event['type'] ?? '');
$obj       = $event['data']['object'] ?? [];

// Idempotency: if we've already seen this event id, ack and exit.
if ($eventId !== '' && stripe_event_seen($eventId)) {
    http_response_code(200); echo 'ok (dup)'; exit;
}

try {
    switch ($eventType) {
        case 'checkout.session.completed':
            handle_checkout_completed($obj);
            break;
        case 'customer.subscription.created':
        case 'customer.subscription.updated':
            handle_subscription_change($obj);
            break;
        case 'customer.subscription.deleted':
            handle_subscription_deleted($obj);
            break;
        case 'invoice.payment_succeeded':
        case 'invoice.paid':
            dispatch_email_for_invoice_paid($obj);
            break;
        case 'invoice.payment_failed':
            // Email the customer + alert billing ops. Subscription tier flip
            // happens via the subsequent customer.subscription.updated event.
            dispatch_email_for_invoice_failed($obj);
            error_log('[relicheck] invoice.payment_failed for sub ' . ($obj['subscription'] ?? '?'));
            break;
        case 'charge.refunded':
            dispatch_email_for_refund($obj);
            break;
        default:
            // No-op for unhandled types; Stripe re-sends if we don't 2xx.
            break;
    }
    if ($eventId !== '') stripe_event_record($eventId, $eventType, null);
    http_response_code(200); echo 'ok';
} catch (Throwable $e) {
    error_log('[relicheck] webhook handler error: ' . $e->getMessage());
    http_response_code(500); echo 'handler error';
}

/* ---------- Handlers ---------- */

function user_id_from_customer(string $customerId): ?int
{
    if ($customerId === '') return null;
    $stmt = db()->prepare('SELECT user_id FROM stripe_customers WHERE stripe_customer_id = :c LIMIT 1');
    $stmt->execute([':c' => $customerId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['user_id'] : null;
}

function handle_checkout_completed(array $session): void
{
    $userId = (int)($session['client_reference_id'] ?? 0);
    if (!$userId) {
        $userId = user_id_from_customer((string)($session['customer'] ?? '')) ?? 0;
    }
    if (!$userId) return;

    $subId = (string)($session['subscription'] ?? '');
    if ($subId === '') return;

    $sub = stripe_get('/subscriptions/' . urlencode($subId));
    apply_subscription_to_user($userId, $sub);
}

function handle_subscription_change(array $sub): void
{
    $userId = user_id_from_customer((string)($sub['customer'] ?? '')) ?? 0;
    if (!$userId) {
        // Try the subscription's own metadata
        $userId = (int)($sub['metadata']['user_id'] ?? 0);
    }
    if (!$userId) return;
    apply_subscription_to_user($userId, $sub);
}

function handle_subscription_deleted(array $sub): void
{
    $userId = user_id_from_customer((string)($sub['customer'] ?? '')) ?? 0;
    if (!$userId) {
        $userId = (int)($sub['metadata']['user_id'] ?? 0);
    }
    if (!$userId) return;

    db()->prepare(
        'UPDATE subscriptions SET status = \'canceled\', cancel_at_period_end = 0 WHERE stripe_subscription_id = :s'
    )->execute([':s' => (string)($sub['id'] ?? '')]);

    set_user_tier($userId, TIER_FREE, null, 'stripe_subscription_deleted', (string)($sub['id'] ?? ''));
}

function apply_subscription_to_user(int $userId, array $sub): void
{
    $items = $sub['items']['data'] ?? [];
    $priceId = (string)($items[0]['price']['id'] ?? '');
    $map = stripe_price_to_tier($priceId);
    if (!$map) {
        error_log('[relicheck] unknown price ID in subscription: ' . $priceId);
        return;
    }
    $status = (string)($sub['status'] ?? 'active');
    $cpEnd  = (int)($sub['current_period_end'] ?? 0);
    $expiresAt = $cpEnd > 0 ? date('Y-m-d H:i:s', $cpEnd) : null;
    $cancelAtEnd = !empty($sub['cancel_at_period_end']) ? 1 : 0;

    // Update subscription row (insert-or-update on stripe_subscription_id).
    db()->prepare(
        'INSERT INTO subscriptions (user_id, stripe_subscription_id, status, tier, cycle, current_period_end, cancel_at_period_end)
         VALUES (:u, :s, :st, :t, :c, :cpe, :ce)
         ON DUPLICATE KEY UPDATE status = VALUES(status), tier = VALUES(tier), cycle = VALUES(cycle),
                                  current_period_end = VALUES(current_period_end),
                                  cancel_at_period_end = VALUES(cancel_at_period_end)'
    )->execute([
        ':u'   => $userId,
        ':s'   => (string)($sub['id'] ?? ''),
        ':st'  => $status,
        ':t'   => $map['tier'],
        ':c'   => $map['cycle'],
        ':cpe' => $expiresAt,
        ':ce'  => $cancelAtEnd,
    ]);

    // Decide the user's effective tier. Active or trialing -> the paid tier.
    // Anything else (past_due, unpaid, incomplete, canceled) -> fall back to free
    // once the period ends; until then we keep them on the paid tier.
    $effectiveTier = TIER_FREE;
    if (in_array($status, ['active','trialing'], true)) {
        $effectiveTier = $map['tier'];
    } elseif (in_array($status, ['past_due','unpaid'], true)) {
        // Keep them on the tier until current_period_end.
        $effectiveTier = $map['tier'];
    }
    set_user_tier($userId, $effectiveTier, $expiresAt, 'stripe_subscription_' . $status, (string)($sub['id'] ?? ''));
}

/* ---------- Email dispatch helpers (additive; never throw) ---------- */

function dispatch_email_for_invoice_paid(array $invoice): void
{
    if (!is_file(__DIR__ . '/../_email_dispatcher.php')) return;
    try {
        require_once __DIR__ . '/../_email_dispatcher.php';
        $userId = user_id_from_customer((string)($invoice['customer'] ?? '')) ?? 0;
        if (!$userId) return;
        $u = db()->prepare('SELECT email, name FROM users WHERE id = :id LIMIT 1');
        $u->execute([':id' => $userId]);
        $row = $u->fetch();
        if (!$row) return;
        $amountCents = (int)($invoice['amount_paid'] ?? $invoice['amount_due'] ?? 0);
        $currency    = strtoupper((string)($invoice['currency'] ?? 'usd'));
        relicheck_email_dispatch('billing.charge.succeeded', [
            'user_id'    => $userId,
            'account_id' => $userId,
            'idempotency_entity_id' => 'invoice:' . (string)($invoice['id'] ?? ''),
            'payload'    => [
                'first_name'     => trim(explode(' ', (string)$row['name'])[0] ?: 'there'),
                'email'          => (string)$row['email'],
                'amount'         => sprintf('%s %.2f', $currency, $amountCents / 100),
                'charge_date'    => date('Y-m-d', (int)($invoice['created'] ?? time())),
                'plan_name'      => (string)($invoice['lines']['data'][0]['description'] ?? 'ReliCheck plan'),
                'invoice_number' => (string)($invoice['number'] ?? $invoice['id'] ?? ''),
                'invoice_id'     => (string)($invoice['id'] ?? ''),
            ],
        ]);
    } catch (Throwable $e) {
        error_log('[relicheck] billing.charge.succeeded dispatch failed: ' . $e->getMessage());
    }
}

function dispatch_email_for_invoice_failed(array $invoice): void
{
    if (!is_file(__DIR__ . '/../_email_dispatcher.php')) return;
    try {
        require_once __DIR__ . '/../_email_dispatcher.php';
        $userId = user_id_from_customer((string)($invoice['customer'] ?? '')) ?? 0;
        if (!$userId) return;
        $u = db()->prepare('SELECT email, name FROM users WHERE id = :id LIMIT 1');
        $u->execute([':id' => $userId]);
        $row = $u->fetch();
        if (!$row) return;
        $amountCents = (int)($invoice['amount_due'] ?? 0);
        $currency    = strtoupper((string)($invoice['currency'] ?? 'usd'));
        relicheck_email_dispatch('billing.charge.failed', [
            'user_id'    => $userId,
            'account_id' => $userId,
            'idempotency_entity_id' => 'invoice-failed:' . (string)($invoice['id'] ?? ''),
            'payload'    => [
                'first_name'   => trim(explode(' ', (string)$row['name'])[0] ?: 'there'),
                'email'        => (string)$row['email'],
                'amount'       => sprintf('%s %.2f', $currency, $amountCents / 100),
                'attempted_at' => date('Y-m-d H:i:s'),
                'customer_account_name' => (string)$row['name'],
                'customer_id'           => (string)$userId,
                'attempts'              => (int)($invoice['attempt_count'] ?? 1),
                'failure_reason'        => 'Card declined or payment method failed.',
            ],
        ]);
    } catch (Throwable $e) {
        error_log('[relicheck] billing.charge.failed dispatch failed: ' . $e->getMessage());
    }
}

function dispatch_email_for_refund(array $charge): void
{
    if (!is_file(__DIR__ . '/../_email_dispatcher.php')) return;
    try {
        require_once __DIR__ . '/../_email_dispatcher.php';
        $userId = user_id_from_customer((string)($charge['customer'] ?? '')) ?? 0;
        if (!$userId) return;
        $u = db()->prepare('SELECT email, name FROM users WHERE id = :id LIMIT 1');
        $u->execute([':id' => $userId]);
        $row = $u->fetch();
        if (!$row) return;
        $refundCents = (int)($charge['amount_refunded'] ?? 0);
        $currency    = strtoupper((string)($charge['currency'] ?? 'usd'));
        relicheck_email_dispatch('billing.refund_issued', [
            'user_id'    => $userId,
            'account_id' => $userId,
            'idempotency_entity_id' => 'refund:' . (string)($charge['id'] ?? ''),
            'payload'    => [
                'first_name'      => trim(explode(' ', (string)$row['name'])[0] ?: 'there'),
                'email'           => (string)$row['email'],
                'amount'          => sprintf('%s %.2f', $currency, $refundCents / 100),
                'invoice_number'  => (string)($charge['invoice'] ?? ''),
                'invoice_id'      => (string)($charge['invoice'] ?? ''),
                'refund_date'     => date('Y-m-d'),
                'customer_account_name' => (string)$row['name'],
                'customer_id'           => (string)$userId,
                'processed_by'          => 'Stripe',
            ],
        ]);
    } catch (Throwable $e) {
        error_log('[relicheck] billing.refund_issued dispatch failed: ' . $e->getMessage());
    }
}
