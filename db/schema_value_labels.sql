-- Value labels for coded categorical variables in MM Studio.
-- Maps a raw stored value (e.g. SELTraining "0") to a human label ("No Training")
-- per project + variable, so the studio can show meaningful labels everywhere
-- instead of numeric codes. Additive + isolated: no existing table is touched.
-- The feature degrades gracefully (label = raw value) until this is applied.

CREATE TABLE IF NOT EXISTS mm_value_labels (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  BIGINT UNSIGNED NOT NULL,
  var_name    VARCHAR(190) NOT NULL,        -- grouping variable name (e.g. "SELTraining")
  value_key   VARCHAR(190) NOT NULL,        -- raw stored value (e.g. "0")
  label       VARCHAR(300) NOT NULL,        -- human label (e.g. "No Training")
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mm_value_labels (project_id, var_name, value_key),
  KEY idx_mm_value_labels_project (project_id),
  CONSTRAINT fk_mm_value_labels_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
