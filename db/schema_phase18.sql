-- Phase 18 migration: team seats with roles + email invitations.
-- Each existing user account becomes the canonical "workspace owner."
-- Other users can be granted access through account_members rows with
-- a role (editor / viewer). Pending invitations live in
-- account_invitations until accepted, declined, or expired.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS account_members (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id    BIGINT UNSIGNED NOT NULL COMMENT 'User whose workspace is being shared.',
  member_id   BIGINT UNSIGNED NOT NULL COMMENT 'User being granted access.',
  role        ENUM('editor','viewer') NOT NULL DEFAULT 'viewer',
  added_by    BIGINT UNSIGNED NOT NULL COMMENT 'User id of whoever added this member.',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_account_members (owner_id, member_id),
  KEY idx_account_members_member (member_id),
  CONSTRAINT fk_acctmembers_owner  FOREIGN KEY (owner_id)  REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_acctmembers_member FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_invitations (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id     BIGINT UNSIGNED NOT NULL COMMENT 'Workspace doing the inviting.',
  email        VARCHAR(255) NOT NULL COMMENT 'Invitee email; matched on accept.',
  role         ENUM('editor','viewer') NOT NULL DEFAULT 'viewer',
  token        CHAR(64) NOT NULL COMMENT 'Random secret embedded in invite link.',
  invited_by   BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at   DATETIME NOT NULL,
  accepted_at  DATETIME NULL,
  declined_at  DATETIME NULL,

  UNIQUE KEY uniq_invite_token (token),
  KEY idx_invite_owner (owner_id, accepted_at, declined_at),
  KEY idx_invite_email (email),
  CONSTRAINT fk_invite_owner   FOREIGN KEY (owner_id)   REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_invite_invitor FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
