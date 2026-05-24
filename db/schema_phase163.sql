-- Phase 163: Step 19 Phase B (Report section version history).
-- Append-only log of section saves. Server prunes each section to the 10
-- most recent versions on every save.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS mm_report_section_versions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id   INT UNSIGNED NOT NULL,
  section_key  VARCHAR(64)  NOT NULL,
  body_text    LONGTEXT     NULL,
  body_html    LONGTEXT     NULL,
  source       VARCHAR(16)  NOT NULL DEFAULT 'user',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mm_rep_ver (project_id, section_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DESCRIBE mm_report_section_versions;
SELECT COUNT(*) AS report_version_rows_total FROM mm_report_section_versions;
