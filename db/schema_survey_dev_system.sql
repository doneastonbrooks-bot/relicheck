-- ReliCheck — Survey Development System (Phase 2A persistence) schema.
-- Backs develop.php's database mode. Run once via phpMyAdmin, OR let the app
-- self-create it: api/dev/_dev_common.php → sds_ensure_schema() runs these same
-- statements on the first authenticated /api/dev/* request.
--
-- Fully ADDITIVE: every statement is CREATE TABLE IF NOT EXISTS against NEW
-- table names (survey_*, sdsi_reviews, siri_reviews, deployment_settings,
-- response_summaries). It never alters or drops existing ReliCheck tables
-- (surveys, tests, sdsi_validity_reviews, …) and never touches their data.
--
-- Naming (locked): SDSI = Survey Design Strength Index (50-pt design review);
-- SIRI = Survey Instrument Readiness Index (100-pt pre-launch gate);
-- RSSI = ReliCheck Survey Strength Index (post-response, NOT wired here).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ── Project shell: one row per survey project in the dev system. ──
CREATE TABLE IF NOT EXISTS survey_projects (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  title         VARCHAR(255) NOT NULL,
  purpose       TEXT NULL,
  population     TEXT NULL,
  response_mode VARCHAR(64) NOT NULL DEFAULT '5-pt agreement',
  data_type     VARCHAR(32) NOT NULL DEFAULT 'Quantitative',
  source        VARCHAR(24) NOT NULL DEFAULT 'scratch',  -- ai-build|ai-assist|scratch|existing|template
  status        VARCHAR(16) NOT NULL DEFAULT 'draft',     -- draft|active|archived
  settings      JSON NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sproj_user (user_id),
  KEY idx_sproj_status (user_id, status),
  CONSTRAINT fk_sproj_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sections: ordered groups of items within a project. ──
CREATE TABLE IF NOT EXISTS survey_sections (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  position    INT UNSIGNED NOT NULL DEFAULT 0,
  title       VARCHAR(255) NOT NULL DEFAULT 'Section',
  description TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ssec_project (project_id, position),
  CONSTRAINT fk_ssec_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Items: ordered questions, optionally bound to a section. ──
CREATE TABLE IF NOT EXISTS survey_items (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  section_id  BIGINT UNSIGNED NULL,
  position    INT UNSIGNED NOT NULL DEFAULT 0,
  type        VARCHAR(40) NOT NULL DEFAULT 'Likert (5-pt)',
  prompt      TEXT NOT NULL,
  options     JSON NULL,
  flag        VARCHAR(16) NULL,           -- null|info|warn|err
  required    TINYINT(1) NOT NULL DEFAULT 0,
  settings    JSON NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sitem_project (project_id, position),
  KEY idx_sitem_section (section_id),
  CONSTRAINT fk_sitem_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_sitem_section FOREIGN KEY (section_id) REFERENCES survey_sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Constructs: the measured dimensions a project defines. ──
CREATE TABLE IF NOT EXISTS survey_constructs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  position    INT UNSIGNED NOT NULL DEFAULT 0,
  name        VARCHAR(255) NOT NULL,
  definition  TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_scons_project (project_id, position),
  CONSTRAINT fk_scons_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Templates: validated starting instruments (system + user-owned). ──
-- user_id NULL = system/global template available to everyone.
CREATE TABLE IF NOT EXISTS survey_templates (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NULL,
  slug         VARCHAR(64) NOT NULL,
  category     VARCHAR(64) NOT NULL DEFAULT 'General',
  name         VARCHAR(255) NOT NULL,
  description  TEXT NULL,
  items_count  INT UNSIGNED NOT NULL DEFAULT 0,
  scale        VARCHAR(64) NOT NULL DEFAULT '5-pt agreement',
  domains      JSON NULL,
  payload      JSON NULL,                 -- full {sections, items, constructs} blueprint
  is_system    TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stmpl_slug (slug),
  KEY idx_stmpl_user (user_id),
  CONSTRAINT fk_stmpl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SDSI reviews: stored 50-pt design-strength review objects (stubbed scoring). ──
CREATE TABLE IF NOT EXISTS sdsi_reviews (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  total       DECIMAL(6,2) NOT NULL DEFAULT 0,
  max_points  DECIMAL(6,2) NOT NULL DEFAULT 50,
  pct         INT UNSIGNED NOT NULL DEFAULT 0,
  band        VARCHAR(120) NULL,
  blocked     TINYINT(1) NOT NULL DEFAULT 0,
  review      JSON NULL,                  -- full lens/flag object
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sdsi_project (project_id),
  CONSTRAINT fk_sdsi_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SIRI reviews: stored 100-pt readiness review objects (stubbed scoring). ──
CREATE TABLE IF NOT EXISTS siri_reviews (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  total       DECIMAL(6,2) NOT NULL DEFAULT 0,
  max_points  DECIMAL(6,2) NOT NULL DEFAULT 100,
  pct         INT UNSIGNED NOT NULL DEFAULT 0,
  band        VARCHAR(120) NULL,
  blocked     TINYINT(1) NOT NULL DEFAULT 0,
  review      JSON NULL,                  -- full domain/lens/flag/checklist object
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_siri_project (project_id),
  CONSTRAINT fk_siri_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── RSSI reviews (Phase 4C): the post-data ReliCheck Survey Strength Index, ──
-- kept SEPARATE from sdsi_reviews (design) and siri_reviews (pre-launch).
-- total/pct are NULLABLE: when the N fence withholds the score they stay NULL
-- and withheld = 1. response_count + last_submitted_at are the response-data
-- fingerprint captured at run time, so a reopened project can tell whether the
-- saved RSSI predates newer responses (stale) instead of pretending it is current.
CREATE TABLE IF NOT EXISTS rssi_reviews (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id        BIGINT UNSIGNED NOT NULL,
  total             DECIMAL(6,2) NULL,            -- NULL when withheld
  max_points        DECIMAL(6,2) NOT NULL DEFAULT 100,
  pct               INT UNSIGNED NULL,            -- NULL when withheld
  band              VARCHAR(160) NULL,
  verdict           VARCHAR(160) NULL,
  withheld          TINYINT(1) NOT NULL DEFAULT 0,
  response_count    INT UNSIGNED NOT NULL DEFAULT 0,  -- fingerprint at run time
  last_submitted_at DATETIME NULL,                    -- fingerprint at run time
  review            JSON NULL,                        -- full RSSIEngine.score() result
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rssi_project (project_id),
  CONSTRAINT fk_rssi_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Deployment settings: table provisioned now, NOT wired until a later phase. ──
CREATE TABLE IF NOT EXISTS deployment_settings (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  settings    JSON NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_deploy_project (project_id),
  CONSTRAINT fk_deploy_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Response summaries: table provisioned now, NOT wired until a later phase. ──
CREATE TABLE IF NOT EXISTS response_summaries (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  collected   INT UNSIGNED NOT NULL DEFAULT 0,
  target      INT UNSIGNED NOT NULL DEFAULT 0,
  summary     JSON NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_respsum_project (project_id),
  CONSTRAINT fk_respsum_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Phase 3D: public response collection. ──
-- One row per completed submission. No respondent identity is stored: only a
-- salted IP hash (api/_helpers.php → ip_hash()) for abuse triage and a
-- truncated user-agent. Never linked to users(). RSSI/analysis read these later.
CREATE TABLE IF NOT EXISTS survey_dev_response_sessions (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id   BIGINT UNSIGNED NOT NULL,
  link_key     VARCHAR(20) NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_hash      CHAR(64) NULL,
  user_agent   VARCHAR(255) NULL,
  KEY idx_devsess_project (project_id, submitted_at),
  KEY idx_devsess_link (link_key),
  CONSTRAINT fk_devsess_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per answer. item_id is the survey_items.id at submit time (no FK, so
-- editing/removing items never destroys collected data); item_label snapshots
-- the prompt so the stored answer stays interpretable on its own.
CREATE TABLE IF NOT EXISTS survey_dev_answers (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id   BIGINT UNSIGNED NOT NULL,
  project_id   BIGINT UNSIGNED NOT NULL,
  item_id      BIGINT UNSIGNED NULL,
  item_label   VARCHAR(500) NOT NULL DEFAULT '',
  answer_value MEDIUMTEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_devans_session (session_id),
  KEY idx_devans_project (project_id),
  KEY idx_devans_item (item_id),
  CONSTRAINT fk_devans_session FOREIGN KEY (session_id) REFERENCES survey_dev_response_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_devans_project FOREIGN KEY (project_id) REFERENCES survey_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
