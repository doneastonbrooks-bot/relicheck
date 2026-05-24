<?php
// Temporary diagnostic endpoint. Reports DB connection status and which
// databases/tables the user can actually see. Delete this file after setup.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = [
    'php_version' => PHP_VERSION,
    'config_file_exists' => is_file(__DIR__ . '/_config.php'),
];

if (!$out['config_file_exists']) {
    $out['next_step'] = 'Copy _config.example.php to _config.php and fill in credentials.';
    echo json_encode($out, JSON_PRETTY_PRINT);
    exit;
}

require_once __DIR__ . '/_db.php';
$cfg = relicheck_config();
$out['db_host']     = $cfg['db_host'] ?? '(missing)';
$out['db_port']     = $cfg['db_port'] ?? '(missing)';
$out['db_name_in_config'] = $cfg['db_name'] ?? '(missing)';
$out['db_user']     = $cfg['db_user'] ?? '(missing)';
$out['db_pass_set'] = !empty($cfg['db_pass']);

// Try to connect WITHOUT specifying a database, so we can list what's available.
try {
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4',
        $cfg['db_host'], $cfg['db_port'] ?? 3306);
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $out['connection'] = 'ok (no database selected)';

    $row = $pdo->query('SELECT VERSION() AS ver, CURRENT_USER() AS who')->fetch();
    $out['mysql_version']  = $row['ver'] ?? null;
    $out['current_user']   = $row['who'] ?? null;

    $stmt = $pdo->query('SHOW DATABASES');
    $databases = [];
    while ($r = $stmt->fetch(PDO::FETCH_NUM)) $databases[] = $r[0];
    $out['databases_available'] = $databases;

    // For each user-owned database (skip information_schema/mysql/performance_schema/sys),
    // try to list its tables.
    $skip = ['information_schema','mysql','performance_schema','sys'];
    foreach ($databases as $db) {
        if (in_array($db, $skip, true)) continue;
        try {
            $pdo->exec('USE `' . str_replace('`', '', $db) . '`');
            $tstmt = $pdo->query('SHOW TABLES');
            $tables = [];
            while ($r = $tstmt->fetch(PDO::FETCH_NUM)) $tables[] = $r[0];
            $out['tables_in'][$db] = $tables;
        } catch (Throwable $e) {
            $out['tables_in'][$db] = 'error: ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
