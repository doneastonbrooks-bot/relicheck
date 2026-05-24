<?php
// POST /api/datasets/update_columns.php
// Body: { id, column_meta }
//   - id:          existing dataset id (owner-only)
//   - column_meta: [{ name, type, reverse?, options?, construct? }, ...]
//
// Updates JUST the column_meta on a dataset without touching its rows.
// Used by the in-place column-role editor so testers can fix stale
// classifications (e.g. a categorical column originally read as Likert)
// without having to re-upload the whole file.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('POST');
check_origin();
$user = require_auth();

$body = read_json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing or invalid dataset id.');

$cols = is_array($body['column_meta'] ?? null) ? $body['column_meta'] : null;
if ($cols === null) fail('bad_column_meta', 'column_meta is required.');
if (count($cols) > 200) fail('too_many_columns', 'Datasets are limited to 200 columns.');

$pdo = db();
$stmt = $pdo->prepare('SELECT id, owner_id, column_meta FROM datasets WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) fail('not_found', 'Dataset not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) {
    fail('forbidden', 'You do not own this dataset.', 403);
}

// Reuse the same cleaning + validation as create.php / refresh.php so
// the saved column_meta has identical shape regardless of entry point.
$clean = [];
foreach ($cols as $c) {
    if (!is_array($c)) continue;
    $type = $c['type'] ?? 'ignore';
    if (!in_array($type, ['likert', 'single', 'multi', 'open', 'ignore'], true)) {
        $type = 'ignore';
    }
    $entry = [
        'name'    => clean_string((string)($c['name'] ?? ''), 200),
        'type'    => $type,
        'reverse' => !empty($c['reverse']),
    ];
    if (in_array($type, ['single', 'multi'], true) && isset($c['options']) && is_array($c['options'])) {
        $entry['options'] = array_values(array_map(fn($o) => clean_string((string)$o, 200), $c['options']));
    }
    if (!empty($c['construct'])) {
        $entry['construct'] = clean_string((string)$c['construct'], 200);
    }
    $clean[] = $entry;
}

$json = json_encode($clean, JSON_UNESCAPED_UNICODE);
if ($json === false) fail('bad_column_meta', 'column_meta could not be encoded: ' . json_last_error_msg());

$pdo->prepare('UPDATE datasets SET column_meta = :cm, column_count = :cc WHERE id = :id')
    ->execute([':cm' => $json, ':cc' => count($clean), ':id' => $id]);

json_out([
    'ok'           => true,
    'id'           => $id,
    'column_count' => count($clean),
    'column_meta'  => $clean,
]);
