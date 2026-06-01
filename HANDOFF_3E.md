# Handoff — Phase 3E: Retrieve Data / Response Viewer

_Date: 2026-05-29._

> **Status: COMPLETE + LIVE-VERIFIED in production.**
> The Retrieve Data screen now shows real stored responses (no mock). Verified
> end-to-end on project #23 via Chrome. #23 is left CLOSED with 1 stored
> response.

## Goal
Replace the mock Retrieve Data screen with real stored responses from the
Phase 3D tables. Viewing only — no RSSI, no analysis, no export.

## What landed

**New file — `api/dev/project-responses.php`** (authenticated, owner-gated)
- GET `?project_id={id}` (optional `&debug=1`).
- `require_auth()` + `sds_require_project($pdo,$userId,$projectId)` → only the
  project owner can read; unauth → 401 `not_authenticated`, non-owner/missing
  project → 404 `not_found` (verified live).
- Reads `survey_dev_response_sessions` (newest first) + `survey_dev_answers`,
  groups answers by session.
- Returns `{ ok, count, sessions: [ { id, submitted_at,
  answers: [ { item_id, label, value } ] } ] }`.
- **Privacy:** `ip_hash` is NEVER returned. `user_agent` is returned ONLY when
  `?debug=1` is passed (owner self-troubleshooting), never by default.
- **Choice-answer resolution:** take.html stores single/multiple-choice answers
  as 0-based option INDEXES. This endpoint loads each item's `options` and
  resolves indexes → option text for choice types (Multiple Choice, Single
  Choice, Dropdown, Checkboxes, Yes/No, True/False, NPS). Multi-select (JSON
  array of indexes) → comma-joined text. Non-choice/text answers pass through
  unchanged. (This was a real fix: the answer first displayed as raw "2";
  now shows "Manager".)

**`develop.php` — rewrote `Screens.retrieve()`** (dropped all `MOCK.responses`)
- States: DB-mode-required guard → loading → error (with Try again) → empty
  state (📭 "No responses yet" + link to Deploy) → populated.
- Populated view: count stat; per-session cards titled "Response N" (newest =
  highest N) with localized `submitted_at` and a Question/Answer table; a
  Refresh button.
- New `App._loadResponses()` — one-time fetch when the screen opens (guarded by
  `state.responsesLoading` / `state.responseList`), `App.refreshResponses()` —
  clears cache + refetch.
- New state fields `responseList` (null until loaded), `responsesLoading`,
  `responsesError`; all reset in `DB.hydrate()` so switching projects refetches.
- The mock `byDay`/`recent`/completion/median stats are gone. `MOCK.responses`
  is still referenced by `Screens.analysis()` (the RSSI/Studios hand-off
  screen) — left untouched, since analysis is out of scope.

## Live verification (Chrome, project #23)
| Check | Result |
|---|---|
| Unauthenticated GET | ✓ 401 `not_authenticated` |
| Owner GET project_id=23 | ✓ `{count:1, sessions:[…]}` |
| Foreign/absent project_id=999999 | ✓ 404 `not_found` (owner isolation) |
| Retrieve screen shows real data, no mock | ✓ "Response 1", count 1 |
| Answer displays correctly | ✓ "Which best describes your role?" → **"Manager"** (was raw "2") |
| submitted_at shown | ✓ "Submitted 5/29/2026, 10:55:58 PM" |
| ip_hash exposed in payload | ✓ NO (`hasIpHash:false`) |
| user_agent exposed by default | ✓ NO (`hasUserAgent:false`) |
| Open→close toggle preserves responses | ✓ count stayed 1 across toggle |

#23 left at `responses_open:false`, 1 stored response.

## Out of scope (NOT done, per instructions)
No RSSI, no Studios, no AI Deep Check, no analysis. **Export was not built** —
the Deploy screen's CSV/Word/API cards are still stubs; export is Phase 3F.

## Suggested next phase
**Phase 3F — Response export (optional):** CSV/XLSX download of
`survey_dev_answers` for the project (resolved values + a respondent-per-row
matrix). Still no RSSI/analysis.

## Standing constraints (unchanged)
No em dashes in user copy. AI is "ReliCheck Intelligence". Do not modify
production `surveys`/`responses` tables. Do not rename sdsi code. SDSI=50-pt
Build, SIRI=100-pt Launch, RSSI=post-data (not wired).
