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

// Self-bootstrap the storage table on first call so the schema migration
// does not need to be applied manually. CREATE TABLE IF NOT EXISTS is
// idempotent; subsequent calls are no-ops. The DDL here must match the
// migration file at db/schema_phase180_derived_variables.sql.
try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS mm_derived_variables (
            id             BIGINT NOT NULL AUTO_INCREMENT,
            project_id     INT NOT NULL,
            name           VARCHAR(120) NOT NULL,
            op             VARCHAR(20) NOT NULL,
            spec_json      JSON NOT NULL,
            values_json    JSON NOT NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_project_name (project_id, name),
            KEY idx_project (project_id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
} catch (Throwable $e) {
    // Non-fatal: if CREATE TABLE fails for any reason (permissions, race),
    // subsequent queries will surface a clearer error.
}

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
$VALID_OPS = ['mean', 'sum', 'recode', 'bin', 'standardize'];
if (!in_array($op, $VALID_OPS, true)) {
    fail('bad_input', "Operation '$op' is not supported. Valid: " . implode(', ', $VALID_OPS) . '.');
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

// Resolve column names (or 'col_<i>') to dataset row indexes. Accepts
// both forms for caller flexibility.
$idxByName = [];
foreach ($cols as $i => $c) {
    if (is_array($c) && isset($c['name'])) {
        $idxByName[(string)$c['name']] = $i;
    }
    $idxByName['col_' . $i] = $i;
}
$resolveCol = function ($name) use ($idxByName) {
    if (!array_key_exists($name, $idxByName)) {
        fail('bad_input', "Source column '$name' was not found in the linked dataset.");
    }
    return $idxByName[$name];
};

// Read a numeric cell from a row, returning null on missing or non-numeric.
$readNumeric = function ($row, $idx) {
    if (!is_array($row) || !array_key_exists($idx, $row)) return null;
    $v = $row[$idx];
    if ($v === null || $v === '') return null;
    if (!is_numeric($v)) return null;
    return (float)$v;
};

$values = [];
$specClean = [];

if ($op === 'mean' || $op === 'sum') {
    $items = isset($spec['items']) && is_array($spec['items']) ? $spec['items'] : [];
    $items = array_values(array_filter(array_map(function ($it) {
        return is_string($it) ? clean_string($it, 80) : '';
    }, $items)));
    if (count($items) < 2) fail('bad_input', "A composite ($op) needs at least 2 source items.");
    if (count($items) > 50) fail('bad_input', 'Too many source items (max 50).');
    $itemIndexes = array_map($resolveCol, $items);
    // If fewer than half the items have a numeric value, the composite
    // is null on that row (rather than misleadingly aggregating a small
    // subset). Standard practice in scale construction.
    $minRequired = (int)ceil(count($itemIndexes) / 2);
    foreach ($rows as $row) {
        if (!is_array($row)) { $values[] = null; continue; }
        $count = 0; $sum = 0.0;
        foreach ($itemIndexes as $idx) {
            $n = $readNumeric($row, $idx);
            if ($n === null) continue;
            $sum += $n; $count++;
        }
        if ($count < $minRequired) {
            $values[] = null;
        } elseif ($op === 'mean') {
            $values[] = $sum / $count;
        } else {
            $values[] = $sum;
        }
    }
    $specClean = ['items' => $items];

} elseif ($op === 'recode') {
    $src = clean_string((string)($spec['source'] ?? ''), 80);
    if ($src === '') fail('bad_input', "Recode needs a 'source' column name.");
    $srcIdx = $resolveCol($src);
    $mapping = isset($spec['mapping']) && is_array($spec['mapping']) ? $spec['mapping'] : [];
    if (empty($mapping)) fail('bad_input', "Recode needs a 'mapping' object of old=>new values.");
    // Normalize: keys are strings (JSON), values can be numeric or string.
    // We compare against the raw cell stringified.
    $normMap = [];
    foreach ($mapping as $k => $v) {
        $kStr = (string)$k;
        // Allow numeric new values (most common for Likert reverse) and
        // short string labels for collapse-categories recodes.
        if (is_numeric($v)) {
            $normMap[$kStr] = (float)$v;
        } elseif (is_string($v)) {
            $normMap[$kStr] = clean_string($v, 80);
        } else {
            $normMap[$kStr] = null;
        }
    }
    foreach ($rows as $row) {
        if (!is_array($row) || !array_key_exists($srcIdx, $row)) { $values[] = null; continue; }
        $raw = $row[$srcIdx];
        if ($raw === null || $raw === '') { $values[] = null; continue; }
        $key = is_float($raw) && floor($raw) === $raw ? (string)(int)$raw : (string)$raw;
        if (!array_key_exists($key, $normMap)) {
            // Value not in the mapping. Null it out so the user knows
            // their mapping is incomplete (vs. silently dropping).
            $values[] = null;
            continue;
        }
        $values[] = $normMap[$key];
    }
    $specClean = ['source' => $src, 'mapping' => $normMap];

} elseif ($op === 'bin') {
    $src = clean_string((string)($spec['source'] ?? ''), 80);
    if ($src === '') fail('bad_input', "Bin needs a 'source' column name.");
    $srcIdx = $resolveCol($src);
    $cuts = isset($spec['cutpoints']) && is_array($spec['cutpoints']) ? $spec['cutpoints'] : [];
    $labels = isset($spec['labels']) && is_array($spec['labels']) ? $spec['labels'] : [];
    if (count($cuts) < 1) fail('bad_input', "Bin needs at least 1 cutpoint.");
    if (count($labels) !== count($cuts) + 1) {
        fail('bad_input', 'Bin needs exactly one more label than cutpoints (e.g. 3 cutpoints => 4 labels).');
    }
    $numCuts = array_map('floatval', $cuts);
    // Sanity: cutpoints must be strictly ascending.
    for ($i = 1; $i < count($numCuts); $i++) {
        if ($numCuts[$i] <= $numCuts[$i - 1]) {
            fail('bad_input', 'Cutpoints must be in strictly ascending order.');
        }
    }
    $cleanLabels = array_map(function ($l) { return is_string($l) ? clean_string($l, 80) : ''; }, $labels);
    foreach ($rows as $row) {
        $n = $readNumeric($row, $srcIdx);
        if ($n === null) { $values[] = null; continue; }
        $bin = count($numCuts); // values >= last cutpoint go to the last bin
        for ($i = 0; $i < count($numCuts); $i++) {
            if ($n < $numCuts[$i]) { $bin = $i; break; }
        }
        $values[] = $cleanLabels[$bin] !== '' ? $cleanLabels[$bin] : ('bin_' . $bin);
    }
    $specClean = ['source' => $src, 'cutpoints' => $numCuts, 'labels' => $cleanLabels];

} elseif ($op === 'standardize') {
    $src = clean_string((string)($spec['source'] ?? ''), 80);
    if ($src === '') fail('bad_input', "Standardize needs a 'source' column name.");
    $srcIdx = $resolveCol($src);
    $method = clean_string((string)($spec['method'] ?? 'z'), 8);
    if (!in_array($method, ['z', 't'], true)) fail('bad_input', "Standardize 'method' must be 'z' or 't'.");
    // First pass: compute mean + sd of the source column over non-null rows.
    $raw = [];
    foreach ($rows as $row) {
        $n = $readNumeric($row, $srcIdx);
        if ($n !== null) $raw[] = $n;
    }
    $n = count($raw);
    if ($n < 2) fail('insufficient_data', 'Standardize needs at least 2 non-null source values.');
    $mean = array_sum($raw) / $n;
    $sumSq = 0.0;
    foreach ($raw as $v) { $sumSq += ($v - $mean) * ($v - $mean); }
    $sd = sqrt($sumSq / ($n - 1));
    if ($sd <= 0) fail('insufficient_data', 'Source has zero variance, cannot standardize.');
    // Second pass: emit z-score (or T-score) per row.
    foreach ($rows as $row) {
        $v = $readNumeric($row, $srcIdx);
        if ($v === null) { $values[] = null; continue; }
        $z = ($v - $mean) / $sd;
        $values[] = ($method === 't') ? (10 * $z + 50) : $z;
    }
    $specClean = ['source' => $src, 'method' => $method, 'sd' => $sd, 'mean' => $mean];
}

// Persist. ON DUPLICATE KEY (uq_project_name) updates the values so a
// recomputed variable replaces the old version cleanly.
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
