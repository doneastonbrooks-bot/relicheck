<?php
// GET /api/sdsi/validity-readiness.php?survey_id=N
// Assembles the saved (settled) reviews for all seven validity lenses into one
// payload. The client (apps/sdsi/validity-readiness.js → liveEntries) re-runs
// the SAME engines on these saved inputs, so the score is deterministic and
// identical to what each lens page showed — the aggregator never re-scores
// differently. A lens with no saved review contributes clean (no-flag) inputs.
//
// Returns: { ok, surveyId, lenses } where lenses = {
//   <factory_component>: { flags, context },          // 5 factory lenses
//   dignity_framing:     { flags, mitigations, population },
//   access:              { flags, mitigations, population }
// } — only keys that have a saved review are present.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/_validity_prompts.php';

require_method('GET');
$user = require_auth();

$surveyId = isset($_GET['survey_id']) ? (int)$_GET['survey_id'] : 0;
if ($surveyId <= 0) fail('bad_survey', 'A survey_id is required.');

$pdo  = db();
$stmt = $pdo->prepare('SELECT owner_id FROM surveys WHERE id = :id');
$stmt->execute([':id' => $surveyId]);
$srow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$srow)                                      fail('not_found', 'Survey not found.', 404);
if ((int)$srow['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

$lenses = [];

// ── Five factory lenses (component-keyed rows in sdsi_validity_reviews). ──
$vstmt = $pdo->prepare(
    'SELECT context, flags FROM sdsi_validity_reviews WHERE survey_id = :sid AND component = :comp'
);
foreach (validity_components() as $component) {
    $vstmt->execute([':sid' => $surveyId, ':comp' => $component]);
    $row = $vstmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $lenses[$component] = [
            'context' => json_decode((string)$row['context'], true) ?: new stdClass(),
            'flags'   => json_decode((string)$row['flags'], true) ?: [],
        ];
    }
}

// ── Dignity / Framing (one row per survey). ──
$dstmt = $pdo->prepare(
    'SELECT population, flags, mitigations FROM sdsi_dignity_reviews WHERE survey_id = :sid'
);
$dstmt->execute([':sid' => $surveyId]);
$drow = $dstmt->fetch(PDO::FETCH_ASSOC);
if ($drow) {
    $lenses['dignity_framing'] = [
        'population'  => json_decode((string)$drow['population'], true) ?: new stdClass(),
        'flags'       => json_decode((string)$drow['flags'], true) ?: [],
        'mitigations' => json_decode((string)$drow['mitigations'], true) ?: [],
    ];
}

// ── Access (one row per survey). ──
$astmt = $pdo->prepare(
    'SELECT population, flags, mitigations FROM sdsi_access_reviews WHERE survey_id = :sid'
);
$astmt->execute([':sid' => $surveyId]);
$arow = $astmt->fetch(PDO::FETCH_ASSOC);
if ($arow) {
    $lenses['access'] = [
        'population'  => json_decode((string)$arow['population'], true) ?: new stdClass(),
        'flags'       => json_decode((string)$arow['flags'], true) ?: [],
        'mitigations' => json_decode((string)$arow['mitigations'], true) ?: [],
    ];
}

json_out([
    'ok'       => true,
    'surveyId' => $surveyId,
    'lenses'   => (object)$lenses,
]);
