<?php
// GET  /api/mm/results-to-explain.php?project_id=N
//   Returns all staged results for explanation: {ok, items: [{id, source, data}]}
//
// POST /api/mm/results-to-explain.php
//   Body: {project_id, source, ...stats fields}
//   Stages a new result for qualitative explanation: {ok, id}

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

// ── Ensure the table exists ───────────────────────────────────────────────
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mm_results_to_explain (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id INT UNSIGNED NOT NULL,
            source     VARCHAR(40)  NOT NULL,
            data_json  TEXT         NOT NULL,
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_mm_rte_project (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $e) { /* table already exists or no CREATE privilege — tolerate */ }

// ── GET ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project($pdo, $uid, $projectId);

    try {
        $stmt = $pdo->prepare(
            'SELECT id, source, data_json, created_at
             FROM mm_results_to_explain
             WHERE project_id = :p
             ORDER BY id ASC'
        );
        $stmt->execute([':p' => $projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        json_out(['ok' => true, 'items' => []]);
    }

    $items = [];
    foreach ($rows as $r) {
        $decoded = json_decode((string)$r['data_json'], true);
        $items[] = [
            'id'     => (int)$r['id'],
            'source' => (string)$r['source'],
            'data'   => is_array($decoded) ? $decoded : [],
        ];
    }
    json_out(['ok' => true, 'items' => $items]);
}

// ── POST ──────────────────────────────────────────────────────────────────
$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$source    = (string)($body['source']     ?? '');
if ($projectId <= 0 || $source === '') fail('bad_input', 'project_id and source are required.');
mm_require_project($pdo, $uid, $projectId);

// Strip project_id from the stored payload — it's implicit
$data = $body;
unset($data['project_id']);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO mm_results_to_explain (project_id, source, data_json) VALUES (:p, :s, :d)'
    );
    $stmt->execute([
        ':p' => $projectId,
        ':s' => $source,
        ':d' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $newId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    fail('db_error', 'Could not save result. The mm_results_to_explain table may need to be created via the database migration.');
}

json_out(['ok' => true, 'id' => $newId]);
