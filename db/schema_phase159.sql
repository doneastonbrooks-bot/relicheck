-- Phase 159: Step 15 (joint display).
-- mm_joint_display_rows persists the AI-picked representative quote per
-- theme, plus a researcher_notes column we'll use in later steps.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS mm_joint_display_rows (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id         INT UNSIGNED NOT NULL,
  theme_id           INT UNSIGNED NOT NULL,
  quote_response_id  INT UNSIGNED NULL,
  quote_text         VARCHAR(800) NULL,
  researcher_notes   VARCHAR(1200) NULL,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mm_jd_theme (project_id, theme_id),
  KEY idx_mm_jd_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DESCRIBE mm_joint_display_rows;
SELECT COUNT(*) AS joint_display_rows_total FROM mm_joint_display_rows;
