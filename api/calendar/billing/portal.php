<?php
// POST /api/billing/portal.php
// Returns a Stripe Customer Portal URL. The user manages payment method,
// upgrades, downgrades, and cancellation in Stripe-hosted UI.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_stripe.php';

require_method('POST');
check_origin();
$user = require_auth();

$cfg = relicheck_config();
$siteUrl = rtrim((string)($cfg['site_url'] ?? ''), '/');
if ($siteUrl === '') $siteUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');

try {
    $stmt = db()->prepare('SELECT stripe_customer_id FROM stripe_customers WHERE user_id = :id');
    $stmt->execute([':id' => $user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('no_customer', 'You have no billing information yet. Subscribe to a plan first.', 400);
    }
    $session = stripe_post('/billing_portal/sessions', [
        'customer'   => (string)$row['stripe_customer_id'],
        'return_url' => $siteUrl . '/app.html',
    ]);
    json_out(['ok' => true, 'url' => $session['url'] ?? '']);
} catch (StripeError $e) {
    fail('stripe_error', $e->getMessage(), $e->http ?: 502, ['stripe' => $e->body]);
}
