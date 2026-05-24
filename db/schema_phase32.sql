-- Phase 32 migration: ReliCheck email notification system, preferences and tracking.
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running. The USE statement below is a
-- belt-and-braces guard.
--
-- Adds customer + employee email preferences, unsubscribe tokens, delivery
-- failures detail, open/click event tables, the digest event buffer, the role
-- requirement map, and the send-jobs queue used by api/email/queue_run.php.
--
-- Depends on: schema_phase31.sql (must be run first).
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. email_preferences: per-customer toggle per preference group.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_preferences (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id             BIGINT UNSIGNED NOT NULL,
  preference_group    VARCHAR(64)     NOT NULL COMMENT 'e.g. marketing_newsletter, sales_followup, survey_activity.',
  is_enabled          TINYINT(1)      NOT NULL DEFAULT 1,
  digest_mode         ENUM('immediate','daily','weekly') NOT NULL DEFAULT 'immediate',
  updated_by          ENUM('user','system','admin') NOT NULL DEFAULT 'user',
  updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_pref_user_group (user_id, preference_group),
  KEY idx_email_pref_group (preference_group, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. employee_notification_preferences: per-employee toggle per group.
--    can_disable derives from role + role_required_notifications.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employee_notification_preferences (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_user_id    BIGINT UNSIGNED NOT NULL,
  preference_group    VARCHAR(64)     NOT NULL,
  is_enabled          TINYINT(1)      NOT NULL DEFAULT 1,
  can_disable         TINYINT(1)      NOT NULL DEFAULT 1,
  updated_by          ENUM('employee','admin','system') NOT NULL DEFAULT 'employee',
  updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_emp_pref (employee_user_id, preference_group),
  KEY idx_emp_pref_group (preference_group, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. role_required_notifications: which preference groups a role MUST receive.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS role_required_notifications (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  role_code           VARCHAR(64)     NOT NULL COMMENT 'e.g. support_agent, billing_ops, owner.',
  preference_group    VARCHAR(64)     NOT NULL,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_role_required (role_code, preference_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. unsubscribe_tokens: one-click unsubscribe links signed with a 64-hex token.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS unsubscribe_tokens (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id             BIGINT UNSIGNED NOT NULL,
  preference_group    VARCHAR(64)     NOT NULL,
  token               CHAR(64)        NOT NULL,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at             DATETIME        NULL,
  expires_at          DATETIME        NOT NULL,
  UNIQUE KEY uniq_unsub_token (token),
  KEY idx_unsub_user_group (user_id, preference_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. email_delivery_failures: detailed failure rows (one per attempt).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_delivery_failures (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_log_id        BIGINT UNSIGNED NOT NULL,
  attempt_number      INT UNSIGNED    NOT NULL,
  error_code          VARCHAR(64)     NULL,
  error_message       VARCHAR(512)    NULL,
  provider_response   TEXT            NULL,
  failed_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_failures_log (email_log_id, attempt_number),
  CONSTRAINT fk_email_failures_log
    FOREIGN KEY (email_log_id) REFERENCES email_logs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. email_open_events.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_open_events (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_log_id        BIGINT UNSIGNED NOT NULL,
  opened_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_agent          VARCHAR(255)    NULL,
  ip_hash             CHAR(64)        NULL,
  KEY idx_email_open_log (email_log_id, opened_at),
  CONSTRAINT fk_email_open_log
    FOREIGN KEY (email_log_id) REFERENCES email_logs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. email_click_events.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_click_events (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_log_id        BIGINT UNSIGNED NOT NULL,
  url                 VARCHAR(1024)   NOT NULL,
  clicked_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_agent          VARCHAR(255)    NULL,
  ip_hash             CHAR(64)        NULL,
  KEY idx_email_click_log (email_log_id, clicked_at),
  CONSTRAINT fk_email_click_log
    FOREIGN KEY (email_log_id) REFERENCES email_logs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 8. email_event_buffer: low-priority events held for daily/weekly digests.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_event_buffer (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id             BIGINT UNSIGNED NOT NULL,
  event_key           VARCHAR(96)     NOT NULL,
  payload_json        JSON            NOT NULL,
  digest_mode         ENUM('daily','weekly') NOT NULL DEFAULT 'daily',
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  flushed_at          DATETIME        NULL,
  KEY idx_email_event_buffer_user (user_id, flushed_at),
  KEY idx_email_event_buffer_pending (flushed_at, digest_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 9. email_send_jobs: durable queue. The queue worker
--    (api/email/queue_run.php) selects rows where due_at <= NOW().
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_send_jobs (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_log_id        BIGINT UNSIGNED NOT NULL,
  status              ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  attempts            INT UNSIGNED    NOT NULL DEFAULT 0,
  due_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  picked_at           DATETIME        NULL,
  finished_at         DATETIME        NULL,
  last_error          VARCHAR(512)    NULL,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_send_jobs_log (email_log_id),
  KEY idx_email_send_jobs_due (status, due_at),
  CONSTRAINT fk_email_send_jobs_log
    FOREIGN KEY (email_log_id) REFERENCES email_logs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Seed: required-notification map for built-in roles.
-- Edit later as the org adds roles.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO role_required_notifications (role_code, preference_group) VALUES
  ('owner',                 'system_alerts'),
  ('owner',                 'legal_issues'),
  ('owner',                 'privacy_issues'),
  ('owner',                 'billing_issues'),
  ('owner',                 'membership_changes'),
  ('support_agent',         'assigned_tickets'),
  ('support_agent',         'overdue_tickets'),
  ('support_supervisor',    'assigned_tickets'),
  ('support_supervisor',    'overdue_tickets'),
  ('support_supervisor',    'escalations'),
  ('billing_ops',           'billing_issues'),
  ('billing_ops',           'refunds'),
  ('membership_ops',        'membership_changes'),
  ('membership_ops',        'promo_code_changes'),
  ('marketing_lead',        'promo_code_changes'),
  ('marketing_lead',        'campaigns'),
  ('privacy_officer',       'privacy_issues'),
  ('legal_owner',           'legal_issues'),
  ('services_rep',          'service_task_assignments'),
  ('services_supervisor',   'service_task_assignments'),
  ('services_supervisor',   'escalations'),
  ('sales_rep',             'sales_leads'),
  ('sales_rep',             'demo_requests'),
  ('sales_lead',            'sales_leads'),
  ('sales_lead',            'demo_requests'),
  ('oncall',                'system_alerts');

-- ---------------------------------------------------------------------------
-- Verification: each table should exist; counts mostly zero (role map seeded).
-- ---------------------------------------------------------------------------
SHOW TABLES LIKE 'email_preferences';
SHOW TABLES LIKE 'employee_notification_preferences';
SHOW TABLES LIKE 'role_required_notifications';
SHOW TABLES LIKE 'unsubscribe_tokens';
SHOW TABLES LIKE 'email_delivery_failures';
SHOW TABLES LIKE 'email_open_events';
SHOW TABLES LIKE 'email_click_events';
SHOW TABLES LIKE 'email_event_buffer';
SHOW TABLES LIKE 'email_send_jobs';

SELECT
  (SELECT COUNT(*) FROM email_preferences)                  AS prefs,
  (SELECT COUNT(*) FROM employee_notification_preferences)  AS emp_prefs,
  (SELECT COUNT(*) FROM role_required_notifications)        AS role_required,
  (SELECT COUNT(*) FROM unsubscribe_tokens)                 AS unsub_tokens,
  (SELECT COUNT(*) FROM email_delivery_failures)            AS failures,
  (SELECT COUNT(*) FROM email_open_events)                  AS opens,
  (SELECT COUNT(*) FROM email_click_events)                 AS clicks,
  (SELECT COUNT(*) FROM email_event_buffer)                 AS digest_buffer,
  (SELECT COUNT(*) FROM email_send_jobs)                    AS send_jobs;

-- ---------------------------------------------------------------------------
-- Roll-back block (run only if you need to undo this migration).
-- ---------------------------------------------------------------------------
-- DROP TABLE IF EXISTS email_send_jobs;
-- DROP TABLE IF EXISTS email_event_buffer;
-- DROP TABLE IF EXISTS email_click_events;
-- DROP TABLE IF EXISTS email_open_events;
-- DROP TABLE IF EXISTS email_delivery_failures;
-- DROP TABLE IF EXISTS unsubscribe_tokens;
-- DROP TABLE IF EXISTS role_required_notifications;
-- DROP TABLE IF EXISTS employee_notification_preferences;
-- DROP TABLE IF EXISTS email_preferences;
