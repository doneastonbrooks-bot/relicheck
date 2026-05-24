<?php
// GET /api/datasets/get.php?id=<dataset_id>
// Returns the full dataset, including the data column. Owner-only.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing or invalid dataset id.');

$stmt = db()->prepare(
    'SELECT id, owner_id, title, source_filename, source_format, row_count, column_count,
            column_meta, settings, data, created_at, updated_at
       FROM datasets WHERE id = :id'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) fail('not_found', 'Dataset not found.', 404);
if ((int)$row['owner_id'] !== (int)$user['id']) fail('forbidden', 'You do not own this dataset.', 403);

$data = json_decode((string)$row['data'], true);
if (!is_array($data)) $data = [];

json_out([
    'dataset' => [
        'id'              => (int)$row['id'],
        'title'           => $row['title'],
        'source_filename' => $row['source_filename'],
        'source_format'   => $row['source_format'],
        'row_count'       => (int)$row['row_count'],
        'column_count'    => (int)$row['column_count'],
        'column_meta'     => json_decode((string)$row['column_meta'], true) ?: [],
        'settings'        => json_decode((string)$row['settings'], true) ?: [],
        'data'            => $data,
        'created_at'      => $row['created_at'],
        'updated_at'      => $row['updated_at'],
    ],
]);
