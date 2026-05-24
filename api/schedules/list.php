<?php
// GET /api/schedules/list.php?survey_id=<id>
//
// Returns every pulse schedule attached to the survey, with a tally of
// invitations sent under each schedule so the UI can show progress.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
check_origin();
$user = require_auth();

$sid = (int)($_GET['survey_id'] ?? 0);
if ($sid <= 0) fail('bad_id', 'Missing survey_id.', 400);

$pdo = db();
$stmt = $pdo->prepare('SELECT id, owner_id FROM surveys WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $sid]);
$survey = $stmt->fetch();
if (!$survey || (int)$survey['owner_id'] !== (int)$user['id']) {
    fail('not_found', 'Survey not found.', 404);
}

$rows = $pdo->prepare(
    'SELECT id, survey_id, name, cadence, interval_days, wave_template,
            start_at, next_fire_at, last_fired_at, fired_count, end_at, status,
            created_at, updated_at
       FROM survey_schedules
      WHERE survey_id = :sid
      ORDER BY id DESC'
);
$rows->execute([':sid' => $sid]);
$schedules = $rows->fetchAll();

// Per-schedule invitation tally (lifetime sent + completed).
$tallies = [];
if (!empty($schedules)) {
    $ids = implode(',', array_map(fn($s) => (int)$s['id'], $schedules));
    $q = $pdo->query(
        'SELECT schedule_id,
                COUNT(*) AS total,
                SUM(status = "completed") AS completed
           FROM survey_invitations
          WHERE schedule_id IN (' . $ids . ')
          GROUP BY schedule_id'
    );
    foreach ($q->fetchAll() as $r) {
        $tallies[(int)$r['schedule_id']] = [
            'total'     => (int)$r['total'],
            'completed' => (int)$r['completed'],
        ];
    }
}
foreach ($schedules as &$s) {
    $sid2 = (int)$s['id'];
    $t = $tallies[$sid2] ?? ['total' => 0, 'completed' => 0];
    $s['invitations_total']     = $t['total'];
    $s['invitations_completed'] = $t['completed'];
}
unset($s);

json_out([
    'ok'        => true,
    'schedules' => $schedules,
]);
