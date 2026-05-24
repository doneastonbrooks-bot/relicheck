-- Phase 155: ReliCheck Mixed-Methods Studio (DRAFT).
-- Adds the data model for the new Mixed-Methods Studio module, which connects
-- quantitative survey results to open-ended responses, and turns open-ended
-- responses into structured datasets.
--
-- All tables use the mm_ prefix so they sit cleanly alongside the existing
-- schema. Nothing here modifies existing tables.
--
-- Run order: click dbs15641829 in the phpMyAdmin sidebar, then SQL tab, paste
-- this whole file, Go.

USE dbs15641829;

SET NAMES utf8mb4;

-- 1. Projects: one Mixed-Methods Studio analysis project per user, optionally
--    bound to a survey or dataset.
CREATE TABLE IF NOT EXISTS mm_projects (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  title           VARCHAR(200)    NOT NULL,
  pathway         ENUM('scores_plus_comments','comments_only') NOT NULL,
  survey_id       VARCHAR(64)     NULL
    COMMENT 'Optional binding to a survey id when pathway A.',
  dataset_id      BIGINT UNSIGNED NULL
    COMMENT 'Optional binding to a dataset id.',
  status          ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  notes           MEDIUMTEXT      NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mm_projects_user (user_id, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Data sources: any input attached to a project (survey, dataset, uploaded
--    file, manual paste). One project may have several.
CREATE TABLE IF NOT EXISTS mm_data_sources (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  source_type     ENUM('survey','dataset','upload','paste') NOT NULL,
  source_ref      VARCHAR(128)    NULL
    COMMENT 'Survey id, dataset id, or upload filename.',
  label           VARCHAR(200)    NULL,
  field_name      VARCHAR(200)    NULL
    COMMENT 'Which column or question id provides the open-ended text.',
  numeric_field   VARCHAR(200)    NULL
    COMMENT 'Which column or question id provides the numeric score.',
  group_field     VARCHAR(200)    NULL
    COMMENT 'Optional group variable.',
  row_count       INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_data_sources_project (project_id),
  CONSTRAINT fk_mm_data_sources_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Text responses: every individual open-ended response feeding the project.
CREATE TABLE IF NOT EXISTS mm_text_responses (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  source_id       BIGINT UNSIGNED NOT NULL,
  respondent_ref  VARCHAR(120)    NULL,
  group_value     VARCHAR(200)    NULL,
  numeric_value   DECIMAL(10,4)   NULL,
  text            MEDIUMTEXT      NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_text_responses_project (project_id),
  KEY idx_mm_text_responses_source  (source_id),
  CONSTRAINT fk_mm_text_responses_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_mm_text_responses_source
    FOREIGN KEY (source_id)  REFERENCES mm_data_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Extracted concepts: short key terms or phrases pulled from the texts.
CREATE TABLE IF NOT EXISTS mm_extracted_concepts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  concept         VARCHAR(200)    NOT NULL,
  frequency       INT UNSIGNED    NOT NULL DEFAULT 0,
  example_quote   VARCHAR(600)    NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_extracted_concepts_project (project_id, frequency),
  CONSTRAINT fk_mm_extracted_concepts_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Theme categories: user-facing categories produced by Auto, Guided, or
--    Hybrid mode. Edited by the human review layer.
CREATE TABLE IF NOT EXISTS mm_theme_categories (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  name            VARCHAR(200)    NOT NULL,
  description     VARCHAR(600)    NULL,
  source_mode     ENUM('auto','guided','hybrid','user') NOT NULL DEFAULT 'auto',
  confidence      ENUM('high','moderate','low') NOT NULL DEFAULT 'moderate',
  position        INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mm_theme_categories_project (project_id, position),
  CONSTRAINT fk_mm_theme_categories_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Coding rules: optional keyword or phrase rules that map text to a
--    category. Lets the human review layer pin a rule for re-coding.
CREATE TABLE IF NOT EXISTS mm_coding_rules (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  category_id     BIGINT UNSIGNED NOT NULL,
  rule_type       ENUM('keyword','phrase','regex') NOT NULL DEFAULT 'keyword',
  pattern         VARCHAR(400)    NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_coding_rules_project  (project_id),
  KEY idx_mm_coding_rules_category (category_id),
  CONSTRAINT fk_mm_coding_rules_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_mm_coding_rules_category
    FOREIGN KEY (category_id) REFERENCES mm_theme_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Coded responses: response-to-category assignments. One row per
--    response-category pairing.
CREATE TABLE IF NOT EXISTS mm_coded_responses (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  response_id     BIGINT UNSIGNED NOT NULL,
  category_id     BIGINT UNSIGNED NOT NULL,
  confidence      ENUM('high','moderate','low') NOT NULL DEFAULT 'moderate',
  is_user_edited  TINYINT(1)      NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mm_coded_unique (response_id, category_id),
  KEY idx_mm_coded_project  (project_id),
  KEY idx_mm_coded_category (category_id),
  CONSTRAINT fk_mm_coded_project
    FOREIGN KEY (project_id)  REFERENCES mm_projects(id)         ON DELETE CASCADE,
  CONSTRAINT fk_mm_coded_response
    FOREIGN KEY (response_id) REFERENCES mm_text_responses(id)   ON DELETE CASCADE,
  CONSTRAINT fk_mm_coded_category
    FOREIGN KEY (category_id) REFERENCES mm_theme_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Sentiment scores: per-response tone label, with optional advanced
--    indicators in a JSON column for extensibility.
CREATE TABLE IF NOT EXISTS mm_sentiment_scores (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  response_id     BIGINT UNSIGNED NOT NULL,
  sentiment       ENUM('positive','neutral','negative','mixed') NOT NULL,
  confidence      ENUM('high','moderate','low') NOT NULL DEFAULT 'moderate',
  indicators_json MEDIUMTEXT      NULL
    COMMENT 'Optional advanced indicators: frustration, trust, urgency, etc.',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mm_sentiment_response (response_id),
  KEY idx_mm_sentiment_project (project_id),
  CONSTRAINT fk_mm_sentiment_project
    FOREIGN KEY (project_id)  REFERENCES mm_projects(id)       ON DELETE CASCADE,
  CONSTRAINT fk_mm_sentiment_response
    FOREIGN KEY (response_id) REFERENCES mm_text_responses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Generated variables: the columns the Variable Builder produces.
CREATE TABLE IF NOT EXISTS mm_generated_variables (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  var_name        VARCHAR(120)    NOT NULL,
  display_label   VARCHAR(200)    NULL,
  var_type        ENUM('binary','frequency','ordinal','sentiment','intensity','category') NOT NULL,
  role            ENUM('predictor','outcome','neutral') NOT NULL DEFAULT 'neutral',
  source_category_id BIGINT UNSIGNED NULL,
  notes           VARCHAR(600)    NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_generated_variables_project (project_id),
  CONSTRAINT fk_mm_generated_variables_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_mm_generated_variables_source
    FOREIGN KEY (source_category_id) REFERENCES mm_theme_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Structured datasets: metadata for the final exportable dataset. The
--     actual cell values live in mm_dataset_cells so the table stays wide
--     enough for any project.
CREATE TABLE IF NOT EXISTS mm_structured_datasets (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  title           VARCHAR(200)    NOT NULL,
  row_count       INT UNSIGNED    NOT NULL DEFAULT 0,
  col_count       INT UNSIGNED    NOT NULL DEFAULT 0,
  schema_json     MEDIUMTEXT      NULL
    COMMENT 'Column definitions in the order they should render.',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_structured_datasets_project (project_id),
  CONSTRAINT fk_mm_structured_datasets_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mm_dataset_cells (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dataset_id      BIGINT UNSIGNED NOT NULL,
  response_id     BIGINT UNSIGNED NULL,
  variable_id     BIGINT UNSIGNED NOT NULL,
  cell_value      VARCHAR(400)    NULL,
  KEY idx_mm_dataset_cells_ds  (dataset_id),
  KEY idx_mm_dataset_cells_var (variable_id),
  CONSTRAINT fk_mm_dataset_cells_ds
    FOREIGN KEY (dataset_id) REFERENCES mm_structured_datasets(id) ON DELETE CASCADE,
  CONSTRAINT fk_mm_dataset_cells_var
    FOREIGN KEY (variable_id) REFERENCES mm_generated_variables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Evidence alignment results: rows for the Evidence Alignment Check.
CREATE TABLE IF NOT EXISTS mm_evidence_alignment_results (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  quant_label     VARCHAR(200)    NOT NULL,
  quant_value     VARCHAR(120)    NULL,
  qual_evidence   VARCHAR(600)    NULL,
  alignment       ENUM('aligned','divergent','nuanced','insufficient') NOT NULL,
  confidence      ENUM('high','moderate','low') NOT NULL DEFAULT 'moderate',
  interpretation  MEDIUMTEXT      NULL,
  next_step       MEDIUMTEXT      NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_alignment_project (project_id),
  CONSTRAINT fk_mm_alignment_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Group voice results: theme distribution per group value.
CREATE TABLE IF NOT EXISTS mm_group_voice_results (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  group_value     VARCHAR(200)    NOT NULL,
  top_themes_json MEDIUMTEXT      NULL,
  sentiment_pattern VARCHAR(120)  NULL,
  interpretation  MEDIUMTEXT      NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_group_voice_project (project_id),
  CONSTRAINT fk_mm_group_voice_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Evidence matrices: joint display rows.
CREATE TABLE IF NOT EXISTS mm_evidence_matrices (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  measure         VARCHAR(200)    NOT NULL,
  quant_result    VARCHAR(120)    NULL,
  theme_evidence  VARCHAR(600)    NULL,
  sentiment       VARCHAR(60)     NULL,
  interpretation  MEDIUMTEXT      NULL,
  recommended_action MEDIUMTEXT   NULL,
  position        INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_matrix_project (project_id, position),
  CONSTRAINT fk_mm_matrix_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Follow-up questions generated by the Follow-Up Builder.
CREATE TABLE IF NOT EXISTS mm_follow_up_questions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  prompt_for      VARCHAR(200)    NULL
    COMMENT 'Score area, theme, or group that triggered the suggestion.',
  question_type   ENUM('survey_item','open_prompt','interview','focus_group','action_planning') NOT NULL,
  question_text   MEDIUMTEXT      NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_follow_up_project (project_id),
  CONSTRAINT fk_mm_follow_up_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Mixed-methods reports: rendered report snapshots and export metadata.
CREATE TABLE IF NOT EXISTS mm_reports (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      BIGINT UNSIGNED NOT NULL,
  title           VARCHAR(200)    NOT NULL,
  body_html       MEDIUMTEXT      NULL,
  summary_json    MEDIUMTEXT      NULL
    COMMENT 'Structured findings used for repeat export to docx, pptx, xlsx.',
  exported_formats VARCHAR(120)   NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mm_reports_project (project_id),
  CONSTRAINT fk_mm_reports_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW TABLES LIKE 'mm_%';
SELECT
  (SELECT COUNT(*) FROM mm_projects)               AS projects,
  (SELECT COUNT(*) FROM mm_text_responses)         AS responses,
  (SELECT COUNT(*) FROM mm_theme_categories)       AS categories,
  (SELECT COUNT(*) FROM mm_coded_responses)        AS coded,
  (SELECT COUNT(*) FROM mm_structured_datasets)    AS datasets;

-- Roll-back (drop child tables before parents because of FK constraints):
-- DROP TABLE IF EXISTS mm_reports;
-- DROP TABLE IF EXISTS mm_follow_up_questions;
-- DROP TABLE IF EXISTS mm_evidence_matrices;
-- DROP TABLE IF EXISTS mm_group_voice_results;
-- DROP TABLE IF EXISTS mm_evidence_alignment_results;
-- DROP TABLE IF EXISTS mm_dataset_cells;
-- DROP TABLE IF EXISTS mm_structured_datasets;
-- DROP TABLE IF EXISTS mm_generated_variables;
-- DROP TABLE IF EXISTS mm_sentiment_scores;
-- DROP TABLE IF EXISTS mm_coded_responses;
-- DROP TABLE IF EXISTS mm_coding_rules;
-- DROP TABLE IF EXISTS mm_theme_categories;
-- DROP TABLE IF EXISTS mm_extracted_concepts;
-- DROP TABLE IF EXISTS mm_text_responses;
-- DROP TABLE IF EXISTS mm_data_sources;
-- DROP TABLE IF EXISTS mm_projects;
