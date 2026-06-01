# Session Kickoff — ReliCheck Survey Development System

Continuing the Survey Development System (`develop.php` + `api/dev/*` + `api/public/*`).
Auto-memory (MEMORY.md index + linked notes) loads the full project state; this file is the
quick "where we are right now" pointer.

## Current status (2026-05-30)
Phase 4 is functionally CLOSED for the develop.php RSSI:
- 4A Dataset Loader, 4B Engine, 4C Endpoint+Persistence, 4D Report Screen, 4E Guidance,
  4F Export/Print — all done & live-verified on #24 (94.9) and #23 (withheld).
- **RSSI report simplification — DONE & LIVE-VERIFIED.** `Screens.rssiReport` + `Screens.rssiGuidance`
  in develop.php now follow the SDSI flow (Score → Meaning → Evidence → Caution → Next), four-domain
  v1 kept, technical detail moved into two `.exp` accordions. Plan file:
  `~/.claude/plans/hidden-bubbling-grove.md`.

## ⚠️ READ FIRST: there are TWO different "RSSI" surfaces (this caused real confusion)
1. **develop.php RSSI report** — the Survey Development System's report (`Screens.rssiReport`/
   `rssiGuidance` over `apps/rssi/rssi-engine.js`). Reached via the workspace's **RSSI / Studios**
   step after Run RSSI. Scores projects #24/#23. **THIS is the one that was simplified.**
2. **Standalone RSSI app** — `rssi.php` → `rssi-upload.php` (+ `apps/rssi/rssi.js`,
   `rssi-analyses.js`, `rssi-reliability.js`). CSV-upload, three-lens / eight-domain / item-analysis-
   tables / methods-appendix model. **UNCHANGED by design** (still busy). The dashboard "ReliCheck
   Survey Strength Index" app card routes here (`/rssi.php`).
If someone says "RSSI still looks the same," they're almost certainly (a) on a STALE tab, or
(b) looking at the standalone app (#2), not the simplified develop.php report (#1).

## Last interaction (unresolved — wait for user)
User asked for a verification-only pass because they "didn't see/approve the plan" and the app
"looks the same." Verified facts delivered:
- Simplification IS live (fetched develop.php no-store: has `rssiGuidance(rr,'trio')`,
  `exp('Full diagnostics'`, `Top issues`, `How RSSI is scored`; old markers gone).
- Live DOM render confirmed: #24 → 94.9, 2 collapsed accordions, Top issues, trio, no inline
  domain evidence bullets; #23 → withheld, no domain/top-issues sections, 2 accordions.
- Plan WAS saved and the harness reported approval (user disputes seeing it).
**Open decision (do NOT code until user picks):** (a) nothing/confirm; (b) re-review & re-approve
the plan; (c) revert develop.php to the busier report; (d) later migrate standalone rssi.php to match.

## How to verify the simplified report yourself
Open `https://relichecksurvey.com/develop.php?db=1&cb=fresh<N>` (logged-in Chrome). The app serves
LIVE from the Dropbox-synced dir but with sync + document-cache lag: to see a JS change you must
poll `fetch('develop.php?probe='+Date.now(),{cache:'no-store'})` for your marker, THEN hard-reload
with a fresh `&cb=`. Drive via `mcp__Claude_in_Chrome__javascript_tool` (CV pixel-clicks hit stale
element refs across re-renders):
`DB.call('project-load.php?id=24')` → `DB.hydrate(...)` → `await App.runRssi()` → `App.go('dataset')`
→ inspect `state.rssiResult` and the `.screen` DOM.

## Hard guardrails (do NOT cross without explicit instruction)
- Do NOT start Phase 5 Studio handoff logic; do NOT wire SIRI/RSSI into Studios.
- Do NOT change the RSSI scoring engine (`apps/rssi/rssi-engine.js`); recompute is forbidden
  (UI reads only `state.rssiResult`; invariant `result===state.rssiResult`).
- Do NOT change SDSI or SIRI (engines/endpoints/screens). Do NOT change response storage.
- Do NOT build AI Deep Check. Do NOT migrate the standalone rssi.php app yet.
- No em dashes in user-facing copy; brand AI as "ReliCheck Intelligence"; builder is click-to-add.

## Test projects
- **#24 "Team Climate Pulse (RSSI seed)"** — link_key `ndimpfy3`, 37 responses, 2 Likert constructs
  (Engagement α.874, Manager Support α.903). Scored case; RSSI 94.9, not stale.
- **#23 "People at Work"** — link_key `hhx4uura`, 1 categorical response. Withheld case
  ("Insufficient data to judge"). Left CLOSED.
- `node apps/rssi/rssi-engine.test.js` → 33/33.

## Landing-page work (done earlier, then user said PAUSE — not the current focus)
A shared RSSI-style landing system exists: `landing.css` + `_landing_head.php`/`_landing_foot.php`,
redesigned hub `app-2026v4.php` (Apps section first: Survey Development System + RSSI; then Studios
MM/TIA/360), `survey-dev.php` (fronts develop.php via `?start=scratch|import|existing`), restyled
`studio-mm/tia/360.php` (per-studio accent, own logo header, hero + CTA + sample-workflow card +
features). `rssi.php` left as the visual reference. See [[landing-alignment]] memory. **User has
twice said to PAUSE landing/Phase-5 work and focus on RSSI verification — respect that.**

## Gotchas
- Screen renderers live on `const Screens` (dispatch `Screens[route]` in `render()`), NOT on `App`.
- `develop.php` is git-untracked (`??`) but IS the served file (Dropbox working dir).
- Print/export (4F): `App.printRssi()` → `buildRssiPrintDoc()` → `Screens.rssiReport(rr,saved,true)`;
  the `forPrint` flag renders the `.exp` accordions OPEN so the PDF stays complete.
