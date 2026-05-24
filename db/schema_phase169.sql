-- Phase 169: in-app feedback for the MM Studio closed beta.
-- Floating Feedback button on Studio pages writes one row here on submit.
-- Captures rating, comment, plus auto-collected context so we can tell
-- where in the flow the user was (project id, current phase/tab, browser).

USE dbs15641829;

CREATE TABLE IF NOT EXISTS mm_feedback (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  project_id      BIGINT UNSIGNED NULL,            -- NULL if submitted outside a project
  rating          TINYINT UNSIGNED NULL,           -- 1..5; NULL if user skipped
  comment         TEXT             NULL,           -- free text
  page_kind       VARCHAR(40)      NULL,           -- 'studio_dashboard' / 'project_phase1' / 'project_phase3_tab_themes' / etc.
  user_agent      VARCHAR(255)     NULL,
  viewport        VARCHAR(40)      NULL,           -- '1440x900'
  status          VARCHAR(20)      NOT NULL DEFAULT 'new', -- new / read / acted / wontfix
  admin_note      VARCHAR(1000)    NULL,
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_feedback_user (user_id, created_at),
  KEY idx_feedback_project (project_id, created_at),
  KEY idx_feedback_status (status, created_at),
  CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DESCRIBE mm_feedback;
SELECT COUNT(*) AS mm_feedback_total FROM mm_feedback;
