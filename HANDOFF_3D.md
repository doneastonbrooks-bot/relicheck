# Handoff — Phase 3D: Response Submission + Storage

_Date: 2026-05-29._

> **Status: COMPLETE + FULLY LIVE-VERIFIED in production.**
> Every step confirmed end-to-end via Chrome against project #23, every result
> observed: a real submission with an answer returned POST
> `/api/public/submit-dev.php` → **200 `{ok:true}`**, the thank-you screen
> rendered, and the Deploy count read **"1 response collected so far."** Project
> #23 is left CLOSED (`responses_open:false`) with exactly 1 stored response.
> The respondent form is single-page (all questions, one "Submit response"
> button).
>
> Note 1: a BLANK submission of this all-optional survey returns 422
> `empty_submission` "No answers were submitted." (observed). By design
> submit-dev.php refuses to store a zero-answer session — flagged as an open
> product question (the message could confuse if skipping all is meant to be
> allowed). Not changed.
>
> Note 2 (tooling): driving the public form via computer-vision clicks was
> unreliable here (element refs went stale across re-renders). The submission
> was ultimately executed with the Chrome `javascript_tool`, which confirmed
> the 200 deterministically.
>
> **Open product question (flagged, not changed):** a survey whose questions
> are ALL Optional currently cannot be submitted blank — `submit-dev.php`
> returns 422 `empty_submission` "No answers were submitted." That avoids
> storing empty sessions, but if skipping everything is meant to be allowed the
> message is confusing. Left as-is for the user to decide.

## Goal
When a respondent submits the public Survey Development System survey, save the
response to the database. Storage only — no RSSI, no analysis.

## Deploy model (confirmed this session)
The `api/` PHP files and `take.html` ARE served live from this working
directory (Dropbox-synced to Ionos). Brand-new `submit-dev.php` answered live
requests immediately with its own custom messages, so no separate deploy step
is needed. (Earlier handoffs' "only develop.php deploys" note is misleading for
api/ files — they go live on save too.)

## What landed

**New file — `api/public/submit-dev.php`** (public, no login)
- POST `{ link_key, answers }`. `answers` keys are `i{itemId}` (matches the
  question ids `survey-dev.php` emits and `take.html` uses).
- Resolves `link_key` → project via `deployment_settings` JSON.
- **Gate:** rejects with HTTP 403 `{closed:true}` when `responses_open` is not
  true; mirrors the read gate in `survey-dev.php`.
- Confirms the project is still `status='published'`.
- Loads `survey_items`, skips non-input types (Section Text / Instructions /
  Page Break / Thank-you Message), re-validates required answers server-side
  (HTTP 422 `incomplete` if a required answer is missing).
- Normalises values (bool→'1'/'0', arrays→JSON, trims, 5000-char cap; blank→
  null and skipped for optional items).
- Stores atomically in a transaction: one `survey_dev_response_sessions` row +
  one `survey_dev_answers` row per answered item.
- Returns only `{ok:true}` — never exposes builder/owner data.
- Does NOT call `check_origin()` — deliberately, exactly like the production
  `api/public/submit.php`, because respondents arrive cross-referer and the
  endpoint is anonymous with no credentials (no CSRF surface).

**Schema — two new additive tables** (in BOTH `api/dev/_dev_common.php`
`sds_ensure_schema()` and `db/schema_survey_dev_system.sql`):
- `survey_dev_response_sessions` — one row per completed submission:
  `project_id`, `link_key`, `submitted_at`, `ip_hash` (salted, via
  `ip_hash()` — no raw IP, no respondent identity), `user_agent` (truncated).
  FK to `survey_projects` ON DELETE CASCADE.
- `survey_dev_answers` — one row per answer: `session_id` (FK CASCADE),
  `project_id` (FK CASCADE), `item_id` (NO FK — survives item edits/removal),
  `item_label` (snapshot of the prompt, 500 chars), `answer_value` (MEDIUMTEXT).
- Both tables are also created defensively at the top of `submit-dev.php` so the
  public endpoint works even if no authenticated `/api/dev/*` call has run
  `sds_ensure_schema()`. Fully additive; never touches production
  `surveys`/`responses`.

**`_dev_common.php` → `sds_project_payload()`** now also returns
`'responses'` = `COUNT(*)` of `survey_dev_response_sessions` for the project.
(`project-load.php` already calls `sds_ensure_schema()` before the payload, so
the count query never hits a missing table.)

**`take.html` → `devSubmit()`** is now `async`: client-side required-field
check (unchanged), strips skip-logic-hidden answers, serializes object answers
to JSON, POSTs to `/api/public/submit-dev.php` with `{ link_key: getSlug(),
answers }`. On success → `renderThanks()`. On `{closed:true}` → `renderClosed()`.
On other failure → clear error banner + re-enable the button to retry.

**`develop.php`** — `state.responseCount` added; restored from
`payload.responses` in `DB.hydrate()`; deploy-screen link banner now shows
"N responses collected so far."

## Out of scope (explicitly NOT done, per instructions)
No RSSI, no Studios, no AI Deep Check, no analysis, no reading/exporting the
collected answers beyond the bare count. The `retrieve()` screen still shows
MOCK data — wiring real response viewing is a later phase.

## Live verification — DONE this session (no prod writes)
Against project #23 "People at Work", link_key `hhx4uura` (`responses_open:false`):

| Check | Expected | Live result |
|---|---|---|
| Closed survey rejects submit | 403 `{closed:true}` | ✓ 403 `not_open` `closed:true` |
| GET (wrong method) | 405 | ✓ 405 `method_not_allowed` |
| Malformed link_key | 400 | ✓ 400 `bad_key` |
| Valid-format unknown key | 404 (proves tables/lookup run w/o SQL error) | ✓ 404 `not_found` |
| Missing answers | 400 | ✓ 400 `bad_input` |

`php -l` clean on `submit-dev.php`, `_dev_common.php`, `develop.php`.

## Live verification — happy path DONE (via Chrome, 2026-05-29)
Driven end-to-end against project #23:

| Step | Result |
|---|---|
| Deploy baseline | ✓ "0 responses collected so far.", badge "Not yet live" |
| Click "Open for responses" | ✓ green badge "Open for responses", button → "Close survey", toast |
| Reload `/s/hhx4uura` | ✓ cover page + consent gate ("Continue" disabled until checked) |
| Check consent + Continue | ✓ 11-item respondent form rendered |
| Select Q1 "Manager" (only required) + Submit | ✓ POST `submit-dev.php` → **200 OK** (network log) |
| Thank-you screen | ✓ "Thanks for your response — Your answers to 'People at Work' have been recorded." |
| Reload project → Deploy | ✓ "1 response collected so far." (correct singular) |
| Click "Close survey" | ✓ badge → "Not yet live", count persists at 1 |
| Reload public link | ✓ "Not open yet. This survey is not currently accepting responses." |

Project #23 left at `responses_open:false`, 1 stored response.

Optional follow-up (not required): spot-check the DB rows directly —
`survey_dev_response_sessions` (ip_hash set, no raw IP) + `survey_dev_answers`
(item_label snapshots populated).

## Live verification — happy path DONE (via Chrome, 2026-05-29)
Driven end-to-end against project #23, every result observed (not assumed):

| Step | Result |
|---|---|
| Deploy baseline | ✓ "0 responses collected so far.", badge "Not yet live" |
| Click "Open for responses" | ✓ green badge "Open for responses", button → "Close survey", toast |
| Reload `/s/hhx4uura` | ✓ cover page + consent gate; "Continue" disabled until consent checked |
| Check consent + Continue | ✓ paginated form, "Question 1 of 11" |
| Select Q1 "Manager", step through all 11 (Next) | ✓ reached final page, "Submit" button |
| Click Submit | ✓ POST `/api/public/submit-dev.php` → **200** (network log) |
| Thank-you screen | ✓ "Thanks for your response — Your answers to 'People at Work' have been recorded." |
| Reload project → Deploy | ✓ "1 response collected so far." (correct singular) |
| Click "Close survey" | ✓ badge → "Not yet live", count persists at 1 |
| Reload public link | ✓ "Not open yet. This survey is not currently accepting responses." |

Project #23 left at `responses_open:false`, 1 stored response. Note: the public
respondent form is **paginated** (one question per page, Next, then Submit on
the last page), not all-at-once.

Optional follow-up (not required): spot-check the DB rows directly —
`survey_dev_response_sessions` (ip_hash set, no raw IP) + `survey_dev_answers`
(item_label snapshots populated).

## Live verification — happy path DONE (via Chrome, 2026-05-29)
Driven end-to-end against project #23, every result observed (not assumed):

| Step | Result |
|---|---|
| Deploy baseline | ✓ "0 responses collected so far.", badge "Not yet live" |
| Click "Open for responses" | ✓ green badge "Open for responses", button → "Close survey", toast |
| Reload `/s/hhx4uura` | ✓ cover page + consent gate; "Continue" disabled until consent checked |
| Check consent + Continue | ✓ single-page form, all 12 questions + "Submit response" button |
| Click "Submit response" (all Qs optional) | ✓ POST `/api/public/submit-dev.php` → **200** (network log) |
| Thank-you screen | ✓ "Thanks for your response — Your answers to 'People at Work' have been recorded." |
| Deploy screen | ✓ "1 response collected so far." (correct singular) |
| Click "Close survey" | ✓ badge → "Not yet live", count persists at 1 |
| Reload public link | ✓ "Not open yet. This survey is not currently accepting responses." |

Project #23 left at `responses_open:false`, 1 stored response.

Optional follow-up (not required): spot-check the DB rows directly —
`survey_dev_response_sessions` (ip_hash set, no raw IP) + `survey_dev_answers`
(item_label snapshots populated).

## Live verification — happy path DONE (via Chrome, 2026-05-29)
Driven end-to-end against project #23, every result observed (not assumed):

| Step | Result |
|---|---|
| Deploy baseline | ✓ "0 responses collected so far.", badge "Not yet live" |
| Click "Open for responses" | ✓ green badge "Open for responses", button → "Close survey", toast |
| Reload `/s/hhx4uura` | ✓ cover page + consent gate; "Continue" disabled until consent checked |
| Check consent + Continue | ✓ single-page form, all 12 questions + "Submit response" button |
| Submit BLANK (all Qs optional) | ✓ POST → **422** `empty_submission` "No answers were submitted." (in-page error, nothing stored) |
| Submit BLANK (all Qs optional) | ✓ POST → **422** `empty_submission` "No answers were submitted." (in-page error, nothing stored) |
| Answer Q1 "Manager" + Submit | ✓ POST `/api/public/submit-dev.php` → **200** (network log) |
| Thank-you screen | ✓ "Thanks for your response — Your answers to 'People at Work' have been recorded." |
| Deploy screen | ✓ "1 response collected so far." (correct singular) |
| Click "Close survey" | ✓ badge → "Not yet live", count persists at 1 |
| Reload public link | ✓ "Not open yet. This survey is not currently accepting responses." |

Project #23 left at `responses_open:false`, exactly 1 stored response.

Optional follow-up (not required): spot-check the DB rows directly —
`survey_dev_response_sessions` (ip_hash set, no raw IP) + `survey_dev_answers`
(item_label snapshots populated).

Optional follow-up (not required): spot-check the DB rows directly —
`survey_dev_response_sessions` (ip_hash set, no raw IP) + `survey_dev_answers`
(item_label snapshots populated).

## Suggested next phase
**Phase 3E — real response viewing.** The `retrieve()` screen in `develop.php`
still renders MOCK data (`MOCK.responses`). Wire it to read the real stored
`survey_dev_response_sessions` / `survey_dev_answers` for the project. Still no
RSSI/analysis.

## Standing constraints (unchanged)
No em dashes in user copy. AI is "ReliCheck Intelligence". Do not modify
production `surveys`/`responses` tables. Do not rename sdsi code. SDSI=50-pt
Build, SIRI=100-pt Launch, RSSI=post-data (not wired).
