<?php
// POST /api/sdsi/dignity-save.php
// Body: {
//   survey_id:   int
//   population:  { minors, peopleFacing, communities[] }
//   flags:       [ settled flags (decision + severity) ]
//   mitigations: [ settled mitigations (decision) ]
//   result:      { score, sdsiPoints, band, launchReady, blockerCount }
// }
//
// Persists the human-SETTLED dignity review for a survey (upsert by survey_id).
// The deterministic DignityEngine on the client produces `result`; this is the
// owner's own instrument, so we store the client-computed numbers as the saved
// state of record. Returns: { ok, saved_at }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body     = read_json_body();
$surveyId = isset($body['survey_id']) ? (int)$body['survey_id'] : 0;
if ($surveyId <= 0) fail('bad_survey', 'A survey_id is required.');

// ── Ownership ──
$pdo  = db();
$stmt = $pdo->prepare('SELECT owner_id FROM surveys WHERE id = :id');
$stmt->execute([':id' => $surveyId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row)                                       fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

// ── Normalise population ──
$popIn       = is_array($body['population'] ?? null) ? $body['population'] : [];
$communities = [];
if (!empty($popIn['communities']) && is_array($popIn['communities'])) {
    foreach ($popIn['communities'] as $c) {
        $c = clean_string((string)$c, 80);
        if ($c !== '') $communities[] = $c;
        if (count($communities) >= 12) break;
    }
}
$population = [
    'minors'       => !empty($popIn['minors']),
    'peopleFacing' => array_key_exists('peopleFacing', $popIn) ? !empty($popIn['peopleFacing']) : true,
    'communities'  => $communities,
];

// ── Pass settled arrays through as-is (owner's own data); cap sizes ──
$flags       = is_array($body['flags'] ?? null) ? array_slice($body['flags'], 0, 200) : [];
$mitigations = is_array($body['mitigations'] ?? null) ? array_slice($body['mitigations'], 0, 100) : [];

// ── Computed result from the client engine ──
$resIn        = is_array($body['result'] ?? null) ? $body['result'] : [];
$score        = max(0, min(100, (int)($resIn['score'] ?? 100)));
$sdsiPoints   = max(0.0, min(8.0, (float)($resIn['sdsiPoints'] ?? 8)));
$band         = clean_string((string)($resIn['band'] ?? ''), 24);
$launchReady  = !empty($resIn['launchReady']) ? 1 : 0;
$blockerCount = max(0, (int)($resIn['blockerCount'] ?? 0));

$pdo->prepare(
    'INSERT INTO sdsi_dignity_reviews
        (survey_id, owner_id, population, flags, mitigations, score, sdsi_points, band, launch_ready, blocker_count)
     VALUES
        (:sid, :oid, :pop, :flags, :mits, :score, :pts, :band, :ready, :bc)
     ON DUPLICATE KEY UPDATE
        population   = VALUES(population),
        flags        = VALUES(flags),
        mitigations  = VALUES(mitigations),
        score        = VALUES(score),
        sdsi_points  = VALUES(sdsi_points),
        band         = VALUES(band),
        launch_ready = VALUES(launch_ready),
        blocker_count= VALUES(blocker_count)'
)->execute([
    ':sid'   => $surveyId,
    ':oid'   => (int)$user['id'],
    ':pop'   => json_encode($population, JSON_UNESCAPED_UNICODE),
    ':flags' => json_encode($flags, JSON_UNESCAPED_UNICODE),
    ':mits'  => json_encode($mitigations, JSON_UNESCAPED_UNICODE),
    ':score' => $score,
    ':pts'   => $sdsiPoints,
    ':band'  => $band,
    ':ready' => $launchReady,
    ':bc'    => $blockerCount,
]);

json_out(['ok' => true, 'saved_at' => time()]);
