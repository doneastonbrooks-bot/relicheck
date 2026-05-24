-- Phase 129: 360 / multi-rater surveys.
-- A "panel" binds one existing survey to a set of subjects (people being
-- evaluated) and the evaluators who rate them. The survey runs once per
-- (subject, evaluator) pair, with each pair carrying a unique tokenized
-- link tagged via the Phase 41 channel convention as
-- "360-S<subject_id>-R<rel_short>" so existing analytics (Compare,
-- Subgroups, Pre/Post) can slice by rater relationship without any
-- changes to the existing schema beyond the three new tables below.

-- IMPORTANT: select the database first (drop-down at the top-left of
-- phpMyAdmin should read "dbs15641829") before running.

USE dbs15641829;

-- ---------------------------------------------------------------------------
-- 1. survey_360_panels
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS survey_360_panels (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  survey_id             BIGINT UNSIGNED NOT NULL,
  user_id               BIGINT UNSIGNED NOT NULL,
  name                  VARCHAR(160)    NOT NULL,
  status                ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
  self_assessment       TINYINT(1)      NOT NULL DEFAULT 0   COMMENT 'If 1, evaluators with relationship=self are auto-created for each subject at launch time.',
  confidentiality_mode  ENUM('anonymous','named') NOT NULL DEFAULT 'anonymous' COMMENT 'anonymous = subject report shows aggregates only.',
  launched_at           DATETIME        NULL,
  closed_at             DATETIME        NULL,
  created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_p360_user (user_id),
  KEY idx_p360_survey (survey_id),
  KEY idx_p360_status (status),
  CONSTRAINT fk_p360_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. survey_360_subjects
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS survey_360_subjects (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  panel_id      BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(120)    NOT NULL,
  email         VARCHAR(255)    NULL                                   COMMENT 'Optional. Used to deliver the subject report.',
  title         VARCHAR(120)    NULL,
  department    VARCHAR(120)    NULL,
  external_ref  VARCHAR(120)    NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_s360_panel (panel_id),
  CONSTRAINT fk_s360_panel FOREIGN KEY (panel_id) REFERENCES survey_360_panels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. survey_360_evaluators
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS survey_360_evaluators (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  panel_id          BIGINT UNSIGNED NOT NULL,
  subject_id        BIGINT UNSIGNED NOT NULL,
  evaluator_email   VARCHAR(255)    NOT NULL,
  evaluator_name    VARCHAR(120)    NULL,
  relationship      ENUM('self','manager','peer','direct_report','external') NOT NULL DEFAULT 'peer',
  invitation_id     BIGINT UNSIGNED NULL                                  COMMENT 'FK to survey_invitations after launch.',
  status            ENUM('pending','queued','sent','opened','completed','failed','unsubscribed') NOT NULL DEFAULT 'pending',
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_e360_subject_email (subject_id, evaluator_email),
  KEY idx_e360_panel (panel_id),
  KEY idx_e360_status (status),
  KEY idx_e360_invitation (invitation_id),
  CONSTRAINT fk_e360_panel    FOREIGN KEY (panel_id)   REFERENCES survey_360_panels(id)   ON DELETE CASCADE,
  CONSTRAINT fk_e360_subject  FOREIGN KEY (subject_id) REFERENCES survey_360_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Verification.
-- ---------------------------------------------------------------------------
SHOW TABLES LIKE 'survey_360_panels';
SHOW TABLES LIKE 'survey_360_subjects';
SHOW TABLES LIKE 'survey_360_evaluators';
DESCRIBE survey_360_panels;
DESCRIBE survey_360_subjects;
DESCRIBE survey_360_evaluators;
SELECT COUNT(*) AS panels      FROM survey_360_panels;
SELECT COUNT(*) AS subjects    FROM survey_360_subjects;
SELECT COUNT(*) AS evaluators  FROM survey_360_evaluators;
-- Expected: 3 tables present, 0/0/0 rows on first install.

-- Roll-back (run only if you need to undo this migration):
-- DROP TABLE IF EXISTS survey_360_evaluators;
-- DROP TABLE IF EXISTS survey_360_subjects;
-- DROP TABLE IF EXISTS survey_360_panels;
