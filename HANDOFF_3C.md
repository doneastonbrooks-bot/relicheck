# Handoff — Phase 3C COMPLETE + LIVE VERIFIED

_Date: 2026-05-29. Hand this whole file to the next session._

---

## TL;DR

Phase 3C landed and was live-verified against project #23 on relichecksurvey.com. All acceptance criteria passed. Project #23 is back to `responses_open:false`. Engine tests: 47/47. Ready for Phase 3D.

---

## Phase 3C — Public Respondent Survey Page (CLOSED + VERIFIED)

### What landed

**New files:**
- `api/public/survey-dev.php` — public GET endpoint keyed on `link_key`. Looks up `deployment_settings` by `JSON_EXTRACT(settings, '$.link_key')`. Returns HTTP 403 + `{closed:true}` when `responses_open=false`. Returns survey data shaped for `take.html` with item types mapped. Builds `settings.coverPage` from launch readiness `consent.statement` + `instructions.text`. Sets `_isDevSurvey:true`.
- `api/dev/project-open.php` — authenticated POST endpoint. Body: `{project_id, open: true|false}`. Flips `responses_open` in `deployment_settings`. Stamps `opened_at` on first open.

**Modified files:**
- `take.html`:
  - `boot()` — tries `survey.php?slug=` first; on 404 falls back to `survey-dev.php?link_key=`; routes `{closed:true}` to `renderClosed()`
  - `renderClosed()` — "Not open yet" page
  - `renderForm()` — `section` items render as `.qsection-heading` (no number); `consent` items render as `.consent-check` checkbox; `_builderType==='long'` gets `min-height:140px`; question counter skips section items
  - `devSubmit()` — client-side required validation + `renderThanks()`; zero network POSTs; Phase 3D will replace with real storage
  - CSS: `.qsection`, `.qsection-heading`, `.consent-check`
- `develop.php`:
  - `App.toggleResponsesOpen()` — POSTs to `project-open.php`, updates state, re-renders
  - `Screens.deploy()` — green/amber badge based on `responses_open`; "Open for responses" / "Close survey" toggle button; stale "Phase 3C" placeholder text removed

### Item type mapping (survey-dev.php)

| dev type | take.html type | notes |
|---|---|---|
| `Likert Scale`, `Likert (5-pt)` | `likert` | 5-pt, anchors from item settings |
| `Likert (7-pt)` | `likert` | 7-pt |
| `Yes/No` | `single` | options: ['Yes','No'] |
| `True/False` | `single` | options: ['True','False'] |
| `Multiple Choice`, `Single Choice`, `Dropdown` | `single` | options from item.options |
| `NPS` | `single` | options: 0–10 |
| `Checkboxes` | `multi` | options from item.options |
| `Rating Scale`, `Rating` | `open` | `_builderType:'rating'` |
| `Long Answer`, `Long Text` | `open` | `_builderType:'long'` (140px min-height) |
| `Short Answer`, `Open-Ended`, etc. | `open` | plain textarea |
| `Section Text`, `Instructions`, `Page Break` | `section` | heading, not numbered |
| `Consent` | `consent` | required checkbox |

---

## Live verification results (all passed)

Verified on project #23 "People at Work", link_key `hhx4uura`.

| Acceptance criterion | Result |
|---|---|
| `responses_open=false` shows "Not open yet" | ✓ |
| Deploy screen toggle opens survey (green badge, "Close survey") | ✓ |
| `responses_open=true` renders respondent-facing survey | ✓ |
| Consent + instructions render as cover page gate | ✓ |
| `single` questions render with radio options | ✓ |
| `open/long` renders as textarea with min-height:140px | ✓ |
| `section` type CSS: font-weight:700, border-bottom | ✓ |
| `consent` type CSS: flex layout, rounded border, checkbox | ✓ |
| Zero builder controls in respondent view | ✓ |
| Submit shows thank-you screen | ✓ |
| Zero POST calls fired — no response storage | ✓ |
| Closing responses returns link to "Not open yet" | ✓ |

---

## Environment / critical facts

- `develop.php` is **UNTRACKED in git** and auto-SFTP-deploys live to Ionos on save. Cache-bust with `&cb=<number>`.
- `localhost:8000` cannot auth — DB-mode live testing must use the logged-in Chrome tab (`App.openExisting(23)`).
- Test project: **#23** "People at Work". link_key: `hhx4uura`. Currently `responses_open:false`.
- Engine tests: `node apps/sdsi/launchcheck-engine.test.js` → **47/47 pass**. Never modify `buildcheck-engine.js`.
- No new DB tables were needed. `deployment_settings` was pre-provisioned in Phase 3B.

---

## Suggested next phase

**Phase 3D: Response Collection Storage**

Wire `devSubmit()` to a real backend endpoint that stores responses from dev surveys.

Steps:
1. Create a `dev_responses` table (do NOT use the production `responses` table).
2. Create `api/public/submit-dev.php` — validates answers against `survey_items`, inserts into `dev_responses`, returns `{ok:true}`.
3. Update `take.html` `devSubmit()` to `async`, POST to `submit-dev.php` instead of calling `renderThanks()` directly.
4. Update deploy screen to show a live collected count from `dev_responses` (do NOT fake it).
5. Remove the `_isDevSurvey` sentinel once real submission is wired, OR keep it to distinguish dev vs. production surveys going forward.

Do NOT start RSSI, Studios, or AI Deep Check. Do NOT modify the existing production `surveys` / `responses` tables.

---

## Standing constraints (never violate)

- Do NOT modify existing production Ionos MySQL tables — only CREATE new additive tables.
- Do NOT rename sdsi code. Do NOT change locked lens vocabularies/scoring/blocker logic.
- Never use em dashes in user-facing copy. Write plainly for non-expert users.
- Brand AI as "ReliCheck Intelligence", not generic "AI".
- Builder is click-to-add, NOT drag-and-drop.
- Never handle/enter credentials; never log into accounts.
- SIRI = 100-pt Launch Check. SDSI = 50-pt Build Check. RSSI = post-data (not wired here).
