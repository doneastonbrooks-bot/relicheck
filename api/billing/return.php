<?php
// GET /api/billing/return.php?session_id=cs_...
//
// Stripe redirects the browser here after a successful Checkout. We do a
// fast-path tier update (pull the session from Stripe, set the tier ourselves)
// so the UI reflects the new plan immediately, even if the webhook hasn't
// fired yet. The webhook still runs and is idempotent.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_stripe.php';
require_once __DIR__ . '/../_tiers.php';

require_method('GET');
$user = require_auth();

// Phase 154c: redirect targets now point to App 2.0 directly with a hash
// route, so users land back in /app-2026.html on the Account view instead
// of bouncing through /app.html (which is being retired). The billing
// status code is preserved as a hash query so the SPA can surface a toast.
$appReturnBase = '/app-2026.html#/account';

$sessionId = (string)($_GET['session_id'] ?? '');
if ($sessionId === '') {
    header('Location: ' . $appReturnBase . '?billing=missing_session', true, 302); exit;
}

try {
    // Pull the session and its line items.
    $session = stripe_get('/checkout/sessions/' . urlencode($sessionId), ['expand[]' => 'line_items']);
    if (($session['client_reference_id'] ?? '') !== (string)$user['id']) {
        // Don't apply someone else's checkout to this user.
        header('Location: ' . $appReturnBase . '?billing=mismatch', true, 302); exit;
    }
    if (($session['payment_status'] ?? '') !== 'paid' && ($session['status'] ?? '') !== 'complete') {
        header('Location: ' . $appReturnBase . '?billing=pending', true, 302); exit;
    }

    $items = $session['line_items']['data'] ?? [];
    if (!$items) {
        header('Location: ' . $appReturnBase . '?billing=no_items', true, 302); exit;
    }
    $priceId = (string)($items[0]['price']['id'] ?? '');
    $tierMap = stripe_price_to_tier($priceId);
    if (!$tierMap) {
        header('Location: ' . $appReturnBase . '?billing=unknown_price', true, 302); exit;
    }

    // Compute an expires_at from the subscription itself if possible.
    $expiresAt = null;
    $subId = (string)($session['subscription'] ?? '');
    if ($subId !== '') {
        $sub = stripe_get('/subscriptions/' . urlencode($subId));
        $cpEnd = (int)($sub['current_period_end'] ?? 0);
        if ($cpEnd > 0) $expiresAt = date('Y-m-d H:i:s', $cpEnd);

        // Persist the subscription mapping (webhook will keep it fresh).
        db()->prepare(
            'INSERT INTO subscriptions (user_id, stripe_subscription_id, status, tier, cycle, current_period_end)
             VALUES (:u, :s, :st, :t, :c, :cpe)
             ON DUPLICATE KEY UPDATE status = VALUES(status), tier = VALUES(tier), cycle = VALUES(cycle), current_period_end = VALUES(current_period_end)'
        )->execute([
            ':u'   => $user['id'],
            ':s'   => $subId,
            ':st'  => (string)($sub['status'] ?? 'active'),
            ':t'   => $tierMap['tier'],
            ':c'   => $tierMap['cycle'],
            ':cpe' => $expiresAt,
        ]);
    }

    set_user_tier((int)$user['id'], $tierMap['tier'], $expiresAt, 'stripe_checkout', $sessionId);

    header('Location: ' . $appReturnBase . '?billing=ok', true, 302); exit;
} catch (StripeError $e) {
    error_log('[relicheck] billing return failed: ' . $e->getMessage());
    header('Location: ' . $appReturnBase . '?billing=error', true, 302); exit;
}
