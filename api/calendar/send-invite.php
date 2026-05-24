<?php
// POST /api/calendar/send-invite.php
// Body: {
//   survey_id, event_title, event_start_at, event_end_at, event_location?,
//   attendees: [{email, name?}, ...],
//   schedule_followup?: bool,
//   delay_minutes?: int (15..1440, default 30)
// }
//
// Phase 128. Two pieces:
//   1. Sends the survey-take card to each attendee right now via the Phase 31
//      email pipeline. Attendees are upserted into survey_contacts so each
//      invitation has a proper contact_id and tracking token. The wave_label
//      is "Invite-<event title>" so analytics can slice the calendar wave.
//   2. If schedule_followup is true, inserts a calendar_followups row with
//      fire_at = event_end_at + delay_minutes. The hourly cron at
//      /api/cron-fire-calendar-followups.php drains due rows and fires a
//      second wave tagged "Post-<event title>".

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_invitations.php';
require_once __DIR__ . '/../_email_renderer.php';
require_once __DIR__ . '/../_email_dispatcher.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$sid  = (int)($body['survey_id'] ?? 0);
if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);

$eventTitle   = trim((string)($body['event_title']    ?? ''));
$eventStart   = trim((string)($body['event_start_at'] ?? ''));
$eventEnd     = trim((string)($body['event_end_at']   ?? ''));
$eventLoc     = trim((string)($body['event_location'] ?? ''));
$attendeesIn  = is_array($body['attendees'] ?? null) ? $body['attendees'] : [];
$doFollowup   = !empty($body['schedule_followup']);
$delayMin     = (int)($body['delay_minutes'] ?? 30);
if ($delayMin < 15)   $delayMin = 15;
if ($delayMin > 1440) $delayMin = 1440;

if ($eventTitle === '') fail('bad_input', 'Event title is required.', 400);
if (mb_strlen($eventTitle) > 200) $eventTitle = mb_substr($eventTitle, 0, 200);
if (mb_strlen($eventLoc)   > 200) $eventLoc   = mb_substr($eventLoc, 0, 200);

// Validate datetimes (accept "YYYY-MM-DDTHH:MM" or "YYYY-MM-DD HH:MM[:SS]").
function _cal_parse_dt(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    $raw = str_replace('T', ' ', $raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $raw) !== 1) return null;
    if (strlen($raw) === 16) $raw .= ':00';
    return $raw;
}
$startSql = _cal_parse_dt($eventStart);
$endSql   = _cal_parse_dt($eventEnd);
if (!$startSql || !$endSql) fail('bad_input', 'Start and end times are required.', 400);
if (strtotime($endSql) <= strtotime($startSql)) {
    fail('bad_input', 'End time must be after start time.', 400);
}

// Normalize attendees: each item {email, name?}. Accept either an object array
// or a flat array of strings (emails). De-dupe by lower-cased email.
$attendees = [];
$seen = [];
foreach ($attendeesIn as $row) {
    $email = '';
    $name  = '';
    if (is_string($row)) {
        $email = (string)$row;
    } elseif (is_array($row)) {
        $email = (string)($row['email'] ?? '');
        $name  = (string)($row['name']  ?? '');
    }
    $clean = invitations_clean_email($email);
    if ($clean === null) continue;
    if (isset($seen[$clean])) continue;
    $seen[$clean] = true;
    if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);
    $attendees[] = ['email' => $clean, 'name' => $name];
}
if (empty($attendees)) {
    fail('bad_input', 'Provide at least one valid attendee email.', 400);
}

$survey = invitations_require_survey_owned_by($sid, (int)$user['id']);
$owner  = invitations_survey_owner($sid);
$senderName = $owner ? trim((string)($owner['name'] ?? '')) : '';
if ($senderName === '') $senderName = 'Your team';

$tpl = relicheck_email_load_template('customer.distribution.survey_invitation');
if (!$tpl) {
    fail('template_missing', 'Invitation template is not seeded yet.', 500);
}

// Wave label for the immediate send.
$waveImmediate = 'Invite-' . $eventTitle;
if (mb_strlen($waveImmediate) > 120) $waveImmediate = mb_substr($waveImmediate, 0, 120);

$pdo = db();
$queued  = 0;
$skipped = 0;
$failed  = 0;

foreach ($attendees as $att) {
    // Upsert into survey_contacts so the invitation has a stable contact_id.
    $cStmt = $pdo->prepare(
        'SELECT id, status FROM survey_contacts
          WHERE survey_id = :sid AND email = :em LIMIT 1'
    );
    $cStmt->execute([':sid' => $sid, ':em' => $att['email']]);
    $existing = $cStmt->fetch();

    if ($existing) {
        $contactId = (int)$existing['id'];
        if ($existing['status'] === 'unsubscribed') {
            // Honor the unsubscribe; do not re-send.
            $skipped++;
            continue;
        }
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO survey_contacts (survey_id, email, name, status, added_by)
             VALUES (:sid, :em, :nm, "active", :uid)'
        );
        try {
            $ins->execute([
                ':sid' => $sid,
                ':em'  => $att['email'],
                ':nm'  => $att['name'] !== '' ? $att['name'] : null,
                ':uid' => (int)$user['id'],
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
        ':sid'  => $sid,
        ':cid'  => $contactId,
        ':wave' => $waveImmediate,
        ':tok'  => $token,
    ]);
    $invId = (int)$pdo->lastInsertId();

    $link      = invitations_link_for_token($token);
    $unsubLink = invitations_unsubscribe_link($token);
    // Phase 41 channel tag travels as a query param.
    $link .= (strpos($link, '?') === false ? '?' : '&')
          . 'channel=' . rawurlencode($waveImmediate);

    $contactName = $att['name'] !== '' ? $att['name'] : '';

    $payload = [
        'first_name'       => relicheck_first_name($contactName) ?: 'there',
        'sender_name'      => $senderName,
        'survey_name'      => (string)$survey['title'] . ' (' . $eventTitle . ')',
        'invitation_link'  => $link,
        'unsubscribe_link' => $unsubLink,
        'site_url'         => rtrim((string)(relicheck_config()['site_url'] ?? 'https://relichecksurvey.com'), '/'),
    ];

    try {
        $rendered = relicheck_email_render($tpl, $payload);
        $idem = 'cal:' . $invId;

        $recipient = [
            'user_id'    => null,
            'email'      => $att['email'],
            'name'       => $contactName,
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

// Optionally schedule the post-meeting follow-up. Fire_at is computed inline
// in SQL via DATE_ADD on event_end_at so we avoid the PHP/MySQL timezone
// mismatch on IONOS.
$followupId = null;
if ($doFollowup) {
    $wavePost = 'Post-' . $eventTitle;
    if (mb_strlen($wavePost) > 120) $wavePost = mb_substr($wavePost, 0, 120);

    $attendeesJson = json_encode($attendees, JSON_UNESCAPED_UNICODE);

    $fIns = $pdo->prepare(
        'INSERT INTO calendar_followups
            (survey_id, user_id, event_title, event_start_at, event_end_at,
             event_location, fire_at, delay_minutes, attendees_json, wave_label, status)
         VALUES
            (:sid, :uid, :title, :startAt, :endAt,
             :loc, DATE_ADD(:endAt2, INTERVAL :delay MINUTE), :delay2, :att, :wave, "pending")'
    );
    $fIns->execute([
        ':sid'     => $sid,
        ':uid'     => (int)$user['id'],
        ':title'   => $eventTitle,
        ':startAt' => $startSql,
        ':endAt'   => $endSql,
        ':loc'     => $eventLoc !== '' ? $eventLoc : null,
        ':endAt2'  => $endSql,
        ':delay'   => $delayMin,
        ':delay2'  => $delayMin,
        ':att'     => $attendeesJson,
        ':wave'    => $wavePost,
    ]);
    $followupId = (int)$pdo->lastInsertId();
}

json_out([
    'ok'           => true,
    'queued'       => $queued,
    'skipped'      => $skipped,
    'failed'       => $failed,
    'total'        => count($attendees),
    'wave_label'   => $waveImmediate,
    'followup_id'  => $followupId,
    'followup_at'  => $followupId
        ? date('Y-m-d H:i:s', strtotime($endSql) + ($delayMin * 60))
        : null,
]);
