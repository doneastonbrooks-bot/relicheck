-- Phase 158: Step 14 (quantitative support analysis).
-- Adds mm_analysis_results, which persists every (predictor, outcome, test)
-- run from the Analysis tab so the user can revisit and so later steps
-- (Strength Check, Joint Display) can read from a stable source.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + INFORMATION_SCHEMA-guarded ADDs.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS mm_analysis_results (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id      INT UNSIGNED NOT NULL,
  dataset_id      INT UNSIGNED NOT NULL,
  predictor_id    INT UNSIGNED NOT NULL,
  outcome_id      INT UNSIGNED NOT NULL,
  test_name       VARCHAR(40)  NOT NULL,                -- chi_square | t_test | anova | pearson
  statistic       DOUBLE       NULL,                    -- chi-sq, t, F, or r
  df1             DOUBLE       NULL,                    -- chi-sq df, t df (Welch fractional), F df1
  df2             DOUBLE       NULL,                    -- F df2 (between-group ANOVA only)
  p_value         DOUBLE       NULL,
  effect_size     DOUBLE       NULL,                    -- Cramer V / Cohen d / eta-sq / r squared
  effect_label    VARCHAR(40)  NULL,                    -- cramers_v | cohens_d | eta_squared | r_squared
  n_total         INT UNSIGNED NULL,
  summary         VARCHAR(600) NULL,                    -- plain-English read
  details_json    TEXT         NULL,                    -- group means, contingency, etc.
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mm_ar_project (project_id),
  KEY idx_mm_ar_dataset (dataset_id),
  KEY idx_mm_ar_pair    (predictor_id, outcome_id, test_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DESCRIBE mm_analysis_results;
SELECT COUNT(*) AS analysis_rows_total FROM mm_analysis_results;
