-- Phase 165: Step 20 Phase E (Project templates).
-- A template snapshots the themes (name, description, position) and the
-- variable role assignments (var_name -> role) from a finished project so
-- the same scaffolding can be applied to a new project with new data.

USE dbs15641829;

CREATE TABLE IF NOT EXISTS mm_project_templates (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  name              VARCHAR(200) NOT NULL,
  description       VARCHAR(800) NULL,
  themes_json       LONGTEXT     NULL,
  var_roles_json    LONGTEXT     NULL,
  source_project_id INT UNSIGNED NULL,
  theme_count       INT UNSIGNED NOT NULL DEFAULT 0,
  role_count        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mm_tpl_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DESCRIBE mm_project_templates;
SELECT COUNT(*) AS template_rows_total FROM mm_project_templates;
