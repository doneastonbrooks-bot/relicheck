-- Phase 24 migration: account pause for vacation / break / billing freeze.
-- Semantically distinct from lock (which is for security/abuse) and from
-- cancel (which ends the membership). A paused account cannot sign in,
-- but the sign-in error message is friendly and points at support.
--
-- paused_at      DATETIME NULL  — when set, the account is paused.
-- paused_reason  VARCHAR(500)   — free-text reason recorded at pause time.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN paused_at     DATETIME     NULL DEFAULT NULL
    COMMENT 'When set, the account is paused (no sign-in). Cleared on reactivate.',
  ADD COLUMN paused_reason VARCHAR(500) NULL DEFAULT NULL
    COMMENT 'Free-text reason recorded at pause time.',
  ADD KEY idx_users_paused (paused_at);

-- Verification: should return TWO rows, paused_at and paused_reason.
SHOW COLUMNS FROM users LIKE 'paused%';
