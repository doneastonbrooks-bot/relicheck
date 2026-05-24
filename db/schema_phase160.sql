-- Phase 160: Step 16 (Integration paragraphs).
-- One row per (project, theme). Stores the AI-written or user-edited
-- mixed-methods integration paragraph that pulls together qualitative
-- and quantitative evidence for that theme.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS mm_integration_rows (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id      INT UNSIGNED NOT NULL,
  theme_id        INT UNSIGNED NOT NULL,
  paragraph_text  TEXT         NULL,
  source          VARCHAR(16)  NOT NULL DEFAULT 'ai',  -- 'ai' | 'user'
  generated_at    DATETIME     NULL,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mm_int_theme (project_id, theme_id),
  KEY idx_mm_int_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DESCRIBE mm_integration_rows;
SELECT COUNT(*) AS integration_rows_total FROM mm_integration_rows;
