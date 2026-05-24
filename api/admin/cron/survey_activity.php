<?php
// GET /api/admin/cron/survey_activity.php?key=<email_cron_key>
//
// Daily sweep that fires the right activity email for each published survey:
//
//   survey.no_responses        - survey has been live for 3+ days with 0
//                                responses. Fires once per survey.
//   survey.low_response_rate   - survey has been live for 7+ days with fewer
//                                than the configured low-rate threshold.
//                                Fires once per survey.
//   survey.milestone_reached   - survey crossed one of the milestone
//                                thresholds (10, 25, 50, 100, 250, 500,
//                                1000, 2500, 5000). Fires once per
//                                (survey, milestone).
//
// Idempotency: each event's idempotency_entity_id is derived so the email
// fires at most once per survey per condition.
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

cron_heartbeat_start('survey_activity');

// Tunable thresholds (override per call via query string for ad-hoc use).
$LOW_RATE_THRESHOLD = max(1, (int)($_GET['low_threshold'] ?? 10));
$NO_RESPONSE_DAYS   = max(1, (int)($_GET['no_response_days'] ?? 3));
$LOW_RATE_DAYS      = max(1, (int)($_GET['low_rate_days']    ?? 7));

$MILESTONES = [10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000];

$pdo = db();

// Pull every published survey + its current response count and days-live.
// Days-live prefers published_at (Phase 36); falls back to created_at if the
// column doesn't exist (pre-migration installs) or is null.
$rows = [];
try {
    $rows = $pdo->query(
        "SELECT s.id, s.title, s.slug, s.owner_id,
                COALESCE(s.published_at, s.created_at) AS effective_published_at,
                DATEDIFF(NOW(), COALESCE(s.published_at, s.created_at)) AS days_live,
                (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.id) AS response_count,
                u.email, u.name
         FROM surveys s
         JOIN users u ON u.id = s.owner_id
         WHERE s.is_published = 1
         ORDER BY s.id ASC"
    )->fetchAll();
} catch (Throwable $e) {
    // published_at column missing; retry with created_at only.
    $rows = $pdo->query(
        "SELECT s.id, s.title, s.slug, s.owner_id,
                s.created_at AS effective_published_at,
                DATEDIFF(NOW(), s.created_at) AS days_live,
                (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.id) AS response_count,
                u.email, u.name
         FROM surveys s
         JOIN users u ON u.id = s.owner_id
         WHERE s.is_published = 1
         ORDER BY s.id ASC"
    )->fetchAll();
}

$considered = count($rows);
$fired      = ['no_responses' => 0, 'low_rate' => 0, 'milestone' => 0];
$skipped    = 0;

foreach ($rows as $s) {
    $sid             = (int)$s['id'];
    $owner_id        = (int)$s['owner_id'];
    $name            = (string)$s['name'];
    $first           = trim(explode(' ', $name)[0] ?: 'there');
    $survey_name     = (string)$s['title'];
    $public_link     = 'https://relichecksurvey.com/s/' . (string)$s['slug'];
    $days_live       = (int)$s['days_live'];
    $response_count  = (int)$s['response_count'];

    $base_payload = [
        'first_name'         => $first,
        'survey_name'        => $survey_name,
        'survey_id'          => (string)$sid,
        'public_survey_link' => $public_link,
        'response_count'     => $response_count,
        'days_live'          => $days_live,
    ];

    // -- no_responses: 0 responses + days_live >= NO_RESPONSE_DAYS, fires once.
    if ($response_count === 0 && $days_live >= $NO_RESPONSE_DAYS) {
        $r = relicheck_email_dispatch('survey.no_responses', [
            'user_id'    => $owner_id,
            'account_id' => $owner_id,
            'idempotency_entity_id' => 'no-responses:' . $sid,
            'payload'    => $base_payload,
        ]);
        if (!empty($r['queued'])) $fired['no_responses']++;
        // Don't also fire low_rate or milestones for a 0-response survey.
        continue;
    }

    // -- low_response_rate: days_live >= LOW_RATE_DAYS AND response_count <
    //    LOW_RATE_THRESHOLD. Fires once per survey.
    if ($response_count > 0
        && $response_count < $LOW_RATE_THRESHOLD
        && $days_live >= $LOW_RATE_DAYS) {
        $r = relicheck_email_dispatch('survey.low_response_rate', [
            'user_id'    => $owner_id,
            'account_id' => $owner_id,
            'idempotency_entity_id' => 'low-rate:' . $sid,
            'payload'    => $base_payload,
        ]);
        if (!empty($r['queued'])) $fired['low_rate']++;
    }

    // -- milestone_reached: fire for the highest milestone the survey has
    //    crossed but not been notified of yet. Each milestone has its own
    //    idempotency key so a survey that grows from 10 -> 5000 gets every
    //    milestone email exactly once.
    foreach ($MILESTONES as $m) {
        if ($response_count >= $m) {
            $r = relicheck_email_dispatch('survey.milestone_reached', [
                'user_id'    => $owner_id,
                'account_id' => $owner_id,
                'idempotency_entity_id' => 'milestone:' . $sid . ':' . $m,
                'payload'    => array_merge($base_payload, [
                    'milestone_label' => number_format($m),
                ]),
            ]);
            if (!empty($r['queued'])) $fired['milestone']++;
        }
    }
}

$_cronSummary = [
    'ok'         => true,
    'considered' => $considered,
    'fired'      => $fired,
    'skipped'    => $skipped,
    'config'     => [
        'low_rate_threshold'  => $LOW_RATE_THRESHOLD,
        'no_response_days'    => $NO_RESPONSE_DAYS,
        'low_rate_days'       => $LOW_RATE_DAYS,
        'milestones'          => $MILESTONES,
    ],
    'message'    => "Considered $considered published surveys. Fired: no_responses={$fired['no_responses']}, low_rate={$fired['low_rate']}, milestones={$fired['milestone']}.",
];
cron_heartbeat_done('survey_activity', $_cronSummary);
json_out($_cronSummary);
