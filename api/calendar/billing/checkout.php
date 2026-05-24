<?php
// POST /api/billing/checkout.php
// Body: { plan: 'researcher'|'professional'|'business', cycle: 'monthly'|'annual' }
//
// Creates a Stripe Checkout Session and returns its URL. The front-end
// redirects the browser to that URL. After payment, Stripe sends the user
// to /api/billing/return.php?session_id=cs_...

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_stripe.php';
require_once __DIR__ . '/../_tiers.php';

require_method('POST');
check_origin();
$user = require_auth();

$body  = read_json_body();
$plan  = (string)($body['plan']  ?? '');
$cycle = (string)($body['cycle'] ?? 'monthly');

if (!in_array($plan, ['researcher','professional','business'], true)) {
    fail('bad_plan', 'Invalid plan.', 400);
}
if (!in_array($cycle, ['monthly','annual'], true)) {
    fail('bad_cycle', 'Invalid billing cycle.', 400);
}

$priceId = stripe_price_id($plan, $cycle);
if ($priceId === '') {
    fail('plan_not_configured', 'This plan is not yet available for purchase. Please email support.', 503);
}

$cfg     = relicheck_config();
$siteUrl = rtrim((string)($cfg['site_url'] ?? ''), '/');
if ($siteUrl === '') $siteUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');

try {
    $customerId = stripe_customer_for((int)$user['id'], (string)$user['email'], (string)$user['name']);

    // Phase 154c: cancel_url stays inside App 2.0 so a canceled upgrade
    // doesn't kick the user out to the marketing pricing page (and force
    // a re-login). Success path still routes through return.php for the
    // fast-path tier update; return.php now also lands inside App 2.0.
    $params = [
        'mode'       => 'subscription',
        'customer'   => $customerId,
        'success_url'=> $siteUrl . '/api/billing/return.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $siteUrl . '/app-2026.html#/account?billing=canceled',
        'allow_promotion_codes' => 'true',
        'billing_address_collection' => 'auto',
        'client_reference_id' => (string)$user['id'],
        'line_items[0][price]'    => $priceId,
        'line_items[0][quantity]' => '1',
        // Make webhook handling easier: stash plan + cycle in subscription metadata
        'subscription_data[metadata][user_id]' => (string)$user['id'],
        'subscription_data[metadata][tier]'    => $plan,
        'subscription_data[metadata][cycle]'   => $cycle,
        // Auto-collect tax behavior left to Stripe defaults
    ];
    $session = stripe_post('/checkout/sessions', $params);
    json_out([
        'ok'  => true,
        'url' => $session['url'] ?? '',
        'session_id' => $session['id'] ?? '',
    ]);
} catch (StripeError $e) {
    fail('stripe_error', $e->getMessage(), $e->http ?: 502, ['stripe' => $e->body]);
}
