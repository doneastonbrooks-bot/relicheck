# HANDOFF — Phase 4E complete (RSSI Interpretation Guidance)

Continuing the Survey Development System (`develop.php` + `api/dev/*` + `api/public/*`).
The RSSI line now runs end to end AND explains itself in plain language.

## Status (all COMPLETE + LIVE-VERIFIED on relichecksurvey.com)
- 2C–3F: build → SDSI Build Check → SIRI Launch Check → publish → deploy → collect → store → view → export CSV
- 4A: RSSI Dataset Loader (`api/dev/rssi-dataset.php` + `Screens.dataset()`)
- 4B: RSSI Scoring Engine v1 (`apps/rssi/rssi-engine.js`, 33/33 unit tests)
- 4C: RSSI Endpoint + Persistence (`api/dev/rssi-run.php` + `rssi_reviews` table)
- 4D: Full construct-first RSSI report screen (`Screens.rssiReport()`)
- **4E (this session): RSSI Interpretation Guidance (`Screens.rssiGuidance()`)**

## What 4E added
New method **`Screens.rssiGuidance(rr)`** in `develop.php`, rendered at the bottom of
`Screens.rssiReport()` under an **"Interpreting this result"** header. It is PURE
presentation: reads ONLY the existing RSSI result object, recomputes nothing, invents
no claim not backed by an engine value, and keeps reliability evidence separate from
descriptive/categorical evidence.

- **Scored branch (6 cards):** What this means (band-keyed) · What you can say (lists
  acceptable+ constructs as reportable + score/band; descriptive caveat when categorical
  items exist) · What not to overclaim (reliability≠validity, sample-bound, names weak
  constructs) · Domain-specific next actions (only domains with fraction<0.85) ·
  Construct-specific guidance (strong/acceptable/weak by alphaBand, or not-enough-evidence
  using `c.note`) · Item-warning guidance (counts by severity).
- **Withheld branch (4 cards):** What this means (too-few-responses vs `fence.level==='no_structure'`)
  · What you can say (descriptive only, preliminary) · What not to overclaim (no reliability
  claim yet) · What to do next (collect ≥minN, or add scorable structure).
- **Stale note** in both branches when `state.rssiStale` is true.
- No em dashes in user-facing copy.

## Verified this session (production, via Chrome javascript_tool)
- **#24** (`ndimpfy3`, 37 responses, RSSI 94.9): all 6 scored cards; names Engagement +
  Manager Support; "Reliability is not validity"; "94.9 / 100" in can-say; "No item-level
  problems"; categorical-separation note present.
- **#23** (`hhx4uura`, 1 categorical response, withheld): 4 withheld cards; "did not produce
  a score" + "not enough analyzable data"; descriptive allowed; "Do not make any reliability"
  claim; "Collect at least… re-run RSSI"; NO domain-actions card.
- Forced `state.rssiStale=true` → stale guidance note renders in both report and guidance.

## RSSI architecture (do not re-litigate)
The engine is the single source of truth and is a PURE JS module
(`apps/rssi/rssi-engine.js`), same client-side pattern as SIRI. The browser runs
`RSSIEngine.score(dataset)`; `api/dev/rssi-run.php` validates + persists + stamps the
response fingerprint server-side. Stats were deliberately NOT ported to PHP. 4D/4E are
presentation-only over `state.rssiResult`.

## Where things live (gotchas)
- Screen renderers live on **`const Screens`** (dispatched `Screens[route]` in `render()`),
  NOT on `App`. Call sibling screen helpers as `Screens.xxx`, not `App.xxx`. (`App.rssiReport`
  threw until fixed to `Screens.rssiReport`.)
- `develop.php`, `take.html`, `api/*`, `apps/*` are served LIVE from the Dropbox-synced
  working dir, BUT with sync + document-cache lag. To verify a JS change: poll a
  `fetch('develop.php?probe='+Date.now(),{cache:'no-store'})` for your new marker string,
  THEN hard-reload the tab with a fresh `&cb=` query param before the loaded page picks it up.
- `node apps/rssi/rssi-engine.test.js` runs the 33 engine unit tests.

## Test projects
- **#24 "Team Climate Pulse (RSSI seed)"** — `ndimpfy3`, ~37 responses, 2 Likert constructs
  (Engagement, Manager Support) + 1 open-text. Primary scored case; RSSI ≈ 94.9, not stale.
- **#23 "People at Work"** — `hhx4uura`, 1 categorical response. Withheld/fence case
  ("Insufficient data to judge", saved `withheld=1`, `total=NULL`). Left CLOSED.

## How to verify on real data
Drive `develop.php?db=1` in the logged-in Chrome tab via javascript_tool:
`DB.call('project-load.php?id=N')` → `DB.hydrate(...)` → `App.go('dataset')` →
`App.runRssi()`. Inspect `state.rssiResult` and the rendered `.screen` DOM. Computer-vision
clicks hit stale element refs across re-renders; drive via JS instead.

## Hard guardrails (do not cross without an explicit instruction)
- Do NOT start Studios, AI Deep Check, or factor analysis.
- Do NOT change the RSSI scoring engine unless a display bug requires it.
- Do NOT modify production surveys/responses tables; new additive tables only.
- Do NOT rename sdsi code (sdsi = internal engine + 50-pt design domain).
- Do NOT change SDSI or SIRI engines/endpoints.
- Do NOT edit index.html / homepage hero.
- No em dashes in user-facing copy; brand AI as "ReliCheck Intelligence";
  builder is click-to-add, not drag-and-drop.

## Candidate next work (pick per instruction — don't assume)
- **Phase 4F: RSSI report export** — download the report/guidance as PDF or Word (and/or an
  XLSX of construct/domain/item tables). Mirror the 3F CSV export pattern (authed,
  owner-gated `api/dev/*`, never include ip_hash/user_agent). Presentation of the saved
  result; no new scoring.
- Optional smaller follow-ups still open: XLSX response export; localize CSV "Submitted at"
  (currently UTC); Word/PDF instrument export.
- Unresolved product question: all-optional surveys reject a fully blank submit with 422
  `empty_submission` — decide whether to allow zero-answer submissions.

## Working agreement
Proceed one phase at a time; verify each on real data before moving on; only mark something
"verified" after observing actual tool output, not prior claims.
