# Handoff — Qualitative Analysis Studio Phase 1

**Date:** 2026-06-02 · **Branch:** `main` · **HEAD:** `686a713` · working tree clean.

> ⚠️ **AUTO-DEPLOY:** saving a file uploads to LIVE prod in ~15s. DB is remote prod — nothing here was runtime-tested beyond `php -l` + JS parse. Live proof = exercising on prod.

---

## What was built this session (4 commits on main)

### `2ad8a74` — Phase 1 foundation
- `db/schema_qual_studio.sql` — 7 tables: `qual_projects` (→ rc_projects), `qual_documents`, `qual_segments`, `qual_codes`, `qual_code_applications`, `qual_memos`, `qual_audit_trail`
- `api/_qual_studio.php` — `qual_ensure_schema()`, `qual_require_project()`, `qual_audit()`, `qual_materialize_segments()`
- `api/qual/` — 13 endpoints: create-project, save-project, get-project, list-projects, link-dataset, get-segments, get-codes, save-code, apply-code, remove-code, save-memo, get-variable-meta
- `qual-studio.php` — landing page
- `qual-studio-workspace.php` — workspace shell
- `apps/qual/qual-studio.js` — workspace JS
- `apps/qual/qual-studio.css` — (now a stub; CSS is inline in workspace PHP)

### `cfec323` — Landing page simplified
- `qual-studio.php`: primary CTA = "Open Qualitative Studio" → workspace. No modal.
- Workspace `Screens.start()`: project picker + new project form shown when no project_id.

### `e84cee3` — Rebuilt to follow studio template contract
- `qual-studio-workspace.php`: now matches exact contract — `.app` grid, `#studioHeader`, numbered `.step` rail with `data-done`/`data-active`, companion (Guidance/Notes/Intelligence tabs), `#studioFooter`. Loads `studio-header.js`, `studio-footer.js`, `type-taxonomy.js`, `data-map.js`.
- `qual-studio.js`: follows `analysis-studio.js` pattern — `renderStart`, `renderOverview`, `renderDataMap`, `renderWork`, `renderReport`. Work steps gated behind `datamapConfirmed && hasData()`.
- Pipeline in BOOT: 13 steps (start → overview → datamap → setup → familiarize → coding → codebook → 6 soon stubs → report).
- `api/qual/get-variable-meta.php`: returns column_meta as rawVars for DataMap.

### `686a713` — Fix not populating
Two bugs:
1. `qual-studio.js` was in `<head>` before `BOOT` was defined → moved to after `BOOT` in `<body>`.
2. Inline `onclick` referenced `state`/`render` inside IIFE → added `window.QS = { go(stepId) }`, swapped handler to `QS.go('codebook')`.

---

## ⛳ KNOWN ISSUE — next session must fix first

**`renderStart()` shows "Turn words into evidence" hero text.** The Start step is a data intake hub, not a marketing page. The hero copy belongs on `qual-studio.php` (the landing), not in the workspace. The fix is to rewrite `renderStart()` to match the D/I studio pattern: clean upload card + "data loaded" bar when data exists + "open saved project" option. No hero text.

---

## Migration required before first use on prod
Run `db/schema_qual_studio.sql` on the production database.

---

## Architecture decisions locked

- **Segment model**: `qual_segments` is the core unit. Survey responses = one segment per response. Transcripts = many segments per document. This model is correct from day 1.
- **Upload widget routing**: `projectType:'rssi'` (returns datasetId directly) → `api/qual/link-dataset.php` handles materializing segments from open columns.
- **DataMap gating**: `state.datamapConfirmed` must be true before any work step is accessible.
- **`rc_projects` FK**: `qual_projects.rc_project_id` links to the ecosystem project table, same as mm_projects and analysis_projects.
- **Theme color**: deep forest green `#1e5c3a` / deep `#174d30` / soft `#e8f5ee`.

---

## Phase 2 (not built — next after Start screen fix)

- Linguistic Concept Scan (Claude API, structured output → evidence_json)
- AI code suggestions with 4 evidence types (lexical / phrase / semantic / syntactic)
- Data Cleaning / De-identification module

## Phase 3+
Category Builder, Theme Builder, Quote Finder, Trustworthiness Review, Saturation Support, Audit Trail UI, Report Builder, Export Center, Ecosystem connections (MM Studio, RSSI, SIRI).

---

## Key files
- Landing: `qual-studio.php`
- Workspace shell: `qual-studio-workspace.php`
- JS controller: `apps/qual/qual-studio.js`
- API helper: `api/_qual_studio.php`
- API endpoints: `api/qual/`
- DB schema: `db/schema_qual_studio.sql`
- Studio registry entry: `_studio_registry.php` (slug: 'qual')
- Wordmark needed: `/Qual-Studio-long.png` at 2172×724 px, 72 dpi (same spec as all other studio wordmarks)
