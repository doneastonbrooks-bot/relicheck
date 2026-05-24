<?php
// POST /api/datasets/create.php
// Body: { title, source_filename?, source_format?, column_meta, settings, data }
//   - column_meta: [{ name, type:'likert'|'single'|'multi'|'open'|'ignore', reverse?, options? }]
//   - settings:    { likertPoints, likertLow, likertHigh }
//   - data:        [[row1Cells...], [row2Cells...], ...]
//
// Limits: 50,000 rows, 200 columns, ~10 MB JSON-encoded payload.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';

require_method('POST');
check_origin();
$user = require_auth();

// Tier check: dataset count limit
$current = (int)db()->query('SELECT COUNT(*) AS c FROM datasets WHERE owner_id = ' . (int)$user['id'])->fetch()['c'];
require_under_limit((int)$user['id'], 'max_datasets', $current);

$body = read_json_body();

$title = clean_string($body['title'] ?? '', 255);
if ($title === '') $title = 'Untitled dataset';

$columnMeta = is_array($body['column_meta'] ?? null) ? $body['column_meta'] : null;
$settings   = is_array($body['settings']    ?? null) ? $body['settings']    : null;
$data       = is_array($body['data']        ?? null) ? $body['data']        : null;

if ($columnMeta === null) fail('bad_column_meta', 'column_meta is required.');
if ($settings   === null) fail('bad_settings',    'settings is required.');
if ($data       === null) fail('bad_data',        'data is required.');

if (count($columnMeta) > 200) fail('too_many_columns', 'Datasets are limited to 200 columns.');
if (count($data) > 50000)     fail('too_many_rows',    'Datasets are limited to 50,000 rows.');

// Tier check: rows-per-dataset limit
require_under_limit((int)$user['id'], 'max_rows_per_dataset', 0, count($data));

// Sanitize column metadata
$cleanCols = [];
foreach ($columnMeta as $c) {
    if (!is_array($c)) continue;
    $type = $c['type'] ?? 'ignore';
    if (!in_array($type, ['likert','single','multi','open','ignore'], true)) $type = 'ignore';
    $entry = [
        'name'    => clean_string((string)($c['name'] ?? ''), 200),
        'type'    => $type,
        'reverse' => !empty($c['reverse']),
    ];
    if (in_array($type, ['single','multi'], true) && isset($c['options']) && is_array($c['options'])) {
        $entry['options'] = array_values(array_map(fn($o) => clean_string((string)$o, 200), $c['options']));
    }
    $cleanCols[] = $entry;
}
$columnCount = count($cleanCols);

// Sanitize settings
$kPoints = (int)($settings['likertPoints'] ?? 5);
if ($kPoints < 2 || $kPoints > 11) $kPoints = 5;
$cleanSettings = [
    'likertPoints' => $kPoints,
    'likertLow'    => clean_string((string)($settings['likertLow']  ?? 'Strongly disagree'), 80),
    'likertHigh'   => clean_string((string)($settings['likertHigh'] ?? 'Strongly agree'),    80),
];

// Encode the data array. We trust the front-end to have already coerced cells
// into appropriate types (numbers stay numbers, strings stay strings).
$jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
if ($jsonData === false) fail('bad_data', 'Could not encode data as JSON.', 400);
if (strlen($jsonData) > 10 * 1024 * 1024) fail('payload_too_large', 'Dataset exceeds the 10 MB size limit.', 413);

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO datasets
        (owner_id, title, source_filename, source_format, row_count, column_count,
         column_meta, settings, data)
     VALUES
        (:uid, :title, :sfn, :sfmt, :rc, :cc, :cm, :st, :d)'
);
$stmt->execute([
    ':uid'   => $user['id'],
    ':title' => $title,
    ':sfn'   => clean_string((string)($body['source_filename'] ?? ''), 255) ?: null,
    ':sfmt'  => in_array($body['source_format'] ?? '', ['csv','xlsx'], true) ? $body['source_format'] : null,
    ':rc'    => count($data),
    ':cc'    => $columnCount,
    ':cm'    => json_encode($cleanCols, JSON_UNESCAPED_UNICODE),
    ':st'    => json_encode($cleanSettings, JSON_UNESCAPED_UNICODE),
    ':d'     => $jsonData,
]);
$id = (int)$pdo->lastInsertId();

json_out([
    'dataset' => [
        'id'              => $id,
        'title'           => $title,
        'row_count'       => count($data),
        'column_count'    => $columnCount,
        'column_meta'     => $cleanCols,
        'settings'        => $cleanSettings,
    ],
], 201);
