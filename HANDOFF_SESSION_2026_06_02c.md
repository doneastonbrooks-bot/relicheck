# Session Handoff — 2026-06-02 (evening)

## What this session accomplished

This session completed **RE Infrastructure Item 1: Unified Type Taxonomy** — a full
9-task build that gives every variable in ReliCheck a canonical analysis classification
independent of its database storage type.

---

### Core principle established

Every variable has two distinct layers:
- **Storage type** (INT, VARCHAR, TEXT) — how the database holds the value
- **Analysis role** (`analysis_type`) — what the variable IS for RE purposes

The analysis role is what matters for scoring, pipeline gating, and analysis decisions.

---

### What was built (Tasks 1–9)

#### Task 1 — Taxonomy constants
Two files, one source of truth:

| File | Purpose |
|---|---|
| `api/dev/_type_taxonomy.php` | PHP constants + 5 helper functions |
| `apps/studio/type-taxonomy.js` | JS mirror; exposes `window.RCTaxonomy` |

**16 canonical `analysis_type` values:**
`identifier` · `likert_item` · `scale_score` · `open_ended` · `narrative` ·
`qualitative_code` · `theme` · `demographic_nominal` · `demographic_ordinal` ·
`demographic_numeric` · `binary` · `date_time` · `metadata` · `computed_score` ·
`file_reference` · `structural`

**Key resolution rule:** `likert_item` with `construct_id` = scale item eligible for
reliability. `likert_item` without `construct_id` = standalone ordinal, treated as
categorical for scoring purposes.

**Role vocabulary** (`RC_ROLES`): `item` · `outcome` · `predictor` · `grouping` · `linking` · `contextual`

Each `analysis_type` carries a controlled list of `allowed_analyses` (e.g. `likert_item`
→ distribution, missing_data, reliability, scale_score, item_total_correlation, etc.)

#### Task 2 — `variable_metadata` table

New table in the dev system DB, auto-created by `sds_ensure_schema()` on first request.
One row per variable per project.

Key columns:
- `project_id` + `project_type` ('survey'|'analysis'|'mm') — FK-by-convention
- `variable_name` — unique per project (SIRI items: `item_{id}`)
- `analysis_type` — canonical RE vocabulary
- `construct_id` — FK to survey_constructs (real FK, ON DELETE SET NULL)
- `survey_item_id` — FK to survey_items (real FK, ON DELETE SET NULL)
- `dataset_id` — FK-by-convention to datasets (for uploaded columns)
- `reverse_scored`, `include_in_analysis`, `role`, `storage_type`, `allowed_values`

#### Task 3 — API endpoints

- `GET /api/dev/variable-meta-load.php?project_id=N&project_type=survey`
  Returns all rows + `allowed_analyses` enrichment from taxonomy.
- `POST /api/dev/variable-meta-save.php` `{ project_id, project_type, variables: [...] }`
  Batch upsert. Validates `analysis_type` against controlled vocab. Derives
  `measurement_level` from taxonomy (never from caller). Owner-gated across all
  three project types.

#### Task 4 — `DataMap` component (`apps/studio/data-map.js`)

Standalone JS plug-in. Requires `type-taxonomy.js` loaded first.

```js
DataMap.init({ container, projectId, projectType, rawVars, constructs, onConfirmed })
DataMap.update(rawVars)   // replace raw variable list and reload
DataMap.mount(container)  // remount without resetting state (for step re-renders)
DataMap.isConfirmed()     // true after user clicks Confirm
DataMap.getVariables()    // current classified list
```

Renders: variable name, detected type chip, analysis_type dropdown (grouped optgroups),
role dropdown, construct assignment (for `likert_item`), reverse/include toggles,
suggested analyses column. Filter bar: All / Quantitative / Qualitative / Categorical / Other.
Table capped at 420px with scroll.

`onConfirmed(variables)` fires when user clicks "Confirm data map" — this is the gate
that unlocks the analysis pipeline.

#### Task 5 — Wire D-Studio and I-Studio

**`_analysis_pipelines.php`** — `datamap` step inserted between `overview` and first
work step in both pipelines.

**`_analysis_studio_v4_shell.php`**:
- `type-taxonomy.js` + `data-map.js` script includes added
- `state.datamapConfirmed` + `state._datamapMounted` added
- `renderCenter()` dispatches `mode='datamap'` → `renderDataMap()`
- `renderDataMap()`: first visit calls `DataMap.init()`; return visits call `DataMap.mount()`
- Overview "Continue" routes to Variable Map step
- All work steps gate on `datamapConfirmed=true`

Flow: **Start → Overview → Variable Map → [pipeline unlocked] → analysis → Report**

#### Task 6 — MM Studio data map migration

**`mmstudioV4.php`** — three surgical changes:
1. Script includes for `type-taxonomy.js` + `data-map.js`
2. Three helpers before `renderDataMap`:
   - `mmRoleToAnalysisType(role)` — MM's 10 display roles → canonical analysis_type
   - `mmAnalysisTypeToMmType(at)` — canonical → MM internal type (bridge)
   - `mmBridgeSave(vars)` — on confirmation, writes back to `column_meta` via
     `dmFetch({save:[...]})` so MM's t-test/ANOVA keeps working
3. `renderDataMap` body replaced: fetches columns from `/api/mm/data-map.php`,
   converts `assigned_role` → canonical `analysis_type`, inits standard DataMap inside
   MM's existing `dmHead/dmNav` chrome.

**The bridge is intentional:** DataMap writes to `variable_metadata` (RE infrastructure);
`onConfirmed` also writes back to `column_meta` (keeps MM's analysis pipeline working).
Bridge stays until unified upload/parser is built.

Old 7-tab DM render code preserved as dead code, not deleted.

#### Task 7 — RSSI engine type resolution

**`api/dev/rssi-dataset.php`** — two-tier resolution:

**Tier 1 (preferred):** If `variable_metadata` rows exist for the project (Data Map
completed), use `rssi_field_type_from_analysis(analysisType, constructId)`.

**Tier 2 (fallback):** Projects without saved metadata use the legacy `$FIELD_TYPES`
display-type map. Nothing breaks for existing projects.

Key scoring rule: `likert_item` is only `numeric_scale` (scorable) when `construct_id`
is set. Without a construct it falls to `categorical` — prevents standalone ordinal
items from inflating reliability scores.

New item fields in response: `analysisType`, `reverseScored`, `includeInAnalysis`.
Items with `include_in_analysis=false` from the Data Map are excluded from scorable.
`hasVarMeta` flag in response tells client which tier was used.

#### Task 8 — 360 and TIA studios

**No-op by design.** Investigation found: both 360-wizard.php and tia-wizard.php are
setup wizards only (3-step modals: name + upload + settings → project-snapshot.php).
No analysis workspace exists for either studio. The Data Map belongs in a studio
workspace pipeline that hasn't been built yet. Deferred until 360/TIA workspaces exist.

#### Task 9 — SIRI builder auto-classification

**`api/dev/items-save.php`** — after the existing item save transaction, upserts a
`variable_metadata` row for every saved item:
- `variable_name` = `item_{id}` (stable, never reused)
- `analysis_type` from `rc_analysis_type(displayType, constructId)`
- `construct_id` from `settings.constructId` (constructs save before items → FK valid)
- `storage_type` hint: INT/TEXT/VARCHAR by analysis_type
- `include_in_analysis = 0` for structural items
- Deleted items have their `variable_metadata` rows cleaned up

**Non-fatal guard:** if metadata sync fails, item save still succeeds.

---

## Files changed this session

| File | Change |
|---|---|
| `api/dev/_type_taxonomy.php` | NEW — PHP taxonomy constants + 5 functions |
| `apps/studio/type-taxonomy.js` | NEW — JS mirror; `window.RCTaxonomy` |
| `db/schema_survey_dev_system.sql` | `variable_metadata` table added |
| `api/dev/_dev_common.php` | `variable_metadata` added to `sds_ensure_schema()` |
| `api/dev/variable-meta-load.php` | NEW — GET endpoint |
| `api/dev/variable-meta-save.php` | NEW — POST batch upsert endpoint |
| `apps/studio/data-map.js` | NEW — shared DataMap component |
| `_analysis_pipelines.php` | `datamap` step added to both pipelines |
| `_analysis_studio_v4_shell.php` | DataMap wired; script includes; gate added |
| `mmstudioV4.php` | `renderDataMap` replaced; bridge helpers added |
| `api/dev/rssi-dataset.php` | Two-tier type resolution; new item fields |
| `api/dev/items-save.php` | `variable_metadata` auto-populated on item save |

---

## Commits this session (in order)

1. `f38ebe7` — Add RE type taxonomy: _type_taxonomy.php + type-taxonomy.js
2. `a6f47fb` — Add variable_metadata table to schema + sds_ensure_schema
3. `8411f37` — Add variable-meta-load + variable-meta-save endpoints
4. `63d2681` — Add DataMap component (apps/studio/data-map.js)
5. `c132987` — Wire Data Map into D-Studio and I-Studio
6. `aacdf48` — Replace MM Studio data map with standard DataMap component
7. `45b3c8a` — Update RSSI engine: prefer variable_metadata over display-type map
8. `831f294` — Assign analysis_type in SIRI builder (items-save.php)

---

## RE infrastructure — remaining items

The original RE infrastructure list had 5 items. Item 1 (Unified type taxonomy) is now
complete. Remaining:

### Item 2 — Unified data upload/parser
Every studio currently has its own upload mechanism:
- D/I: `analysis-upload.js` + `datasets` table
- MM: `evidence-intake.js` → `datasets` table (via browser-to-server fix)
- RSSI standalone: `rssi-upload.js`
- SIRI: no upload (instrument builder, not data receiver)

Goal: one upload path that detects column types, maps them to `analysis_type`,
and populates `variable_metadata` in a single pass. All studios call the same
intake. This is a substantial build.

### Item 3 — Unified project table
Currently: `survey_projects` (SIRI) / `analysis_projects` (D/I) / `mm_projects` (MM).
`variable_metadata` uses `project_type` FK-by-convention to span all three.
Goal: one project table that all studios reference, so a single project can move
through SIRI → analysis studios without re-linking.

### Item 4 — Unified export
Each studio exports separately. Goal: one export system that produces a structured
data package (responses + variable_metadata + scores) from any project type.

### Item 5 — Wire RE connections
Connect the SIRI Journey steps 02–06 and the RSSI Journey to the live engines.
Currently: step-rail apps are high-fidelity mockups with sample data (apps/journey/).

---

## Key architectural facts for next session

**Data Map flow per studio:**
- **D/I:** rawVars built from `dataset.variables` at dataset load. `projectType='analysis'`.
- **MM:** rawVars built from `/api/mm/data-map.php` column list. `projectType='mm'`.
  Bridge in `onConfirmed` writes back to `column_meta` for MM's analysis pipeline.
- **SIRI items:** auto-populated to `variable_metadata` on every `items-save.php` call.
  The Data Map in D/I can load these if the project is a SIRI project linked by dataset.

**RSSI engine:** now reads `variable_metadata` when present, falls back to display-type
map. `hasVarMeta=true` in response means canonical types were used. The `reverseScored`
flag is now in item records but not yet applied by the scoring engine — that's a
future scoring enhancement.

**360 and TIA:** wizard-only, no analysis workspace. When workspaces are built, wire
Data Map exactly like D/I: add `datamap` step to pipeline, call `DataMap.init()` with
uploaded columns, `projectType='mm'` or a new `'360'`/`'tia'` type.

---

## Standing rules (unchanged)

- **Auto-deploy is LIVE** — saving a file uploads to prod in ~15s.
- **Never rename `sdsi` code** — internal engine namespace stays as-is.
- **RE build principles** — build outside first; rules before plugging.
- **MM Studio** — V4 only. Edit with care (RE infrastructure rollouts only).
- **No em dashes** in user-facing copy.
- **AI = ReliCheck Intelligence** in user-facing copy.
- **Logo height** — 70px for all studio long wordmarks.
