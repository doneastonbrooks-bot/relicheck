-- Phase 14 migration: promotional codes for tier access.
-- Two tables: promo_codes (the codes themselves) and promo_redemptions
-- (one row per (code, user) so we can prevent double-use and audit who redeemed what).
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS promo_codes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40)     NOT NULL,                 -- canonical uppercase code
  tier_key      VARCHAR(40)     NOT NULL,                 -- e.g., 'researcher'
  duration_days INT UNSIGNED    NULL,                     -- NULL = permanent
  max_uses      INT UNSIGNED    NULL,                     -- NULL = unlimited
  uses_count    INT UNSIGNED    NOT NULL DEFAULT 0,
  expires_at    DATETIME        NULL,                     -- code itself stops working after this
  notes         VARCHAR(500)    NULL,
  is_active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_by    BIGINT UNSIGNED NOT NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_promo_code (code),
  KEY idx_active_expires (is_active, expires_at),
  CONSTRAINT fk_promo_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promo_redemptions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code_id       BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  tier_granted  VARCHAR(40)     NOT NULL,
  expires_at    DATETIME        NULL,                     -- NULL = permanent grant
  redeemed_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_redemption (code_id, user_id),
  KEY idx_user_expires (user_id, expires_at),
  CONSTRAINT fk_redemption_code FOREIGN KEY (code_id) REFERENCES promo_codes(id) ON DELETE CASCADE,
  CONSTRAINT fk_redemption_user FOREIGN KEY (user_id) REFERENCES users(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
