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

## LOCKED: Writing Quality stem-gate (accepted 2026-06-05 — do not change without approval)

**The hard rule:** If an item is not written as a respondent-facing, answerable prompt, **Writing Quality cannot show "Strong".**

Two axes, kept separate:
1. **Stem Quality (question writing)** — is it an answerable question with a clear construct and clear wording? Label-only / coded / metadata / fragment / instruction-only stems (e.g. "Department", "Tenure", "Role Level", "Start Date", "Respondent ID", "Q1") FAIL this. **Stem Quality drives the survey-level Writing Quality band.**
2. **Response Fit** — judged ONLY after the stem is interpretable: does the response format match what the (clear) question asks?

How it is wired (in `apps/sdsi/buildcheck-engine.js`, used by `developV2.php`):
- "Needs question text" items are counted by their stem-quality flags — `STEM_QUALITY_FLAGS` = `stem_item_code`, `stem_demographic_label`, `stem_construct_label`, `stem_metadata_field`, `stem_not_answerable`, `stem_verb_fragment` (plus the `response_fit_status === 'cannot_assess'` echo).
- Band tiers: **any → cannot be Strong (cap Good); 25% → Caution / "Needs revision"; 40% → Not ready / "Question text needed"; 60% → Assessment blocked.**
- The engine exposes `writing_quality_band`, `writing_quality_can_show_strong`, `writing_quality_needs_text_count`. **The gauge MUST use these, never the raw numeric score.**
- Item-card marks, left-rail dots, the summary count ("N need question text"), the gauge, the SIRI card, and the review drawer must all tell the same story.

**Do not change the writing-quality gates/thresholds, the label-stem (STEM_QUALITY_FLAGS) counting, or the "needs question text" handling without explicit approval.** These are shared SDSI/SIRI engines — see "Don't touch the Survey Dev app from other apps" in memory. (Reference: branch `developv2-response-fit-check`, commit `01fd15b`.)

Note: `developV2.php` inline JS caches — hard-refresh (Cmd+Shift+R) to see new builder logic on the live site.

---

## Upload widget — universal rule

Every studio uses `apps/studio/dataset-upload.js` — `DatasetUpload.open({ projectType, ... })`. One widget, one modal, same file formats, same look. No exceptions. No studio builds its own upload path.

Supported formats: CSV, TSV, XLSX, JSON, Qualtrics exports, Google Forms exports.

| Studio | projectType | Entry |
|---|---|---|
| MM Studio | `'mm'` | `mmstudioV4.php` |
| RSSI App | `'rssi'` | `apps/journey/journey-rssi.js` |
| Qual Studio | `'qual'` | `apps/qual/qual-studio.js` |
| Survey Dev System | `'survey'` | `develop.php` |

If upload looks wrong or fails in any studio: check the browser console first before touching code.

There is no mm-wizard.php. It was deleted. Do not recreate it.

---

## How the upload widget routes

In `apps/studio/dataset-upload.js` `attach()`:

- `projectType === 'rssi'` → return datasetId directly (no project record)
- `projectType === 'survey'` → `api/dev/link-dataset.php` → sets `survey_projects.dataset_id`
- `projectType === 'qual'`, no projectId → create qual project, then `api/qual/link-dataset.php`
- `projectType === 'qual'`, with projectId → `api/qual/link-dataset.php` directly
- `projectType === 'mm'`, no projectId → create mm_projects row, then `api/mm/link-dataset.php`
- `projectType === 'mm'`, with projectId → `api/mm/link-dataset.php` directly

All link endpoints call `rc_seed_var_meta_from_dataset()` to seed `variable_metadata`.

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

## Key files

- Upload widget: `apps/studio/dataset-upload.js`
- MM Studio: `mmstudioV4.php` (edit with caution — production file)
- RSSI journey app: `rssi-app.php` + `apps/journey/journey-rssi.js`
- Qual Studio: `qual-studio-workspace.php` + `apps/qual/qual-studio.js`
- Survey Dev System: `develop.php` + `api/dev/link-dataset.php`
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
