# Handoff — Phase 3F: Export Responses

_Date: 2026-05-29._

> **Status: COMPLETE + LIVE-VERIFIED in production.**
> The project owner can export the real stored survey responses as CSV from the
> Retrieve Data screen. Verified end-to-end on project #23 via Chrome. #23 is
> untouched (still CLOSED, 1 stored response).

## Goal
Allow the project owner to export the real stored survey responses (CSV first).
Export only — no RSSI, no Studios, no AI Deep Check, no analysis.

## What landed

**New file — `api/dev/project-export.php`** (authenticated, owner-gated)
- GET `?project_id={id}` (optional `&format=csv`; CSV is the only format).
- `require_auth()` + `sds_require_project($pdo,$userId,$projectId)` →
  unauthenticated → 401 `not_authenticated`; non-owner / absent project → 404
  `not_found` (both verified live).
- Reads `survey_dev_response_sessions` + `survey_dev_answers`.
- **One row per response session** (oldest first), columns:
  `Response`, `Submitted at`, then one column per input item.
- **Column headers = question text** (`item_label` / prompt), whitespace
  flattened to one line; duplicate headers de-duplicated with ` (2)`, ` (3)`,
  …; blank prompt falls back to `Question {id}`.
- **Choice answers resolved index → display text** (Multiple Choice, Single
  Choice, Dropdown, Checkboxes, Yes/No, True/False, NPS; multi-select indexes
  joined comma-separated). Mirrors `api/dev/project-responses.php` so the CSV
  matches the on-screen viewer.
- Streams `text/csv` with `Content-Disposition: attachment;
  filename="responses-project-{id}.csv"` and a UTF-8 BOM (Excel-friendly).
- **Privacy:** the CSV NEVER includes the respondent IP hash or user agent, and
  carries no internal owner/project metadata beyond the project id used in the
  file name.

**`develop.php` — Export CSV button**
- Added an **Export CSV** button next to Refresh in the populated Retrieve Data
  view (`Screens.retrieve()`).
- New `App.exportResponsesCsv()` — navigates to the export endpoint
  (same-origin cookie auth) so the browser downloads the file. Guards with a
  toast if DB mode is off or there are nothing to export.
- The button only appears in the populated state; the empty state shows no
  export button (disabled/empty handling).

## Live verification (Chrome, project #23)
Actual exported CSV:
```
Response,"Submitted at","Which best describes your role? Individual contributor",...,"Which best describes your race or ethnicity?"
1,"2026-05-30 05:55:58",Manager,,,,,,,,,,,
```
| Check | Result |
|---|---|
| Owner export project_id=23 | ✓ 200, `text/csv`, attachment disposition |
| One response row | ✓ exactly 1 data row |
| submitted_at present | ✓ "2026-05-30 05:55:58" |
| Answer in correct question column | ✓ "Manager" under "Which best describes your role?" |
| IP hash in CSV | ✓ NO (scan: mentionsIpHash=false) |
| User agent in CSV | ✓ NO (scan: mentionsUserAgent=false) |
| Unauthenticated export | ✓ 401 `not_authenticated` (curl, no cookie) |
| Non-owner / absent project | ✓ 404 `not_found` |

## Known note — timestamp timezone (left as-is)
- CSV `Submitted at` timestamps are currently **UTC** (e.g. `2026-05-30
  05:55:58`), straight from the stored `submitted_at`.
- The on-screen Retrieve Data viewer **localizes** timestamps (e.g. "Submitted
  5/29/2026, 10:55:58 PM").
- **Decision: leave the UTC behavior as-is for now.** Revisit only if a future
  request wants the CSV to match the viewer's local time.

## Out of scope (NOT done, per instructions)
No RSSI, no Studios, no AI Deep Check, no analysis. No XLSX/other formats (CSV
only). The Deploy screen's Word/PDF and API/Qualtrics export cards remain stubs.

## Suggested next phase
RSSI (post-response analysis) is the next major area, but it is explicitly NOT
started. Optional smaller follow-ups: XLSX export, CSV timestamp localization,
Word/PDF instrument export.

## Standing constraints (unchanged)
No em dashes in user copy. AI is "ReliCheck Intelligence". Do not modify
production `surveys`/`responses` tables. Do not rename sdsi code. SDSI=50-pt
Build, SIRI=100-pt Launch, RSSI=post-data (not wired).
