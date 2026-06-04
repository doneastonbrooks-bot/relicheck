# MM Studio — assessment + handoff (2026-06-05)

_Read this first. Supersedes the status portions of `HANDOFF_MM_STUDIO_V4.md` (that doc is still valid for build history and conventions)._

The goal of this handoff: confirm whether MM Studio (`mmstudioV4.php`) is a correct, complete build **before it is used as the template to redesign the other studios** (starting with the Claude/Descriptive studio).

---

## VERDICT (read this)

**The LIVE studio works. The LOCAL repo + GitHub copy is INCOMPLETE.** The entire quantitative-analysis half of MM Studio runs on production, but its engine files are **missing from the local working tree and untracked in git** — they exist on the Ionos prod server only. So today MM Studio is *not* safely restorable or templatable from the repository.

**Do NOT start templating the other studios until the missing endpoints below are pulled down from prod into git.** If prod is ever lost (or local is restored from GitHub), the quantitative side of MM Studio is gone, and any studio templated from the repo would inherit broken quant calls.

Everything else — the 2026 facelift, the qualitative pipeline, integration/report wiring — is in good shape and verified this session.

---

## 1. CRITICAL GAP — quantitative endpoints are prod-only

`mmstudioV4.php` calls these under `/api/mm/`. None exist in the local repo, none are in git:

| Endpoint (called as) | Used by | Local file? | In git? |
|---|---|---|---|
| `/api/mm/ttest.php` | renderTTest | **missing** | no |
| `/api/mm/anova.php` | renderANOVA | **missing** | no |
| `/api/mm/chisquare.php` | renderChiSquare | **missing** | no |
| `/api/mm/correlation.php` | renderCorrelation | **missing** | no |
| `/api/mm/regression.php` | renderRegression | **missing** | no |
| `/api/mm/reliability.php` | renderReliability | **missing** | no |
| `/api/mm/descriptives.php` | renderDescriptive (Means/Freq/Cross-tabs) | **missing** | no |
| `/api/mm/results-to-explain.php` | Identify Results to Explain pivot, Qual Sampling, Explanation Map | **missing** | no |

Note: there ARE `anova.php` / `correlation.php` / `regression.php` / `reliability.php` at the **repo root**, but they are unrelated — they are the **Inferential Studio** mount pages (`$mount_app='inferential'`), not the MM `/api/mm/` engines. Name collision only.

Why the live studio still works: auto-deploy is one-way (local save → prod). These endpoints were built + deployed in an earlier session (the old `HANDOFF_MM_STUDIO_V4.md` records `api/mm/ttest.php` as "fully wired, verified live"), then lost locally in the 2026-05-31 Dropbox revert. Prod kept its copies; local never got them back.

**Action for next session (do this FIRST):**
1. On the live site, confirm the quantitative analyses still run (t-test, ANOVA, chi-square, correlation, regression, reliability, descriptives) on a project with data — e.g. project 24 (Team Climate, 36 responses).
2. Download those 8 files from the Ionos server (`/api/mm/`) into the local repo and `git add` + commit them, so the repo is whole and GitHub is a real backup.
3. If any of them do NOT run live, they must be rebuilt (they reuse `api/_stats.php` + `api/_mm.php`, both present locally).
4. Re-run the endpoint cross-check to confirm zero gaps:
   `grep -oE "/api/mm/[a-z-]+\.php" mmstudioV4.php | sort -u` vs `ls api/mm/`.

---

## 2. SECONDARY GAPS (lower risk, note for completeness)

- **`merge` pivot has no native view.** The "Merge & Compare" pivot step (Convergent design only) has no `renderCenter` case, so it falls through to the generic placeholder ("this workstation is a new build, not yet wired"). Convergent users hit a stub between the strands and the Joint Display. Either build it or repoint it. (Contrast: the other two pivots, `explain` → renderExplain and `q2q` → renderQ2Q, are built.)
- **Two steps defined but wired into no design:** `l_exemp` (Exemplar Quotes) and `q_instr` (Instrument Quality). They have AHELP entries and no renderer. Exemplar Quotes overlaps with Joint Displays' quote-picking; Instrument Quality is the quant parallel to Trustworthiness (reliability referenced from RSSI). Decide whether to wire or delete.
- **Theme by Group (`l_bygroup`)** was an empty placeholder until this session; it is now built (theme × group coverage matrix) but **not yet live-verified** with real data.

---

## 3. WHAT THIS SESSION CHANGED (all committed + auto-deployed)

The 2026 prototype facelift + a series of fixes. Commits `a9a340b` → `bbd2c04`:

- **Facelift**: applied the approved `mm-studio-prototype.html` design wholesale (indigo `#5552f6`, 4-row grid: RC header / 76px topbar / sidebar+main / footer; topbar step rail; sidebar with Study Design switcher + Researcher's Notes; slide-in Coach; Report drawer).
- **Five prototype-match corrections**: header height, sidebar width (300px), Coach tab stays visible (rides panel edge), Coach content rebuilt to prototype (Step-N-of-M chip, Guidance, Common questions, Ask box), Report drawer overlap (mutually exclusive with Coach).
- **Report drawer** wired to the real saved report (`report.php`) — reads sections on open; "Add to Report" persists to Findings; Export opens Report Builder.
- **Data Map**: removed duplicate header (component owns the single "Variable Map" header); compacted the table (MM-scoped CSS) so all 7 columns fit; kept Construct column (functional for Likert).
- **Design switch** always returns to step 1 (was jumping to first strand step).
- **Purple buttons** unified to the Study Design flat-indigo treatment.
- **Footer nav** buttons now name the prev/next step (via `navFooter()`), pipeline-aware.
- **Analysis tool tabs** restored (the facelift had hidden the palette): persistent `#qTabsBar` (tabs) + `#qFootBar` (save + nav) for `q_desc` and `q_inf`, so ANOVA/Chi-square/etc. are reachable again and every inferential test has a footer + Save-to-report. Renamed `q_inf` "Quantitative Results" → "Quantitative Inferential".
- **Qualitative order fixed** across all 3 designs → Themes → Codebook → … → Trustworthiness (added Codebook to Convergent, Trustworthiness to Explanatory, moved it after coding in Exploratory). Built the missing **Theme by Group** view.
- **Themes empty state** now explains where qual data comes from (Data Map / Upload) with buttons.
- **Qual materialization fix** (`api/mm/data-map.php` + `reingest-dataset-text.php`): trust the user's explicit Open-ended classification — materialize any `type=='open'` column with text, instead of re-applying an `avg>20 chars && >12 distinct` heuristic that silently dropped small samples (≤12 respondents) / short answers. **User-verified working this session.**
- **Discover themes** no longer hangs: `build.php` gained a `discover_only` mode that returns after Pass 1 (theme discovery) instead of blocking through Pass 2 (full batched coding). Coding stays the separate "Tag responses" action.
- **Contextual Lens** (Context / Voice / Counter-Pattern / Consequence + divergence flag) extracted into reusable helpers (`clPanel`/`clCompose`/`clFold`) and added to **Convergence & Divergence** so it's available in all 3 designs (was Explanatory-only). Folds into the per-theme saved note.

---

## 4. VERIFIED vs NOT VERIFIED

- **Verified live by the user this session**: Data Map classification, qual materialization (open-ended columns now reach Themes), the empty-state path, Discover (the hang is the design issue now fixed).
- **Verified structurally only** (php -l + node --check on the extracted inline JS, every commit; NOT run against the remote DB): the facelift, tool tabs, footer nav, Theme by Group, Contextual Lens, Report drawer wiring.
- **NOT verified at all this session**: the quantitative analyses end-to-end (because of §1 — the endpoints aren't local; they must be exercised on the live site).

There is **no local DB** — the production DB is remote (Ionos). All live verification is the user's.

---

## 5. ENVIRONMENT DANGERS (unchanged, still true)

- **Auto-deploy**: saving any file uploads it to LIVE prod in ~15s. There is no separate deploy step. (Dropbox *sync* is off, but auto-deploy is independent and still live.)
- **GitHub is the safety net, not Dropbox.** Commit every finished unit. NEVER `git reset --hard` / `clean` / `stash` / switch branches without explicit user OK — untracked files are live and fragile (see the §1 loss).
- **Prod has files local does not** (the §1 endpoints; also `descriptive-analysis-workspace.php` / `analysis-studio.js` are server-only stubs in git). Treat the repo as a partial mirror until §1 is resolved.
- Can't test against the DB locally.

---

## 6. TEMPLATING THE OTHER STUDIOS — guidance

MM Studio's facelift is the intended visual + structural template:

- **The look** lives in `mmstudioV4.php`'s `<style>` block (the prototype CSS) — indigo tokens, the 4-row grid, topbar step rail, sidebar, Coach slide-in, Report drawer, `navFooter()`, the segmented tool-tabs (`.q-tooltabs`).
- **Reusable cross-studio pieces already exist** under `apps/studio/`: `dataset-upload.js`, `studio-header.js`, `studio-footer.js`, `data-map.js` (Variable Map — LOCKED core step), `contextual-lens.js`, `type-taxonomy.js`. Reuse these; do not fork them.
- **Honor the conventions** in `HANDOFF_MM_STUDIO_V4.md` §4 (ReliCheck Intelligence is the labeled, never-first AI; purple = `--indigo` only where the studio already uses purple; no em dashes; one upload widget).
- **Before templating: resolve §1.** Pull the quant endpoints into git so MM Studio is a complete, restorable reference. Templating from an incomplete reference will propagate the gap.

### Suggested first move for the next session
1. Confirm + recover the §1 endpoints (live check, download, commit).
2. Live click-through of MM Studio on a real project per design (Convergent / Explanatory / Exploratory): walk every step, confirm each renders real data, fix any step that errors.
3. Only then begin the Claude/Descriptive studio redesign, reusing the MM facelift CSS + shared `apps/studio/` components.

---

## Key files
- `mmstudioV4.php` — the whole studio (PHP shell + inline JS; all render fns).
- `_mm_pipelines.php` — step/design definitions (`$SETUP`, `$TOOLS`, `$CONCLUDE`, `$DESIGNS`, `$PIVOTS`).
- `api/mm/*` — endpoints (note §1 gap). Shared libs `api/_mm.php`, `api/_stats.php` present.
- `apps/studio/*` — shared cross-studio components.
- `mm-studio-prototype.html` — the approved design source.
- `HANDOFF_MM_STUDIO_V4.md` — prior build history + conventions (still valid).
