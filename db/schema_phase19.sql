-- Phase 19 migration: HRIS connection scaffolding.
-- Two tables: hris_connections (one row per workspace per provider)
-- and hris_employees (the synced directory). Credentials are stored
-- encrypted at rest; the encryption key lives in api/_config.php and
-- is never written to the database.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS hris_connections (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id        BIGINT UNSIGNED NOT NULL,
  provider        VARCHAR(40) NOT NULL COMMENT 'workday | bamboohr | rippling',
  status          ENUM('disconnected','pending','connected','error') NOT NULL DEFAULT 'disconnected',
  credentials     TEXT NULL COMMENT 'AES-encrypted JSON blob (api keys, OAuth tokens, tenant ids)',
  metadata        JSON NULL COMMENT 'Non-sensitive provider metadata (subdomain, domain, instance name).',
  last_sync_at    DATETIME NULL,
  last_error      VARCHAR(500) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_hris_owner_provider (owner_id, provider),
  KEY idx_hris_status (status),
  CONSTRAINT fk_hris_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hris_employees (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id        BIGINT UNSIGNED NOT NULL,
  provider        VARCHAR(40) NOT NULL,
  remote_id       VARCHAR(80) NOT NULL COMMENT 'Provider-side employee id.',
  email           VARCHAR(255) NULL,
  full_name       VARCHAR(255) NULL,
  job_title       VARCHAR(255) NULL,
  department      VARCHAR(255) NULL,
  manager_remote_id VARCHAR(80) NULL,
  location        VARCHAR(255) NULL,
  start_date      DATE NULL,
  status          VARCHAR(40) NULL COMMENT 'active | terminated | on_leave (provider-defined values normalized).',
  raw             JSON NULL COMMENT 'Full provider record for debugging and re-mapping.',
  synced_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_hris_emp (owner_id, provider, remote_id),
  KEY idx_hris_emp_email (email),
  KEY idx_hris_emp_dept (owner_id, department),
  CONSTRAINT fk_hris_emp_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
