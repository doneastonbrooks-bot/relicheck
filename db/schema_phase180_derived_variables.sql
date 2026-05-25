-- Phase 180: derived variables (user-created composite scores, recodes,
-- binned variables, standardized scores) attached to an MM project.
--
-- Per tester feedback May 2026: a researcher who computes a scale
-- composite or recodes a column should not lose that work on logout.
-- The values are persisted server-side and reappear in every analysis
-- dropdown across the project.
--
-- Storage model:
--   project_id      which MM project the variable belongs to
--   name            unique label within the project
--   op              operation type: 'mean' | 'sum' | 'recode' | 'bin' | 'standardize'
--   spec_json       op-specific configuration (source columns, cutpoints, recode map)
--   values_json     computed value per respondent, stored as a JSON array
--                   parallel in order to the dataset's rows
--
-- Phase 1 scope: only 'mean' and 'sum' are implemented. The op column
-- accepts the other values now so the schema does not need to change
-- when Phase 2 ships recode/bin/standardize.

CREATE TABLE IF NOT EXISTS mm_derived_variables (
  id             BIGINT NOT NULL AUTO_INCREMENT,
  project_id     INT NOT NULL,
  name           VARCHAR(120) NOT NULL,
  op             VARCHAR(20) NOT NULL,
  spec_json      JSON NOT NULL,
  values_json    JSON NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_name (project_id, name),
  KEY idx_project (project_id),
  CONSTRAINT fk_mmdv_project FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
