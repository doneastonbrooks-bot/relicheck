-- ReliCheck — SDSI Validity Readiness review persistence (the five factory lenses).
-- Run once via phpMyAdmin. Idempotent (CREATE TABLE IF NOT EXISTS).
--
-- The five remaining Validity Readiness components — construct_definition,
-- purpose_alignment, dimension_coverage, item_construct_alignment, and
-- response_option_validity — share one engine (apps/sdsi/validity-lens-engine.js)
-- and one table, keyed by `component`. Dignity/Framing and Access keep their own
-- tables (they ship as standalone engines). For each component the AI proposes
-- flags, a human settles each one, and the deterministic factory lens scores
-- from the settled flags. This table stores one settled review per
-- (survey, component) pair so the score, the evidence ledger, and the launch-gate
-- state survive a reload and feed the report.
--
-- `context` holds the reviewer-declared facts the lens reads (construct,
-- definition, purpose, intended_use, dimensions) — the surveys table does not
-- store these, so the reviewer supplies them. Item quotes are derived from the
-- survey's `questions` column at propose time; we persist only the SETTLED
-- arrays + the computed result, never a live AI call's raw output.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS sdsi_validity_reviews (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id     BIGINT UNSIGNED NOT NULL,
  component     VARCHAR(32) NOT NULL,         -- construct_definition | purpose_alignment | dimension_coverage | item_construct_alignment | response_option_validity
  owner_id      BIGINT UNSIGNED NOT NULL,
  context       JSON NOT NULL,                -- reviewer-declared facts (construct, definition, purpose, intended_use, dimensions[])
  flags         JSON NOT NULL,                -- settled flags (each with decision + severity)
  mitigations   JSON NOT NULL,                -- always [] for the five (kept for spine parity)
  score         SMALLINT UNSIGNED NOT NULL,   -- 0..100 deterministic component score
  sdsi_points   DECIMAL(3,1) NOT NULL,        -- 0.0..8.0 contribution to validity readiness (weight varies by component)
  band          VARCHAR(24) NOT NULL,         -- strong / good / moderate / significant / high
  launch_ready  TINYINT(1) NOT NULL DEFAULT 0,-- orthogonal gate state (1 = no unreviewed blockers)
  blocker_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_validity_survey_component (survey_id, component),
  KEY idx_validity_owner (owner_id),
  CONSTRAINT fk_validity_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
