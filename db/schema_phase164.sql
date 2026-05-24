-- Phase 164: Step 19 Phase C (Report section notes + to-dos).
-- One row per note. Each note belongs to a (project, section). is_todo turns
-- the note into an action item; is_resolved hides it from the unresolved count.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS mm_report_section_notes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id   INT UNSIGNED NOT NULL,
  section_key  VARCHAR(64)  NOT NULL,
  body_text    VARCHAR(800) NOT NULL,
  is_todo      TINYINT(1)   NOT NULL DEFAULT 0,
  is_resolved  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mm_rep_notes (project_id, section_key, is_resolved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DESCRIBE mm_report_section_notes;
SELECT COUNT(*) AS report_note_rows_total FROM mm_report_section_notes;
