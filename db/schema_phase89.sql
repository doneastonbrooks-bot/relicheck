-- Phase 89 migration: Key Drivers digest email.
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- Adds one customer template (customer.services.keydrivers_digest) and one
-- event binding (survey.keydrivers_digest_reached). The threshold and "last
-- fired at N" flag live in the existing surveys.settings JSON column, so no
-- columns are added to surveys.
--
-- Re-runnable. Depends on Phase 31 / 31b / 32 / 36.

USE dbs15641829;

SET NAMES utf8mb4;

-- 1. Template: customer.services.keydrivers_digest
--    The {{top_drivers_html}} and {{top_drivers_text}} variables are
--    composed by api/_keydrivers_snapshot.php from the live response data
--    at the moment the threshold is crossed.
INSERT INTO email_templates
  (template_key, email_name, department_id, subject_line, preview_text,
   body_html, body_text, primary_button_label, primary_button_url_template,
   dynamic_fields, audience, is_required, is_unsubscribable, unsubscribe_group, restricted_data)
VALUES
  ('customer.services.keydrivers_digest',
   'Key Drivers Digest',
   (SELECT id FROM email_departments WHERE code='services'),
   '{{survey_name}} hit {{threshold_n}} responses. Here are the top drivers.',
   'Your Key Drivers ranking just crossed the threshold you set.',
   '<p>Hi {{first_name}},</p><p><strong>{{survey_name}}</strong> just crossed <strong>{{threshold_n}}</strong> responses (currently {{response_count}}). The Key Drivers tab now has enough data to rank which survey factors are most strongly associated with <strong>{{outcome_label}}</strong>.</p><p>Top three drivers by simple correlation:</p>{{top_drivers_html}}<p style="font-size:14px;color:#555;">These are bivariate correlations as a quick read. The full ranking on the Key Drivers tab uses Johnson''s Relative Weights (for continuous outcomes) or standardized log-odds (for binary outcomes) and accounts for how the drivers covary, so a strong correlation here can shrink or grow once the others are in the model.</p><p>Open the Key Drivers tab to see the full ranking, the 95% confidence intervals, and the AI summary.</p>',
   'Hi {{first_name}},\n\n{{survey_name}} just crossed {{threshold_n}} responses (currently {{response_count}}). The Key Drivers tab now has enough data to rank which survey factors are most strongly associated with {{outcome_label}}.\n\nTop three drivers by simple correlation:\n\n{{top_drivers_text}}\n\nThese are bivariate correlations as a quick read. The full ranking on the Key Drivers tab uses Johnson Relative Weights (for continuous outcomes) or standardized log-odds (for binary outcomes) and accounts for how the drivers covary, so a strong correlation here can shrink or grow once the others are in the model.\n\nOpen the Key Drivers tab to see the full ranking, the 95% confidence intervals, and the AI summary.\n\n{{button_url}}',
   'Open Key Drivers', '{{site_url}}/app.html#survey/{{survey_id}}/analytics/keydrivers',
   JSON_ARRAY('first_name','survey_name','survey_id','response_count','threshold_n','outcome_label','top_drivers_html','top_drivers_text'),
   'customer', 0, 1, 'survey_activity', 0)
ON DUPLICATE KEY UPDATE
  email_name = VALUES(email_name), subject_line = VALUES(subject_line),
  preview_text = VALUES(preview_text), body_html = VALUES(body_html),
  body_text = VALUES(body_text), primary_button_label = VALUES(primary_button_label),
  primary_button_url_template = VALUES(primary_button_url_template),
  dynamic_fields = VALUES(dynamic_fields), is_required = VALUES(is_required),
  is_unsubscribable = VALUES(is_unsubscribable),
  unsubscribe_group = VALUES(unsubscribe_group),
  restricted_data = VALUES(restricted_data), is_active = 1;

-- 2. Initial version snapshot for the audit trail.
INSERT IGNORE INTO email_template_versions
  (template_id, version_number, subject_line, preview_text, body_html, body_text,
   primary_button_label, primary_button_url_template, dynamic_fields,
   edited_by_user_id, change_note)
SELECT id, current_version, subject_line, preview_text, body_html, body_text,
       primary_button_label, primary_button_url_template, dynamic_fields,
       NULL, 'Initial seed (schema_phase89)'
FROM email_templates
WHERE template_key = 'customer.services.keydrivers_digest';

-- 3. Event binding: survey.keydrivers_digest_reached
INSERT INTO email_events
  (event_key, description, customer_template_id, employee_template_id,
   recipient_resolver, dedupe_window_minutes, priority, is_required)
VALUES
  ('survey.keydrivers_digest_reached',
   'Survey crossed the configured Key Drivers digest threshold',
   (SELECT id FROM email_templates WHERE template_key='customer.services.keydrivers_digest'),
   NULL, 'customer_self', 0, 'P2', 0)
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  customer_template_id = VALUES(customer_template_id),
  recipient_resolver = VALUES(recipient_resolver),
  priority = VALUES(priority), is_required = VALUES(is_required),
  is_active = 1;

-- Verification: expected one new template and one new event.
SELECT template_key FROM email_templates WHERE template_key = 'customer.services.keydrivers_digest';
SELECT event_key    FROM email_events    WHERE event_key    = 'survey.keydrivers_digest_reached';

-- Roll-back (run only if you need to undo this migration):
-- DELETE FROM email_events WHERE event_key = 'survey.keydrivers_digest_reached';
-- DELETE FROM email_template_versions WHERE template_id IN (SELECT id FROM email_templates WHERE template_key = 'customer.services.keydrivers_digest');
-- DELETE FROM email_templates WHERE template_key = 'customer.services.keydrivers_digest';
