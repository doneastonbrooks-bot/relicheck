<?php
// POST /api/dev/rssi-run.php
// Body: { project_id, result }   (result = the object from RSSIEngine.score())
//
// Phase 4C — the authenticated RSSI run/persist endpoint. RSSI is the
// post-data ReliCheck Survey Strength Index.
//
// ARCHITECTURE NOTE: the RSSI scoring engine is the single source of truth and
// lives as a pure JS module (apps/rssi/rssi-engine.js), the same client-side
// pattern SIRI uses (siri-readiness.js → siri-save.php). PHP cannot execute
// that module, and porting ~400 lines of statistics to PHP would create a
// second implementation that could silently drift from the tested one. So the
// browser loads the dataset (api/dev/rssi-dataset.php, Phase 4A), runs the
// engine, and POSTs the structured result here. This endpoint:
//   • enforces auth + project ownership,
//   • validates the posted result is a real rssi-v1 engine object,
//   • stamps the AUTHORITATIVE response-data fingerprint (count + newest
//     submission) server-side, so staleness can never be faked by the client,
//   • persists everything to rssi_reviews (separate from SDSI/SIRI),
//   • returns the canonical stored record.
// It computes NO score itself and runs NO AI.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';

require_method('POST');
check_origin();
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$body      = read_json_body();
$projectId = isset($body['project_id']) ? (int)$body['project_id'] : 0;
sds_require_project($pdo, (int)$user['id'], $projectId);

$result = (isset($body['result']) && is_array($body['result'])) ? $body['result'] : null;
if ($result === null) {
    fail('bad_input', 'An RSSI engine result is required.', 400);
}
// Validate it is a genuine rssi-v1 engine object before trusting it.
if (($result['version'] ?? '') !== 'rssi-v1'
    || !array_key_exists('score', $result)
    || !isset($result['fence']) || !is_array($result['fence'])
    || !isset($result['domains']) || !is_array($result['domains'])) {
    fail('bad_result', 'The posted result is not a valid RSSI engine output.', 422);
}

// Pull the persisted fields out of the engine result.
$score    = $result['score'];                         // number | null
$withheld = !empty($result['fence']['withheld']) || $score === null;
$total    = is_numeric($score) ? round((float)$score, 2) : null;
$max      = is_numeric($result['max'] ?? null) ? round((float)$result['max'], 2) : 100.0;
$pct      = is_numeric($result['pct'] ?? null) ? (int)round((float)$result['pct']) : null;
$band     = clean_string((string)($result['band'] ?? ''), 160);
$verdict  = clean_string((string)($result['verdict'] ?? ''), 160);
$reviewJson = json_encode($result, JSON_UNESCAPED_UNICODE);

// AUTHORITATIVE response-data fingerprint (server-side, not from the client).
$fp = $pdo->prepare('SELECT COUNT(*) AS c, MAX(submitted_at) AS last FROM survey_dev_response_sessions WHERE project_id = :id');
$fp->execute([':id' => $projectId]);
$cur = $fp->fetch(PDO::FETCH_ASSOC);
$responseCount   = (int)($cur['c'] ?? 0);
$lastSubmittedAt = $cur['last'] ?? null;

$pdo->prepare(
    'INSERT INTO rssi_reviews
        (project_id, total, max_points, pct, band, verdict, withheld, response_count, last_submitted_at, review)
     VALUES (:pid, :total, :max, :pct, :band, :verdict, :withheld, :rc, :last, :review)
     ON DUPLICATE KEY UPDATE
        total = VALUES(total), max_points = VALUES(max_points), pct = VALUES(pct),
        band = VALUES(band), verdict = VALUES(verdict), withheld = VALUES(withheld),
        response_count = VALUES(response_count), last_submitted_at = VALUES(last_submitted_at),
        review = VALUES(review), updated_at = NOW()'
)->execute([
    ':pid'     => $projectId,
    ':total'   => $total,
    ':max'     => $max,
    ':pct'     => $pct,
    ':band'    => $band !== '' ? $band : null,
    ':verdict' => $verdict !== '' ? $verdict : null,
    ':withheld'=> $withheld ? 1 : 0,
    ':rc'      => $responseCount,
    ':last'    => $lastSubmittedAt,
    ':review'  => $reviewJson,
]);

json_out([
    'ok'   => true,
    'rssi' => [
        'total'             => $total,
        'max'               => $max,
        'pct'               => $pct,
        'band'              => $band !== '' ? $band : null,
        'verdict'           => $verdict !== '' ? $verdict : null,
        'withheld'          => $withheld,
        'response_count'    => $responseCount,
        'last_submitted_at' => $lastSubmittedAt,
        // Just stamped against the current response data, so it is fresh.
        'stale'             => false,
        'current_count'     => $responseCount,
        'review'            => $result,
    ],
]);
