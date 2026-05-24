-- Phase 157: add question_id to mm_text_responses so Step 7 can scope themes
-- per question without abusing respondent_ref.
--
-- Idempotent: skips the column if it already exists.

USE dbs15641829;

DROP PROCEDURE IF EXISTS mm_add_col;
DELIMITER $$
CREATE PROCEDURE mm_add_col(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN ', ddl);
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;

CALL mm_add_col('mm_text_responses', 'question_id_raw',
  "question_id_raw VARCHAR(120) NULL AFTER respondent_ref");

CALL mm_add_col('mm_text_responses', 'question_text_raw',
  "question_text_raw VARCHAR(2000) NULL AFTER question_id_raw");

DROP PROCEDURE IF EXISTS mm_add_col;

DESCRIBE mm_text_responses;
SELECT COUNT(*) AS responses_total FROM mm_text_responses;
