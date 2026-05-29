<?php
// POST /api/sdsi/administration-save.php
// Body: {
//   survey_id: int,
//   component: string,
//   context:   { ...reviewer-declared fields... },
//   flags:     [ settled flags (decision + severity) ],
//   result:    { score, sdsiPoints, band, launchReady, blockerCount }
// }
//
// Persists the human-SETTLED administration review for a (survey, component)
// pair (upsert). The deterministic factory lens on the client produces `result`;
// this is the owner's own instrument, so we store the client-computed numbers
// as the saved state of record. Dismissed flags are preserved in the flags
// array at 0 points (the engine drops them from the score); accepted and
// severity_overridden flags count. Returns: { ok, saved_at }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/_administration_prompts.php';

require_method('POST');
check_origin();
$user = require_auth();

$body      = read_json_body();
$surveyId  = isset($body['survey_id']) ? (int)$body['survey_id'] : 0;
$component = clean_string((string)($body['component'] ?? ''), 32);
if ($surveyId <= 0) fail('bad_survey', 'A survey_id is required.');

$spec = administration_component_spec($component);
if (!$spec) fail('bad_component', 'Unknown administration component.');

// ── Ownership ──
$pdo  = db();
$stmt = $pdo->prepare('SELECT owner_id FROM surveys WHERE id = :id');
$stmt->execute([':id' => $surveyId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row)                                       fail('not_found', 'Survey not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this survey.', 403);

// ── Normalise context against the spec's fields ──
$ctxIn   = is_array($body['context'] ?? null) ? $body['context'] : [];
$context = [];
foreach ($spec['contextFields'] as $cf) {
    $key = $cf['key'];
    if ($cf['type'] === 'list') {
        $vals = [];
        $raw  = $ctxIn[$key] ?? [];
        if (is_string($raw)) $raw = preg_split('/[\r\n,]+/', $raw);
        if (is_array($raw)) {
            foreach ($raw as $v) {
                $v = clean_string((string)$v, 120);
                if ($v !== '') $vals[] = $v;
                if (count($vals) >= 24) break;
            }
        }
        $context[$key] = $vals;
    } elseif ($cf['type'] === 'number') {
        // item_count / denominator_reviewed / page_count: keep numeric (warning denominators).
        $context[$key] = isset($ctxIn[$key]) && $ctxIn[$key] !== '' ? max(0, (int)$ctxIn[$key]) : '';
    } else {
        $context[$key] = clean_string((string)($ctxIn[$key] ?? ''), 2000);
    }
}

// ── Pass settled flags through as-is (owner's own data); cap size. The five
//    factory lenses define no mitigations, but keep the column for spine parity. ──
$flags       = is_array($body['flags'] ?? null) ? array_slice($body['flags'], 0, 200) : [];
$mitigations = is_array($body['mitigations'] ?? null) ? array_slice($body['mitigations'], 0, 100) : [];

// ── Computed result from the client engine ──
$resIn        = is_array($body['result'] ?? null) ? $body['result'] : [];
$score        = max(0, min(100, (int)($resIn['score'] ?? 100)));
$sdsiPoints   = max(0.0, min((float)$spec['weight'], (float)($resIn['sdsiPoints'] ?? $spec['weight'])));
$band         = clean_string((string)($resIn['band'] ?? ''), 24);
$launchReady  = !empty($resIn['launchReady']) ? 1 : 0;
$blockerCount = max(0, (int)($resIn['blockerCount'] ?? 0));

$pdo->prepare(
    'INSERT INTO sdsi_administration_reviews
        (survey_id, component, owner_id, context, flags, mitigations, score, sdsi_points, band, launch_ready, blocker_count)
     VALUES
        (:sid, :comp, :oid, :ctx, :flags, :mits, :score, :pts, :band, :ready, :bc)
     ON DUPLICATE KEY UPDATE
        context      = VALUES(context),
        flags        = VALUES(flags),
        mitigations  = VALUES(mitigations),
        score        = VALUES(score),
        sdsi_points  = VALUES(sdsi_points),
        band         = VALUES(band),
        launch_ready = VALUES(launch_ready),
        blocker_count= VALUES(blocker_count)'
)->execute([
    ':sid'   => $surveyId,
    ':comp'  => $component,
    ':oid'   => (int)$user['id'],
    ':ctx'   => json_encode($context, JSON_UNESCAPED_UNICODE),
    ':flags' => json_encode($flags, JSON_UNESCAPED_UNICODE),
    ':mits'  => json_encode($mitigations, JSON_UNESCAPED_UNICODE),
    ':score' => $score,
    ':pts'   => $sdsiPoints,
    ':band'  => $band,
    ':ready' => $launchReady,
    ':bc'    => $blockerCount,
]);

json_out(['ok' => true, 'saved_at' => time()]);
