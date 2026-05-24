-- Phase 23 migration: account flag for review.
-- Adds two columns to users so admins can flag suspicious or
-- problematic accounts for upper-management review without
-- locking them out.
--
-- flagged_at      DATETIME NULL  — when set, the account is flagged.
-- flagged_reason  VARCHAR(500)   — free-text reason recorded at flag time.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN flagged_at     DATETIME     NULL DEFAULT NULL
    COMMENT 'When set, the account is flagged for review. Cleared on unflag.',
  ADD COLUMN flagged_reason VARCHAR(500) NULL DEFAULT NULL
    COMMENT 'Free-text reason recorded at flag time.',
  ADD KEY idx_users_flagged (flagged_at);

-- Verification: should return TWO rows, flagged_at and flagged_reason.
SHOW COLUMNS FROM users LIKE 'flagged%';
