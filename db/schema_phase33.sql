-- Phase 33 migration: scheduled-deletion grace period for customer accounts.
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- Adds four columns to users that track a soft-scheduled hard-delete with a
-- 30-day grace window. Inserts three privacy-class email templates and three
-- event bindings (account.deletion_requested, account.deletion_cancelled,
-- account.deleted) so the dispatcher can fire on schedule, cancel, and final
-- deletion.
--
-- Depends on: schema_phase31 / 32 / 31b (email system tables and seed) must
-- already exist.
-- Run once in phpMyAdmin against the relicheck database.

USE dbs15641829;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. Users table: deletion-grace columns.
-- ---------------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN deletion_scheduled_at      DATETIME     NULL DEFAULT NULL
    COMMENT 'When the admin scheduled this account for deletion. NULL = not scheduled.',
  ADD COLUMN deletion_grace_ends_at     DATETIME     NULL DEFAULT NULL
    COMMENT 'When the cron will hard-delete this account. Customer can cancel before this time.',
  ADD COLUMN deletion_requested_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Admin user_id who scheduled the deletion.',
  ADD COLUMN deletion_reason            VARCHAR(500) NULL DEFAULT NULL
    COMMENT 'Free-text reason recorded at schedule time.',
  ADD KEY idx_users_deletion_grace (deletion_grace_ends_at);

-- ---------------------------------------------------------------------------
-- 2. Three new privacy-class customer templates.
-- ---------------------------------------------------------------------------
INSERT INTO email_templates
  (template_key, email_name, department_id, subject_line, preview_text,
   body_html, body_text, primary_button_label, primary_button_url_template,
   dynamic_fields, audience, is_required, is_unsubscribable, unsubscribe_group, restricted_data)
VALUES
  ('customer.privacy.account_deletion_requested',
   'Account Deletion Requested',
   (SELECT id FROM email_departments WHERE code='privacy'),
   'Your ReliCheck account is scheduled for deletion',
   'Your account will be permanently deleted on {{grace_ends_at}}.',
   '<p>Hi {{first_name}},</p><p>Your ReliCheck account ({{email}}) has been scheduled for deletion. Unless you cancel, all account data, surveys, responses, and reports will be permanently removed on <strong>{{grace_ends_at}}</strong>.</p><p>If this was a mistake or you change your mind, sign in any time before that date and your account will be restored automatically. After the grace period ends, recovery is not possible.</p>',
   'Hi {{first_name}},\n\nYour ReliCheck account ({{email}}) has been scheduled for deletion. Unless you cancel, all account data, surveys, responses, and reports will be permanently removed on {{grace_ends_at}}.\n\nIf this was a mistake or you change your mind, sign in any time before that date and your account will be restored automatically. After the grace period ends, recovery is not possible.\n\n{{button_url}}',
   'Cancel deletion', '{{site_url}}/app.html',
   JSON_ARRAY('first_name','email','grace_ends_at'), 'customer', 1, 0, NULL, 0),

  ('customer.privacy.account_deletion_cancelled',
   'Account Deletion Cancelled',
   (SELECT id FROM email_departments WHERE code='privacy'),
   'Your ReliCheck account deletion was cancelled',
   'Your account has been restored.',
   '<p>Hi {{first_name}},</p><p>The scheduled deletion of your ReliCheck account ({{email}}) was cancelled on {{cancelled_at}}. Your account is fully restored and you can sign in normally.</p>',
   'Hi {{first_name}},\n\nThe scheduled deletion of your ReliCheck account ({{email}}) was cancelled on {{cancelled_at}}. Your account is fully restored and you can sign in normally.\n\n{{button_url}}',
   'Open ReliCheck', '{{site_url}}/app.html',
   JSON_ARRAY('first_name','email','cancelled_at'), 'customer', 1, 0, NULL, 0),

  ('customer.privacy.account_deleted',
   'Account Deleted',
   (SELECT id FROM email_departments WHERE code='privacy'),
   'Your ReliCheck account has been deleted',
   'All account data has been permanently removed.',
   '<p>Hi {{first_name}},</p><p>Your ReliCheck account ({{email}}) and all associated data, including surveys, responses, and reports, have been permanently deleted on {{deleted_at}}.</p><p>If you did not request this and believe it was made in error, please contact <a href="mailto:privacy@relichecksurvey.com">privacy@relichecksurvey.com</a>. Note that account data cannot be recovered.</p><p>Thank you for the time you spent with us.</p>',
   'Hi {{first_name}},\n\nYour ReliCheck account ({{email}}) and all associated data, including surveys, responses, and reports, have been permanently deleted on {{deleted_at}}.\n\nIf you did not request this and believe it was made in error, please contact privacy@relichecksurvey.com. Note that account data cannot be recovered.\n\nThank you for the time you spent with us.',
   NULL, NULL,
   JSON_ARRAY('first_name','email','deleted_at'), 'customer', 1, 0, NULL, 0)
ON DUPLICATE KEY UPDATE
  email_name = VALUES(email_name), subject_line = VALUES(subject_line),
  preview_text = VALUES(preview_text), body_html = VALUES(body_html),
  body_text = VALUES(body_text), primary_button_label = VALUES(primary_button_label),
  primary_button_url_template = VALUES(primary_button_url_template),
  dynamic_fields = VALUES(dynamic_fields), is_required = VALUES(is_required),
  is_unsubscribable = VALUES(is_unsubscribable),
  unsubscribe_group = VALUES(unsubscribe_group),
  restricted_data = VALUES(restricted_data), is_active = 1;

-- Snapshot the three new templates as v1 in template_versions.
INSERT IGNORE INTO email_template_versions
  (template_id, version_number, subject_line, preview_text, body_html, body_text,
   primary_button_label, primary_button_url_template, dynamic_fields,
   edited_by_user_id, change_note)
SELECT id, current_version, subject_line, preview_text, body_html, body_text,
       primary_button_label, primary_button_url_template, dynamic_fields,
       NULL, 'Initial seed (schema_phase33)'
FROM email_templates
WHERE template_key IN (
  'customer.privacy.account_deletion_requested',
  'customer.privacy.account_deletion_cancelled',
  'customer.privacy.account_deleted'
);

-- ---------------------------------------------------------------------------
-- 3. Event bindings.
-- ---------------------------------------------------------------------------
INSERT INTO email_events
  (event_key, description, customer_template_id, employee_template_id,
   recipient_resolver, dedupe_window_minutes, priority, is_required)
VALUES
  ('account.deletion_requested',
   'Customer account scheduled for deletion (30-day grace)',
   (SELECT id FROM email_templates WHERE template_key='customer.privacy.account_deletion_requested'),
   NULL,
   'customer_self', 0, 'P0', 1),
  ('account.deletion_cancelled',
   'Scheduled customer deletion was cancelled',
   (SELECT id FROM email_templates WHERE template_key='customer.privacy.account_deletion_cancelled'),
   NULL,
   'customer_self', 0, 'P1', 1),
  ('account.deleted',
   'Customer account has been hard-deleted',
   (SELECT id FROM email_templates WHERE template_key='customer.privacy.account_deleted'),
   NULL,
   'customer_self', 0, 'P0', 1)
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  customer_template_id = VALUES(customer_template_id),
  recipient_resolver = VALUES(recipient_resolver),
  priority = VALUES(priority), is_required = VALUES(is_required),
  is_active = 1;

-- ---------------------------------------------------------------------------
-- Verification.
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM users LIKE 'deletion_%';
SELECT template_key, audience, primary_button_label
FROM email_templates
WHERE template_key LIKE 'customer.privacy.account_delet%'
ORDER BY template_key;
SELECT event_key, recipient_resolver, priority
FROM email_events
WHERE event_key LIKE 'account.delet%'
ORDER BY event_key;
-- Expected: 4 user columns; 3 templates; 3 events.

-- ---------------------------------------------------------------------------
-- Roll-back block (run only if you need to undo this migration).
-- ---------------------------------------------------------------------------
-- DELETE FROM email_events WHERE event_key IN
--   ('account.deletion_requested','account.deletion_cancelled','account.deleted');
-- DELETE FROM email_template_versions WHERE template_id IN
--   (SELECT id FROM email_templates WHERE template_key LIKE 'customer.privacy.account_delet%');
-- DELETE FROM email_templates WHERE template_key LIKE 'customer.privacy.account_delet%';
-- ALTER TABLE users
--   DROP KEY idx_users_deletion_grace,
--   DROP COLUMN deletion_reason,
--   DROP COLUMN deletion_requested_by_user_id,
--   DROP COLUMN deletion_grace_ends_at,
--   DROP COLUMN deletion_scheduled_at;
