<?php
// Shared dataset helpers — used across analysis, MM, and RSSI endpoints.
// Include after _helpers.php.
//
// rc_seed_var_meta_from_dataset() — called at dataset-link time so the DataMap
// opens pre-classified rather than empty. Non-fatal on error.

declare(strict_types=1);

require_once __DIR__ . '/dev/_type_taxonomy.php';
require_once __DIR__ . '/dev/_dev_common.php';

/**
 * Seed variable_metadata rows from a dataset's column_meta.
 *
 * Reads the stored column_meta for $datasetId and upserts one variable_metadata
 * row per column, mapping the legacy type vocab to canonical analysis_type.
 * If a column already has an explicit analysis_type stored in column_meta
 * (written by the new unified upload widget) that value is used directly.
 *
 * ON DUPLICATE KEY UPDATE: re-linking the same dataset refreshes types but
 * never clobbers user-confirmed overrides made via the DataMap (those writes
 * happen through variable-meta-save.php which has its own path).
 *
 * @param PDO      $pdo
 * @param int      $projectId    The project receiving the dataset.
 * @param string   $projectType  'analysis' | 'mm' | 'survey'
 * @param int      $datasetId
 * @param int|null $rcProjectId  Ecosystem project id (RE Item 3); null for legacy rows.
 */
function rc_seed_var_meta_from_dataset(
    PDO    $pdo,
    int    $projectId,
    string $projectType,
    int    $datasetId,
    ?int   $rcProjectId = null
): void {
    try {
        sds_ensure_schema($pdo);

        $ds = $pdo->prepare('SELECT column_meta FROM datasets WHERE id = :id LIMIT 1');
        $ds->execute([':id' => $datasetId]);
        $row = $ds->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $cm = $row['column_meta'];
        if (is_string($cm)) $cm = json_decode($cm, true);
        if (!is_array($cm) || !count($cm)) return;

        $stmt = $pdo->prepare(
            'INSERT INTO variable_metadata
                (project_id, project_type, variable_name, dataset_id, source,
                 analysis_type, measurement_level, storage_type, include_in_analysis,
                 reverse_scored, created_at, updated_at)
             VALUES
                (:pid, :pt, :vn, :did, "dataset_column",
                 :at, :ml, :st, :inc,
                 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                analysis_type     = VALUES(analysis_type),
                measurement_level = VALUES(measurement_level),
                storage_type      = VALUES(storage_type),
                dataset_id        = VALUES(dataset_id),
                updated_at        = NOW()'
        );

        foreach ($cm as $i => $col) {
            if (!is_array($col)) continue;
            $name = trim((string)($col['name'] ?? ('col_' . ($i + 1))));
            if ($name === '') continue;

            // Prefer the explicit analysis_type written by the unified upload widget.
            // Fall back to mapping from the legacy type vocab.
            $rawType = (string)($col['type'] ?? 'ignore');
            $at = (isset($col['analysis_type']) && rc_valid_analysis_type((string)$col['analysis_type']))
                ? (string)$col['analysis_type']
                : rc_analysis_type_from_dataset_type($rawType);

            $ml  = rc_measurement_level($at);
            $st  = match($at) {
                'identifier', 'open_ended', 'narrative',
                'demographic_nominal', 'qualitative_code',
                'theme', 'metadata'  => 'TEXT',
                'date_time'          => 'DATE',
                'structural'         => 'VARCHAR',
                default              => 'INT',
            };
            $inc = ($at !== 'structural' && $at !== 'identifier') ? 1 : 0;

            $stmt->execute([
                ':pid' => $projectId,
                ':pt'  => $projectType,
                ':vn'  => mb_substr($name, 0, 128),
                ':did' => $datasetId,
                ':at'  => $at,
                ':ml'  => $ml,
                ':st'  => $st,
                ':inc' => $inc,
            ]);
        }
        // Propagate ecosystem project id to the rows we just seeded.
        if ($rcProjectId !== null) {
            try {
                $pdo->prepare(
                    'UPDATE variable_metadata SET rc_project_id = :rc
                      WHERE project_id = :pid AND project_type = :pt AND rc_project_id IS NULL'
                )->execute([':rc' => $rcProjectId, ':pid' => $projectId, ':pt' => $projectType]);
            } catch (Throwable $e) {
                // Column may not exist on a first request before rc_ensure_project_schema ran — ignore.
            }
        }
    } catch (Throwable $e) {
        error_log('rc_seed_var_meta_from_dataset: project=' . $projectId
            . ' type=' . $projectType . ' dataset=' . $datasetId
            . ': ' . $e->getMessage());
    }
}
