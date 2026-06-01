<?php
// /api/tia/project.php
// GET   ?id=N          fetch one TIA project (owned by caller)
// PATCH { id, title?, notes?, settings? }  partial update

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';

require_method('GET', 'PATCH');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

function tia_out2(array $r): array {
    return [
        'id'         => (int)$r['id'],
        'title'      => (string)$r['title'],
        'notes'      => (string)($r['notes'] ?? ''),
        'settings'   => json_decode((string)($r['settings'] ?? '{}'), true) ?: [],
        'status'     => (string)$r['status'],
        'created_at' => (string)$r['created_at'],
        'updated_at' => (string)$r['updated_at'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) fail('bad_id', 'Missing id.');
    $stmt = $pdo->prepare('SELECT * FROM tia_projects WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $id, ':uid' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('not_found', 'Project not found.', 404);
    json_out(['ok' => true, 'project' => tia_out2($row)]);
}

// PATCH: partial update.
check_origin();
$body = read_json_body();
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) fail('bad_id', 'Missing id.');

$os = $pdo->prepare('SELECT * FROM tia_projects WHERE id = :id AND user_id = :uid');
$os->execute([':id' => $id, ':uid' => $uid]);
$row = $os->fetch(PDO::FETCH_ASSOC);
if (!$row) fail('not_found', 'Project not found.', 404);

$set    = [];
$params = [':id' => $id];
if (array_key_exists('title', $body)) {
    $t = trim((string)$body['title']);
    if ($t === '') fail('bad_input', 'Title cannot be empty.');
    if (mb_strlen($t) > 255) $t = mb_substr($t, 0, 255);
    $set[] = 'title = :title'; $params[':title'] = $t;
}
if (array_key_exists('notes', $body)) {
    $n = (string)$body['notes'];
    if (mb_strlen($n) > 4000) $n = mb_substr($n, 0, 4000);
    $set[] = 'notes = :notes'; $params[':notes'] = $n;
}
if (array_key_exists('settings', $body) && is_array($body['settings'])) {
    $set[] = 'settings = :settings'; $params[':settings'] = json_encode($body['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
if (!$set) json_out(['ok' => true, 'project' => tia_out2($row)]);

$sql = 'UPDATE tia_projects SET ' . implode(', ', $set) . ' WHERE id = :id';
$pdo->prepare($sql)->execute($params);

$g = $pdo->prepare('SELECT * FROM tia_projects WHERE id = :id');
$g->execute([':id' => $id]);
$updated = $g->fetch(PDO::FETCH_ASSOC);
json_out(['ok' => true, 'project' => tia_out2($updated)]);
