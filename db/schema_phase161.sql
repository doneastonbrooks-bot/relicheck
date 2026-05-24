-- Phase 161: Step 17 (Strength Check).
-- One row per (project, check_key). Stores the latest result of each
-- methodological check the Studio can run against the project's data.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS mm_strength_checks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id   INT UNSIGNED NOT NULL,
  check_key    VARCHAR(64)  NOT NULL,                         -- e.g. sample_total, theme_min_coded, saturation_late, effect_negligible, ai_quote_quality
  status       VARCHAR(8)   NOT NULL DEFAULT 'skip',          -- 'pass' | 'fix' | 'skip'
  severity     VARCHAR(8)   NOT NULL DEFAULT 'info',          -- 'info' | 'warn' | 'high'
  title        VARCHAR(200) NOT NULL,
  message      VARCHAR(600) NULL,
  fix_hint     VARCHAR(600) NULL,
  details_json TEXT         NULL,
  ran_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mm_sc (project_id, check_key),
  KEY idx_mm_sc_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DESCRIBE mm_strength_checks;
SELECT COUNT(*) AS strength_check_rows_total FROM mm_strength_checks;
