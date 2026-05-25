<?php
// /api/mm/derived-variables.php
//
//   GET  ?project_id=N
//        Returns this project's derived variables, with their computed
//        values, so the frontend can include them in analysis dropdowns
//        as if they were original dataset columns.
//
//   POST { project_id, action: 'create' | 'delete',
//          name?, op?, spec?, id? }
//        - create: name, op, spec required. Server computes values_json
//          from the dataset that the project is linked to.
//        - delete: id required.
//
// Phase 1 ops:
//   - 'mean': spec = { items: ['col_3', 'col_4', 'col_5'] }
//             value = mean of the named columns per respondent
//             (null when fewer than half the items have values)
//   - 'sum':  same as mean but sum, with the same missing-data rule
//
// Phase 2 will add 'recode', 'bin', 'standardize'. The op field is wide
// enough to accept those without a schema change.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

// ---------- GET: list ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project_or_coder($pdo, $uid, $projectId);

    $stmt = $pdo->prepare(
        'SELECT id, name, op, spec_json, values_json, created_at, updated_at
           FROM mm_derived_variables
          WHERE project_id = :p
       ORDER BY id ASC'
    );
    $stmt->execute([':p' => $projectId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $spec   = json_decode((string)$r['spec_json'], true);
        $values = json_decode((string)$r['values_json'], true);
        $out[] = [
            'id'         => (int)$r['id'],
            'name'       => (string)$r['name'],
            'op'         => (string)$r['op'],
            'spec'       => is_array($spec) ? $spec : [],
            'values'     => is_array($values) ? $values : [],
            'created_at' => (string)$r['created_at'],
            'updated_at' => (string)$r['updated_at'],
        ];
    }
    json_out(['ok' => true, 'variables' => $out]);
}

// ---------- POST ----------
$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$action    = clean_string((string)($body['action'] ?? ''), 16);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) fail('bad_input', 'Missing id for delete.');
    $stmt = $pdo->prepare('DELETE FROM mm_derived_variables WHERE id = :i AND project_id = :p');
    $stmt->execute([':i' => $id, ':p' => $projectId]);
    json_out(['ok' => true, 'deleted' => $stmt->rowCount()]);
}

if ($action !== 'create') {
    fail('bad_input', "Unknown action '$action'. Use create or delete.");
}

// ---------- create ----------
$name = clean_string((string)($body['name'] ?? ''), 120);
$op   = clean_string((string)($body['op'] ?? ''), 20);
$spec = is_array($body['spec'] ?? null) ? $body['spec'] : [];

if ($name === '') fail('bad_input', 'A variable name is required.');
if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
    fail('bad_input', "Variable name must start with a letter and contain only letters, numbers, and underscores (no spaces or special characters).");
}
if (!in_array($op, ['mean', 'sum'], true)) {
    fail('bad_input', "Operation '$op' is not supported yet. Phase 1 ships 'mean' and 'sum'; the others come in Phase 2.");
}

$items = isset($spec['items']) && is_array($spec['items']) ? $spec['items'] : [];
$items = array_values(array_filter(array_map(function ($it) {
    return is_string($it) ? clean_string($it, 80) : '';
}, $items)));
if (count($items) < 2) {
    fail('bad_input', "A composite ($op) needs at least 2 source items.");
}
if (count($items) > 50) {
    fail('bad_input', "Too many source items (max 50).");
}

// Find the project's dataset to read source-column data from.
$projStmt = $pdo->prepare('SELECT id, dataset_id FROM mm_projects WHERE id = :p');
$projStmt->execute([':p' => $projectId]);
$proj = $projStmt->fetch(PDO::FETCH_ASSOC);
$datasetId = $proj && $proj['dataset_id'] !== null ? (int)$proj['dataset_id'] : 0;
if ($datasetId <= 0) {
    fail('no_dataset', 'This project is not linked to a dataset. Derived variables need a linked dataset to compute against.');
}

// Pull the dataset's column_meta and data.
$dsStmt = $pdo->prepare('SELECT column_meta, data FROM datasets WHERE id = :i AND owner_id = :u');
$dsStmt->execute([':i' => $datasetId, ':u' => $uid]);
$ds = $dsStmt->fetch(PDO::FETCH_ASSOC);
if (!$ds) fail('no_dataset', 'Linked dataset is not accessible.');

$cols = json_decode((string)$ds['column_meta'], true);
$rows = json_decode((string)$ds['data'], true);
if (!is_array($cols) || !is_array($rows)) {
    fail('bad_dataset', 'Dataset is missing column metadata or data.');
}

// Resolve each requested item to its column index. The frontend sends
// column IDs in the form 'col_<i>' that adaptDatasetToSurvey uses; we
// accept both 'col_<i>' and bare column names for flexibility.
$idxByName = [];
foreach ($cols as $i => $c) {
    if (is_array($c) && isset($c['name'])) {
        $idxByName[(string)$c['name']] = $i;
    }
    $idxByName['col_' . $i] = $i;
}
$itemIndexes = [];
foreach ($items as $it) {
    if (!array_key_exists($it, $idxByName)) {
        fail('bad_input', "Source item '$it' was not found in the linked dataset.");
    }
    $itemIndexes[] = $idxByName[$it];
}

// Compute per-row values. Missing values are skipped; if fewer than half
// the items have a numeric value on a given row, the composite is null
// (rather than misleadingly averaging a small subset).
$values = [];
$minRequired = (int)ceil(count($itemIndexes) / 2);
foreach ($rows as $row) {
    if (!is_array($row)) { $values[] = null; continue; }
    $count = 0;
    $sum   = 0.0;
    foreach ($itemIndexes as $idx) {
        if (!array_key_exists($idx, $row)) continue;
        $v = $row[$idx];
        if ($v === null || $v === '') continue;
        if (!is_numeric($v)) continue;
        $sum += (float)$v;
        $count++;
    }
    if ($count < $minRequired) {
        $values[] = null;
    } elseif ($op === 'mean') {
        $values[] = $sum / $count;
    } else { // sum
        $values[] = $sum;
    }
}

// Persist. ON DUPLICATE KEY (uq_project_name) updates the values so a
// recomputed variable replaces the old version cleanly.
$specClean = ['items' => $items];
$specJson  = json_encode($specClean, JSON_UNESCAPED_UNICODE);
$valuesJson = json_encode($values, JSON_UNESCAPED_UNICODE);
if ($specJson === false || $valuesJson === false) {
    fail('encode_failed', 'Could not encode the variable spec or values.');
}

$ins = $pdo->prepare(
    'INSERT INTO mm_derived_variables (project_id, name, op, spec_json, values_json)
     VALUES (:p, :n, :o, :s, :v)
     ON DUPLICATE KEY UPDATE op = VALUES(op), spec_json = VALUES(spec_json), values_json = VALUES(values_json)'
);
$ins->execute([
    ':p' => $projectId, ':n' => $name, ':o' => $op,
    ':s' => $specJson,  ':v' => $valuesJson,
]);
$id = (int)$pdo->lastInsertId();
if ($id <= 0) {
    // ON DUPLICATE KEY UPDATE path; look up the existing id.
    $look = $pdo->prepare('SELECT id FROM mm_derived_variables WHERE project_id = :p AND name = :n');
    $look->execute([':p' => $projectId, ':n' => $name]);
    $id = (int)($look->fetchColumn() ?: 0);
}

json_out([
    'ok'         => true,
    'id'         => $id,
    'name'       => $name,
    'op'         => $op,
    'spec'       => $specClean,
    'values'     => $values,
    'n_computed' => count(array_filter($values, fn($v) => $v !== null)),
    'n_missing'  => count(array_filter($values, fn($v) => $v === null)),
]);
