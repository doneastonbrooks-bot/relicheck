# Handoff — Phase 4A: RSSI Dataset Loader

_Date: 2026-05-30._

> **Status: COMPLETE + LIVE-VERIFIED in production (relichecksurvey.com).**
> Loads stored survey responses into a normalized, analysis-ready RSSI dataset.
> No RSSI score, no Cronbach alpha, no reliability statistic is computed. Verified
> on project #23, which is untouched (still CLOSED, 1 stored response).

RSSI = ReliCheck Survey Strength Index (post-response evidence strength;
reliability is one core domain, not the whole). Design spine: memory
`rssi-design` / `HANDOFF_RSSI_DESIGN.md`.

## What landed

**New file — `api/dev/rssi-dataset.php`** (authenticated, owner-gated)
- GET `?project_id={id}`. `require_auth()` + `sds_require_project()` →
  unauthenticated 401, non-owner/absent 404 (both verified live).
- Reads live: `survey_items`, `survey_constructs`,
  `survey_dev_response_sessions`, `survey_dev_answers`. No mock data.
- Item → construct mapping is lifted out of `survey_items.settings` JSON
  (`{construct, constructId}`), the same place develop.php writes it.
- Choice answers resolved index → option text (mirrors
  `api/dev/project-responses.php`); both `raw` and `resolved` preserved.
- **Privacy: ip_hash and user_agent are NEVER returned.**

### Returned shape (normalized dataset)
- `responses`: `total_n`, `analyzable_n`, `min_n` (30), `too_few_responses`,
  `fence_notes[]`.
- `counts`: sessions, items_total, items_input, items_scorable, constructs,
  constructs_mapped, items_unmapped.
- `fieldTypeSummary`: counts per field type.
- `sessions`: `[{id, submitted_at}]` (no ip, no UA).
- `items`: `[{id, label, type, fieldType, structural, scorable, constructId,
  construct, options, scale, answered, missing, values:[{sessionId, raw,
  resolved}]}]`.
- `constructs`: `[{id, name, definition, itemIds, itemCount, scorableCount,
  enoughItems, note}]`.
- `unmappedItemIds`.

### Classification rules
- **Field types:** numeric_scale (Likert/Rating/NPS/Slider/Numeric/Number),
  binary (Yes-No/True-False/Consent), categorical (choice/dropdown/ranking/
  matrix), open_text (short/long/email/phone/date/open-ended), structural
  (section/instructions/page break/thank-you). Unknown → open_text (never
  silently scorable).
- **scorable** (eligible for internal consistency later) = numeric_scale OR
  binary.
- **analyzable_n** = sessions that answered ≥1 scorable item.
- **N fence** (alpha-fence stance): `too_few_responses = analyzable_n < 30`.
  Below threshold the notes say RSSI should WITHHOLD reliability claims and
  report "Insufficient data to judge" rather than force a number. Per-construct
  thin evidence (<3 scorable items) flagged with `enoughItems:false` + a note.

**develop.php — Dataset Preview screen (`Screens.dataset()`)**
- Reached from the analysis screen "Run RSSI" card (now `App.go('dataset')`,
  CTA "Load dataset").
- Shows: sample-size fence panel, top-line stats (responses / input items /
  construct groups / scorable items), construct groups (construct-first, each
  with its items + thin-evidence note), unmapped items, field-type summary,
  missingness by item. Loading / error / DB-required / empty states included.
- `App._loadDataset()` (one-time fetch), `App.refreshDataset()`, state
  `dataset` / `datasetLoading` / `datasetError` (reset in `DB.hydrate`). Rail
  maps `dataset` → analysis step.

## Live verification (Chrome, project #23)
| Check | Result |
|---|---|
| #23 loads into RSSI dataset | ✓ ok:true, phase 4A |
| Dataset shows 1 response | ✓ total_n=1 |
| Answers joined to items | ✓ raw "2" → resolved "Manager", options preserved |
| Constructs/mappings preserved | ✓ Engagement, 11 items, constructId 7; 1 unmapped |
| Field types | ✓ 11 categorical, 1 open_text, 0 scorable |
| N fence | ✓ analyzable_n 0 < 30 → "Insufficient data to judge" |
| Preview no mock data | ✓ live "RSSI dataset" screen |
| No RSSI score | ✓ loader only |
| projectName | ✓ "People at Work" |
| Foreign / unauth | ✓ 404 / 401 |
| ip_hash / user_agent | ✓ never returned |

## Note carried forward
#23's items are all Multiple Choice (categorical), so it has zero scorable
items → analyzable_n = 0 and the fence reads "Insufficient data to judge." That
is the honest-fences behavior, not a bug. A survey with Likert/Rating items
would surface scorable items and a non-zero analyzable_n.

## Out of scope (NOT done, per instructions)
No RSSI scoring engine, no RSSI score, no Cronbach alpha, no Studios, no AI Deep
Check, no RSSI report UI. CSV/other exports unchanged.

## Suggested next phase
Phase 4B — RSSI scoring engine over this dataset, built to the locked spine
(Internal consistency 35 / Item performance 25 / Response quality 20 / Score
interpretability 20; construct-first; N fence). Await user go-ahead.

## Standing constraints (unchanged)
No em dashes in user copy. AI = "ReliCheck Intelligence". Do not modify
production surveys/responses tables (new additive only). Do not rename sdsi code.
