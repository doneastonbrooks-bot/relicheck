-- Phase 152: webhook delivery log.
-- Adds a per-fire history row so the dedicated Webhooks view can show the
-- last 50 deliveries per hook with status, timing, and an excerpt of the
-- response body or error message. The Phase 30 dispatcher
-- (api/_webhooks.php) writes one row on every fire (success or failure)
-- and trims the oldest beyond 50 per webhook.
--
-- Run order: select dbs15641829 in phpMyAdmin, paste this whole file.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  webhook_id        BIGINT UNSIGNED NOT NULL,
  event             VARCHAR(80)     NOT NULL,
  http_status       SMALLINT        NULL,
  response_excerpt  VARCHAR(500)    NULL
    COMMENT 'First 500 chars of the response body (truncated).',
  error             VARCHAR(255)    NULL
    COMMENT 'Truncated curl/network error if the request never reached HTTP.',
  duration_ms       INT UNSIGNED    NULL,
  payload_json      MEDIUMTEXT      NULL
    COMMENT 'Full request body so the Replay action can re-fire the same payload.',
  is_test           TINYINT(1)      NOT NULL DEFAULT 0
    COMMENT '1 when fired by the Test button on the webhook detail view.',
  fired_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_webhook_deliveries_hook (webhook_id, fired_at),
  CONSTRAINT fk_webhook_deliveries_hook
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW TABLES LIKE 'webhook_deliveries';
DESCRIBE webhook_deliveries;
SELECT COUNT(*) AS delivery_rows FROM webhook_deliveries;

-- Roll-back:
-- DROP TABLE IF EXISTS webhook_deliveries;
