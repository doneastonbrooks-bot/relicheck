# Handoff — MM Studio qualitative pipeline + Qualitative Studio slice 1

**Date:** 2026-06-01 · **Branch:** `main` · **HEAD:** `2e852f4` · working tree clean.
Test project throughout: `relichecksurvey.com/mmstudioV4.php?project_id=189` ("Test of MM Studio Validate", Explanatory Sequential).

> ⚠️ **AUTO-DEPLOY:** saving a file uploads to LIVE prod in ~15s. The DB is remote prod — **nothing here was runtime-tested**, only `php -l` + JS-parse verified. Live proof = exercising each feature on prod. See [[project_autodeploy_danger]].
> ⚠️ **NEVER edit** `_mm_pipelines.php` (pipeline is locked — that's why new steps live inside existing renderers), mm-wizard, studio-mm*. `mmstudioV4.php` + `api/mm/*` edits are OK **because the user explicitly directed MM Studio work this session**.

## ⛳ ACTION ITEMS FOR THE USER (do before relying on features)
1. **Run migration on prod:** `db/schema_value_labels.sql` (creates `mm_value_labels`). Until then, value labels won't persist (the "Save value labels" button reports the migration is needed; everything falls back to `SELTraining=0` form — no breakage).
2. **Report-notes table:** Steps 8 (Sampling Plan) & 11 (Explanation Map) persist to `mm_report_section_notes` (schema_phase164.sql). If that table isn't on prod, those saves show a "needs migration" note and won't persist — confirm it exists.
3. **Reconcile duplicate themes:** project 189 has BOTH a manual theme set (underscore names, e.g. `Barriers_Time`) AND an RI-discovered set (prose names). Use the ⋯ menu → **Remove** the duplicates down to ONE clean set, then re-tag. This is the root of the "manual themes 0%" and "inconsistent label format" findings.
4. **Value labels:** on the t-test setup, expand "Label the values of SELTraining" → set 0="No Training", 1="Attended Training", Save. Then re-open Step 11 and re-Save so the Integration note refreshes with labels.

## What was built this session (16 commits, all on main)
**Quant→Qual pipeline wiring (Explanatory Sequential):**
- `76a0555` Step 7 Identify Results to Explain (`renderExplain`)
- `e76a301`/`f362d55` Step 8 Qualitative Sampling Plan (`renderQualSampling`) → persists to report **Methods** notes
- `f207371` Step 11 Quant→Qual Explanation Map (`renderExplainMap`) → persists to report **Integration** notes. **Qual pipeline now fully wired:** q_desc · q_inf · @explain · qual_sampling · l_themes · l_book · explain_map · joint · converge.

**Qualitative Themes step (`l_themes`) overhaul:**
- `07f1f36`/`6a0ca80` Semantic theme tagging — NEW `api/mm/code-existing-semantic.php` (Claude codes full set vs EXISTING themes; never creates themes). Hardened after a review workflow (name-match normalization, atomicity, batch truncation). `106aaee` recalibrated prompt for under-coding.
- `8d4fe0c` "Clear tags" (NEW `api/mm/clear-tags.php`) + AI re-tag available after tagging.
- `53aea01` Per-theme ⋯ menu: rename / merge / remove (reuses `categories.php`; merge now keeps a description).
- `963e437` Guard: whole-project AI **Discovery** refuses to add a 2nd theme set on top of existing themes unless `force` (server guard in `build.php`).
- `f76eaf2` **Manual per-response coding workspace** ("✎ Code by hand" toggle; NEW `api/mm/coder-responses.php`; edits reuse `coder-set-coding.php`). Qual Studio slice 1.

**Cross-cutting fixes:**
- `be5a6b0` 0/1 group label prefix + regression "Add to Results to Explain" button.
- `11652a6` **PHP session-lock fix** — `release_session_lock()` in `_session.php`, called in long AI endpoints (`build.php`, `code-existing-semantic.php`, now `alignment.php`). See [[project_session_lock_gotcha]]. **RULE: every new AI/slow endpoint must call it.**
- `f455d33` **Value-label support** — NEW `db/schema_value_labels.sql` + `api/mm/value-labels.php`; server emits `BOOT.valueLabels`; client `vlabel()`/`vlabFmt()` substitute in qsReadFinding/renderExplain/addToExplain/t-test dropdowns; editor on t-test setup. Render-time substitution → fixes EXISTING staged findings on re-render. See [[project_value_labels]].
- `cd31b4f` Joint display **manual quote entry** (`set_quote_manual` action, no migration — reuses `quote_text`) + low-tagging warning banner.
- `2e852f4` Convergence **"Suggest alignment"** rewritten to use theme-level joint-display evidence (was requiring per-response scores that don't exist) — `alignment.php` rewritten, no longer persists.

## Validation status (user is walking the studio end-to-end)
Working & validated by user: Step 7, Step 8, Step 11, semantic tagging (coverage populates but was under-counting → recalibrated, NEEDS RE-TEST), per-theme menu, manual coding workspace, joint display manual quotes, Convergence alignment (just fixed, NEEDS TEST). Next step the user was about to reach: **Convergence & Divergence** review, then onward (Meta-inferences, Integrated Interpretation, Evidence Strength, Report Builder — those were built earlier per memory).

## Recurring root causes (not new bugs — pending user actions)
- **SELTraining=0/1 everywhere** → value labels (now built; run migration + set labels) OR re-upload text. See [[project_value_labels]].
- **Duplicate themes / inconsistent naming / manual themes 0%** → reconcile to one theme set (⋯ Remove).
- **Low/under coverage** → tag more (semantic re-tag recalibrated; or manual "Code by hand").

## Next Qualitative-Studio slices (NOT built; user approved the studio direction)
Per the capability audit ([[project_qual_studio]]): full demographic hydration (respondent_ref→dataset row), dual-coder disagreement view + kappa (infra exists: `coder-invite-*`, `intercoder_phase181.sql`, kappa in `trustworthiness.php`), semantic free-text search (net-new, needs embeddings), saturation tracking, per-coding memos.

## How to verify any change
`php -l <file>` + extract the `<script>` block and `new Function(js)` to parse-check (see prior commits). Then ask the user to exercise it on prod (can't test the remote DB locally). Commit each finished unit (don't leave files untracked — auto-deploy + Dropbox loss risk).

## Read first
Memory index `MEMORY.md`, especially: [[project_qual_studio]], [[project_semantic_tagging]], [[project_value_labels]], [[project_session_lock_gotcha]], [[project_explain_step_gap]], [[feedback_never_touch_mm_studio]], [[project_autodeploy_danger]]. Plus repo `HANDOFF_MM_STUDIO_V4.md`.
