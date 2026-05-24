-- Phase 16 migration: random assignment and quota infrastructure.
-- Adds an arm_id column to responses so each submission can be tagged
-- with the experimental cell the respondent was assigned to. Arms
-- themselves (id, name, quota, weight) live in surveys.settings JSON
-- so no new tables are needed.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

ALTER TABLE responses
  ADD COLUMN arm_id VARCHAR(40) NULL DEFAULT NULL
    COMMENT 'Random-assignment arm/cell id from survey.settings.arms (null when arming disabled)',
  ADD KEY idx_responses_arm (survey_id, arm_id);
