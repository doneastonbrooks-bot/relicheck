-- Phase 162: Step 18 (Report).
-- One row per (project, section_key). Stores both auto-templated text
-- (assembled from project state) and AI-written or user-edited prose.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS mm_report_sections (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id    INT UNSIGNED NOT NULL,
  section_key   VARCHAR(64)  NOT NULL,                    -- exec_summary, methods, results_qual, results_quant, integration, recommendations, strength_appendix
  title         VARCHAR(200) NOT NULL,
  body_text     LONGTEXT     NULL,
  body_html     LONGTEXT     NULL,                        -- for templated sections that ship HTML tables
  source        VARCHAR(16)  NOT NULL DEFAULT 'template', -- 'template' | 'ai' | 'user'
  generated_at  DATETIME     NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mm_rep (project_id, section_key),
  KEY idx_mm_rep_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DESCRIBE mm_report_sections;
SELECT COUNT(*) AS report_section_rows_total FROM mm_report_sections;
