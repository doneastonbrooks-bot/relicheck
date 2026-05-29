-- ReliCheck — SDSI Dignity / Framing Readiness review persistence.
-- Run once via phpMyAdmin. Idempotent (CREATE TABLE IF NOT EXISTS).
--
-- Dignity / Framing is a PRE-DATA validity subdomain inside the Instrument
-- Quality app. The AI proposes wording flags; a human settles each one; the
-- deterministic DignityEngine scores from the settled flags. This table stores
-- one settled review per survey (the latest), so the score, the evidence
-- ledger, and the launch-gate state survive a reload and feed the report.
--
-- The proposal text and item quotes are derived from the survey's `questions`
-- column at propose time; we persist only the SETTLED arrays + the computed
-- result, never a live AI call's raw output.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS sdsi_dignity_reviews (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id     BIGINT UNSIGNED NOT NULL,
  owner_id      BIGINT UNSIGNED NOT NULL,
  population    JSON NOT NULL,             -- { minors, peopleFacing, communities[] }
  flags         JSON NOT NULL,             -- settled flags (each with decision + severity)
  mitigations   JSON NOT NULL,             -- settled mitigations (each with decision)
  score         SMALLINT UNSIGNED NOT NULL,   -- 0..100 deterministic dignity score
  sdsi_points   DECIMAL(3,1) NOT NULL,        -- 0.0..8.0 contribution to validity readiness
  band          VARCHAR(24) NOT NULL,         -- strong / good / moderate / significant / high
  launch_ready  TINYINT(1) NOT NULL DEFAULT 0,-- orthogonal gate state (1 = no unreviewed blockers)
  blocker_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_dignity_survey (survey_id),
  KEY idx_dignity_owner (owner_id),
  CONSTRAINT fk_dignity_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
