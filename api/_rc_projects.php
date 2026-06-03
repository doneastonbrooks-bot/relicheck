<?php
// Ecosystem project helpers — RE Infrastructure Item 3.
//
// rc_projects is the lightweight parent record that spans all studios.
// Studio tables (survey_projects, mm_projects) point up
// to it via rc_project_id (nullable — null means a legacy row created before
// this infrastructure existed). variable_metadata gains the same FK so
// cross-studio data-dictionary queries have a single join target.
//
// Include after _helpers.php. Does not require any session or DB setup beyond
// a live PDO handle — callers own that.

declare(strict_types=1);

/**
 * Create the rc_projects table and add rc_project_id to studio tables +
 * variable_metadata. Idempotent: guarded by SHOW COLUMNS so the ALTER runs
 * at most once per deployment. Safe to call before any transaction.
 */
function rc_ensure_project_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rc_projects (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     BIGINT UNSIGNED NOT NULL,
            title       VARCHAR(255) NOT NULL,
            description TEXT NULL,
            dataset_id  BIGINT UNSIGNED NULL,
            status      VARCHAR(16) NOT NULL DEFAULT 'active',
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_rcproj_user (user_id, status, updated_at),
            CONSTRAINT fk_rcproj_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    foreach (['survey_projects', 'mm_projects'] as $tbl) {
        try {
            $c = $pdo->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'rc_project_id'")->fetch();
            if (!$c) {
                $pdo->exec("ALTER TABLE `{$tbl}`
                    ADD COLUMN rc_project_id BIGINT UNSIGNED NULL,
                    ADD KEY idx_{$tbl}_rc (rc_project_id)");
            }
        } catch (Throwable $e) {
            // Table may not exist yet — ignore, will be added next time.
        }
    }

    try {
        $c = $pdo->query("SHOW COLUMNS FROM variable_metadata LIKE 'rc_project_id'")->fetch();
        if (!$c) {
            $pdo->exec("ALTER TABLE variable_metadata
                ADD COLUMN rc_project_id BIGINT UNSIGNED NULL,
                ADD KEY idx_varmet_rc (rc_project_id)");
        }
    } catch (Throwable $e) { /* table may not exist yet */ }

    $done = true;
}

/**
 * Insert a new ecosystem project row and return its id.
 * Must be called inside the same transaction as the studio INSERT.
 */
function rc_create_project(PDO $pdo, int $uid, string $title, ?string $description = null): int
{
    $pdo->prepare(
        'INSERT INTO rc_projects (user_id, title, description) VALUES (:u, :t, :d)'
    )->execute([':u' => $uid, ':t' => $title, ':d' => $description ?: null]);
    return (int)$pdo->lastInsertId();
}

/**
 * Update rc_projects.dataset_id to the canonical dataset for an ecosystem
 * project. Non-fatal on error — the studio dataset_id is the fallback.
 */
function rc_set_project_dataset(PDO $pdo, int $rcProjectId, int $datasetId): void
{
    try {
        $pdo->prepare('UPDATE rc_projects SET dataset_id = :d WHERE id = :id')
            ->execute([':d' => $datasetId, ':id' => $rcProjectId]);
    } catch (Throwable $e) {
        error_log('rc_set_project_dataset: id=' . $rcProjectId . ': ' . $e->getMessage());
    }
}

/**
 * Return the rc_project_id stored on a studio row, or null for legacy rows.
 *
 * @param string $table  One of: survey_projects, mm_projects
 */
function rc_project_id_for_studio(PDO $pdo, string $table, int $studioId): ?int
{
    static $allowed = ['survey_projects' => true, 'mm_projects' => true];
    if (!isset($allowed[$table])) return null;
    try {
        $s = $pdo->prepare("SELECT rc_project_id FROM `{$table}` WHERE id = :id LIMIT 1");
        $s->execute([':id' => $studioId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return ($row && $row['rc_project_id']) ? (int)$row['rc_project_id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}
