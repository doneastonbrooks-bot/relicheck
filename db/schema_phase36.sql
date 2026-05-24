-- Phase 36 migration: survey activity emails (no_responses, low_response_rate,
-- milestone_reached) plus the published_at column the cron needs.
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- Re-runnable. Depends on Phase 31 / 32 / 31b.

USE dbs15641829;

SET NAMES utf8mb4;

-- 1. surveys.published_at — when the survey first transitioned to is_published=1.
--    Used by the activity cron to compute "days since publish."
ALTER TABLE surveys
  ADD COLUMN published_at DATETIME NULL DEFAULT NULL
    COMMENT 'When the survey first became published. NULL = never published. Used by activity cron.',
  ADD KEY idx_surveys_published (is_published, published_at);

-- Backfill: any existing published survey gets its updated_at as the
-- best-available proxy for when it was published.
UPDATE surveys
SET published_at = updated_at
WHERE is_published = 1 AND published_at IS NULL;

-- 2. Three new customer.services templates.
INSERT INTO email_templates
  (template_key, email_name, department_id, subject_line, preview_text,
   body_html, body_text, primary_button_label, primary_button_url_template,
   dynamic_fields, audience, is_required, is_unsubscribable, unsubscribe_group, restricted_data)
VALUES
  ('customer.services.no_responses',
   'No Responses Yet',
   (SELECT id FROM email_departments WHERE code='services'),
   '{{survey_name}} has not received responses yet',
   'Three days in with no responses. Try sharing the link.',
   '<p>Hi {{first_name}},</p><p><strong>{{survey_name}}</strong> has been live for {{days_live}} days but has not received any responses yet. The most common reason is that respondents have not seen the link.</p><p>The share link below is active. If you have a list of respondents, send it now. If you are reaching them in person, the link works as a QR code too.</p><p style="word-break:break-all;background:#f4f5fb;padding:10px 12px;border-radius:6px;"><a href="{{public_survey_link}}">{{public_survey_link}}</a></p>',
   'Hi {{first_name}},\n\n{{survey_name}} has been live for {{days_live}} days but has not received any responses yet. The most common reason is that respondents have not seen the link.\n\nThe share link below is active. If you have a list of respondents, send it now. If you are reaching them in person, the link works as a QR code too.\n\n{{public_survey_link}}\n\n{{button_url}}',
   'Open survey distribution', '{{site_url}}/app.html',
   JSON_ARRAY('first_name','survey_name','survey_id','public_survey_link','days_live'), 'customer', 0, 1, 'survey_activity', 0),

  ('customer.services.low_response_rate',
   'Low Response Rate',
   (SELECT id FROM email_departments WHERE code='services'),
   '{{survey_name}} response rate is low',
   '{{response_count}} responses in {{days_live}} days. Reminder time?',
   '<p>Hi {{first_name}},</p><p><strong>{{survey_name}}</strong> has been live for {{days_live}} days and collected <strong>{{response_count}}</strong> responses so far.</p><p>If you were expecting more, a quick reminder to your respondents usually doubles the count. The share link is still active.</p><p style="word-break:break-all;background:#f4f5fb;padding:10px 12px;border-radius:6px;"><a href="{{public_survey_link}}">{{public_survey_link}}</a></p>',
   'Hi {{first_name}},\n\n{{survey_name}} has been live for {{days_live}} days and collected {{response_count}} responses so far.\n\nIf you were expecting more, a quick reminder to your respondents usually doubles the count. The share link is still active.\n\n{{public_survey_link}}\n\n{{button_url}}',
   'Open survey distribution', '{{site_url}}/app.html',
   JSON_ARRAY('first_name','survey_name','survey_id','public_survey_link','response_count','days_live'), 'customer', 0, 1, 'survey_activity', 0),

  ('customer.services.milestone_reached',
   'Milestone Reached',
   (SELECT id FROM email_departments WHERE code='services'),
   '{{survey_name}} just hit {{milestone_label}} responses',
   '{{response_count}} total responses and counting.',
   '<p>Hi {{first_name}},</p><p>Nice work. <strong>{{survey_name}}</strong> just crossed <strong>{{milestone_label}}</strong> responses (currently {{response_count}}).</p><p>You can review the latest numbers, generate a report, or run AI insights any time from your dashboard.</p>',
   'Hi {{first_name}},\n\nNice work. {{survey_name}} just crossed {{milestone_label}} responses (currently {{response_count}}).\n\nYou can review the latest numbers, generate a report, or run AI insights any time from your dashboard.\n\n{{button_url}}',
   'View results', '{{site_url}}/app.html',
   JSON_ARRAY('first_name','survey_name','survey_id','response_count','milestone_label'), 'customer', 0, 1, 'survey_activity', 0)
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
       NULL, 'Initial seed (schema_phase36)'
FROM email_templates
WHERE template_key IN (
  'customer.services.no_responses',
  'customer.services.low_response_rate',
  'customer.services.milestone_reached'
);

-- 3. Three new event bindings.
INSERT INTO email_events
  (event_key, description, customer_template_id, employee_template_id,
   recipient_resolver, dedupe_window_minutes, priority, is_required)
VALUES
  ('survey.no_responses', 'Survey has been live with zero responses',
   (SELECT id FROM email_templates WHERE template_key='customer.services.no_responses'),
   NULL, 'customer_self', 0, 'P2', 0),
  ('survey.low_response_rate', 'Survey has fewer responses than expected',
   (SELECT id FROM email_templates WHERE template_key='customer.services.low_response_rate'),
   NULL, 'customer_self', 0, 'P2', 0),
  ('survey.milestone_reached', 'Survey crossed a response milestone',
   (SELECT id FROM email_templates WHERE template_key='customer.services.milestone_reached'),
   NULL, 'customer_self', 0, 'P3', 0)
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  customer_template_id = VALUES(customer_template_id),
  recipient_resolver = VALUES(recipient_resolver),
  priority = VALUES(priority), is_required = VALUES(is_required),
  is_active = 1;

-- Verification.
SHOW COLUMNS FROM surveys LIKE 'published_at';
SELECT template_key FROM email_templates WHERE template_key LIKE 'customer.services.%' AND template_key NOT IN ('customer.services.first_survey_created','customer.services.survey_is_live','customer.services.survey_closed','customer.services.report_ready','customer.services.ai_insights_ready');
SELECT event_key FROM email_events WHERE event_key LIKE 'survey.%' AND event_key NOT IN ('survey.first_created','survey.published','survey.closed');
-- Expected: 1 column, 3 templates, 3 events.

-- Roll-back (run only if you need to undo this migration):
-- DELETE FROM email_events WHERE event_key IN ('survey.no_responses','survey.low_response_rate','survey.milestone_reached');
-- DELETE FROM email_template_versions WHERE template_id IN (SELECT id FROM email_templates WHERE template_key IN ('customer.services.no_responses','customer.services.low_response_rate','customer.services.milestone_reached'));
-- DELETE FROM email_templates WHERE template_key IN ('customer.services.no_responses','customer.services.low_response_rate','customer.services.milestone_reached');
-- ALTER TABLE surveys DROP KEY idx_surveys_published, DROP COLUMN published_at;
