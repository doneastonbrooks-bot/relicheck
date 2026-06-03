<?php
// GET /api/qual/get-categories.php?project_id=N
// Returns all categories for the project with their assigned codes.
// Also returns codes not assigned to any category (unassigned).

declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_qual_studio.php';

require_method('GET');
$user      = require_auth();
$pdo       = db();
$uid       = (int)$user['id'];
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'Missing project_id.');

qual_require_project($pdo, $uid, $projectId);

// Load categories
$catSt = $pdo->prepare(
    "SELECT id, name, description, position
     FROM qual_categories
     WHERE project_id = :p
     ORDER BY position ASC, id ASC"
);
$catSt->execute([':p' => $projectId]);
$categories = $catSt->fetchAll(PDO::FETCH_ASSOC);

// Load all non-retired codes with their category assignment
$codeSt = $pdo->prepare(
    "SELECT id, name, definition, status, parent_category_id,
            (SELECT COUNT(*) FROM qual_code_applications a WHERE a.code_id = c.id) AS application_count
     FROM qual_codes c
     WHERE c.project_id = :p AND c.status <> 'retired'
     ORDER BY c.position ASC, c.id ASC"
);
$codeSt->execute([':p' => $projectId]);
$allCodes = $codeSt->fetchAll(PDO::FETCH_ASSOC);

// Group codes by category
$codesByCategory = [];
$unassigned      = [];
foreach ($allCodes as $code) {
    $catId = $code['parent_category_id'] ? (int)$code['parent_category_id'] : null;
    if ($catId) {
        $codesByCategory[$catId][] = $code;
    } else {
        $unassigned[] = $code;
    }
}

// Attach codes to categories
foreach ($categories as &$cat) {
    $cat['codes'] = $codesByCategory[(int)$cat['id']] ?? [];
}
unset($cat);

json_out([
    'ok'         => true,
    'categories' => $categories,
    'unassigned' => $unassigned,
]);
