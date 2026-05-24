-- Phase 149: Account view backend (user 2FA, sessions, SSO config).
--
-- Adds the persistence layer that the Phase 149 Account view needs.
-- What this file does NOT touch (already shipped):
--   - api_tokens     (Phase 17)
--   - webhooks       (Phase 30)
--   - custom_domain  (Phase 15 ALTER on users)
--   - users.deletion_scheduled_at  (Phase 33)
--   - api/_totp.php  (already RFC 6238-compatible, used for staff_users)
--
-- Three changes here:
--   1. users gains 2FA columns (mirror of the staff_users Phase 28 columns).
--   2. user_sessions table (parallel to admin_sessions from Phase 27,
--      but for regular customer sign-ins so they can be listed/revoked).
--   3. org_sso_config table (SSO provider config; UI stub for Business and
--      Enterprise tiers, actual SAML/OIDC login flow is a follow-up phase).
--
-- Run order: select dbs15641829 in phpMyAdmin, paste this whole file.

USE dbs15641829;

SET NAMES utf8mb4;

-- -----------------------------------------------------------------
-- 1. users: add TOTP + backup-codes + email-OTP fallback columns
-- -----------------------------------------------------------------
-- Phase 28 added these columns to staff_users; this mirrors the schema
-- onto regular users so the Account view 2FA flow can use the existing
-- api/_totp.php helper without changes.
ALTER TABLE users
  ADD COLUMN totp_secret           VARCHAR(64)  NULL DEFAULT NULL
    COMMENT 'Base32-encoded TOTP secret. NULL means 2FA is not enrolled.',
  ADD COLUMN totp_enrolled_at      DATETIME     NULL DEFAULT NULL,
  ADD COLUMN totp_enabled          TINYINT(1)   NOT NULL DEFAULT 0
    COMMENT '1 = TOTP enrollment confirmed and required at next sign-in.',
  ADD COLUMN backup_codes_json     TEXT         NULL DEFAULT NULL
    COMMENT 'JSON array of 10 single-use SHA-256-hashed backup codes.',
  ADD COLUMN email_otp_enabled     TINYINT(1)   NOT NULL DEFAULT 0
    COMMENT '1 = also allow email-OTP as a 2FA recovery channel.';

-- -----------------------------------------------------------------
-- 2. user_sessions  (active sign-in sessions, for list/revoke)
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  session_token CHAR(64)        NOT NULL
    COMMENT 'SHA-256 of the cookie session id; raw value never stored.',
  ip_hash       CHAR(64)        NULL,
  user_agent    VARCHAR(255)    NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at    DATETIME        NULL,
  UNIQUE KEY uniq_user_sessions_token (session_token),
  KEY idx_user_sessions_user (user_id, revoked_at),
  CONSTRAINT fk_user_sessions_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------
-- 3. org_sso_config  (SSO config stub, one row per owner-user)
-- -----------------------------------------------------------------
-- Owner-scoped: a "workspace" in ReliCheck today equals one owner_id, so
-- we key on user_id. When orgs gain their own table this can FK there.
CREATE TABLE IF NOT EXISTS org_sso_config (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  provider          VARCHAR(32)     NOT NULL DEFAULT 'saml'
    COMMENT 'google | okta | azure | saml | oidc',
  display_name      VARCHAR(120)    NULL,
  issuer            VARCHAR(255)    NULL,
  audience          VARCHAR(255)    NULL,
  sso_url           VARCHAR(255)    NULL,
  metadata_url      VARCHAR(255)    NULL,
  metadata_xml      MEDIUMTEXT      NULL,
  certificate       MEDIUMTEXT      NULL,
  email_domain      VARCHAR(120)    NULL
    COMMENT 'Optional email domain restriction, e.g. acme.edu.',
  enabled           TINYINT(1)      NOT NULL DEFAULT 0,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_org_sso_user (user_id),
  CONSTRAINT fk_org_sso_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW COLUMNS FROM users LIKE 'totp%';
SHOW COLUMNS FROM users LIKE 'backup_codes_json';
SHOW COLUMNS FROM users LIKE 'email_otp_enabled';
SHOW TABLES LIKE 'user_sessions';
SHOW TABLES LIKE 'org_sso_config';
DESCRIBE user_sessions;
DESCRIBE org_sso_config;
SELECT COUNT(*) AS user_session_rows FROM user_sessions;
SELECT COUNT(*) AS sso_config_rows   FROM org_sso_config;

-- Roll-back:
-- DROP TABLE IF EXISTS user_sessions;
-- DROP TABLE IF EXISTS org_sso_config;
-- ALTER TABLE users
--   DROP COLUMN totp_secret,
--   DROP COLUMN totp_enrolled_at,
--   DROP COLUMN totp_enabled,
--   DROP COLUMN backup_codes_json,
--   DROP COLUMN email_otp_enabled;
