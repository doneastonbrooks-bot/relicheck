-- Phase 181: TIA Studio metadata on the existing tests table.
--
-- Per tester spec May 2026, the Test & Item Analysis Studio needs project-
-- level metadata to drive downstream interpretation: what the assessment is
-- being used for, what kind of decision rides on it, what cognitive demand
-- the author intended, and which kinds of data the project includes.
--
-- This is Phase 1 of the TIA Studio de-demo work. Phase 2+ will add separate
-- tables for persisted analysis runs, rubric data, open-ended responses,
-- and reports. For now we extend the existing tests table additively, so
-- nothing breaks for tests already in the database.
--
-- All columns are NULL-safe or have defaults, so existing rows stay valid.

USE dbs15641829;
SET NAMES utf8mb4;

-- assessment_purpose: 'practice' | 'formative' | 'summative' | 'placement'
--                     | 'credential' | 'screening' | 'research' | ''
ALTER TABLE tests ADD COLUMN IF NOT EXISTS
  assessment_purpose VARCHAR(40) NULL AFTER pass_threshold;

-- decision_type: 'low_stakes' | 'medium_stakes' | 'high_stakes' | ''
ALTER TABLE tests ADD COLUMN IF NOT EXISTS
  decision_type VARCHAR(40) NULL AFTER assessment_purpose;

-- intended_cognitive_demand: 'recall' | 'understand' | 'apply' | 'analyze'
--                            | 'evaluate' | 'create' | 'mixed' | ''
ALTER TABLE tests ADD COLUMN IF NOT EXISTS
  intended_cognitive_demand VARCHAR(40) NULL AFTER decision_type;

ALTER TABLE tests ADD COLUMN IF NOT EXISTS
  includes_open_ended TINYINT(1) NOT NULL DEFAULT 0 AFTER intended_cognitive_demand;

ALTER TABLE tests ADD COLUMN IF NOT EXISTS
  includes_rubric TINYINT(1) NOT NULL DEFAULT 0 AFTER includes_open_ended;

ALTER TABLE tests ADD COLUMN IF NOT EXISTS
  includes_group_analysis TINYINT(1) NOT NULL DEFAULT 0 AFTER includes_rubric;

-- status: workflow state shown as a badge on the landing.
--   'setup'           Project created, no data yet
--   'data_uploaded'   Test responses are loaded
--   'ready'           Setup + data complete, ready to analyze
--   'running'         Analysis in progress
--   'complete'        Analysis run finished successfully
--   'needs_attention' Analysis flagged issues that need researcher input
ALTER TABLE tests ADD COLUMN IF NOT EXISTS
  status VARCHAR(30) NOT NULL DEFAULT 'setup' AFTER includes_group_analysis;

-- Backfill: any existing test that already has responses linked is treated
-- as data_uploaded so the landing badge reflects reality on first load.
UPDATE tests t
   SET t.status = 'data_uploaded'
 WHERE t.status = 'setup'
   AND EXISTS (SELECT 1 FROM test_responses tr WHERE tr.test_id = t.id);

-- Verification.
SHOW COLUMNS FROM tests LIKE 'assessment_purpose';
SHOW COLUMNS FROM tests LIKE 'status';
