<?php
// GET /api/dev/variable-meta-load.php?project_id=N[&project_type=survey]
// Authenticated, owner-gated. Returns all variable_metadata rows for a project,
// ordered by position. project_type defaults to 'survey'.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';
require_once __DIR__ . '/_type_taxonomy.php';

require_method('GET');
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$projectId   = isset($_GET['project_id'])   ? (int)$_GET['project_id']                : 0;
$projectType = isset($_GET['project_type']) ? clean_string($_GET['project_type'], 24)  : 'survey';

if ($projectId <= 0) fail('bad_input', 'project_id is required.');
vm_require_owner($pdo, (int)$user['id'], $projectId, $projectType);

$stmt = $pdo->prepare(
    'SELECT id, project_id, project_type, variable_name, display_label, source,
            survey_item_id, dataset_id, storage_type, analysis_type,
            measurement_level, role, construct_id, allowed_values,
            reverse_scored, include_in_analysis, position,
            created_at, updated_at
       FROM variable_metadata
      WHERE project_id = :pid AND project_type = :ptype
      ORDER BY position ASC, id ASC'
);
$stmt->execute([':pid' => $projectId, ':ptype' => $projectType]);

$rows = array_map(function (array $r): array {
    return [
        'id'                  => (int)$r['id'],
        'project_id'          => (int)$r['project_id'],
        'project_type'        => $r['project_type'],
        'variable_name'       => $r['variable_name'],
        'display_label'       => $r['display_label'],
        'source'              => $r['source'],
        'survey_item_id'      => $r['survey_item_id'] !== null ? (int)$r['survey_item_id'] : null,
        'dataset_id'          => $r['dataset_id']     !== null ? (int)$r['dataset_id']     : null,
        'storage_type'        => $r['storage_type'],
        'analysis_type'       => $r['analysis_type'],
        'measurement_level'   => $r['measurement_level'],
        'role'                => $r['role'],
        'construct_id'        => $r['construct_id'] !== null ? (int)$r['construct_id'] : null,
        'allowed_values'      => $r['allowed_values'] !== null ? json_decode((string)$r['allowed_values'], true) : null,
        'reverse_scored'      => (bool)$r['reverse_scored'],
        'include_in_analysis' => (bool)$r['include_in_analysis'],
        'position'            => (int)$r['position'],
        'created_at'          => $r['created_at'],
        'updated_at'          => $r['updated_at'],
        // Enrich with allowed analyses from the taxonomy (client convenience)
        'allowed_analyses'    => rc_allowed_analyses((string)$r['analysis_type']),
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

json_out(['ok' => true, 'variables' => $rows, 'count' => count($rows)]);

// ── Owner check helper ───────────────────────────────────────────────────────
// Validates that $userId owns the project identified by ($projectId, $projectType).
// Calls fail() on 404/403 — never returns on invalid access.
function vm_require_owner(PDO $pdo, int $userId, int $projectId, string $projectType): void
{
    $tables = [
        'survey'   => ['survey_projects',   'user_id'],
        'analysis' => ['analysis_projects', 'user_id'],
        'mm'       => ['mm_projects',       'user_id'],
    ];
    if (!isset($tables[$projectType])) {
        fail('bad_input', "Unknown project_type '$projectType'.");
    }
    [$table, $col] = $tables[$projectType];
    $stmt = $pdo->prepare("SELECT id FROM $table WHERE id = :id AND $col = :uid LIMIT 1");
    $stmt->execute([':id' => $projectId, ':uid' => $userId]);
    if (!$stmt->fetch()) {
        fail('forbidden', 'Project not found or access denied.', 403);
    }
}
