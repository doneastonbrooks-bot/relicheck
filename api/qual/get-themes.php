<?php
// GET /api/qual/get-themes.php?project_id=N
// Returns all themes for the project with their linked categories.

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user      = require_auth();
release_session_lock();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

qual_require_project($pdo, $uid, $projectId);

// Load themes
$tSt = $pdo->prepare(
    "SELECT id, name, interpretive_claim, notes, position,
            cl_context, cl_group_variation, cl_pattern_type,
            cl_counter_story, cl_structural_framing, cl_action_caution
     FROM qual_themes
     WHERE project_id = :p
     ORDER BY position ASC, id ASC"
);
$tSt->execute([':p' => $projectId]);
$themes = $tSt->fetchAll(PDO::FETCH_ASSOC);

// Load all categories (for the "link categories to theme" picker)
$catSt = $pdo->prepare(
    "SELECT id, name FROM qual_categories WHERE project_id=:p ORDER BY position ASC, id ASC"
);
$catSt->execute([':p' => $projectId]);
$allCategories = $catSt->fetchAll(PDO::FETCH_ASSOC);

// Load theme→category links
$linkSt = $pdo->prepare(
    "SELECT tc.theme_id, tc.category_id, c.name AS category_name
     FROM qual_theme_categories tc
     JOIN qual_categories c ON c.id = tc.category_id
     WHERE c.project_id = :p"
);
$linkSt->execute([':p' => $projectId]);
$links = $linkSt->fetchAll(PDO::FETCH_ASSOC);

$catsByTheme = [];
foreach ($links as $link) {
    $catsByTheme[(int)$link['theme_id']][] = [
        'id'   => (int)$link['category_id'],
        'name' => $link['category_name'],
    ];
}

foreach ($themes as &$theme) {
    $theme['categories'] = $catsByTheme[(int)$theme['id']] ?? [];
}
unset($theme);

json_out([
    'ok'             => true,
    'themes'         => $themes,
    'all_categories' => $allCategories,
]);
