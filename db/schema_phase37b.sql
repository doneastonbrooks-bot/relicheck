-- Phase 37b migration: user preferences for customizable home grid.
--   * users.prefs JSON - per-user preferences blob. Currently used to
--     persist the home card layout (visible cards + order). Will hold
--     other UI prefs in future phases.
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- FULLY IDEMPOTENT. Safe to paste-and-run as many times as needed.

USE dbs15641829;

SET NAMES utf8mb4;

-- 1. users.prefs (only added if missing).
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'prefs'),
  'SELECT 1',
  "ALTER TABLE users ADD COLUMN prefs JSON NULL DEFAULT NULL COMMENT 'Per-user UI preferences (home card layout, etc.).'"
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Verification.
SHOW COLUMNS FROM users LIKE 'prefs';
SELECT COUNT(*) AS users_with_prefs FROM users WHERE prefs IS NOT NULL;

-- Roll-back (run only if you need to undo this migration):
-- ALTER TABLE users DROP COLUMN prefs;
