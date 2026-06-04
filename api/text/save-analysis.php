<?php
// POST /api/text/save-analysis.php
// Body: { title, responses[], themes[], matrix?, cultural_context? }
// Saves the analysis as a dataset owned by the user.
// If matrix is provided: response_text col + one binary col per theme.
// If no matrix: response_text col only. Theme metadata stored in settings.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';

require_method('POST');
check_origin();
$user = require_auth();
$uid  = (int)$user['id'];

$body            = read_json_body();
$title           = clean_string((string)($body['title'] ?? ''), 255);
$responses       = $body['responses'] ?? [];
$rawThemes       = $body['themes']    ?? [];
$rawMatrix       = $body['matrix']    ?? null;
$culturalContext = clean_string((string)($body['cultural_context'] ?? ''), 1000);

if ($title === '') $title = 'OpenText Analysis';

if (!is_array($responses) || count($responses) === 0) {
    fail('bad_input', 'Responses array is required.');
}
if (!is_array($rawThemes) || count($rawThemes) === 0) {
    fail('bad_input', 'Themes array is required.');
}

// Normalize
$responses = array_values(array_map('strval', $responses));
$themeNames = array_values(array_filter(array_map(function ($t) {
    return trim((string)($t['name'] ?? ''));
}, $rawThemes)));

// Tier check
$current = (int)db()->query('SELECT COUNT(*) AS c FROM datasets WHERE owner_id = ' . $uid)->fetch()['c'];
require_under_limit($uid, 'max_datasets', $current);

// Build column_meta and data rows
$columnMeta = [
    ['name' => 'response_text', 'type' => 'open', 'analysis_type' => 'open_ended'],
];

// Add one binary column per theme
foreach ($themeNames as $name) {
    $columnMeta[] = [
        'name'          => $name,
        'type'          => 'single',
        'analysis_type' => 'binary',
    ];
}

// Build a theme presence map by response index (from matrix if provided)
$presenceMap = [];
if (is_array($rawMatrix) && isset($rawMatrix['matrix']) && is_array($rawMatrix['matrix'])) {
    foreach ($rawMatrix['matrix'] as $row) {
        $idx = (int)($row['response_idx'] ?? -1);
        if ($idx < 0) continue;
        $presenceMap[$idx] = $row['theme_presence'] ?? [];
    }
}

$data = [];
foreach ($responses as $i => $text) {
    $row = [$text];
    foreach ($themeNames as $name) {
        $present = isset($presenceMap[$i][$name]) ? (bool)$presenceMap[$i][$name] : false;
        $row[]   = $present ? 1 : 0;
    }
    $data[] = $row;
}

// Settings: store theme metadata + cultural context for future Qual Studio import
$settings = [
    'likertPoints' => 5,
    'likertLow'    => 'Strongly disagree',
    'likertHigh'   => 'Strongly agree',
    'source'       => 'opentext',
    'cultural_context' => $culturalContext,
    'themes' => array_map(function ($t) {
        return [
            'name'        => trim((string)($t['name'] ?? '')),
            'description' => trim((string)($t['description'] ?? '')),
            'quotes'      => array_values(array_filter(array_map('strval', (array)($t['quotes'] ?? [])))),
            'prominence'  => (string)($t['prominence'] ?? 'moderate'),
        ];
    }, $rawThemes),
];

$pdo  = db();
$stmt = $pdo->prepare(
    'INSERT INTO datasets (owner_id, title, source_filename, source_format, row_count, column_count, column_meta, settings, data)
     VALUES (:uid, :title, :sfn, :sfmt, :rc, :cc, :cm, :st, :d)'
);
$stmt->execute([
    ':uid'   => $uid,
    ':title' => $title,
    ':sfn'   => $title . '.csv',
    ':sfmt'  => 'csv',
    ':rc'    => count($data),
    ':cc'    => count($columnMeta),
    ':cm'    => json_encode($columnMeta, JSON_UNESCAPED_UNICODE),
    ':st'    => json_encode($settings,    JSON_UNESCAPED_UNICODE),
    ':d'     => json_encode($data,        JSON_UNESCAPED_UNICODE),
]);

$datasetId = (int)$pdo->lastInsertId();

json_out([
    'ok'         => true,
    'dataset_id' => $datasetId,
    'title'      => $title,
    'row_count'  => count($data),
    'col_count'  => count($columnMeta),
]);
