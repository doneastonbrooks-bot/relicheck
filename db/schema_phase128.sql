-- Phase 128: calendar email send + post-meeting auto-fire.
-- Calendar-attached surveys (Phase 126) shipped as a download-only .ics
-- generator. Phase 128 adds two operational pieces:
--   1. "Send invite to attendees" sends the survey-take card to the
--      provided attendee list right now using the existing Phase 31 email
--      pipeline.
--   2. Optionally, a calendar_followups row schedules an automatic
--      survey fire at the meeting end time plus a configurable delay.
--      The hourly cron at /api/cron-fire-calendar-followups.php drains
--      due rows, creates invitations tagged with a "Post-<event>" wave
--      label, and queues emails.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS calendar_followups (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  survey_id       BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  event_title     VARCHAR(200)    NOT NULL,
  event_start_at  DATETIME        NOT NULL,
  event_end_at    DATETIME        NOT NULL,
  event_location  VARCHAR(200)    NULL,
  fire_at         DATETIME        NOT NULL                              COMMENT 'When the cron should fire the follow-up. Usually event_end_at + delay_minutes.',
  delay_minutes   INT UNSIGNED    NOT NULL DEFAULT 30,
  attendees_json  JSON            NOT NULL                              COMMENT 'Array of {email, name?} the follow-up fires to.',
  wave_label      VARCHAR(120)    NOT NULL                              COMMENT 'Channel tag stamped on responses, e.g. "Post-Engagement Pulse Q2".',
  status          ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  fired_at        DATETIME        NULL,
  fired_count     INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cf_due (status, fire_at),
  KEY idx_cf_survey (survey_id),
  KEY idx_cf_user (user_id),
  CONSTRAINT fk_cf_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW TABLES LIKE 'calendar_followups';
DESCRIBE calendar_followups;

-- Roll-back (if you ever need it):
-- DROP TABLE IF EXISTS calendar_followups;
