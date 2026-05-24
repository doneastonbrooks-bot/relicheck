-- Phase 20 migration: admin audit log.
-- Single immutable append-only table that records every administrative
-- action performed via the admin panel. Indexed for the four common
-- read patterns: by recency, by actor, by category, and by target.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_audit (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ts            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_user_id INT UNSIGNED NOT NULL,
  actor_email   VARCHAR(190) NOT NULL,
  actor_role    VARCHAR(32)  NOT NULL DEFAULT 'owner',
  action        VARCHAR(80)  NOT NULL
    COMMENT 'Human-readable verb, e.g. "Reset password", "Canceled membership"',
  category      VARCHAR(32)  NOT NULL
    COMMENT 'customer | employee | membership | promo | auth | security | system',
  severity      VARCHAR(16)  NOT NULL DEFAULT 'info'
    COMMENT 'info | warn | critical',
  target_type   VARCHAR(32)  NULL
    COMMENT 'customer | employee | promo | plan | session | etc.',
  target_id     VARCHAR(64)  NULL
    COMMENT 'Stable id of the target row (user id, promo code, plan id)',
  target_label  VARCHAR(255) NULL
    COMMENT 'Human-readable target, e.g. "Maria Vasquez (cus_1001)"',
  before_value  VARCHAR(500) NULL,
  after_value   VARCHAR(500) NULL,
  reason        VARCHAR(500) NULL
    COMMENT 'Required for sensitive actions; recorded verbatim from the confirmation modal',
  ip            VARCHAR(64)  NULL,
  user_agent    VARCHAR(255) NULL,
  KEY idx_audit_ts        (ts),
  KEY idx_audit_actor     (actor_user_id, ts),
  KEY idx_audit_category  (category, ts),
  KEY idx_audit_target    (target_type, target_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification: should return one row showing the new table.
SHOW TABLES LIKE 'admin_audit';

-- Roll-back, if you ever need it:
-- USE dbs15641829;
-- DROP TABLE admin_audit;
