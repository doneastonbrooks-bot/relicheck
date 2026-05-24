<?php
// Temporary Phase 5 diagnostic. Reports every location PHP might see
// the Authorization header on IONOS. Delete this file after the auth
// header issue is fixed. The leading underscore would normally make
// .htaccess block it, but we want this one reachable — rename inline
// to a fetchable path:
//
//   GET /api/v1/authprobe.php  (sym/copy without the underscore)
//
// Or just request it as /api/v1/_authprobe.php and confirm whether the
// .htaccess underscore-block fires (it should, which itself is a clue).
//
// No bearer token validation. The output reveals only environment
// keys, never the token value beyond the first 11 characters.

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

$server_keys = [];
foreach ($_SERVER as $k => $v) {
    // Whitelist the keys that matter for Authorization-header debugging
    if (stripos($k, 'auth') !== false ||
        stripos($k, 'http_') === 0 ||
        stripos($k, 'redirect_') === 0 ||
        $k === 'REQUEST_METHOD' ||
        $k === 'REQUEST_URI' ||
        $k === 'SERVER_SOFTWARE') {
        $val = (string)$v;
        // Redact anything that looks like a bearer token to its prefix.
        if (preg_match('/Bearer\s+(rk_\w{8})\w+/', $val, $m)) {
            $val = preg_replace('/Bearer\s+rk_\w+/', 'Bearer ' . $m[1] . '...', $val);
        }
        $server_keys[$k] = $val;
    }
}

$report = [
    'server_software'      => $_SERVER['SERVER_SOFTWARE'] ?? null,
    'request_method'       => $_SERVER['REQUEST_METHOD'] ?? null,
    'has_apache_request_headers' => function_exists('apache_request_headers'),
    'has_getallheaders'    => function_exists('getallheaders'),
    'server_authorization_keys' => $server_keys,
];

if (function_exists('apache_request_headers')) {
    $h = apache_request_headers();
    if (is_array($h)) {
        $auth = null;
        foreach ($h as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $val = (string)$v;
                if (preg_match('/Bearer\s+(rk_\w{8})\w+/', $val, $m)) {
                    $val = 'Bearer ' . $m[1] . '...';
                }
                $auth = $val;
                break;
            }
        }
        $report['apache_request_headers_authorization'] = $auth;
    }
}

if (function_exists('getallheaders')) {
    $h = getallheaders();
    if (is_array($h)) {
        $auth = null;
        foreach ($h as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $val = (string)$v;
                if (preg_match('/Bearer\s+(rk_\w{8})\w+/', $val, $m)) {
                    $val = 'Bearer ' . $m[1] . '...';
                }
                $auth = $val;
                break;
            }
        }
        $report['getallheaders_authorization'] = $auth;
    }
}

echo json_encode($report, JSON_PRETTY_PRINT);
