-- Phase 27 migration: separate admin authentication.
-- Adds a password_hash column to staff_users so admin staff have their own
-- credentials independent of any customer-side ReliCheck account, plus an
-- admin_sessions table for an independent session cookie.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

ALTER TABLE staff_users
  ADD COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Admin-side password hash (PASSWORD_DEFAULT). NULL until invitation accepted.',
  ADD COLUMN failed_login_at DATETIME NULL DEFAULT NULL
    COMMENT 'Timestamp of last failed sign-in attempt; used for rate-limit hints.';

CREATE TABLE IF NOT EXISTS admin_sessions (
  token        CHAR(64)        NOT NULL PRIMARY KEY
    COMMENT 'Random hex token; matches the relicheck_admin cookie value.',
  staff_id     BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at   DATETIME        NOT NULL,
  ip           VARCHAR(64)     NULL,
  user_agent   VARCHAR(255)    NULL,
  KEY idx_admin_sessions_staff   (staff_id),
  KEY idx_admin_sessions_expires (expires_at),
  CONSTRAINT fk_admin_sessions_staff
    FOREIGN KEY (staff_id) REFERENCES staff_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification: should return one row for staff_users.password_hash and one for admin_sessions.
SHOW COLUMNS FROM staff_users LIKE 'password_hash';
SHOW TABLES LIKE 'admin_sessions';
