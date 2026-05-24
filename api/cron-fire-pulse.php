<?php
// GET/POST /api/cron-fire-pulse.php?key=<email_cron_key>
//
// Pulse-cadence cron worker (Phase 119). Drains schedules where
// status = "active" AND next_fire_at <= NOW(). For each due schedule:
//   1. Resolve the wave label (substitutes {n} and {date} placeholders).
//   2. For each ACTIVE contact on the schedule's survey, create a new
//      invitation row with the schedule_id + wave_label, then queue an
//      email via the existing Phase 31 dispatcher.
//   3. Advance next_fire_at by the cadence's interval.
//   4. Increment fired_count, set last_fired_at.
//   5. If end_at has passed, flip status to "completed".
//
// Configure cron-job.org to hit this URL once per hour. Protected by the
// email_cron_key from _config.php so the URL is not openly callable.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_invitations.php';
require_once __DIR__ . '/_email_renderer.php';
require_once __DIR__ . '/_email_dispatcher.php';
require_once __DIR__ . '/_channels.php';
require_once __DIR__ . '/_cron.php';

require_method('GET', 'POST');

$cfg = relicheck_config();
$expected = (string)($cfg['email_cron_key'] ?? '');
if ($expected !== '') {
    $given = (string)($_GET['key'] ?? $_POST['key'] ?? '');
    if (!hash_equals($expected, $given)) {
        fail('forbidden', 'Invalid cron key.', 403);
    }
}

cron_heartbeat_start('fire_pulse');

$pdo = db();

// Auto-complete any schedule whose end_at has passed.
$pdo->exec(
    'UPDATE survey_schedules
        SET status = "completed"
      WHERE status = "active"
        AND end_at IS NOT NULL
        AND end_at < NOW()'
);

// Pull a small batch of due schedules so a single cron tick stays bounded.
$dueStmt = $pdo->query(
    'SELECT id, survey_id, name, cadence, interval_days, wave_template,
            next_fire_at, fired_count, end_at
       FROM survey_schedules
      WHERE status = "active"
        AND next_fire_at <= NOW()
      ORDER BY next_fire_at ASC
      LIMIT 25'
);
$due = $dueStmt->fetchAll();

$result = [
    'ok'           => true,
    'fired'        => 0,
    'waves'        => [],
    'invitations'  => 0,
    'skipped'      => 0,
];

if (empty($due)) {
    json_out($result);
}

$tpl = relicheck_email_load_template('customer.distribution.survey_invitation');
if (!$tpl) {
    fail('template_missing', 'Invitation template is not seeded yet.', 500);
}

foreach ($due as $sched) {
    $schedId    = (int)$sched['id'];
    $surveyId   = (int)$sched['survey_id'];
    $cadence    = (string)$sched['cadence'];
    $intervalDays = (int)($sched['interval_days'] ?? 0);
    $waveTpl    = (string)$sched['wave_template'];
    $firedCount = (int)$sched['fired_count'];

    // Survey + owner.
    $sStmt = $pdo->prepare('SELECT id, title, owner_id FROM surveys WHERE id = :id LIMIT 1');
    $sStmt->execute([':id' => $surveyId]);
    $survey = $sStmt->fetch();
    if (!$survey) {
        // Survey was deleted out from under the schedule; mark complete.
        $pdo->prepare('UPDATE survey_schedules SET status = "completed" WHERE id = :id')
            ->execute([':id' => $schedId]);
        continue;
    }

    // Wave label: substitute {n} and {date}.
    $waveN = $firedCount + 1;
    $waveLabel = str_replace(
        ['{n}', '{date}'],
        [(string)$waveN, date('Y-m-d')],
        $waveTpl
    );
    if (strlen($waveLabel) > 120) $waveLabel = substr($waveLabel, 0, 120);

    // Active contacts.
    $cStmt = $pdo->prepare(
        'SELECT id, email, name FROM survey_contacts
          WHERE survey_id = :sid AND status = "active"
          ORDER BY id ASC'
    );
    $cStmt->execute([':sid' => $surveyId]);
    $contacts = $cStmt->fetchAll();

    $ownerStmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
    $ownerStmt->execute([':id' => (int)$survey['owner_id']]);
    $owner = $ownerStmt->fetch();
    $senderName = $owner ? trim((string)($owner['name'] ?? '')) : '';
    if ($senderName === '') $senderName = 'Your team';

    $queued = 0;
    foreach ($contacts as $c) {
        $cid = (int)$c['id'];
        $token = invitations_generate_token();

        $ins = $pdo->prepare(
            'INSERT INTO survey_invitations
                (survey_id, contact_id, schedule_id, wave_label, invitation_token, status)
             VALUES (:sid, :cid, :schedid, :wave, :tok, "queued")'
        );
        $ins->execute([
            ':sid'     => $surveyId,
            ':cid'     => $cid,
            ':schedid' => $schedId,
            ':wave'    => $waveLabel,
            ':tok'     => $token,
        ]);
        $invId = (int)$pdo->lastInsertId();

        $link      = invitations_link_for_token($token);
        $unsubLink = invitations_unsubscribe_link($token);
        // Phase 41 channel tag travels as a query param so the public take
        // page can stamp the response.
        $link .= (strpos($link, '?') === false ? '?' : '&')
              . 'channel=' . rawurlencode($waveLabel);

        $payload = [
            'first_name'       => relicheck_first_name((string)($c['name'] ?? '')) ?: 'there',
            'sender_name'      => $senderName,
            'survey_name'      => (string)$survey['title'],
            'invitation_link'  => $link,
            'unsubscribe_link' => $unsubLink,
            'site_url'         => rtrim((string)(relicheck_config()['site_url'] ?? 'https://relichecksurvey.com'), '/'),
        ];

        try {
            $rendered = relicheck_email_render($tpl, $payload);
            $idem = 'pulse:' . $schedId . ':' . $waveN . ':' . $cid;

            $recipient = [
                'user_id'    => null,
                'email'      => (string)$c['email'],
                'name'       => $c['name'] !== null ? (string)$c['name'] : '',
                'role'       => 'invitee',
                'account_id' => null,
            ];

            $logId = relicheck_email_insert_log($tpl, $recipient, $rendered, $idem, 'distribution.pulse', [
                'idempotency_entity_id' => $idem,
            ]);
            relicheck_email_enqueue($logId);
            invitations_mark_sent($invId, $logId);
            $queued++;
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE survey_invitations SET status = "failed" WHERE id = :id')
                ->execute([':id' => $invId]);
            $result['skipped']++;
        }
    }

    // Phase 121: also fire the wave to every active Slack/Teams channel
    // on this survey. Failures are logged on the channel row but do not
    // stop the schedule advance.
    $chStmt = $pdo->prepare(
        'SELECT id, kind, webhook_url
           FROM survey_channels
          WHERE survey_id = :sid AND status = "active"
          ORDER BY id ASC'
    );
    $chStmt->execute([':sid' => $surveyId]);
    $channels = $chStmt->fetchAll();
    if (!empty($channels)) {
        $cfgC = relicheck_config();
        $baseC = rtrim((string)($cfgC['site_url'] ?? 'https://relichecksurvey.com'), '/');
        // Look up slug + description once.
        $svc = $pdo->prepare('SELECT slug, description FROM surveys WHERE id = :id LIMIT 1');
        $svc->execute([':id' => $surveyId]);
        $svRow = $svc->fetch() ?: [];
        $shareLinkCh = $baseC . '/s/' . rawurlencode((string)($svRow['slug'] ?? ''))
                     . (strpos((string)($svRow['slug'] ?? ''), '?') === false ? '?' : '&')
                     . 'channel=' . rawurlencode($waveLabel);
        $surveyArr = [
            'title'       => (string)$survey['title'],
            'description' => (string)($svRow['description'] ?? ''),
        ];
        foreach ($channels as $chRow) {
            try {
                channels_dispatch_to_channel($pdo, [
                    'id'          => (int)$chRow['id'],
                    'kind'        => (string)$chRow['kind'],
                    'webhook_url' => (string)$chRow['webhook_url'],
                ], $surveyArr, $shareLinkCh, 'Wave: ' . $waveLabel);
            } catch (Throwable $e) { /* outcome is stamped on the row inside dispatch */ }
        }
    }

    // Advance the schedule. Use SQL DATE_ADD inline; PHP/MySQL timezone
    // mismatches are a known issue (feedback_php_mysql_timezone_mismatch).
    $intervalSql = _phase119_interval_sql($cadence, $intervalDays);
    $advance = $pdo->prepare(
        'UPDATE survey_schedules
            SET fired_count   = fired_count + 1,
                last_fired_at = NOW(),
                next_fire_at  = DATE_ADD(NOW(), ' . $intervalSql . '),
                status        = CASE
                  WHEN end_at IS NOT NULL AND DATE_ADD(NOW(), ' . $intervalSql . ') > end_at THEN "completed"
                  ELSE status
                END
          WHERE id = :id'
    );
    $advance->execute([':id' => $schedId]);

    $result['fired']++;
    $result['invitations'] += $queued;
    $result['waves'][] = [
        'schedule_id' => $schedId,
        'wave_label'  => $waveLabel,
        'queued'      => $queued,
    ];
}

cron_heartbeat_done('fire_pulse', is_array($result) ? $result : []);
json_out($result);

// Cadence -> INTERVAL SQL expression. We use inline INTERVAL strings (not
// placeholders) because MySQL does not allow placeholders inside INTERVAL.
function _phase119_interval_sql(string $cadence, int $intervalDays): string {
    switch ($cadence) {
        case 'weekly':    return 'INTERVAL 7 DAY';
        case 'biweekly':  return 'INTERVAL 14 DAY';
        case 'monthly':   return 'INTERVAL 1 MONTH';
        case 'quarterly': return 'INTERVAL 3 MONTH';
        case 'custom':
        default:
            $d = $intervalDays > 0 ? $intervalDays : 30;
            $d = max(1, min(365, $d));
            return 'INTERVAL ' . $d . ' DAY';
    }
}
