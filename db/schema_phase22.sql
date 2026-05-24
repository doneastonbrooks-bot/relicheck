-- Phase 22 migration: admin support notes.
-- Free-text notes about customer accounts that staff write for each other.
-- Notes are internal: customers do not see them. Each note records who
-- wrote it, when, and the customer it pertains to.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_notes (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_user_id INT UNSIGNED NOT NULL
    COMMENT 'Customer this note is about (users.id).',
  author_user_id   INT UNSIGNED NOT NULL
    COMMENT 'Admin who wrote the note (users.id).',
  author_email     VARCHAR(190) NOT NULL
    COMMENT 'Snapshot of author email at write time, in case the author account is later removed.',
  body             TEXT NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin_notes_customer (customer_user_id, created_at),
  KEY idx_admin_notes_author   (author_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification: should return one row showing the new table.
SHOW TABLES LIKE 'admin_notes';

-- Roll-back, if you ever need it:
-- USE dbs15641829;
-- DROP TABLE admin_notes;
