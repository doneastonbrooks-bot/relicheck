<?php
// GET /api/mm/generated-variables.php?project_id=N
//
// Lists the project's generated (theme-derived) variables — the ones created by
// the Qual → Quant step (dataset.php) — for the Build & Test Measures picker.
// Each carries the name of the theme it came from when it has one, so a test on
// it can be linked back to that theme in the Joint Display.

declare(strict_types=1);

require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../_mm.php';

require_method('GET');
check_origin();
$user = require_auth();
$pdo  = db();
$uid  = (int)$user['id'];

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) fail('bad_input', 'project_id is required.');
mm_require_project($pdo, $uid, $projectId);

$vars = [];
try {
    $stmt = $pdo->prepare(
        'SELECT gv.id, gv.var_name, gv.display_label, gv.var_type, gv.role, gv.source_category_id,
                tc.name AS theme_name
           FROM mm_generated_variables gv
           LEFT JOIN mm_theme_categories tc ON tc.id = gv.source_category_id
          WHERE gv.project_id = :p
          ORDER BY gv.id ASC'
    );
    $stmt->execute([':p' => $projectId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $vars[] = [
            'id'    => (int)$r['id'],
            'name'  => (string)($r['display_label'] !== '' && $r['display_label'] !== null ? $r['display_label'] : $r['var_name']),
            'type'  => (string)$r['var_type'],
            'role'  => (string)($r['role'] ?? ''),
            'theme' => $r['theme_name'] !== null ? (string)$r['theme_name'] : null,
        ];
    }
} catch (PDOException $e) {
    // mm_generated_variables absent on this deployment — return empty, not 500.
    $vars = [];
}

json_out(['ok' => true, 'variables' => $vars]);
