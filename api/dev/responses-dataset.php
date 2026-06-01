<?php
// GET /api/dev/responses-dataset.php?project_id=N
//
// SIRI / survey-dev counterpart to /api/surveys/responses-dataset.php. Returns
// a survey-dev project's collected responses transformed into the standard
// analysis dataset shape { source, variables:[{name,types,values}], rowCount },
// for the Descriptive / Inferential studios (and their Variables & Fit panel).
//
// Authoritative project identity = survey_projects.id (same id the RSSI
// run-status endpoint uses). Owner-only. Computes NO reliability and NO score.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';
require_once __DIR__ . '/_build_dev_dataset.php';

require_method('GET');
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$project   = sds_require_project($pdo, (int)$user['id'], $projectId); // 404/403 on miss

$dataset = relicheck_surveydev_build_dataset($pdo, $projectId, (string)($project['title'] ?? ''));

json_out([
    'ok'      => true,
    'savedAt' => time(),
    'studio'  => 'descriptive', // generic analysis source; engine-agnostic
    'payload' => ['dataset' => $dataset],
]);
