-- Phase 17 migration: public API access tokens.
-- Each user can mint multiple bearer tokens, identified by a label.
-- We store SHA-256 of the raw token plus the first 8 chars in cleartext
-- as a "prefix" so the UI can show "rk_a3f8…7c2 (Production)" without
-- being able to reconstruct the token. Tokens are returned exactly once
-- at creation time and never persisted in plaintext.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS api_tokens (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(80)     NOT NULL,
  prefix       CHAR(11)        NOT NULL COMMENT 'First 11 chars of the raw token, shown in UI (rk_ + 8 chars).',
  token_hash   CHAR(64)        NOT NULL COMMENT 'SHA-256 of the raw token. Compared on every request.',
  last_used_at DATETIME        NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at   DATETIME        NULL,

  UNIQUE KEY uniq_api_tokens_hash (token_hash),
  KEY idx_api_tokens_user (user_id, revoked_at),
  CONSTRAINT fk_api_tokens_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
