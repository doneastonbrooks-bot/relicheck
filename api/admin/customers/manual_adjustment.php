<?php
// POST /api/admin/customers/manual_adjustment.php
// Body: { customer_id?: int, customer_email?: string,
//         type: 'credit'|'debit',
//         amount_cents: int,        (always positive; sign is applied from type)
//         currency?: string,        (default 'usd')
//         description?: string,     (visible to customer in their billing portal)
//         reason: string }          (internal audit reason)
//
// Adjusts the customer's Stripe customer balance.
//   credit  -> amount is APPLIED as a credit (Stripe stores it as a negative
//             balance, which reduces the next invoice).
//   debit   -> amount is added to what the customer owes (positive balance).
// Both flow through Stripe so the customer's invoices, receipts, and
// portal all reflect the change. We never create an unbacked local credit.
//
// For tier-level "comp" actions (e.g., grant Researcher for 90 days), use
// Change Plan or Apply Promo Code - those are the right tools, not this one.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_admin_audit.php';
require_once __DIR__ . '/../../_stripe.php';

require_method('POST');
check_origin();

// Top-level safety net so any fatal error returns JSON instead of an HTML 500.
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
    $type          = strtolower(clean_string((string)($body['type'] ?? ''), 16));
    $amountCents   = (int)($body['amount_cents'] ?? 0);
    $currency      = strtolower(clean_string((string)($body['currency'] ?? 'usd'), 8));
    $description   = clean_string((string)($body['description'] ?? ''), 200);
    $reason        = clean_string((string)($body['reason'] ?? ''), 500);

    if ($customerId <= 0 && $customerEmail === '') fail('bad_input', 'Provide customer_id or customer_email.');
    if (!in_array($type, ['credit', 'debit'], true)) {
        fail('bad_type', 'type must be "credit" or "debit". For tier comps, use Change Plan or Apply Promo Code.');
    }
    if ($amountCents <= 0)        fail('bad_amount', 'amount_cents must be a positive integer (sign is applied from type).');
    if ($amountCents > 1000000)   fail('amount_too_large', 'Amount exceeds the safety cap of $10,000.00 per adjustment.');
    if ($reason === '')            fail('reason_required', 'Reason is required for billing adjustments.');
    if (!preg_match('/^[a-z]{3}$/', $currency)) fail('bad_currency', 'Currency must be a 3-letter ISO code, e.g., "usd".');

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

    // Map to Stripe customer id.
    try {
        $hasStripeTable = (bool)$pdo->query("SHOW TABLES LIKE 'stripe_customers'")->fetchColumn();
    } catch (Throwable $e) { $hasStripeTable = false; }
    if (!$hasStripeTable) {
        fail('no_stripe_customers_table', 'This database has no stripe_customers table; no Stripe customer to adjust.');
    }

    $sc = $pdo->prepare('SELECT stripe_customer_id FROM stripe_customers WHERE user_id = :uid');
    $sc->execute([':uid' => $cuid]);
    $row = $sc->fetch();
    $stripeCustomerId = $row ? (string)$row['stripe_customer_id'] : '';
    if ($stripeCustomerId === '') {
        fail('no_stripe_customer', 'This customer has no Stripe customer record yet (free tier with no checkout history). Manual balance adjustments only apply to customers Stripe knows about.');
    }

    // Sign convention: credit -> negative balance (Stripe applies as discount on next invoice).
    //                  debit  -> positive balance (customer owes more).
    $signedAmount = ($type === 'credit') ? -$amountCents : $amountCents;

    $stripeParams = [
        'amount'      => (string)$signedAmount,
        'currency'    => $currency,
    ];
    if ($description !== '') {
        $stripeParams['description'] = $description;
    }
    // Surface the admin actor in Stripe metadata so finance can audit there too.
    $stripeParams['metadata[admin_email]'] = $admin['email'];
    $stripeParams['metadata[admin_id]']    = (string)(int)$admin['id'];
    $stripeParams['metadata[reason]']      = mb_substr($reason, 0, 500);

    try {
        $resp = stripe_post('customers/' . $stripeCustomerId . '/balance_transactions', $stripeParams);
    } catch (StripeError $e) {
        fail('stripe_error', 'Stripe rejected the adjustment: ' . $e->getMessage(), $e->http ?: 502);
    }

    $balanceTxnId = (string)($resp['id'] ?? '');
    $endingBalance = isset($resp['ending_balance']) ? (int)$resp['ending_balance'] : null;

    $amountDollars = number_format($amountCents / 100, 2);
    $signLabel = $type === 'credit' ? '-$' : '+$';

    admin_audit_log(
        ['id' => (int)$admin['id'], 'email' => $admin['email'], 'role' => $admin['role'] ?? 'owner'],
        'Manual ' . $type . ' adjustment',
        'membership',
        [
            'severity'     => 'critical',
            'target_type'  => 'customer',
            'target_id'    => 'cus_' . $cuid,
            'target_label' => trim(($customer['name'] ?: '') . ' (' . $customer['email'] . ')'),
            'before'       => $endingBalance !== null
                ? 'Stripe balance before: $' . number_format(($endingBalance + (-$signedAmount)) / 100, 2)
                : '-',
            'after'        => $signLabel . $amountDollars . ' ' . strtoupper($currency)
                . ' * stripe_txn:' . $balanceTxnId
                . ($endingBalance !== null ? ' * new balance: $' . number_format($endingBalance / 100, 2) : ''),
            'reason'       => $reason,
        ]
    );

    json_out([
        'ok'              => true,
        'type'            => $type,
        'amount_cents'    => $amountCents,
        'currency'        => $currency,
        'stripe_txn_id'   => $balanceTxnId,
        'ending_balance'  => $endingBalance,
        'message'         => 'Applied ' . $type . ' of $' . $amountDollars . ' ' . strtoupper($currency) . ' to ' . $customer['email'] . '. Stripe txn: ' . $balanceTxnId,
    ]);

} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error'   => 'adjustment_uncaught',
        'message' => $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine(),
        'class'   => get_class($e),
    ]);
    exit;
}
