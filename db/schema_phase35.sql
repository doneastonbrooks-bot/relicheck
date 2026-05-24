-- Phase 35 migration: Trial Midpoint template + event binding.
--
-- IMPORTANT: select the database first (drop-down at the top-left of phpMyAdmin
-- should read "dbs15641829") before running.
--
-- Adds the missing customer.membership.trial_midpoint template (was listed
-- under Phase 2 in the original spec, not seeded at launch) and the
-- trial.midpoint event binding. Required by api/admin/cron/trial_lifecycle.php.
--
-- Depends on schema_phase31, 32, 31b. Re-runnable.

USE dbs15641829;

SET NAMES utf8mb4;

INSERT INTO email_templates
  (template_key, email_name, department_id, subject_line, preview_text,
   body_html, body_text, primary_button_label, primary_button_url_template,
   dynamic_fields, audience, is_required, is_unsubscribable, unsubscribe_group, restricted_data)
VALUES
  ('customer.membership.trial_midpoint',
   'Trial Midpoint',
   (SELECT id FROM email_departments WHERE code='membership'),
   'You are halfway through your ReliCheck trial',
   '{{days_remaining}} days left to explore ReliCheck.',
   '<p>Hi {{first_name}},</p><p>You are halfway through your ReliCheck trial of <strong>{{plan_name}}</strong>. {{days_remaining}} days remain (trial ends {{trial_end_date}}).</p><p>If you have not built a survey yet, now is a great time. If you already have one running, take a look at the report or AI insights views to see what you can do with the data.</p>',
   'Hi {{first_name}},\n\nYou are halfway through your ReliCheck trial of {{plan_name}}. {{days_remaining}} days remain (trial ends {{trial_end_date}}).\n\nIf you have not built a survey yet, now is a great time. If you already have one running, take a look at the report or AI insights views to see what you can do with the data.\n\n{{button_url}}',
   'Open ReliCheck', '{{site_url}}/app.html',
   JSON_ARRAY('first_name','plan_name','days_remaining','trial_end_date'), 'customer', 0, 1, 'membership_promo', 0)
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
       NULL, 'Initial seed (schema_phase35)'
FROM email_templates
WHERE template_key = 'customer.membership.trial_midpoint';

INSERT INTO email_events
  (event_key, description, customer_template_id, employee_template_id,
   recipient_resolver, dedupe_window_minutes, priority, is_required)
VALUES
  ('trial.midpoint', 'Customer is halfway through their trial',
   (SELECT id FROM email_templates WHERE template_key='customer.membership.trial_midpoint'),
   NULL, 'customer_self', 0, 'P2', 0)
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  customer_template_id = VALUES(customer_template_id),
  recipient_resolver = VALUES(recipient_resolver),
  priority = VALUES(priority), is_required = VALUES(is_required),
  is_active = 1;

-- Verification.
SELECT template_key FROM email_templates WHERE template_key = 'customer.membership.trial_midpoint';
SELECT event_key FROM email_events WHERE event_key = 'trial.midpoint';
-- Expected: 1 template, 1 event.

-- Roll-back (run only if you need to undo this migration):
-- DELETE FROM email_events WHERE event_key = 'trial.midpoint';
-- DELETE FROM email_template_versions WHERE template_id IN (SELECT id FROM email_templates WHERE template_key = 'customer.membership.trial_midpoint');
-- DELETE FROM email_templates WHERE template_key = 'customer.membership.trial_midpoint';
