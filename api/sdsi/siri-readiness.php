<?php
// GET /api/sdsi/siri-readiness.php?survey_id=N
// Assembles the saved (settled) reviews for ALL THREE readiness domains into one
// SIRI payload. The client (apps/sdsi/siri-readiness.js → liveDomains) hands each
// domain's saved inputs to that domain's aggregator, which re-runs the SAME
// engines client-side — so the SIRI score is deterministic and identical to what
// each domain dashboard showed. SIRI never re-scores: it only sums the three
// domain contribution points. A lens with no saved review contributes clean
// (no-flag) inputs, so each domain still reflects its full point weight.
//
// Returns: { ok, surveyId, domains } where domains = {
//   validity:       { lenses: { <factory×5> {flags,context},
//                               dignity_framing {flags,mitigations,population},
//                               access {flags,mitigations,population} } },
//   reliability:    { lenses: { <factory×5> {flags,context} } },
//   administration: { lenses: { <factory×5> {flags,context} } }
// } — only lens keys that have a saved review are present.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/_validity_prompts.php';
require_once __DIR__ . '/_reliability_prompts.php';
require_once __DIR__ . '/_administration_prompts.php';

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

// Small helper: read the factory-lens component rows from one domain table.
$readComponents = function (PDO $pdo, string $table, array $components, int $surveyId): array {
    $lenses = [];
    $stmt = $pdo->prepare(
        'SELECT context, flags FROM ' . $table . ' WHERE survey_id = :sid AND component = :comp'
    );
    foreach ($components as $component) {
        $stmt->execute([':sid' => $surveyId, ':comp' => $component]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $lenses[$component] = [
                'context' => json_decode((string)$row['context'], true) ?: new stdClass(),
                'flags'   => json_decode((string)$row['flags'], true) ?: [],
            ];
        }
    }
    return $lenses;
};

// ── Validity domain (50): five factory lenses + Dignity + Access. ──
$validityLenses = $readComponents($pdo, 'sdsi_validity_reviews', validity_components(), $surveyId);

$dstmt = $pdo->prepare('SELECT population, flags, mitigations FROM sdsi_dignity_reviews WHERE survey_id = :sid');
$dstmt->execute([':sid' => $surveyId]);
$drow = $dstmt->fetch(PDO::FETCH_ASSOC);
if ($drow) {
    $validityLenses['dignity_framing'] = [
        'population'  => json_decode((string)$drow['population'], true) ?: new stdClass(),
        'flags'       => json_decode((string)$drow['flags'], true) ?: [],
        'mitigations' => json_decode((string)$drow['mitigations'], true) ?: [],
    ];
}

$astmt = $pdo->prepare('SELECT population, flags, mitigations FROM sdsi_access_reviews WHERE survey_id = :sid');
$astmt->execute([':sid' => $surveyId]);
$arow = $astmt->fetch(PDO::FETCH_ASSOC);
if ($arow) {
    $validityLenses['access'] = [
        'population'  => json_decode((string)$arow['population'], true) ?: new stdClass(),
        'flags'       => json_decode((string)$arow['flags'], true) ?: [],
        'mitigations' => json_decode((string)$arow['mitigations'], true) ?: [],
    ];
}

// ── Reliability domain (35): five factory lenses. ──
$reliabilityLenses = $readComponents($pdo, 'sdsi_reliability_reviews', reliability_components(), $surveyId);

// ── Administration domain (15): five factory lenses. ──
$administrationLenses = $readComponents($pdo, 'sdsi_administration_reviews', administration_components(), $surveyId);

json_out([
    'ok'       => true,
    'surveyId' => $surveyId,
    'domains'  => [
        'validity'       => ['lenses' => (object)$validityLenses],
        'reliability'    => ['lenses' => (object)$reliabilityLenses],
        'administration' => ['lenses' => (object)$administrationLenses],
    ],
]);
