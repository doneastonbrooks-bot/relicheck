-- Phase 37 migration: surveys list overhaul.
--   * surveys.is_favorite             - star toggle on the list row
--   * surveys.archived_at             - soft-archive timestamp
--   * surveys.folder_id               - per-user folder (nullable, ON DELETE SET NULL)
--   * surveys.health_alpha_min        - cached min Cronbach alpha (Likert)
--   * surveys.health_last_response_at - cached last response timestamp
--   * folders                         - per-user folders (id, owner, name, color)
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- FULLY IDEMPOTENT. Safe to paste-and-run as many times as needed; every step
-- checks information_schema before applying. If a previous attempt half-ran
-- (e.g. #1060 Duplicate column), just paste this whole block again.

USE dbs15641829;

SET NAMES utf8mb4;

-- 1. folders table (CREATE IF NOT EXISTS is already idempotent).
CREATE TABLE IF NOT EXISTS folders (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id    BIGINT UNSIGNED NOT NULL COMMENT 'User who owns this folder.',
  name        VARCHAR(120)    NOT NULL COMMENT 'Folder display name.',
  color       VARCHAR(16)     NOT NULL DEFAULT 'slate' COMMENT 'Visual swatch token (slate, coral, navy, etc.).',
  sort_order  INT             NOT NULL DEFAULT 0,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_folders_owner (owner_id, sort_order),
  CONSTRAINT fk_folders_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. surveys.is_favorite
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'surveys' AND column_name = 'is_favorite'),
  'SELECT 1',
  "ALTER TABLE surveys ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Star toggle on the survey list row.'"
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. surveys.archived_at
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'surveys' AND column_name = 'archived_at'),
  'SELECT 1',
  "ALTER TABLE surveys ADD COLUMN archived_at DATETIME NULL DEFAULT NULL COMMENT 'Soft-archive timestamp. NULL = active. Excluded from list by default.'"
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. surveys.folder_id
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'surveys' AND column_name = 'folder_id'),
  'SELECT 1',
  "ALTER TABLE surveys ADD COLUMN folder_id BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Per-user folder. NULL = no folder. ON DELETE SET NULL via folders.'"
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 5. surveys.health_alpha_min
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'surveys' AND column_name = 'health_alpha_min'),
  'SELECT 1',
  "ALTER TABLE surveys ADD COLUMN health_alpha_min FLOAT NULL DEFAULT NULL COMMENT 'Cached min Cronbach alpha across Likert subscales. NULL = not computed yet.'"
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 6. surveys.health_last_response_at
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'surveys' AND column_name = 'health_last_response_at'),
  'SELECT 1',
  "ALTER TABLE surveys ADD COLUMN health_last_response_at DATETIME NULL DEFAULT NULL COMMENT 'Cached most recent response timestamp. NULL = no responses yet.'"
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 7. surveys idx_surveys_owner_archive
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'surveys' AND index_name = 'idx_surveys_owner_archive'),
  'SELECT 1',
  'ALTER TABLE surveys ADD KEY idx_surveys_owner_archive (owner_id, archived_at)'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 8. surveys idx_surveys_owner_favorite
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'surveys' AND index_name = 'idx_surveys_owner_favorite'),
  'SELECT 1',
  'ALTER TABLE surveys ADD KEY idx_surveys_owner_favorite (owner_id, is_favorite)'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 9. surveys idx_surveys_folder
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'surveys' AND index_name = 'idx_surveys_folder'),
  'SELECT 1',
  'ALTER TABLE surveys ADD KEY idx_surveys_folder (folder_id)'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 10. surveys fk_surveys_folder (foreign key to folders)
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.table_constraints
          WHERE table_schema = DATABASE() AND table_name = 'surveys' AND constraint_name = 'fk_surveys_folder'),
  'SELECT 1',
  'ALTER TABLE surveys ADD CONSTRAINT fk_surveys_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 11. Backfill cached health_last_response_at from responses (safe to re-run;
--     it only writes rows where the column is currently NULL).
--     Note: the responses table uses 'submitted_at', not 'created_at'.
UPDATE surveys s
LEFT JOIN (
  SELECT survey_id, MAX(submitted_at) AS last_at
  FROM responses
  GROUP BY survey_id
) r ON r.survey_id = s.id
SET s.health_last_response_at = r.last_at
WHERE s.health_last_response_at IS NULL;

-- Verification.
SHOW COLUMNS FROM surveys LIKE 'is_favorite';
SHOW COLUMNS FROM surveys LIKE 'archived_at';
SHOW COLUMNS FROM surveys LIKE 'folder_id';
SHOW COLUMNS FROM surveys LIKE 'health_alpha_min';
SHOW COLUMNS FROM surveys LIKE 'health_last_response_at';
SHOW TABLES LIKE 'folders';
SELECT COUNT(*) AS folder_rows FROM folders;
-- Expected: 5 columns shown, 1 table shown, 0 folder rows on a fresh install.

-- Roll-back (run only if you need to undo this migration):
-- ALTER TABLE surveys DROP FOREIGN KEY fk_surveys_folder;
-- ALTER TABLE surveys
--   DROP KEY idx_surveys_owner_archive,
--   DROP KEY idx_surveys_owner_favorite,
--   DROP KEY idx_surveys_folder;
-- ALTER TABLE surveys
--   DROP COLUMN is_favorite,
--   DROP COLUMN archived_at,
--   DROP COLUMN folder_id,
--   DROP COLUMN health_alpha_min,
--   DROP COLUMN health_last_response_at;
-- DROP TABLE IF EXISTS folders;
