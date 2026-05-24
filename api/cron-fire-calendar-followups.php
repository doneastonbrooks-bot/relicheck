<?php
// GET/POST /api/cron-fire-calendar-followups.php?key=<email_cron_key>
//
// Phase 128 hourly cron. Drains calendar_followups rows where
// status = "pending" AND fire_at <= NOW(). For each due row:
//   1. Walk the attendees_json list.
//   2. Upsert each attendee into survey_contacts (so the invitation has a
//      stable contact_id and tracking token).
//   3. Insert one survey_invitations row per attendee, tagged with the
//      wave_label "Post-<event title>" and a NULL schedule_id.
//   4. Render and enqueue the customer.distribution.survey_invitation
//      email via the Phase 31 dispatcher.
//   5. Stamp the followup row: status = "sent", fired_at = NOW(),
//      fired_count = N.
//
// Configure cron-job.org to hit this URL once per hour. Protected by the
// email_cron_key from _config.php so the URL is not openly callable.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_invitations.php';
require_once __DIR__ . '/_email_renderer.php';
require_once __DIR__ . '/_email_dispatcher.php';
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

cron_heartbeat_start('fire_calendar_followups');

$pdo = db();

// Pull a small batch of due followups so a single cron tick stays bounded.
$dueStmt = $pdo->query(
    'SELECT id, survey_id, user_id, event_title, event_start_at, event_end_at,
            event_location, fire_at, delay_minutes, attendees_json, wave_label
       FROM calendar_followups
      WHERE status = "pending"
        AND fire_at <= NOW()
      ORDER BY fire_at ASC
      LIMIT 25'
);
$due = $dueStmt->fetchAll();

$result = [
    'ok'           => true,
    'fired'        => 0,
    'invitations'  => 0,
    'skipped'      => 0,
    'failed'       => 0,
    'events'       => [],
];

if (empty($due)) {
    json_out($result);
}

$tpl = relicheck_email_load_template('customer.distribution.survey_invitation');
if (!$tpl) {
    fail('template_missing', 'Invitation template is not seeded yet.', 500);
}

foreach ($due as $fu) {
    $fuId      = (int)$fu['id'];
    $surveyId  = (int)$fu['survey_id'];
    $userId    = (int)$fu['user_id'];
    $waveLabel = (string)$fu['wave_label'];
    if (strlen($waveLabel) > 120) $waveLabel = substr($waveLabel, 0, 120);

    // Survey + owner.
    $sStmt = $pdo->prepare('SELECT id, title FROM surveys WHERE id = :id LIMIT 1');
    $sStmt->execute([':id' => $surveyId]);
    $survey = $sStmt->fetch();
    if (!$survey) {
        // Survey deleted out from under the followup; mark cancelled.
        $pdo->prepare('UPDATE calendar_followups SET status = "cancelled" WHERE id = :id')
            ->execute([':id' => $fuId]);
        continue;
    }

    $ownerStmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
    $ownerStmt->execute([':id' => $userId]);
    $owner = $ownerStmt->fetch();
    $senderName = $owner ? trim((string)($owner['name'] ?? '')) : '';
    if ($senderName === '') $senderName = 'Your team';

    $attendees = [];
    $decoded = json_decode((string)$fu['attendees_json'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            $email = is_array($row) ? (string)($row['email'] ?? '') : (string)$row;
            $name  = is_array($row) ? (string)($row['name']  ?? '') : '';
            $clean = invitations_clean_email($email);
            if ($clean === null) continue;
            $attendees[] = ['email' => $clean, 'name' => $name];
        }
    }

    $queued = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($attendees as $att) {
        // Upsert contact.
        $cStmt = $pdo->prepare(
            'SELECT id, status FROM survey_contacts
              WHERE survey_id = :sid AND email = :em LIMIT 1'
        );
        $cStmt->execute([':sid' => $surveyId, ':em' => $att['email']]);
        $existing = $cStmt->fetch();

        if ($existing) {
            if ($existing['status'] === 'unsubscribed') {
                $skipped++;
                continue;
            }
            $contactId = (int)$existing['id'];
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO survey_contacts (survey_id, email, name, status, added_by)
                 VALUES (:sid, :em, :nm, "active", :uid)'
            );
            try {
                $ins->execute([
                    ':sid' => $surveyId,
                    ':em'  => $att['email'],
                    ':nm'  => $att['name'] !== '' ? $att['name'] : null,
                    ':uid' => $userId,
                ]);
                $contactId = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                $failed++;
                continue;
            }
        }

        $token = invitations_generate_token();
        $invIns = $pdo->prepare(
            'INSERT INTO survey_invitations
                (survey_id, contact_id, schedule_id, wave_label, invitation_token, status)
             VALUES (:sid, :cid, NULL, :wave, :tok, "queued")'
        );
        $invIns->execute([
            ':sid'  => $surveyId,
            ':cid'  => $contactId,
            ':wave' => $waveLabel,
            ':tok'  => $token,
        ]);
        $invId = (int)$pdo->lastInsertId();

        $link      = invitations_link_for_token($token);
        $unsubLink = invitations_unsubscribe_link($token);
        $link .= (strpos($link, '?') === false ? '?' : '&')
              . 'channel=' . rawurlencode($waveLabel);

        $payload = [
            'first_name'       => relicheck_first_name((string)$att['name']) ?: 'there',
            'sender_name'      => $senderName,
            'survey_name'      => (string)$survey['title'] . ' (' . (string)$fu['event_title'] . ' follow-up)',
            'invitation_link'  => $link,
            'unsubscribe_link' => $unsubLink,
            'site_url'         => rtrim((string)(relicheck_config()['site_url'] ?? 'https://relichecksurvey.com'), '/'),
        ];

        try {
            $rendered = relicheck_email_render($tpl, $payload);
            $idem = 'cal-fu:' . $fuId . ':' . $invId;

            $recipient = [
                'user_id'    => null,
                'email'      => $att['email'],
                'name'       => $att['name'],
                'role'       => 'invitee',
                'account_id' => null,
            ];

            $logId = relicheck_email_insert_log($tpl, $recipient, $rendered, $idem, 'distribution.invitation', [
                'idempotency_entity_id' => $idem,
            ]);
            relicheck_email_enqueue($logId);
            invitations_mark_sent($invId, $logId);
            $queued++;
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE survey_invitations SET status = "failed" WHERE id = :id')
                ->execute([':id' => $invId]);
            $failed++;
        }
    }

    // Stamp the followup row regardless of per-attendee outcome. If the whole
    // batch failed, mark "failed" so the operator can re-fire manually.
    $finalStatus = $queued > 0 ? 'sent' : 'failed';
    $pdo->prepare(
        'UPDATE calendar_followups
            SET status = :st,
                fired_at = NOW(),
                fired_count = :fc
          WHERE id = :id'
    )->execute([
        ':st' => $finalStatus,
        ':fc' => $queued,
        ':id' => $fuId,
    ]);

    $result['fired']++;
    $result['invitations'] += $queued;
    $result['skipped']     += $skipped;
    $result['failed']      += $failed;
    $result['events'][] = [
        'followup_id' => $fuId,
        'wave_label'  => $waveLabel,
        'queued'      => $queued,
        'skipped'     => $skipped,
        'failed'      => $failed,
        'status'      => $finalStatus,
    ];
}

cron_heartbeat_done('fire_calendar_followups', is_array($result) ? $result : []);
json_out($result);
