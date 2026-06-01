# Mixed Methods Studio — Build Specification

**Product:** ReliCheck
**Module:** Mixed Methods Studio (one of four studios)
**Status:** Prototype approved, ready for implementation
**Companion files (visual source of truth):**
- `relicheck-mm-studio-fused-shell.html` — the canonical shell: six-stage spine, sub-page rail, document area, Report Draft inspector. This is the top-level structure.
- `mixed-methods-studio-quantitative-workspace.html` — detail reference for the quantitative analysis surface (lives inside Descriptive and Inferential stages).
- `mixed-methods-studio-qualitative-workspace.html` — detail reference for the coding surface (lives inside the QUAL pages).
- `mixed-methods-studio-integration-canvas.html` — detail reference for Joint Displays and Meta-inferences (the MM pages in Inferential).

This document is the implementation brief. The prototypes are the canonical visual reference. When this text and a prototype disagree on a pixel-level detail, follow the prototype. When they disagree on architecture or behavior, follow this document. The fused shell supersedes the earlier three-workspace structure: the three workspaces are now analysis surfaces that live inside the stages, not top-level navigation.

---

## 1. Product architecture

ReliCheck is two apps and four studios.

**Apps**
- **Survey developer** (name to be confirmed, currently carrying the placeholder "Siri"). Builds the instrument before it goes to respondents.
- **RSSI.** Analyzes a survey instrument for credibility after it has been administered.

**Studios**
- **Analysis Studio.** Deeper analysis on a single survey.
- **Mixed Methods Studio.** The subject of this spec.
- **360 Studio.**
- **Test Studio.** Tests and instruments.

The Mixed Methods Studio stands as its own application. It does not own survey scoring. It reads one signal from RSSI: whether the instrument is credible.

---

## 2. The RSSI boundary (do not cross)

This is the single most important rule in the build.

**RSSI covers instrument credibility only.** It answers whether a survey is sound as a measurement tool. It does not produce descriptive statistics, inferential statistics, or any analysis of the response data.

**The Mixed Methods Studio owns all analysis of the data.** Descriptive statistics, inferential tests, qualitative coding, interpretation, and reporting all happen inside this studio.

The only thread connecting the two is an instrument-validation read. In the UI this surfaces inside the Instrument Quality stage, where the Credibility Summary, the eight dimensions, and the three lenses are read from RSSI and shown read-only, with an "Open in RSSI" action for the full report. Reliability and validity belong to RSSI. Everything computed on the response data belongs to the studio.

Implementation consequence: do not call any RSSI scoring endpoint to populate the analysis stages. The studio reads a single instrument-credibility status object from RSSI and computes everything else itself.

---

## 3. Navigation model: the six-stage spine

The studio is organized by research stage, not by data type. This is the spine.

**Two levels of navigation.**
- **Stages are top tabs.** Six stages run across a tab bar under the toolbar, each with a plain-language question: Overview ("what do we have?"), Instrument Quality ("can we trust it?"), Descriptive Analysis ("what does it show?"), Inferential Analysis ("what can we test?"), Interpretation ("what does it mean?"), Reporting ("how do we report it?"). Selecting a stage swaps the left rail.
- **Sub-pages fill the left rail.** Each stage lists its sub-pages in the rail, ordered alphabetically by name, each carrying its strand tag. Selecting a sub-page renders it in the document area.

**Three regions of work.**
- **Document area (center).** A document-first surface modeled on Apple Pages. Most pages open with an eyebrow, a serif title, a description, a data-context strip, and a Run / Configure / Learn action card. Pages with live output (for example Means & Distributions) render the analysis inline as a results document. The MM pages (Joint Displays, Meta-inferences) route to the Integration canvas.
- **Report Draft (right inspector).** A live, cross-stage list of every analysis the researcher has saved to the report, grouped by stage, with a running count and an "Open Report Builder" action.

This replaces the earlier three-workspace top-level navigation. Quantitative analysis, qualitative coding, and integration are no longer separate tabs. They are surfaces that live inside Descriptive Analysis, Inferential Analysis, and the MM pages respectively.

---

## 4. Full information architecture

All sub-pages carry a strand tag where applicable (see Section 5). Stages whose work is framing, synthesis, or output are untagged because they are not strand-specific.

| Stage | Question | Sub-pages (tag) |
|---|---|---|
| **Overview** | what do we have? | Project Snapshot · Purpose & Research Question · Sample & Data Sources · Data Quality |
| **Instrument Quality** | can we trust it? | Credibility Summary (RSSI) · Eight Dimensions (RSSI) · Three Lenses (RSSI) |
| **Descriptive Analysis** | what does it show? | Frequencies (QUAN) · Means & Distributions (QUAN) · Cross-Tabs (QUAN) · Group Summaries (QUAN) · Item / Theme Summaries (MM) · Top & Bottom Items (QUAN) · Scale Scores (QUAN) · Missing Data (QUAN) · Themes & Codes (QUAL) · Codebook Builder (QUAL) · Theme by Group (QUAL) · Qual → Quant (MM) · Exemplar Quotes (QUAL) · Theme Co-occurrence (QUAL) |
| **Inferential Analysis** | what can we test? | Recommended Analyses · T-Test (QUAN) · ANOVA (QUAN) · Chi-Square (QUAN) · Correlation (QUAN) · Effect Sizes (QUAN) · Paired T-Test (QUAN) · Welch ANOVA (QUAN) · Post-Hoc Comparison (QUAN) · Regression (QUAN) · Confidence Intervals (QUAN) · Assumption Checks (QUAN) · Joint Displays (MM) · Convergence & Divergence (MM) · Coding Agreement κ (QUAL) · Pattern Matching (QUAL) · Meta-inferences (MM) |
| **Interpretation** | what does it mean? | Key Findings · Evidence Notes · Practical Meaning · Limitations · Recommended Actions · Teaching Moments · AI Interpretation · Decision Readiness |
| **Reporting** | how do we report it? | Report Builder · Executive Summary · Methodology · Findings · Tables & Figures · Recommendations · Appendix · Exports |

---

## 5. Strand tagging system

Every analysis is tagged by its strand so the three sit as equals. This is a deliberate integration choice. Tagging only the qualitative work would leave quantitative as the unmarked default, which reads as a quantitative tool with qualitative bolted on. The studio is integrated, so the tags make the strands visibly equal.

- **QUAN.** Purely quantitative method or data.
- **QUAL.** Purely qualitative method or data.
- **MM.** Genuinely combines both strands (for example Item / Theme Summaries, Qual → Quant, Joint Displays, Convergence & Divergence, Meta-inferences).
- **RSSI.** Not a strand. A source marker for the read-only instrument-credibility pages, styled distinctly so it does not read as a fourth strand.

Helper and synthesis pages that are not strand-specific carry no tag (Recommended Analyses, the Overview pages, the Interpretation pages, the Reporting pages).

### Tag styling
```
QUAN   text #0d9488 on #e3f3f0
QUAL   text #db2777 on #fbe3ef
MM     white text on a left-to-right gradient #0d9488 → #db2777   (the blend of the two strands)
RSSI   text #64748b on #eef1f4   (neutral, source not strand)
```
The MM gradient is intentional. It reads as the two strands joined, which is the meaning of the tag.

---

## 6. Recommended tech stack

- **Framework:** Next.js (App Router), matching the podcast site setup.
- **Styling:** Tailwind CSS, with the tokens in Section 7 mapped into theme extensions and a CSS variable layer for the palette.
- **State:** React state for in-session interaction. Server persistence for projects, datasets, codebooks, canvases, and the report draft.
- **Statistics:** the prototype computes some stats in the browser with a normal approximation for p-values. For production, move inferential statistics to a server module using exact t and F distributions, or a vetted library. See Section 13.
- **Charts:** the prototype hand-rolls SVG and CSS bars. A charting library is acceptable as long as it honors the tokens.

---

## 7. Design system

Refined, document-first surface modeled on Apple Pages. The studio accent is purple. Orange is reserved for the brand mark and the Save to Report action.

### Fonts
- **Display and document body:** Newsreader (serif). Titles, italic subtitles, result sentences, theme names, large numerals.
- **UI chrome:** Hanken Grotesk (sans). Toolbar, tabs, rail, inspector, labels, buttons.

### Color tokens
```
--ink:         #1b2426
--ink-soft:    #566065
--ink-faint:   #8a9499
--paper:       #ffffff
--app-bg:      #eef0ef
--panel:       #f7f8f8
--line:        #e2e6e5
--line-soft:   #edf0ef
--accent:      #7c3aed   /* studio accent: active tabs, active rail, eyebrows, primary buttons, charts */
--accent-deep: #6d28d9
--accent-wash: #efe9fb
--accent-tint: #f6f3fd
--orange:      #e2542c   /* brand mark and Save to Report only */
--orange-deep: #c4441f
--orange-wash: #fbe9e2
```
Strand tag colors are in Section 5.

### Shape and depth
```
radius: page surfaces 4px, cards 12-14px, chips and pills 5-20px, fields and buttons 7-9px
shadow: 0 1px 2px rgba(27,36,38,.05), 0 8px 24px rgba(27,36,38,.08)
numerals: tabular lining figures for all statistics, counts, and scores
```

### Color semantics
- Purple means navigation and studio identity. Active tab, active rail item, eyebrow, primary Run button, Ask ReliCheck, chart fills.
- Orange means "send to the deliverable." The brand mark and the Save to Report button. Nothing else.
- Strand colors are categorical, never UI accents.

---

## 8. Shared application shell

Build the shell once as a layout component.

**Top toolbar (52px).** Left: the ReliCheck wordmark with the orange waveform mark, a divider, then the studio identity ("Mixed Methods Studio" with a small purple MM square). Right: Save, Print, Export, and Ask ReliCheck (purple).

**Stage tab bar (58px).** The six stages as tabs, each with its name and italic question. Active tab carries a purple bottom border and purple text.

**Three-column body.**
- **Left rail (about 262px).** The active stage name as a caption, then the stage's sub-pages. Each item shows its label and its strand tag. Active item: purple tint background, purple left border, purple label.
- **Document area (center).** A breadcrumb strip ("Project › Stage › Page"), then the page content in the document-first layout.
- **Report Draft (right inspector, about 300px).** The live saved-analyses list grouped by stage, the running count, and the Open Report Builder action.

---

## 9. Data models

Guidance, not final schema.

```
Project {
  id, name
  design: "convergent" | "explanatory_sequential" | "exploratory_sequential"
  instrumentCredibility: {           // read from RSSI, not computed here
    validated: boolean
    lenses: { psychometricCore, respondentCentered, validityForward }   // 0-100
    scales: [{ key, name, alpha, omega, items }]   // alpha/omega are RSSI's
  }
}

Respondent {
  id
  groups: { tenure, role, ... }
  scaleScores: { Belonging, Voice, Fairness, ... }   // numeric 1-5
  openEnds: [{ questionId, text }]
}

Analysis {                          // any saved analysis result
  id, stageId, pageId, kind
  title, output, chartSpec
  savedToReport: boolean
}

ReportDraftItem { stageId, stageName, pageId, pageName, analysisId }

Code { id, name, color }
Segment { responseId, questionId, text, codeId | null }   // production: character offsets for true range selection
Theme { id, name, codeIds: [...], segmentCount (derived), promoted: boolean }

JointRow {
  id, quantFindingId, themeId
  relationship: "convergent" | "complementary" | "contradictory" | "expansion" | null
  metaInference: string
}
```

---

## 10. Stage notes

**Overview.** Framing, no analysis. Project Snapshot follows the Run / Configure / Learn pattern. Sample & Data Sources renders categorical profile cards (see the fused-shell prototype). Purpose & Research Question stores the research questions and the design, which frame the integration logic downstream.

**Instrument Quality.** Read-only from RSSI. Credibility Summary shows the three lens scores (Psychometric Core, Respondent-Centered, Validity-Forward) with a source banner and an Open in RSSI action. This is the visible expression of the boundary in Section 2.

**Descriptive Analysis.** The quantitative pages use the analysis surface from the quantitative-workspace prototype (live descriptives, distributions). The QUAL pages use the coding surface from the qualitative-workspace prototype (codebook, themes, tagging). Item / Theme Summaries and Qual → Quant are the MM pages that show both strands together.

**Inferential Analysis.** The quantitative tests produce findings in the results-document style. Joint Displays and Meta-inferences (MM) open the Integration canvas. Coding Agreement κ and Pattern Matching (QUAL) operate on the coded data.

**Interpretation.** Synthesis. Key Findings, Evidence Notes, Practical Meaning, Limitations, Recommended Actions, Teaching Moments, AI Interpretation, and Decision Readiness turn integrated results into meaning, limits, and decisions. To build next.

**Reporting.** The deliverable. Report Builder is a full document-first screen (built in the fused-shell prototype) that reads the saved analyses and lays them into report sections, with the Findings section populated by saved items and an export bar (Word, PDF, Slides). The remaining pages are the individual report sections.

---

## 11. Cross-cutting threads

Two distinct threads run across the studio. Both must be wired to shared state.

**Save to Report.** Every analysis page carries a Save to Report action (orange). Saving adds the analysis to the Report Draft inspector and to the Report Builder Findings section, grouped by stage. This is the thread that closes the loop from "what do we have" to "how do we report it."

**Promote to Integration.** Inside the analysis surfaces, a quantitative finding or a qualitative theme promotes into the Integration canvas, where the two pair into a joint display with a named relationship and a written meta-inference. This is the thread that produces the signature mixed methods output.

The two are independent. A finding can be promoted to the canvas, saved to the report, both, or neither.

---

## 12. Integration canvas and credibility

The canvas (Joint Displays, Meta-inferences) pairs a promoted quantitative finding with a promoted qualitative theme. Each row carries a relationship (Convergent, Complementary, Contradictory, Expansion) and an editable meta-inference. An Integration Credibility score (0 to 100, with Provisional / Developing / Defensible states) rises as rows carry both a named relationship and a written inference. Defensibility checks list whether pairings exist, whether each has a relationship, whether each meta-inference is written, and whether more than one finding was examined. See the integration-canvas prototype.

---

## 13. Statistics: prototype versus production

| Concern | Prototype | Production |
|---|---|---|
| Dataset | Seeded generator in JS | Real project data |
| Descriptives | Live, exact | Same |
| t test | Pooled variance | Pooled and Welch, exact df |
| Effect size | Cohen's d | Cohen's d with confidence interval |
| Correlation | Pearson r | Pearson and Spearman |
| P-values | Normal approximation | Exact t and F distributions |
| Regression | Stubbed | Linear and logistic, with assumption checks |

The normal approximation is accurate near n = 150 to 250 and drifts at small samples. Do not ship it.

---

## 14. Voice rules for generated copy

- No em dashes. Use commas, colons, or separate sentences.
- No personification. Do not give data, results, surveys, or rooms human agency. Write "Early-career staff report lower belonging," not "the data tells us." Use "associated with" for correlation.
- No unintentional anaphora. Do not start consecutive sentences with the same word unless the repetition is clearly earned.
- Formal academic register. Report statistics in APA style (no leading zero on r and p, t with degrees of freedom).

---

## 15. Real versus stubbed in the prototypes

**Real and interactive**
- Fused shell: stage tabs, rail navigation, document rendering, Save to Report into the Report Draft, the Report Builder reading live saved items with removal, cross-navigation.
- Quantitative surface: full analysis builder, live statistics and charts, promote flow.
- Qualitative surface: code selection, tagging and untagging, the popover, live counts, new codes, theme counts, promote flow.
- Integration canvas: pairing, relationship tagging, inline meta-inference editing, live credibility scoring, defensibility checks.

**Stubbed or simplified**
- Save to Report and Promote to Integration are per-file in the prototypes. Wire them to shared state.
- Qualitative segments are pre-split rather than true range selection.
- Cohen's kappa is a fixed display value.
- Regression is a labeled placeholder.
- P-values use a normal approximation.
- Data is generated in the browser.
- Report Builder reorder is visual only (no drag yet).

---

## 16. Suggested build sequence

1. Shared shell: toolbar, stage tabs, rail, document area, Report Draft inspector, tokens in Tailwind.
2. Data layer: project, respondents, scales, instrument-credibility read from RSSI.
3. Overview and Instrument Quality stages.
4. Descriptive Analysis: quantitative surface with server-side statistics, then the QUAL coding surface.
5. Inferential Analysis: tests, then the canvas-backed MM pages.
6. Shared state for Save to Report and Promote to Integration.
7. Integration canvas with credibility scoring.
8. Interpretation stage.
9. Reporting stage and Report Builder.
10. Design setup step and design-driven stage framing.
11. Inter-rater reliability from two coders, regression, assumption checks.

---

## 17. Open decisions

- **Survey developer app name.** Still a placeholder ("Siri"). Confirm before shared navigation is built.
- **RSSI acronym expansion.** Define the full phrase so first mentions can be spelled out.
- **Where coding lives.** Coding currently sits in the Descriptive Analysis QUAL pages. Confirm whether it stays there or becomes an upstream step that feeds themes in.
- **Charting library.** Choose one that honors the tokens, or keep hand-rolled SVG.
- **Report Builder ordering.** Confirm whether sections and findings are drag-reorderable and whether section content is editable in place or generated on export.
