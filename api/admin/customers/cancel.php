<?php
// POST /api/admin/customers/cancel.php
// Body: { customer_id?: int, customer_email?: string,
//         immediate?: bool,
//         reason: string }
//
// Cancels a customer's paid subscription. Two modes:
//   immediate=false (default) - cancel at period end. Stripe keeps charging
//      nothing more after the current cycle; the customer keeps access through
//      the end of the period they already paid for.
//   immediate=true - cancel right now. Stripe ends the subscription
//      immediately and the customer's tier resets to free.
//
// Free-tier customers without an active Stripe subscription have nothing
// to cancel; the endpoint refuses cleanly. To "downgrade" a free-tier
// customer further, use Change Plan.
//
// Audit row written with severity=critical regardless of mode.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';
require_once __DIR__ . '/../../_stripe.php';
require_once __DIR__ . '/../../_tiers.php';

require_method('POST');
check_origin();

// Catch any fatal error and return it as JSON so the admin panel can show
// the actual cause instead of a generic "HTTP 500". Without this, a typo
// or undefined-function error returns an HTML error page that the JS can't
// surface in a toast.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error'   => 'fatal_error',
                'message' => $err['message'] . ' at ' . basename((string)$err['file']) . ':' . $err['line'],
            ]);
        }
    }
});

try {
    $admin = require_admin();

$body          = read_json_body();
$customerId    = (int)($body['customer_id'] ?? 0);
$customerEmail = strtolower(clean_string((string)($body['customer_email'] ?? ''), 255));
$immediate     = !empty($body['immediate']);
$reason        = clean_string((string)($body['reason'] ?? ''), 500);

if ($customerId <= 0 && $customerEmail === '') fail('bad_input', 'Provide customer_id or customer_email.');
if ($reason === '') fail('reason_required', 'A reason is required to cancel a membership.');

$pdo = db();

// Resolve customer.
if ($customerId > 0) {
    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
} else {
    if (!valid_email($customerEmail)) fail('bad_email', 'Invalid customer_email.');
    $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE email = :em');
    $stmt->execute([':em' => $customerEmail]);
}
$customer = $stmt->fetch();
if (!$customer) fail('not_found', 'Customer not found.', 404);
$cuid = (int)$customer['id'];

// Find their cancellable subscription. Bail clearly if none.
try {
    $hasSubsTable = (bool)$pdo->query("SHOW TABLES LIKE 'subscriptions'")->fetchColumn();
} catch (Throwable $e) { $hasSubsTable = false; }
if (!$hasSubsTable) {
    fail('no_subscriptions_table', 'This database has no subscriptions table; nothing to cancel. Free-tier customers can be downgraded via Change Plan.');
}

// subscriptions uses user_id as the PRIMARY KEY (one subscription per user).
$sstmt = $pdo->prepare(
    "SELECT user_id, stripe_subscription_id, status, tier, cycle, current_period_end, cancel_at_period_end
       FROM subscriptions
      WHERE user_id = :uid
        AND status IN ('active','trialing','past_due','incomplete')
      LIMIT 1"
);
$sstmt->execute([':uid' => $cuid]);
$sub = $sstmt->fetch();
if (!$sub) {
    fail('no_active_subscription', 'This customer has no active Stripe subscription to cancel. They are likely on the free tier already.');
}

if ($immediate === false && (int)$sub['cancel_at_period_end'] === 1) {
    fail('already_pending_cancel', 'This subscription is already set to cancel at period end (' . substr((string)($sub['current_period_end'] ?? ''), 0, 10) . ').');
}

$beforeLabel = $sub['tier'] . ' * ' . $sub['cycle'] . ' * status=' . $sub['status']
    . ($sub['current_period_end'] ? ' * period ends ' . substr((string)$sub['current_period_end'], 0, 10) : '');
$afterLabel  = '';

$stripeSubId = (string)($sub['stripe_subscription_id'] ?? '');
if ($stripeSubId === '') {
    fail('missing_stripe_id', 'Local subscription row has no stripe_subscription_id; cannot reach Stripe.');
}

try {
    if ($immediate) {
        // Hard cancel. Stripe ends the subscription immediately.
        $stripeResult = stripe_request('DELETE', 'subscriptions/' . $stripeSubId);

        // Update local row to canceled and reset the user's tier to free.
        $pdo->prepare(
            "UPDATE subscriptions
                SET status = 'canceled',
                    cancel_at_period_end = 0,
                    updated_at = NOW()
              WHERE user_id = :uid"
        )->execute([':uid' => $cuid]);

        set_user_tier($cuid, 'free', null, 'admin_cancel_immediate', 'admin:' . $admin['email']);

        $afterLabel = 'canceled immediately * tier reset to free';
    } else {
        // Soft cancel - runs out the period.
        $stripeResult = stripe_post('subscriptions/' . $stripeSubId, [
            'cancel_at_period_end' => 'true',
        ]);

        $pdo->prepare(
            "UPDATE subscriptions
                SET cancel_at_period_end = 1,
                    updated_at = NOW()
              WHERE user_id = :uid"
        )->execute([':uid' => $cuid]);

        $endDate = $sub['current_period_end'] ? substr((string)$sub['current_period_end'], 0, 10) : 'period end';
        $afterLabel = 'cancel scheduled * access through ' . $endDate;
    }
} catch (StripeError $e) {
    fail('stripe_error', 'Stripe rejected the cancellation: ' . $e->getMessage(), $e->http ?: 502);
} catch (Throwable $e) {
    fail('cancel_failed', 'Could not cancel: ' . $e->getMessage(), 500);
}

admin_audit_log(
    ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => $admin['role'] ?? 'owner'],
    $immediate ? 'Canceled membership (immediate)' : 'Canceled membership (at period end)',
    'membership',
    [
        'severity'     => 'critical',
        'target_type'  => 'customer',
        'target_id'    => 'cus_' . $cuid,
        'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
        'before'       => $beforeLabel,
        'after'        => $afterLabel,
        'reason'       => $reason,
    ]
);

json_out([
    'ok'        => true,
    'immediate' => $immediate,
    'message'   => $immediate
        ? 'Canceled ' . $customer['email'] . ' immediately. Tier reset to free.'
        : 'Cancellation scheduled for ' . $customer['email'] . '. Access through ' . substr((string)($sub['current_period_end'] ?? ''), 0, 10) . '.',
]);

} catch (Throwable $e) {
    // Top-level safety net: any uncaught error/exception in the request
    // path returns JSON instead of a generic 500 page. The admin panel's
    // toast can then surface the real cause.
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error'   => 'cancel_uncaught',
        'message' => $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine(),
        'class'   => get_class($e),
    ]);
    exit;
}
