<?php
// POST /api/mm/save-to-datasets.php
// Body: { project_id, title? }
//
// Copies the Mixed-Methods project's structured dataset into the main
// `datasets` table so it appears in the Datasets sidebar and can be used
// with the rest of ReliCheck (descriptive stats, reliability, factor
// analysis, exports, etc.).
//
// Column types map per generated variable type:
//   binary, frequency, intensity, ordinal, sentiment -> 'likert' (numeric-ish)
//   category                                          -> 'single'
// Anything else                                       -> 'open'

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';
require_once __DIR__ . '/../_mm.php';

require_method('POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$titleIn   = clean_string((string)($body['title'] ?? ''), 255);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
$project = mm_require_project($pdo, $uid, $projectId);

// Pull the most recent structured dataset for this project.
$dsStmt = $pdo->prepare('SELECT * FROM mm_structured_datasets WHERE project_id = :p ORDER BY id DESC LIMIT 1');
$dsStmt->execute([':p' => $projectId]);
$ds = $dsStmt->fetch(PDO::FETCH_ASSOC);
if (!$ds) {
    fail('mm_no_dataset', 'No dataset has been built yet for this project. Click Build dataset first.');
}

$schema = json_decode((string)($ds['schema_json'] ?? ''), true);
if (!is_array($schema) || count($schema) === 0) {
    fail('mm_no_columns', 'The dataset has no columns. Build it again.');
}

// Load all responses + cells.
$resps = mm_load_responses($pdo, $projectId, 5000);
$cellStmt = $pdo->prepare('SELECT response_id, variable_id, cell_value FROM mm_dataset_cells WHERE dataset_id = :d');
$cellStmt->execute([':d' => (int)$ds['id']]);
$byResp = [];
foreach ($cellStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $byResp[(int)$c['response_id']][(int)$c['variable_id']] = (string)$c['cell_value'];
}

// Build the column_meta in the shape the datasets table expects.
$mapType = function (string $t): string {
    switch ($t) {
        case 'binary':
        case 'frequency':
        case 'intensity':
        case 'ordinal':
        case 'sentiment':
            return 'likert';
        case 'category':
            return 'single';
        default:
            return 'open';
    }
};
$cleanCols = [];
$cleanCols[] = ['name' => 'respondent_ref', 'type' => 'open'];
foreach ($schema as $col) {
    $name = clean_string((string)($col['var_name'] ?? $col['label'] ?? 'col'), 200);
    if ($name === '') continue;
    $type = $mapType((string)($col['type'] ?? ''));
    $cleanCols[] = ['name' => $name, 'type' => $type];
}

// Build the 2D data array, one row per response.
$data = [];
foreach ($resps as $r) {
    $rid = (int)$r['id'];
    $row = [];
    $row[] = $r['respondent_ref'] !== null ? (string)$r['respondent_ref'] : ('R' . $rid);
    foreach ($schema as $col) {
        $vid = (int)($col['variable_id'] ?? 0);
        $val = $byResp[$rid][$vid] ?? '';
        // For numeric-ish columns, coerce to int/float when possible.
        $colType = (string)($col['type'] ?? '');
        if (in_array($colType, ['binary','frequency','intensity','ordinal'], true) && $val !== '' && is_numeric($val)) {
            $row[] = (float)$val == (int)$val ? (int)$val : (float)$val;
        } else {
            $row[] = (string)$val;
        }
    }
    $data[] = $row;
}

if (count($data) === 0) fail('mm_no_rows', 'The dataset has no rows.');

// Tier check (same caps the main datasets endpoint uses).
$current = (int)$pdo->query('SELECT COUNT(*) FROM datasets WHERE owner_id = ' . (int)$user['id'])->fetch()['COUNT(*)'];
require_under_limit((int)$user['id'], 'max_datasets', $current);
require_under_limit((int)$user['id'], 'max_rows_per_dataset', 0, count($data));

$settings = ['likertPoints' => 5, 'likertLow' => 'Strongly disagree', 'likertHigh' => 'Strongly agree'];

$title = $titleIn !== ''
    ? $titleIn
    : (clean_string((string)$project['title'], 200) . ' - mixed-methods dataset');

$jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
if ($jsonData === false || strlen($jsonData) > 10 * 1024 * 1024) {
    fail('payload_too_large', 'Dataset exceeds the 10 MB size limit.', 413);
}

$ins = $pdo->prepare(
    'INSERT INTO datasets
        (owner_id, title, source_filename, source_format, row_count, column_count, column_meta, settings, data)
     VALUES
        (:uid, :title, :sfn, NULL, :rc, :cc, :cm, :st, :d)'
);
$ins->execute([
    ':uid'   => $uid,
    ':title' => $title,
    ':sfn'   => 'mm_project_' . $projectId,
    ':rc'    => count($data),
    ':cc'    => count($cleanCols),
    ':cm'    => json_encode($cleanCols, JSON_UNESCAPED_UNICODE),
    ':st'    => json_encode($settings, JSON_UNESCAPED_UNICODE),
    ':d'     => $jsonData,
]);

$datasetId = (int)$pdo->lastInsertId();

json_out([
    'ok'         => true,
    'dataset_id' => $datasetId,
    'title'      => $title,
    'row_count'  => count($data),
    'column_count' => count($cleanCols),
]);
