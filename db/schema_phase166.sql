-- Phase 166: Email verification on signup.
-- Adds users.email_verified_at + the email_verifications token store.
-- Idempotent: every ALTER and CREATE is guarded.

USE dbs15641829;

-- Add users.email_verified_at if it does not exist.
DROP PROCEDURE IF EXISTS p166_add_col;
DELIMITER $$
CREATE PROCEDURE p166_add_col()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at'
  ) THEN
    ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER last_login_at;
  END IF;
END$$
DELIMITER ;
CALL p166_add_col();
DROP PROCEDURE p166_add_col;

-- Verification tokens. Single-use, 7-day TTL by default.
CREATE TABLE IF NOT EXISTS email_verifications (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  email        VARCHAR(255)    NOT NULL,
  token_hash   CHAR(64)        NOT NULL,
  expires_at   DATETIME        NOT NULL,
  used_at      DATETIME        NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email_ver_token (token_hash),
  KEY idx_email_ver_user (user_id, used_at),
  CONSTRAINT fk_email_ver_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: anyone already in the system at this point is treated as verified.
-- Otherwise existing paid users would suddenly be locked out of checkout.
UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL;

DESCRIBE users;
SHOW TABLES LIKE 'email_verifications';
SELECT
  (SELECT COUNT(*) FROM users) AS users_total,
  (SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL) AS users_verified,
  (SELECT COUNT(*) FROM email_verifications) AS tokens_total;
