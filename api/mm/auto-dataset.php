<?php
// POST /api/mm/auto-dataset.php
// Body: { project_id }
//
// Creates a regular ReliCheck dataset directly from the project's
// mm_text_responses rows. Unlike save-to-datasets.php, this endpoint does NOT
// require the Coded Variables table (mm_structured_datasets) to exist. It is
// the bridge that lets the full ReliCheck analytics dashboard (Strength Index,
// Reliability, Validity, Description, Compare, Predictors, etc.) run on any
// MM Studio project including paste/manual ingest projects.
//
// Behavior:
//   - Idempotent. If mm_projects.dataset_id already points at a dataset row
//     this user owns, returns that dataset_id.
//   - Otherwise builds a dataset with columns:
//       respondent_ref (open)
//       numeric_value  (likert)  - if any rows have a numeric value
//       group_value    (single)  - if any rows have a group label
//       response_text  (open)
//   - Links the new dataset_id back to mm_projects so future Quant Analysis
//     opens reuse the same dataset.
//
// Phase 178h.

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
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
$project = mm_require_project($pdo, $uid, $projectId);

// Idempotency: if a linked dataset already exists, return it.
$existingId = isset($project['dataset_id']) && $project['dataset_id'] !== null
    ? (int)$project['dataset_id']
    : 0;
if ($existingId > 0) {
    $own = $pdo->prepare('SELECT id, row_count, column_count FROM datasets WHERE id = :d AND owner_id = :u LIMIT 1');
    $own->execute([':d' => $existingId, ':u' => $uid]);
    $existing = $own->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        json_out([
            'ok'           => true,
            'dataset_id'   => (int)$existing['id'],
            'reused'       => true,
            'row_count'    => (int)$existing['row_count'],
            'column_count' => (int)$existing['column_count'],
        ]);
    }
}

// Load the project's text responses.
$rstmt = $pdo->prepare(
    'SELECT id, respondent_ref, group_value, numeric_value, text
     FROM mm_text_responses
     WHERE project_id = :p
     ORDER BY id ASC
     LIMIT 50000'
);
$rstmt->execute([':p' => $projectId]);
$rows = $rstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($rows) === 0) {
    fail('mm_no_rows', 'No responses found for this project. Import data in Step 2 (Structure Data) first.');
}

// Inspect what columns make sense based on the data shape.
$hasNumeric = false;
$hasGroup   = false;
$hasText    = false;
foreach ($rows as $r) {
    if ($r['numeric_value'] !== null && $r['numeric_value'] !== '') $hasNumeric = true;
    if ($r['group_value']   !== null && trim((string)$r['group_value']) !== '') $hasGroup = true;
    if ($r['text']          !== null && trim((string)$r['text'])        !== '') $hasText = true;
    if ($hasNumeric && $hasGroup && $hasText) break;
}

$columns = [];
$columns[] = ['name' => 'respondent_ref', 'type' => 'open'];
if ($hasNumeric) $columns[] = ['name' => 'numeric_value', 'type' => 'likert'];
if ($hasGroup)   $columns[] = ['name' => 'group_value',   'type' => 'single'];
if ($hasText)    $columns[] = ['name' => 'response_text', 'type' => 'open'];

$data = [];
foreach ($rows as $r) {
    $row = [];
    $row[] = $r['respondent_ref'] !== null ? (string)$r['respondent_ref'] : ('R' . (int)$r['id']);
    if ($hasNumeric) {
        $v = $r['numeric_value'];
        if ($v === null || $v === '') {
            $row[] = '';
        } else {
            $f = (float)$v;
            $row[] = (float)$f == (int)$f ? (int)$f : $f;
        }
    }
    if ($hasGroup) {
        $row[] = $r['group_value'] !== null ? (string)$r['group_value'] : '';
    }
    if ($hasText) {
        $row[] = $r['text'] !== null ? (string)$r['text'] : '';
    }
    $data[] = $row;
}

// Tier checks (same as the main datasets endpoint).
$cur = (int)$pdo->query('SELECT COUNT(*) FROM datasets WHERE owner_id = ' . $uid)->fetch()['COUNT(*)'];
require_under_limit($uid, 'max_datasets', $cur);
require_under_limit($uid, 'max_rows_per_dataset', 0, count($data));

$jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
if ($jsonData === false || strlen($jsonData) > 10 * 1024 * 1024) {
    fail('payload_too_large', 'Dataset exceeds the 10 MB size limit.', 413);
}

$settings = [
    'likertPoints' => 5,
    'likertLow'    => 'Strongly disagree',
    'likertHigh'   => 'Strongly agree',
];

$title = clean_string((string)$project['title'], 180);
if ($title === '') $title = 'MM project ' . $projectId;
$title .= ' - quant dataset';

$ins = $pdo->prepare(
    'INSERT INTO datasets
        (owner_id, title, source_filename, source_format, row_count, column_count, column_meta, settings, data)
     VALUES
        (:uid, :title, :sfn, NULL, :rc, :cc, :cm, :st, :d)'
);
$ins->execute([
    ':uid'   => $uid,
    ':title' => $title,
    ':sfn'   => 'mm_auto_' . $projectId,
    ':rc'    => count($data),
    ':cc'    => count($columns),
    ':cm'    => json_encode($columns, JSON_UNESCAPED_UNICODE),
    ':st'    => json_encode($settings, JSON_UNESCAPED_UNICODE),
    ':d'     => $jsonData,
]);

$datasetId = (int)$pdo->lastInsertId();

// Link back to the MM project so subsequent Quant Analysis opens reuse this
// dataset rather than creating duplicates.
try {
    $upd = $pdo->prepare('UPDATE mm_projects SET dataset_id = :d WHERE id = :p AND user_id = :u');
    $upd->execute([':d' => $datasetId, ':p' => $projectId, ':u' => $uid]);
} catch (Throwable $e) {
    // Non-fatal: the dataset exists; future opens will just re-create. Log
    // server-side via error_log; do not break the response.
    error_log('mm/auto-dataset: link-back failed for project ' . $projectId . ': ' . $e->getMessage());
}

json_out([
    'ok'           => true,
    'dataset_id'   => $datasetId,
    'reused'       => false,
    'row_count'    => count($data),
    'column_count' => count($columns),
    'title'        => $title,
    'columns'      => array_map(function ($c) { return $c['name']; }, $columns),
]);
