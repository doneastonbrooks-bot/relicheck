-- Phase 21 migration: account lock for abuse / security handling.
-- Adds two columns to users:
--   locked_at      DATETIME NULL  — when set, the account cannot sign in.
--   locked_reason  VARCHAR(500)   — free-text reason recorded at lock time.
-- Lock state is enforced in api/auth/login.php (refuses sign-in if locked_at
-- is set). admin.html exposes the lock/unlock button on the customer profile.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN locked_at     DATETIME     NULL DEFAULT NULL
    COMMENT 'When set, the user cannot sign in. Cleared on unlock.',
  ADD COLUMN locked_reason VARCHAR(500) NULL DEFAULT NULL
    COMMENT 'Free-text reason recorded at lock time.',
  ADD KEY idx_users_locked (locked_at);

-- Verification: should return one row showing both new columns.
SHOW COLUMNS FROM users LIKE 'locked%';
