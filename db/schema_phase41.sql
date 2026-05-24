-- Phase 41 migration: take-page tracking upgrades.
--   * responses.channel              - Q_CHANNEL-style tag (email, link, qr, sms)
--   * responses.is_partial           - 1 = draft, 0 = final
--   * responses.last_seen_at         - last autosave / page activity
--   * response_drafts                - per-token in-progress answers
--   * Updated invitation + reminder templates: now reference {{unsubscribe_link}}
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- FULLY IDEMPOTENT.

USE dbs15641829;
SET NAMES utf8mb4;

-- 1. responses.channel
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'responses' AND column_name = 'channel'),
  'SELECT 1',
  "ALTER TABLE responses ADD COLUMN channel VARCHAR(32) NULL DEFAULT NULL COMMENT 'Q_CHANNEL-style tag (email, link, qr, sms, other).'"
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. responses.is_partial
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'responses' AND column_name = 'is_partial'),
  'SELECT 1',
  "ALTER TABLE responses ADD COLUMN is_partial TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = draft (autosave only), 0 = final submission.'"
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. responses.last_seen_at
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'responses' AND column_name = 'last_seen_at'),
  'SELECT 1',
  "ALTER TABLE responses ADD COLUMN last_seen_at DATETIME NULL DEFAULT NULL COMMENT 'Last autosave / page activity timestamp.'"
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. response_drafts
CREATE TABLE IF NOT EXISTS response_drafts (
  survey_id    BIGINT UNSIGNED NOT NULL,
  inv_token    CHAR(32)        NOT NULL,
  answers      JSON            NOT NULL,
  channel      VARCHAR(32)     NULL,
  last_seen_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (survey_id, inv_token),
  KEY idx_response_drafts_seen (last_seen_at),
  CONSTRAINT fk_response_drafts_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Update invitation + reminder templates to include an unsubscribe footer
--    referencing {{unsubscribe_link}}. Idempotent ON DUPLICATE KEY rewrite.
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
   '<p>Hi {{first_name}},</p><p>{{sender_name}} has invited you to take a short survey: <strong>{{survey_name}}</strong>.</p><p>Your responses are confidential and the survey takes a few minutes to complete.</p><p style="word-break:break-all;background:#f4f5fb;padding:10px 12px;border-radius:6px;"><a href="{{invitation_link}}">{{invitation_link}}</a></p><p>If you have already responded, you can ignore this message.</p><hr style="border:0;border-top:1px solid #e2e4ea;margin:24px 0;"><p style="font-size:12px;color:#7a8499;">Do not want these messages? <a href="{{unsubscribe_link}}" style="color:#7a8499;">Unsubscribe</a>.</p>',
   'Hi {{first_name}},\n\n{{sender_name}} has invited you to take a short survey: {{survey_name}}.\n\nYour responses are confidential and the survey takes a few minutes to complete.\n\n{{invitation_link}}\n\nIf you have already responded, you can ignore this message.\n\nUnsubscribe: {{unsubscribe_link}}',
   'Open survey', '{{invitation_link}}',
   JSON_ARRAY('first_name','sender_name','survey_name','invitation_link','unsubscribe_link'), 'customer', 0, 1, 'survey_distribution', 0),

  ('customer.distribution.survey_reminder',
   'Survey Reminder',
   (SELECT id FROM email_departments WHERE code='services'),
   'Reminder: {{survey_name}}',
   'A short reminder. Your input still helps.',
   '<p>Hi {{first_name}},</p><p>This is a quick reminder that <strong>{{survey_name}}</strong> is still open. Your responses help shape what comes next.</p><p style="word-break:break-all;background:#f4f5fb;padding:10px 12px;border-radius:6px;"><a href="{{invitation_link}}">{{invitation_link}}</a></p><p>The survey takes a few minutes. If you have already responded, you can ignore this message.</p><hr style="border:0;border-top:1px solid #e2e4ea;margin:24px 0;"><p style="font-size:12px;color:#7a8499;">Do not want these messages? <a href="{{unsubscribe_link}}" style="color:#7a8499;">Unsubscribe</a>.</p>',
   'Hi {{first_name}},\n\nThis is a quick reminder that {{survey_name}} is still open. Your responses help shape what comes next.\n\n{{invitation_link}}\n\nThe survey takes a few minutes. If you have already responded, you can ignore this message.\n\nUnsubscribe: {{unsubscribe_link}}',
   'Open survey', '{{invitation_link}}',
   JSON_ARRAY('first_name','sender_name','survey_name','invitation_link','unsubscribe_link'), 'customer', 0, 1, 'survey_distribution', 0)
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
       NULL, 'Phase 41: added unsubscribe footer'
FROM email_templates
WHERE template_key IN (
  'customer.distribution.survey_invitation',
  'customer.distribution.survey_reminder'
);

-- 6. Verification.
SHOW COLUMNS FROM responses LIKE 'channel';
SHOW COLUMNS FROM responses LIKE 'is_partial';
SHOW COLUMNS FROM responses LIKE 'last_seen_at';
SHOW TABLES LIKE 'response_drafts';
SELECT COUNT(*) AS draft_rows FROM response_drafts;

-- Roll-back (run only if you need to undo):
-- DROP TABLE IF EXISTS response_drafts;
-- ALTER TABLE responses
--   DROP COLUMN channel,
--   DROP COLUMN is_partial,
--   DROP COLUMN last_seen_at;
