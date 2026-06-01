# Handoff: Fix the MM Studio Report Export

**For:** Claude Code
**From:** Cowork session, 2026-05-24
**Project:** ReliCheck / MM Studio
**Status:** Diagnosis complete, target output produced, implementation pending

---

## TL;DR

The current MM Studio report export produces a `.docx` file that is technically a Word document but is really an HTML fragment in disguise. It also generates content that reads like a status page instead of a defensible research report. This brief explains both problems, points at two reference files in this folder (one broken, one fixed), and gives a prioritized fix list.

**Two reference files in this same `test_datasets/` folder:**

- `mm_report_128_ORIGINAL.docx` — what MM Studio currently produces for Project #128. The broken one.
- `MM_Report_128_DEI_DataTest_Improved.docx` — the target shape. Same project, same numbers, native Word.

Diff them in Word. The difference in how each opens, navigates, and reads is the entire point of this work.

---

## Problem 1: It is not actually a Word document

### What we observed

Unzipping `mm_report_128_ORIGINAL.docx` reveals:

```
word/document.xml          2,056 bytes
word/afchunk.mht           5,664 bytes
```

The body of `document.xml` is one element:

```xml
<w:body>
  <w:altChunk r:id="htmlChunk" />
  <w:sectPr>...</w:sectPr>
</w:body>
```

`afchunk.mht` is an MHT-encoded HTML fragment containing all the actual content (headings, tables, prose). Word renders altChunk on open by interpreting the embedded HTML.

### Why this is a problem

1. **Compatibility.** altChunk is a Microsoft Word desktop feature. Google Docs, Pages, Word for Web, LibreOffice, and most document parsers either ignore it entirely or render an empty document. `python-docx` reads the file as 0 paragraphs and 0 tables, because from its perspective the body is empty.
2. **Round-trip instability.** If a user opens the file in desktop Word and saves it, Word converts the altChunk to native elements using its own defaults. The output then looks different from what MM Studio generated. We've lost determinism.
3. **No real document structure.** The "Heading 2" elements are HTML `<h2>` tags styled with CSS. They are not real Word heading styles. That means: no navigation pane, no working table of contents, no outline view, no accessibility tree, no `Find Heading` in any downstream tool.
4. **Credibility.** MM Studio's pitch includes "defensible research output." Shipping a file that competitor tools can't reliably open undercuts that.

### The fix

Replace the altChunk-based export with a native Word document built using the `docx` skill (or `docx-js` directly). The target file `MM_Report_128_DEI_DataTest_Improved.docx` in this folder was built this way — 42 native `<w:p>` paragraphs, 2 native `<w:tbl>` tables, real `Heading1`/`Heading2`/`Heading3` styles with `outlineLevel` set.

Reference implementation: see the `build_report.js` pattern in `/Users/don/Library/Application Support/Claude/local-agent-mode-sessions/.../outputs/dei_report/build_report.js` if it's still available; otherwise the docx skill documentation has the templates.

---

## Problem 2: The content reads like a status page, not a report

Three of the four sections in `mm_report_128_ORIGINAL.docx` are mostly stubs telling the reader to go do work somewhere else:

- Section 3 (Qualitative): *"No themes coded in this project yet. Build a codebook…"*
- Section 4 joint display: *"No joint display rows yet. Open the Joint Display workspace…"*
- Section 4 evidence matrix: *"No matrix rows yet. Open the Matrix workspace…"*

Section 1 is two-word fragments (*"compare groups, find themes"*) that read like database values rather than prose. The Limitations paragraph is generic boilerplate about explanatory designs that does not mention any of *this* study's actual limitations (3-item scale, α = 0.62, missing qual coding, no sample frame documentation).

### Why this is a problem

A reader who exports the report at this stage receives a document that mostly describes its own incompleteness. The numbers it does contain are presented without interpretation — α = 0.62 is reported with no reference to conventional thresholds; item means are listed with no pattern called out; the Strength Index gives subscores with no explanation of what they mean or what would change them.

The defensibility comes from the hybrid pattern already shipped for Reliability ([[project_mm_studio_hybrid_engine]]): statistics write the finding, AI improves the explanation. The report export does not yet apply that pattern.

### The fix

Two changes to the report generation logic:

1. **Gate sections, or annotate absences.** If a section has no data, either suppress it OR render it with a one-sentence interpretation of *why the absence matters for this specific report.* The rewrite handles Section 3 like this:

   > Status. No themes have been coded in this project yet.
   >
   > Why this matters here. The quantitative finding above — a clear gap between the organization-level item and the leadership-specific item — is the kind of result an explanatory sequential design is built to interrogate. Without coded qualitative evidence, that gap is described but not explained.
   >
   > Next step to make this section reportable. Build a starter codebook…

   That is the pattern: status, why-it-matters-for-this-report, concrete next step.

2. **Run the existing numbers through the hybrid engine before rendering.** Every number that lands in the report should arrive with an interpretation sentence attached. Examples from the rewrite:

   - α = 0.62 → *"below the 0.70 threshold conventionally used to support group-level comparisons … with only three items, α is bounded by the small item count as much as by inter-item correlation."*
   - Item means 3.22 / 2.82 / 2.68 → *"On a 1–5 scale where 3 is the neutral midpoint, only the first item sits above neutral … the 0.54-point gap between the highest and lowest item is meaningful relative to the standard deviations (~0.80), suggesting respondents distinguish between the organization's stated posture on equity and what they observe from leadership specifically."*
   - Strength Index domain scores → render with a third column: *"What the score is telling you."*

   This is the same hybrid-engine pattern already shipped for the Reliability panel preview. Extend it to the report export.

---

## Side-by-side comparison

| | `mm_report_128_ORIGINAL.docx` | `MM_Report_128_DEI_DataTest_Improved.docx` |
|---|---|---|
| File structure | HTML in altChunk; empty Word body | 42 native paragraphs, 2 native tables |
| Opens correctly in | Desktop Word only | Word, Google Docs, Pages, Word for Web, LibreOffice |
| Real Word headings | No (HTML `<h2>`) | Yes (Heading 1/2/3 with outline levels) |
| Cover block | Title + metadata | Title, metadata, headline paragraph stating finding + constraint |
| Reliability section | α reported, no interpretation | α reported with thresholds named, plus the three-item-scale caveat |
| Item descriptives | Means/SDs only | Same table, plus prose calling out the Org > Contributions > Leadership pattern |
| Qualitative section | "No themes coded yet" | Same status, plus why-it-matters and a concrete next step |
| Joint display / matrix | Two empty stubs | Same status, framed as dependencies on the qual coding pass |
| Strength Index | Scores only | Scores + "What the score is telling you" column |
| Limitations | Generic boilerplate | Three named limitations grounded in this study's data |
| Next actions | None | Four ordered moves, highest-leverage first |

---

## Prioritized fix list

In order of leverage. Do them in this order; the first one unblocks everything else.

### 1. Swap the export from altChunk/MHT to native docx

**File to change:** the report export endpoint. Search the codebase for `altChunk`, `afchunk`, or `mhtDocumentPart` to locate it.

**Change:** instead of writing an HTML fragment to `word/afchunk.mht` and referencing it from `document.xml`, build native Word XML using the `docx` skill. The skill handles styles, tables, headings, headers/footers, and validation.

**Acceptance test:** open the exported file with `python-docx` and confirm `len(doc.paragraphs) > 0` and `len(doc.tables) > 0`. The original returns zero for both.

### 2. Gate-and-annotate empty sections

**Change:** in the report generation logic, every section template should check whether its inputs are populated. If not, render:

- the current status (one sentence)
- why the absence matters *for this specific report* (one sentence, generated from the other sections' findings)
- the concrete next step to populate it (one sentence)

Never just "go do this in another workspace." The reader needs the implication.

**Acceptance test:** generate a report for a project with no coded themes. The Qualitative section should mention which specific quantitative finding is being left unexplained, not generic instructions.

### 3. Pipe every reported number through the hybrid engine before rendering

**Change:** the report generator currently emits values directly. Wrap each value in a function that produces a value + interpretation sentence. The interpretation rules are deterministic (thresholds for α, neutral-point comparison for Likert means, gap-vs-SD comparison for item ranges) — Haiku can be called for the rewrite layer where needed, exactly like the Reliability panel already does.

**Acceptance test:** no number in the rendered report should appear without an accompanying interpretive clause. α = 0.62 alone fails the test; α = 0.62 with "below the 0.70 threshold" passes.

### 4. Add a "What the score is telling you" column to the Strength Index table

**Change:** the Strength Index table currently has two columns (Domain, Score). Add a third column that explains each subscore in plain language tied to the project's data. The rewrite has working text for all six domains — see the `strengthRows` array in the reference implementation.

### 5. Add a headline paragraph to the cover block

**Change:** below the title and metadata, render a single paragraph (2–3 sentences) that states the principal finding, the principal constraint, and the Strength Index band. This gives the reader the report in 50 words before they read 500.

---

## Where to find things

- **Target output (open this first):** `test_datasets/MM_Report_128_DEI_DataTest_Improved.docx`
- **Original broken output for diffing:** `test_datasets/mm_report_128_ORIGINAL.docx`
- **Test datasets to regenerate reports against:**
  - `test_datasets/MM_Test_01_Hotel_Experience_QualHeavy.xlsx` (100 respondents, qual-heavy)
  - `test_datasets/MM_Test_02_Emotional_Intelligence_QuanHeavy.xlsx` (250 respondents, 24-item EI scale with α ≈ 0.89–0.91 per subscale, 1 outcome variable)
  - `test_datasets/MM_Test_03_Marketing_Research_AllQual.xlsx` (150 respondents, all qual + demographics)

After implementing, regenerate the report for one of these datasets and diff it against the target file. If the shape matches, the structural fix is done. If the interpretive prose is missing or generic, the hybrid-engine wiring still needs work.

---

## Notes on the underlying data in the reference report

The numbers in `MM_Report_128_DEI_DataTest_Improved.docx` are the same ones MM Studio produced for Project #128:

- N = 150, 3 Likert items, Cronbach α = 0.62
- L1_OrgPromotesEquity: M = 3.22, SD = 0.80
- L2_ContributionsValued: M = 2.82, SD = 0.85
- L3_LeadershipCommitment: M = 2.68, SD = 0.80
- Strength Index 73 / 100 (USABLE)
- Domain subscores: Reliability 12/25, Factor 11/20, Item 20/20, Response 14/15, Open-End 9/10, Action 7/10

Nothing in the rewrite invents data. The improvement is entirely in how those numbers are framed, structured, and interpreted.

---

## Out of scope for this handoff

- Charts and visualizations (none in either file; can be added in a later pass).
- Multi-language support (current export is English-only; rewrite is English-only).
- PDF export (separate code path; same hybrid-engine wiring would apply when it's tackled).
- Theme suggester / qual coding logic (separate open thread; see [[project_qual_theme_generation]] in memory).
