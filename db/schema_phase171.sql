-- Phase 171: closed-beta cohort marker on promo codes.
-- Adds an is_beta_cohort flag to promo_codes so the admin "Beta cohort"
-- view can list only the codes that belong to a beta cohort, separate
-- from regular promo activity. Idempotent.

USE dbs15641829;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'promo_codes'
     AND COLUMN_NAME  = 'is_beta_cohort'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE promo_codes ADD COLUMN is_beta_cohort TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active',
  'SELECT "promo_codes.is_beta_cohort already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index on the new flag so the admin list query is fast even with many
-- regular promo codes in the table.
SET @idx_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'promo_codes'
     AND INDEX_NAME   = 'idx_promo_beta_cohort'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE promo_codes ADD INDEX idx_promo_beta_cohort (is_beta_cohort, created_at)',
  'SELECT "idx_promo_beta_cohort already present" AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DESCRIBE promo_codes;
SELECT
  (SELECT COUNT(*) FROM promo_codes) AS promo_codes_total,
  (SELECT COUNT(*) FROM promo_codes WHERE is_beta_cohort = 1) AS beta_cohort_codes;
