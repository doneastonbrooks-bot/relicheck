<?php
// GET    /api/analysis/project.php?id=N        read one project (with dataset meta)
// PATCH  /api/analysis/project.php  { id, title?, notes?, dataset_id?, status? }
// DELETE /api/analysis/project.php  { id }      archive (soft delete)

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_analysis_studio.php';

require_method('GET', 'PATCH', 'POST', 'DELETE');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
analysis_ensure_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) fail('bad_input', 'id is required.');
    $row = analysis_require_project($pdo, $uid, $id);
    $out = analysis_project_out($row);
    // Attach light dataset metadata so the workspace can show row/col counts
    // without a second round-trip.
    $out['dataset'] = null;
    if ($out['dataset_id']) {
        $ds = $pdo->prepare('SELECT id, title, row_count, column_count FROM datasets WHERE id = :id AND owner_id = :uid LIMIT 1');
        $ds->execute([':id' => $out['dataset_id'], ':uid' => $uid]);
        $drow = $ds->fetch(PDO::FETCH_ASSOC);
        if ($drow) {
            $out['dataset'] = [
                'id'           => (int)$drow['id'],
                'title'        => (string)$drow['title'],
                'row_count'    => (int)$drow['row_count'],
                'column_count' => (int)$drow['column_count'],
            ];
        }
    }
    json_out(['ok' => true, 'project' => $out]);
}

// State-changing methods.
$body = read_json_body();
$id   = isset($body['id']) ? (int)$body['id'] : 0;
if ($id <= 0) fail('bad_input', 'id is required.');
$row = analysis_require_project($pdo, $uid, $id);

if ($method === 'DELETE') {
    $stmt = $pdo->prepare('UPDATE analysis_projects SET status = "archived" WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $id, ':uid' => $uid]);
    json_out(['ok' => true, 'archived' => true]);
}

// PATCH / POST: partial update.
$sets = [];
$args = [':id' => $id, ':uid' => $uid];
if (array_key_exists('title', $body)) {
    $t = clean_string((string)$body['title'], 200);
    if ($t !== '') { $sets[] = 'title = :t'; $args[':t'] = $t; }
}
if (array_key_exists('notes', $body)) {
    $n = clean_string((string)$body['notes'], 4000);
    $sets[] = 'notes = :n'; $args[':n'] = $n !== '' ? $n : null;
}
if (array_key_exists('dataset_id', $body)) {
    $did = (int)$body['dataset_id'];
    if ($did > 0) {
        $own = $pdo->prepare('SELECT id FROM datasets WHERE id = :id AND owner_id = :uid LIMIT 1');
        $own->execute([':id' => $did, ':uid' => $uid]);
        if (!$own->fetch()) fail('bad_dataset', 'Dataset not found or not owned.', 404);
        $sets[] = 'dataset_id = :d'; $args[':d'] = $did;
    } else {
        $sets[] = 'dataset_id = NULL';
    }
}
if (array_key_exists('status', $body)) {
    $s = clean_string((string)$body['status'], 16);
    if (in_array($s, ['draft', 'active', 'archived'], true)) { $sets[] = 'status = :s'; $args[':s'] = $s; }
}
if (!$sets) fail('nothing_to_update', 'No updatable fields supplied.');

$sql  = 'UPDATE analysis_projects SET ' . implode(', ', $sets) . ' WHERE id = :id AND user_id = :uid';
$stmt = $pdo->prepare($sql);
$stmt->execute($args);

$fresh = $pdo->prepare('SELECT * FROM analysis_projects WHERE id = :id');
$fresh->execute([':id' => $id]);
json_out(['ok' => true, 'project' => analysis_project_out($fresh->fetch(PDO::FETCH_ASSOC) ?: [])]);
