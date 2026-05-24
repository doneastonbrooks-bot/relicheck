-- Phase 178: back-end six-stop journey.
-- Adds:
--   * mm_project_framing.backend_stage  -- where the user is post-wizard
--   * mm_project_framing.chosen_design_locked_at  -- audit timestamp
-- Backfills:
--   * Any framing row with chosen_design IS NULL gets stage='needs_design'.
--   * Any framing row with chosen_design set gets stage='analyze'.
--   * Legacy rows (skipped_legacy) with no chosen_design also get stage='needs_design'
--     so they pass through the new Choose Design gate once.
--
-- Idempotent: INFORMATION_SCHEMA guards.

USE dbs15641829;

-- 1. backend_stage column ------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_project_framing'
     AND COLUMN_NAME  = 'backend_stage'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE mm_project_framing
     ADD COLUMN backend_stage
       ENUM(''needs_design'',''analyze'',''integrate'',''defend'')
       NOT NULL DEFAULT ''needs_design''
       AFTER chosen_design',
  'SELECT "mm_project_framing.backend_stage already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. chosen_design_locked_at column -------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_project_framing'
     AND COLUMN_NAME  = 'chosen_design_locked_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE mm_project_framing
     ADD COLUMN chosen_design_locked_at DATETIME NULL
       AFTER backend_stage',
  'SELECT "mm_project_framing.chosen_design_locked_at already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Index on backend_stage for the admin activity roll-up -------------------
SET @idx_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_project_framing'
     AND INDEX_NAME   = 'idx_mm_framing_backend_stage'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_mm_framing_backend_stage ON mm_project_framing(backend_stage)',
  'SELECT "idx_mm_framing_backend_stage already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Backfill the new column ---------------------------------------------------
UPDATE mm_project_framing
   SET backend_stage = CASE
     WHEN chosen_design IS NULL OR chosen_design = '' THEN 'needs_design'
     ELSE 'analyze'
   END,
       chosen_design_locked_at = CASE
         WHEN chosen_design IS NOT NULL AND chosen_design <> '' AND chosen_design_locked_at IS NULL
           THEN COALESCE(updated_at, created_at, NOW())
         ELSE chosen_design_locked_at
       END;

-- 5. Verification --------------------------------------------------------------
DESCRIBE mm_project_framing;
SELECT
  (SELECT COUNT(*) FROM mm_project_framing) AS framing_rows,
  (SELECT COUNT(*) FROM mm_project_framing WHERE backend_stage = 'needs_design') AS needs_design,
  (SELECT COUNT(*) FROM mm_project_framing WHERE backend_stage = 'analyze')      AS in_analyze,
  (SELECT COUNT(*) FROM mm_project_framing WHERE backend_stage = 'integrate')    AS in_integrate,
  (SELECT COUNT(*) FROM mm_project_framing WHERE backend_stage = 'defend')       AS in_defend;
