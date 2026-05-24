-- Phase 11 migration: rate_limits table.
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key   VARCHAR(160) NOT NULL PRIMARY KEY,   -- e.g., 'login:email:foo@bar.com'
  count        INT UNSIGNED NOT NULL DEFAULT 0,
  first_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_last_at (last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
