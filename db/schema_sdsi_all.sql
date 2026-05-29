-- ReliCheck — SIRI/SDSI review tables: COMBINED INSTALL FILE.
-- ============================================================================
-- WHAT THIS FILE DOES
--   1. This file installs the SIRI/SDSI review tables — the persistence layer
--      behind the SIRI (Survey Instrument Readiness Index) dashboard.
--   2. It ASSUMES the base `surveys` table already exists. Every table below
--      declares a foreign key to surveys(id); the install will fail if that
--      base table is missing.
--   3. It is safe and idempotent: each table uses CREATE TABLE IF NOT EXISTS,
--      so re-running this file will not error or drop existing data.
--   4. Run this AFTER the base app schema (which creates `surveys` and the
--      other core tables).
--   5. It creates the FIVE review tables read by the SIRI dashboard:
--        sdsi_dignity_reviews, sdsi_access_reviews, sdsi_validity_reviews,
--        sdsi_reliability_reviews, sdsi_administration_reviews.
--
-- CONVENIENCE ONLY
--   This is a convenience aggregate of the five individual schema files
--   (db/schema_sdsi_dignity.sql, schema_sdsi_access.sql, schema_sdsi_validity.sql,
--   schema_sdsi_reliability.sql, schema_sdsi_administration.sql). The table
--   definitions are copied verbatim from those files. You may run either the
--   five files individually or this single combined file — the result is
--   identical.
--
-- ORDER
--   The order among the five tables does not matter technically (they reference
--   only surveys(id), never each other). The order below is kept because it
--   matches the SIRI domain flow:
--     1. Dignity / Framing      -> sdsi_dignity_reviews
--     2. Access                 -> sdsi_access_reviews
--     3. Validity / SDSI        -> sdsi_validity_reviews
--     4. Reliability Readiness  -> sdsi_reliability_reviews
--     5. Administration Readiness -> sdsi_administration_reviews
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ----------------------------------------------------------------------------
-- 1. Dignity / Framing  (copied from db/schema_sdsi_dignity.sql)
--    PRE-DATA validity subdomain. One settled review per survey (the latest).
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 2. Access  (copied from db/schema_sdsi_access.sql)
--    Second PRE-DATA validity lens. One settled review per survey (the latest).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sdsi_access_reviews (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id     BIGINT UNSIGNED NOT NULL,
  owner_id      BIGINT UNSIGNED NOT NULL,
  population    JSON NOT NULL,             -- { minors, peopleFacing, communities[] }
  flags         JSON NOT NULL,             -- settled flags (each with decision + severity)
  mitigations   JSON NOT NULL,             -- settled mitigations (each with decision)
  score         SMALLINT UNSIGNED NOT NULL,   -- 0..100 deterministic access score
  sdsi_points   DECIMAL(3,1) NOT NULL,        -- 0.0..8.0 contribution to validity readiness
  band          VARCHAR(24) NOT NULL,         -- strong / good / moderate / significant / high
  launch_ready  TINYINT(1) NOT NULL DEFAULT 0,-- orthogonal gate state (1 = no unreviewed blockers)
  blocker_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_access_survey (survey_id),
  KEY idx_access_owner (owner_id),
  CONSTRAINT fk_access_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Validity / SDSI  (copied from db/schema_sdsi_validity.sql)
--    Five factory components, keyed by `component`.
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 4. Reliability Readiness  (copied from db/schema_sdsi_reliability.sql)
--    Five factory components, keyed by `component`. ALPHA FENCE: pre-data only;
--    no alpha/omega/item-total/factor stats — those belong to RSSI.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sdsi_reliability_reviews (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id     BIGINT UNSIGNED NOT NULL,
  component     VARCHAR(32) NOT NULL,         -- scale_structure_readiness | item_clarity | response_scale_consistency | redundancy_balance | administration_consistency
  owner_id      BIGINT UNSIGNED NOT NULL,
  context       JSON NOT NULL,                -- reviewer-declared facts (construct, definition, declared_scales[], score_reporting, modes[], has_branching, …)
  flags         JSON NOT NULL,                -- settled flags (each with decision + severity)
  mitigations   JSON NOT NULL,                -- always [] for the five (kept for spine parity)
  score         SMALLINT UNSIGNED NOT NULL,   -- 0..100 deterministic component score
  sdsi_points   DECIMAL(3,1) NOT NULL,        -- 0.0..8.0 contribution to reliability readiness (weight varies by component)
  band          VARCHAR(24) NOT NULL,         -- strong / good / moderate / significant / high
  launch_ready  TINYINT(1) NOT NULL DEFAULT 0,-- orthogonal gate state (1 = no unreviewed blockers)
  blocker_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reliability_survey_component (survey_id, component),
  KEY idx_reliability_owner (owner_id),
  CONSTRAINT fk_reliability_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Administration Readiness  (copied from db/schema_sdsi_administration.sql)
--    Five factory components, keyed by `component`. PRE-LAUNCH scope only;
--    no survey results / post-administration data quality — those belong to RSSI.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sdsi_administration_reviews (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id     BIGINT UNSIGNED NOT NULL,
  component     VARCHAR(32) NOT NULL,         -- respondent_instructions | consent_privacy | fielding_plan | sensitive_safety | completion_burden
  owner_id      BIGINT UNSIGNED NOT NULL,
  context       JSON NOT NULL,                -- reviewer-declared facts (participation_status, privacy_status, target_population, launch_window, …)
  flags         JSON NOT NULL,                -- settled flags (each with decision + severity)
  mitigations   JSON NOT NULL,                -- always [] for the five (kept for spine parity)
  score         SMALLINT UNSIGNED NOT NULL,   -- 0..100 deterministic component score
  sdsi_points   DECIMAL(3,1) NOT NULL,        -- 0.0..4.0 contribution to administration readiness (weight varies by component)
  band          VARCHAR(24) NOT NULL,         -- strong / good / moderate / significant / high
  launch_ready  TINYINT(1) NOT NULL DEFAULT 0,-- orthogonal gate state (1 = no unreviewed blockers)
  blocker_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_administration_survey_component (survey_id, component),
  KEY idx_administration_owner (owner_id),
  CONSTRAINT fk_administration_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
