<?php
// POST /api/dev/sdsi-save.php
// Body: { project_id, total, max?, pct?, band?, blocked?, review? }
// Stores the SDSI (Survey Design Strength Index, 50-pt) review OBJECT for a
// project. Scoring itself stays stubbed in Phase 2A — this endpoint only
// persists whatever review payload the client produces, separately from SIRI.
// Upsert: one stored review per project.

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

$total   = is_numeric($body['total'] ?? null) ? round((float)$body['total'], 2) : 0.0;
$max     = is_numeric($body['max'] ?? null)   ? round((float)$body['max'], 2)   : 50.0;
$pct     = is_numeric($body['pct'] ?? null)   ? max(0, min(100, (int)$body['pct'])) : (int)round($max > 0 ? $total / $max * 100 : 0);
$band    = clean_string((string)($body['band'] ?? ''), 120);
$blocked = !empty($body['blocked']) ? 1 : 0;
$review  = (isset($body['review']) && is_array($body['review'])) ? json_encode($body['review'], JSON_UNESCAPED_UNICODE) : null;

$pdo->prepare(
    'INSERT INTO sdsi_reviews (project_id, total, max_points, pct, band, blocked, review)
     VALUES (:pid, :total, :max, :pct, :band, :blocked, :review)
     ON DUPLICATE KEY UPDATE total = VALUES(total), max_points = VALUES(max_points),
        pct = VALUES(pct), band = VALUES(band), blocked = VALUES(blocked), review = VALUES(review)'
)->execute([
    ':pid'     => $projectId,
    ':total'   => $total,
    ':max'     => $max,
    ':pct'     => $pct,
    ':band'    => $band !== '' ? $band : null,
    ':blocked' => $blocked,
    ':review'  => $review,
]);

json_out(['ok' => true, 'project_id' => $projectId, 'total' => $total, 'max' => $max, 'pct' => $pct]);
