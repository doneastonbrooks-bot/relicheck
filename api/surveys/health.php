<?php
// GET /api/surveys/health.php
// Returns cached health pill inputs for every survey owned by the caller.
//
// Shape:
//   { "health": { "<survey_id>": { "status": "green|amber|red|unknown",
//                                  "alpha_min": float|null,
//                                  "last_response_at": "YYYY-MM-DD HH:MM:SS"|null,
//                                  "is_published": bool } } }
//
// Status rules (computed server-side so the client just paints pills):
//   red     - survey is published AND (no responses ever, OR last response > 30d ago).
//   amber   - survey has alpha_min < 0.70.
//   green   - published, has recent responses, alpha_min >= 0.70 (or no alpha if no Likert).
//   unknown - draft survey, or migration not yet applied (cached fields missing).

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$pdo = db();

$rows = [];
try {
    $stmt = $pdo->prepare(
        "SELECT id, is_published,
                health_alpha_min,
                health_last_response_at,
                CASE
                  WHEN health_last_response_at IS NULL THEN NULL
                  ELSE DATEDIFF(NOW(), health_last_response_at)
                END AS days_since_last
           FROM surveys
          WHERE owner_id = :uid
            AND archived_at IS NULL"
    );
    $stmt->execute([':uid' => (int)$user['id']]);
    while ($r = $stmt->fetch()) {
        $rows[] = $r;
    }
} catch (Throwable $e) {
    // Pre-migration. Tell the client nothing is known yet.
    json_out(['health' => [], 'note' => 'health_unavailable']);
}

$health = [];
foreach ($rows as $r) {
    $id          = (int)$r['id'];
    $published   = (bool)$r['is_published'];
    $alphaMin    = $r['health_alpha_min'] !== null ? (float)$r['health_alpha_min'] : null;
    $lastAt      = $r['health_last_response_at'] !== null ? (string)$r['health_last_response_at'] : null;
    $daysSince   = $r['days_since_last'] !== null ? (int)$r['days_since_last'] : null;

    $status = 'unknown';
    if (!$published) {
        $status = 'unknown';
    } elseif ($lastAt === null) {
        // Published, no responses ever.
        $status = 'red';
    } elseif ($daysSince !== null && $daysSince > 30) {
        $status = 'red';
    } elseif ($alphaMin !== null && $alphaMin < 0.70) {
        $status = 'amber';
    } else {
        $status = 'green';
    }

    $health[$id] = [
        'status'           => $status,
        'alpha_min'        => $alphaMin,
        'last_response_at' => $lastAt,
        'days_since_last'  => $daysSince,
        'is_published'     => $published,
    ];
}

json_out(['health' => $health]);
