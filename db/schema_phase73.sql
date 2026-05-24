-- Phase 73 migration: add per-item skill / standard tags to tests.
--
--   * tests.skill_tags - JSON array, one entry per item, parallel to answer_key.
--     Null means no skill tagging for this test. Empty-string entries within
--     the array mean "this item has no skill assigned."
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- FULLY IDEMPOTENT (uses INFORMATION_SCHEMA guard before ADD COLUMN).

USE dbs15641829;
SET NAMES utf8mb4;

SET @col_exists := (
  SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'tests'
     AND COLUMN_NAME  = 'skill_tags'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE tests ADD COLUMN skill_tags JSON NULL AFTER item_labels',
  'SELECT "skill_tags already exists, skipping" AS note'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verification.
SHOW COLUMNS FROM tests LIKE 'skill_tags';

-- Roll-back (run only if you need to undo):
-- ALTER TABLE tests DROP COLUMN skill_tags;
