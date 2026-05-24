-- Phase 5 migration: password_resets table.
-- Run once in phpMyAdmin against your relicheck database (dbs15641829).
-- Safe to re-run; CREATE TABLE IF NOT EXISTS skips if already present.

USE dbs15641829;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS password_resets (
  token         CHAR(64) PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  used_at       DATETIME NULL,
  ip_hash       CHAR(64) NULL,
  KEY idx_user (user_id),
  KEY idx_expires (expires_at),
  CONSTRAINT fk_pwreset_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
