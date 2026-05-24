-- Phase 30 migration: outbound webhooks for survey events.
-- Owners can register webhook URLs (Slack incoming webhooks, Zapier catch-hook
-- URLs, custom endpoints, etc.) and choose which events should fire to each.
-- Each fire POSTs a JSON body with an HMAC-SHA256 signature header derived
-- from the per-webhook secret, so receivers can verify the request came from us.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS webhooks (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id        BIGINT UNSIGNED NOT NULL COMMENT 'Workspace owner the webhook belongs to.',
  name            VARCHAR(120)    NOT NULL COMMENT 'Human label, e.g. "Team Slack channel".',
  url             VARCHAR(2048)   NOT NULL COMMENT 'Destination URL (HTTPS only enforced in app).',
  secret          CHAR(64)        NOT NULL COMMENT 'Hex-encoded random secret used for HMAC signing.',
  events          JSON            NOT NULL COMMENT 'Array of event names this webhook subscribes to, e.g. ["response.received","survey.published"].',
  active          TINYINT(1)      NOT NULL DEFAULT 1,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_fired_at   DATETIME        NULL COMMENT 'Most recent fire attempt timestamp.',
  last_status     SMALLINT        NULL COMMENT 'Most recent HTTP status code returned by the destination (or NULL on network error).',
  last_error      VARCHAR(255)    NULL COMMENT 'Truncated message on the most recent failure.',
  total_fires     INT UNSIGNED    NOT NULL DEFAULT 0,
  failed_fires    INT UNSIGNED    NOT NULL DEFAULT 0,
  KEY idx_webhooks_owner_active (owner_id, active),
  CONSTRAINT fk_webhooks_owner
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification: should return one row, then a count of zero (fresh table).
SHOW TABLES LIKE 'webhooks';
SELECT COUNT(*) AS webhook_rows FROM webhooks;

-- Roll-back block (run only if you need to undo this migration):
-- DROP TABLE IF EXISTS webhooks;
