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

CREATE TABLE IF NOT EXISTS mm_trustworthiness (
  project_id       BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  member_checking  MEDIUMTEXT      NULL
    COMMENT 'JSON array of member-checking entries: [{id, finding, date, outcome, feedback}, ...]',
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mm_trust_project
    FOREIGN KEY (project_id) REFERENCES mm_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
