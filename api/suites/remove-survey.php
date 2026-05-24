<?php
// POST /api/suites/remove-survey.php
// Body: { suite_id, survey_id }
// Removes a survey from a suite. The survey itself stays.

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

db()->prepare('DELETE FROM suite_surveys WHERE suite_id = :s AND survey_id = :sv')
    ->execute([':s' => $suiteId, ':sv' => $surveyId]);

json_out(['ok' => true]);
