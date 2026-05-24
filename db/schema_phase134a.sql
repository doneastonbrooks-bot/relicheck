-- Phase 134a: Test & Item Analysis Suite.
-- Adds an 8th system suite for the Tests entity and a parallel join table
-- so tests bind to a suite the same way surveys do via suite_surveys.
--
-- The system-suite row itself is created automatically by the lazy seeder
-- in _suites.php the next time a user opens the Suites hub; this migration
-- only adds the join table.

USE dbs15641829;

-- Constraint names must be globally unique per database in MySQL, so we
-- prefix with the full table name.
CREATE TABLE IF NOT EXISTS suite_tests (
  suite_id   BIGINT UNSIGNED NOT NULL,
  test_id    BIGINT UNSIGNED NOT NULL,
  added_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (suite_id, test_id),
  KEY idx_suite_tests_test (test_id),
  CONSTRAINT fk_suite_tests_suite FOREIGN KEY (suite_id) REFERENCES suites(id) ON DELETE CASCADE,
  CONSTRAINT fk_suite_tests_test  FOREIGN KEY (test_id)  REFERENCES tests(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification.
SHOW TABLES LIKE 'suite_tests';
DESCRIBE suite_tests;
SELECT COUNT(*) AS join_rows FROM suite_tests;

-- Roll-back:
-- DROP TABLE IF EXISTS suite_tests;
