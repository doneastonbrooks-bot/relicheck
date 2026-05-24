<?php
// GET /api/mm/diag-app-version.php
//
// Tiny health-check. Reports size, mtime, and the presence of recent
// Phase-marker strings inside the live app-2026.html on the host. Lets us
// tell at a glance whether a recent edit has actually reached production.
//
// Delete after the Evidence loader issue is closed.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_method('GET');
$user = require_auth();

$appPath = realpath(__DIR__ . '/../../app-2026.html');
$out = [
    'ok'       => true,
    'app_path' => $appPath ?: '(not found)',
    'exists'   => $appPath && is_file($appPath),
];

if ($appPath && is_file($appPath)) {
    $out['size']   = filesize($appPath);
    $out['mtime']  = date('Y-m-d H:i:s T', filemtime($appPath));
    $out['mtime_epoch'] = filemtime($appPath);

    // Read the file in chunks; only check for known marker strings to keep
    // the response small.
    $markers = [
        'Phase 178dd' => false,
        'mmCbEvLoadTime' => false,
        'mmCbEvLoadMsg'  => false,
        'Phase 178cc'    => false,
        'Phase 178bb'    => false,
        'Phase 178aa'    => false,
    ];
    $needle = array_keys($markers);
    $haystack = file_get_contents($appPath);
    foreach ($needle as $n) {
        $markers[$n] = (strpos($haystack, $n) !== false);
    }
    $out['markers_found'] = $markers;
}

json_out($out);
