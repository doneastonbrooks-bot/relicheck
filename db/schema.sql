-- ReliCheck schema for MySQL 8.0 (Ionos)
-- Run this once via the Ionos phpMyAdmin tool against your relicheck database.
-- Safe to re-run: existing tables are skipped.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(255) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  name            VARCHAR(120) NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at   DATETIME NULL,
  UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS surveys (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id        BIGINT UNSIGNED NOT NULL,
  slug            VARCHAR(32) NOT NULL,
  title           VARCHAR(255) NOT NULL,
  description     TEXT NULL,
  settings        JSON NOT NULL,
  questions       JSON NOT NULL,
  is_published    TINYINT(1) NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_surveys_slug (slug),
  KEY idx_surveys_owner (owner_id),
  CONSTRAINT fk_surveys_owner
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS responses (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id       BIGINT UNSIGNED NOT NULL,
  submitted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_hash         CHAR(64) NULL,
  user_agent      VARCHAR(255) NULL,
  answers         JSON NOT NULL,
  KEY idx_responses_survey (survey_id, submitted_at),
  CONSTRAINT fk_responses_survey
    FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 1 leaves password reset tokens out; we'll add them when email sending is wired up.
