-- ReliCheck — TIA (Test & Item Analysis) studio schema.
-- Run once via phpMyAdmin. Idempotent (CREATE TABLE IF NOT EXISTS).
--
-- TIA projects are tests with student × item response data plus an answer
-- key. The wizard collects: title, notes, scoring mode, item type defaults.
-- The dataset itself lives in localStorage (per the v4 architecture); the
-- DB only carries the project shell + settings.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS tia_projects (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  title       VARCHAR(255) NOT NULL,
  notes       TEXT NULL,
  settings    JSON NOT NULL,
  status      VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tia_user (user_id),
  CONSTRAINT fk_tia_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
