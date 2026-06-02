# ReliCheck — Claude Code project instructions

Read this fully before doing anything else.

---

## The ecosystem principle (this keeps getting dropped — hold it)

ReliCheck is an ecosystem of connected but independent sub-systems. Users can enter and exit at any point. There is no forced pipeline. Valid entries: SIRI only, D/I Studio directly with a CSV, standalone RSSI, full path SIRI → RSSI → D/I → MM — all equally valid.

The unified infrastructure (upload widget, type taxonomy, rc_projects table, variable_metadata) exists to make free movement possible without losing continuity. Data uploaded anywhere lands in the same format, classified the same way, and can be picked up by any other part of the system.

**The upload widget is the entry gate.** If it works differently depending on where you enter, the ecosystem breaks at the first step. This is not a UX preference. It is a structural requirement.

---

## Auto-deploy warning

Saving any file in this repo auto-uploads to live production in ~15 seconds. GitHub is the safety net. Never reset, clean, stash, or switch branches without explicit user approval.

---

## Current state of the upload widget

All three studio entry points use `apps/studio/dataset-upload.js` — `DatasetUpload.open()`. One widget, one modal, same look everywhere.

- **D/I Studio** — working, verified
- **MM Studio** — `mmStartUpload()` in mmstudioV4.php calls `DatasetUpload.open({ projectType:'mm' })`. Widget creates the mm_projects row and links the dataset. **Verify in browser before assuming it works.**
- **RSSI journey app** — "Upload your data" button calls `DatasetUpload.open({ projectType:'rssi' })`. Widget creates the dataset and redirects to `?dataset_id=N`. **Verify in browser before assuming it works.**

If MM or RSSI upload looks wrong or fails: check the browser console first before touching code.

There is no mm-wizard.php. It was deleted. Do not recreate it.

---

## RE Infrastructure build order and status

| Item | Status |
|---|---|
| 1 — Unified type taxonomy | COMPLETE |
| 2 — Unified data upload widget | COMPLETE |
| 3 — Unified project table (rc_projects) | COMPLETE |
| 4 — Unified export | NOT STARTED — next |
| 5 — Wire RE connections | NOT STARTED |

Build order is locked. Item 4 before Item 5. Define rules before building anything.

---

## How the upload widget routes

In `apps/studio/dataset-upload.js` `attach()`:

- `ctx.projectId` set → link dataset to existing project
- `projectType === 'rssi'` → return datasetId directly (no project record)
- `projectType === 'mm'`, no projectId → create mm_projects row, link dataset, return projectId
- Otherwise → create analysis_projects row, return projectId

---

## Key files

- Upload widget: `apps/studio/dataset-upload.js`
- MM Studio: `mmstudioV4.php` (edit with caution — production file)
- RSSI journey app: `rssi-app.php` + `apps/journey/journey-rssi.js`
- D/I studio shell: `_analysis_studio_v4_shell.php`
- Ecosystem project helper: `api/_rc_projects.php`
- Dataset seeding: `api/_dataset_helpers.php`

---

## Coding rules

- No em dashes in user-facing copy
- AI features = "ReliCheck Intelligence" in user-facing copy
- No paste option in upload widget — file only
- Types auto-detected silently on upload; DataMap is where the user confirms
- Build outside first: ask "unique to this app?" before building anything new
- Define rules before wiring any stat or shared function into a system
- MM Studio edits allowed only for deliberate RE infrastructure rollouts
