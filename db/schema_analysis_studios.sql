-- Analysis Studios (Descriptive + Inferential) — canonical schema.
-- These tables are also auto-created on first request by
-- api/_analysis_studio.php::analysis_ensure_schema() (the TIA pattern),
-- so no manual migration is required. This file is the reference copy.

CREATE TABLE IF NOT EXISTS analysis_projects (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    kind        ENUM('descriptive','inferential') NOT NULL,
    title       VARCHAR(200) NOT NULL,
    dataset_id  BIGINT UNSIGNED NULL,          -- FK-by-convention to datasets.id (SIRI / existing dataset)
    dataset_payload LONGTEXT NULL,             -- verbatim Evidence-Intake payload (engine-native shape)
    status      ENUM('draft','active','archived') NOT NULL DEFAULT 'active',
    notes       MEDIUMTEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_analysis_projects_user (user_id, kind, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analysis_results (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id   BIGINT UNSIGNED NOT NULL,
    kind         ENUM('descriptive','inferential') NOT NULL,
    tool_key     VARCHAR(64) NOT NULL,         -- e.g. frequencies, t_test
    inputs_json  MEDIUMTEXT NULL,              -- variable picks / options
    result_json  MEDIUMTEXT NULL,              -- snapshot of RELICHECK_APP_STATE
    summary      VARCHAR(600) NULL,            -- plain-English one-liner
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_analysis_results_project (project_id, tool_key),
    CONSTRAINT fk_analysis_results_project
      FOREIGN KEY (project_id) REFERENCES analysis_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
