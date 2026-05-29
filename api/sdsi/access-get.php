<?php
// GET /api/sdsi/access-get.php?survey_id=N
// Returns the saved (settled) Access review for a survey, if any.
// Returns: { ok, exists, review? } where review = { population, flags,
//           mitigations, score, sdsiPoints, band, launchReady, blockerCount,
//           updatedAt }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$surveyId = isset($_GET['survey_id']) ? (int)$_GET['survey_id'] : 0;
if ($surveyId <= 0) fail('bad_survey', 'A survey_id is required.');

$pdo  = db();
$stmt = $pdo->prepare('SELECT owner_id FROM surveys WHERE id = :id');
$stmt->execute([':id' => $surveyId]);
$srow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$srow)                                       fail('not_found', 'Survey not found.', 404);
if ((int)$srow['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

$stmt = $pdo->prepare(
    'SELECT population, flags, mitigations, score, sdsi_points, band, launch_ready, blocker_count, updated_at
       FROM sdsi_access_reviews WHERE survey_id = :sid'
);
$stmt->execute([':sid' => $surveyId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    json_out(['ok' => true, 'exists' => false]);
}

json_out([
    'ok'     => true,
    'exists' => true,
    'review' => [
        'population'   => json_decode((string)$row['population'], true) ?: new stdClass(),
        'flags'        => json_decode((string)$row['flags'], true) ?: [],
        'mitigations'  => json_decode((string)$row['mitigations'], true) ?: [],
        'score'        => (int)$row['score'],
        'sdsiPoints'   => (float)$row['sdsi_points'],
        'band'         => (string)$row['band'],
        'launchReady'  => (bool)$row['launch_ready'],
        'blockerCount' => (int)$row['blocker_count'],
        'updatedAt'    => (string)$row['updated_at'],
    ],
]);
