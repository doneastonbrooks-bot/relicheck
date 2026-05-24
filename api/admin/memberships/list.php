<?php
// GET /api/admin/memberships/list.php
//
// Returns the live tier catalog from api/_tiers.php, reshaped for the
// admin panel's Memberships view. Read-only: editing plans means
// editing the PHP catalog file, not a database row, so there's no
// PATCH endpoint. Each tier also gets a count of how many users are
// currently on it (joined from the users table).

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';
require_once __DIR__ . '/../../_tiers.php';

require_method('GET');
require_admin();

$catalog = tier_catalog();
$pdo     = db();

// Count users on each tier (only if the tier column exists).
$counts = [];
try {
    $hasTier = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'tier'")->fetchColumn();
} catch (Throwable $e) { $hasTier = false; }
if ($hasTier) {
    try {
        $rows = $pdo->query("SELECT tier, COUNT(*) AS cnt FROM users GROUP BY tier")->fetchAll();
        foreach ($rows as $r) $counts[(string)$r['tier']] = (int)$r['cnt'];
    } catch (Throwable $e) { /* leave counts empty */ }
}

$out = [];
foreach ($catalog as $key => $t) {
    $monthly = isset($t['price_monthly_cents']) ? (int)$t['price_monthly_cents'] / 100 : null;
    $annual  = isset($t['price_annual_cents'])  ? (int)$t['price_annual_cents']  / 100 : null;

    $limits   = $t['limits']   ?? [];
    $features = $t['features'] ?? [];

    // Map limits to the admin panel's vocabulary.
    $userLimit     = ($features['team_sharing'] ?? 0);
    $userLimit     = is_int($userLimit) ? ($userLimit + 1) : $userLimit; // +1 for the owner
    $surveyLimit   = $limits['max_surveys']              ?? null;
    $responseLimit = $limits['max_responses_per_survey'] ?? null;

    // Display "Unlimited" for PHP_INT_MAX values.
    $fmt = function ($v) {
        if ($v === null) return null;
        if (is_int($v) && $v >= PHP_INT_MAX - 100) return 'Unlimited';
        return $v;
    };

    // AI access summary (which features are on).
    $aiLevel = 'Basic';
    if (!empty($features['skip_logic']) && !empty($features['anonymous_mode'])) $aiLevel = 'Standard';
    if (!empty($features['manager_dashboard']))                                  $aiLevel = 'Advanced';

    $out[] = [
        'id'            => $key,
        'name'          => $t['name'] ?? ucfirst($key),
        'rank'          => (int)($t['rank'] ?? 0),
        'monthly'       => $monthly,
        'annual'        => $annual,
        'userLimit'     => $fmt($userLimit),
        'surveyLimit'   => $fmt($surveyLimit),
        'responseLimit' => $fmt($responseLimit),
        'aiAccess'      => $aiLevel,
        'features'      => $features,
        'public'        => true,                       // all tiers in the catalog are public
        'active'        => true,                       // catalog entries are always active
        'notes'         => $t['notes'] ?? '',
        'user_count'    => $counts[$key] ?? 0,
    ];
}

// Sort by rank so Free is first.
usort($out, function ($a, $b) { return $a['rank'] - $b['rank']; });

json_out([
    'ok'    => true,
    'rows'  => $out,
    'count' => count($out),
    'note'  => 'Plan definitions live in api/_tiers.php. To edit prices or features, edit that file and redeploy.',
]);
