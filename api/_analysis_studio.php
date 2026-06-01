<?php
// Analysis Studios shared helpers (Descriptive + Inferential).
// -------------------------------------------------------------------
// Owns lazy schema creation, project lookup/ownership, and the JSON
// serializers used by every endpoint under api/analysis/. Underscore-
// prefixed so it cannot be hit over HTTP (the api/ .htaccess rejects
// underscore files).
//
// One shared project table with a `kind` discriminator backs BOTH the
// Descriptive Analysis Studio and the Inferential Statistics Studio —
// they are near-identical quantitative-analysis flows. The actual data
// lives in the generic `datasets` table (db/schema_phase7.sql), linked
// via analysis_projects.dataset_id. Saved analysis runs (snapshots of
// window.RELICHECK_APP_STATE) live in analysis_results.
//
// Tables are created on first request (the TIA pattern, api/tia/
// projects.php) so the studios work with no manual migration step. The
// canonical schema is mirrored in db/schema_analysis_studios.sql.

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_session.php';

const ANALYSIS_STUDIO_KINDS = ['descriptive', 'inferential'];

/** True for a recognized studio kind. */
function analysis_valid_kind(string $kind): bool
{
    return in_array($kind, ANALYSIS_STUDIO_KINDS, true);
}

/**
 * Create the analysis-studio tables if they don't exist. Idempotent and
 * cheap; called at the top of every api/analysis/ endpoint.
 */
function analysis_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS analysis_projects (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     BIGINT UNSIGNED NOT NULL,
            kind        ENUM('descriptive','inferential') NOT NULL,
            title       VARCHAR(200) NOT NULL,
            dataset_id  BIGINT UNSIGNED NULL,
            dataset_payload LONGTEXT NULL,
            status      ENUM('draft','active','archived') NOT NULL DEFAULT 'active',
            notes       MEDIUMTEXT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_analysis_projects_user (user_id, kind, status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS analysis_results (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id   BIGINT UNSIGNED NOT NULL,
            kind         ENUM('descriptive','inferential') NOT NULL,
            tool_key     VARCHAR(64) NOT NULL,
            inputs_json  MEDIUMTEXT NULL,
            result_json  MEDIUMTEXT NULL,
            summary      VARCHAR(600) NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_analysis_results_project (project_id, tool_key),
            CONSTRAINT fk_analysis_results_project
              FOREIGN KEY (project_id) REFERENCES analysis_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Defensive: if analysis_projects was created by an earlier build without
    // the dataset_payload column, add it. Guarded so it runs at most once.
    try {
        $col = $pdo->query("SHOW COLUMNS FROM analysis_projects LIKE 'dataset_payload'");
        if ($col && !$col->fetch()) {
            $pdo->exec('ALTER TABLE analysis_projects ADD COLUMN dataset_payload LONGTEXT NULL AFTER dataset_id');
        }
    } catch (Throwable $e) { /* table brand-new (already has it) or race — ignore */ }
}

/**
 * Load an analysis project the current user owns, or fail 404. When
 * $kind is given, the project must also match that studio kind.
 */
function analysis_require_project(PDO $pdo, int $userId, int $projectId, ?string $kind = null): array
{
    $stmt = $pdo->prepare('SELECT * FROM analysis_projects WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $projectId, ':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($kind !== null && (string)$row['kind'] !== $kind)) {
        fail('analysis_project_not_found', 'Analysis project not found.', 404);
    }
    return $row;
}

/** Stable project shape for the front end. */
function analysis_project_out(array $row): array
{
    return [
        'id'         => (int)$row['id'],
        'kind'       => (string)$row['kind'],
        'title'      => (string)$row['title'],
        'dataset_id' => $row['dataset_id'] !== null ? (int)$row['dataset_id'] : null,
        // has_data: prefer a precomputed flag (light list query) else derive.
        'has_data'   => array_key_exists('has_data', $row)
                          ? (bool)$row['has_data']
                          : (!empty($row['dataset_payload']) || !empty($row['dataset_id'])),
        'status'     => (string)$row['status'],
        'notes'      => $row['notes'] !== null ? (string)$row['notes'] : '',
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

/** Stable saved-result shape for the front end. */
function analysis_result_out(array $row): array
{
    return [
        'id'         => (int)$row['id'],
        'project_id' => (int)$row['project_id'],
        'kind'       => (string)$row['kind'],
        'tool_key'   => (string)$row['tool_key'],
        'inputs'     => $row['inputs_json'] !== null ? json_decode((string)$row['inputs_json'], true) : null,
        'result'     => $row['result_json'] !== null ? json_decode((string)$row['result_json'], true) : null,
        'summary'    => $row['summary'] !== null ? (string)$row['summary'] : '',
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}
