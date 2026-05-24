<?php
// GET    /api/mm/project.php?id=N
// PATCH  /api/mm/project.php   { id, title?, notes?, status? }
// DELETE /api/mm/project.php   { id }

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'PATCH', 'DELETE');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) fail('bad_input', 'Missing project id.');
    // Phase 181: GET is widened to accepted coders. PATCH and DELETE below
    // stay owner-only via mm_require_project.
    $project = mm_require_project_or_coder($pdo, $uid, $id);

    $counts = [
        'responses' => mm_response_count($pdo, $id),
    ];
    $catStmt = $pdo->prepare('SELECT COUNT(*) FROM mm_theme_categories WHERE project_id = :p');
    $catStmt->execute([':p' => $id]);
    $counts['categories'] = (int)$catStmt->fetchColumn();

    $codedStmt = $pdo->prepare('SELECT COUNT(*) FROM mm_coded_responses WHERE project_id = :p');
    $codedStmt->execute([':p' => $id]);
    $counts['coded'] = (int)$codedStmt->fetchColumn();

    $projectOut = mm_project_out($project);
    // Surface the role so the front-end can branch (blind coding UI for coders).
    $projectOut['mm_role'] = (string)($project['mm_role'] ?? 'owner');
    json_out(['ok' => true, 'project' => $projectOut, 'counts' => $counts]);
}

if ($method === 'PATCH') {
    $body = read_json_body();
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) fail('bad_input', 'Missing project id.');
    mm_require_project($pdo, $uid, $id);

    $fields = [];
    $params = [':id' => $id];

    if (array_key_exists('title', $body)) {
        $title = clean_string((string)$body['title'], 200);
        if ($title === '') fail('bad_input', 'Title cannot be empty.');
        $fields[] = 'title = :t';
        $params[':t'] = $title;
    }
    if (array_key_exists('notes', $body)) {
        $notes = clean_string((string)$body['notes'], 4000);
        $fields[] = 'notes = :n';
        $params[':n'] = $notes !== '' ? $notes : null;
    }
    if (array_key_exists('status', $body)) {
        $st = clean_string((string)$body['status'], 32);
        if (!in_array($st, ['draft', 'active', 'archived'], true)) {
            fail('bad_input', 'Status must be draft, active, or archived.');
        }
        $fields[] = 'status = :st';
        $params[':st'] = $st;
    }

    if (count($fields) === 0) json_out(['ok' => true, 'changed' => 0]);

    $sql = 'UPDATE mm_projects SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_out(['ok' => true, 'changed' => $stmt->rowCount()]);
}

// DELETE
$body = read_json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_input', 'Missing project id.');
mm_require_project($pdo, $uid, $id);

$stmt = $pdo->prepare('DELETE FROM mm_projects WHERE id = :id');
$stmt->execute([':id' => $id]);
json_out(['ok' => true]);
