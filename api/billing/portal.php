<?php
// POST /api/billing/portal.php
//
// Creates a Stripe Customer Portal session for the current user and returns
// its URL. The front-end (App 2.0 Account page, "Manage subscription" button)
// redirects the browser to that URL. Inside the portal, the customer can
// update payment method, view invoices, cancel, or switch plans.
//
// The Portal needs a Customer ID. If we don't have one yet for this user,
// stripe_customer_for() creates one on the fly.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_stripe.php';

require_method('POST');
check_origin();
$user = require_auth();

$cfg     = relicheck_config();
$siteUrl = rtrim((string)($cfg['site_url'] ?? ''), '/');
if ($siteUrl === '') $siteUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// Phase 154d: portal sends the user back to App 2.0 Account view, not
// the retired /app.html shell. Preserves the in-app feel of the upgrade
// flow and keeps the session intact across the Stripe redirect.
$returnUrl = $siteUrl . '/app-2026.html#/account';

try {
    $customerId = stripe_customer_for((int)$user['id'], (string)$user['email'], (string)$user['name']);

    $params = [
        'customer'   => $customerId,
        'return_url' => $returnUrl,
    ];

    // Optional: if the live _config.php declares a portal configuration ID,
    // pass it so Stripe uses the matching branded portal config (logo,
    // allowed actions, allowed price switches, etc.). The example config
    // does not ship this key, so absence is fine and falls back to the
    // default portal configuration set in the Stripe Dashboard.
    $portalConfigId = trim((string)($cfg['stripe_portal_configuration_id'] ?? ''));
    if ($portalConfigId !== '') {
        $params['configuration'] = $portalConfigId;
    }

    $session = stripe_post('/billing_portal/sessions', $params);
    json_out([
        'ok'  => true,
        'url' => $session['url'] ?? '',
    ]);
} catch (StripeError $e) {
    fail('stripe_error', $e->getMessage(), $e->http ?: 502, ['stripe' => $e->body]);
}
