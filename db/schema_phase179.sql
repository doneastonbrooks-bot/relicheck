-- Phase 179: Codebook workspace.
-- Extends the existing mm_codebooks table (Phase 156) with the full set of
-- fields needed for a dissertation-defensible codebook entry:
--   * short_definition  (one-line label)
--   * full_description  (long paragraph)
--   * borderline_cases  (where the line is tricky)
--   * analyst_memo      (researcher reasoning, audit trail)
--   * parent_cluster    (optional grouping label)
--   * linked_variables_json (JSON list of variable keys this theme feeds)
--   * status            (draft / reviewed / approved)
--
-- All additions are idempotent: every column is guarded by INFORMATION_SCHEMA.
-- The existing inclusion_rules, exclusion_rules, example_quotes_json, and
-- coding_confidence columns are reused as-is.

USE dbs15641829;

-- 1. short_definition
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_codebooks'
     AND COLUMN_NAME  = 'short_definition'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE mm_codebooks ADD COLUMN short_definition VARCHAR(400) NULL AFTER theme_id',
  'SELECT "mm_codebooks.short_definition already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. full_description
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_codebooks'
     AND COLUMN_NAME  = 'full_description'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE mm_codebooks ADD COLUMN full_description MEDIUMTEXT NULL AFTER short_definition',
  'SELECT "mm_codebooks.full_description already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. borderline_cases
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_codebooks'
     AND COLUMN_NAME  = 'borderline_cases'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE mm_codebooks ADD COLUMN borderline_cases MEDIUMTEXT NULL AFTER exclusion_rules',
  'SELECT "mm_codebooks.borderline_cases already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. analyst_memo
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_codebooks'
     AND COLUMN_NAME  = 'analyst_memo'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE mm_codebooks ADD COLUMN analyst_memo MEDIUMTEXT NULL AFTER borderline_cases',
  'SELECT "mm_codebooks.analyst_memo already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. parent_cluster
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_codebooks'
     AND COLUMN_NAME  = 'parent_cluster'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE mm_codebooks ADD COLUMN parent_cluster VARCHAR(200) NULL AFTER analyst_memo',
  'SELECT "mm_codebooks.parent_cluster already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. linked_variables_json
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_codebooks'
     AND COLUMN_NAME  = 'linked_variables_json'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE mm_codebooks ADD COLUMN linked_variables_json MEDIUMTEXT NULL AFTER parent_cluster',
  'SELECT "mm_codebooks.linked_variables_json already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. status
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_codebooks'
     AND COLUMN_NAME  = 'status'
);
SET @sql := IF(@col_exists = 0,
  "ALTER TABLE mm_codebooks ADD COLUMN status ENUM('draft','reviewed','approved') NOT NULL DEFAULT 'draft' AFTER linked_variables_json",
  'SELECT "mm_codebooks.status already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. Index on status for the theme list filter.
SET @idx_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_codebooks'
     AND INDEX_NAME   = 'idx_mm_codebooks_status'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_mm_codebooks_status ON mm_codebooks(project_id, status)',
  'SELECT "idx_mm_codebooks_status already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Verification.
DESCRIBE mm_codebooks;
SELECT
  (SELECT COUNT(*) FROM mm_codebooks) AS codebook_rows,
  (SELECT COUNT(*) FROM mm_codebooks WHERE status = 'draft')    AS drafts,
  (SELECT COUNT(*) FROM mm_codebooks WHERE status = 'reviewed') AS reviewed,
  (SELECT COUNT(*) FROM mm_codebooks WHERE status = 'approved') AS approved;
