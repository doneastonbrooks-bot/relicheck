<?php
// PDO singleton for the ReliCheck database.
// Loads credentials from _config.php (which is gitignored).

declare(strict_types=1);

function relicheck_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = __DIR__ . '/_config.php';
    if (!is_file($path)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'server_misconfigured',
            'message' => 'Missing _config.php. Copy _config.example.php to _config.php and fill in your Ionos credentials.',
        ]);
        exit;
    }
    $cfg = require $path;
    if (!is_array($cfg)) {
        throw new RuntimeException('_config.php must return an array.');
    }

    // Validate before returning. We want scrambled or placeholder values
    // to fail loudly with a precise message, not silently break.
    require_once __DIR__ . '/_config_validator.php';
    $errors = relicheck_validate_config($cfg);
    if (!empty($errors)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error'   => 'config_invalid',
            'message' => 'Server config (_config.php) failed validation. Fix these and reload:',
            'issues'  => $errors,
        ], JSON_PRETTY_PRINT);
        exit;
    }

    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $cfg = relicheck_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['db_host'],
        $cfg['db_port'] ?? 3306,
        $cfg['db_name']
    );
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
        PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES    => false,
        PDO::MYSQL_ATTR_INIT_COMMAND  => "SET time_zone='+00:00', NAMES utf8mb4",
    ]);
    return $pdo;
}
