-- Phase 31 migration: ReliCheck email notification system, core tables.
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running. The USE statement below is a
-- belt-and-braces guard.
--
-- Creates departments, templates (and version history), event bindings,
-- delivery log, audit log, and the suppression list. Seed data for the eleven
-- official departments and the launch-ready event bindings is in
-- schema_phase31b.sql, which must be run AFTER this file.
--
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. email_departments: the eleven official @relichecksurvey.com senders.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_departments (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(32)     NOT NULL COMMENT 'Lowercase department key, e.g. "support".',
  display_name    VARCHAR(64)     NOT NULL COMMENT 'Sender display name, e.g. "ReliCheck Support".',
  sender_email    VARCHAR(128)    NOT NULL COMMENT 'Mailbox, e.g. support@relichecksurvey.com.',
  email_class     ENUM('transactional','operational','marketing','legal','privacy','billing') NOT NULL,
  audience        ENUM('customer','employee','both') NOT NULL,
  is_active       TINYINT(1)      NOT NULL DEFAULT 1,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_departments_code (code),
  UNIQUE KEY uniq_email_departments_sender (sender_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. email_templates: one row per template_key (current/active version).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_templates (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_key                VARCHAR(96)     NOT NULL COMMENT 'Snake-case unique key, e.g. customer.welcome.verify_email.',
  email_name                  VARCHAR(128)    NOT NULL,
  department_id               BIGINT UNSIGNED NOT NULL,
  subject_line                VARCHAR(255)    NOT NULL,
  preview_text                VARCHAR(255)    NOT NULL DEFAULT '',
  body_html                   MEDIUMTEXT      NOT NULL,
  body_text                   MEDIUMTEXT      NOT NULL,
  primary_button_label        VARCHAR(64)     NULL,
  primary_button_url_template VARCHAR(512)    NULL,
  dynamic_fields              JSON            NOT NULL COMMENT 'Array of variable names referenced in the body/subject.',
  audience                    ENUM('customer','employee') NOT NULL,
  is_required                 TINYINT(1)      NOT NULL DEFAULT 1,
  is_unsubscribable           TINYINT(1)      NOT NULL DEFAULT 0,
  unsubscribe_group           VARCHAR(64)     NULL,
  restricted_data             TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Always 0 for employee templates; renderer enforces.',
  current_version             INT UNSIGNED    NOT NULL DEFAULT 1,
  is_active                   TINYINT(1)      NOT NULL DEFAULT 1,
  created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_templates_key (template_key),
  KEY idx_email_templates_department (department_id),
  CONSTRAINT fk_email_templates_department
    FOREIGN KEY (department_id) REFERENCES email_departments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. email_template_versions: full snapshot per edit, for rollback + audit.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_template_versions (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id                 BIGINT UNSIGNED NOT NULL,
  version_number              INT UNSIGNED    NOT NULL,
  subject_line                VARCHAR(255)    NOT NULL,
  preview_text                VARCHAR(255)    NOT NULL DEFAULT '',
  body_html                   MEDIUMTEXT      NOT NULL,
  body_text                   MEDIUMTEXT      NOT NULL,
  primary_button_label        VARCHAR(64)     NULL,
  primary_button_url_template VARCHAR(512)    NULL,
  dynamic_fields              JSON            NOT NULL,
  edited_by_user_id           BIGINT UNSIGNED NULL,
  change_note                 VARCHAR(512)    NULL,
  created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_template_version (template_id, version_number),
  CONSTRAINT fk_template_versions_template
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. email_events: maps system events to templates and routing rules.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_events (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key               VARCHAR(96)     NOT NULL,
  description             VARCHAR(255)    NOT NULL DEFAULT '',
  customer_template_id    BIGINT UNSIGNED NULL,
  employee_template_id    BIGINT UNSIGNED NULL,
  recipient_resolver      VARCHAR(96)     NOT NULL DEFAULT 'self' COMMENT 'Name of resolver function in _email_resolver.php.',
  dedupe_window_minutes   INT UNSIGNED    NOT NULL DEFAULT 0,
  priority                ENUM('P0','P1','P2','P3') NOT NULL DEFAULT 'P2',
  is_required             TINYINT(1)      NOT NULL DEFAULT 1,
  is_active               TINYINT(1)      NOT NULL DEFAULT 1,
  created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_events_key (event_key),
  CONSTRAINT fk_email_events_customer_tpl
    FOREIGN KEY (customer_template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
  CONSTRAINT fk_email_events_employee_tpl
    FOREIGN KEY (employee_template_id) REFERENCES email_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. email_logs: one row per dispatch attempt, success or failure.
--    sanitized_body never carries restricted variables (renderer scrubs).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_logs (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key               VARCHAR(96)     NOT NULL,
  template_id             BIGINT UNSIGNED NULL,
  template_version        INT UNSIGNED    NULL,
  department_id           BIGINT UNSIGNED NULL,
  sender_email            VARCHAR(128)    NOT NULL,
  sender_display_name     VARCHAR(128)    NOT NULL,
  recipient_user_id       BIGINT UNSIGNED NULL,
  recipient_email         VARCHAR(255)    NOT NULL,
  recipient_role          VARCHAR(64)     NULL,
  customer_account_id     BIGINT UNSIGNED NULL,
  subject                 VARCHAR(512)    NOT NULL,
  preview                 VARCHAR(255)    NOT NULL DEFAULT '',
  body_snapshot_hash      CHAR(64)        NOT NULL,
  sanitized_body          MEDIUMTEXT      NOT NULL,
  dynamic_payload         JSON            NULL,
  idempotency_key         VARCHAR(96)     NOT NULL,
  provider_message_id     VARCHAR(255)    NULL,
  status                  ENUM('queued','sending','sent','delivered','opened','clicked','bounced','failed','failed_permanent','suppressed','complained') NOT NULL DEFAULT 'queued',
  attempts                INT UNSIGNED    NOT NULL DEFAULT 0,
  last_error              VARCHAR(512)    NULL,
  next_attempt_at         DATETIME        NULL,
  sent_at                 DATETIME        NULL,
  delivered_at            DATETIME        NULL,
  created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_logs_idem (idempotency_key),
  KEY idx_email_logs_recipient (recipient_user_id, created_at),
  KEY idx_email_logs_account (customer_account_id, created_at),
  KEY idx_email_logs_event (event_key, created_at),
  KEY idx_email_logs_status (status, next_attempt_at),
  CONSTRAINT fk_email_logs_template
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
  CONSTRAINT fk_email_logs_department
    FOREIGN KEY (department_id) REFERENCES email_departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. email_audit_logs: template edits, manual resends, suppression edits, etc.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_audit_logs (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id       BIGINT UNSIGNED NULL,
  action              VARCHAR(64)     NOT NULL COMMENT 'e.g. template.edit, email.resend, suppression.add.',
  target_type         VARCHAR(64)     NOT NULL,
  target_id           BIGINT UNSIGNED NULL,
  before_json         JSON            NULL,
  after_json          JSON            NULL,
  ip_hash             CHAR(64)        NULL,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_audit_actor (actor_user_id, created_at),
  KEY idx_email_audit_action (action, created_at),
  KEY idx_email_audit_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. email_suppression_list: hard bounces, complaints, manual blocks.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_suppression_list (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email               VARCHAR(255)    NOT NULL,
  reason              ENUM('hard_bounce','complaint','manual','invalid') NOT NULL,
  added_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  added_by_user_id    BIGINT UNSIGNED NULL,
  notes               VARCHAR(512)    NULL,
  UNIQUE KEY uniq_email_suppression_email (email),
  KEY idx_email_suppression_reason (reason, added_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Verification: each row should return 1; counts should all be zero on a
-- fresh install (templates and events get populated by schema_phase31b.sql).
-- ---------------------------------------------------------------------------
SHOW TABLES LIKE 'email_departments';
SHOW TABLES LIKE 'email_templates';
SHOW TABLES LIKE 'email_template_versions';
SHOW TABLES LIKE 'email_events';
SHOW TABLES LIKE 'email_logs';
SHOW TABLES LIKE 'email_audit_logs';
SHOW TABLES LIKE 'email_suppression_list';

SELECT
  (SELECT COUNT(*) FROM email_departments)        AS departments,
  (SELECT COUNT(*) FROM email_templates)          AS templates,
  (SELECT COUNT(*) FROM email_template_versions)  AS template_versions,
  (SELECT COUNT(*) FROM email_events)             AS events,
  (SELECT COUNT(*) FROM email_logs)               AS logs,
  (SELECT COUNT(*) FROM email_audit_logs)         AS audit_logs,
  (SELECT COUNT(*) FROM email_suppression_list)   AS suppression_rows;

-- ---------------------------------------------------------------------------
-- Roll-back block (run only if you need to undo this migration).
-- Order matters: drop the dependent tables before email_departments.
-- ---------------------------------------------------------------------------
-- DROP TABLE IF EXISTS email_suppression_list;
-- DROP TABLE IF EXISTS email_audit_logs;
-- DROP TABLE IF EXISTS email_logs;
-- DROP TABLE IF EXISTS email_events;
-- DROP TABLE IF EXISTS email_template_versions;
-- DROP TABLE IF EXISTS email_templates;
-- DROP TABLE IF EXISTS email_departments;
