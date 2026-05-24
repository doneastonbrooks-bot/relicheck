<?php
// GET/POST /api/admin/cron/send_reminders.php?key=<email_cron_key>
//
// Daily sweep that fires reminder emails to invitees who haven't responded
// yet, respecting the per-survey schedule (days_until_first, days_between,
// max_reminders). Uses Phase 31's email pipeline (template + log + queue);
// the actual SMTP send happens in queue_run.php.
//
// Auth: same email_cron_key as the email queue worker. Schedule once a day.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_invitations.php';
require_once __DIR__ . '/../../_email_renderer.php';
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

cron_heartbeat_start('send_reminders');

$pdo = db();

$tpl = relicheck_email_load_template('customer.distribution.survey_reminder');
if (!$tpl) {
    cron_heartbeat_done('send_reminders', ['ok' => false, 'reason' => 'reminder_template_missing'], 'reminder_template_missing');
    json_out(['ok' => false, 'reason' => 'reminder_template_missing']);
}

$siteUrl = rtrim((string)($cfg['site_url'] ?? 'https://relichecksurvey.com'), '/');

$due = invitations_due_for_reminder(500);

$considered = count($due);
$queued     = 0;
$skipped    = 0;
$errors     = 0;
$errorSamples = [];

foreach ($due as $row) {
    try {
        $invId   = (int)$row['id'];
        $sid     = (int)$row['survey_id'];
        $cid     = (int)$row['contact_id'];
        $token   = (string)$row['invitation_token'];
        $sentAt  = (string)$row['sent_at'];

        // Resolve sender display name from survey owner.
        $owner = invitations_survey_owner($sid);
        $senderName = ($owner && !empty($owner['name'])) ? trim((string)$owner['name']) : 'Your team';

        $link      = invitations_link_for_token($token);
        $unsubLink = invitations_unsubscribe_link($token);
        $payload = [
            'first_name'       => relicheck_first_name((string)($row['contact_name'] ?? '')) ?: 'there',
            'sender_name'      => $senderName,
            'survey_name'      => (string)$row['survey_title'],
            'invitation_link'  => $link,
            'unsubscribe_link' => $unsubLink,
            'site_url'         => $siteUrl,
        ];

        $rendered  = relicheck_email_render($tpl, $payload);
        $reminderN = ((int)$row['reminder_count']) + 1;
        $idem      = 'rem:' . $invId . ':' . $reminderN;

        $recipient = [
            'user_id'    => null,
            'email'      => (string)$row['contact_email'],
            'name'       => (string)($row['contact_name'] ?? ''),
            'role'       => 'invitee',
            'account_id' => null,
        ];

        $logId = relicheck_email_insert_log($tpl, $recipient, $rendered, $idem, 'distribution.reminder', [
            'idempotency_entity_id' => 'rem:' . $invId . ':' . $reminderN,
        ]);
        relicheck_email_enqueue($logId);

        $pdo->prepare(
            'UPDATE survey_invitations
                SET reminder_count = reminder_count + 1,
                    last_reminder_at = NOW()
              WHERE id = :id'
        )->execute([':id' => $invId]);

        $queued++;
    } catch (Throwable $e) {
        $errors++;
        if (count($errorSamples) < 3) {
            $errorSamples[] = [
                'invitation_id' => $invId ?? null,
                'message'       => $e->getMessage(),
            ];
        }
    }
}

$_cronSummary = [
    'ok'             => true,
    'considered'     => $considered,
    'queued'         => $queued,
    'skipped'        => $skipped,
    'errors'         => $errors,
    'error_samples'  => $errorSamples,
];
cron_heartbeat_done('send_reminders', $_cronSummary, $errors > 0 ? ($errors . ' reminder(s) failed') : null);
json_out($_cronSummary);
