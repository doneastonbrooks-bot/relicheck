-- Phase 119: pulse cadence schedules.
-- A schedule fires the same survey to the same contact list on a recurring
-- cadence (weekly / biweekly / monthly / quarterly / custom). Each wave
-- creates one invitation per active contact, tagged with the wave label
-- so analytics (Compare, Pre/Post, Test-retest) can slice by wave.

-- Pick the database first so phpMyAdmin runs the rest in the right context.
USE dbs15641829;

CREATE TABLE IF NOT EXISTS survey_schedules (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  survey_id       BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  name            VARCHAR(120)    NOT NULL,
  cadence         ENUM('weekly','biweekly','monthly','quarterly','custom') NOT NULL DEFAULT 'monthly',
  interval_days   INT UNSIGNED    NULL                                 COMMENT 'Used when cadence = custom; otherwise derived (7, 14, 30, 90).',
  wave_template   VARCHAR(120)    NOT NULL DEFAULT 'Pulse {n}'         COMMENT 'Template for wave labels. {n} = sequence number, {date} = YYYY-MM-DD.',
  start_at        DATETIME        NOT NULL                              COMMENT 'When the schedule first fires.',
  next_fire_at    DATETIME        NOT NULL                              COMMENT 'When the schedule fires next; advanced by the cron after each fire.',
  last_fired_at   DATETIME        NULL,
  fired_count     INT UNSIGNED    NOT NULL DEFAULT 0,
  end_at          DATETIME        NULL                                  COMMENT 'Optional auto-complete date; schedule status flips to completed after this.',
  status          ENUM('active','paused','completed') NOT NULL DEFAULT 'active',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sched_survey (survey_id),
  KEY idx_sched_user (user_id),
  KEY idx_sched_due (status, next_fire_at),
  CONSTRAINT fk_sched_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add schedule_id + wave_label to survey_invitations so each invitation
-- knows which wave it belongs to. Idempotent via the information_schema
-- procedure pattern.

DROP PROCEDURE IF EXISTS _phase119_add_columns;
DELIMITER //
CREATE PROCEDURE _phase119_add_columns()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'survey_invitations'
      AND COLUMN_NAME = 'schedule_id'
  ) THEN
    ALTER TABLE survey_invitations ADD COLUMN schedule_id BIGINT UNSIGNED NULL AFTER contact_id;
    ALTER TABLE survey_invitations ADD KEY idx_inv_schedule (schedule_id);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'survey_invitations'
      AND COLUMN_NAME = 'wave_label'
  ) THEN
    ALTER TABLE survey_invitations ADD COLUMN wave_label VARCHAR(120) NULL AFTER schedule_id;
  END IF;
END//
DELIMITER ;
CALL _phase119_add_columns();
DROP PROCEDURE _phase119_add_columns;

-- Verification.
SHOW TABLES LIKE 'survey_schedules';
DESCRIBE survey_schedules;
SHOW COLUMNS FROM survey_invitations LIKE 'schedule_id';
SHOW COLUMNS FROM survey_invitations LIKE 'wave_label';

-- Roll-back (if you ever need it):
-- DROP TABLE IF EXISTS survey_schedules;
-- ALTER TABLE survey_invitations DROP COLUMN wave_label;
-- ALTER TABLE survey_invitations DROP COLUMN schedule_id;
