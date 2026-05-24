-- Phase 156: Mixed-Methods Studio full rebuild.
-- SAFE RE-RUNNABLE VERSION. Uses INFORMATION_SCHEMA to skip columns/tables
-- that already exist, so a partial earlier run will not break this one.
--
-- Run order: click `dbs15641829` in the phpMyAdmin sidebar, SQL tab, paste
-- this whole file, Go.

USE dbs15641829;

SET NAMES utf8mb4;

-- Drop obsolete tables if they still exist.
DROP TABLE IF EXISTS mm_extracted_concepts;
DROP TABLE IF EXISTS mm_coding_rules;
DROP TABLE IF EXISTS mm_evidence_alignment_results;
DROP TABLE IF EXISTS mm_group_voice_results;
DROP TABLE IF EXISTS mm_follow_up_questions;

-- Helper procedure: add a column only if it does not exist.
DROP PROCEDURE IF EXISTS mm_add_col;
DELIMITER $$
CREATE PROCEDURE mm_add_col(
  IN tbl  VARCHAR(64),
  IN col  VARCHAR(64),
  IN ddl  TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN ', ddl);
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;

-- mm_projects.
CALL mm_add_col('mm_projects', 'data_kind',
  "data_kind ENUM('open_ended_only','survey_plus_open','survey_plus_separate_qual','quant_only_with_qual','from_scratch') NULL AFTER pathway");
CALL mm_add_col('mm_projects', 'purpose',
  "purpose ENUM('explain_survey_results','find_themes','compare_groups','build_variables_from_text','strengthen_report','mixed_methods_section','evaluation_accreditation','pre_survey_exploration') NULL AFTER data_kind");
CALL mm_add_col('mm_projects', 'design_choice',
  "design_choice ENUM('A_explain_numbers','B_comments_to_themes','C_compare_themes_groups','D_variables_from_text','E_full_integrated_report') NULL AFTER purpose");
CALL mm_add_col('mm_projects', 'wizard_completed_at',
  "wizard_completed_at DATETIME NULL AFTER design_choice");

-- mm_data_sources.
CALL mm_add_col('mm_data_sources', 'format',
  "format ENUM('csv','tsv','xlsx','spss','google_forms','surveymonkey','qualtrics','paste','transcript','focus_group_notes','document_text','survey') NULL AFTER source_type");
CALL mm_add_col('mm_data_sources', 'field_map_json',
  "field_map_json MEDIUMTEXT NULL");

-- mm_theme_categories.
CALL mm_add_col('mm_theme_categories', 'question_id',
  "question_id BIGINT UNSIGNED NULL AFTER project_id");
CALL mm_add_col('mm_theme_categories', 'definition',
  "definition VARCHAR(1000) NULL AFTER description");
CALL mm_add_col('mm_theme_categories', 'tone',
  "tone ENUM('positive','neutral','negative','mixed') NULL");
CALL mm_add_col('mm_theme_categories', 'overlap_json',
  "overlap_json MEDIUMTEXT NULL");
CALL mm_add_col('mm_theme_categories', 'is_final',
  "is_final TINYINT(1) NOT NULL DEFAULT 0");

-- mm_coded_responses.
CALL mm_add_col('mm_coded_responses', 'intensity',
  "intensity ENUM('low','moderate','high') NULL AFTER confidence");
CALL mm_add_col('mm_coded_responses', 'relevance',
  "relevance ENUM('usable','unclear','off_topic') NOT NULL DEFAULT 'usable'");
CALL mm_add_col('mm_coded_responses', 'quote_worthy',
  "quote_worthy TINYINT(1) NOT NULL DEFAULT 0");

-- mm_reports.
CALL mm_add_col('mm_reports', 'report_style',
  "report_style ENUM('executive','hr','education_accreditation','research','program_evaluation','marketing','technical_appendix') NOT NULL DEFAULT 'executive' AFTER title");
CALL mm_add_col('mm_reports', 'narrative_json',
  "narrative_json MEDIUMTEXT NULL");

DROP PROCEDURE IF EXISTS mm_add_col;

-- New tables (idempotent via IF NOT EXISTS).
CREATE TABLE IF NOT EXISTS mm_open_questions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  source_id   BIGINT UNSIGNED NULL,
  qid         VARCHAR(64)     NULL,
  question_text VARCHAR(2000) NOT NULL,
  position    INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_open_questions_project (project_id, position),
  KEY idx_mm_open_questions_source  (source_id),
  CONSTRAINT fk_mm_open_questions_project FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_mm_open_questions_source  FOREIGN KEY (source_id)  REFERENCES mm_data_sources(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Helper: add FK only if missing.
DROP PROCEDURE IF EXISTS mm_add_fk;
DELIMITER $$
CREATE PROCEDURE mm_add_fk(
  IN tbl    VARCHAR(64),
  IN fkname VARCHAR(64),
  IN ddl    TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND CONSTRAINT_NAME = fkname
  ) THEN
    SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD CONSTRAINT ', fkname, ' ', ddl);
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;

CALL mm_add_fk('mm_theme_categories', 'fk_mm_themes_question',
  'FOREIGN KEY (question_id) REFERENCES mm_open_questions(id) ON DELETE SET NULL');

DROP PROCEDURE IF EXISTS mm_add_fk;

CREATE TABLE IF NOT EXISTS mm_quality_briefs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  source_id       BIGINT UNSIGNED NOT NULL,
  blank_count     INT UNSIGNED    NOT NULL DEFAULT 0,
  short_count     INT UNSIGNED    NOT NULL DEFAULT 0,
  duplicate_count INT UNSIGNED    NOT NULL DEFAULT 0,
  irrelevant_count INT UNSIGNED   NOT NULL DEFAULT 0,
  low_effort_count INT UNSIGNED   NOT NULL DEFAULT 0,
  copy_paste_count INT UNSIGNED   NOT NULL DEFAULT 0,
  language_json   VARCHAR(500)    NULL,
  length_p50      INT UNSIGNED    NULL,
  length_p90      INT UNSIGNED    NULL,
  sentiment_intensity FLOAT       NULL,
  notes           MEDIUMTEXT      NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_quality_briefs_project (project_id),
  KEY idx_mm_quality_briefs_source  (source_id),
  CONSTRAINT fk_mm_quality_briefs_project FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_mm_quality_briefs_source  FOREIGN KEY (source_id)  REFERENCES mm_data_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mm_clusters (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(200)    NOT NULL,
  description VARCHAR(600)    NULL,
  mode        ENUM('auto','by_number','by_category_type') NOT NULL DEFAULT 'auto',
  position    INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_clusters_project (project_id, position),
  CONSTRAINT fk_mm_clusters_project FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mm_cluster_members (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cluster_id  BIGINT UNSIGNED NOT NULL,
  theme_id    BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_mm_cluster_members (cluster_id, theme_id),
  KEY idx_mm_cluster_members_theme (theme_id),
  CONSTRAINT fk_mm_cluster_members_cluster FOREIGN KEY (cluster_id) REFERENCES mm_clusters(id) ON DELETE CASCADE,
  CONSTRAINT fk_mm_cluster_members_theme   FOREIGN KEY (theme_id)   REFERENCES mm_theme_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mm_integration_findings (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  category        ENUM('confirms','explains','expands','contradicts','needs_caution') NOT NULL,
  quant_pattern   VARCHAR(600)    NULL,
  qual_evidence   VARCHAR(600)    NULL,
  interpretation  MEDIUMTEXT      NULL,
  confidence      ENUM('high','moderate','low') NOT NULL DEFAULT 'moderate',
  position        INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_integration_project (project_id, position),
  CONSTRAINT fk_mm_integration_project FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mm_strength_check (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  data_quality              TINYINT UNSIGNED NULL,
  theme_clarity             TINYINT UNSIGNED NULL,
  coding_confidence         TINYINT UNSIGNED NULL,
  quote_support             TINYINT UNSIGNED NULL,
  quant_qual_alignment      TINYINT UNSIGNED NULL,
  group_comparison_readiness TINYINT UNSIGNED NULL,
  reporting_readiness       TINYINT UNSIGNED NULL,
  actionability             TINYINT UNSIGNED NULL,
  overall_score             TINYINT UNSIGNED NULL,
  notes_json                MEDIUMTEXT       NULL,
  created_at                DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mm_strength_check_project (project_id),
  CONSTRAINT fk_mm_strength_check_project FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mm_codebooks (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  theme_id        BIGINT UNSIGNED NOT NULL,
  inclusion_rules MEDIUMTEXT      NULL,
  exclusion_rules MEDIUMTEXT      NULL,
  example_quotes_json MEDIUMTEXT  NULL,
  coding_confidence ENUM('high','moderate','low') NOT NULL DEFAULT 'moderate',
  final_count     INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mm_codebook_theme (theme_id),
  KEY idx_mm_codebook_project (project_id),
  CONSTRAINT fk_mm_codebook_project FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_mm_codebook_theme   FOREIGN KEY (theme_id)   REFERENCES mm_theme_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW TABLES LIKE 'mm_%';
DESCRIBE mm_projects;
SELECT
  (SELECT COUNT(*) FROM mm_projects)             AS projects,
  (SELECT COUNT(*) FROM mm_data_sources)         AS data_sources,
  (SELECT COUNT(*) FROM mm_open_questions)       AS open_questions,
  (SELECT COUNT(*) FROM mm_quality_briefs)       AS quality_briefs,
  (SELECT COUNT(*) FROM mm_clusters)             AS clusters,
  (SELECT COUNT(*) FROM mm_integration_findings) AS findings,
  (SELECT COUNT(*) FROM mm_strength_check)       AS strength,
  (SELECT COUNT(*) FROM mm_codebooks)            AS codebooks;
