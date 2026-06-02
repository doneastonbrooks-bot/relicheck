-- RE Infrastructure Item 3: Unified project table.
--
-- rc_projects is the ecosystem-level project identity that spans all studios.
-- One row = one project in the ReliCheck ecosystem (SIRI, D/I Studio, MM Studio).
--
-- Studio tables point UP to rc_projects via rc_project_id (nullable — null
-- means a legacy row created before this infrastructure was deployed). This is
-- additive: the ALTER TABLE statements below never modify existing rows.
--
-- The rc_ensure_project_schema() function in api/_rc_projects.php runs all
-- of these statements automatically on first use, so no manual migration is
-- required. This file is the canonical reference copy.
--
-- Rules (locked):
--   1. rc_projects.title  — canonical display title; set at creation.
--   2. rc_projects.dataset_id — canonical dataset; updated by every
--      link-dataset.php endpoint that processes a linked studio project.
--   3. Creation is transactional: rc_projects INSERT + studio INSERT +
--      studio.rc_project_id UPDATE happen in one transaction.
--   4. Ownership: rc_projects.user_id must equal every linked studio row's
--      user_id. Enforced at the application level on creation.
--   5. Deletion of a studio row does NOT cascade to rc_projects.

SET NAMES utf8mb4;

-- ── Ecosystem project: one row per project across all studios. ──
CREATE TABLE IF NOT EXISTS rc_projects (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(255) NOT NULL,
    description TEXT NULL,
    dataset_id  BIGINT UNSIGNED NULL,       -- canonical dataset; FK-by-convention
    status      VARCHAR(16) NOT NULL DEFAULT 'active',   -- active|archived
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rcproj_user (user_id, status, updated_at),
    CONSTRAINT fk_rcproj_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Add rc_project_id to every studio table (additive; no data changed). ──
-- Run each ALTER only if the column does not already exist.

-- survey_projects
SET @col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'survey_projects' AND column_name = 'rc_project_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE survey_projects ADD COLUMN rc_project_id BIGINT UNSIGNED NULL, ADD KEY idx_survey_projects_rc (rc_project_id)',
  'SELECT "survey_projects.rc_project_id already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- analysis_projects
SET @col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'analysis_projects' AND column_name = 'rc_project_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE analysis_projects ADD COLUMN rc_project_id BIGINT UNSIGNED NULL, ADD KEY idx_analysis_projects_rc (rc_project_id)',
  'SELECT "analysis_projects.rc_project_id already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- mm_projects
SET @col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'mm_projects' AND column_name = 'rc_project_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE mm_projects ADD COLUMN rc_project_id BIGINT UNSIGNED NULL, ADD KEY idx_mm_projects_rc (rc_project_id)',
  'SELECT "mm_projects.rc_project_id already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- variable_metadata
SET @col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'variable_metadata' AND column_name = 'rc_project_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE variable_metadata ADD COLUMN rc_project_id BIGINT UNSIGNED NULL, ADD KEY idx_varmet_rc (rc_project_id)',
  'SELECT "variable_metadata.rc_project_id already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Verification.
SELECT
  (SELECT COUNT(*) FROM rc_projects)                           AS rc_projects,
  (SELECT COUNT(*) FROM survey_projects  WHERE rc_project_id IS NOT NULL) AS siri_linked,
  (SELECT COUNT(*) FROM analysis_projects WHERE rc_project_id IS NOT NULL) AS analysis_linked,
  (SELECT COUNT(*) FROM mm_projects       WHERE rc_project_id IS NOT NULL) AS mm_linked,
  (SELECT COUNT(*) FROM variable_metadata  WHERE rc_project_id IS NOT NULL) AS varmet_linked;
