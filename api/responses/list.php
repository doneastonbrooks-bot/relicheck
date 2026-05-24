<?php
// GET /api/responses/list.php?survey_id=<id>
// Owner-only. Returns all responses for a survey, ordered by submission time.
// Defensive: works whether or not the Phase 16 arm_id column exists.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$surveyId = (int)($_GET['survey_id'] ?? 0);
if ($surveyId <= 0) fail('bad_id', 'Missing or invalid survey_id.');

$pdo = db();
$stmt = $pdo->prepare('SELECT owner_id FROM surveys WHERE id = :id');
$stmt->execute([':id' => $surveyId]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

// Detect whether the optional arm_id column (Phase 16) exists. If it does
// not, we omit it from the SELECT instead of throwing a fatal SQL error.
$hasArmId = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM responses LIKE 'arm_id'");
    if ($col && $col->fetch()) $hasArmId = true;
} catch (Throwable $e) {
    $hasArmId = false;
}

$sql = $hasArmId
    ? 'SELECT id, submitted_at, answers, arm_id FROM responses WHERE survey_id = :sid ORDER BY submitted_at ASC'
    : 'SELECT id, submitted_at, answers FROM responses WHERE survey_id = :sid ORDER BY submitted_at ASC';

$rstmt = $pdo->prepare($sql);
$rstmt->execute([':sid' => $surveyId]);

$responses = [];
while ($r = $rstmt->fetch()) {
    $answers = json_decode((string)$r['answers'], true);
    if (!is_array($answers)) $answers = [];
    $responses[] = [
        'id'           => (int)$r['id'],
        'submitted_at' => $r['submitted_at'],
        'answers'      => $answers,
        'arm_id'       => $hasArmId ? ($r['arm_id'] ?? null) : null,
    ];
}

json_out([
    'survey_id' => $surveyId,
    'count'     => count($responses),
    'responses' => $responses,
]);
