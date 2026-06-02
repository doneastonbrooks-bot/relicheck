# Session Handoff — 2026-06-02 (end of session)

## READ THIS FIRST — the ecosystem principle

ReliCheck is an ecosystem of connected but independent sub-systems. Users enter at any point — SIRI, RSSI, D/I Studio, MM Studio — with no forced pipeline. The unified infrastructure exists to make that free movement possible. The upload widget is the entry gate. It must look and work identically everywhere. This principle keeps getting dropped in implementation. Hold it.

---

## Current state of the upload widget

### What works
- **D/I and I/S Studios** — `DatasetUpload.open()` fires correctly. Shows: title field, description field, file drop zone, Upload Data button. Correct.

### What was broken this session (and the fix committed)
The upload widget (`apps/studio/dataset-upload.js`) uses CSS classes `au-overlay`, `au-panel`, `au-btn`, `au-close`, `au-msg`. These classes were only defined in `apps/analysis-studio/analysis-studio.css`, which only the D/I/I-S studio pages load. MM Studio and RSSI journey app do not load that stylesheet, so the modal rendered broken.

**Fix committed `ab63c08`**: widget now injects its own `au-*` base styles. It is fully self-contained. The modal should now look identical on every page.

### What needs to be verified in the browser (not done — session ended)

1. **MM Studio** — go to `mmstudioV4.php`, click "Bring in your data" on the Start screen. Should open the same upload modal as D/I. Upload a CSV. Should create an MM project and redirect to the studio with data loaded.

2. **RSSI journey app** — go to `rssi-app.php` (no ?dataset_id), click "Upload your data". Should open the same upload modal. Upload a CSV. Should redirect to `rssi-app.php?dataset_id=N` and load the scoring.

If either still looks wrong or fails, check the browser console for errors before touching any code.

---

## What changed this session (commits in order)

| Commit | What |
|---|---|
| `1bc20cc` | RE Item 3: rc_projects unified project table |
| `72981f3` | Unify upload: MM wizard → DatasetUpload.open(); RSSI custom stack → DatasetUpload.open() |
| `75828e6` | Delete mm-wizard.php; MM Studio creates projects via DatasetUpload.open() inline |
| `ab63c08` | Fix: inject au-* CSS so widget works on MM/RSSI pages (the actual visual fix) |

---

## How the upload widget now routes by context

`apps/studio/dataset-upload.js` `attach()` function:

| Condition | What happens |
|---|---|
| `ctx.projectId` is set | Links dataset to existing project via link-dataset.php; returns projectId |
| `ctx.projectType === 'rssi'` | Returns datasetId directly (no project to link) |
| `ctx.projectType === 'mm'`, no projectId | Creates mm_projects row, links dataset, returns new projectId |
| Otherwise (D/I with no projectId) | Creates analysis_projects row, returns new projectId |

---

## MM Studio — no wizard

`mm-wizard.php` is deleted. New MM project flow:
1. Go to `mmstudioV4.php` (no project_id)
2. Start screen shows "Bring in your data" button
3. Button calls `mmStartUpload()` → `DatasetUpload.open({ projectType:'mm' })`
4. User fills title + description + uploads file
5. Widget creates mm_projects row + links dataset
6. Redirects to `mmstudioV4.php?project_id=N`

---

## RE Infrastructure status

| Item | Status |
|---|---|
| 1 — Unified type taxonomy | COMPLETE |
| 2 — Unified data upload/parser | COMPLETE (widget self-contained as of ab63c08) |
| 3 — Unified project table (rc_projects) | COMPLETE |
| 4 — Unified export | NOT STARTED |
| 5 — Wire RE connections | NOT STARTED |

---

## Standing rules

- Auto-deploy is LIVE — saving a file uploads to prod in ~15s
- Never rename sdsi code
- MM Studio (mmstudioV4.php) — edit with caution, RE infrastructure rollouts only
- No em dashes in user-facing copy
- AI = ReliCheck Intelligence in user-facing copy
- No paste option in upload widget — file only
- No column confirm step — types auto-detected silently; DataMap is the confirmation step
