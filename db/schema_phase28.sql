-- Phase 28 migration: TOTP-based 2FA for admin sign-in.
-- Adds the secret + enrollment timestamp on staff_users, plus a session
-- status field so admin_sessions can carry a "pending_2fa" state between
-- password-verified and code-verified.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

ALTER TABLE staff_users
  ADD COLUMN totp_secret      VARCHAR(64) NULL DEFAULT NULL
    COMMENT 'Base32-encoded TOTP secret. NULL means 2FA is not enrolled.',
  ADD COLUMN totp_enrolled_at DATETIME    NULL DEFAULT NULL;

ALTER TABLE admin_sessions
  ADD COLUMN status         VARCHAR(20) NOT NULL DEFAULT 'active'
    COMMENT 'active | pending_2fa | pending_setup',
  ADD COLUMN pending_secret VARCHAR(64) NULL DEFAULT NULL
    COMMENT 'Temporary TOTP secret while a first-time enrollment is in progress.';

-- Verification.
SHOW COLUMNS FROM staff_users    LIKE 'totp%';
SHOW COLUMNS FROM admin_sessions LIKE 'status';
SHOW COLUMNS FROM admin_sessions LIKE 'pending_secret';
