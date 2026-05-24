<?php
// POST /api/invitations/send.php
// Body: { survey_id, contact_ids?: int[] }
//
// Sends a survey invitation to the listed contacts (or to every active
// contact on the survey when contact_ids is omitted). Skips contacts who
// already have a non-failed invitation row, so re-clicking Send doesn't
// double-fire. Each invitation gets a unique tracking token used in the
// public link.

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

$survey = invitations_require_survey_owned_by($sid, (int)$user['id']);

$ids = is_array($body['contact_ids'] ?? null) ? array_map('intval', $body['contact_ids']) : [];
$ids = array_values(array_filter(array_unique($ids), fn($n) => $n > 0));

$pdo = db();

// Resolve target contacts, scoped to this survey.
if (!empty($ids)) {
    $placeholders = implode(',', array_map('intval', $ids));
    $sql = "SELECT id, email, name, status FROM survey_contacts
             WHERE survey_id = " . (int)$sid . "
               AND id IN ($placeholders)
               AND status = 'active'";
    $contacts = $pdo->query($sql)->fetchAll();
} else {
    $stmt = $pdo->prepare(
        "SELECT id, email, name, status FROM survey_contacts
          WHERE survey_id = :sid AND status = 'active'
          ORDER BY id ASC"
    );
    $stmt->execute([':sid' => $sid]);
    $contacts = $stmt->fetchAll();
}

if (!$contacts) {
    json_out(['ok' => true, 'queued' => 0, 'skipped' => 0, 'note' => 'no_active_contacts']);
}

// Skip contacts that already have a non-failed invitation for this survey.
// Re-sending is a future feature; today, "Send" means send to NEW recipients.
$existing = [];
$ph = implode(',', array_map(fn($c) => (int)$c['id'], $contacts));
if ($ph) {
    $sql = "SELECT contact_id FROM survey_invitations
             WHERE survey_id = " . (int)$sid . "
               AND contact_id IN ($ph)
               AND status NOT IN ('failed','bounced')";
    foreach ($pdo->query($sql)->fetchAll() as $r) {
        $existing[(int)$r['contact_id']] = true;
    }
}

$tpl = relicheck_email_load_template('customer.distribution.survey_invitation');
if (!$tpl) {
    fail('template_missing', 'Invitation template is not seeded yet.', 500);
}

$owner = invitations_survey_owner($sid);
$senderName = $owner ? trim((string)($owner['name'] ?? '')) : '';
if ($senderName === '') $senderName = 'Your team';

$queued  = 0;
$skipped = 0;

foreach ($contacts as $c) {
    $cid = (int)$c['id'];
    if (isset($existing[$cid])) { $skipped++; continue; }

    $token = invitations_generate_token();

    // Insert the invitation row first so we have an id to link the email log to.
    $ins = $pdo->prepare(
        'INSERT INTO survey_invitations (survey_id, contact_id, invitation_token, status)
         VALUES (:sid, :cid, :tok, "queued")'
    );
    $ins->execute([':sid' => $sid, ':cid' => $cid, ':tok' => $token]);
    $invId = (int)$pdo->lastInsertId();

    $link        = invitations_link_for_token($token);
    $unsubLink   = invitations_unsubscribe_link($token);
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
        $idem = 'inv:' . $invId; // one invitation row -> one send

        $recipient = [
            'user_id'     => null,
            'email'       => (string)$c['email'],
            'name'        => $c['name'] !== null ? (string)$c['name'] : '',
            'role'        => 'invitee',
            'account_id'  => null,
        ];

        $logId = relicheck_email_insert_log($tpl, $recipient, $rendered, $idem, 'distribution.invitation', [
            'idempotency_entity_id' => 'inv:' . $invId,
        ]);
        relicheck_email_enqueue($logId);
        invitations_mark_sent($invId, $logId);
        $queued++;
    } catch (Throwable $e) {
        // Mark this single invitation failed; don't kill the batch.
        $pdo->prepare('UPDATE survey_invitations SET status = "failed" WHERE id = :id')
            ->execute([':id' => $invId]);
        $skipped++;
    }
}

json_out([
    'ok'      => true,
    'queued'  => $queued,
    'skipped' => $skipped,
    'total'   => count($contacts),
]);
