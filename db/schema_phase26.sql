-- Phase 26 migration: staff_users for the admin panel.
-- Maps email → role for admin staff. Replaces the static
-- admin_emails allowlist in api/_admin.php with a database-backed
-- list that supports invite/accept/suspend/remove.
--
-- Status lifecycle:
--   invited    — invite sent, token outstanding, no admin access yet
--   active     — accepted, admin access enabled
--   suspended  — temporarily blocked from admin access (record kept)
--   removed    — soft-removed (record kept for audit, no admin access)
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS staff_users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email             VARCHAR(190) NOT NULL
    COMMENT 'Email of the staff account. Matched case-insensitively.',
  name              VARCHAR(120) NULL,
  role              VARCHAR(32)  NOT NULL DEFAULT 'cs'
    COMMENT 'owner | upper | supervisor | cs | tech | readonly',
  status            VARCHAR(20)  NOT NULL DEFAULT 'invited'
    COMMENT 'invited | active | suspended | removed',
  invite_token      CHAR(64)     NULL
    COMMENT 'One-time token sent in the invitation email.',
  invite_expires    DATETIME     NULL,
  added_by_user_id  INT UNSIGNED NULL
    COMMENT 'Owner-side user.id of whoever invited them.',
  added_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at      DATETIME     NULL,
  suspended_at      DATETIME     NULL,
  removed_at        DATETIME     NULL,
  last_login_at     DATETIME     NULL,
  two_factor_required TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_staff_email (email),
  KEY idx_staff_status (status),
  KEY idx_staff_token  (invite_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification: should return one row showing the new table.
SHOW TABLES LIKE 'staff_users';
