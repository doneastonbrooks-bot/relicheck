<?php
// GET /api/sdsi/reliability-readiness.php?survey_id=N
// Assembles the saved (settled) reviews for all five reliability lenses into
// one payload. The client (apps/sdsi/reliability-readiness.js → liveEntries)
// re-runs the SAME factory engines on these saved inputs, so the score is
// deterministic and identical to what each lens page showed — the aggregator
// never re-scores differently. A lens with no saved review contributes clean
// (no-flag) inputs, so the domain total still reflects the full 35 points.
//
// Returns: { ok, surveyId, lenses } where lenses = {
//   <component>: { flags, context }   // all five factory reliability lenses
// } — only keys that have a saved review are present.

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
if (!$srow)                                      fail('not_found', 'Survey not found.', 404);
if ((int)$srow['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

// The five reliability lenses, persisted as component-keyed rows in
// sdsi_reliability_reviews (mirrors sdsi_validity_reviews).
$components = [
    'scale_structure_readiness',
    'item_clarity',
    'response_scale_consistency',
    'redundancy_balance',
    'administration_consistency',
];

$lenses = [];
$rstmt  = $pdo->prepare(
    'SELECT context, flags FROM sdsi_reliability_reviews WHERE survey_id = :sid AND component = :comp'
);
foreach ($components as $component) {
    $rstmt->execute([':sid' => $surveyId, ':comp' => $component]);
    $row = $rstmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $lenses[$component] = [
            'context' => json_decode((string)$row['context'], true) ?: new stdClass(),
            'flags'   => json_decode((string)$row['flags'], true) ?: [],
        ];
    }
}

json_out([
    'ok'       => true,
    'surveyId' => $surveyId,
    'lenses'   => (object)$lenses,
]);
