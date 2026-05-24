<?php
// POST /api/panels/launch.php
// Body: { panel_id }
//
// Phase 129. Walks every (subject, evaluator) pair on the panel that still
// has status='pending', and for each pair:
//   1. Upsert a survey_contact for the evaluator's email.
//   2. Insert a survey_invitations row, tagged with the panel's wave label
//      "360-S<subject_id>-R<rel_short>" so the Phase 41 channel mechanism
//      stamps responses correctly for the new subject_report endpoint.
//   3. Render and enqueue the survey_invitation template via the Phase 31
//      email dispatcher. Subject's name appears in the survey_name suffix so
//      the recipient sees who they are rating.
//   4. Stamp the evaluator row with invitation_id and status='sent'.
//
// If a panel includes self_assessment and any subject has an email, a
// self-evaluator row is auto-created at launch (one-time, idempotent).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_panels.php';
require_once __DIR__ . '/../_invitations.php';
require_once __DIR__ . '/../_email_renderer.php';
require_once __DIR__ . '/../_email_dispatcher.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$pid  = (int)($body['panel_id'] ?? 0);
if ($pid <= 0) fail('bad_id', 'Missing panel_id.', 400);

$panel = panels_require_owned($pid, (int)$user['id']);
if ($panel['status'] === 'closed') {
    fail('bad_state', 'Panel is closed.', 409);
}

$pdo = db();

// Subjects.
$sStmt = $pdo->prepare('SELECT id, name, email FROM survey_360_subjects WHERE panel_id = :pid');
$sStmt->execute([':pid' => $pid]);
$subjects = $sStmt->fetchAll();
if (!$subjects) fail('bad_state', 'Add at least one subject before launching.', 400);

// If self-assessment is enabled, ensure each subject with an email has a
// self-evaluator row. Idempotent via the UNIQUE (subject_id, email) index.
if ((int)$panel['self_assessment'] === 1) {
    $selfIns = $pdo->prepare(
        'INSERT IGNORE INTO survey_360_evaluators
            (panel_id, subject_id, evaluator_email, evaluator_name, relationship, status)
         VALUES (:pid, :sid, :em, :nm, "self", "pending")'
    );
    foreach ($subjects as $s) {
        if (empty($s['email'])) continue;
        $selfIns->execute([
            ':pid' => $pid,
            ':sid' => (int)$s['id'],
            ':em'  => strtolower((string)$s['email']),
            ':nm'  => (string)$s['name'],
        ]);
    }
}

// Pull pending evaluators.
$eStmt = $pdo->prepare(
    'SELECT ev.id, ev.subject_id, ev.evaluator_email, ev.evaluator_name, ev.relationship
       FROM survey_360_evaluators ev
      WHERE ev.panel_id = :pid AND ev.status = "pending"
      ORDER BY ev.id ASC'
);
$eStmt->execute([':pid' => $pid]);
$evaluators = $eStmt->fetchAll();

if (!$evaluators) {
    json_out([
        'ok'     => true,
        'queued' => 0,
        'note'   => 'no_pending_evaluators',
    ]);
}

// Subject lookup map (for the panel's wave label + survey name suffix).
$subjectsById = [];
foreach ($subjects as $s) $subjectsById[(int)$s['id']] = $s;

$surveyId = (int)$panel['survey_id'];
$ownerStmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
$ownerStmt->execute([':id' => (int)$user['id']]);
$owner = $ownerStmt->fetch();
$senderName = $owner ? trim((string)($owner['name'] ?? '')) : '';
if ($senderName === '') $senderName = 'Your team';

$tpl = relicheck_email_load_template('customer.distribution.survey_invitation');
if (!$tpl) {
    fail('template_missing', 'Invitation template is not seeded yet.', 500);
}

$queued = 0;
$failed = 0;

foreach ($evaluators as $ev) {
    $sub = $subjectsById[(int)$ev['subject_id']] ?? null;
    if (!$sub) { $failed++; continue; }

    $channel = panels_channel_for((int)$ev['subject_id'], (string)$ev['relationship']);

    // Upsert evaluator into survey_contacts.
    $cStmt = $pdo->prepare(
        'SELECT id, status FROM survey_contacts
          WHERE survey_id = :sid AND email = :em LIMIT 1'
    );
    $cStmt->execute([':sid' => $surveyId, ':em' => $ev['evaluator_email']]);
    $existing = $cStmt->fetch();

    if ($existing) {
        if ($existing['status'] === 'unsubscribed') {
            $pdo->prepare('UPDATE survey_360_evaluators SET status = "unsubscribed" WHERE id = :id')
                ->execute([':id' => (int)$ev['id']]);
            $failed++;
            continue;
        }
        $contactId = (int)$existing['id'];
    } else {
        $cIns = $pdo->prepare(
            'INSERT INTO survey_contacts (survey_id, email, name, status, added_by)
             VALUES (:sid, :em, :nm, "active", :uid)'
        );
        try {
            $cIns->execute([
                ':sid' => $surveyId,
                ':em'  => $ev['evaluator_email'],
                ':nm'  => $ev['evaluator_name'] !== null && $ev['evaluator_name'] !== '' ? $ev['evaluator_name'] : null,
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
        ':sid'  => $surveyId,
        ':cid'  => $contactId,
        ':wave' => $channel,
        ':tok'  => $token,
    ]);
    $invId = (int)$pdo->lastInsertId();

    $link      = invitations_link_for_token($token);
    $unsubLink = invitations_unsubscribe_link($token);
    $link .= (strpos($link, '?') === false ? '?' : '&')
          . 'channel=' . rawurlencode($channel);

    $subjectName = (string)$sub['name'];
    $surveyNameWithSubject = (string)$panel['survey_title'];
    if ((string)$ev['relationship'] === 'self') {
        $surveyNameWithSubject .= ' (self-assessment)';
    } else {
        $surveyNameWithSubject .= ' (about ' . $subjectName . ')';
    }

    $payload = [
        'first_name'       => relicheck_first_name((string)($ev['evaluator_name'] ?? '')) ?: 'there',
        'sender_name'      => $senderName,
        'survey_name'      => $surveyNameWithSubject,
        'invitation_link'  => $link,
        'unsubscribe_link' => $unsubLink,
        'site_url'         => rtrim((string)(relicheck_config()['site_url'] ?? 'https://relichecksurvey.com'), '/'),
    ];

    try {
        $rendered = relicheck_email_render($tpl, $payload);
        $idem = 'p360:' . $invId;

        $recipient = [
            'user_id'    => null,
            'email'      => (string)$ev['evaluator_email'],
            'name'       => (string)($ev['evaluator_name'] ?? ''),
            'role'       => 'invitee',
            'account_id' => null,
        ];

        $logId = relicheck_email_insert_log($tpl, $recipient, $rendered, $idem, 'distribution.invitation', [
            'idempotency_entity_id' => $idem,
        ]);
        relicheck_email_enqueue($logId);
        invitations_mark_sent($invId, $logId);

        $pdo->prepare(
            'UPDATE survey_360_evaluators
                SET invitation_id = :iid, status = "sent"
              WHERE id = :id'
        )->execute([':iid' => $invId, ':id' => (int)$ev['id']]);
        $queued++;
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE survey_invitations SET status = "failed" WHERE id = :id')
            ->execute([':id' => $invId]);
        $pdo->prepare('UPDATE survey_360_evaluators SET status = "failed" WHERE id = :id')
            ->execute([':id' => (int)$ev['id']]);
        $failed++;
    }
}

// Flip panel to active on first successful launch.
if ($queued > 0 && $panel['status'] === 'draft') {
    $pdo->prepare(
        'UPDATE survey_360_panels
            SET status = "active",
                launched_at = COALESCE(launched_at, NOW())
          WHERE id = :id'
    )->execute([':id' => $pid]);
}

json_out([
    'ok'     => true,
    'queued' => $queued,
    'failed' => $failed,
    'total'  => count($evaluators),
]);
