<?php
// GET /api/calendar/list-followups.php?survey_id=<id>
// Returns up to 50 calendar_followups rows for the given survey, scoped to
// the caller. Used by the calendar panel in app.html to render the list of
// pending and recently fired post-meeting follow-ups.

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
$stmt = $pdo->prepare(
    'SELECT id, event_title, event_start_at, event_end_at, event_location,
            fire_at, delay_minutes, wave_label, status, fired_at, fired_count,
            attendees_json, created_at
       FROM calendar_followups
      WHERE survey_id = :sid
      ORDER BY fire_at DESC
      LIMIT 50'
);
$stmt->execute([':sid' => $sid]);
$rows = $stmt->fetchAll();

$out = [];
foreach ($rows as $r) {
    $attendees = [];
    if (!empty($r['attendees_json'])) {
        $decoded = json_decode((string)$r['attendees_json'], true);
        if (is_array($decoded)) $attendees = $decoded;
    }
    $out[] = [
        'id'              => (int)$r['id'],
        'event_title'     => (string)$r['event_title'],
        'event_start_at'  => (string)$r['event_start_at'],
        'event_end_at'    => (string)$r['event_end_at'],
        'event_location'  => $r['event_location'] !== null ? (string)$r['event_location'] : '',
        'fire_at'         => (string)$r['fire_at'],
        'delay_minutes'   => (int)$r['delay_minutes'],
        'wave_label'      => (string)$r['wave_label'],
        'status'          => (string)$r['status'],
        'fired_at'        => $r['fired_at'] !== null ? (string)$r['fired_at'] : null,
        'fired_count'     => (int)$r['fired_count'],
        'attendee_count'  => count($attendees),
        'created_at'      => (string)$r['created_at'],
    ];
}

json_out(['ok' => true, 'followups' => $out]);
