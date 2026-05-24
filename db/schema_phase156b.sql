-- Phase 156b: Multi-select Steps 1 and 2.
-- Converts mm_projects.data_kind and mm_projects.purpose from single-value
-- ENUMs to JSON-array TEXT so a project can carry multiple data kinds and
-- multiple purposes. Existing rows are migrated: a NULL stays NULL, a single
-- value becomes a one-element array.
--
-- Run order: click `dbs15641829` in the phpMyAdmin sidebar, SQL tab, paste
-- this whole file, Go.

USE dbs15641829;

-- Stash current values, change the columns, re-encode as JSON arrays.

-- 1. Add temp columns to hold the existing values.
ALTER TABLE mm_projects
  ADD COLUMN data_kind_old VARCHAR(40) NULL,
  ADD COLUMN purpose_old   VARCHAR(40) NULL;

UPDATE mm_projects SET data_kind_old = data_kind WHERE data_kind IS NOT NULL;
UPDATE mm_projects SET purpose_old   = purpose   WHERE purpose   IS NOT NULL;

-- 2. Drop the ENUM columns and re-add as JSON-string TEXT columns.
ALTER TABLE mm_projects DROP COLUMN data_kind;
ALTER TABLE mm_projects DROP COLUMN purpose;
ALTER TABLE mm_projects
  ADD COLUMN data_kinds VARCHAR(400) NULL AFTER pathway,
  ADD COLUMN purposes   VARCHAR(400) NULL AFTER data_kinds;

-- 3. Re-encode old single values as JSON one-element arrays.
UPDATE mm_projects
   SET data_kinds = CONCAT('["', data_kind_old, '"]')
 WHERE data_kind_old IS NOT NULL;
UPDATE mm_projects
   SET purposes   = CONCAT('["', purpose_old, '"]')
 WHERE purpose_old   IS NOT NULL;

-- 4. Drop the temp columns.
ALTER TABLE mm_projects DROP COLUMN data_kind_old;
ALTER TABLE mm_projects DROP COLUMN purpose_old;

-- Verification.
DESCRIBE mm_projects;
SELECT id, title, data_kinds, purposes, design_choice FROM mm_projects ORDER BY id DESC LIMIT 10;
