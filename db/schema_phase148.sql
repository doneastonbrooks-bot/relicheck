-- Phase 148: Server-backed Reports.
-- Replaces the localStorage-only Reports view with persistent reports plus
-- shareable links (slug + password + expiry) and scheduled regeneration.
--
-- Two tables:
--   reports         one row per saved report, with a JSON snapshot of the
--                   analytics taken at create/regenerate time.
--   report_shares   public share links (slug + optional password hash +
--                   optional expiry). Mirrors the public_dashboard_links
--                   pattern from Phase 42 so the UI flow feels identical.
--
-- Run order: select dbs15641829 in phpMyAdmin, then paste this whole file.

USE dbs15641829;

-- -----------------------------------------------------------------
-- reports
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reports (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  source_survey_id  BIGINT UNSIGNED NOT NULL,
  title             VARCHAR(240)    NOT NULL,
  template          VARCHAR(40)     NOT NULL DEFAULT 'executive',
  status            VARCHAR(20)     NOT NULL DEFAULT 'draft',
  snapshot_json     MEDIUMTEXT      NULL,
  schedule_cadence  VARCHAR(20)     NULL,
  schedule_next_at  DATETIME        NULL,
  last_generated_at DATETIME        NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_reports_user         (user_id, updated_at),
  KEY idx_reports_survey       (source_survey_id),
  KEY idx_reports_schedule_due (schedule_next_at),
  CONSTRAINT fk_reports_user   FOREIGN KEY (user_id)          REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_reports_survey FOREIGN KEY (source_survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------
-- report_shares  (public share links)
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS report_shares (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_id       BIGINT UNSIGNED NOT NULL,
  slug            VARCHAR(24)     NOT NULL,
  password_hash   VARCHAR(255)    NULL,
  expires_at      DATETIME        NULL,
  view_count      INT UNSIGNED    NOT NULL DEFAULT 0,
  created_by      BIGINT UNSIGNED NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_report_shares_slug (slug),
  KEY idx_report_shares_report     (report_id),
  CONSTRAINT fk_report_shares_report FOREIGN KEY (report_id)  REFERENCES reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_shares_user   FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW TABLES LIKE 'reports';
SHOW TABLES LIKE 'report_shares';
DESCRIBE reports;
DESCRIBE report_shares;
SELECT COUNT(*) AS report_rows FROM reports;
SELECT COUNT(*) AS share_rows  FROM report_shares;

-- Roll-back:
-- DROP TABLE IF EXISTS report_shares;
-- DROP TABLE IF EXISTS reports;
