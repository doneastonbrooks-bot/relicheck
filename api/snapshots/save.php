<?php
// POST /api/snapshots/save.php
// Body: {
//   "survey_id":      <int>,
//   "response_count": <int>,
//   "ssi_total":      <int|null>,
//   "alpha":          <float|null>,
//   "metrics":        { strengths, needs_review, action_count, ... }
// }
//
// Phase 103. Stores a Survey Strength snapshot when the most recent one is
// more than 7 days old (or none exists). The dashboard Top Findings card
// reads two snapshots back to compute "vs Previous" deltas. Idempotent
// within the 7-day window so repeated dashboard loads do not pile rows.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$surveyId = (int)($body['survey_id'] ?? 0);
if ($surveyId <= 0) fail('bad_input', 'Missing survey_id.');

$pdo = db();

// Confirm the survey belongs to this user.
$stmt = $pdo->prepare('SELECT id, owner_id FROM surveys WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $surveyId]);
$survey = $stmt->fetch();
if (!$survey) fail('not_found', 'Survey not found.', 404);
if ((int)$survey['owner_id'] !== (int)$user['id']) fail('forbidden', 'Not your survey.', 403);

// Cooldown: skip if a snapshot exists in the last 7 days.
$cd = $pdo->prepare('SELECT id FROM survey_snapshots WHERE survey_id = :s AND snapshot_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY snapshot_at DESC LIMIT 1');
$cd->execute([':s' => $surveyId]);
if ($cd->fetch()) {
    json_out(['ok' => true, 'skipped' => true, 'reason' => 'cooldown']);
    return;
}

$respCount = max(0, (int)($body['response_count'] ?? 0));
$ssi       = isset($body['ssi_total']) && is_numeric($body['ssi_total']) ? (int)$body['ssi_total'] : null;
$alpha     = isset($body['alpha']) && is_numeric($body['alpha']) ? round((float)$body['alpha'], 4) : null;
$metrics   = is_array($body['metrics'] ?? null) ? $body['metrics'] : [];

// Clamp + sanitize metrics. We only persist what the dashboard reads.
$clean = [
    'strengths'    => array_slice(array_values(array_filter(array_map(function ($s) { return is_string($s) ? clean_string($s, 240) : ''; }, $metrics['strengths']    ?? []), 'strlen')), 0, 5),
    'needs_review' => array_slice(array_values(array_filter(array_map(function ($s) { return is_string($s) ? clean_string($s, 240) : ''; }, $metrics['needs_review'] ?? []), 'strlen')), 0, 5),
    'action_count' => max(0, (int)($metrics['action_count'] ?? 0)),
];

$ins = $pdo->prepare(
    'INSERT INTO survey_snapshots (survey_id, response_count, ssi_total, alpha, metrics_json) ' .
    'VALUES (:s, :n, :ssi, :a, :m)'
);
$ins->execute([
    ':s'   => $surveyId,
    ':n'   => $respCount,
    ':ssi' => $ssi,
    ':a'   => $alpha,
    ':m'   => json_encode($clean, JSON_UNESCAPED_UNICODE),
]);

json_out([
    'ok'         => true,
    'snapshot_id'=> (int)$pdo->lastInsertId(),
]);
