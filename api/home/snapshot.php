<?php
// GET /api/home/snapshot.php
// One round-trip for everything the Home cards need: pinned surveys,
// latest result snapshot, and a 7-day response-activity series.
//
// Shape:
// {
//   "pinned":   [ { id, title, slug, response_count, health: "green|amber|red|unknown" }, ... ],
//   "latest":   { id, title, slug, response_count, alpha: float|null, health: "..." }|null,
//   "activity": { from: "YYYY-MM-DD", to: "YYYY-MM-DD", series: [c0..c6], total: int },
//   "totals":   { surveys: int, datasets: int, responses: int }
// }
//
// Defensive: pre-migration installs miss is_favorite / archived_at / health_*
// columns. Those branches fall back to safe defaults instead of crashing.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

// ---- Try the Phase 37a-aware SELECTs first; fall back gracefully ---------
$canUseHealth = true;
try {
    // 1. Pinned (favorited, non-archived) surveys, up to 5.
    $stmt = $pdo->prepare(
        "SELECT s.id, s.slug, s.title, s.is_published,
                s.health_alpha_min, s.health_last_response_at,
                (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.id) AS rc
           FROM surveys s
          WHERE s.owner_id = :uid
            AND s.is_favorite = 1
            AND s.archived_at IS NULL
          ORDER BY s.updated_at DESC
          LIMIT 5"
    );
    $stmt->execute([':uid' => $uid]);
    $pinnedRows = $stmt->fetchAll();

    // 2. Latest = most recently updated, non-archived, published survey.
    $stmt = $pdo->prepare(
        "SELECT s.id, s.slug, s.title, s.is_published,
                s.health_alpha_min, s.health_last_response_at,
                (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.id) AS rc
           FROM surveys s
          WHERE s.owner_id = :uid
            AND s.archived_at IS NULL
          ORDER BY s.is_published DESC, s.updated_at DESC
          LIMIT 1"
    );
    $stmt->execute([':uid' => $uid]);
    $latestRow = $stmt->fetch();
} catch (Throwable $e) {
    // Pre-migration fallback: drop the Phase 37a columns from the SELECT.
    $canUseHealth = false;

    $stmt = $pdo->prepare(
        "SELECT s.id, s.slug, s.title, s.is_published,
                (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.id) AS rc
           FROM surveys s
          WHERE s.owner_id = :uid
          ORDER BY s.updated_at DESC
          LIMIT 1"
    );
    $stmt->execute([':uid' => $uid]);
    $latestRow = $stmt->fetch();
    $pinnedRows = []; // no favorites concept yet.
}

// ---- 3. 7-day response activity across all surveys -----------------------
$activitySeries = array_fill(0, 7, 0);
$activityTotal  = 0;
$range = $pdo->query(
    'SELECT DATE(DATE_SUB(NOW(), INTERVAL 6 DAY)) AS d_from,
            DATE(NOW())                           AS d_to'
)->fetch();
$dFrom = (string)$range['d_from'];
$dTo   = (string)$range['d_to'];

try {
    $stmt = $pdo->prepare(
        "SELECT DATE(r.submitted_at) AS d, COUNT(*) AS c
           FROM responses r
           JOIN surveys s ON s.id = r.survey_id
          WHERE s.owner_id = :uid
            AND r.submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          GROUP BY DATE(r.submitted_at)"
    );
    $stmt->execute([':uid' => $uid]);
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[date('Y-m-d', strtotime($dFrom . " +{$i} day"))] = $i;
    }
    while ($r = $stmt->fetch()) {
        $idx = $days[$r['d']] ?? null;
        if ($idx !== null) {
            $activitySeries[$idx] = (int)$r['c'];
            $activityTotal += (int)$r['c'];
        }
    }
} catch (Throwable $e) {
    // Leave the all-zero scaffold.
}

// ---- 4. Totals strip (small under-greeting numbers) ----------------------
$totSurveys = 0; $totDatasets = 0; $totResponses = 0;
try {
    $totSurveys = (int)$pdo->query(
        'SELECT COUNT(*) AS c FROM surveys WHERE owner_id = ' . $uid
    )->fetch()['c'];
} catch (Throwable $e) {}
try {
    $totDatasets = (int)$pdo->query(
        'SELECT COUNT(*) AS c FROM datasets WHERE owner_id = ' . $uid
    )->fetch()['c'];
} catch (Throwable $e) {}
try {
    $totResponses = (int)$pdo->query(
        'SELECT COUNT(*) AS c FROM responses r JOIN surveys s ON s.id = r.survey_id WHERE s.owner_id = ' . $uid
    )->fetch()['c'];
} catch (Throwable $e) {}

// ---- Health classifier (same rules as api/surveys/health.php) ------------
$healthFor = function (array $row) use ($canUseHealth) {
    if (!$canUseHealth) return 'unknown';
    if (empty($row['is_published']))         return 'unknown';
    $last = $row['health_last_response_at'] ?? null;
    if ($last === null && (int)($row['rc'] ?? 0) === 0) return 'red';
    if ($last !== null) {
        $ts = strtotime((string)$last . ' UTC');
        if ($ts && (time() - $ts) > 30 * 86400) return 'red';
    }
    $alpha = $row['health_alpha_min'] ?? null;
    if ($alpha !== null && (float)$alpha < 0.70) return 'amber';
    return 'green';
};

// ---- Shape the payload --------------------------------------------------
$pinned = [];
foreach ($pinnedRows as $r) {
    $pinned[] = [
        'id'             => (int)$r['id'],
        'slug'           => (string)$r['slug'],
        'title'          => (string)$r['title'],
        'response_count' => (int)$r['rc'],
        'is_published'   => (bool)$r['is_published'],
        'health'         => $healthFor($r),
    ];
}

$latest = null;
if ($latestRow) {
    $latest = [
        'id'             => (int)$latestRow['id'],
        'slug'           => (string)$latestRow['slug'],
        'title'          => (string)$latestRow['title'],
        'response_count' => (int)$latestRow['rc'],
        'is_published'   => (bool)$latestRow['is_published'],
        'alpha'          => $canUseHealth && isset($latestRow['health_alpha_min']) && $latestRow['health_alpha_min'] !== null
                              ? (float)$latestRow['health_alpha_min'] : null,
        'health'         => $healthFor($latestRow),
    ];
}

json_out([
    'pinned'   => $pinned,
    'latest'   => $latest,
    'activity' => [
        'from'   => $dFrom,
        'to'     => $dTo,
        'series' => array_values($activitySeries),
        'total'  => $activityTotal,
    ],
    'totals'   => [
        'surveys'   => $totSurveys,
        'datasets'  => $totDatasets,
        'responses' => $totResponses,
    ],
]);
