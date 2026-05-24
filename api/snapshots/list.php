<?php
// GET /api/snapshots/list.php?survey_id=<int>&limit=<int>
//
// Phase 103. Returns the most recent N snapshots (default 2, max 12) for a
// survey so the dashboard can compute "vs Previous" trends.
//
// Response: { ok, snapshots: [{ snapshot_at, response_count, ssi_total, alpha, metrics }] }
// Ordered newest first.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_db.php';

require_method('GET');
check_origin();
$user = require_auth();

$surveyId = (int)($_GET['survey_id'] ?? 0);
if ($surveyId <= 0) fail('bad_input', 'Missing survey_id.');
$limit = (int)($_GET['limit'] ?? 2);
if ($limit < 1) $limit = 1;
if ($limit > 12) $limit = 12;

$pdo = db();

// Confirm ownership.
$own = $pdo->prepare('SELECT owner_id FROM surveys WHERE id = :id LIMIT 1');
$own->execute([':id' => $surveyId]);
$row = $own->fetch();
if (!$row) fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'Not your survey.', 403);

// Inline the limit as a sanitized integer because some MySQL setups reject
// placeholders inside LIMIT clauses.
$sql = 'SELECT snapshot_at, response_count, ssi_total, alpha, metrics_json ' .
       'FROM survey_snapshots WHERE survey_id = :s ORDER BY snapshot_at DESC LIMIT ' . $limit;
$stmt = $pdo->prepare($sql);
$stmt->execute([':s' => $surveyId]);

$out = [];
while ($r = $stmt->fetch()) {
    $metrics = [];
    if (!empty($r['metrics_json'])) {
        $decoded = json_decode($r['metrics_json'], true);
        if (is_array($decoded)) $metrics = $decoded;
    }
    $out[] = [
        'snapshot_at'    => $r['snapshot_at'],
        'response_count' => (int)$r['response_count'],
        'ssi_total'      => $r['ssi_total'] !== null ? (int)$r['ssi_total'] : null,
        'alpha'          => $r['alpha']     !== null ? (float)$r['alpha']   : null,
        'metrics'        => $metrics,
    ];
}

json_out(['ok' => true, 'snapshots' => $out]);
