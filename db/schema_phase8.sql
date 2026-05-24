-- Phase 8 migration: tier columns on users + tier_changes audit table.
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

-- 1. Add tier columns. The tier column is a small enumerated string so we
--    can add new tiers later without further migrations.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS tier VARCHAR(20) NOT NULL DEFAULT 'free',
  ADD COLUMN IF NOT EXISTS tier_expires_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS tier_changed_at DATETIME NULL;

-- Some MySQL versions don't support IF NOT EXISTS on columns. If the above
-- errors, run these one at a time and skip ones that already exist:
--   ALTER TABLE users ADD COLUMN tier VARCHAR(20) NOT NULL DEFAULT 'free';
--   ALTER TABLE users ADD COLUMN tier_expires_at DATETIME NULL;
--   ALTER TABLE users ADD COLUMN tier_changed_at DATETIME NULL;

-- 2. Audit log for tier changes. We don't strictly need this yet, but it
--    makes Stripe webhook integration much cleaner later: each subscription
--    event (created, updated, cancelled, refunded) writes a row here.
CREATE TABLE IF NOT EXISTS tier_changes (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  from_tier     VARCHAR(20) NULL,
  to_tier       VARCHAR(20) NOT NULL,
  expires_at    DATETIME NULL,
  reason        VARCHAR(60) NULL,    -- 'admin', 'signup', 'stripe_subscription_created', etc.
  source_ref    VARCHAR(120) NULL,   -- e.g., a Stripe subscription id
  changed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_tier_changes_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
