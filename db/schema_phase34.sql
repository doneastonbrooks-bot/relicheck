-- Phase 34 migration: last_login_ip_hash column for new-device detection.
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- Adds a single column to users. api/auth/login.php compares the current
-- request's hashed IP to this column on every successful sign-in; if they
-- differ AND the account has logged in before, the new email system fires
-- auth.new_device_or_location to send the customer a security alert.
--
-- Until this column exists, login.php silently skips the new-device check
-- (the lookup is wrapped in try/catch). So this migration is optional and
-- non-breaking; running it just enables the alert.

USE dbs15641829;

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN last_login_ip_hash CHAR(64) NULL DEFAULT NULL
    COMMENT 'SHA-256 of last successful login IP. Used to detect new-device sign-ins for the auth.new_device_or_location email.';

-- Verification.
SHOW COLUMNS FROM users LIKE 'last_login_ip_hash';

-- Roll-back (run only if you need to undo this migration):
-- ALTER TABLE users DROP COLUMN last_login_ip_hash;
