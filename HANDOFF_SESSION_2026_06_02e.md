# Session Handoff — 2026-06-02 (very late evening)

## The ecosystem understanding — READ THIS FIRST

ReliCheck is an **ecosystem of connected but independent sub-systems**:

**SIRI (pre-data) → RSSI (post-data, optional) → Studios (deep analysis, optional)**

Users can enter and exit at any point. There is no forced pipeline. The unified RE infrastructure (upload widget, type taxonomy, project table, variable_metadata) is what makes that freedom possible without losing continuity. When data enters anywhere, it lands the same way, is classified the same way, and can be picked up by any other part of the ecosystem.

---

## What this session accomplished

### RE Infrastructure Item 3 — Unified project table

**Committed `1bc20cc`** — rules defined and built:

**New files:**
- `api/_rc_projects.php` — 4 helpers:
  - `rc_ensure_project_schema(PDO $pdo)` — creates `rc_projects` table + adds `rc_project_id BIGINT NULL` to survey_projects, analysis_projects, mm_projects, variable_metadata (idempotent, SHOW COLUMNS guarded)
  - `rc_create_project($pdo, $uid, $title, $description)` — INSERT rc_projects, returns id
  - `rc_set_project_dataset($pdo, $rcProjectId, $datasetId)` — UPDATE rc_projects.dataset_id (non-fatal)
  - `rc_project_id_for_studio($pdo, $table, $studioId)` — SELECT rc_project_id from a studio row (null = legacy)
- `db/schema_rc_projects.sql` — canonical reference SQL (also usable as a manual migration via phpMyAdmin)

**Modified files:**
- `api/_dataset_helpers.php` — `rc_seed_var_meta_from_dataset()` gains optional `$rcProjectId = null` param; when non-null, a follow-up UPDATE stamps `rc_project_id` on the seeded rows
- `api/dev/project-create.php` — requires `_rc_projects.php`; calls `rc_ensure_project_schema` before transaction; creates rc_projects row + links it to survey_projects inside the existing transaction
- `api/analysis/projects.php` — requires `_rc_projects.php`; calls `rc_ensure_project_schema`; POST creation now wrapped in transaction: studio INSERT + rc_projects INSERT + rc_project_id UPDATE + optional rc_projects.dataset_id set
- `api/mm/projects.php` — same pattern as analysis
- `api/analysis/link-dataset.php` — requires `_rc_projects.php`; after linking, looks up rc_project_id and calls `rc_set_project_dataset`; passes rcId to seeder
- `api/mm/link-dataset.php` — same pattern as analysis link-dataset

---

## RE Infrastructure status

| Item | Status |
|---|---|
| 1 — Unified type taxonomy | COMPLETE |
| 2 — Unified data upload/parser | COMPLETE |
| 3 — Unified project table | COMPLETE (committed `1bc20cc`) |
| 4 — Unified export | NOT STARTED — NEXT |
| 5 — Wire RE connections | NOT STARTED |

---

## RE Infrastructure Item 4 — Unified export (next)

**The problem:** Each studio produces data in a different format with no shared export infrastructure:
- D/I Studio: `analysis_results` rows (JSON snapshots of RELICHECK_APP_STATE)
- MM Studio: `mm_reports` (HTML body + summary_json) + `mm_structured_datasets`
- SIRI: SDSI/SIRI/RSSI review JSON in their respective tables

Export types needed: PDF, Word (.docx), XLSX, JSON. Each studio needs to export its project content in a consistent format that can be read by the ecosystem.

**Approach is still to be designed.** Same rules-before-building principle applies.

Candidate approach: a shared `api/_rc_export.php` helper that knows how to render content for each studio type, plus a single `api/export.php` endpoint that routes by studio type and export format. The `/skill anthropic-skills:docx` and `/skill anthropic-skills:xlsx` skills are available for the Word and Excel parts.

---

## Architecture: what rc_projects looks like

```sql
rc_projects (
    id          -- ecosystem project identity
    user_id     -- owner (FK → users, ON DELETE CASCADE)
    title       -- canonical display title
    description -- optional narrative
    dataset_id  -- canonical dataset FK-by-convention
    status      -- active | archived
    created_at, updated_at
)
```

Each studio table now has:
- `survey_projects.rc_project_id BIGINT NULL` — linked to rc_projects.id
- `analysis_projects.rc_project_id BIGINT NULL`
- `mm_projects.rc_project_id BIGINT NULL`
- `variable_metadata.rc_project_id BIGINT NULL`

All existing rows have `rc_project_id = NULL` (legacy — still fully functional). Only new projects created after this commit get rc_project_id set.

**Rules (locked):**
1. rc_projects.title is the canonical display title (set at creation; sync on rename is Item 5)
2. rc_projects.dataset_id is the canonical dataset (updated by every link-dataset endpoint)
3. Creation is transactional: studio INSERT + rc_projects INSERT + rc_project_id UPDATE in one transaction
4. rc_projects.user_id MUST equal the linked studio row's user_id
5. Deleting a studio row does NOT cascade to rc_projects

---

## Key architectural facts (unchanged from previous handoff)

### Upload widget (`apps/studio/dataset-upload.js`)
- **`DatasetUpload.open(ctx)`** — full modal: title + description + file picker, single page, no confirm step.
- **`DatasetUpload.embed(container, ctx)`** — inline version for wizard use.
- `ctx = { kind, projectId, projectType, title, onLoaded(null, pid) }`.
- Widget injects its own `du-*` CSS on load — no separate stylesheet needed.

### MM Studio wizard (mm-wizard.php)
- Now 2 steps only: Step 1 = title + description (creates MM project), Step 2 = upload via `DatasetUpload.embed()`.
- On upload complete: redirect to `mmstudioV4.php?project_id=N`.

### MM Studio Study Setup step
- Rail label: "Study Setup", mode: `study_design`.
- 3 tabs: Data Kind / Intent / Design.
- Save posts all three to `/api/mm/wizard.php`.

### Studio template contract (7 parts, locked)
Every analysis studio: Start → Overview → Variable Map (DataMap gate) → Analysis steps → Report → Uniform header + footer.

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
