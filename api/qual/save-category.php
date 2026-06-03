<?php
// POST /api/qual/save-category.php
// Create or update a qual_category.
// Body: { project_id, id?(update), name, description? }
// Returns: { ok, category_id }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId = (int)($body['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');
qual_require_project($pdo, $uid, $projectId);

$name = trim((string)($body['name'] ?? ''));
if ($name === '') fail('bad_input', 'name is required.');
$desc = trim((string)($body['description'] ?? '')) ?: null;

$catId = (int)($body['id'] ?? 0);

if ($catId > 0) {
    $pdo->prepare(
        'UPDATE qual_categories SET name=:n, description=:d WHERE id=:id AND project_id=:p LIMIT 1'
    )->execute([':n' => $name, ':d' => $desc, ':id' => $catId, ':p' => $projectId]);
    qual_audit($pdo, $projectId, $uid, 'category_updated', 'category', $catId, $name);
    json_out(['ok' => true, 'category_id' => $catId]);
} else {
    $pdo->prepare(
        'INSERT INTO qual_categories (project_id, name, description) VALUES (:p, :n, :d)'
    )->execute([':p' => $projectId, ':n' => $name, ':d' => $desc]);
    $catId = (int)$pdo->lastInsertId();
    qual_audit($pdo, $projectId, $uid, 'category_created', 'category', $catId, $name);
    json_out(['ok' => true, 'category_id' => $catId]);
}
