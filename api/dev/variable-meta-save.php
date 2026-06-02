<?php
// POST /api/dev/variable-meta-save.php
// Body: { project_id, project_type?, variables: [{ variable_name, analysis_type, ...fields }] }
//
// Batch upsert: inserts new variable_metadata rows and updates existing ones
// (matched on the unique key: project_id + project_type + variable_name).
// Does NOT delete variables absent from the batch — callers send only what changed.
// To re-derive all variables from scratch, send the full set; existing extras persist.
//
// Returns the full saved variable list for the project after the upsert.

declare(strict_types=1);

require_once __DIR__ . '/_dev_common.php';
require_once __DIR__ . '/_type_taxonomy.php';

require_method('POST');
check_origin();
$user = require_auth();

$pdo = db();
sds_ensure_schema($pdo);

$body        = read_json_body();
$projectId   = isset($body['project_id'])   ? (int)$body['project_id']                     : 0;
$projectType = isset($body['project_type']) ? clean_string((string)$body['project_type'], 24) : 'survey';
$incoming    = (isset($body['variables']) && is_array($body['variables'])) ? $body['variables'] : [];

if ($projectId <= 0) fail('bad_input', 'project_id is required.');
if (count($incoming) === 0) fail('bad_input', 'variables array must not be empty.');

vm_require_owner($pdo, (int)$user['id'], $projectId, $projectType);

// ── Validate and normalise each incoming variable ────────────────────────────
$VALID_SOURCES = ['siri_item', 'dataset_column', 'computed'];
$VALID_STORAGE = ['INT', 'BIGINT', 'DECIMAL', 'FLOAT', 'VARCHAR', 'TEXT', 'LONGTEXT',
                  'TINYINT', 'BOOLEAN', 'DATETIME', 'DATE', 'JSON', null];

$normalised = [];
foreach ($incoming as $i => $v) {
    if (!is_array($v)) continue;

    $varName = clean_string((string)($v['variable_name'] ?? ''), 128);
    if ($varName === '') continue;

    $analysisType = clean_string((string)($v['analysis_type'] ?? ''), 32);
    if (!rc_valid_analysis_type($analysisType)) {
        fail('bad_input', "Variable '$varName': unknown analysis_type '$analysisType'.");
    }

    $source = clean_string((string)($v['source'] ?? 'siri_item'), 24);
    if (!in_array($source, $VALID_SOURCES, true)) $source = 'siri_item';

    $storageRaw = isset($v['storage_type']) ? strtoupper(clean_string((string)$v['storage_type'], 24)) : null;
    $storageType = in_array($storageRaw, $VALID_STORAGE, true) ? $storageRaw : null;

    $measurementLevel = rc_measurement_level($analysisType); // always derive from taxonomy

    $role = isset($v['role']) ? clean_string((string)$v['role'], 24) : null;
    if ($role !== null && !in_array($role, RC_ROLES, true)) $role = null;

    $constructId   = isset($v['construct_id'])   && $v['construct_id']   !== null ? (int)$v['construct_id']   : null;
    $surveyItemId  = isset($v['survey_item_id']) && $v['survey_item_id'] !== null ? (int)$v['survey_item_id'] : null;
    $datasetId     = isset($v['dataset_id'])     && $v['dataset_id']     !== null ? (int)$v['dataset_id']     : null;

    $allowedValues = null;
    if (isset($v['allowed_values']) && is_array($v['allowed_values'])) {
        $allowedValues = json_encode(array_values($v['allowed_values']));
    }

    $normalised[] = [
        'variable_name'       => $varName,
        'display_label'       => isset($v['display_label']) ? clean_string((string)$v['display_label'], 255) : null,
        'source'              => $source,
        'survey_item_id'      => $surveyItemId,
        'dataset_id'          => $datasetId,
        'storage_type'        => $storageType,
        'analysis_type'       => $analysisType,
        'measurement_level'   => $measurementLevel,
        'role'                => $role,
        'construct_id'        => $constructId,
        'allowed_values'      => $allowedValues,
        'reverse_scored'      => isset($v['reverse_scored']) && $v['reverse_scored'] ? 1 : 0,
        'include_in_analysis' => !isset($v['include_in_analysis']) || $v['include_in_analysis'] ? 1 : 0,
        'position'            => isset($v['position']) ? max(0, (int)$v['position']) : $i,
    ];
}

if (count($normalised) === 0) fail('bad_input', 'No valid variables after normalisation.');

// ── Batch upsert ─────────────────────────────────────────────────────────────
$sql = "INSERT INTO variable_metadata
            (project_id, project_type, variable_name, display_label, source,
             survey_item_id, dataset_id, storage_type, analysis_type,
             measurement_level, role, construct_id, allowed_values,
             reverse_scored, include_in_analysis, position)
        VALUES
            (:pid, :ptype, :vname, :dlabel, :src,
             :siid, :dsid, :stype, :atype,
             :mlevel, :role, :cid, :avals,
             :rscore, :include, :pos)
        ON DUPLICATE KEY UPDATE
            display_label       = VALUES(display_label),
            source              = VALUES(source),
            survey_item_id      = VALUES(survey_item_id),
            dataset_id          = VALUES(dataset_id),
            storage_type        = VALUES(storage_type),
            analysis_type       = VALUES(analysis_type),
            measurement_level   = VALUES(measurement_level),
            role                = VALUES(role),
            construct_id        = VALUES(construct_id),
            allowed_values      = VALUES(allowed_values),
            reverse_scored      = VALUES(reverse_scored),
            include_in_analysis = VALUES(include_in_analysis),
            position            = VALUES(position),
            updated_at          = NOW()";

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare($sql);
    foreach ($normalised as $n) {
        $stmt->execute([
            ':pid'     => $projectId,
            ':ptype'   => $projectType,
            ':vname'   => $n['variable_name'],
            ':dlabel'  => $n['display_label'],
            ':src'     => $n['source'],
            ':siid'    => $n['survey_item_id'],
            ':dsid'    => $n['dataset_id'],
            ':stype'   => $n['storage_type'],
            ':atype'   => $n['analysis_type'],
            ':mlevel'  => $n['measurement_level'],
            ':role'    => $n['role'],
            ':cid'     => $n['construct_id'],
            ':avals'   => $n['allowed_values'],
            ':rscore'  => $n['reverse_scored'],
            ':include' => $n['include_in_analysis'],
            ':pos'     => $n['position'],
        ]);
    }
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('db_error', 'Could not save variable metadata: ' . $e->getMessage(), 500);
}

// Return the full saved list so the client can re-sync state.
$loadStmt = $pdo->prepare(
    'SELECT id, project_id, project_type, variable_name, display_label, source,
            survey_item_id, dataset_id, storage_type, analysis_type,
            measurement_level, role, construct_id, allowed_values,
            reverse_scored, include_in_analysis, position,
            created_at, updated_at
       FROM variable_metadata
      WHERE project_id = :pid AND project_type = :ptype
      ORDER BY position ASC, id ASC'
);
$loadStmt->execute([':pid' => $projectId, ':ptype' => $projectType]);

$saved = array_map(function (array $r): array {
    return [
        'id'                  => (int)$r['id'],
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
        'allowed_analyses'    => rc_allowed_analyses((string)$r['analysis_type']),
    ];
}, $loadStmt->fetchAll(PDO::FETCH_ASSOC));

json_out(['ok' => true, 'saved' => count($normalised), 'variables' => $saved]);

// ── Owner check helper (shared with load endpoint) ───────────────────────────
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
