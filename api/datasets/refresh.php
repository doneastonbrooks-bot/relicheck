<?php
// POST /api/datasets/refresh.php
// Body: { id, source_filename?, data, column_meta? }
//   - id:          existing dataset id (owner-only)
//   - data:        replacement [[row1Cells...], ...]
//   - column_meta: optional. If present, replaces the meta; otherwise the
//                   existing column_meta is preserved (typical refresh
//                   case: same schema, more rows).
//
// Replaces the row data on an existing dataset without changing its id.
// Same limits as create.php (200 columns, 50k rows). Bumps updated_at.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_tiers.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing or invalid dataset id.');

$pdo = db();
$stmt = $pdo->prepare('SELECT id, owner_id, column_meta FROM datasets WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Dataset not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) {
    fail('forbidden', 'You do not own this dataset.', 403);
}

$data = is_array($body['data'] ?? null) ? $body['data'] : null;
if ($data === null) fail('bad_data', 'data is required.');
if (count($data) > 50000) fail('too_many_rows', 'Datasets are limited to 50,000 rows.');

// Tier check: per-dataset rows.
require_under_limit((int)$user['id'], 'max_rows_per_dataset', 0, count($data));

// Optional column_meta replacement. If absent, preserve existing.
$colMetaJson = null;
$colCount    = null;
if (array_key_exists('column_meta', $body)) {
    if (!is_array($body['column_meta'])) fail('bad_column_meta', 'column_meta must be an array.');
    if (count($body['column_meta']) > 200) fail('too_many_columns', 'Datasets are limited to 200 columns.');
    $cleanCols = [];
    foreach ($body['column_meta'] as $c) {
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
        if (!empty($c['construct'])) {
            $entry['construct'] = clean_string((string)$c['construct'], 200);
        }
        $cleanCols[] = $entry;
    }
    $colMetaJson = json_encode($cleanCols, JSON_UNESCAPED_UNICODE);
    $colCount    = count($cleanCols);
}

// Optional source filename + size update.
$srcFilename = null;
if (array_key_exists('source_filename', $body)) {
    $srcFilename = clean_string((string)$body['source_filename'], 255);
}

$dataJson = json_encode($data, JSON_UNESCAPED_UNICODE);
if ($dataJson === false) fail('bad_data', 'data could not be encoded: ' . json_last_error_msg());

$sql = 'UPDATE datasets SET data = :data, row_count = :rc';
$params = [':data' => $dataJson, ':rc' => count($data), ':id' => $id];
if ($colMetaJson !== null) {
    $sql .= ', column_meta = :cm, column_count = :cc';
    $params[':cm'] = $colMetaJson;
    $params[':cc'] = $colCount;
}
if ($srcFilename !== null) {
    $sql .= ', source_filename = :sf';
    $params[':sf'] = $srcFilename;
}
$sql .= ' WHERE id = :id';
$pdo->prepare($sql)->execute($params);

json_out([
    'ok'        => true,
    'id'        => $id,
    'row_count' => count($data),
]);
