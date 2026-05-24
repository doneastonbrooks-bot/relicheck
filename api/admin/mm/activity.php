<?php
// GET /api/admin/mm/activity.php
//
// Admin readout of MM Studio beta cohort activity. Returns:
//   * Aggregate counters (users total, projects total, active last 7 days,
//     stuck mid-wizard, themes built, reports started)
//   * Per-user roll-up: projects total, past wizard, with themes, with
//     report sections, last activity, stuck flag
//
// One row per user who has at least one mm_projects row. Admin-only.
// Guards every optional table read with try/catch so a missing migration
// returns a graceful empty cell rather than HTML 500.
//
// Phase 170c (admin tooling for the closed beta).

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');
require_admin();

$pdo = db();

// Table guard. If MM Studio hasn't been migrated on this server we return a
// soft empty payload rather than 500.
try {
    $has = (bool)$pdo->query("SHOW TABLES LIKE 'mm_projects'")->fetchColumn();
} catch (Throwable $e) { $has = false; }
if (!$has) {
    json_out([
        'ok'       => true,
        'users'    => [],
        'totals'   => [
            'users' => 0, 'projects' => 0, 'active_7d' => 0,
            'stuck' => 0, 'themes_built' => 0, 'reports_started' => 0,
        ],
        'warning'  => 'mm_projects table is not present. Apply schema_phase155.sql.',
    ]);
}

// Whether the framing table is present (Phase 170 migration). If not, treat
// every project as legacy / past-wizard.
$hasFraming = false;
try {
    $hasFraming = (bool)$pdo->query("SHOW TABLES LIKE 'mm_project_framing'")->fetchColumn();
} catch (Throwable $e) { $hasFraming = false; }

// Whether the theme / report tables exist.
$hasThemes = false;
try { $hasThemes = (bool)$pdo->query("SHOW TABLES LIKE 'mm_theme_categories'")->fetchColumn(); }
catch (Throwable $e) {}

$hasReportSections = false;
try { $hasReportSections = (bool)$pdo->query("SHOW TABLES LIKE 'mm_report_sections'")->fetchColumn(); }
catch (Throwable $e) {}

// "Past wizard" = wizard_step >= 99 OR framing_status in (complete, skipped_legacy).
// "Stuck" = project has been mid-wizard for more than 48 hours (wizard_step
// between 1 and 98 AND last update older than 48 hours).
//
// We compute per-project flags in a single SELECT, then roll up per user.

$projectFlags = "
  p.id AS project_id,
  p.user_id,
  p.title,
  p.status,
  p.wizard_step,
  p.updated_at,
  " . ($hasFraming
        ? "COALESCE(f.framing_status, 'pending') AS framing_status,"
        : "'skipped_legacy' AS framing_status,") . "
  CASE WHEN p.wizard_step >= 99 " .
    ($hasFraming ? "OR f.framing_status IN ('complete','skipped_legacy') " : "") .
    "THEN 1 ELSE 0 END AS past_wizard,
  CASE WHEN p.wizard_step BETWEEN 1 AND 98
            AND p.updated_at < (NOW() - INTERVAL 48 HOUR)
            " . ($hasFraming ? "AND COALESCE(f.framing_status,'pending') IN ('pending','in_progress')" : "AND 1=0") . "
       THEN 1 ELSE 0 END AS stuck_flag
";

$joinFraming = $hasFraming
    ? "LEFT JOIN mm_project_framing f ON f.project_id = p.id"
    : "";

// Subquery: themes count per project. NULL when the table is missing.
$themeCountExpr = $hasThemes
    ? "(SELECT COUNT(*) FROM mm_theme_categories t WHERE t.project_id = p.id)"
    : "0";

// Subquery: report sections count per project.
$reportCountExpr = $hasReportSections
    ? "(SELECT COUNT(*) FROM mm_report_sections r WHERE r.project_id = p.id)"
    : "0";

$sql = "
    SELECT
        $projectFlags,
        $themeCountExpr  AS theme_count,
        $reportCountExpr AS report_count
    FROM mm_projects p
    $joinFraming
    WHERE p.status != 'archived'
";

try {
    $rows = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    json_out([
        'ok'       => false,
        'users'    => [],
        'totals'   => ['users' => 0, 'projects' => 0, 'active_7d' => 0, 'stuck' => 0, 'themes_built' => 0, 'reports_started' => 0],
        'warning'  => 'Activity query failed: ' . $e->getMessage(),
    ]);
}

// Roll up per user.
$byUser = [];
$now = time();
$sevenDays = 7 * 24 * 3600;
foreach ($rows as $r) {
    $uid = (int)$r['user_id'];
    if (!isset($byUser[$uid])) {
        $byUser[$uid] = [
            'user_id'         => $uid,
            'email'           => null,
            'name'            => null,
            'projects_total'  => 0,
            'past_wizard'     => 0,
            'in_wizard'       => 0,
            'stuck'           => 0,
            'themes_built'    => 0,
            'reports_started' => 0,
            'last_activity'   => null,
        ];
    }
    $u = &$byUser[$uid];
    $u['projects_total']++;
    if ((int)$r['past_wizard']) $u['past_wizard']++; else $u['in_wizard']++;
    if ((int)$r['stuck_flag']) $u['stuck']++;
    if ((int)$r['theme_count']  > 0) $u['themes_built']++;
    if ((int)$r['report_count'] > 0) $u['reports_started']++;
    $upd = $r['updated_at'];
    if ($upd && ($u['last_activity'] === null || $upd > $u['last_activity'])) {
        $u['last_activity'] = $upd;
    }
    unset($u);
}

// Hydrate user email/name in one query.
if (!empty($byUser)) {
    $ids = array_keys($byUser);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $ustmt = $pdo->prepare("SELECT id, email, name FROM users WHERE id IN ($place)");
    $ustmt->execute($ids);
    foreach ($ustmt->fetchAll() as $u) {
        $uid = (int)$u['id'];
        if (isset($byUser[$uid])) {
            $byUser[$uid]['email'] = $u['email'];
            $byUser[$uid]['name']  = $u['name'];
        }
    }
}

$users = array_values($byUser);
// Sort: stuck users first, then most recently active.
usort($users, function ($a, $b) {
    if (($b['stuck'] > 0) !== ($a['stuck'] > 0)) return ($b['stuck'] > 0) ? 1 : -1;
    return strcmp((string)$b['last_activity'], (string)$a['last_activity']);
});

// Aggregate counters.
$totals = [
    'users'           => count($users),
    'projects'        => 0,
    'active_7d'       => 0,
    'stuck'           => 0,
    'themes_built'    => 0,
    'reports_started' => 0,
];
foreach ($users as $u) {
    $totals['projects']        += $u['projects_total'];
    $totals['stuck']           += ($u['stuck'] > 0) ? 1 : 0;
    $totals['themes_built']    += $u['themes_built'];
    $totals['reports_started'] += $u['reports_started'];
    if ($u['last_activity']) {
        $ts = strtotime($u['last_activity']);
        if ($ts && ($now - $ts) <= $sevenDays) $totals['active_7d']++;
    }
}

json_out([
    'ok'     => true,
    'now'    => date('Y-m-d H:i:s'),
    'totals' => $totals,
    'users'  => $users,
]);
