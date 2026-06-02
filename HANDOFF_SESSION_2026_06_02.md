# Session Handoff — 2026-06-02

## What this session accomplished

### Housekeeping (all 4 items resolved)
1. **D/I Studios v4 cutover** — `descriptive-analysis-workspace.php` and `inferential-statistics-workspace.php` now unconditionally load `_analysis_studio_v4_shell.php`. The old shell fallback and `?v4=1` toggle are gone.
2. **project-snapshot.php** — confirmed as active shared infrastructure (Overview/data snapshot step for MM, 360, TIA, Survey, Strength Survey). NOT legacy. Memory corrected.
3. **`_analysis_studio_shell.php`** — deleted. Was the old D/I studio shell, fully superseded by v4.
4. **Survey/Strength Survey studios** — `studio-strength-survey.php` deleted (was a 301 redirect stub). `studio-survey.php` is still active and kept.

### Files deleted this session
- `rsci.php` (standalone RSCI page — no inbound links; `apps/rsci/rsci-engine.js` kept, still used by survey builder)
- `_analysis_studio_shell.php` (old D/I studio shell)
- `studio-strength-survey.php` (legacy redirect stub)

### Naming conventions locked
- **SIRI** in conversation = SIRI Index (`siri-readiness.php`). The journey app is always "SIRI Journey."
- **RSSI** in conversation = standalone report (`rssi.php`). The journey app is always "RSSI Journey."
- **MM Studio** = V4 only (`mmstudioV4.php`). No more version confusion.

### Architecture decisions made

**ReliCheck Ecosystem (RE)** — the product is an ecosystem of connected but independent sub-systems:
- SIRI (pre-data) → RSSI (post-data, optional) → Studios (deep analysis, optional)
- Users can enter and exit at any point. No forced pipeline.

**SIRI is the hub** — SIRI is not just a pre-launch checklist. It is the survey instrument platform:
- Build → Assess (SDSI/SIRI) → Revise → Deploy → Collect responses → Hand off
- 360, program eval, HR, test development = domain templates inside SIRI, not separate studios
- ReliCheck Basic = entry tier of SIRI, not a separate app
- `studio-survey.php` to be retired as RE infrastructure work proceeds

**SIRI handoff is user's choice:**
- SIRI → RSSI → Studios (full path)
- SIRI → Studios directly (skips RSSI)
- RSSI is optional, never a gate

**MM Studio edit rule updated** — no-touch rule lifted. V4 is the only version. Edits allowed but deliberate (RE infrastructure rollouts only, never casual).

### RE build principles locked (see memory)
1. **Build outside first** — ask "unique to this app?" before every build. If shared, centralize first.
2. **Rules before plugging** — define data rules + violations before wiring any stat/shared function. Each stat test = a self-contained plug (rules + computation + violation messages).
3. **5-step implementation process** — define rules → build in isolation → test all sub-systems → implement one at a time → system-wide only after all pass.

### Infrastructure audit completed
Full RE audit identified what's centralized vs. fragmented. See audit results in session transcript or memory file `feedback_re_implementation_process.md`.

**Centralized (good):** auth, DB, API helpers, stats engine (`api/_stats.php`), datasets table.

**Fragmented (needs work — in order):**
1. Type taxonomy (different column type names across studios)
2. Data upload/parser (4 independent implementations, CSV duplicated 3+ times)
3. Project persistence (4 separate tables, no cross-studio linking)
4. Report/export (multiple independent approaches)
5. Then wire RE connections

### SIRI scope items resolved (items 1-4)
- **develop.php DB mode** — now defaults to DB mode. `?mock=1` forces mock.
- **Deploy workspace** — already substantially built; open/close toggle, auto-save, launch wired.
- **Response collection** — already fully built in `api/dev/project-responses.php`.
- **RSSI/Studios handoff** — all three studio cards + RSSI card now link to real URLs with `project_id` passed through. User chooses freely.

---

## Where to pick up next session

### Immediate next: Item 5 — Domain template content

The template infrastructure EXISTS (`api/dev/templates-list.php`, `project-from-template.php`, template picker in develop.php). What's missing is real domain content.

Need to define and build templates for:
1. **360 Feedback** — multi-rater (self/peer/manager/direct report), competency-based constructs
2. **Program Evaluation** — outcome indicators, fidelity measures, stakeholder perspectives
3. **HR / Organizational** — engagement, climate, onboarding, exit
4. **Test Development** — item banks, knowledge/skill constructs, difficulty levels

Each template needs:
- Template name + description
- Constructs (2-5 per template)
- Items per construct (4-8 items, Likert unless otherwise noted)
- Scale (usually 5-pt Likert with anchors)
- Domain-specific notes for SDSI scoring

**Approach:** Define the content first (rules/constructs/items), then wire into the template system. Follow RE principle: content defined outside before plugging in.

### After templates: RE infrastructure work (in order)

**Step 1 — Unified type taxonomy**
- Agree on one column type system for all studios/apps
- Current candidates: Analysis Studio's 7 types vs. RSSI's 7+3
- Decision needed before building

**Step 2 — Unified data upload/parser**
- One shared CSV/XLSX parser (currently duplicated in 4 places)
- One shared upload endpoint
- Consistent file format support everywhere (all should accept CSV + Excel)
- Build and test in isolation FIRST, then roll to each sub-system

**Step 3 — Unified project table**
- One `projects` table with `kind` discriminator
- Replaces: `survey_projects`, `mm_projects`, `analysis_projects`, `tia_projects`
- Enables cross-studio project linking
- HIGH RISK — touch carefully, test every sub-system before going live

**Step 4 — Unified export**
- One export system all studios call
- Current: RSSI = browser print; MM = DOCX; others unclear

**Step 5 — Wire RE connections**
- SIRI Journey steps 02-06 wired to real engines
- RSSI Journey wired
- Basic tier enforcement
- All built on clean centralized infrastructure

---

## Current studio/app status table

| Studio / App | Entry Point | Latest File | State |
|---|---|---|---|
| **MM Studio** | `studio-mm.php` → redirects | `mmstudioV4.php` | Live, V4 only. Edit with care — RE infra only. |
| **Descriptive Studio** | `descriptive-analysis-studio.php` | `descriptive-analysis-workspace.php` → v4 shell | Live, v4 default (cutover done this session) |
| **Inferential Studio** | `inferential-statistics-studio.php` | `inferential-statistics-workspace.php` → v4 shell | Live, v4 default (cutover done this session) |
| **SIRI Index** | `siri-readiness.php` | `apps/sdsi/*` | Built & verified. 100-pt pre-launch score. |
| **SIRI Journey** | `siri-app.php` | `apps/journey/journey.js` | Steps 00-01 built; 02-06 shells. Wire after RE infra. |
| **RSSI (standalone)** | `rssi.php` / `rssi-upload.php` | `apps/rssi/rssi.js` | Live. 4-domain report. |
| **RSSI Journey** | `rssi-app.php` | `apps/journey/journey-rssi.js` | Most recently active. Excel upload + score hero done. |
| **Survey Dev System (SIRI hub)** | `survey-dev.php` → `develop.php` | `develop.php` | DB mode now default. Deploy + handoff wired this session. |
| **360 Studio** | `studio-360.php` | `360-wizard.php`, `apps/threesixty/` | Status unclear — confirm before RE infra work |
| **TIA Studio** | `studio-tia.php` | `tia-wizard.php`, `apps/tia-analysis/` | Status unclear — confirm before RE infra work |
| **Survey Studio** | `studio-survey.php` | — | Active but to be retired as RE proceeds. SIRI is the hub. |
| **ReliCheck Basic** | `relicheck-basic.php` | `apps/basic/*` | Plan approved. Becomes a SIRI tier, not separate app. |
| **SDSI Engine** | (internal) | `apps/sdsi/buildcheck-engine.js` | Live, powers SIRI. Do NOT rename sdsi code. |

---

## Key memory files updated this session
- `project_siri_naming.md` — SIRI disambiguation locked
- `project_rssi_design.md` — RSSI disambiguation locked
- `feedback_never_touch_mm_studio.md` — no-touch rule lifted, caution rule in place
- `project_studio_architecture.md` — Survey Studio dissolution status clarified
- `project_mm_studio_design_led.md` — project-snapshot.php clarified (not MM backup)
- `feedback_re_build_principles.md` — NEW: build-outside-first + rules-before-plugging
- `feedback_re_implementation_process.md` — NEW: 5-step RE implementation process
- `project_siri_as_hub.md` — NEW: SIRI as RE hub, templates model, optional RSSI
- `MEMORY.md` — index updated with all new entries

---

## Commits this session (in order)
1. `012bd82` — D/I Studios: make v4 shell the default
2. `d2f7a8b` — Remove rsci.php
3. `eceab1c` — Remove _analysis_studio_shell.php
4. `6c099ec` — Remove studio-strength-survey.php + update inbound links
5. `71e7b47` — SIRI: DB mode default, real response count, live RSSI/Studio handoff

---

## Standing rules (never forget)
- **Auto-deploy is LIVE** — saving a file uploads to prod in ~15s. No accidental edits.
- **Never rename `sdsi` code** — internal engine namespace stays as-is.
- **RE build principles** — build outside first; rules before plugging; test all sub-systems before system-wide.
- **No em dashes** in user-facing copy.
- **AI = ReliCheck Intelligence** in user-facing copy.
- **Project Snapshot** (`project-snapshot.php`) is active shared infrastructure — not an MM backup.
