<?php
// GET  /api/mm/value-labels.php?project_id=N
//        -> { ok, has_table, labels: { "<var>": { "<value>": "<label>" } } }
// POST /api/mm/value-labels.php
//        { project_id, items: [ { var_name, value, label } ] }   (batch upsert)
//        An empty/blank label DELETES that value's label.
//        -> { ok, saved, cleared }
//
// Maps raw categorical codes (SELTraining "0") to human labels ("No Training")
// so the whole studio can show labels instead of codes. Isolated table
// (mm_value_labels); degrades to has_table:false until the migration is run.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'POST');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

function vl_table_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM mm_value_labels LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project_or_coder($pdo, $uid, $projectId);

    if (!vl_table_exists($pdo)) {
        json_out(['ok' => true, 'has_table' => false, 'labels' => new stdClass()]);
    }
    $stmt = $pdo->prepare('SELECT var_name, value_key, label FROM mm_value_labels WHERE project_id = :p');
    $stmt->execute([':p' => $projectId]);
    $labels = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $labels[(string)$r['var_name']][(string)$r['value_key']] = (string)$r['label'];
    }
    json_out(['ok' => true, 'has_table' => true, 'labels' => $labels ?: new stdClass()]);
}

// ---- POST (batch upsert) ----
check_origin();
$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
mm_require_project($pdo, $uid, $projectId);

if (!vl_table_exists($pdo)) {
    fail('mm_no_value_labels_table', 'Value labels need a one-time database migration (schema_value_labels.sql).', 500);
}

$items = $body['items'] ?? [];
if (!is_array($items) || count($items) === 0) fail('bad_input', 'items (array) is required.');

$ins = $pdo->prepare(
    'INSERT INTO mm_value_labels (project_id, var_name, value_key, label)
     VALUES (:p, :v, :k, :l)
     ON DUPLICATE KEY UPDATE label = VALUES(label)'
);
$del = $pdo->prepare(
    'DELETE FROM mm_value_labels WHERE project_id = :p AND var_name = :v AND value_key = :k'
);

$saved = 0; $cleared = 0;
foreach ($items as $it) {
    if (!is_array($it)) continue;
    $var = clean_string((string)($it['var_name'] ?? ''), 190);
    $key = clean_string((string)($it['value'] ?? ''), 190);
    $lab = clean_string((string)($it['label'] ?? ''), 300);
    if ($var === '' || $key === '') continue;
    if ($lab === '') {
        $del->execute([':p' => $projectId, ':v' => $var, ':k' => $key]);
        $cleared++;
    } else {
        $ins->execute([':p' => $projectId, ':v' => $var, ':k' => $key, ':l' => $lab]);
        $saved++;
    }
}

json_out(['ok' => true, 'saved' => $saved, 'cleared' => $cleared]);
