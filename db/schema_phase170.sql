-- Phase 170: MM Studio v2 wizard flow.
-- Adds the study-framing layer that runs between intake and analysis:
--   * Title + description on every project (Step 1 of the new wizard)
--   * Persisted wizard_step so a return visit resumes at the right screen
--   * mm_project_framing table that stores data_kinds, intent_purposes,
--     and chosen_design (Steps 4 and 5)
--   * Backfill so every existing project is treated as legacy (skips the
--     wizard and drops the user straight into the Analyze view)
--
-- Idempotent: uses INFORMATION_SCHEMA guards so re-running the file is safe.

USE dbs15641829;

-- 1. mm_projects.description  ----------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_projects'
     AND COLUMN_NAME  = 'description'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE mm_projects ADD COLUMN description TEXT NULL AFTER title',
  'SELECT "mm_projects.description already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. mm_projects.wizard_step  ----------------------------------------------
-- 1 = welcome shown, 2 = title saved, 3 = data added, 4 = mapping confirmed,
-- 5 = framing saved, 6 = design chosen, 99 = wizard complete / legacy.
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'mm_projects'
     AND COLUMN_NAME  = 'wizard_step'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE mm_projects ADD COLUMN wizard_step TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER status',
  'SELECT "mm_projects.wizard_step already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. mm_project_framing  ---------------------------------------------------
CREATE TABLE IF NOT EXISTS mm_project_framing (
  project_id        BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  data_kinds        TEXT         NULL
    COMMENT 'JSON array of slugs from the Step 4a multi-select: open_only, survey_plus_open, survey_plus_interviews, quant_plus_interpretation, build_from_scratch.',
  intent_purposes   TEXT         NULL
    COMMENT 'JSON array of slugs from the Step 4b multi-select: explain_survey, find_themes, compare_groups, build_variables, strengthen_report, findings_section, eval_evidence, explore_patterns.',
  chosen_design     VARCHAR(40)  NULL
    COMMENT 'One of design_a..design_e (the Step 5 single pick).',
  framing_status    ENUM('pending','in_progress','complete','skipped_legacy')
                    NOT NULL DEFAULT 'pending',
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mm_framing_project FOREIGN KEY (project_id)
    REFERENCES mm_projects(id) ON DELETE CASCADE,
  KEY idx_mm_framing_status (framing_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Backfill: every existing project becomes a "legacy" record so its
--    user drops straight into the analysis tabs and is not pushed through
--    the new wizard retroactively. wizard_step = 99 means "past the wizard".
UPDATE mm_projects SET wizard_step = 99 WHERE wizard_step < 99;

INSERT INTO mm_project_framing (project_id, framing_status)
  SELECT p.id, 'skipped_legacy'
    FROM mm_projects p
    LEFT JOIN mm_project_framing f ON f.project_id = p.id
   WHERE f.project_id IS NULL;

-- 5. Verification queries  -------------------------------------------------
DESCRIBE mm_projects;
DESCRIBE mm_project_framing;
SELECT
  (SELECT COUNT(*) FROM mm_projects) AS projects_total,
  (SELECT COUNT(*) FROM mm_project_framing) AS framing_rows,
  (SELECT COUNT(*) FROM mm_project_framing WHERE framing_status = 'skipped_legacy') AS legacy_skipped,
  (SELECT COUNT(*) FROM mm_projects WHERE wizard_step = 99) AS wizard_done;
