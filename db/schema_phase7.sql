-- Phase 7 migration: datasets table.
-- Run once in phpMyAdmin against the relicheck database (dbs15641829).

USE dbs15641829;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS datasets (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id        BIGINT UNSIGNED NOT NULL,
  title           VARCHAR(255) NOT NULL,
  source_filename VARCHAR(255) NULL,
  source_format   VARCHAR(20)  NULL,    -- 'csv' or 'xlsx'
  row_count       INT UNSIGNED NOT NULL DEFAULT 0,
  column_count    INT UNSIGNED NOT NULL DEFAULT 0,
  column_meta     JSON NOT NULL,        -- [{name, type, reverse?, options?}]
  settings        JSON NOT NULL,        -- {likertPoints, likertLow, likertHigh}
  data            LONGTEXT NOT NULL,    -- JSON-encoded array of rows; LONGTEXT keeps us under MySQL JSON-column quirks for large uploads
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_datasets_owner (owner_id),
  CONSTRAINT fk_datasets_owner
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
