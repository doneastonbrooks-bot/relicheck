-- Phase 71 migration: Test & Item Analysis. A test is a new top-level
-- entity (parallel to surveys and datasets). Each test has an answer key
-- and per-student responses. Items are not normalized into their own row
-- because tests are immutable once uploaded; the answer key and responses
-- ride on JSON columns.
--
--   * tests - one row per uploaded test.
--   * test_responses - one row per student per test.
--
-- IMPORTANT: select the database first (drop-down at the top-left of
-- phpMyAdmin should read "dbs15641829") before running.
--
-- FULLY IDEMPOTENT.

USE dbs15641829;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tests (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  title           VARCHAR(255)    NOT NULL,
  description     TEXT            NULL,
  num_items       INT UNSIGNED    NOT NULL,
  answer_key      JSON            NOT NULL                 COMMENT 'Array of correct answers, one per item, in item order.',
  item_labels     JSON            NULL                     COMMENT 'Optional array of item labels matching answer_key length.',
  pass_threshold  DECIMAL(5,2)    NOT NULL DEFAULT 70.00   COMMENT 'Percent correct considered passing.',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  archived_at     DATETIME        NULL,
  KEY idx_tests_user (user_id, archived_at, created_at),
  CONSTRAINT fk_tests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_responses (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  test_id         BIGINT UNSIGNED NOT NULL,
  student_id      VARCHAR(120)    NOT NULL                 COMMENT 'Free-text student identifier as supplied in upload.',
  responses       JSON            NOT NULL                 COMMENT 'Array of student responses, parallel to answer_key.',
  score           INT UNSIGNED    NOT NULL                 COMMENT 'Number of items correct.',
  percent_correct DECIMAL(5,2)    NOT NULL,
  submitted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tr_test (test_id, submitted_at),
  KEY idx_tr_test_student (test_id, student_id),
  CONSTRAINT fk_tr_test FOREIGN KEY (test_id) REFERENCES tests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW TABLES LIKE 'tests';
SHOW TABLES LIKE 'test_responses';
SELECT COUNT(*) AS test_rows           FROM tests;
SELECT COUNT(*) AS test_response_rows  FROM test_responses;

-- Roll-back (run only if you need to undo):
-- DROP TABLE IF EXISTS test_responses;
-- DROP TABLE IF EXISTS tests;
