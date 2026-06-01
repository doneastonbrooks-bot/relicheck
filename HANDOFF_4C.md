# Handoff — Phase 4C: RSSI Endpoint + Persistence

_Date: 2026-05-30._

> **Status: COMPLETE + LIVE-VERIFIED in production (relichecksurvey.com).**
> The Phase 4B RSSI engine is wired into the authenticated app, run over the
> Phase 4A dataset, and the result is persisted in its own table separate from
> SDSI and SIRI, with honest staleness tracking. NO full report UI, NO Studios,
> NO AI Deep Check, NO factor analysis. SDSI and SIRI untouched.

## Architecture note (read this first)
The RSSI scoring engine is the single source of truth and is a **pure JS
module** (`apps/rssi/rssi-engine.js`), the same client-side pattern SIRI uses
(`siri-readiness.js` → `siri-save.php`). PHP cannot execute that module, and
porting ~400 lines of statistics to PHP would create a second implementation
that could silently drift from the tested one. So the **browser** loads the
dataset and runs the engine; **`api/dev/rssi-run.php`** validates and persists
the result. The suggested "server-side engine execution" was therefore
reconciled this way on purpose; the endpoint still owns the run record and
stamps the authoritative response fingerprint server-side so staleness cannot
be faked by the client.

## What landed

**New file — `api/dev/rssi-run.php`** (authenticated, owner-gated)
- POST `{ project_id, result }`. `require_auth()` + `sds_require_project()`
  (401 / 403-404). `check_origin()`.
- Validates `result.version === 'rssi-v1'` and the presence of score/fence/
  domains; rejects anything else with 422 `bad_result` (will not store junk).
- Computes the AUTHORITATIVE response-data fingerprint server-side
  (`COUNT(*)` + `MAX(submitted_at)` from `survey_dev_response_sessions`).
- Upserts `rssi_reviews` (one row per project): `total` (NULL when withheld),
  `max_points` 100, `pct`, `band`, `verdict`, `withheld`, `response_count`,
  `last_submitted_at`, full `review` JSON, created/updated.
- Returns the canonical stored record (`stale:false`, just stamped).

**New table — `rssi_reviews`** (additive; in `_dev_common.php sds_ensure_schema`
AND `db/schema_survey_dev_system.sql`). Separate from `sdsi_reviews` and
`siri_reviews`. `total`/`pct` are NULLABLE so a withheld score is stored
honestly, not as a fake 0.

**`_dev_common.php sds_project_payload`** now returns a `rssi` object next to
`sdsi`/`siri`. It includes the saved fields plus a server-computed **`stale`**
flag = saved fingerprint (`response_count`, `last_submitted_at`) differs from
the live response count / newest submission. Also returns `current_count`.

**`develop.php` (minimal wiring only)**
- Loads `apps/rssi/rssi-engine.js`.
- State: `rssiResult`, `rssiSaved`, `rssiStale`, `rssiRunning` (reset/hydrated in
  `DB.hydrate` from `payload.rssi`, separate from SDSI/SIRI).
- `App.runRssi()`: ensures the dataset is loaded, runs `RSSIEngine.score()` in
  the browser, POSTs to `rssi-run.php`, stores the saved record, toasts the
  score (or "Insufficient data to judge").
- Dataset/Analysis screen (`Screens.dataset()`) gains an **"RSSI score"** panel
  above the dataset detail: Run / Re-run button; big score (or "—" when
  withheld); band/verdict; fence status; saved-from-N-responses line; a
  **stale banner** when new responses arrived after the run; and a minimal
  4-domain subscore table. Full report cards are explicitly deferred to 4D.

## Live verification (Chrome, real data)
| Check | Result |
|---|---|
| #24 runs RSSI from the authed app | ✓ score 95.2 (later 94.9 after a 37th response) |
| #24 ≈ verified engine result (~95.2) | ✓ 95.2 on the same 36-response data |
| #23 withholds | ✓ score null, verdict "Insufficient data to judge", saved `withheld=1`, `total=NULL` |
| Saves to DB | ✓ `rssi_reviews` upsert, fingerprint rc=36/37 |
| Reloads on reopen | ✓ reopening #24 hydrates `rssiResult` 95.2 from `payload.rssi` without re-running |
| Separate from SDSI/SIRI | ✓ payload returns `rssi` alongside `siri`; own table |
| Stale on new data | ✓ after a new submission, reopening #24 shows `rssiStale=true` (saved 36 vs current 37); old score still shown but flagged, never silently treated as current |
| SDSI / SIRI unchanged | ✓ no edits to those engines/endpoints |

## Out of scope (NOT done, per instructions)
No full RSSI report UI/cards, no Studios, no AI Deep Check, no factor analysis.
SDSI and SIRI engines/endpoints untouched.

## Suggested next phase
Phase 4D — the full construct-first RSSI report screen (overall → domains →
per-construct reliability with alphas → item warnings inside each construct →
interpretation band), reading the saved `rssi` review. Await go-ahead.

## Standing constraints (unchanged)
No em dashes in user copy. AI = "ReliCheck Intelligence". Additive only; do not
modify production surveys/responses tables. Do not rename sdsi code.

## State left on the box
#24 "Team Climate Pulse (RSSI seed)" now has **37** responses (one extra was
submitted to exercise staleness) and a fresh, non-stale RSSI of **94.9**. #23
has a saved withheld RSSI row.
