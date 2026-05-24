<?php
// GET /api/folders/list.php
// Returns the current user's folders ordered by sort_order ASC, name ASC.
// Defensive: returns an empty list (not 500) if the folders table does not
// exist yet, so the dashboard works fine before the Phase 37 migration runs.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET');
$user = require_auth();

$pdo = db();

$rows = [];
try {
    $stmt = $pdo->prepare(
        'SELECT f.id, f.name, f.color, f.sort_order, f.created_at, f.updated_at,
                (SELECT COUNT(*) FROM surveys s
                  WHERE s.folder_id = f.id AND s.archived_at IS NULL) AS survey_count
           FROM folders f
          WHERE f.owner_id = :uid
          ORDER BY f.sort_order ASC, f.name ASC'
    );
    $stmt->execute([':uid' => (int)$user['id']]);
    while ($r = $stmt->fetch()) {
        $rows[] = [
            'id'           => (int)$r['id'],
            'name'         => (string)$r['name'],
            'color'        => (string)$r['color'],
            'sort_order'   => (int)$r['sort_order'],
            'survey_count' => (int)$r['survey_count'],
            'created_at'   => $r['created_at'],
            'updated_at'   => $r['updated_at'],
        ];
    }
} catch (Throwable $e) {
    // folders table missing (pre-migration). Surface a hint so the client can
    // gracefully render the rail with a "no folders yet" placeholder.
    json_out(['folders' => [], 'note' => 'folders_unavailable']);
}

json_out(['folders' => $rows]);
