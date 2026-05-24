<?php
// GET /api/invitations/list.php?survey_id=N
//
// PRIVACY: returns aggregate counts ONLY. Never returns per-invitation rows,
// emails, names, or per-recipient timestamps. The owner can see how many
// invitations were sent and how many were completed, but cannot identify
// which contact responded. Server-side, the linkage exists in
// survey_invitations so reminders can skip people who already responded,
// but that data never leaves the server through this endpoint.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_invitations.php';

require_method('GET');
$user = require_auth();

$sid = (int)($_GET['survey_id'] ?? 0);
if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);
invitations_require_survey_owned_by($sid, (int)$user['id']);

$pdo = db();

$counts = ['queued'=>0,'sent'=>0,'opened'=>0,'completed'=>0,'bounced'=>0,'failed'=>0];
$totalContacts = 0;
$totalReminders = 0;

try {
    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS n
           FROM survey_invitations
          WHERE survey_id = :sid
          GROUP BY status"
    );
    $stmt->execute([':sid' => $sid]);
    while ($r = $stmt->fetch()) {
        $st = (string)$r['status'];
        if (isset($counts[$st])) $counts[$st] = (int)$r['n'];
    }

    $contactsStmt = $pdo->prepare(
        "SELECT COUNT(*) AS n FROM survey_contacts WHERE survey_id = :sid"
    );
    $contactsStmt->execute([':sid' => $sid]);
    $totalContacts = (int)$contactsStmt->fetch()['n'];

    $remStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(reminder_count), 0) AS n
           FROM survey_invitations WHERE survey_id = :sid"
    );
    $remStmt->execute([':sid' => $sid]);
    $totalReminders = (int)$remStmt->fetch()['n'];
} catch (Throwable $e) {
    json_out([
        'counts'                => $counts,
        'total_contacts'        => 0,
        'total_reminders_sent'  => 0,
        'note'                  => 'phase38_pending',
    ]);
}

json_out([
    'counts'                => $counts,
    'total_contacts'        => $totalContacts,
    'total_reminders_sent'  => $totalReminders,
]);
