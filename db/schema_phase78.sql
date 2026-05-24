-- Phase 78 migration: hosted test delivery.
--
--   * tests.slug         - unguessable public ID used by /take-test.html?s=...
--   * tests.is_published - 0/1; only published tests can be loaded by the public take endpoint.
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- FULLY IDEMPOTENT (INFORMATION_SCHEMA guards before ADD COLUMN).

USE dbs15641829;
SET NAMES utf8mb4;

-- Add slug.
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND COLUMN_NAME = 'slug'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE tests ADD COLUMN slug CHAR(24) NULL AFTER user_id, ADD UNIQUE KEY uniq_tests_slug (slug)',
  'SELECT "slug already exists, skipping" AS note'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add is_published.
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND COLUMN_NAME = 'is_published'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE tests ADD COLUMN is_published TINYINT(1) NOT NULL DEFAULT 0 AFTER slug',
  'SELECT "is_published already exists, skipping" AS note'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Verification.
SHOW COLUMNS FROM tests LIKE 'slug';
SHOW COLUMNS FROM tests LIKE 'is_published';

-- Roll-back (run only if you need to undo):
-- ALTER TABLE tests DROP COLUMN is_published;
-- ALTER TABLE tests DROP INDEX uniq_tests_slug, DROP COLUMN slug;
