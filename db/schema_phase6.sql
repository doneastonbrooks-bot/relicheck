-- Phase 6 migration: oauth_identities table.
-- Run once in phpMyAdmin against the relicheck database.
-- Safe to re-run; CREATE TABLE IF NOT EXISTS skips if already present.

USE dbs15641829;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS oauth_identities (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider    VARCHAR(20) NOT NULL,        -- 'google', 'apple', etc.
  sub         VARCHAR(255) NOT NULL,       -- provider's stable user id (Google: sub claim)
  user_id     BIGINT UNSIGNED NOT NULL,
  email       VARCHAR(255) NULL,           -- email at the provider, snapshot at link time
  linked_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  UNIQUE KEY uniq_provider_sub (provider, sub),
  KEY idx_user (user_id),
  CONSTRAINT fk_oauth_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
