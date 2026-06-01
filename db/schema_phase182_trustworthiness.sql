-- ============================================================
-- Phase 182 — MM Trustworthiness storage
-- Run in phpMyAdmin against dbs15641829.
-- Idempotent: re-running is safe.
--
-- Backs the qualitative "Trustworthiness" step in MM Studio v4. Only the
-- member-checking log needs storage; the audit trail is derived from existing
-- project events and the coding agreement (kappa) is computed on demand from
-- mm_coded_responses, so neither is stored here. One row per project.
--
-- NOTE: this touches NO scoring/RSSI/SIRI tables. mm_trustworthiness is a new,
-- self-contained table read only by api/mm/trustworthiness.php.
-- ============================================================
USE dbs15641829;

-- No FOREIGN KEY to mm_projects on purpose: the endpoint only reads/writes by
-- project_id and never relies on cascade delete, and an FK to mm_projects can
-- fail on shared hosting with an errno-150 type/charset/engine mismatch. Keeping
-- this table self-contained makes the migration run reliably everywhere.
CREATE TABLE IF NOT EXISTS mm_trustworthiness (
  project_id       BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  member_checking  MEDIUMTEXT      NULL
    COMMENT 'JSON array of member-checking entries: [{id, finding, date, outcome, feedback}, ...]',
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
