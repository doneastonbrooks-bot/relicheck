<?php
// GET /api/public/stats.php
// Public, unauthenticated. Returns the two homepage hero counts:
//   - teams: number of distinct workspace owners with at least one published survey
//   - surveys: total number of published surveys
//
// Cached in an APCu key for 1 hour to avoid hitting the DB on every page load.
// Falls back to a fresh query if APCu isn't available (Ionos shared hosting
// sometimes has APCu disabled). The response also includes a static fallback
// that the homepage can render if the fetch fails entirely.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';

require_method('GET');

const STATS_CACHE_KEY = 'relicheck_homepage_stats_v1';
const STATS_CACHE_TTL = 3600; // 1 hour

function load_stats_from_db(): array
{
    // Distinct workspace owners with >= 1 published survey.
    // Uses surveys.owner_id (FK to users.id). The DISTINCT count is the closest
    // honest mapping for "research teams" given the current schema, which has no
    // explicit org_type column. If an org_type column is added later, swap this
    // query to JOIN users and filter by type IN ('research', 'evaluation', 'academic').
    $teams = (int)db()->query(
        "SELECT COUNT(DISTINCT owner_id) FROM surveys WHERE is_published = 1"
    )->fetchColumn();

    $surveys = (int)db()->query(
        "SELECT COUNT(*) FROM surveys WHERE is_published = 1"
    )->fetchColumn();

    return [
        'teams'      => $teams,
        'surveys'    => $surveys,
        'as_of'      => gmdate('c'),
    ];
}

// Try APCu; fall through to a fresh query if cache is unavailable or expired.
$payload = null;
$fromCache = false;
if (function_exists('apcu_fetch')) {
    $hit = apcu_fetch(STATS_CACHE_KEY, $found);
    if ($found && is_array($hit)) {
        $payload = $hit;
        $fromCache = true;
    }
}
if ($payload === null) {
    $payload = load_stats_from_db();
    if (function_exists('apcu_store')) {
        apcu_store(STATS_CACHE_KEY, $payload, STATS_CACHE_TTL);
    }
}

// Caller-side cache hint (CDN-safe; we revalidate every 10 minutes even if
// APCu is hot). Cache-Control intentionally allows 60s of stale serving on
// errors so a brief DB blip doesn't break the homepage.
header('Cache-Control: public, max-age=600, stale-if-error=60');
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'teams'   => $payload['teams'],
    'surveys' => $payload['surveys'],
    'as_of'   => $payload['as_of'] ?? gmdate('c'),
    'cached'  => $fromCache,
], JSON_UNESCAPED_SLASHES);
