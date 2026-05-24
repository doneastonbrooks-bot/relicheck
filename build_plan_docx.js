// Build MM-Studio-rebuild-plan.docx from the source markdown.
// Run from /sessions/brave-stoic-gauss/mnt/outputs (where docx is npm-installed).

const fs = require('fs');
const path = require('path');
const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType,
  LevelFormat, PageOrientation, BorderStyle,
} = require('docx');

const OUT_PATH = '/sessions/brave-stoic-gauss/mnt/relicheck/MM-Studio-rebuild-plan.docx';

// ---------- helpers ----------
const P = (text, opts = {}) => new Paragraph({
  children: [new TextRun({ text, ...(opts.run || {}) })],
  spacing: opts.spacing || { before: 80, after: 80 },
  ...(opts.heading ? { heading: opts.heading } : {}),
  ...(opts.alignment ? { alignment: opts.alignment } : {}),
});

const H1 = (text) => new Paragraph({
  heading: HeadingLevel.HEADING_1,
  children: [new TextRun({ text })],
  spacing: { before: 320, after: 160 },
});
const H2 = (text) => new Paragraph({
  heading: HeadingLevel.HEADING_2,
  children: [new TextRun({ text })],
  spacing: { before: 260, after: 140 },
});
const H3 = (text) => new Paragraph({
  heading: HeadingLevel.HEADING_3,
  children: [new TextRun({ text })],
  spacing: { before: 220, after: 120 },
});
const H4 = (text) => new Paragraph({
  heading: HeadingLevel.HEADING_4,
  children: [new TextRun({ text })],
  spacing: { before: 180, after: 100 },
});

// Inline parser: turn **bold** and `code` into TextRuns.
function inlineRuns(text) {
  const runs = [];
  const re = /(\*\*[^*]+\*\*|`[^`]+`)/g;
  let last = 0;
  let m;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) {
      runs.push(new TextRun({ text: text.slice(last, m.index) }));
    }
    const tok = m[1];
    if (tok.startsWith('**')) {
      runs.push(new TextRun({ text: tok.slice(2, -2), bold: true }));
    } else {
      runs.push(new TextRun({ text: tok.slice(1, -1), font: 'Menlo' }));
    }
    last = m.index + tok.length;
  }
  if (last < text.length) {
    runs.push(new TextRun({ text: text.slice(last) }));
  }
  return runs.length ? runs : [new TextRun({ text })];
}

const Para = (text) => new Paragraph({
  children: inlineRuns(text),
  spacing: { before: 80, after: 80 },
});

const Bullet = (text, level = 0) => new Paragraph({
  numbering: { reference: 'bullets', level },
  children: inlineRuns(text),
  spacing: { before: 40, after: 40 },
});

const Rule = () => new Paragraph({
  spacing: { before: 80, after: 80 },
  border: {
    bottom: { style: BorderStyle.SINGLE, size: 6, color: '999999', space: 1 },
  },
  children: [new TextRun({ text: '' })],
});

const Blank = () => new Paragraph({
  children: [new TextRun({ text: '' })],
  spacing: { before: 40, after: 40 },
});

// ---------- document body ----------
const children = [];

// Title
children.push(new Paragraph({
  alignment: AlignmentType.LEFT,
  spacing: { before: 0, after: 80 },
  children: [new TextRun({ text: 'MM Studio Rebuild Plan — App 2.0', bold: true, size: 40 })],
}));
children.push(Para('Reconciliation of upgrade.pdf and Best-Practice.pdf against the current app-2026.html, followed by a phased delivery plan.'));
children.push(Para('Prepared 2026-05-21 for Donald’s review. Do not start coding until this plan is approved.'));
children.push(Rule());

// ===== Executive summary =====
children.push(H1('Executive summary'));
children.push(Para('Three things to know up front.'));

children.push(Para('1. The 6-stage spine already exists. app-2026.html already implements mmStopSetUp, mmStopStructure, mmStopChooseDesign, mmStopAnalyze, mmStopIntegrate, mmStopDefend, the design-aware ORDER routing for each pathway, the framing.chosen_design / framing.backend_stage state, and the mmStopStrip chip header. The 3-pathway pivot (Explanatory / Exploratory / Convergent, mapped to design_a / design_b / design_c) is wired in with can-claim / cannot-claim copy and a recommendation API call. We are not building the spine from scratch. We are filling in the rooms.'));

children.push(Para('2. The PDFs describe a long backlog, not one build. Counting strictly, upgrade.pdf calls for roughly 60 new pieces across 8 stages and 3 pathways, plus 5 signature capabilities (Strength Index, audit trail, design-aware analysis, human-in-the-loop control, report templates). That is months of work, not a single deliverable. A rebuild that tries to ship it all at once produces a broken half-rebuild.'));

children.push(Para('3. The rollout is feature-flagged side-by-side. The current MM Studio keeps working. New flow lives behind ?v=2 or localStorage.mmFlow = "v2". Once Phase 5+ ships and the new flow has full parity, default flips and the old code goes in one clean PR. Reason: there are too many missing pieces (codebook builder, intercoder agreement, meta-inference, audit trail) for the new flow to be usable end-to-end during the build.'));

children.push(Rule());

// ===== Part 1 Reconciliation =====
children.push(H1('Part 1 — Reconciliation: what exists vs what the PDFs ask for'));
children.push(Para('For each stage of the new flow, three columns of information: Exists (in app-2026.html today), Adapt (exists but needs rework to match the PDF), and Build (genuinely new).'));

// Stage 1
children.push(H2('Stage 1 — Set Up (Project Setup)'));
children.push(H3('Exists'));
children.push(Bullet('Title project, describe study, save/resume (mmStopSetUp renders project title and description with an Edit-in-wizard link).'));
children.push(Bullet('Wizard for first-time framing (referenced via data-mm-edit-wizard="title").'));
children.push(H3('Adapt'));
children.push(Bullet('"Edit in wizard" currently shows a toast saying it’s coming soon. Real revision flow needed.'));
children.push(H3('Build (from upgrade.pdf "missing pieces to add")'));
children.push(Bullet('Study purpose prompt (the "what are you trying to understand" question from Best-Practice phase 1).'));
children.push(Bullet('Intended audience selector (dissertation / evaluation / HR / accreditation / market research).'));
children.push(Bullet('Final report type selection (feeds Stage 6 templates).'));

// Stage 2
children.push(H2('Stage 2 — Structure Data (Data Intake + Confirm Data Structure)'));
children.push(H3('Exists'));
children.push(Bullet('mmStopStructure reads back data_kinds, intent_purposes, response/category counts.'));
children.push(Bullet('mmTabData is a real intake surface: upload CSV/TSV/XLSX, link existing survey, paste rows, with column-role guessing.'));
children.push(Bullet('Column roles (text / numeric / group) are detected.'));
children.push(H3('Adapt'));
children.push(Bullet('Structure stop is read-only summary. Needs an inline "confirm data structure" step that lets the user reclassify columns into the five PDF roles: open-ended, closed-ended, demographics/groups, outcomes, time/wave.'));
children.push(Bullet('5,000-row cap is current. May need pathway-aware minimums instead (e.g., Exploratory wants at least 30 open-ended responses for theme saturation).'));
children.push(H3('Build'));
children.push(Bullet('Data privacy notice on intake.'));
children.push(Bullet('Dataset readiness warning (red/yellow/green).'));
children.push(Bullet('Minimum data requirements per pathway.'));
children.push(Bullet('"What can this dataset answer?" checker.'));
children.push(Bullet('Missing variable warning.'));
children.push(Bullet('Multi-question open-ended detection.'));
children.push(Bullet('Automatic design-fit warning (does the dataset support the design the user is about to pick?).'));

// Stage 3
children.push(H2('Stage 3 — Choose Design (the major pivot)'));
children.push(H3('Exists (this is the most complete stage)'));
children.push(Bullet('mmStopChooseDesign shows 3 design cards (Explanatory / Exploratory / Convergent).'));
children.push(Bullet('"Can claim" and "Cannot claim" copy per design.'));
children.push(Bullet('"Recommended for your study" pill from api.mmDesignRecs(p.id).'));
children.push(Bullet('Internal design slugs design_a / design_b / design_c persist via api.mmFraming.'));
children.push(Bullet('Stop unlocks Analyze / Integrate / Defend.'));
children.push(H3('Adapt'));
children.push(Bullet('Cards are present but copy is brief. PDF wants a fuller design map (when to use, when not to use, methodological tradeoffs).'));
children.push(H3('Build'));
children.push(Bullet('Design justification statement (1-paragraph "why this design fits your study" tied to data structure and intent).'));
children.push(Bullet('Suggested pathway preview (a tiny diagram showing what the next 3 stops will look like for the chosen design).'));
children.push(Bullet('Stronger "what this design can and cannot claim" warning (current copy is one line each — PDF wants the full implication).'));

// Stage 4
children.push(H2('Stage 4 — Analyze in the Right Order'));
children.push(Para('This is the largest stage. It branches into three pathways. Existing tabs are already routed design-aware in mmStopAnalyze.'));
children.push(H3('Exists — shared infrastructure'));
children.push(Bullet('Design-aware ORDER arrays per pathway in mmStopAnalyze.'));
children.push(Bullet('Existing per-tab renderers: mmTabBuilder, mmTabCategories (themes), mmTabDataset, mmTabAnalysis (quant), mmTabScoreToTheme, mmTabAlignment, mmTabMatrix.'));
children.push(Bullet('Predictor/outcome assignment, suggested tests, t-test / chi-square / ANOVA / Pearson r, effect sizes, sample sizes (via the PHP api/_stats.php).'));
children.push(Bullet('Quality Brief, theme builder with auto/guided/hybrid/user modes, per-question themes, sentiment, category editing.'));

children.push(H3('Pathway A — Explanatory (Quant → Qual → Integration)'));
children.push(H4('Adapt'));
children.push(Bullet('mmTabAnalysis runs four tests; reorder so descriptive precedes inferential per Best-Practice step 2.'));
children.push(Bullet('Theme builder is general; needs a mode where theme prompts are tied back to the quant findings.'));
children.push(H4('Build'));
children.push(Bullet('Regression and logistic regression (Best-Practice step 2; currently only the four classical tests are wired).'));
children.push(Bullet('Group comparison assistant (post-hoc, pairwise, with multiple-comparison correction).'));
children.push(Bullet('Assumption checks (Levene, Shapiro, VIF where relevant).'));
children.push(Bullet('Multiple-comparison warning.'));
children.push(Bullet('Plain-language interpretation per test result.'));
children.push(Bullet('Codebook builder (formalize the categories the Builder surfaces).'));
children.push(Bullet('Inclusion/exclusion criteria per theme.'));
children.push(Bullet('Representative quote selector (already partially in Joint Display — promote to first-class).'));
children.push(Bullet('Theme explanation prompts tied to quant results.'));

children.push(H3('Pathway B — Exploratory (Qual → Quant → Integration)'));
children.push(H4('Adapt'));
children.push(Bullet('Builder modes (auto/guided/hybrid/user) are present; PDF asks for explicit "thematic analysis" vs "framework analysis" vs "grounded theory-lite" pathway choice inside the Builder.'));
children.push(Bullet('Variables built from themes (theme_presence, theme_intensity, sentiment_label, sentiment_numeric, response_length) exist as concepts on the Dataset tab; promote to a real Variable Builder UI.'));
children.push(H4('Build'));
children.push(Bullet('Formal codebook (with code definitions, inclusion/exclusion, exemplars).'));
children.push(Bullet('Grounded theory-lite option, thematic analysis pathway, framework analysis pathway as named modes.'));
children.push(Bullet('Intercoder agreement (kappa or alpha between two coders on the same items).'));
children.push(Bullet('Memo writing surface (per-code and per-theme memos with a timeline).'));
children.push(Bullet('Theme saturation check (does adding more responses produce new themes?).'));
children.push(Bullet('Construct builder (combine multiple themes into a construct).'));
children.push(Bullet('Scale builder (combine constructs into a measurable scale).'));
children.push(Bullet('Theme clustering (similarity-based grouping).'));
children.push(Bullet('Code-to-variable audit (which codes became which variables, with the count and a reverse link).'));
children.push(Bullet('Variable naming assistant.'));
children.push(Bullet('Reliability analysis for created scales (Cronbach’s alpha, McDonald’s omega — Best-Practice step 1).'));
children.push(Bullet('Factor analysis (already in app under renderValidityTab for the survey-side — port).'));
children.push(Bullet('Cluster comparison.'));
children.push(Bullet('Validation warnings on the new scales.'));

children.push(H3('Pathway C — Convergent (parallel tracks → Compare → Integration)'));
children.push(H4('Exists'));
children.push(Bullet('Joint Display, Score-to-Theme, Alignment, Evidence Matrix (mmTabJointDisplay, mmTabScoreToTheme, mmTabAlignment, mmTabMatrix).'));
children.push(H4('Adapt'));
children.push(Bullet('Alignment surface needs convergence/divergence labels per row, not just a free-form interpretation.'));
children.push(H4('Build'));
children.push(Bullet('Convergence/divergence table (explicit Agree / Complicate / Contradict labels per theme-test pair).'));
children.push(Bullet('Contradiction analysis (surface the contradictions on their own, prominently — Best-Practice says these are the most valuable findings).'));
children.push(Bullet('Mixed evidence flags.'));
children.push(Bullet('"Qual supports / complicates / contradicts quant" labels (this is the killer feature per Best-Practice).'));
children.push(Bullet('Descriptive dashboards on the quant track.'));
children.push(Bullet('Reliability checks on the quant track.'));
children.push(Bullet('Assumption checks on the quant track.'));

// Stage 5
children.push(H2('Stage 5 — Integrate Evidence'));
children.push(H3('Exists'));
children.push(Bullet('mmStopIntegrate orders joint → integration → strength.'));
children.push(Bullet('mmTabJointDisplay: theme-per-row table with quant footprint, sentiment bar, representative quote, "Pick all quotes" Intelligence call.'));
children.push(Bullet('mmTabIntegration: per-theme integration paragraphs, generate-all, save edits, Intelligence-drafted.'));
children.push(Bullet('mmTabStrengthCheck: four families of checks (sample/coverage, theme saturation, effect-size sanity, optional Intelligence quality review of picked quotes and integration paragraphs). Pass / fix / skip pills, severity, fix hints.'));
children.push(H3('Adapt'));
children.push(Bullet('Strength Check is itemized but does not yet roll up into a single index. PDF asks for a numeric "ReliCheck Mixed-Methods Strength Index" — combine the existing pass/fix/skip rows into a weighted score.'));
children.push(H3('Build (the headline missing pieces)'));
children.push(Bullet('Meta-inference generator (Best-Practice integration technique #2): "Quant said X, qual said Y, integrated conclusion is Z." One per project, editable, defaults to Intelligence-drafted.'));
children.push(Bullet('Claims audit (every claim in the report must trace back through the integration paragraph → theme → quote / variable → statistical result).'));
children.push(Bullet('Contradiction finder (alongside Joint Display, surface where quant and qual disagree, with a one-click "promote to integration" action).'));
children.push(Bullet('Limitations generator (per design: what this design fundamentally cannot claim).'));
children.push(Bullet('Reviewer-readiness score (Strength Index rolled to a single 0–100 with a green/yellow/red lozenge).'));

// Stage 6
children.push(H2('Stage 6 — Defend the Report'));
children.push(H3('Exists'));
children.push(Bullet('mmStopDefend shows the Report tab.'));
children.push(Bullet('mmTabReport is a section-by-section editor with three source pills (INTELLIGENCE / EDITED / TEMPLATE), DOCX/PDF export, note chips with unresolved counts, contenteditable per section, auto-save 5s after typing, Intelligence regeneration, version history (implied by edited-source pill).'));
children.push(H3('Adapt'));
children.push(Bullet('Sections are currently project-wide. Make section set selectable based on the report template the user picked in Stage 1.'));
children.push(H3('Build'));
children.push(Bullet('Methods appendix generator (every method used, with parameters and reference).'));
children.push(Bullet('Design justification paragraph (pulled from Stage 3).'));
children.push(Bullet('Limitations section (pulled from Stage 5).'));
children.push(Bullet('Audit trail export (every claim → theme → quote → variable → test → paragraph, as a separate appendix or supplement).'));
children.push(Bullet('Reviewer questions checklist (the questions a methodologist or dissertation chair will ask, with where in the report each is answered).'));
children.push(Bullet('Templated section sets per audience: Dissertation chapter, Program evaluation, Accreditation evidence, HR insights, Market research, Academic manuscript findings section.'));

// Cross-cutting
children.push(H2('Cross-cutting capability — Reviewer-Ready Audit Trail'));
children.push(Para('This is signature capability #2 in upgrade.pdf and applies across all stages. Every claim should trace:'));
children.push(Para('theme → quote → respondent row → variable → statistical result → report paragraph'));
children.push(Para('Pieces of this exist (the Report links sections back to data, the Joint Display ties themes to quotes), but the chain is not navigable end-to-end. This is a Phase 5 deliverable.'));

children.push(Rule());

// ===== Part 2 Phases =====
children.push(H1('Part 2 — Phased delivery plan'));
children.push(Para('Eight phases. Each is a discrete shippable unit. Each has a "definition of done" you can verify before we move on.'));

children.push(H2('Phase 0 — Audit and feature flag (~1 build)'));
children.push(Para('Lightweight. No new analysis features.'));
children.push(Bullet('Add ?v=2 or localStorage.mmFlow = "v2" flag check at the MM Studio entry.'));
children.push(Bullet('Audit the existing spine against the PDF for naming and copy parity (the stop strip uses "Set Up / Structure Data / Choose Design / Analyze / Integrate / Defend" — confirm the labels match upgrade.pdf exactly, fix any drift).'));
children.push(Bullet('Strengthen the Choose Design cards: full "can claim / cannot claim" copy, a 1-paragraph design justification per option, a tiny pathway preview diagram.'));
children.push(Bullet('This is the only phase where the new flow is fully usable, because we have not removed anything.'));
children.push(Para('Definition of done: Visiting /app-2026.html?v=2 shows the same MM Studio with upgraded Choose Design copy. Visiting without the flag is unchanged.'));

children.push(H2('Phase 1 — Set Up + Structure Data depth (~1 build)'));
children.push(Bullet('Study purpose prompt in Stage 1.'));
children.push(Bullet('Intended audience selector in Stage 1 (drives Stage 6 template).'));
children.push(Bullet('Final report type selection.'));
children.push(Bullet('Confirm-data-structure inline editor in Stage 2 (reclassify columns into the five PDF roles).'));
children.push(Bullet('Data privacy notice on intake.'));
children.push(Bullet('Dataset readiness warning (red/yellow/green).'));
children.push(Bullet('Minimum data requirements per pathway (warning, not block).'));
children.push(Bullet('Automatic design-fit warning (suggest pathway based on data structure).'));
children.push(Para('Definition of done: A fresh project run through the new wizard records purpose + audience + report type, classifies columns, and shows pathway-fit warnings before the user reaches Stage 3.'));

children.push(H2('Phase 2 — Explanatory pathway filled in (~2-3 builds)'));
children.push(Para('Pick this pathway first because it is the most common in applied research (Best-Practice page 3) and Donald’s audience (dissertation, evaluation, HR, accreditation) leans Explanatory.'));
children.push(Bullet('Regression and logistic regression in mmTabAnalysis.'));
children.push(Bullet('Group comparison assistant with post-hoc and multiple-comparison correction.'));
children.push(Bullet('Assumption checks (Levene, Shapiro, VIF).'));
children.push(Bullet('Plain-language interpretation per test.'));
children.push(Bullet('Codebook builder.'));
children.push(Bullet('Inclusion/exclusion criteria per theme.'));
children.push(Bullet('Promote representative quote selector to first-class.'));
children.push(Bullet('Theme prompts tied to quant findings.'));
children.push(Para('Definition of done: A user with the demo dataset can run Explanatory end-to-end inside the v2 flow and produce a finished joint display and integration paragraphs that look reviewer-ready.'));

children.push(H2('Phase 3 — Exploratory pathway filled in (~3-4 builds)'));
children.push(Para('Largest backlog because the qual-first workflow needs the most new infrastructure.'));
children.push(Bullet('Formal codebook (definitions, inclusion/exclusion, exemplars).'));
children.push(Bullet('Named modes: grounded theory-lite, thematic analysis, framework analysis.'));
children.push(Bullet('Intercoder agreement.'));
children.push(Bullet('Memo writing.'));
children.push(Bullet('Theme saturation check.'));
children.push(Bullet('Construct builder.'));
children.push(Bullet('Scale builder.'));
children.push(Bullet('Theme clustering.'));
children.push(Bullet('Code-to-variable audit.'));
children.push(Bullet('Variable naming assistant.'));
children.push(Bullet('Cronbach’s alpha and McDonald’s omega on built scales.'));
children.push(Bullet('Port factor analysis from the survey-side renderer.'));
children.push(Bullet('Validation warnings on new scales.'));
children.push(Para('Definition of done: A user with interview-style open-ended data can run Exploratory, surface themes, build a scale from them, and produce a quantitative test on the new scale.'));

children.push(H2('Phase 4 — Convergent pathway filled in (~2 builds)'));
children.push(Bullet('Convergence/divergence table with explicit Agree / Complicate / Contradict labels.'));
children.push(Bullet('Contradiction analysis as its own surface.'));
children.push(Bullet('Mixed evidence flags.'));
children.push(Bullet('"Qual supports / complicates / contradicts quant" labels in Joint Display.'));
children.push(Bullet('Descriptive dashboards on the quant track.'));
children.push(Bullet('Reliability and assumption checks on the quant track.'));
children.push(Para('Definition of done: Parallel-track analysis surfaces convergence and divergence as discrete findings, not just free-form interpretation.'));

children.push(H2('Phase 5 — Strength Index + Meta-Inference + Audit Trail (~2 builds)'));
children.push(Para('Cross-cutting. This is the differentiator and the hardest engineering.'));
children.push(Bullet('Roll the existing Strength Check rows into a single weighted Reviewer-Readiness Score (0–100 with green/yellow/red).'));
children.push(Bullet('Meta-inference generator (one per project, Intelligence-drafted, editable).'));
children.push(Bullet('Claims audit endpoint that walks every claim in the integration paragraphs back to themes, quotes, variables, and tests.'));
children.push(Bullet('Contradiction finder that surfaces from the Convergent pathway data (and from Explanatory where the qual contradicts the quant).'));
children.push(Bullet('Limitations generator (design-aware).'));
children.push(Bullet('Reviewer-readiness score lozenge in the Report tab header.'));
children.push(Para('Definition of done: A finished project shows a single Reviewer-Readiness score, a methodologist-defensible meta-inference paragraph, and an Audit Trail tab that lets you click any claim and see its full provenance.'));

children.push(H2('Phase 6 — Report templates per audience (~1-2 builds)'));
children.push(Bullet('Six template section sets: Dissertation, Program evaluation, Accreditation, HR insights, Market research, Academic manuscript findings.'));
children.push(Bullet('Methods appendix generator.'));
children.push(Bullet('Design justification paragraph (pulled from Stage 3).'));
children.push(Bullet('Limitations section (pulled from Stage 5).'));
children.push(Bullet('Audit trail export as appendix.'));
children.push(Bullet('Reviewer questions checklist.'));
children.push(Para('Definition of done: A user picking "Dissertation chapter" in Stage 1 gets a different section set in Stage 6 than a user picking "HR insights", with the right academic-vs-business tone and the right appendices.'));

children.push(H2('Phase 7 — Cutover and cleanup (~1 build)'));
children.push(Bullet('Flip the v=2 flag default to on.'));
children.push(Bullet('Run side-by-side parity checks on a known project (results, themes, joint display, integration paragraphs, report sections all match between v1 and v2 for the demo data).'));
children.push(Bullet('Remove the v1 spine code: legacy mmRenderProjectShell branch, any analyze-tab routing that does not go through mmStopAnalyze, and the dead design_d / design_e slugs.'));
children.push(Bullet('Update memory: mark project_mm_studio_desktop.md as not impacted; create project_app2_mmstudio_v2.md for the web app.'));
children.push(Para('Definition of done: The old MM Studio surface no longer exists in the codebase. Every visitor lands on the new flow. The diff that landed Phase 7 is a net deletion.'));

children.push(Rule());

// ===== Risks =====
children.push(H1('Risks and unknowns'));
children.push(Bullet('Intercoder agreement requires two coders. If Donald is the sole researcher on most demo projects, the kappa/alpha surface will mostly be empty. Decide whether to ship it as an optional pane or behind a "Add a second coder" CTA.'));
children.push(Bullet('Meta-inference generator depends on Intelligence quotas. Each project = 1 Intelligence call to draft. Per-Intelligence cost is already baked into Strength Check and Integration. Confirm budget headroom before Phase 5.'));
children.push(Bullet('Audit trail end-to-end needs schema work. Most pieces exist as separate API responses; stitching them into one navigable chain is server-side work, not just UI. Add ~1 build of PHP/MySQL schema work in Phase 5 for claim_provenance tables.'));
children.push(Bullet('Report templates per audience may conflict with the existing mmTabReport section_key list. Templates need to be a separate concept (report_template_id on the project) that selects which section_keys appear. Schema migration in Phase 6.'));
children.push(Bullet('The desktop MM Studio (Mac) is parallel. Phases 0–5 land in the web app. The desktop app will lag and will need its own port plan once the web design has settled. Do not try to keep them in lockstep during the rebuild.'));

children.push(Rule());

// ===== Recommended next step =====
children.push(H1('Recommended next step'));
children.push(Para('If this plan is accepted, the first delivery (Phase 0) is small: feature flag, naming/copy parity, stronger Choose Design cards. That’s a single build I can ship in the next session.'));
children.push(Para('If the plan is not what you wanted — wrong phase ordering, wrong scope per phase, wrong pathway-first choice — push back here and we revise before any code lands.'));

// ---------- assemble ----------
const doc = new Document({
  creator: 'Claude',
  title: 'MM Studio Rebuild Plan — App 2.0',
  styles: {
    default: { document: { run: { font: 'Arial', size: 22 } } }, // 11pt
    paragraphStyles: [
      { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { size: 32, bold: true, font: 'Arial', color: '1F3A8A' },
        paragraph: { spacing: { before: 320, after: 160 }, outlineLevel: 0 } },
      { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { size: 28, bold: true, font: 'Arial', color: '1F3A8A' },
        paragraph: { spacing: { before: 260, after: 140 }, outlineLevel: 1 } },
      { id: 'Heading3', name: 'Heading 3', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { size: 24, bold: true, font: 'Arial', color: '23303D' },
        paragraph: { spacing: { before: 220, after: 120 }, outlineLevel: 2 } },
      { id: 'Heading4', name: 'Heading 4', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { size: 22, bold: true, font: 'Arial', color: '23303D', italics: true },
        paragraph: { spacing: { before: 180, after: 100 }, outlineLevel: 3 } },
    ],
  },
  numbering: {
    config: [
      { reference: 'bullets',
        levels: [
          { level: 0, format: LevelFormat.BULLET, text: '•', alignment: AlignmentType.LEFT,
            style: { paragraph: { indent: { left: 720, hanging: 360 } } } },
          { level: 1, format: LevelFormat.BULLET, text: '◦', alignment: AlignmentType.LEFT,
            style: { paragraph: { indent: { left: 1440, hanging: 360 } } } },
        ] },
    ],
  },
  sections: [{
    properties: {
      page: {
        size: { width: 12240, height: 15840 }, // US Letter
        margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 },
      },
    },
    children,
  }],
});

Packer.toBuffer(doc).then(buffer => {
  fs.writeFileSync(OUT_PATH, buffer);
  console.log('Wrote ' + OUT_PATH + ' (' + buffer.length + ' bytes)');
});
