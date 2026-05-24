<?php
// GET /api/datasets/list.php
// Returns the current user's datasets without the heavy data column.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$stmt = db()->prepare(
    'SELECT id, title, source_filename, source_format, row_count, column_count,
            column_meta, settings, created_at, updated_at
       FROM datasets
      WHERE owner_id = :uid
      ORDER BY updated_at DESC'
);
$stmt->execute([':uid' => $user['id']]);

$rows = [];
while ($r = $stmt->fetch()) {
    $cm = json_decode((string)$r['column_meta'], true) ?: [];
    $likertCount = 0;
    foreach ($cm as $c) if (($c['type'] ?? '') === 'likert') $likertCount++;
    $rows[] = [
        'id'              => (int)$r['id'],
        'title'           => $r['title'],
        'source_filename' => $r['source_filename'],
        'source_format'   => $r['source_format'],
        'row_count'       => (int)$r['row_count'],
        'column_count'    => (int)$r['column_count'],
        'likert_count'    => $likertCount,
        'column_meta'     => $cm,
        'settings'        => json_decode((string)$r['settings'], true) ?: [],
        'created_at'      => $r['created_at'],
        'updated_at'      => $r['updated_at'],
    ];
}

json_out(['datasets' => $rows]);
