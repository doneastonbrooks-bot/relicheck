# Session Handoff — 2026-06-02 (late evening)

## The ecosystem understanding — READ THIS FIRST

ReliCheck is an **ecosystem of connected but independent sub-systems**:

**SIRI (pre-data) → RSSI (post-data, optional) → Studios (deep analysis, optional)**

Users can enter and exit at any point. There is no forced pipeline. Someone might:
- Upload a CSV directly into the D/I Studio, never touching SIRI
- Build in SIRI, skip RSSI, go straight to MM Studio
- Use the standalone RSSI upload only
- Do the full path: SIRI → deploy → collect → RSSI → D/I → MM

All of those are valid. The unified RE infrastructure (upload widget, type taxonomy, project table, variable_metadata) is what makes that freedom possible without losing continuity. When data enters the ecosystem anywhere, it is stored the same way, classified the same way, and can be picked up by any other part of the ecosystem. The user does not re-upload, re-classify, or lose project context when they move between parts of the system.

**This is why the upload widget being consistent across every studio matters.** It is the entry gate to the ecosystem. If it works differently depending on where you enter, the data lands in a different format and the ecosystem connection breaks at the first step.

Every RE infrastructure item is a link in that chain. A broken or inconsistent link breaks the ecosystem promise.

---

## What this session accomplished

### RE Infrastructure Item 2 — Unified data upload widget

**Committed `949605d`** — core infrastructure:
- `api/dev/_type_taxonomy.php` — added `RC_DATASET_TYPE_MAP` + `rc_analysis_type_from_dataset_type()` (maps old column type vocab to canonical)
- `api/_dataset_helpers.php` (NEW) — `rc_seed_var_meta_from_dataset()`: called at dataset-link time, reads `column_meta`, writes `variable_metadata` rows with canonical types; prefers explicit `analysis_type` stored by new widget, falls back to legacy type mapping; non-fatal on error
- `api/datasets/create.php` — now accepts optional `analysis_type` per column (stored alongside legacy `type` for backward compat); requires `_type_taxonomy.php`
- `api/analysis/link-dataset.php` — calls `rc_seed_var_meta_from_dataset()` after linking
- `api/analysis/projects.php` — calls `rc_seed_var_meta_from_dataset()` when project created with a dataset
- `api/mm/link-dataset.php` — calls `rc_seed_var_meta_from_dataset()` — MM gets `variable_metadata` for free with no client changes
- `apps/studio/dataset-upload.js` (NEW) — unified upload widget (replaces `analysis-upload.js`); CSV/TSV/XLSX/JSON + Qualtrics platform detection; sends both `analysis_type` + legacy type per column; `projectType`-aware link routing; no paste option
- `_analysis_studio_v4_shell.php` — swapped to `dataset-upload.js`, added `projectType:'analysis'` to ctx

**Committed `9d5c12b`** — upload modal redesign + MM wizard simplification:
- `dataset-upload.js` — rewritten `open()`: single-page modal (title + description + file drop zone, no confirm step); types auto-detected silently; new `embed(container, ctx)` for inline use without modal wrapper
- `mm-wizard.php` — collapsed from 5 steps to 2 (Set Up: title+desc / Upload Data); removed steps 3-5 (Data Kind, Intent, Design) from wizard entirely; replaced evidence-intake.js embed with `DatasetUpload.embed()` inline; on upload complete, redirect straight to mmstudioV4.php
- `_mm_pipelines.php` — added `study_design` step to `$SETUP` after `data_map` (mode='study_design')
- `mmstudioV4.php` — added `renderStudyDesign()` workstation panel for the 3 questions moved from wizard

**Committed `0d16612`** — Study Setup 3-tab panel:
- `_mm_pipelines.php` — renamed 'Study Design' → 'Study Setup' (label + title)
- `mmstudioV4.php` — `renderStudyDesign()` rewritten as 3-tab panel: Data Kind / Intent / Design; tab switching re-renders in place; Design tab shows Recommended pill computed from current Data Kind + Intent; Save persists all three from any tab via `/api/mm/wizard.php`

**Committed `aed0613`** — CSS fix:
- `dataset-upload.js` — injects `du-*` styles on first load (style tag `id=du-styles`); single-page modal layout (title + description + file picker all visible at once) now renders correctly in every studio
- Fixed `#duChange` callback bug (`arguments.callee` → named `onFile` function)

---

## RE Infrastructure status

| Item | Status |
|---|---|
| 1 — Unified type taxonomy | COMPLETE |
| 2 — Unified data upload/parser | COMPLETE |
| 3 — Unified project table | NOT STARTED — NEXT |
| 4 — Unified export | NOT STARTED |
| 5 — Wire RE connections | NOT STARTED |

---

## RE Infrastructure Item 3 — Unified project table (next)

**The problem:** Four separate project tables exist today:
- `survey_projects` — SIRI (develop.php)
- `analysis_projects` — D/I Studios
- `mm_projects` — MM Studio
- `variable_metadata` uses `project_type` FK-by-convention to span all three

A SIRI project is completely disconnected from an analysis project. A user cannot "open this in the Descriptive Studio" from SIRI and have it remember project context. The handoff between parts of the ecosystem breaks at the project level.

**The goal:** One project table that all studios reference, so a project can move SIRI → analysis studios → MM without re-linking. This is what makes the "enter and exit at any point" ecosystem promise real at the data level.

**Approach is still to be designed.** Do not start building without first defining the rules (RE build principle #2).

---

## Key architectural facts

### Upload widget (`apps/studio/dataset-upload.js`)
- **`DatasetUpload.open(ctx)`** — full modal: title + description + file picker, single page, no confirm step. Types auto-detected silently. `ctx = { kind, projectId, projectType, title, onLoaded(null, pid) }`.
- **`DatasetUpload.embed(container, ctx)`** — inline version for wizard use. No modal wrapper. Same upload logic.
- **`DatasetUpload.openSaved(ctx)`** — pick from saved datasets.
- Widget injects its own `du-*` CSS on load — no separate stylesheet needed.
- File types: CSV, TSV, XLSX, JSON (in-browser); Qualtrics CSV/XLSX platform detection (strips 3-row header); QSF shows helpful error message; SPSS/Stata/R deferred as "coming soon."
- On file chosen: auto-fills title from filename if blank; shows filename below drop zone; activates Upload button.
- On upload: parse → auto-detect types → POST `create.php` (with both `analysis_type` + legacy `type` per column) → link to project → call `onLoaded`.
- `projectType` determines which link endpoint is called: `'mm'` → `/api/mm/link-dataset.php`; otherwise → `/api/analysis/link-dataset.php`.

### variable_metadata seeding flow
- `create.php` stores `analysis_type` per column when widget sends it (alongside legacy `type`).
- At link time, `rc_seed_var_meta_from_dataset()` in `api/_dataset_helpers.php` reads `column_meta` and upserts `variable_metadata` rows.
- Prefers stored `analysis_type` if present; falls back to `rc_analysis_type_from_dataset_type()` mapping.
- DataMap opens pre-classified rather than empty.
- Non-fatal: link succeeds even if seeding fails.

### MM Studio wizard (mm-wizard.php)
- Now 2 steps only: Step 1 = title + description (creates MM project), Step 2 = upload via `DatasetUpload.embed()`.
- On upload complete: redirect to `mmstudioV4.php?project_id=N`.
- No more evidence-intake.js dependency.
- Study Setup questions (Data Kind / Intent / Design) live in the MM Studio left rail as a 3-tab panel after Data Map step.

### MM Studio Study Setup step
- Rail label: "Study Setup", mode: `study_design`.
- 3 tabs: Data Kind (checkboxes), Intent (checkboxes), Design (radio with Recommended pill).
- Pre-populated from `BOOT.framing` (loaded from `mm_project_framing` table).
- Save posts all three to `/api/mm/wizard.php`. Works from any tab.
- Design Recommended pill updates based on current Data Kind + Intent selections.

### Studio template contract (7 parts, locked)
Every analysis studio: Start → Overview → Variable Map (DataMap gate) → Analysis steps → Report → Uniform header + footer.

- D/I: fully wired via `_analysis_studio_v4_shell.php`
- MM: fully wired in `mmstudioV4.php`
- 360/TIA: NOT YET wired — still use platform shell header/footer

---

## Standing rules (unchanged)

- **Auto-deploy is LIVE** — saving a file uploads to prod in ~15s. GitHub is the safety net.
- **Never rename `sdsi` code** — internal engine namespace stays as-is.
- **RE build principles** — build outside first; rules before plugging; 5-step implementation process.
- **MM Studio** — V4 only. Edit with care (RE infrastructure rollouts only).
- **No em dashes** in user-facing copy.
- **AI = ReliCheck Intelligence** in user-facing copy.
- **Logo height** — 70px for all studio long wordmarks.
- **No paste option** in upload widget — file-only.
- **No column confirm step** in upload widget — types auto-detected silently; DataMap is the confirmation step.
