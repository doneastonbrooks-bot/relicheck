-- Phase 42 migration: shareable public-dashboard links.
--   * public_dashboard_links - one row per shareable read-only dashboard URL.
--     Optional password + optional expiry. Slug is the unguessable public id.
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- FULLY IDEMPOTENT.

USE dbs15641829;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS public_dashboard_links (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  survey_id       BIGINT UNSIGNED NOT NULL,
  slug            CHAR(24)        NOT NULL COMMENT 'Unguessable public slug used in /dashboard.html?s=...',
  password_hash   VARCHAR(255)    NULL     COMMENT 'bcrypt hash; NULL = no password.',
  expires_at      DATETIME        NULL     COMMENT 'NULL = never expires.',
  view_count      INT UNSIGNED    NOT NULL DEFAULT 0,
  created_by      BIGINT UNSIGNED NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_pdl_slug (slug),
  KEY idx_pdl_survey (survey_id),
  CONSTRAINT fk_pdl_survey  FOREIGN KEY (survey_id)  REFERENCES surveys(id) ON DELETE CASCADE,
  CONSTRAINT fk_pdl_creator FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW TABLES LIKE 'public_dashboard_links';
SELECT COUNT(*) AS link_rows FROM public_dashboard_links;

-- Roll-back (run only if you need to undo):
-- DROP TABLE IF EXISTS public_dashboard_links;
