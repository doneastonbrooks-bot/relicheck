<?php
// GET /api/rssi/run-status.php?project_id=N
//
// Phase 3 — authoritative, READ-ONLY RSSI run-status probe for the analysis
// studios' upper-right RSSI control. Returns whether a saved RSSI run exists
// for a project and, if so, its stored score/band/withheld status.
//
// It computes NOTHING: no RSSI score, no reliability, no AI. It only reads the
// authoritative rssi_reviews row (written by /api/dev/rssi-run.php), which is
// keyed by survey_projects.id and owned via survey_projects.user_id. RSSI's
// scoring engine, formula, weights, bands, and report logic are untouched.
//
// Robustness: this is a status probe consumed by a passive UI control. If the
// project is missing or not owned by the caller, we return {exists:false}
// rather than 403/404 — that leaks nothing (no data is returned for projects
// you don't own) and keeps the control's "no run available" state clean even
// when it is handed an id from a different id-space.

declare(strict_types=1);

require_once __DIR__ . '/../dev/_dev_common.php';

require_method('GET');
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($projectId <= 0) { json_out(['exists' => false]); }

// Ownership: the RSSI run belongs to a survey-dev project. Verify the caller
// owns it; on any miss, report no run (do not reveal existence/ownership).
$own = $pdo->prepare('SELECT user_id FROM survey_projects WHERE id = :id');
$own->execute([':id' => $projectId]);
$ownRow = $own->fetch(PDO::FETCH_ASSOC);
if (!$ownRow || (int)$ownRow['user_id'] !== (int)$user['id']) {
    json_out(['exists' => false]);
}

// Read the saved RSSI review (authoritative). No computation.
$stmt = $pdo->prepare('SELECT total, band, withheld, updated_at FROM rssi_reviews WHERE project_id = :id');
$stmt->execute([':id' => $projectId]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) { json_out(['exists' => false]); }

json_out([
    'exists'      => true,
    'score'       => $r['total'] !== null ? (float)$r['total'] : null,
    'band'        => $r['band'] !== null && $r['band'] !== '' ? $r['band'] : null,
    'withheld'    => (bool)$r['withheld'],
    'last_run_at' => $r['updated_at'],
]);
