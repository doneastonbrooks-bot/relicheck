-- Phase 121: Slack and Microsoft Teams distribution channels.
-- Per-survey pasted webhook URLs (Slack incoming webhook or Teams webhook).
-- The Distribute view can push the survey share link as a Slack Block Kit
-- card or Teams Adaptive Card to each channel; the Phase 119 pulse cadence
-- cron also fires each scheduled wave to every active channel.

-- Pick the database first so phpMyAdmin runs the rest in the right context.
USE dbs15641829;

CREATE TABLE IF NOT EXISTS survey_channels (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  survey_id       BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  label           VARCHAR(120)    NOT NULL                         COMMENT 'Friendly name shown in the Distribute UI.',
  kind            ENUM('slack','teams') NOT NULL                   COMMENT 'Destination type. Drives payload format.',
  webhook_url     VARCHAR(500)    NOT NULL                         COMMENT 'Pasted incoming-webhook URL provided by Slack or Teams.',
  status          ENUM('active','paused') NOT NULL DEFAULT 'active',
  last_fired_at   DATETIME        NULL,
  last_status     VARCHAR(40)     NULL                             COMMENT 'Last delivery outcome: ok / failed / forbidden / ...',
  last_response   VARCHAR(255)    NULL                             COMMENT 'Truncated body of the most recent delivery for debugging.',
  fired_count     INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_channels_survey (survey_id),
  KEY idx_channels_user (user_id),
  CONSTRAINT fk_channels_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW TABLES LIKE 'survey_channels';
DESCRIBE survey_channels;

-- Roll-back (if you ever need it):
-- DROP TABLE IF EXISTS survey_channels;
