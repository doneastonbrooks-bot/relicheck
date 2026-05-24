<?php
// GET    /api/mm/responses.php?project_id=N&limit=200
// PATCH  /api/mm/responses.php   { project_id, response_id, text?, numeric_value?, group_value? }
// DELETE /api/mm/responses.php   { project_id, response_id }
// POST   /api/mm/responses.php   { project_id, action: "delete_many", ids: [N, ...] }
//
// Builder-stage row management. Lets the user clean the raw imported rows
// before running the AI category build. Editing or deleting a row drops any
// existing coded_responses / sentiment_scores tied to it so the next build
// starts from a clean slate.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET', 'PATCH', 'DELETE', 'POST');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $projectId = (int)($_GET['project_id'] ?? 0);
    $limit     = (int)($_GET['limit'] ?? 200);
    if ($limit < 1)    $limit = 1;
    if ($limit > 2000) $limit = 2000;
    if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
    mm_require_project($pdo, $uid, $projectId);

    $stmt = $pdo->prepare(
        'SELECT id, respondent_ref, group_value, numeric_value, text, created_at
         FROM mm_text_responses WHERE project_id = :p ORDER BY id ASC LIMIT ' . $limit
    );
    $stmt->execute([':p' => $projectId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'             => (int)$r['id'],
            'respondent_ref' => $r['respondent_ref'] !== null ? (string)$r['respondent_ref'] : '',
            'group_value'    => $r['group_value']    !== null ? (string)$r['group_value']    : '',
            'numeric_value'  => $r['numeric_value']  !== null ? (float)$r['numeric_value']   : null,
            'text'           => (string)$r['text'],
            'created_at'     => (string)$r['created_at'],
        ];
    }
    $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM mm_text_responses WHERE project_id = :p');
    $totalStmt->execute([':p' => $projectId]);
    json_out(['ok' => true, 'rows' => $out, 'total' => (int)$totalStmt->fetchColumn()]);
}

if ($method === 'PATCH') {
    $body       = read_json_body();
    $projectId  = (int)($body['project_id']  ?? 0);
    $responseId = (int)($body['response_id'] ?? 0);
    if ($projectId <= 0 || $responseId <= 0) fail('bad_input', 'project_id and response_id are required.');
    mm_require_project($pdo, $uid, $projectId);

    // Confirm the response belongs to this project.
    $own = $pdo->prepare('SELECT id FROM mm_text_responses WHERE id = :id AND project_id = :p');
    $own->execute([':id' => $responseId, ':p' => $projectId]);
    if (!$own->fetch()) fail('mm_response_not_found', 'Response not found in this project.', 404);

    $fields = [];
    $params = [':id' => $responseId];
    if (array_key_exists('text', $body)) {
        $t = clean_string((string)$body['text'], 8000);
        if ($t === '') fail('bad_input', 'Text cannot be empty.');
        $fields[] = 'text = :t';        $params[':t'] = $t;
    }
    if (array_key_exists('group_value', $body)) {
        $g = clean_string((string)$body['group_value'], 200);
        $fields[] = 'group_value = :g';  $params[':g'] = $g !== '' ? $g : null;
    }
    if (array_key_exists('numeric_value', $body)) {
        $v = $body['numeric_value'];
        $fields[] = 'numeric_value = :n';
        $params[':n'] = ($v === null || $v === '' || !is_numeric($v)) ? null : (float)$v;
    }
    if (array_key_exists('respondent_ref', $body)) {
        $r = clean_string((string)$body['respondent_ref'], 120);
        $fields[] = 'respondent_ref = :r'; $params[':r'] = $r !== '' ? $r : null;
    }
    if (count($fields) === 0) json_out(['ok' => true, 'changed' => 0]);

    // Drop any AI-generated coding for this response so the next build re-codes it.
    $pdo->prepare('DELETE FROM mm_coded_responses WHERE response_id = :r')->execute([':r' => $responseId]);
    $pdo->prepare('DELETE FROM mm_sentiment_scores WHERE response_id = :r')->execute([':r' => $responseId]);

    $sql = 'UPDATE mm_text_responses SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_out(['ok' => true, 'changed' => $stmt->rowCount()]);
}

if ($method === 'DELETE') {
    $body       = read_json_body();
    $projectId  = (int)($body['project_id']  ?? 0);
    $responseId = (int)($body['response_id'] ?? 0);
    if ($projectId <= 0 || $responseId <= 0) fail('bad_input', 'project_id and response_id are required.');
    mm_require_project($pdo, $uid, $projectId);
    $stmt = $pdo->prepare('DELETE FROM mm_text_responses WHERE id = :id AND project_id = :p');
    $stmt->execute([':id' => $responseId, ':p' => $projectId]);
    json_out(['ok' => true, 'deleted' => $stmt->rowCount()]);
}

// POST: bulk delete.
$body      = read_json_body();
$projectId = (int)($body['project_id'] ?? 0);
$action    = clean_string((string)($body['action'] ?? ''), 32);
$ids       = $body['ids'] ?? [];
if ($projectId <= 0 || $action !== 'delete_many' || !is_array($ids) || count($ids) === 0) {
    fail('bad_input', 'Provide project_id, action=delete_many, and a non-empty ids array.');
}
mm_require_project($pdo, $uid, $projectId);

$clean = [];
foreach ($ids as $id) { $id = (int)$id; if ($id > 0) $clean[] = $id; }
if (count($clean) === 0) fail('bad_input', 'No valid ids provided.');
if (count($clean) > 2000) $clean = array_slice($clean, 0, 2000);

$placeholders = implode(',', array_fill(0, count($clean), '?'));
$sql = "DELETE FROM mm_text_responses WHERE project_id = ? AND id IN (" . $placeholders . ")";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$projectId], $clean));
json_out(['ok' => true, 'deleted' => $stmt->rowCount()]);
