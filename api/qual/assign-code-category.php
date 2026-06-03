<?php
// POST /api/qual/assign-code-category.php
// Assign or unassign a code to a category by setting qual_codes.parent_category_id.
// Body: { project_id, code_id, category_id }  (category_id=0 to unassign)
// Returns: { ok }

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('POST');
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];
$body = read_json_body();

$projectId  = (int)($body['project_id'] ?? 0);
$codeId     = (int)($body['code_id']    ?? 0);
$categoryId = (int)($body['category_id']?? 0);
if ($projectId <= 0 || $codeId <= 0) fail('bad_input', 'project_id and code_id required.');

qual_require_project($pdo, $uid, $projectId);

// Verify code belongs to project
$codeRow = $pdo->prepare('SELECT id, name FROM qual_codes WHERE id=:id AND project_id=:p LIMIT 1');
$codeRow->execute([':id' => $codeId, ':p' => $projectId]);
$code = $codeRow->fetch(PDO::FETCH_ASSOC);
if (!$code) fail('not_found', 'Code not found.', 404);

// Verify category belongs to project (if assigning)
if ($categoryId > 0) {
    $catRow = $pdo->prepare('SELECT id FROM qual_categories WHERE id=:id AND project_id=:p LIMIT 1');
    $catRow->execute([':id' => $categoryId, ':p' => $projectId]);
    if (!$catRow->fetch()) fail('not_found', 'Category not found.', 404);
}

$newCatId = $categoryId > 0 ? $categoryId : null;
$pdo->prepare(
    'UPDATE qual_codes SET parent_category_id=:c WHERE id=:id AND project_id=:p LIMIT 1'
)->execute([':c' => $newCatId, ':id' => $codeId, ':p' => $projectId]);

$action = $categoryId > 0 ? 'code_assigned_to_category' : 'code_unassigned_from_category';
qual_audit($pdo, $projectId, $uid, $action, 'code', $codeId, (string)$code['name']);

json_out(['ok' => true]);
