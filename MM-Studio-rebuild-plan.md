# MM Studio Rebuild Plan — App 2.0

Reconciliation of `upgrade.pdf` and `Best-Practice.pdf` against the current `app-2026.html`, followed by a phased delivery plan.

Prepared 2026-05-21 for Donald's review. **Do not start coding until this plan is approved.**

---

## Executive summary

Three things to know up front.

**1. The 6-stage spine already exists.** `app-2026.html` already implements `mmStopSetUp`, `mmStopStructure`, `mmStopChooseDesign`, `mmStopAnalyze`, `mmStopIntegrate`, `mmStopDefend`, the design-aware ORDER routing for each pathway, the `framing.chosen_design` / `framing.backend_stage` state, and the `mmStopStrip` chip header. The 3-pathway pivot (Explanatory / Exploratory / Convergent, mapped to `design_a` / `design_b` / `design_c`) is wired in with can-claim / cannot-claim copy and a recommendation API call. We are not building the spine from scratch. We are filling in the rooms.

**2. The PDFs describe a long backlog, not one build.** Counting strictly, `upgrade.pdf` calls for ~60 new pieces across 8 stages and 3 pathways, plus 5 signature capabilities (Strength Index, audit trail, design-aware analysis, human-in-the-loop control, report templates). That is months of work, not a single deliverable. A rebuild that tries to ship it all at once produces a broken half-rebuild.

**3. The rollout is feature-flagged side-by-side.** The current MM Studio keeps working. New flow lives behind `?v=2` or `localStorage.mmFlow = 'v2'`. Once Phase 5+ ships and the new flow has full parity, default flips and the old code goes in one clean PR. Reason: there are too many missing pieces (codebook builder, intercoder agreement, meta-inference, audit trail) for the new flow to be usable end-to-end during the build.

---

## Part 1 — Reconciliation: what exists vs what the PDFs ask for

For each stage of the new flow, three columns: **Exists** (in app-2026.html today), **Adapt** (exists but needs rework to match PDF), **Build** (genuinely new).

### Stage 1 — Set Up (Project Setup)

**Exists**
- Title project, describe study, save/resume (`mmStopSetUp` renders project title + description with an Edit-in-wizard link).
- Wizard for first-time framing (referenced via `data-mm-edit-wizard="title"`).

**Adapt**
- "Edit in wizard" currently shows a toast saying it's coming soon. Real revision flow needed.

**Build (from upgrade.pdf "missing pieces to add")**
- Study purpose prompt (the "what are you trying to understand" question from Best-Practice phase 1).
- Intended audience selector (dissertation / evaluation / HR / accreditation / market research).
- Final report type selection (feeds Stage 6 templates).

### Stage 2 — Structure Data (Data Intake + Confirm Data Structure)

**Exists**
- `mmStopStructure` reads back data_kinds, intent_purposes, response/category counts.
- `mmTabData` is a real intake surface: upload CSV/TSV/XLSX, link existing survey, paste rows, with column-role guessing.
- Column roles (text / numeric / group) are detected.

**Adapt**
- Structure stop is read-only summary. Needs an inline "confirm data structure" step that lets the user reclassify columns into the five PDF roles: open-ended, closed-ended, demographics/groups, outcomes, time/wave.
- 5,000-row cap is current. May need pathway-aware minimums instead (e.g., Exploratory wants ≥30 open-ended responses for theme saturation).

**Build**
- Data privacy notice on intake.
- Dataset readiness warning (red/yellow/green).
- Minimum data requirements per pathway.
- "What can this dataset answer?" checker.
- Missing variable warning.
- Multi-question open-ended detection.
- Automatic design-fit warning (does the dataset support the design the user is about to pick?).

### Stage 3 — Choose Design (the major pivot)

**Exists** (this is the most complete stage)
- `mmStopChooseDesign` shows 3 design cards (Explanatory / Exploratory / Convergent).
- "Can claim" and "Cannot claim" copy per design.
- "Recommended for your study" pill from `api.mmDesignRecs(p.id)`.
- Internal design slugs `design_a` / `design_b` / `design_c` persist via `api.mmFraming`.
- Stop unlocks Analyze / Integrate / Defend.

**Adapt**
- Cards are present but copy is brief. PDF wants a fuller design map (when to use, when not to use, methodological tradeoffs).

**Build**
- Design justification statement (1-paragraph "why this design fits your study" tied to data structure + intent).
- Suggested pathway preview (a tiny diagram showing what the next 3 stops will look like for the chosen design).
- Stronger "what this design can and cannot claim" warning (current copy is one line each — PDF wants the full implication).

### Stage 4 — Analyze in the Right Order

This is the largest stage. It branches into three pathways. Existing tabs are already routed design-aware in `mmStopAnalyze`.

**Exists — shared infrastructure**
- Design-aware ORDER arrays per pathway in `mmStopAnalyze`.
- Existing per-tab renderers: `mmTabBuilder`, `mmTabCategories` (themes), `mmTabDataset`, `mmTabAnalysis` (quant), `mmTabScoreToTheme`, `mmTabAlignment`, `mmTabMatrix`.
- Predictor/outcome assignment, suggested tests, t-test / chi-square / ANOVA / Pearson r, effect sizes, sample sizes (via the PHP `api/_stats.php`).
- Quality Brief, theme builder with auto/guided/hybrid/user modes, per-question themes, sentiment, category editing.

#### Pathway A — Explanatory (Quant → Qual → Integration)

**Adapt**
- `mmTabAnalysis` runs four tests; reorder so descriptive precedes inferential per Best-Practice step 2.
- Theme builder is general; needs a mode where theme prompts are tied back to the quant findings ("you found a significant group difference on `outcome`; have respondents say anything that explains it?").

**Build**
- Regression and logistic regression (Best-Practice step 2; currently only the four classical tests are wired).
- Group comparison assistant (post-hoc, pairwise, with multiple-comparison correction).
- Assumption checks (Levene, Shapiro, VIF where relevant).
- Multiple-comparison warning.
- Plain-language interpretation per test result (one paragraph: what happened, what the effect size means, what it does not mean).
- Codebook builder (formalize the categories the Builder surfaces).
- Inclusion/exclusion criteria per theme.
- Representative quote selector (already partially in Joint Display — promote to first-class).
- Theme explanation prompts tied to quant results.

#### Pathway B — Exploratory (Qual → Quant → Integration)

**Adapt**
- Builder modes (auto/guided/hybrid/user) are present; PDF asks for explicit "thematic analysis" vs "framework analysis" vs "grounded theory-lite" pathway choice inside the Builder.
- Variables built from themes (theme_presence, theme_intensity, sentiment_label, sentiment_numeric, response_length) exist as concepts on the Dataset tab; promote to a real Variable Builder UI.

**Build**
- Formal codebook (with code definitions, inclusion/exclusion, exemplars).
- Grounded theory-lite option, thematic analysis pathway, framework analysis pathway as named modes.
- Intercoder agreement (κ or α between two coders on the same items).
- Memo writing surface (per-code and per-theme memos with a timeline).
- Theme saturation check (does adding more responses produce new themes?).
- Construct builder (combine multiple themes into a construct).
- Scale builder (combine constructs into a measurable scale).
- Theme clustering (similarity-based grouping).
- Code-to-variable audit (which codes became which variables, with the count and a reverse link).
- Variable naming assistant.
- Reliability analysis for created scales (Cronbach's α, McDonald's ω — Best-Practice step 1).
- Factor analysis (already in app under `renderValidityTab` for the survey-side — port).
- Cluster comparison.
- Validation warnings on the new scales.

#### Pathway C — Convergent (parallel tracks → Compare → Integration)

**Exists**
- Joint Display, Score-to-Theme, Alignment, Evidence Matrix (`mmTabJointDisplay`, `mmTabScoreToTheme`, `mmTabAlignment`, `mmTabMatrix`).

**Adapt**
- Alignment surface needs convergence/divergence labels per row, not just a free-form interpretation.

**Build**
- Convergence/divergence table (explicit Agree / Complicate / Contradict labels per theme-test pair).
- Contradiction analysis (surface the contradictions on their own, prominently — Best-Practice says these are the most valuable findings).
- Mixed evidence flags.
- "Qual supports / complicates / contradicts quant" labels (this is the killer feature per Best-Practice).
- Descriptive dashboards on the quant track.
- Reliability checks on the quant track.
- Assumption checks on the quant track.

### Stage 5 — Integrate Evidence

**Exists**
- `mmStopIntegrate` orders `joint` → `integration` → `strength`.
- `mmTabJointDisplay`: theme-per-row table with quant footprint, sentiment bar, representative quote, "Pick all quotes" Intelligence call.
- `mmTabIntegration`: per-theme integration paragraphs, generate-all, save edits, Intelligence-drafted.
- `mmTabStrengthCheck`: four families of checks (sample/coverage, theme saturation, effect-size sanity, optional Intelligence quality review of picked quotes + integration paragraphs). Pass / fix / skip pills, severity, fix hints.

**Adapt**
- Strength Check is itemized but does not yet roll up into a single index. PDF asks for a numeric "ReliCheck Mixed-Methods Strength Index" — combine the existing pass/fix/skip rows into a weighted score.

**Build (the headline missing pieces)**
- **Meta-inference generator** (Best-Practice integration technique #2): "Quant said X, qual said Y, integrated conclusion is Z." One per project, editable, defaults to Intelligence-drafted.
- Claims audit (every claim in the report must trace back through the integration paragraph → theme → quote / variable → statistical result).
- Contradiction finder (alongside Joint Display, surface where quant and qual disagree, with a one-click "promote to integration" action).
- Limitations generator (per design: what this design fundamentally cannot claim).
- Reviewer-readiness score (Strength Index rolled to a single 0–100 with a green/yellow/red lozenge).

### Stage 6 — Defend the Report

**Exists**
- `mmStopDefend` shows the Report tab.
- `mmTabReport` is a section-by-section editor with three source pills (INTELLIGENCE / EDITED / TEMPLATE), DOCX/PDF export, note chips with unresolved counts, contenteditable per section, auto-save 5s after typing, Intelligence regeneration, version history (implied by edited-source pill).

**Adapt**
- Sections are currently project-wide. Make section set selectable based on the **report template** the user picked in Stage 1.

**Build**
- Methods appendix generator (every method used, with parameters and reference).
- Design justification paragraph (pulled from Stage 3).
- Limitations section (pulled from Stage 5).
- Audit trail export (every claim → theme → quote → variable → test → paragraph, as a separate appendix or supplement).
- Reviewer questions checklist (the questions a methodologist or dissertation chair will ask, with where in the report each is answered).
- Templated section sets per audience: Dissertation chapter, Program evaluation, Accreditation evidence, HR insights, Market research, Academic manuscript findings section.

### Cross-cutting capability — Reviewer-Ready Audit Trail

This is signature capability #2 in upgrade.pdf and applies across all stages. Every claim should trace:

theme → quote → respondent row → variable → statistical result → report paragraph

Pieces of this exist (the Report links sections back to data, the Joint Display ties themes to quotes), but the chain is not navigable end-to-end. This is a Phase 5 deliverable.

---

## Part 2 — Phased delivery plan

Eight phases. Each is a discrete shippable unit. Each has a "definition of done" you can verify before we move on.

### Phase 0 — Audit and feature flag (~1 build)

Lightweight. No new analysis features.

- Add `?v=2` or `localStorage.mmFlow = 'v2'` flag check at the MM Studio entry.
- Audit the existing spine against the PDF for naming and copy parity (the stop strip uses "Set Up / Structure Data / Choose Design / Analyze / Integrate / Defend" — confirm the labels match upgrade.pdf exactly, fix any drift).
- Strengthen the Choose Design cards: full "can claim / cannot claim" copy, a 1-paragraph design justification per option, a tiny pathway preview diagram.
- This is the only phase where the new flow is fully usable, because we have not removed anything.

**Definition of done:** Visiting `/app-2026.html?v=2` shows the same MM Studio with upgraded Choose Design copy. Visiting without the flag is unchanged.

### Phase 1 — Set Up + Structure Data depth (~1 build)

- Study purpose prompt in Stage 1.
- Intended audience selector in Stage 1 (drives Stage 6 template).
- Final report type selection.
- Confirm-data-structure inline editor in Stage 2 (reclassify columns into the five PDF roles).
- Data privacy notice on intake.
- Dataset readiness warning (red/yellow/green).
- Minimum data requirements per pathway (warning, not block).
- Automatic design-fit warning (suggest pathway based on data structure).

**Definition of done:** A fresh project run through the new wizard records purpose + audience + report type, classifies columns, and shows pathway-fit warnings before the user reaches Stage 3.

### Phase 2 — Explanatory pathway filled in (~2-3 builds)

Pick this pathway first because it is the most common in applied research (Best-Practice page 3) and Donald's audience (dissertation, evaluation, HR, accreditation) leans Explanatory.

- Regression + logistic regression in `mmTabAnalysis`.
- Group comparison assistant with post-hoc + multiple-comparison correction.
- Assumption checks (Levene, Shapiro, VIF).
- Plain-language interpretation per test.
- Codebook builder.
- Inclusion/exclusion criteria per theme.
- Promote representative quote selector to first-class.
- Theme prompts tied to quant findings.

**Definition of done:** A user with the demo dataset can run Explanatory end-to-end inside the v2 flow and produce a finished joint display + integration paragraphs that look reviewer-ready.

### Phase 3 — Exploratory pathway filled in (~3-4 builds)

Largest backlog because the qual-first workflow needs the most new infrastructure.

- Formal codebook (definitions, inclusion/exclusion, exemplars).
- Named modes: grounded theory-lite, thematic analysis, framework analysis.
- Intercoder agreement.
- Memo writing.
- Theme saturation check.
- Construct builder.
- Scale builder.
- Theme clustering.
- Code-to-variable audit.
- Variable naming assistant.
- Cronbach's α and McDonald's ω on built scales.
- Port factor analysis from the survey-side renderer.
- Validation warnings on new scales.

**Definition of done:** A user with interview-style open-ended data can run Exploratory, surface themes, build a scale from them, and produce a quantitative test on the new scale.

### Phase 4 — Convergent pathway filled in (~2 builds)

- Convergence/divergence table with explicit Agree / Complicate / Contradict labels.
- Contradiction analysis as its own surface.
- Mixed evidence flags.
- "Qual supports / complicates / contradicts quant" labels in Joint Display.
- Descriptive dashboards on the quant track.
- Reliability + assumption checks on the quant track.

**Definition of done:** Parallel-track analysis surfaces convergence and divergence as discrete findings, not just free-form interpretation.

### Phase 5 — Strength Index + Meta-Inference + Audit Trail (~2 builds)

Cross-cutting. This is the differentiator and the hardest engineering.

- Roll the existing Strength Check rows into a single weighted Reviewer-Readiness Score (0–100 with green/yellow/red).
- Meta-inference generator (one per project, Intelligence-drafted, editable).
- Claims audit endpoint that walks every claim in the integration paragraphs back to themes, quotes, variables, and tests.
- Contradiction finder that surfaces from the Convergent pathway data (and from Explanatory where the qual contradicts the quant).
- Limitations generator (design-aware).
- Reviewer-readiness score lozenge in the Report tab header.

**Definition of done:** A finished project shows a single Reviewer-Readiness score, a Methodologist-defensible meta-inference paragraph, and an Audit Trail tab that lets you click any claim and see its full provenance.

### Phase 6 — Report templates per audience (~1-2 builds)

- Six template section sets: Dissertation, Program evaluation, Accreditation, HR insights, Market research, Academic manuscript findings.
- Methods appendix generator.
- Design justification paragraph (pulled from Stage 3).
- Limitations section (pulled from Stage 5).
- Audit trail export as appendix.
- Reviewer questions checklist.

**Definition of done:** A user picking "Dissertation chapter" in Stage 1 gets a different section set in Stage 6 than a user picking "HR insights", with the right academic-vs-business tone and the right appendices.

### Phase 7 — Cutover and cleanup (~1 build)

- Flip the `v=2` flag default to on.
- Run side-by-side parity checks on a known project (results, themes, joint display, integration paragraphs, report sections all match between v1 and v2 for the demo data).
- Remove the v1 spine code: legacy `mmRenderProjectShell` branch, any analyze-tab routing that does not go through `mmStopAnalyze`, and the dead `design_d` / `design_e` slugs.
- Update memory: mark project_mm_studio_desktop.md as not impacted; create `project_app2_mmstudio_v2.md` for the web app.

**Definition of done:** The old MM Studio surface no longer exists in the codebase. Every visitor lands on the new flow. The diff that landed Phase 7 is a net deletion.

---

## Risks and unknowns

1. **Intercoder agreement requires two coders.** If Donald is the sole researcher on most demo projects, the κ/α surface will mostly be empty. Decide whether to ship it as an optional pane or behind a "Add a second coder" CTA.

2. **Meta-inference generator depends on Intelligence quotas.** Each project = 1 Intelligence call to draft. Per-Intelligence cost is already baked into Strength Check and Integration. Confirm budget headroom before Phase 5.

3. **Audit trail end-to-end needs schema work.** Most pieces exist as separate API responses; stitching them into one navigable chain is server-side work, not just UI. Add ~1 build of PHP/MySQL schema work in Phase 5 for `claim_provenance` tables.

4. **Report templates per audience may conflict with the existing `mmTabReport` section_key list.** Templates need to be a separate concept (`report_template_id` on the project) that selects which section_keys appear. Schema migration in Phase 6.

5. **The desktop MM Studio (Mac) is parallel.** Phases 0–5 land in the web app. The desktop app will lag and will need its own port plan once the web design has settled. Do not try to keep them in lockstep during the rebuild.

---

## Recommended next step

If this plan is accepted, the first delivery (Phase 0) is small: feature flag, naming/copy parity, stronger Choose Design cards. That's a single build I can ship in the next session.

If the plan is not what you wanted — wrong phase ordering, wrong scope per phase, wrong pathway-first choice — push back here and we revise before any code lands.
