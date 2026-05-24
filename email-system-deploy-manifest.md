# ReliCheck Email System: deploy manifest

Order of operations: SQL first (phpMyAdmin), then files (FileZilla), then config, then cron.

---

## 1. Database (run in phpMyAdmin, in this exact order)

Confirm the database drop-down at the top-left of phpMyAdmin reads `dbs15641829` before each run.

| # | File | Notes |
|---|------|-------|
| 1 | `db/schema_phase31.sql` | Core tables: departments, templates, template_versions, events, logs, audit_logs, suppression_list. Verification queries print at the end. |
| 2 | `db/schema_phase32.sql` | Preferences and tracking: email_preferences, employee_notification_preferences, role_required_notifications (seeded), unsubscribe_tokens, delivery_failures, open_events, click_events, event_buffer, send_jobs. |
| 3 | `db/schema_phase31b.sql` | Seed: eleven departments, all 24 customer launch templates, all 19 employee launch templates, 40 event bindings. Re-runnable. Final query should report 11 / 43 / 43 / 40. |

If anything goes wrong, each file has a roll-back block at the bottom (commented out by default).

These three SQL files do **not** ship via FileZilla. They are pasted into phpMyAdmin only.

---

## 2. Files to upload via FileZilla

All paths are relative to your IONOS web root.

### New library files

| Source path | IONOS destination |
|---|---|
| `api/_email_dispatcher.php` | `/api/_email_dispatcher.php` |
| `api/_email_renderer.php`   | `/api/_email_renderer.php` |
| `api/_email_resolver.php`   | `/api/_email_resolver.php` |

### New customer-facing endpoints

| Source path | IONOS destination |
|---|---|
| `api/email/queue_run.php`    | `/api/email/queue_run.php` |
| `api/email/preferences.php`  | `/api/email/preferences.php` |
| `api/email/unsubscribe.php`  | `/api/email/unsubscribe.php` |
| `api/email/resend.php`       | `/api/email/resend.php` |
| `api/email/suppression.php`  | `/api/email/suppression.php` |
| `api/webhooks/email.php`     | `/api/webhooks/email.php` |

### New admin endpoints

| Source path | IONOS destination |
|---|---|
| `api/admin/email/templates.php` | `/api/admin/email/templates.php` |
| `api/admin/email/logs.php`      | `/api/admin/email/logs.php` |
| `api/admin/email/failures.php`  | `/api/admin/email/failures.php` |
| `api/admin/email/audit.php`     | `/api/admin/email/audit.php` |

### New admin UI page

| Source path | IONOS destination |
|---|---|
| `admin-email.html` | `/admin-email.html` |

### Specification (reference only, do not need to upload)

| Source path | Stays local |
|---|---|
| `email-system-specification.md` | reference doc for the build |
| `email-system-deploy-manifest.md` | this file |

Total: **14 PHP files** + **1 HTML page** to upload via FileZilla.

---

## 3. Config additions in `api/_config.php`

Add (or confirm) these keys:

```php
// Email cron worker auth (recommended). Set to a 32+ char random string.
'email_cron_key' => 'CHANGE-ME-to-a-long-random-string',

// Email provider webhook signing secret (only if using a transactional provider
// that posts delivery callbacks). Set to a 32+ char random string and
// configure the provider to sign with HMAC-SHA256 using this secret in
// the X-Relicheck-Signature header.
'email_webhook_secret' => 'CHANGE-ME-to-a-long-random-string',

// Internal domains allowed for the admin "Test send" button.
'email_test_send_domains' => ['relichecksurvey.com'],
```

You should already have `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `mail_from`, `mail_from_name`, and `site_url` from the existing `_mailer.php`. The dispatcher reuses them.

---

## 4. Cron job (IONOS control panel -> Cron Jobs)

Add a job that hits the queue worker once a minute:

```
* * * * * curl -fsS "https://relichecksurvey.com/api/email/queue_run.php?key=YOUR_email_cron_key" > /dev/null
```

If you cannot reach `curl`, IONOS also supports calling the URL directly from its scheduled-tasks UI. Either works.

---

## 5. Wiring existing events to the dispatcher

The dispatcher is idle until something calls it. Add `relicheck_email_dispatch(...)` calls at these places (additive, no edits to existing flow):

| Existing endpoint | Event to fire |
|---|---|
| `api/auth/signup.php` after insert | `user.created` |
| `api/auth/verify.php` after success | `user.email_verified` |
| `api/auth/forgot.php` (replace inline send_mail) | `password.reset_requested` |
| `api/account/change_password.php` after success | `password.changed` |
| `api/auth/login.php` when new device/IP detected | `auth.new_device_or_location` |
| `api/surveys/create.php` when first survey | `survey.first_created` |
| Survey publish endpoint | `survey.published` |
| Survey close endpoint | `survey.closed` |
| Report-generation flow on completion | `report.generated` |
| AI insights flow on completion | `insights.generated` |
| Trial start flow | `trial.started` |
| Nightly trial-state job | `trial.ending_soon`, `trial.expired` |
| Stripe webhook: invoice.paid | `billing.charge.succeeded` |
| Stripe webhook: invoice.payment_failed | `billing.charge.failed` |
| Stripe webhook: charge.refunded | `billing.refund_issued` |
| Support ticket create endpoint | `support.ticket.created` |
| Support ticket reply endpoint (agent) | `support.ticket.replied_by_agent` |
| Support ticket reply endpoint (customer) | `support.ticket.replied_by_customer` |
| Support ticket close endpoint | `support.ticket.closed` |
| Nightly SLA job | `support.ticket.overdue` |
| HR / staff invite endpoint | `hr.invite_employee` |
| HR / staff role change endpoint | `hr.role_changed` |
| HR / staff access remove endpoint | `hr.access_removed` |
| Admin promo create / edit | `membership.promo_created`, `membership.promo_edited` |
| Lead capture endpoint | `sales.lead_submitted`, `sales.demo_requested` |

Example call from `api/auth/signup.php`:

```php
require_once __DIR__ . '/../_email_dispatcher.php';
relicheck_email_dispatch('user.created', [
    'user_id'    => (int)$new_user_id,
    'account_id' => (int)$new_user_id,
    'idempotency_entity_id' => 'user:' . (int)$new_user_id,
    'payload'    => [
        'first_name'   => $first_name,
        'verify_token' => $verify_token,
    ],
]);
```

The dispatcher takes care of dedupe, preference checks, suppression, sender resolution, sanitized snapshotting, and logging. The actual SMTP call happens inside the cron worker on the next tick.

---

## 6. Smoke test checklist

1. Open `https://relichecksurvey.com/admin-email.html`. Confirm the privacy banner is visible and the Templates tab loads 43 rows across 11 departments.
2. Click any template, hit **Preview** with `{"first_name":"Donald","survey_name":"Test"}`, confirm the rendered HTML looks right.
3. Hit **Test send** to your `@relichecksurvey.com` address. Confirm the email arrives from the correct department sender.
4. From a real flow (e.g., `forgot password`), trigger a dispatch. Within a minute the cron worker should send the email and the Logs tab should show status `sent` -> `delivered`.
5. Try saving an employee template that includes `{{response_text}}` somewhere. The save endpoint should reject with `privacy_violation`. (This is the dispatcher safety net.)
6. Hard-bounce a test address and confirm the suppression list grows. (Webhook step optional if no provider connected yet.)

---

## 7. Files that already exist and will NOT change

The roll-out is purely additive. Per the project's "no cross-cutting endpoint changes" rule, no existing files in `/api/auth/`, `/api/billing/`, `/api/surveys/`, etc. are modified by this drop. Wiring new events is done by *adding* a single dispatcher call to each existing endpoint, in a separate small PR after the new infrastructure has been verified in production.
