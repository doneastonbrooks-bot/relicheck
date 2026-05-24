-- Phase 133: Suites (real-container workspace grouping).
-- A suite is a workflow package: templates, distribution, analysis, AI
-- narration, and reports for a specific use case. Seven system suites
-- are auto-seeded per user the first time they hit /api/suites/list.php:
-- 360 Feedback, HR & Teams, Pulse Survey, Program Evaluation, Education,
-- Researcher, Customer Experience. Users can also create custom suites.
--
-- Surveys belong to zero or more suites via the suite_surveys join.
-- Many-to-many so a "Change readiness pulse" survey can live in both
-- the HR Suite and the Pulse Suite.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS suites (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  suite_key     VARCHAR(40)     NOT NULL                              COMMENT 'Stable key. For system suites: 360, hr, pulse, program_eval, education, researcher, cx. For custom suites: slugified name.',
  name          VARCHAR(160)    NOT NULL,
  description   VARCHAR(500)    NULL,
  color         VARCHAR(20)     NOT NULL DEFAULT '#1F3A8A'            COMMENT 'Hex color for suite chip and accent.',
  icon          VARCHAR(20)     NOT NULL DEFAULT 'box'                COMMENT 'Short icon key for the UI.',
  is_system     TINYINT(1)      NOT NULL DEFAULT 0                    COMMENT '1 for one of the 7 pre-seeded suites; 0 for user-created.',
  display_order INT             NOT NULL DEFAULT 100,
  status        ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_suite_per_user (user_id, suite_key),
  KEY idx_suite_user (user_id),
  KEY idx_suite_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Constraint names must be globally unique per database in MySQL. The
-- short fk_ss_* names collide with constraints on other ReliCheck tables
-- (e.g. survey_schedules / survey_sections). Use table-prefixed names.
CREATE TABLE IF NOT EXISTS suite_surveys (
  suite_id   BIGINT UNSIGNED NOT NULL,
  survey_id  BIGINT UNSIGNED NOT NULL,
  added_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (suite_id, survey_id),
  KEY idx_suite_surveys_survey (survey_id),
  CONSTRAINT fk_suite_surveys_suite  FOREIGN KEY (suite_id)  REFERENCES suites(id)  ON DELETE CASCADE,
  CONSTRAINT fk_suite_surveys_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW TABLES LIKE 'suites';
SHOW TABLES LIKE 'suite_surveys';
DESCRIBE suites;
DESCRIBE suite_surveys;
SELECT COUNT(*) AS suite_rows FROM suites;
SELECT COUNT(*) AS join_rows  FROM suite_surveys;

-- Roll-back:
-- DROP TABLE IF EXISTS suite_surveys;
-- DROP TABLE IF EXISTS suites;
