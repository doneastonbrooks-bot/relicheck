<?php
// POST /api/qual/link-theme-category.php
// Add or remove a category from a theme.
// Body: { project_id, theme_id, category_id, action: 'add'|'remove' }
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

$projectId  = (int)($body['project_id']  ?? 0);
$themeId    = (int)($body['theme_id']    ?? 0);
$categoryId = (int)($body['category_id'] ?? 0);
$action     = (string)($body['action']   ?? 'add');

if ($projectId <= 0 || $themeId <= 0 || $categoryId <= 0) {
    fail('bad_input', 'project_id, theme_id, and category_id required.');
}
qual_require_project($pdo, $uid, $projectId);

// Verify theme and category belong to project
$t = $pdo->prepare('SELECT id FROM qual_themes     WHERE id=:id AND project_id=:p LIMIT 1');
$t->execute([':id' => $themeId, ':p' => $projectId]);
if (!$t->fetch()) fail('not_found', 'Theme not found.', 404);

$c = $pdo->prepare('SELECT id, name FROM qual_categories WHERE id=:id AND project_id=:p LIMIT 1');
$c->execute([':id' => $categoryId, ':p' => $projectId]);
$cat = $c->fetch(PDO::FETCH_ASSOC);
if (!$cat) fail('not_found', 'Category not found.', 404);

if ($action === 'remove') {
    $pdo->prepare(
        'DELETE FROM qual_theme_categories WHERE theme_id=:t AND category_id=:c'
    )->execute([':t' => $themeId, ':c' => $categoryId]);
    qual_audit($pdo, $projectId, $uid, 'theme_category_removed', 'theme', $themeId, (string)$cat['name']);
} else {
    $pdo->prepare(
        'INSERT IGNORE INTO qual_theme_categories (theme_id, category_id) VALUES (:t, :c)'
    )->execute([':t' => $themeId, ':c' => $categoryId]);
    qual_audit($pdo, $projectId, $uid, 'theme_category_added', 'theme', $themeId, (string)$cat['name']);
}

json_out(['ok' => true]);
