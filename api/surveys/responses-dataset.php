<?php
// GET /api/surveys/responses-dataset.php?survey_id=N
// Owner-only. Returns survey responses transformed into the localStorage
// dataset wrapper format consumed by all ReliCheck analysis apps.
// The transform function lives in _build_dataset.php (shared with _studio_mount.php).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/_build_dataset.php';

require_method('GET');
$user = require_auth();

$surveyId = (int)($_GET['survey_id'] ?? 0);
if ($surveyId <= 0) fail('bad_id', 'Missing or invalid survey_id.');

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT id, title, questions, settings, owner_id FROM surveys WHERE id = :id'
);
$stmt->execute([':id' => $surveyId]);
$survey = $stmt->fetch();
if (!$survey)                                        fail('not_found', 'Survey not found.',           404);
if ((int)$survey['owner_id'] !== (int)$user['id'])  fail('forbidden', 'You do not own this survey.', 403);

$questions = json_decode((string)$survey['questions'], true) ?: [];
$settings  = json_decode((string)($survey['settings'] ?? ''), true) ?: [];

// Detect optional arm_id column
$hasArmId = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM responses LIKE 'arm_id'");
    if ($col && $col->fetch()) $hasArmId = true;
} catch (Throwable $e) {}

$sql = $hasArmId
    ? 'SELECT id, submitted_at, answers, arm_id FROM responses WHERE survey_id = :sid ORDER BY submitted_at ASC'
    : 'SELECT id, submitted_at, answers FROM responses WHERE survey_id = :sid ORDER BY submitted_at ASC';

$rstmt = $pdo->prepare($sql);
$rstmt->execute([':sid' => $surveyId]);

$responses = [];
while ($r = $rstmt->fetch()) {
    $answers = json_decode((string)$r['answers'], true);
    $responses[] = [
        'id'           => (int)$r['id'],
        'submitted_at' => $r['submitted_at'],
        'answers'      => is_array($answers) ? $answers : [],
    ];
}

$dataset = relicheck_survey_build_dataset($survey['title'], $questions, $responses, $settings);

json_out([
    'ok'      => true,
    'savedAt' => time(),
    'studio'  => 'survey',
    'payload' => ['dataset' => $dataset],
]);
