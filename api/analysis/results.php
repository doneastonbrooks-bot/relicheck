<?php
// GET    /api/analysis/results.php?project_id=N   list saved results for a project
// GET    /api/analysis/results.php?id=N           read one saved result
// POST   /api/analysis/results.php  { project_id, tool_key, inputs?, result?, summary? }
//          save a result snapshot (latest-per-tool: replaces any prior
//          snapshot for the same project + tool_key).
// DELETE /api/analysis/results.php  { id }         delete one saved result

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_analysis_studio.php';

require_method('GET', 'POST', 'DELETE');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
analysis_ensure_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $rid = (int)$_GET['id'];
        $stmt = $pdo->prepare(
            'SELECT r.* FROM analysis_results r
               JOIN analysis_projects p ON p.id = r.project_id
              WHERE r.id = :rid AND p.user_id = :uid LIMIT 1'
        );
        $stmt->execute([':rid' => $rid, ':uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) fail('result_not_found', 'Saved result not found.', 404);
        json_out(['ok' => true, 'result' => analysis_result_out($row)]);
    }
    $projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    if ($projectId <= 0) fail('bad_input', 'project_id is required.');
    analysis_require_project($pdo, $uid, $projectId); // ownership
    $stmt = $pdo->prepare(
        'SELECT * FROM analysis_results WHERE project_id = :pid ORDER BY updated_at DESC LIMIT 200'
    );
    $stmt->execute([':pid' => $projectId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out  = [];
    foreach ($rows as $r) $out[] = analysis_result_out($r);
    json_out(['ok' => true, 'results' => $out]);
}

$body = read_json_body();

if ($method === 'DELETE') {
    $rid = isset($body['id']) ? (int)$body['id'] : 0;
    if ($rid <= 0) fail('bad_input', 'id is required.');
    $stmt = $pdo->prepare(
        'DELETE r FROM analysis_results r
           JOIN analysis_projects p ON p.id = r.project_id
          WHERE r.id = :rid AND p.user_id = :uid'
    );
    $stmt->execute([':rid' => $rid, ':uid' => $uid]);
    json_out(['ok' => true, 'deleted' => true]);
}

// POST: save snapshot (latest-per-tool).
$projectId = isset($body['project_id']) ? (int)$body['project_id'] : 0;
$toolKey   = clean_string((string)($body['tool_key'] ?? ''), 64);
if ($projectId <= 0 || $toolKey === '') fail('bad_input', 'project_id and tool_key are required.');
$project = analysis_require_project($pdo, $uid, $projectId);

$inputs  = isset($body['inputs']) ? json_encode($body['inputs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
$result  = isset($body['result']) ? json_encode($body['result'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
$summary = clean_string((string)($body['summary'] ?? ''), 600);

// Replace any prior snapshot for this tool in this project.
$del = $pdo->prepare('DELETE FROM analysis_results WHERE project_id = :pid AND tool_key = :tk');
$del->execute([':pid' => $projectId, ':tk' => $toolKey]);

$ins = $pdo->prepare(
    'INSERT INTO analysis_results (project_id, kind, tool_key, inputs_json, result_json, summary)
     VALUES (:pid, :kind, :tk, :inp, :res, :sum)'
);
$ins->execute([
    ':pid'  => $projectId,
    ':kind' => (string)$project['kind'],
    ':tk'   => $toolKey,
    ':inp'  => $inputs,
    ':res'  => $result,
    ':sum'  => $summary !== '' ? $summary : null,
]);
$rid = (int)$pdo->lastInsertId();

// Touch the project so it sorts to the top of the picker.
$pdo->prepare('UPDATE analysis_projects SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')
    ->execute([':id' => $projectId]);

$fresh = $pdo->prepare('SELECT * FROM analysis_results WHERE id = :id');
$fresh->execute([':id' => $rid]);
json_out(['ok' => true, 'result' => analysis_result_out($fresh->fetch(PDO::FETCH_ASSOC) ?: [])]);
