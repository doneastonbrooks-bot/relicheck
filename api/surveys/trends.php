<?php
// GET /api/surveys/trends.php
// Returns a 14-element response-count array (oldest -> newest, last 14 days)
// for every survey owned by the caller. Lightweight by design: a single
// GROUP BY query, no joins to questions or settings.
//
// Shape:
//   { "trends": { "<survey_id>": [c0, c1, ..., c13], ... }, "from": "...", "to": "..." }
// where c0 is 13 days ago and c13 is today.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$pdo = db();

// Date range: today minus 13 days, to today (14 buckets total).
// We let MySQL build the date list to avoid PHP/MySQL TZ drift.
$rangeRow = $pdo->query(
    'SELECT DATE(DATE_SUB(NOW(), INTERVAL 13 DAY)) AS d_from,
            DATE(NOW())                            AS d_to'
)->fetch();
$dFrom = (string)$rangeRow['d_from'];
$dTo   = (string)$rangeRow['d_to'];

// Pull every (survey_id, day, count) bucket inside the window for this owner.
$stmt = $pdo->prepare(
    "SELECT r.survey_id AS sid,
            DATE(r.submitted_at) AS d,
            COUNT(*) AS c
       FROM responses r
       JOIN surveys s ON s.id = r.survey_id
      WHERE s.owner_id = :uid
        AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
      GROUP BY r.survey_id, DATE(r.submitted_at)"
);
$stmt->execute([':uid' => (int)$user['id']]);

// Build a 14-zero scaffold per survey, then fill known buckets.
$scaffold = array_fill(0, 14, 0);
$days = [];
for ($i = 0; $i < 14; $i++) {
    $days[date('Y-m-d', strtotime($dFrom . " +{$i} day"))] = $i;
}

$trends = [];
while ($r = $stmt->fetch()) {
    $sid = (int)$r['sid'];
    $idx = $days[$r['d']] ?? null;
    if ($idx === null) continue;
    if (!isset($trends[$sid])) $trends[$sid] = $scaffold;
    $trends[$sid][$idx] = (int)$r['c'];
}

// Surveys with zero responses in the window are simply absent from the map;
// the client treats absence as the all-zero scaffold.

json_out([
    'trends' => $trends,
    'from'   => $dFrom,
    'to'     => $dTo,
]);
