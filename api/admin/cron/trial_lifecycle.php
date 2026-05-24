<?php
// GET /api/admin/cron/trial_lifecycle.php?key=<email_cron_key>
//
// Daily sweep that fires the right trial-lifecycle email for each customer
// with an active trial:
//
//   trial.started       - fired the day the trial began (catch-all if the
//                         Stripe webhook hadn't wired this yet)
//   trial.midpoint      - fired when half the trial has elapsed
//   trial.ending_soon   - fired when 2 days remain
//   trial.expired       - fired the day the trial expires (and once after,
//                         to catch trials that ended overnight)
//
// Idempotency: each event's idempotency_entity_id includes the trial's
// expiration timestamp, so each email fires at most once per trial period.
// A user who reactivates a fresh trial later will get a fresh series.
//
// Auth: same email_cron_key as the queue worker. Schedule daily.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_email_dispatcher.php';
require_once __DIR__ . '/../../_cron.php';

require_method('GET', 'POST');

$cfg = relicheck_config();
$expected = (string)($cfg['email_cron_key'] ?? '');
if ($expected === '') {
    fail('not_configured', 'email_cron_key is not set in api/_config.php.', 500);
}
$given = (string)($_GET['key'] ?? $_POST['key'] ?? '');
if (!hash_equals($expected, $given)) {
    fail('forbidden', 'Invalid cron key.', 403);
}

cron_heartbeat_start('trial_lifecycle');

$pdo = db();

// Pull active trials. A user is "on a trial" if EITHER:
//   - subscriptions.status = 'trialing' for that user, OR
//   - users.tier <> 'free' AND users.tier_expires_at IS NOT NULL AND there's
//     no active paid subscription (covers non-Stripe trials, if any).
//
// We compute days_remaining and trial_total_days entirely in SQL so the
// IONOS PHP/MySQL timezone gap doesn't bite. Wrapped LEFT JOIN handles the
// case where the subscriptions table doesn't exist yet (older installs).
$rows = [];
try {
    $rows = $pdo->query(
        "SELECT u.id, u.email, u.name, u.tier, u.tier_expires_at, u.tier_changed_at,
                DATEDIFF(u.tier_expires_at, NOW())               AS days_remaining,
                DATEDIFF(u.tier_expires_at, IFNULL(u.tier_changed_at, u.created_at)) AS days_total,
                IFNULL(s.status, 'unknown')                      AS sub_status
         FROM users u
         LEFT JOIN subscriptions s ON s.user_id = u.id
         WHERE u.tier IS NOT NULL
           AND u.tier <> 'free'
           AND u.tier_expires_at IS NOT NULL
           AND (s.status IS NULL OR s.status IN ('trialing','past_due','unpaid','incomplete'))
         ORDER BY u.tier_expires_at ASC"
    )->fetchAll();
} catch (Throwable $e) {
    // subscriptions table missing on this install. Fall back to users-only.
    $rows = $pdo->query(
        "SELECT u.id, u.email, u.name, u.tier, u.tier_expires_at, u.tier_changed_at,
                DATEDIFF(u.tier_expires_at, NOW())               AS days_remaining,
                DATEDIFF(u.tier_expires_at, IFNULL(u.tier_changed_at, u.created_at)) AS days_total,
                'unknown'                                        AS sub_status
         FROM users u
         WHERE u.tier IS NOT NULL
           AND u.tier <> 'free'
           AND u.tier_expires_at IS NOT NULL
         ORDER BY u.tier_expires_at ASC"
    )->fetchAll();
}

$considered = count($rows);
$fired      = ['started' => 0, 'midpoint' => 0, 'ending_soon' => 0, 'expired' => 0];
$skipped    = 0;

foreach ($rows as $u) {
    $uid              = (int)$u['id'];
    $email            = (string)$u['email'];
    $name             = (string)$u['name'];
    $first            = trim(explode(' ', $name)[0] ?: 'there');
    $plan_name        = (string)$u['tier'];
    $expires_at       = (string)$u['tier_expires_at'];
    $trial_end_label  = date('F j, Y', strtotime($expires_at));
    $days_remaining   = (int)$u['days_remaining'];
    $days_total       = max(1, (int)$u['days_total']);  // protect against div-by-zero
    $days_elapsed     = $days_total - $days_remaining;
    $halfway_threshold = (int)floor($days_total / 2);

    // Common payload reused across the four templates.
    $base_payload = [
        'first_name'      => $first,
        'email'           => $email,
        'plan_name'       => $plan_name,
        'trial_days'      => $days_total,
        'trial_end_date'  => $trial_end_label,
        'days_remaining'  => max(0, $days_remaining),
    ];

    // -- trial.started: fire if the trial began today (catches non-webhook
    //    trial creations). The webhook may also fire it; idempotency dedupes.
    if (!empty($u['tier_changed_at'])) {
        $started_at = strtotime((string)$u['tier_changed_at']);
        if ($started_at && (time() - $started_at) < 86400 * 2) {
            // Started within the last 48h. The dispatcher will dedupe by
            // idempotency_entity_id so the email only goes once per trial.
            $r = relicheck_email_dispatch('trial.started', [
                'user_id'    => $uid,
                'account_id' => $uid,
                'idempotency_entity_id' => 'trial-started:' . $uid . ':' . $expires_at,
                'payload'    => $base_payload,
            ]);
            if (!empty($r['queued'])) $fired['started']++;
        }
    }

    // -- trial.expired: trial is past its expiration. Fire once.
    if ($days_remaining <= 0) {
        $r = relicheck_email_dispatch('trial.expired', [
            'user_id'    => $uid,
            'account_id' => $uid,
            'idempotency_entity_id' => 'trial-expired:' . $uid . ':' . $expires_at,
            'payload'    => $base_payload,
        ]);
        if (!empty($r['queued'])) $fired['expired']++;
        continue;
    }

    // -- trial.ending_soon: 1-2 days remain.
    if ($days_remaining >= 1 && $days_remaining <= 2) {
        $r = relicheck_email_dispatch('trial.ending_soon', [
            'user_id'    => $uid,
            'account_id' => $uid,
            'idempotency_entity_id' => 'trial-ending:' . $uid . ':' . $expires_at,
            'payload'    => $base_payload,
        ]);
        if (!empty($r['queued'])) $fired['ending_soon']++;
        continue;
    }

    // -- trial.midpoint: roughly half the trial elapsed (within 1-day window
    //    so a daily cron catches it even if exactly-half day was missed).
    if ($days_elapsed >= $halfway_threshold && $days_elapsed <= $halfway_threshold + 1
        && $days_remaining > 2) {
        $r = relicheck_email_dispatch('trial.midpoint', [
            'user_id'    => $uid,
            'account_id' => $uid,
            'idempotency_entity_id' => 'trial-midpoint:' . $uid . ':' . $expires_at,
            'payload'    => $base_payload,
        ]);
        if (!empty($r['queued'])) $fired['midpoint']++;
        continue;
    }

    $skipped++;
}

$_cronSummary = [
    'ok'         => true,
    'considered' => $considered,
    'fired'      => $fired,
    'skipped'    => $skipped,
    'message'    => "Considered $considered trials. Fired: started={$fired['started']}, midpoint={$fired['midpoint']}, ending_soon={$fired['ending_soon']}, expired={$fired['expired']}. Skipped {$skipped}.",
];
cron_heartbeat_done('trial_lifecycle', $_cronSummary);
json_out($_cronSummary);
