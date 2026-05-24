-- Phase 12 migration: google_oauth_tokens table.
-- Stores per-user Google OAuth tokens used for Sheets export and Drive uploads.
-- Each user has at most one row; refreshing a token updates the existing row in place.
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS google_oauth_tokens (
  user_id       BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  access_token  TEXT         NOT NULL,
  refresh_token TEXT         NULL,                  -- only present on first consent or full re-consent
  scopes        TEXT         NOT NULL,              -- space-separated scope list returned by Google
  token_type    VARCHAR(32)  NOT NULL DEFAULT 'Bearer',
  expires_at    DATETIME     NOT NULL,              -- absolute expiry of access_token
  google_email  VARCHAR(255) NULL,                  -- handy for the UI ("Connected as alice@example.com")
  google_sub    VARCHAR(64)  NULL,                  -- Google account subject id
  connected_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_google_tokens_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

  KEY idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
