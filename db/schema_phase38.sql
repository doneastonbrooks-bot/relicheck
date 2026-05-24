-- Phase 38 migration: Distribution module.
--   * survey_contacts            - per-survey list of invitees
--   * survey_invitations         - one row per invitation send (with status + token)
--   * survey_reminder_schedules  - per-survey reminder cadence config
--   * 2 new email templates seeded (customer.distribution.survey_invitation,
--                                   customer.distribution.survey_reminder)
--   * 2 new email events seeded
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- FULLY IDEMPOTENT. Safe to paste-and-run as many times as needed.
-- Depends on Phase 31 / 32 / 31b (email infrastructure must already exist).

USE dbs15641829;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. survey_contacts
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS survey_contacts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  survey_id     BIGINT UNSIGNED NOT NULL,
  email         VARCHAR(255)    NOT NULL,
  name          VARCHAR(120)    NULL,
  external_ref  VARCHAR(120)    NULL COMMENT 'Optional ID from imported CSV.',
  status        ENUM('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
  added_by      BIGINT UNSIGNED NOT NULL COMMENT 'User who added this contact.',
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_survey_email (survey_id, email),
  KEY idx_contacts_survey_status (survey_id, status),
  CONSTRAINT fk_contacts_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE,
  CONSTRAINT fk_contacts_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. survey_invitations
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS survey_invitations (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  survey_id           BIGINT UNSIGNED NOT NULL,
  contact_id          BIGINT UNSIGNED NOT NULL,
  invitation_token    CHAR(32)        NOT NULL COMMENT 'Unique token used in personalized link.',
  status              ENUM('queued','sent','opened','completed','bounced','failed') NOT NULL DEFAULT 'queued',
  sent_at             DATETIME        NULL,
  opened_at           DATETIME        NULL,
  completed_at        DATETIME        NULL,
  reminder_count      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_reminder_at    DATETIME        NULL,
  email_log_id        BIGINT UNSIGNED NULL COMMENT 'FK to email_logs from Phase 31.',
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_invitation_token (invitation_token),
  KEY idx_inv_survey_status (survey_id, status),
  KEY idx_inv_contact (contact_id),
  CONSTRAINT fk_inv_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_contact FOREIGN KEY (contact_id) REFERENCES survey_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. survey_reminder_schedules
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS survey_reminder_schedules (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  survey_id           BIGINT UNSIGNED NOT NULL,
  enabled             TINYINT(1)      NOT NULL DEFAULT 1,
  days_until_first    INT             NOT NULL DEFAULT 3 COMMENT 'Days after invite before first reminder.',
  days_between        INT             NOT NULL DEFAULT 4 COMMENT 'Days between subsequent reminders.',
  max_reminders       TINYINT         NOT NULL DEFAULT 2,
  created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reminder_survey (survey_id),
  CONSTRAINT fk_reminder_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Email templates: invitation + reminder.
-- ---------------------------------------------------------------------------
INSERT INTO email_templates
  (template_key, email_name, department_id, subject_line, preview_text,
   body_html, body_text, primary_button_label, primary_button_url_template,
   dynamic_fields, audience, is_required, is_unsubscribable, unsubscribe_group, restricted_data)
VALUES
  ('customer.distribution.survey_invitation',
   'Survey Invitation',
   (SELECT id FROM email_departments WHERE code='services'),
   '{{sender_name}} invited you to {{survey_name}}',
   'Your input takes a few minutes. Open the survey to begin.',
   '<p>Hi {{first_name}},</p><p>{{sender_name}} has invited you to take a short survey: <strong>{{survey_name}}</strong>.</p><p>Your responses are confidential and the survey takes a few minutes to complete.</p><p style="word-break:break-all;background:#f4f5fb;padding:10px 12px;border-radius:6px;"><a href="{{invitation_link}}">{{invitation_link}}</a></p><p>If you have already responded, you can ignore this message.</p>',
   'Hi {{first_name}},\n\n{{sender_name}} has invited you to take a short survey: {{survey_name}}.\n\nYour responses are confidential and the survey takes a few minutes to complete.\n\n{{invitation_link}}\n\nIf you have already responded, you can ignore this message.',
   'Open survey', '{{invitation_link}}',
   JSON_ARRAY('first_name','sender_name','survey_name','invitation_link'), 'customer', 0, 1, 'survey_distribution', 0),

  ('customer.distribution.survey_reminder',
   'Survey Reminder',
   (SELECT id FROM email_departments WHERE code='services'),
   'Reminder: {{survey_name}}',
   'A short reminder. Your input still helps.',
   '<p>Hi {{first_name}},</p><p>This is a quick reminder that <strong>{{survey_name}}</strong> is still open. Your responses help shape what comes next.</p><p style="word-break:break-all;background:#f4f5fb;padding:10px 12px;border-radius:6px;"><a href="{{invitation_link}}">{{invitation_link}}</a></p><p>The survey takes a few minutes. If you have already responded, you can ignore this message.</p>',
   'Hi {{first_name}},\n\nThis is a quick reminder that {{survey_name}} is still open. Your responses help shape what comes next.\n\n{{invitation_link}}\n\nThe survey takes a few minutes. If you have already responded, you can ignore this message.',
   'Open survey', '{{invitation_link}}',
   JSON_ARRAY('first_name','sender_name','survey_name','invitation_link'), 'customer', 0, 1, 'survey_distribution', 0)
ON DUPLICATE KEY UPDATE
  email_name = VALUES(email_name), subject_line = VALUES(subject_line),
  preview_text = VALUES(preview_text), body_html = VALUES(body_html),
  body_text = VALUES(body_text), primary_button_label = VALUES(primary_button_label),
  primary_button_url_template = VALUES(primary_button_url_template),
  dynamic_fields = VALUES(dynamic_fields), is_required = VALUES(is_required),
  is_unsubscribable = VALUES(is_unsubscribable),
  unsubscribe_group = VALUES(unsubscribe_group),
  restricted_data = VALUES(restricted_data), is_active = 1;

INSERT IGNORE INTO email_template_versions
  (template_id, version_number, subject_line, preview_text, body_html, body_text,
   primary_button_label, primary_button_url_template, dynamic_fields,
   edited_by_user_id, change_note)
SELECT id, current_version, subject_line, preview_text, body_html, body_text,
       primary_button_label, primary_button_url_template, dynamic_fields,
       NULL, 'Initial seed (schema_phase38)'
FROM email_templates
WHERE template_key IN (
  'customer.distribution.survey_invitation',
  'customer.distribution.survey_reminder'
);

-- ---------------------------------------------------------------------------
-- 5. Email events: distribution.invitation, distribution.reminder.
-- ---------------------------------------------------------------------------
INSERT INTO email_events
  (event_key, description, customer_template_id, employee_template_id,
   recipient_resolver, dedupe_window_minutes, priority, is_required)
VALUES
  ('distribution.invitation',
   'Survey invitation sent to a contact.',
   (SELECT id FROM email_templates WHERE template_key='customer.distribution.survey_invitation'),
   NULL, 'invitation_contact', 0, 'P1', 0),
  ('distribution.reminder',
   'Survey reminder sent to a contact who has not completed.',
   (SELECT id FROM email_templates WHERE template_key='customer.distribution.survey_reminder'),
   NULL, 'invitation_contact', 0, 'P2', 0)
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  customer_template_id = VALUES(customer_template_id),
  recipient_resolver = VALUES(recipient_resolver),
  priority = VALUES(priority), is_required = VALUES(is_required),
  is_active = 1;

-- ---------------------------------------------------------------------------
-- 6. Verification.
-- ---------------------------------------------------------------------------
SHOW TABLES LIKE 'survey_contacts';
SHOW TABLES LIKE 'survey_invitations';
SHOW TABLES LIKE 'survey_reminder_schedules';
SELECT template_key FROM email_templates WHERE template_key IN (
  'customer.distribution.survey_invitation',
  'customer.distribution.survey_reminder'
);
SELECT event_key FROM email_events WHERE event_key IN (
  'distribution.invitation',
  'distribution.reminder'
);
SELECT COUNT(*) AS contacts FROM survey_contacts;
SELECT COUNT(*) AS invitations FROM survey_invitations;
SELECT COUNT(*) AS reminder_schedules FROM survey_reminder_schedules;
-- Expected: 3 tables shown, 2 templates, 2 events, 0/0/0 rows on first install.

-- Roll-back (run only if you need to undo this migration):
-- DELETE FROM email_events WHERE event_key IN ('distribution.invitation','distribution.reminder');
-- DELETE FROM email_template_versions WHERE template_id IN (
--   SELECT id FROM email_templates WHERE template_key IN ('customer.distribution.survey_invitation','customer.distribution.survey_reminder'));
-- DELETE FROM email_templates WHERE template_key IN ('customer.distribution.survey_invitation','customer.distribution.survey_reminder');
-- DROP TABLE IF EXISTS survey_invitations;
-- DROP TABLE IF EXISTS survey_reminder_schedules;
-- DROP TABLE IF EXISTS survey_contacts;
