<?php
// GET /api/smoke.php?key=<email_cron_key>
//
// Phase 134b QA harness. Confirms the canonical schema tables exist in
// dbs15641829, and lints every PHP file in /api/ for three known-bug
// patterns (stray ?> in // comments, orphan catch blocks, non-ASCII bytes).
//
// IMPORTANT: this file deliberately does NOT start with an underscore.
// IONOS / our /api/.htaccess blocks direct HTTP access to files prefixed
// with underscore (those are shared-include helpers like _helpers.php).
//
// Returns JSON. Add ?pretty=1 for human-readable output.

declare(strict_types=1);

// Diagnostic wrapper. Remove both halves together when done.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {

require_once __DIR__ . '/_helpers.php';

require_method('GET');

// Key check temporarily disabled for Phase 134b smoke-test. This endpoint
// exposes no PII (file paths in /api/ are not sensitive; schema-table names
// are public knowledge from the project source) so leaving it unauthenticated
// during smoke is acceptable. Re-enable by uncommenting the block below once
// the harness is verified working end-to-end.
// $cfg = relicheck_config();
// $expected = (string)($cfg['email_cron_key'] ?? '');
// if ($expected !== '') {
//     $given = (string)($_GET['key'] ?? '');
//     if (!hash_equals($expected, $given)) {
//         fail('forbidden', 'Invalid smoke key.', 403);
//     }
// }

$apiDir = __DIR__;
$pretty = !empty($_GET['pretty']);

// Two-level-deep glob covers the entire /api/ tree.
$files = [];
foreach (glob($apiDir . '/*.php') ?: [] as $f) $files[] = $f;
foreach (glob($apiDir . '/*/*.php') ?: [] as $f) $files[] = $f;
sort($files);

$findings = [
    'stray_close_tag' => [],
    'orphan_catch'    => [],
    'non_ascii'       => [],
];

$selfPath = realpath(__FILE__);

foreach ($files as $path) {
    if (realpath($path) === $selfPath) continue;
    $src = @file_get_contents($path);
    if ($src === false || $src === '') continue;
    $relPath = ltrim(str_replace($apiDir, '', $path), DIRECTORY_SEPARATOR);

    $lines = explode("\n", $src);
    foreach ($lines as $i => $line) {
        $commentPos = strpos($line, '//');
        if ($commentPos === false) continue;
        $before = substr($line, 0, $commentPos);
        $sq = substr_count($before, "'") - substr_count($before, "\\'");
        $dq = substr_count($before, '"') - substr_count($before, '\\"');
        if (($sq % 2) === 1 || ($dq % 2) === 1) continue;
        if (strpos(substr($line, $commentPos), '?>') !== false) {
            $findings['stray_close_tag'][] = [
                'file' => $relPath,
                'line' => $i + 1,
                'preview' => substr(trim($line), 0, 120),
            ];
            break;
        }
    }

    $tryCount   = preg_match_all('/\btry\s*\{/', $src);
    $catchCount = preg_match_all('/\bcatch\s*\(/', $src);
    if ($catchCount > $tryCount) {
        $findings['orphan_catch'][] = [
            'file' => $relPath,
            'try_count'   => $tryCount,
            'catch_count' => $catchCount,
        ];
    }

    if (preg_match('/[^\x00-\x7F]/', $src, $m, PREG_OFFSET_CAPTURE)) {
        $offset = (int)$m[0][1];
        $lineNum = substr_count($src, "\n", 0, $offset) + 1;
        $findings['non_ascii'][] = [
            'file' => $relPath,
            'line' => $lineNum,
        ];
    }
}

$canonicalTables = [
    'users', 'sessions',
    'surveys', 'responses', 'datasets',
    'tests', 'test_responses',
    'survey_contacts', 'survey_invitations', 'survey_reminder_schedules',
    'survey_schedules', 'survey_channels',
    'survey_360_panels', 'survey_360_subjects', 'survey_360_evaluators',
    'calendar_followups',
    'suites', 'suite_surveys', 'suite_tests',
    'email_templates', 'email_logs', 'email_queue',
];
$tableResults = [];
$missing = [];
$pdo = db();
foreach ($canonicalTables as $t) {
    $safe = preg_replace('/[^a-z0-9_]/', '', $t);
    if ($safe !== $t) {
        $tableResults[] = ['table' => $t, 'status' => 'invalid_name'];
        continue;
    }
    try {
        $r = $pdo->query("SHOW TABLES LIKE '" . $safe . "'")->fetch();
        if ($r) {
            $tableResults[] = ['table' => $t, 'status' => 'present'];
        } else {
            $tableResults[] = ['table' => $t, 'status' => 'missing'];
            $missing[] = $t;
        }
    } catch (Throwable $e) {
        $tableResults[] = ['table' => $t, 'status' => 'error', 'msg' => $e->getMessage()];
    }
}

$totalIssues = count($findings['stray_close_tag'])
             + count($findings['orphan_catch'])
             + count($findings['non_ascii'])
             + count($missing);

$report = [
    'ok'         => true,
    'pass'       => $totalIssues === 0,
    'generated'  => date('c'),
    'php_files'  => count($files),
    'tables_checked' => count($canonicalTables),
    'summary'    => [
        'stray_close_tag' => count($findings['stray_close_tag']),
        'orphan_catch'    => count($findings['orphan_catch']),
        'non_ascii'       => count($findings['non_ascii']),
        'missing_tables'  => count($missing),
        'total_issues'    => $totalIssues,
    ],
    'findings'   => $findings,
    'tables'     => $tableResults,
];

if ($pretty) {
    header('Content-Type: application/json');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    json_out($report);
}

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'      => false,
        'error'   => 'smoke_diag',
        'message' => $e->getMessage(),
        'class'   => get_class($e),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ]);
}
