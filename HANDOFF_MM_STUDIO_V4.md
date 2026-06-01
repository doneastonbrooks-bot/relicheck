# HANDOFF — MM Studio v4 (read this first)

_Last updated: 2026-06-01. Branch: `recover/mm-studio-v4`._

---

## ⚠️ 0. ENVIRONMENT DANGERS — read before touching anything

1. **This repo lives in Dropbox AND saved files auto-upload to LIVE production (Ionos) in ~15 seconds.**
   Editing/saving a file = deploying it. There is no separate "deploy" step. The pre-commit hook documents this.
2. **Dropbox previously reverted the whole repo, including `.git`, mid-session** (2026-05-31): it rewound to an old snapshot, erased the branch + commits, deleted ~269 untracked files, and reverted tracked files. RECOVERED from the Dropbox local cache (`/Volumes/Doc Drive/Dropbox/.dropbox.cache/old_files/`) + the old stash.
   **FIX APPLIED:** `.git` is now excluded from Dropbox sync — `xattr -p com.dropbox.ignored .git` should return `1`. Verify it still does. If not, re-run `xattr -w com.dropbox.ignored 1 .git`.
3. **RULES:** NEVER run `git reset --hard`, `git clean`, `git stash`, `git checkout .`, or switch/checkout branches without explicit user OK — untracked files are live and fragile. **Commit each finished unit immediately** (don't leave new files untracked). User gave standing OK to commit-as-you-go on this branch.
4. **Can't test against the DB locally** — production DB is remote. Live click-through verification is the user's.

See user-memory `project_autodeploy_danger.md` for the full incident + recovery playbook.

---

## 1. WHERE THINGS STAND — the whole MM Studio v4 pipeline is natively wired

`mmstudioV4.php` is the LIVE MM Studio. The main hub (`app-2026v4.php`) and the studio registry (`_studio_registry.php`) now point to it. Every pipeline step renders a **native** view (no legacy iframes) wired to real `api/mm/*` endpoints. Done this session (commits `ed170af`..`167f397`):

| Step (id) | View fn | Endpoint(s) |
|---|---|---|
| Data Map (`data_map`) | renderDataMap | `data-map.php` |
| Data Quality (`data_quality`) | renderQuality | `data-quality.php` |
| Quant Descriptives/Inferential (`q_desc`/`q_inf`) | renderDescriptive/renderTTest/ANOVA/Chi/Corr/Reg/Reliability | `descriptives.php`, `ttest.php`, `anova.php`, `chisquare.php`, `correlation.php`, `regression.php`, `reliability.php` |
| Qualitative Themes (`l_themes`) | renderThemes | `codebook.php`, `coded-responses.php`, `code-existing.php` (no-AI tagger), `build.php` (AI discover) |
| Codebook & Evidence (`l_book`) | renderBook | `codebook.php` (+ AI draft), `codebook-evidence.php` |
| Trustworthiness (`l_trust`) | renderTrust | `trustworthiness.php` (audit/member/kappa); `_stats.php` cohen/fleiss kappa |
| Qual → Quant (`q2q`) | renderQ2Q | `dataset.php` (quantitize themes → mm_generated_variables) |
| Build & Test Measures (`q_build`) | renderMeasureTest / renderReliability | `generated-variables.php`, `analysis-run.php` (persists results) |
| Joint Displays (`joint`) | renderJoint | `joint-display.php` (+ `set_quote` manual action I added) |
| Convergence & Divergence (`converge`) | renderConverge | `joint-display.php` notes, `alignment.php` (AI suggest) |
| Meta-inferences (`meta`) | renderMeta | `project.php` notes |
| Integrated Interpretation (`interp`) | renderInterp | `integration.php` |
| Evidence Strength (`evidence_strength`) | renderStrength | `strength-check.php` |
| Report Builder (`report`) | renderReport | `report.php` (7 sections), `report-docx.php` + `report-export.php` (downloads) |

**The integration loop is closed end-to-end:** q2q quantitizes themes (presence/intensity vars w/ `source_category_id`) → Build & Test Measures runs `analysis-run.php` (writes `mm_analysis_results`) → Joint Display's "Statistical result" column reads it back by `source_category_id`.

**Start page = the studio's intro + entry:** centered hero (BETA badge, bold headline, purple "and meaning."), a "what mixed methods is" line, a 4-step "how it works" strip, 3 clickable design cards (Convergent/Explanatory/Exploratory via `startPickDesign`), then upload/open/create options.

---

## 2. PENDING / NEXT STEPS

1. **Run two one-time migrations on prod (phpMyAdmin, db `dbs15641829`):**
   - `db/schema_phase182_trustworthiness.sql` → enables Member-checking save (Trustworthiness). Use `USE dbs15641829;` first or select the DB. (No FK — errno-150-safe.)
   - `db/schema_phase161.sql` → enables Evidence Strength checks.
   Both degrade gracefully (clear "needs migration" note) until run.
2. **Live end-to-end click-through** on a real project (e.g. "Test the Qual", 175 resp / 1 ID / 4 demo / 10 Likert / 7 open-ended).
3. **Two old side-doors still route to the legacy flow** (`project-snapshot.php`): the `studio-mm.php` landing tiles and the **mm-wizard "finish"** redirect. Repoint to `mmstudioV4.php` for full consistency (main hub already done).
4. **Optional:** PDF export on Report Builder; better theme-coding coverage (the no-AI keyword tagger under-codes abstract themes — the AI coder in `build.php` is accurate but FREEZES on large datasets; chunk it per `question_id`).

---

## 3. HOW TO BUILD A STEP (the established pattern)

- A step renders natively by adding `if(s.id==='X'){ return renderX(s); }` near the top of `renderCenter()` in `mmstudioV4.php`, and setting that step's `route` to `null` in `_mm_pipelines.php` (so it doesn't iframe a legacy page).
- `renderX(s)` = gate on `BOOT.projectId` → fetch → loading/error → render. Use the studio's OWN classes only: `panel`/`panel-h`/`panel-b`, `dx-table`, `tt-status ok`/`rev` badges (two states), `dx-layers`, `dm-cards`, `ov-score`. Help drawer + Coach come from `AHELP['X']` via `helpBar('X')`.
- Palette/tool-box items can carry a one-shot `action` (JS call) instead of `selPal` — e.g. `['name'=>'✦ Build','action'=>'rpGenerateAll()']`.
- **Deferred-tool note:** validate after every edit — `php -l <file>`, and extract the `<script>` from mmstudioV4.php and `node --check` it (stub `<?= ?>`/`<?php ?>` first). A JS syntax error breaks the whole studio. Watch nested template-literal backticks and `||` inside bash loops.

---

## 4. CONVENTIONS (user feedback — honor these)

- **Brand:** the AI assistant is **"ReliCheck Intelligence"**, never "AI", in user-facing copy. It must **never be the first or only choice** — always offer a manual/human-led path first; AI is a labeled secondary in the body AND the tool box. Never auto-run it. (See `project_relicheck_intelligence.md`.)
- **Styling:** reuse the studio's existing visual vocabulary; invent no new colors. Purple = `--btn` (#6d4ad8), used only where the studio already uses purple. Chrome gray = `--accent`. Strand colors: `--quan` blue, `--qual` purple, `--mm` green. (See `feedback_studio_style_and_commit.md`.)
- **Writing:** no em dashes; plain language for non-experts.
- Every ReliCheck Intelligence button fires an instant `toast('Working with ReliCheck Intelligence…')`.

---

## 5. KEY FILES

- `mmstudioV4.php` — the whole studio (PHP shell + inline JS). ~all render fns live here.
- `_mm_pipelines.php` — step/design definitions (`$SETUP`, `$TOOLS`, `$CONCLUDE`, `$DESIGNS`, pivots). Steps resolve via `buildSteps()`; `@x` = pivot `P[x]`.
- `api/mm/*` — endpoints (see table above). New this session: `data-quality.php`, `data-map.php`, `trustworthiness.php`, `generated-variables.php`; kappa added to `api/_stats.php`.
- `app-2026v4.php` — main hub (studio cards from `_studio_registry.php`; opens MM projects at `/mmstudioV4.php?project_id=N`).
- Backups of the 2026-05-31 incident: `/tmp/relicheck-safe-snapshot/` (worktree + gitdir tarballs).
