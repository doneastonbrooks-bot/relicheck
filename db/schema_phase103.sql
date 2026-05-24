-- Phase 103: trend snapshots for the Survey Overview "vs Previous" column.
-- Stores a periodic snapshot of each survey's headline metrics so the
-- dashboard Top Findings table can show change-over-time. Client writes a
-- new snapshot on render if the most recent one is more than 7 days old.

-- Pick the database first so phpMyAdmin runs the rest in the right context.
USE dbs15641829;

CREATE TABLE IF NOT EXISTS survey_snapshots (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  survey_id       BIGINT UNSIGNED NOT NULL,
  snapshot_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  response_count  INT UNSIGNED    NOT NULL,
  ssi_total       INT NULL                                  COMMENT 'Strength Index 0-100 at snapshot time.',
  alpha           DECIMAL(6,4) NULL                          COMMENT 'Cronbach alpha at snapshot time.',
  metrics_json    JSON            NOT NULL                  COMMENT 'Strengths, needs-review, action count, etc.',
  KEY idx_ss_survey_at (survey_id, snapshot_at),
  CONSTRAINT fk_ss_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW TABLES LIKE 'survey_snapshots';
DESCRIBE survey_snapshots;

-- Roll-back (if you ever need it):
-- DROP TABLE IF EXISTS survey_snapshots;
