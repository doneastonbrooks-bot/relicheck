<?php
// GET /api/admin/audit/diag.php
//
// One-shot diagnostic for the admin audit slice. Reports everything I need
// to know in a single response: which version of list.php is on disk,
// whether the admin_audit table exists, what columns it has, how many rows,
// and whether the helper file is loadable. Admin-only.

declare(strict_types=1);

require_once __DIR__ . '/../../_helpers.php';
require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../../_admin.php';

require_method('GET');
require_admin();

$report = [
    'diag_version' => 'AUDIT-DIAG-1',
    'timestamp'    => date('Y-m-d H:i:s'),
    'php_version'  => PHP_VERSION,
];

// 1. Verify the new list.php is what's on disk (look for the soft-fail marker).
$listPath = __DIR__ . '/list.php';
$report['list_php_present']  = file_exists($listPath);
$report['list_php_modified'] = $report['list_php_present'] ? date('Y-m-d H:i:s', filemtime($listPath)) : null;
$report['list_php_size']     = $report['list_php_present'] ? filesize($listPath) : 0;
if ($report['list_php_present']) {
    $contents = file_get_contents($listPath);
    $report['list_php_has_softfail']    = strpos($contents, "admin_audit table not found") !== false;
    $report['list_php_has_table_check'] = strpos($contents, "SHOW TABLES LIKE :n") !== false;
}

// 2. Verify _admin_audit.php helper is present.
$helperPath = __DIR__ . '/../../_admin_audit.php';
$report['helper_present']  = file_exists($helperPath);
$report['helper_modified'] = $report['helper_present'] ? date('Y-m-d H:i:s', filemtime($helperPath)) : null;

// 3. Database connection info.
try {
    $pdo = db();
    $dbName = $pdo->query('SELECT DATABASE() AS d')->fetchColumn();
    $report['db_connected']   = true;
    $report['db_name']        = $dbName;
    $report['mysql_version']  = $pdo->query('SELECT VERSION() AS v')->fetchColumn();
} catch (Throwable $e) {
    $report['db_connected'] = false;
    $report['db_error']     = $e->getMessage();
    json_out($report);
}

// 4. Does the admin_audit table exist?
try {
    $exists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_audit'")->fetchColumn();
    $report['admin_audit_table_exists'] = $exists;
} catch (Throwable $e) {
    $report['admin_audit_table_exists'] = false;
    $report['admin_audit_check_error']  = $e->getMessage();
    json_out($report);
}

if (!$exists) {
    $report['conclusion'] = 'admin_audit table does not exist. Run the Phase 20 migration in phpMyAdmin.';
    json_out($report);
}

// 5. Inspect the columns to detect schema drift.
try {
    $cols = $pdo->query("SHOW COLUMNS FROM admin_audit")->fetchAll(PDO::FETCH_COLUMN);
    $report['admin_audit_columns'] = $cols;
} catch (Throwable $e) {
    $report['admin_audit_columns_error'] = $e->getMessage();
}

// 6. Count rows.
try {
    $report['admin_audit_row_count'] = (int)$pdo->query("SELECT COUNT(*) FROM admin_audit")->fetchColumn();
} catch (Throwable $e) {
    $report['admin_audit_count_error'] = $e->getMessage();
}

// 7. Sample the most recent rows.
try {
    $sample = $pdo->query("SELECT id, ts, actor_email, action, category, severity, target_label
                           FROM admin_audit ORDER BY ts DESC LIMIT 5")->fetchAll();
    $report['admin_audit_sample'] = $sample;
} catch (Throwable $e) {
    $report['admin_audit_sample_error'] = $e->getMessage();
}

// 8. Try the exact query list.php uses.
try {
    $stmt = $pdo->prepare('SELECT id, ts, actor_user_id, actor_email, actor_role, action, category,
                                  severity, target_type, target_id, target_label,
                                  before_value, after_value, reason, ip
                           FROM admin_audit ORDER BY ts DESC LIMIT 1');
    $stmt->execute();
    $stmt->fetchAll();
    $report['list_query_works'] = true;
} catch (Throwable $e) {
    $report['list_query_works']  = false;
    $report['list_query_error']  = $e->getMessage();
}

$report['conclusion'] = ($report['admin_audit_table_exists'] && ($report['list_query_works'] ?? false))
    ? 'Audit slice should work end-to-end. If admin.html still shows placeholder, hard-refresh (Cmd+Shift+R) or upload the latest admin.html.'
    : 'See the per-step error fields above.';

json_out($report);
