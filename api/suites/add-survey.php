<?php
// POST /api/suites/add-survey.php
// Body: { suite_id, survey_id }
// Adds a survey to a suite. Idempotent via the join's primary key.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_suites.php';

require_method('POST');
check_origin();
$user = require_auth();
$userId = (int)$user['id'];

$body = read_json_body();
$suiteId  = (int)($body['suite_id']  ?? 0);
$surveyId = (int)($body['survey_id'] ?? 0);
if ($suiteId <= 0 || $surveyId <= 0) fail('bad_id', 'Missing suite_id or survey_id.', 400);

suites_require_owned($suiteId, $userId);

// Confirm survey ownership.
$pdo = db();
$sStmt = $pdo->prepare('SELECT owner_id FROM surveys WHERE id = :id LIMIT 1');
$sStmt->execute([':id' => $surveyId]);
$sRow = $sStmt->fetch();
if (!$sRow) fail('not_found', 'Survey not found.', 404);
if ((int)$sRow['owner_id'] !== $userId) fail('forbidden', 'You can only add your own surveys.', 403);

$pdo->prepare(
    'INSERT IGNORE INTO suite_surveys (suite_id, survey_id) VALUES (:s, :sv)'
)->execute([':s' => $suiteId, ':sv' => $surveyId]);

json_out(['ok' => true]);
