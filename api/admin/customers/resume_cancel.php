<?php
// POST /api/admin/customers/resume_cancel.php
// Body: { customer_id?: int, customer_email?: string, reason: string }
//
// Reverses an at-period-end cancellation. Stripe API:
//   POST /v1/subscriptions/{id}  with  cancel_at_period_end=false
// The subscription resumes normal billing. The customer never loses access
// because we do this BEFORE the period-end actually fires.
//
// Refuses if the subscription is already fully canceled (status='canceled')
// - at that point Stripe needs a brand-new subscription, not a resume.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';
require_once __DIR__ . '/../../_stripe.php';

require_method('POST');
check_origin();

// Top-level safety net so any fatal returns JSON, not a generic 500.
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
    $reason        = clean_string((string)($body['reason'] ?? ''), 500);

    if ($customerId <= 0 && $customerEmail === '') fail('bad_input', 'Provide customer_id or customer_email.');
    if ($reason === '') fail('reason_required', 'Reason is required to resume a scheduled cancellation.');

    $pdo = db();

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

    try {
        $hasSubsTable = (bool)$pdo->query("SHOW TABLES LIKE 'subscriptions'")->fetchColumn();
    } catch (Throwable $e) { $hasSubsTable = false; }
    if (!$hasSubsTable) {
        fail('no_subscriptions_table', 'No subscriptions table; nothing to resume.');
    }

    $sstmt = $pdo->prepare(
        "SELECT user_id, stripe_subscription_id, status, tier, cycle, current_period_end, cancel_at_period_end
           FROM subscriptions WHERE user_id = :uid LIMIT 1"
    );
    $sstmt->execute([':uid' => $cuid]);
    $sub = $sstmt->fetch();
    if (!$sub) {
        fail('no_subscription', 'This customer has no subscription to resume.');
    }
    if ((int)$sub['cancel_at_period_end'] !== 1) {
        fail('not_scheduled', 'This subscription has no scheduled cancellation to resume.');
    }
    if ($sub['status'] === 'canceled') {
        fail('already_canceled', 'This subscription is already fully canceled. Resume only works before the period-end fires; create a new subscription instead.');
    }

    $stripeSubId = (string)($sub['stripe_subscription_id'] ?? '');
    if ($stripeSubId === '') {
        fail('missing_stripe_id', 'Local subscription row has no stripe_subscription_id; cannot reach Stripe.');
    }

    try {
        $stripeResult = stripe_post('subscriptions/' . $stripeSubId, [
            'cancel_at_period_end' => 'false',
        ]);
    } catch (StripeError $e) {
        fail('stripe_error', 'Stripe rejected the resume: ' . $e->getMessage(), $e->http ?: 502);
    }

    $pdo->prepare(
        "UPDATE subscriptions
            SET cancel_at_period_end = 0,
                updated_at = NOW()
          WHERE user_id = :uid"
    )->execute([':uid' => $cuid]);

    $endDate = $sub['current_period_end'] ? substr((string)$sub['current_period_end'], 0, 10) : 'period end';

    admin_audit_log(
        ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => $admin['role'] ?? 'owner'],
        'Resumed scheduled cancellation',
        'membership',
        [
            'severity'     => 'warn',
            'target_type'  => 'customer',
            'target_id'    => 'cus_' . $cuid,
            'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
            'before'       => 'cancel scheduled * would have ended ' . $endDate,
            'after'        => 'resumed * subscription continues normally',
            'reason'       => $reason,
        ]
    );

    json_out([
        'ok'      => true,
        'message' => 'Resumed ' . $customer['email'] . '\'s subscription. The previously scheduled cancellation on ' . $endDate . ' has been reversed; billing continues as normal.',
    ]);

} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error'   => 'resume_uncaught',
        'message' => $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine(),
        'class'   => get_class($e),
    ]);
    exit;
}
