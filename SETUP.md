# ReliCheck — Phase 1 Setup (Ionos + MySQL 8.0 + PHP)

This phase gets signup, login, logout, and "who am I" working against your
Ionos MySQL database. It does not yet wire surveys or responses to the
database — that comes in Phase 2.

## What you'll need

1. SFTP access to your Ionos webspace (the same credentials you use to upload `index.html`).
2. The Ionos MySQL credentials from the database overview page:
   - **Host name** (e.g. `db5020390340.hosting-data.io`)
   - **Port** (always `3306`)
   - **User name** (e.g. `dbu1274347`)
   - **Database name** (e.g. `dbs1274347`, shown above the password row)
   - **Password** (the one hidden behind `*******`; click the row in Ionos to reveal it)
3. Access to the Ionos phpMyAdmin tool (linked from the database overview page).

## Step 1 — Upload the files

Using SFTP or the Ionos File Explorer, upload everything in this folder to
your webspace, preserving the folder structure. The result on Ionos should
look like:

```
/  (your domain root — usually maps to relicheck.com)
├── index.html
├── about.html
├── login.html
├── signup.html
├── app.html
├── styles.css
├── favicon.svg
├── api/
│   ├── .htaccess
│   ├── _config.example.php
│   ├── _db.php
│   ├── _helpers.php
│   ├── _session.php
│   └── auth/
│       ├── signup.php
│       ├── login.php
│       ├── logout.php
│       └── me.php
└── db/
    └── schema.sql
```

The `.htaccess` in `api/` blocks any file that starts with an underscore
from being served directly, so `_config.php` and the helpers stay private.

## Step 2 — Create the tables

1. In your Ionos control panel, open the database overview page and click
   the link to **phpMyAdmin** (or "Open phpMyAdmin").
2. Sign in using the user name and password from the database overview.
3. On the left, click your database (`dbs…`).
4. Click the **SQL** tab at the top.
5. Open `db/schema.sql` from this project, paste the entire contents into
   the SQL window, and click **Go**.

You should see "Your SQL query has been executed successfully" and three
new tables on the left: `users`, `surveys`, `responses`.

## Step 3 — Add your credentials

1. On the server, copy `api/_config.example.php` to `api/_config.php`
   (most SFTP clients let you right-click → Duplicate, then rename).
2. Open `api/_config.php` and fill in:
   - `db_name` — your database name (e.g. `dbs1274347`)
   - `db_pass` — the password from the Ionos overview
3. Verify `db_host` and `db_user` already match what's shown on the Ionos
   page. If they don't, update them.
4. Save and re-upload (if you edited locally).

`_config.php` should never be committed to git or shared in chat — keep it
on the server only.

## Step 4 — Verify the API

Open a new browser tab and visit:

```
https://relichecksurvey.com/api/auth/me.php
```

You should see JSON like:

```json
{"authenticated": false}
```

If you see `{"error":"server_misconfigured", ...}`, the `_config.php` file
is missing or unreadable. If you see a blank page or a 500 error, see the
"Troubleshooting" section below.

## Step 5 — Try the real flow

1. Go to `https://relichecksurvey.com/signup.html`, fill in name + email +
   password (8+ chars, includes a digit), and submit.
2. You should briefly see "Welcome aboard" and land on `app.html`.
3. In phpMyAdmin, open the `users` table. You should see your new row
   with a hashed password (never the plaintext).
4. Visit `https://relichecksurvey.com/api/auth/me.php` again. It should now
   return `{"authenticated": true, "user": {...}}`.
5. Try `https://relichecksurvey.com/login.html` in a private window with
   the same credentials.

## Troubleshooting

**500 Internal Server Error on any /api/ URL.**
Check `error.log` in your Ionos webspace (under "Logs"). Common causes:
PHP version too old (Ionos defaults are fine, but if your space is set to
PHP 7.0 or older, switch it to 8.0+ in the Ionos hosting settings), or
`_config.php` has a syntax error.

**"server_misconfigured" message.**
You haven't created `api/_config.php` yet. Copy `_config.example.php` to
`_config.php` and fill in credentials.

**"forbidden_origin" on signup or login.**
The browser sent the request from a different host than the page itself.
This shouldn't happen if you visit the site directly. If you're testing
through a proxy or port-forwarded URL, just hit the page on the same
domain you're submitting to.

**Login always says "Email or password is incorrect" even with the right
credentials.**
The password column may have been truncated. Confirm the `users` table
has `password_hash VARCHAR(255)` — bcrypt hashes are 60 characters, so
255 is plenty.

## Phase 2 — Surveys API and the My Surveys dashboard

Phase 2 adds the ability to create, edit, and delete multiple surveys per
user. Each survey is stored in MySQL, with a unique shareable slug. The
Builder auto-saves changes to the database.

### What changed in this phase

New PHP files (upload to `/api/surveys/`):
- `list.php` — GET, returns all surveys owned by the current user
- `create.php` — POST, creates a new empty survey
- `get.php` — GET, fetches one survey
- `update.php` — PATCH, partial updates (title, description, settings, questions)
- `delete.php` — POST, deletes a survey and all its responses

Updated PHP files:
- `_helpers.php` — adds `generate_slug()`, `unique_survey_slug()`, and
  `default_survey_settings()` helpers used by the surveys API.

Updated front-end files:
- `app.html` — new "My Surveys" list view (default landing tab),
  per-survey context bar, debounced auto-save, save indicator in the
  header, user chip with logout button.

### Upload steps

1. Upload the new and changed files via SFTP, preserving structure:

   ```
   api/_helpers.php             (replaces existing)
   api/surveys/list.php         (new)
   api/surveys/create.php       (new)
   api/surveys/get.php          (new)
   api/surveys/update.php       (new)
   api/surveys/delete.php       (new)
   app.html                     (replaces existing)
   ```

2. **Do not** overwrite `api/_config.php`. It already has your credentials.

3. The Phase 1 schema already created the `surveys` and `responses`
   tables, so no SQL needs to run for Phase 2.

### Verifying Phase 2

1. Visit `https://relichecksurvey.com/app.html`. If you're not signed in,
   you'll be redirected to the login page.
2. Sign in. You should land on **My Surveys** with an empty state and a
   "+ New survey" button.
3. Click **+ New survey**. A new "Untitled survey" is created and you
   open the Builder.
4. Edit the title. Watch the header — it should briefly say "Saving…"
   then "Saved".
5. Add a few questions. Each change auto-saves after about 0.8 seconds.
6. Click **My Surveys** in the tab nav. The list should reflect the
   updated title, item count, and last-updated time.
7. Click **Open** on the row to re-open the same survey. Your data
   should be there.
8. (Optional sanity check.) In phpMyAdmin, open the `surveys` table.
   You should see your row with the JSON `questions` and `settings`
   columns populated.

### Known limits in Phase 2

- The public `/s/{slug}` survey-taking page does not exist yet
  (Phase 3). The "Copy link" button gives you the URL, but visiting
  it currently 404s.
- Responses are still stored in the visitor's browser via localStorage,
  keyed per survey. You can use the "Take Survey" tab to submit
  test responses and see them in Analytics, but they do not yet sync
  to the database.
- Generated sample responses also live in localStorage (per survey),
  for now.

## Phase 3 — Public survey-taking page

Phase 3 makes the share link actually work. Respondents go to
`https://relichecksurvey.com/s/{slug}`, fill out your survey without
logging in, and their answers land in the MySQL `responses` table.

### What changed in this phase

New files:
- `take.html` (root) — the public survey-taking page
- `.htaccess` (root) — rewrites `/s/{slug}` to `take.html?slug={slug}`
- `api/public/survey.php` — GET, returns a published survey by slug
- `api/public/submit.php` — POST, validates and stores a response

Updated front-end:
- `app.html` — adds Publish / Unpublish button in the Builder context bar,
  Draft / Published badge on every survey row in My Surveys, an active
  share link rendered only when published.

### Upload steps

Upload these via SFTP, preserving structure:

```
.htaccess                    (root, hidden file)
take.html                    (root)
api/public/survey.php        (new folder + file)
api/public/submit.php        (new file)
app.html                     (replaces existing)
```

No schema changes. Phase 1 already created the `responses` table.

Two notes about `.htaccess`:

- Some SFTP clients hide files starting with a dot. Turn on "show hidden
  files" or your client may skip uploading it.
- Ionos has Apache and `mod_rewrite` enabled by default. If you ever
  switch hosting plans, confirm the new host supports both.

### Verifying Phase 3

1. Sign into `app.html`. Open one of your existing surveys.
2. The Builder context bar at the top now shows a "Draft" badge next
   to your title and a **Publish** button on the right.
3. Click **Publish**. The badge flips to "Published" and the share
   link becomes a clickable URL.
4. Open that share link in a private browsing window
   (`https://relichecksurvey.com/s/{your-slug}`). The take page should
   render the survey.
5. Fill it in and click **Submit response**. You should see a "Thanks
   for your response" confirmation.
6. Back in your owner session, open phpMyAdmin → `responses` table.
   You should see a row with your answers stored as JSON, plus a
   hashed IP and the user-agent string.
7. Try the same link from a *different* browser without filling in a
   required question. The page should highlight the issue and refuse
   to submit.
8. In your owner session, click **Unpublish**. Hit the share link
   again from the private window. You should see "This survey is
   not open for responses yet."

## Phase 4 — Analytics and exports backed by MySQL

Phase 4 closes the loop. The Analytics tab and every export (CSV,
Excel, SPSS, PDF, Word) now read from MySQL. Whatever respondents
submit through `/s/{slug}` shows up in your dashboards and downloads
with no manual stitching.

### What changed in this phase

New PHP file:
- `api/responses/list.php` — GET `?survey_id=`, owner-only, returns
  every response for the survey ordered by submission time.

Updated front-end (`app.html`):
- Opening a survey from My Surveys now fetches its responses from the
  API in the background.
- The Analytics tab has a **↻ Refresh** button that re-fetches.
- The "Take Survey" tab now submits through the public API, so
  in-app preview submissions count as real responses (only when the
  survey is published).
- The "Generate sample responses" button has been removed.
- "Load demo" now seeds demo questions only, not fake responses.
- The Clear Responses button is gone — responses live in the database.

### Upload steps

Upload via SFTP, preserving structure:

```
api/responses/list.php       (new folder + file)
app.html                     (replaces existing)
```

No schema changes. The `responses` table from Phase 1 is what we read.

### Verifying Phase 4

1. Sign into `app.html` and open a published survey.
2. Switch to the **Analytics** tab. If you've already collected
   responses through the public link, they should appear in the
   reliability summary, item-total table, correlations, and
   per-item descriptives.
3. Click **↻ Refresh** to confirm the round-trip pulls fresh data.
4. Open the public share link in a private window and submit a
   new response. Back in Analytics, click **Refresh** — your new
   response should reflect immediately.
5. Click **Download data** and pick CSV, Excel, SPSS bundle, or
   SPSS .sav. Each file should contain your real responses.
6. Click **Download report** and pick PDF or Word. Both should
   reflect the current dataset.
7. (Optional cleanup.) If you have test responses you want to
   remove from a survey, open phpMyAdmin → `responses` → filter
   by `survey_id` and delete the rows.

### Limits and follow-ups (not in scope here)

- No pagination yet. Surveys with thousands of responses will
  pull them all in a single request. Fine for typical research
  surveys, slow above ~10k.
- No "delete a single response" UI. You can clean up via
  phpMyAdmin if needed.
- No password reset or email verification. Account creation
  works, but if a user forgets their password they'll need a
  manual reset. We can add SMTP-backed email flows in a
  follow-on phase.

That's the full ReliCheck stack: signup, multi-survey builder,
public take page, real-time analytics, and exports, all backed
by the Ionos MySQL database.

## Phase 5 — Password reset and account settings

Phase 5 closes the auth gap: users who forget their password can recover
their account via an emailed reset link, and signed-in users get an
Account modal to update their name, email, or password.

### What changed in this phase

New SQL migration:
- `db/schema_phase5.sql` — adds the `password_resets` table.

New PHP files:
- `api/_mailer.php` — small SMTP client (EHLO + STARTTLS + AUTH LOGIN).
- `api/auth/forgot.php` — POST email, generates a token, sends a reset email.
- `api/auth/reset.php` — POST token + new password, applies the change.
- `api/account/profile.php` — GET / PATCH the signed-in user's profile.
- `api/account/change_password.php` — POST current + new, verifies and updates.

New front-end:
- `reset.html` (root) — landing page for the email link.

Updated front-end:
- `login.html` — Forgot password? now POSTs to `/api/auth/forgot.php`.
- `app.html` — clicking the user chip in the header opens an Account
  settings modal (name, email, change password).

Updated config:
- `api/_config.example.php` — adds `site_url`, `smtp_host`, `smtp_port`,
  `smtp_user`, `smtp_pass`, `mail_from`, `mail_from_name`, and `ip_salt`.

### Upload steps

1. Apply the new migration. In phpMyAdmin, click `dbs15641829`,
   open the SQL tab, paste the contents of `db/schema_phase5.sql`,
   click Go. You should see `password_resets` appear in the sidebar.

2. Update `api/_config.php` on the server with your SMTP credentials.
   Add (or replace) these keys based on `_config.example.php`:

   ```
   'site_url'       => 'https://relichecksurvey.com',
   'smtp_host'      => 'smtp.ionos.com',
   'smtp_port'      => 587,
   'smtp_user'      => 'noreply@relichecksurvey.com',
   'smtp_pass'      => 'YOUR_MAILBOX_PASSWORD',
   'mail_from'      => 'noreply@relichecksurvey.com',
   'mail_from_name' => 'ReliCheck',
   ```

   The Ionos SMTP server is `smtp.ionos.com` (port 587 with STARTTLS).
   `smtp_user` and `smtp_pass` are the email mailbox you created in
   the Ionos control panel for the noreply address.

3. Upload via SFTP, preserving structure:

   ```
   api/_mailer.php              (new)
   api/auth/forgot.php          (new)
   api/auth/reset.php           (new)
   api/account/profile.php      (new folder + file)
   api/account/change_password.php (new file)
   reset.html                   (root, new)
   login.html                   (replaces existing)
   app.html                     (replaces existing)
   ```

### Verifying Phase 5

**Forgot password flow.**

1. Sign out of `app.html`.
2. On `login.html`, enter the email of an existing account and click
   **Forgot password?**. The banner should say "If an account exists
   for that email, a reset link has been sent."
3. Check your email. You should receive a "Reset your ReliCheck
   password" message with a link of the form
   `https://relichecksurvey.com/reset.html?token=<long hex string>`.
4. Click the link. Set a new password (at least 8 chars with a digit).
   Confirm it. You should land on `app.html` signed in.
5. (Optional) Try the link a second time — it should refuse with
   "This reset link has already been used."

If the email never arrives, check the Ionos error log for entries
beginning with `[relicheck]`. Common causes:

- SMTP credentials wrong → `SMTP wanted 235, got: 535`
- Wrong port or no STARTTLS support → `SMTP unexpected reply`
- The `From` address doesn't match a real mailbox you own → some
  receivers (Hotmail, Outlook) reject silently. Use the same address
  for `mail_from` and `smtp_user`.

**Account settings.**

1. Sign in to `app.html`. Click your name in the header. The Account
   modal should open.
2. Change your name and click **Save profile**. The modal should show
   "Profile saved." and the name in the header should update.
3. In the **Change password** section, enter your current password and
   a new one, then click **Change password**. You should see "Password
   updated." Sign out and sign back in with the new password to confirm.
4. Try entering the wrong current password — the modal should show
   "Your current password is incorrect."

### Known limits

- The mailer is plain SMTP only. If you ever need attachments,
  embedded images, or DKIM/SPF signing beyond what Ionos provides
  by default, swap `api/_mailer.php` for PHPMailer.
- `forgot.php` does not rate-limit. A determined attacker could
  flood your SMTP. If you start seeing abuse, add a per-IP rate
  limit (a few requests per hour per IP/email).
- Email change does not currently re-verify the new address.
  In strict-compliance settings you'd send a confirmation link
  to the new email and only switch on click.

## Phase 6 — Sign in with Google

Phase 6 adds Google as a sign-up and sign-in option. Visitors click
"Sign in with Google", pick their Google account in a popup, and land in
ReliCheck without ever typing a password. If they already have a
ReliCheck account with the same email, the Google identity is linked to
it; otherwise a new account is created.

Apple Sign-In is intentionally not built in this phase. The button is
not shown.

### Google Cloud setup (one-time)

1. Open Google Cloud Console: https://console.cloud.google.com
2. **Create or select a project.** Top bar → project dropdown →
   "New project" → name it "ReliCheck" (or anything you like) → Create.
3. **OAuth consent screen.**
   - Sidebar → APIs & Services → OAuth consent screen.
   - User type: **External**. Click Create.
   - App name: `ReliCheck`. User support email: your email.
   - Developer contact: your email. Save and Continue.
   - Scopes: leave default (email, profile, openid).
   - Test users: add your own Google email if you want to test before
     publishing.
   - Save. (You don't need to publish unless you want to allow any
     Google account; Test Users mode is enough for early use.)
4. **Create OAuth Client ID.**
   - Sidebar → APIs & Services → Credentials → "+ Create credentials" →
     OAuth client ID.
   - Application type: **Web application**.
   - Name: `ReliCheck Web`.
   - **Authorized JavaScript origins:** add `https://relichecksurvey.com`
   - **Authorized redirect URIs:** leave empty (the new Google Identity
     Services flow doesn't use a redirect URI).
   - Click Create.
5. Copy the **Client ID** that pops up. It looks like
   `123456789012-abcdef.apps.googleusercontent.com`. Note: the Client
   Secret is **not needed** for the Identity Services flow we use, but
   you can save it in case you ever switch to a server-side OAuth
   redirect flow.

### Update _config.php

Open `_config.php` on the Ionos server and set:

```
'google_client_id'     => 'YOUR_CLIENT_ID_HERE.apps.googleusercontent.com',
'google_client_secret' => '',
```

The `_config.example.php` already shows where these go. Save and re-upload.

### Apply the migration

In phpMyAdmin, click `dbs15641829`, open the SQL tab, paste the contents
of `db/schema_phase6.sql`, click Go. You should see `oauth_identities`
appear under your database in the sidebar.

### Upload the new and changed files

Via SFTP, preserving structure:

```
api/auth/google.php           (new)
api/auth/google_config.php    (new)
db/schema_phase6.sql          (new — for reference)
signup.html                   (replaces existing)
login.html                    (replaces existing)
```

### Verifying Phase 6

1. Visit `https://relichecksurvey.com/signup.html` in a private window.
2. The page should now show Google's official "Sign up with Google"
   button instead of the placeholder. (If you see the placeholder
   still, check `_config.php` has `google_client_id` set, then refresh.)
3. Click it. A Google popup appears. Pick your Google account.
4. The popup closes and you should land on `app.html` signed in.
5. In phpMyAdmin, open the `users` table and the `oauth_identities`
   table. You should see your new user and a matching `google` row
   linking the Google `sub` to that user.
6. Sign out, go to `login.html`, click the Google button — same
   Google account should sign you straight in.
7. (Optional sanity check.) On `signup.html` in another private
   window, sign up with email + password using the same email as your
   Google account. Then on `login.html`, click the Google button — it
   should link Google to that existing account, not create a duplicate.

### Limits and follow-ups

- The button appears only when `google_client_id` is set in `_config.php`.
  If you ever rotate the client ID, just update the config and re-upload;
  no code change needed.
- Google's One Tap auto-prompt is not enabled in this phase. Users must
  explicitly click the button.
- Apple Sign-In is left as a follow-up. When ready, the same
  `oauth_identities` table will store Apple identities under
  `provider='apple'`; only the verification endpoint and front-end
  button differ.

## Phase 7 — Upload datasets and analyze them

Phase 7 lets users skip the survey-collection step and upload their
existing data (CSV or Excel) for reliability and validity analysis.
A new "My Datasets" tab sits beside "My Surveys". Uploaded data flows
through the same Stats engine and the same export buttons, so anything
that worked on real survey responses works on uploaded data too.

### What changed in this phase

New SQL migration:
- `db/schema_phase7.sql` — adds the `datasets` table.

New PHP files:
- `api/datasets/list.php` — owner's datasets, metadata only.
- `api/datasets/create.php` — accepts the parsed rows + column mapping.
- `api/datasets/get.php` — full dataset including data rows.
- `api/datasets/update.php` — edits title, column mapping, settings.
- `api/datasets/delete.php` — removes a dataset.

Updated front-end:
- `app.html` — new Datasets tab, list view, upload + mapping wizard,
  dataset → survey transformation so Analytics + exports work as is.

### Upload steps

1. **Run the migration.** In phpMyAdmin: click `dbs15641829`, open SQL,
   paste the contents of `db/schema_phase7.sql`, click Go. A new
   `datasets` table appears.
2. **Upload via SFTP**, preserving folders:
   ```
   api/datasets/list.php       (new folder + file)
   api/datasets/create.php     (new file)
   api/datasets/get.php        (new file)
   api/datasets/update.php     (new file)
   api/datasets/delete.php     (new file)
   app.html                    (replaces existing)
   ```

No `_config.php` changes.

### Verifying Phase 7

1. Sign into `app.html`. You should see a new **My Datasets** tab in
   the header.
2. Click it. The list is empty. Click **+ Upload dataset**.
3. Pick a CSV or Excel file. The wizard steps through:
   - Step 1: pick the file, give it a title.
   - Step 2: review columns, set the Likert scale and anchors, mark
     each column as Likert / Categorical / Open-ended / Ignore, and
     toggle reverse-scoring on Likert items.
4. Click **Save dataset**. You'll land on the Analytics view, which
   computes Cronbach's α, KMO, item-total stats, and per-item
   descriptives just like a native survey.
5. From Analytics, the **Download data** and **Download report**
   menus work identically — CSV, Excel, SPSS bundle, native SPSS .sav,
   PDF, and Word, all on your uploaded data.

### Limits

- Files up to 10 MB, 50,000 rows, 200 columns.
- One Likert scale per dataset (5-point, 7-point, etc.). For mixed
  scales, split the data into separate datasets for now.
- Column types are: Likert, Categorical (single choice with
  auto-detected options), Open-ended, Ignore. Multi-select isn't
  supported in v1; if you have multi-select data, split each option
  into a separate binary column before uploading.
- Reverse-scoring is per-Likert-column.
- Datasets do not have a public take page; they're already-collected
  data.

## Phase 8 — Membership tiers (scaffolding)

Phase 8 adds the structure for paid plans without yet wiring up Stripe.
Every user has a `tier` column on the users row (default `free`).
Each tier defines hard limits (surveys, responses per survey, datasets,
rows per dataset, questions per survey) plus feature flags
(skip logic, team sharing, watermarked reports, etc.). The API enforces
limits server-side; the front-end shows a tier badge and routes the
"upgrade" CTA to a new public pricing page.

Pricing baked into the tier catalog (`api/_tiers.php`):

| Tier | Monthly | Annual |
|------|---------|--------|
| Free | $0 | $0 |
| Researcher | $19 | $190 |
| Professional | $49 | $490 |
| Business | $99 | $990 |

### What changed in this phase

New SQL migration:
- `db/schema_phase8.sql` — adds `tier`, `tier_expires_at`, `tier_changed_at`
  columns to `users`, plus a `tier_changes` audit table for future
  Stripe webhook events.

New PHP files:
- `api/_tiers.php` — tier catalog and limit-enforcement helpers.
- `api/account/tier.php` — GET endpoint returning the user's plan,
  limits, current usage, and the public catalog.

Updated PHP files (limits enforced):
- `api/surveys/create.php` — caps active survey count.
- `api/surveys/update.php` — caps questions per survey.
- `api/public/submit.php` — caps responses per survey using the owner's
  plan; respondents see a polite "no longer accepting responses" message
  when the cap is reached.
- `api/datasets/create.php` — caps dataset count and rows per dataset.

New front-end:
- `pricing.html` — public pricing page with the four tiers, monthly /
  annual toggle, full comparison table, and an FAQ. Linked from the
  app's tier badge and from the Account modal's Plan section.

Updated front-end (`app.html`):
- Tier badge in the header (color-coded).
- Plan section in the Account modal, showing current tier, surveys
  used, datasets used, and per-survey / per-dataset caps.
- Any 402 `plan_limit` error from the API now pops a confirm dialog
  inviting the user to open the pricing page in a new tab.

### Upload steps

1. **Run the migration.** In phpMyAdmin: select `dbs15641829`, paste
   the contents of `db/schema_phase8.sql`, click Go. New columns appear
   on the `users` table; new `tier_changes` table appears in the sidebar.
   - Note: Some MySQL 5.x versions don't support `IF NOT EXISTS` on
     ALTER TABLE columns. If you get an error, run the three
     ALTER lines in the file's comment one at a time, skipping any
     that report a "duplicate column" error.

2. **Upload via SFTP**, preserving folder structure:

   ```
   api/_tiers.php                 (new)
   api/account/tier.php           (new file)
   api/surveys/create.php         (updated — adds tier check)
   api/surveys/update.php         (updated — adds tier check)
   api/public/submit.php          (updated — adds tier check)
   api/datasets/create.php        (updated — adds tier check)
   pricing.html                   (root, new)
   app.html                       (replaces existing)
   ```

No `_config.php` changes.

### Manually upgrading a user (until Stripe is wired up)

While billing is not yet automated, you can promote any user to a paid
tier by running this in phpMyAdmin's SQL tab:

```sql
USE dbs15641829;

-- Set a user to Researcher for one year:
UPDATE users
   SET tier = 'researcher',
       tier_expires_at = DATE_ADD(NOW(), INTERVAL 1 YEAR),
       tier_changed_at = NOW()
 WHERE email = 'user@example.com';

-- Or to Professional / Business:
-- tier = 'professional'  / 'business'

-- To revoke and send the user back to Free:
UPDATE users
   SET tier = 'free', tier_expires_at = NULL, tier_changed_at = NOW()
 WHERE email = 'user@example.com';
```

Tiers expire automatically: when `tier_expires_at` is in the past, the
API serves limits as if the user is Free until you renew the row.

### Verifying Phase 8

1. Sign in to `app.html`. The header should show a colored tier badge
   reading "Free" next to your name.
2. Click the badge or your name. The Account modal opens; the **Plan**
   section shows your current usage (e.g. "Surveys used: 1 of 1").
3. Click **+ New survey** to try to exceed the Free survey limit. The
   API should return a 402 and the upgrade prompt should appear.
4. Click **View plans** in the modal (or the badge → "View plans") to
   open `pricing.html`. The four tiers and the comparison table render.
5. In phpMyAdmin, run the SQL above to promote your account to
   `researcher`. Reload `app.html`. The badge should now read
   "Researcher" in blue, and the survey limit should be lifted.

### Coming in Phase 9

Stripe Checkout, billing portal, and webhook handling. See the next section.

## Phase 9 — Stripe Checkout, billing portal, and webhooks

Phase 9 wires real billing in. Visitors click a plan on the pricing page,
go through Stripe Checkout, and on return their `users.tier` is updated
and the subscription is tracked. The Account modal gets a "Manage
subscription" button that opens Stripe's customer portal so users can
upgrade, downgrade, change payment method, or cancel without involving
you. A webhook keeps everything in sync if Stripe events fire later
(renewals, payment failures, cancellations).

### Stripe Dashboard setup (one-time)

This is the part you do in Stripe. Take it slowly; there's no rush.

**1. Activate your account if you haven't already.**

Go to https://dashboard.stripe.com. If your account is in "test mode,"
that's the right starting place — we'll do everything in test mode
first, then flip to live when it's working.

**2. Create three Products with two Prices each.**

Sidebar → **Products** → **Add product**. Repeat three times:

- **Researcher**
  - Pricing: $19.00 USD, recurring monthly. Save and copy the Price ID
    (looks like `price_1PabcXYZ…`). Then **+ Add another price** to the
    same product: $190.00 USD recurring yearly. Save and copy that Price ID too.
- **Professional** — $49 monthly, $490 yearly.
- **Business** — $99 monthly, $990 yearly.

You'll end up with 6 Price IDs total. Save them somewhere private
for the next step.

**3. Get your API keys.**

Sidebar → **Developers** → **API keys**. Copy the two test-mode keys:

- Publishable key: `pk_test_…`
- Secret key: `sk_test_…` (click **Reveal test key** to see it)

When you're ready to go live, you'll come back and copy the live keys
(`pk_live_…` and `sk_live_…`) instead.

**4. Add a webhook endpoint.**

Sidebar → **Developers** → **Webhooks** → **Add endpoint**.

- Endpoint URL: `https://relichecksurvey.com/api/billing/webhook.php`
- API version: leave default
- Events to send: click "Select events" and tick:
  - `checkout.session.completed`
  - `customer.subscription.created`
  - `customer.subscription.updated`
  - `customer.subscription.deleted`
  - `invoice.payment_failed`

After you save, click into the endpoint → **Signing secret** → **Reveal**.
Copy the value (starts with `whsec_…`).

### Update `_config.php`

Open `_config.php` on Ionos in a code editor and add (or replace) the
Stripe block at the bottom, before the closing `];`:

```php
'stripe_secret_key'      => 'sk_test_REPLACE_ME',
'stripe_publishable_key' => 'pk_test_REPLACE_ME',
'stripe_webhook_secret'  => 'whsec_REPLACE_ME',

'stripe_price_researcher_monthly'   => 'price_REPLACE',
'stripe_price_researcher_annual'    => 'price_REPLACE',
'stripe_price_professional_monthly' => 'price_REPLACE',
'stripe_price_professional_annual'  => 'price_REPLACE',
'stripe_price_business_monthly'     => 'price_REPLACE',
'stripe_price_business_annual'      => 'price_REPLACE',
```

Save and re-upload. The `_config.example.php` file already has these
keys laid out as placeholders.

### Apply the migration

In phpMyAdmin, click `dbs15641829`, open SQL, paste the contents of
`db/schema_phase9.sql`, click Go. Three new tables appear:
`stripe_customers`, `subscriptions`, `stripe_events`.

### Upload the files

Via SFTP, preserving folders:

```
api/_stripe.php                 (new)
api/billing/checkout.php        (new folder + file)
api/billing/return.php          (new file)
api/billing/portal.php          (new file)
api/billing/webhook.php         (new file)
pricing.html                    (replaces existing)
app.html                        (replaces existing)
```

### Verifying Phase 9 (test mode)

Stripe test cards: any number from
https://stripe.com/docs/testing#cards. The classic one is
**4242 4242 4242 4242** with any future expiration, any CVC, and any zip.

1. Sign in to `app.html` as a Free user.
2. Open `pricing.html`. Click **Choose Researcher** (or any tier). You
   should land on Stripe's hosted Checkout page.
3. Pay with a test card. Stripe redirects you back to `app.html?billing=ok`.
4. The header tier badge should now show "Researcher" in blue, and the
   Account modal's Plan section reflects the new caps. The "Manage
   subscription" button now appears.
5. Click **Manage subscription** in the Account modal. It opens the
   Stripe Customer Portal. Try canceling the subscription from there.
6. Wait a few seconds (Stripe sends a `customer.subscription.deleted`
   webhook). Reload `app.html`. You should drop back to Free at the
   end of the current period (or immediately if you picked "Cancel
   immediately" in the portal).
7. In phpMyAdmin: `users` should have your tier updated, `subscriptions`
   should have a row, and `stripe_events` should have entries logged for
   each webhook delivery.

### Going live

When you're confident the test-mode flow works:

1. In the Stripe Dashboard, switch from **Test mode** to **Live mode**
   (toggle in the top right).
2. Re-create the three products with the same six prices in live mode.
3. Re-create the webhook endpoint in live mode (it'll get a new
   `whsec_…` secret).
4. Update `_config.php` with the live keys (`sk_live_…`, `pk_live_…`,
   the new webhook secret, and the live Price IDs).
5. Re-upload `_config.php`.
6. Run a real card through Checkout to confirm.

### Limits and follow-ups

- The webhook handler only processes the five event types listed above.
  Stripe re-sends if the endpoint returns a non-2xx, so transient errors
  recover automatically.
- Refunds aren't actively handled yet. A refund event doesn't change
  the user's tier; if you fully refund, also cancel the subscription
  in Stripe so the next subscription-deleted webhook drops the user
  to Free.
- Tax collection uses Stripe's defaults. Turn on Stripe Tax in the
  Dashboard if you need to charge sales tax in specific regions.
- Proration on upgrades/downgrades is handled by Stripe automatically
  via the customer portal.

## Phase 10 — Per-question Likert, Responses viewer, Templates, Skip logic

This phase adds four research-grade features in one upload.

### What changed in this phase

**Per-question Likert scale.** Each Likert question can override the
survey-level points/anchors. The Builder shows a points dropdown
(3, 4, 5, 6, 7, 9, 11) plus low/high anchor inputs on every Likert
question. The take page, public take page, exports, and analytics all
respect the per-question values; they fall back to the survey-level
default when a question doesn't override.

**Per-response delete + Responses tab.** A new "Responses" tab in
`app.html` lists each submission with timestamp. Click a row to expand
into a question-by-question detail view. Each row has a Delete button
for cleaning up test responses or anomalies without phpMyAdmin.

**Survey duplication + research-friendly templates.** Each My Surveys
row gets a Duplicate button that clones the survey (questions,
settings, anchors) into a new draft. The "+ New survey" button opens
a picker letting the user start blank or pick from three starter
templates: Workplace Engagement, Burnout Indicators, Perceived Social
Support. Templates are paraphrased generic items that researchers
should swap for validated instruments before publishing.

**Question branching / skip logic.** Each question can be set to
"Show only if [previous Likert/single question] [equals|does not equal]
[value]". The Builder editor for skip logic is gated to Professional
and Business plans (Free/Researcher see an upgrade hint if a rule
already exists from a higher-tier period). The take page (in-app and
public) evaluates rules in real time and hides questions that don't
qualify; the public submit endpoint re-validates server-side and
skips storing answers for hidden questions.

### Upload steps

New PHP files:
- `api/responses/delete.php`
- `api/surveys/templates.php`
- `api/surveys/duplicate.php`

Updated PHP files:
- `api/surveys/update.php` (accepts per-question Likert + showIf)
- `api/public/submit.php` (per-question Likert validation + skip-logic
  evaluation)

Updated front-end:
- `app.html` (per-question Likert UI, Responses tab + viewer, New
  Survey picker, Duplicate action, Skip logic editor, skip-aware
  in-app preview)
- `take.html` (per-question Likert rendering + skip logic on the
  public take page)

Schema is unchanged. No migration needed.

### Verifying Phase 10

1. **Per-question Likert.** Open any survey. Add a Likert question.
   Inside the question card, change Points to 7. Anchors to whatever.
   Add a second Likert question with Points 5. Switch to Take Survey
   in the in-app preview — each question renders with its own scale.
   Submit; in Responses, both answers reflect the right scale.
2. **Responses viewer.** Submit a few responses (or use the public
   link). Click the **Responses** tab. Click a row to expand. Click
   Delete on one response and confirm; the row disappears and the
   header response count drops.
3. **Templates + duplication.** Click **+ New survey** in My Surveys;
   the picker opens. Pick "Workplace engagement (starter)". A new
   survey is created with the seeded questions and you land in the
   Builder. Back in My Surveys, click **Duplicate** on any survey;
   a "Copy of …" appears at the top.
4. **Skip logic.** While on the Professional or Business tier
   (manually upgrade via SQL or via Stripe), edit a survey with at
   least two questions. On the second Likert question, check "Show
   only if…", pick the first question as the trigger, set "equals"
   and pick a value. In Take Survey preview, the second question
   only appears once you select that value on the first question.
   Submit and verify the Responses viewer shows only the visible
   answers; hidden ones are absent.

### Limits and follow-ups

- Skip logic supports a single condition per question (one trigger,
  one comparison). AND / OR chains and multi-select triggers can be
  added later.
- Survey templates are baked into PHP (`api/surveys/templates.php`).
  Adding new ones is one PR away; longer term, a templates table
  would let admins manage them through the UI.
- The Responses viewer paginates only by browser scrolling. Surveys
  with thousands of responses will load all at once. Add server-side
  pagination if this gets slow.

## Phase 11 — Production hardening

This phase adds three things you want before sharing the site widely:
auth-endpoint rate limiting, a branded 404 page, and a custom
thank-you message per survey.

### What changed in this phase

New SQL migration:
- `db/schema_phase11.sql` — adds the `rate_limits` table.

New PHP files:
- `api/_ratelimit.php` — small helper that throttles attempts per
  IP / email / user. Returns HTTP 429 with a Retry-After header when
  the cap is exceeded.

Updated PHP (rate limits added):
- `api/auth/signup.php` — 8 signups per IP per hour.
- `api/auth/login.php` — 10 attempts per email per 15 min, 30 per IP
  per 15 min. Both apply, so a single IP sweeping many emails still
  gets caught.
- `api/auth/forgot.php` — 5 reset emails per email per hour, 20 per
  IP per hour.
- `api/auth/reset.php` — 20 reset attempts per IP per hour to slow
  token brute-force.
- `api/account/change_password.php` — 5 attempts per user per 15 min.
- `api/auth/google.php` — 30 sign-ins per IP per 15 min.
- `api/surveys/update.php` — sanitizes a new `thankYou` setting
  (up to 1,000 characters).
- `api/public/survey.php` — returns the `thankYou` setting alongside
  the existing Likert config.

New front-end:
- `404.html` — branded 404 with "Go home" / "Log in" buttons.

Updated front-end:
- Root `.htaccess` — `ErrorDocument 404 /404.html`.
- `app.html` — Survey Details card now has a **Thank-you message**
  textarea, gated to Researcher and higher (Free shows an upgrade
  hint).
- `take.html` — when the survey owner has set a thank-you message,
  the public take page shows it instead of the generic "Thanks for
  your response" copy.

### Upload steps

1. **Run the migration.** In phpMyAdmin (`dbs15641829`), open SQL,
   paste the contents of `db/schema_phase11.sql`, click Go. A new
   `rate_limits` table appears.
2. **Upload via SFTP**, preserving folders:
   ```
   api/_ratelimit.php                   (new)
   api/auth/signup.php                  (overwrite — adds rate limit)
   api/auth/login.php                   (overwrite)
   api/auth/forgot.php                  (overwrite)
   api/auth/reset.php                   (overwrite)
   api/auth/google.php                  (overwrite)
   api/account/change_password.php      (overwrite)
   api/surveys/update.php               (overwrite — adds thankYou)
   api/public/survey.php                (overwrite — returns thankYou)
   404.html                             (root, new)
   .htaccess                            (root, overwrite)
   app.html                             (overwrite)
   take.html                            (overwrite)
   ```

No `_config.php` changes.

### Verifying Phase 11

**Rate limits.** From a private window, hit `https://relichecksurvey.com/login.html`
and submit wrong-password 11 times in a row with the same email.
On the 11th attempt the API should return 429 with the message
"Too many attempts. Please wait a few minutes and try again."
Open the `rate_limits` table in phpMyAdmin to see the counter. Wait
15 minutes (or set count to 0 manually) to retry.

**404 page.** Visit `https://relichecksurvey.com/this-page-does-not-exist`.
You should land on the branded 404 page with a "Go home" button,
not Ionos's default error page. The 404 page also pops up if a
survey link slug is wrong (tries `/s/foo` for a non-existent slug).

**Custom thank-you.** Sign in as a Researcher-or-higher user. Open
a published survey. In the Survey Details card, fill in the
**Thank-you message** field (e.g., "Thanks! Results land in your
inbox within a week."). It auto-saves. Open the public link in a
private window, submit a response. The thank-you screen should now
show your custom message instead of the generic one. As a Free
user, the textarea is disabled with an upgrade hint.

### Limits and follow-ups

- Rate-limit windows are sliding via the `first_at` timestamp on
  each row. A user near the cap who waits past the window resets
  the counter on their next attempt. Old rows are auto-purged after
  24 hours.
- The 429 response is a hard block; we don't currently issue CAPTCHA
  challenges. If abuse becomes a real problem, hCaptcha or Cloudflare
  Turnstile is the standard next step.
- The thank-you message is plain text only. If you ever want
  formatting or links, render it through a small Markdown converter
  (or extend the textarea to a richer editor) before swapping
  `escapeHtml(custom)` for sanitized HTML in `take.html`.

## Phase 12 — Google Sheets and Google Drive integration

This phase adds two integrations to the existing analytics screen:

- **Send to Google Sheets.** Creates a brand new Google Sheet in the
  signed-in user's Drive containing every response for the current
  survey, one row per response, one column per question.
- **Save to Google Drive.** Uploads a CSV or JSON file of the survey's
  responses to the root of the user's My Drive.

Both flows share a single OAuth connection that the user grants once,
stored server-side. Refresh tokens are used to keep the connection
alive without prompting again.

Google Sign-In (from Phase 6) was using only the basic profile scope
and never needed a client secret. These features need a real OAuth
authorization-code flow with a refresh token, so this phase is the
first time the client secret has to be filled in.

### What changed in this phase

New SQL migration:
- `db/schema_phase12.sql` — adds the `google_oauth_tokens` table
  (one row per user, holds access token + refresh token + scopes
  + expiry).

New PHP files:
- `api/_google.php` — token loader, transparent refresh, and a thin
  client for calling Sheets and Drive APIs (no Composer needed).
- `api/google/connect.php` — starts the OAuth flow (302 redirect to
  Google with the right scopes).
- `api/google/callback.php` — receives the auth code, exchanges for
  tokens, stores them, redirects back to `app.html?google=connected`.
- `api/google/status.php` — returns `{ enabled, connected, google_email }`
  for the UI.
- `api/google/disconnect.php` — revokes the token at Google and
  deletes the local row.
- `api/google/sheets/export.php` — creates a new spreadsheet with
  the survey's responses.
- `api/google/drive/upload.php` — uploads a CSV or JSON of the
  responses to Drive.

Updated front-end (`app.html`):
- New "Send to Google Sheets" item under the **Download data** menu.
- New "Save CSV to Google Drive" and "Save JSON to Google Drive"
  items under the **Download report** menu.
- A small `?google=connected` toast handler that fires after the
  OAuth callback bounces the user back to the app.

### Google Cloud Console setup (one-time, on your account)

1. Open **APIs & Services → Library** and enable both:
   - **Google Sheets API**
   - **Google Drive API**

2. Open **APIs & Services → OAuth consent screen → Scopes** and add:
   - `https://www.googleapis.com/auth/drive.file`
     (only files the user creates through ReliCheck)
   - `https://www.googleapis.com/auth/spreadsheets`

   Save. If your consent screen is in Testing, add yourself as a
   test user before trying the flow.

3. Open **APIs & Services → Credentials → [your OAuth 2.0 Client ID]**
   and add this entry under **Authorized redirect URIs**:

   ```
   https://relichecksurvey.com/api/google/callback.php
   ```

   The Authorized JavaScript origins from Phase 6 stay the same.

4. From the same Credentials page, copy the **Client Secret** (next
   to the Client ID). You'll paste it into `_config.php` below.

### Upload steps

1. Apply the new migration. In phpMyAdmin, click `dbs15641829`,
   open the SQL tab, paste the contents of `db/schema_phase12.sql`,
   and click Go. You should see `google_oauth_tokens` appear in the
   sidebar.

2. Update `api/_config.php` on the server. Find the existing line:

   ```
   'google_client_secret' => '',
   ```

   and paste the secret you copied from the Credentials page between
   the quotes. Save.

3. Upload via SFTP, preserving structure:

   ```
   api/_google.php                       (new)
   api/google/connect.php                (new folder + file)
   api/google/callback.php               (new)
   api/google/status.php                 (new)
   api/google/disconnect.php             (new)
   api/google/sheets/export.php          (new folder + file)
   api/google/drive/upload.php           (new folder + file)
   app.html                              (overwrite)
   ```

### Verifying Phase 12

1. Sign in to `https://relichecksurvey.com/app.html`.
2. Open any survey that already has a few responses, and switch to
   the **Analytics** tab.
3. Click **Download data → Send to Google Sheets**. The first time,
   you'll see a confirm dialog asking you to connect Google. Click
   OK, complete the Google consent, and you'll land back on
   `app.html` with a "Google connected" toast.
4. Click **Download data → Send to Google Sheets** again. A new
   spreadsheet opens in a new tab, named like
   `<survey title> · ReliCheck export · YYYY-MM-DD`, with one row
   per response.
5. Click **Download report → Save CSV to Google Drive**. A toast
   confirms the upload and the file opens in Drive.
6. (Optional disconnect.) POST to `/api/google/disconnect.php`
   from the browser console:

   ```js
   fetch('/api/google/disconnect.php', { method: 'POST', credentials: 'same-origin' })
     .then(r => r.json()).then(console.log)
   ```

   The next Sheets/Drive action will prompt to reconnect.

### Limits and follow-ups

- The Drive upload always lands in the root of My Drive. A
  follow-up could let users pick a destination folder via the
  Google Picker (an additional script load on the front end).
- The Sheets export writes one tab (`Responses`). A follow-up
  could add a second `Summary` tab with α, item-rest correlations,
  and per-item descriptives.
- "Save report to Drive" currently uploads CSV or JSON, not PDF or
  Word. The PDF and Word generators are client-side; a follow-up
  could either generate them in the browser and `POST` the bytes
  to a new "raw upload" endpoint, or move the report generation
  server-side.
- Refresh tokens issued by Google can be revoked if the user
  removes ReliCheck from their Google account permissions
  (myaccount.google.com → Security → Third-party apps). The
  next API call will then return `google_refresh_required`,
  which the UI surfaces as a "reconnect" prompt.

## Phase 13 — AI survey generation

This phase adds a "Generate with AI" button to the survey builder.
The user describes what they want to measure, the AI drafts a clean
survey (title, description, scale anchors, and a list of items with
appropriate types), and the user previews the draft before pushing
it into the builder.

The model is called server-side through the Anthropic Messages API.
Nothing is stored on Anthropic's side beyond the request itself (per
their default API policy at the time of writing), and the generated
survey lives only in the user's own database row once they accept it.

### What changed in this phase

New PHP files:
- `api/_ai.php` — thin wrapper around Anthropic's Messages API,
  with helpers for completing a prompt and extracting JSON from
  the response.
- `api/ai/generate-survey.php` — POST endpoint that takes a goal
  description, calls the model, sanitizes the result against the
  app schema, and returns a fully formed survey object. Capped at
  10 generations per user per hour via the existing rate limiter.

Updated PHP:
- `api/_config.example.php` — adds `anthropic_api_key` and
  `anthropic_model`. Both blank by default; the AI button no-ops
  with a clear error if the key is missing.

Updated front-end (`app.html`):
- New "✨ Generate with AI" button in the Survey Details card,
  next to "Load demo questions."
- New AI generation modal with a goal textarea, audience field,
  item-count and Likert-scale selectors, draft preview, and a
  "Use this draft" action that replaces the current questions.

### Anthropic setup (one-time)

1. Sign in at [console.anthropic.com](https://console.anthropic.com)
   (or sign up if you don't have an account; pay-as-you-go).
2. Open **Settings → API Keys** and click **Create Key**. Name it
   something like `relicheck-prod`. Copy the key. You only see it
   once.
3. Optionally add a small spend cap under **Settings → Plans &
   Billing → Limits** so an unexpected loop can't run up the bill.

### Upload steps

1. Update `api/_config.php` on the server. Add (or replace) these
   keys:

   ```
   'anthropic_api_key' => 'sk-ant-...',
   'anthropic_model'   => 'claude-sonnet-4-6',
   ```

   Leave `anthropic_api_key` blank to disable the feature on that
   server. The button still renders, but the endpoint will return
   a clean "AI features are not configured" error.

2. Upload via SFTP, preserving structure:

   ```
   api/_ai.php                          (new)
   api/ai/generate-survey.php           (new folder + file)
   app.html                             (overwrite)
   ```

   No SQL migration. Generated surveys are saved through the
   existing `surveys/update.php` endpoint when the user clicks
   "Use this draft."

### Verifying Phase 13

1. Sign in to `app.html` and create a new blank survey (or open
   an empty existing one).
2. Click **✨ Generate with AI** in the Survey Details card.
3. In the modal, type a goal: e.g., "Employee engagement on a
   hybrid team after our return-to-office policy change."
4. Optionally add audience, change item count or Likert scale.
   Click **Generate**. The button shows "Generating…" while the
   model responds (typically 4-12 seconds).
5. The preview pane shows the title, description, and one row
   per question with type badges. The italic line at the bottom
   is the model's rationale for the construct grouping.
6. Click **Use this draft**. The questions land in the builder.
   Edit anything you want, then **Save** and **Publish** as usual.

### Cost and rate limits

- Generation cost is roughly $0.01–$0.05 per survey on
  `claude-sonnet-4-6`. Switch to `claude-haiku-4-5-20251001` in
  `_config.php` for ~10x cheaper at slightly lower quality.
- Per-user rate limit is 10 generations per hour, enforced by
  the existing `_ratelimit.php`. Bump the limit in
  `api/ai/generate-survey.php` if you want to be more generous on
  paid tiers.

### Limits and follow-ups

- The model does not see existing user data. Generation is
  zero-shot from the prompt + schema. A future enhancement could
  feed it 5-10 examples from the user's own past surveys for
  consistency with their voice.
- No translation step yet. A "Translate this survey" action that
  reuses `_ai.php` is a natural Phase 13.5.
- No per-tier caps yet. Free, Researcher, Professional, and
  Business all share the same hourly cap. Worth tightening on
  Free if usage takes off.

## Phase 14 — Promotional codes

This phase adds a small admin tool for issuing promotional codes
that grant a tier (typically Researcher) for free, either for a
fixed number of days or permanently. Recipients redeem from inside
Account settings.

### What changed in this phase

New SQL migration:
- `db/schema_phase14.sql` — adds `promo_codes` (the codes themselves)
  and `promo_redemptions` (one row per (code, user) so we can prevent
  re-use and audit who redeemed what).

New PHP files:
- `api/_admin.php` — small admin gate keyed off the `admin_emails`
  list in `_config.php`. `require_admin()` returns the user or 403s.
- `api/promo/create.php` — admin: create a new code.
- `api/promo/list.php` — admin: list all codes with current usage.
- `api/promo/toggle.php` — admin: enable or disable a code without
  deleting it.
- `api/promo/redeem.php` — any user: redeem a code, upgrades tier
  via the existing `set_user_tier()` helper.

Updated config:
- `api/_config.example.php` — adds `admin_emails` array. Default
  contains `don.eastonbrooks@gmail.com`. Add or remove emails to
  control who sees the admin section.

Updated front-end (`app.html`):
- New "Redeem promotional code" section in the Account modal,
  visible to every signed-in user. Apply codes here.
- New "Manage promotional codes" section in the Account modal,
  visible only to admin emails. Create codes (with tier, duration,
  optional max-uses cap, optional code-level expiry, and notes),
  see usage counts, and toggle codes on or off.

### Behavior rules

- Each user can redeem each code at most once.
- A code is rejected if it's disabled, past its `expires_at`, or
  has already hit its `max_uses` cap.
- A redemption is rejected if the user is already on a higher tier.
- If the user is already on the same tier, the new duration stacks
  onto the current expiry instead of replacing it.
- Permanent codes (`duration_days = NULL`) set the user's tier with
  no expiry.

### Upload steps

1. Apply the new migration. In phpMyAdmin, click `dbs15641829`,
   open the SQL tab, paste the contents of `db/schema_phase14.sql`,
   and click Go. Two new tables appear: `promo_codes` and
   `promo_redemptions`.

2. Update `api/_config.php` on the server. Add the `admin_emails`
   key, copying from `_config.example.php`:

   ```
   'admin_emails' => [
       'don.eastonbrooks@gmail.com',
   ],
   ```

   You can add additional admin emails to this array later.

3. Upload via SFTP, preserving structure:

   ```
   api/_admin.php                      (new)
   api/promo/create.php                (new folder + file)
   api/promo/list.php                  (new)
   api/promo/toggle.php                (new)
   api/promo/redeem.php                (new)
   app.html                            (overwrite)
   ```

### Verifying Phase 14

1. Sign in to `app.html` as your admin email.
2. Click your avatar in the top right to open Account settings.
3. Scroll down. You should see two new sections: "Redeem
   promotional code" (always visible) and "Manage promotional
   codes" (only visible because you are on the admin list).
4. Under Manage promotional codes, create a code like
   `RESEARCH-90` with tier = Researcher, duration = 90 days,
   max uses = 50, code expires (optional). Click Create code.
5. The new code shows up in the table below with a green dot
   and 0 / 50 uses.
6. Sign in as a different user (or open an incognito window and
   sign up a new account). Open Account settings, scroll to
   Redeem promotional code, paste `RESEARCH-90`, click Apply.
7. You should see a green success message and the Plan section
   should now read "Researcher" with the expiry date 90 days
   from today.
8. Back as the admin, the table should show 1 / 50 uses.

### Sharing codes externally

A common pattern: create a code with a generous max-uses cap, a
6-12 month code expiry, and post it in a campaign (newsletter,
conference flyer, partner announcement). Recipients who already
have an account redeem from Account settings; new visitors sign
up first, then redeem.

There is currently no public landing page for the redemption flow.
A natural follow-up would be to accept `?promo=CODE` in the URL on
the signup page so the code auto-applies once the new account is
created.

### Limits and follow-ups

- Codes are case-insensitive but normalized to uppercase server
  side.
- The redemption endpoint is rate-limited to 20 attempts per user
  per hour, so a typo or two won't lock anyone out, but a brute
  force won't get far either.
- No "delete code" yet, only disable. Old codes stay in the table
  for the audit trail. If you really need to delete one, do it
  via phpMyAdmin.
- No bulk import. If you need 1,000 codes, generate them via a
  loop against the create endpoint, or insert directly via SQL.
