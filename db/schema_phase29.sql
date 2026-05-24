-- Phase 29 migration: admin password reset tokens.
-- Parallels password_resets for customers but lives in its own table so
-- admin-side and customer-side reset flows can never get crossed.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_password_resets (
  token       CHAR(64)        NOT NULL PRIMARY KEY,
  staff_id    BIGINT UNSIGNED NOT NULL,
  expires_at  DATETIME        NOT NULL,
  used_at     DATETIME        NULL,
  ip_hash     CHAR(64)        NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin_pw_resets_staff (staff_id),
  KEY idx_admin_pw_resets_expires (expires_at),
  CONSTRAINT fk_admin_pw_resets_staff
    FOREIGN KEY (staff_id) REFERENCES staff_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification: should return one row.
SHOW TABLES LIKE 'admin_password_resets';
