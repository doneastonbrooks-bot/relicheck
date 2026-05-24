# ReliCheck Repositioning Strategy

**Date:** 2026-05-11
**Author:** Drafted with Donald
**Status of the live site:** Stage 1 of the credibility repositioning shipped (homepage hero rebrand, Survey Strength Score showcase, plain-language feature cards, new Import Data page, new Upload Your Survey path, app analytics tabs relabeled with plain-language questions). The remaining work, listed at the end of this document, is what this strategy is meant to drive.

## Core positioning, in one sentence

ReliCheck helps you know whether your survey results are strong enough to trust, explain, and defend.

Three secondary lines that should travel with it:

> Build it here or bring it from anywhere.
> Plain-language reports backed by rigorous analysis.
> Survey results you can explain and defend.

The site's job is to make that promise immediately understandable to a non-technical reader while preserving credibility for the people who care about reliability, validity, factor analysis, and evidence quality. The product should feel like TurboTax for survey credibility, not SPSS.

What the site is not selling: another survey builder. We do not need to win that fight. SurveyMonkey and Qualtrics already own those words. The builder remains a feature, but it is no longer the headline.

---

## A. Revised homepage wireframe with section-by-section copy

The hero leads. The next section reframes the choice. The Survey Strength Score section gives the visitor a tangible artifact. The five plain-language checks explain what the product actually does. A short audience strip shows who the product is for. The sample-report block sells through the deliverable. A closing CTA repeats the primary action.

### Section 1: Hero

**Kicker (small, all caps):** Survey credibility platform

**Headline:** Surveys you can trust. Insights you can explain.

**Subheadline:** ReliCheck helps you know whether your survey results are strong enough to use. Upload results from another tool or build a survey here, then get a plain-language report showing what is strong, what is weak, and what to fix next.

**Primary button:** Check my survey

**Secondary button:** View sample report

**Tertiary link, small:** Build a new survey

**Trust line under the buttons:** Used by X research teams across Y surveys analyzed.

**Right side of hero (image area):** A product screenshot of the Survey Strength Score card. Right rail: a small pill that reads "AI + Psychometrics. AI helps you write, analyze, and explain. Reliability and validity checks help you know what to trust."

Notes on changes from current: the primary CTA already reads "Check my survey." The secondary button currently reads "Build a new survey" and should switch to "View sample report." "Build a new survey" demotes to a smaller third link below the two main buttons.

### Section 2: Start with what you have

**Kicker:** Three ways to start

**Headline:** Pick the path that matches what you already have.

**Three cards:**

1. **I already have survey results.** Upload CSV, Excel, SurveyMonkey, Qualtrics, Google Forms, or Microsoft Forms exports. ReliCheck reads your scales and runs the strength report on the responses you already collected.
   CTA: Upload results →

2. **I need to build a survey.** Create a survey with question quality checks built in from the start. Templates, validated scales, and AI question review keep weak items out before launch.
   CTA: Start a survey →

3. **I need a report.** Turn results into a clear summary for leaders, teams, funders, reviewers, or decision-makers. Plain-language strength score, item flags, and recommended fixes.
   CTA: See a sample report →

**Optional fourth row, smaller and lower-weight:** Upload your survey (Qualtrics .qsf or pasted text). This already lives on the homepage as part of the Four Ways to Start. The repositioning brief now wants three primary cards plus this as a smaller affordance below them, since "upload your survey" overlaps conceptually with the import-data card for newcomers.

### Section 3: Survey Strength Score showcase

See deliverable E below for the full section spec. This sits as the third major section on the homepage.

### Section 4: What ReliCheck checks for you

**Kicker:** Five plain-language checks

**Headline:** Five questions ReliCheck answers about your survey.

**Five cards** (plain-language title, technical subtitle, body):

1. Do your questions work together? *Reliability analysis, item-total correlations, Cronbach's alpha.* ReliCheck checks whether the items in each scale move together, flags items that weaken consistency, and tells you which item to revise or drop first.
2. What is your survey really measuring? *Factor analysis, KMO, Bartlett's test, factor loadings.* ReliCheck surfaces the underlying dimensions in your data, names them in plain English (with AI suggestions you can edit), and shows which items belong to which factor.
3. Are your results strong enough to use? *Response quality, missing data, descriptive patterns.* ReliCheck reviews response counts, completion rates, missing-data patterns, and warning signs before you read the headline.
4. What are people saying in their own words? *Open-ended theme analysis and qualitative summaries.* ReliCheck groups open-ended responses into themes with example quotes, sentiment, and counts you can paste into a report.
5. What should you fix next? *Item-level recommendations and survey revision guidance.* Every report ends with practical recommendations: items to revise, scales to split, and decisions you can defend.

### Section 5: Built for people whose numbers get questioned

**Kicker:** Real-world teams

**Headline:** Built for people whose numbers get questioned.

**Five audience cards:**

- **Education and accreditation.** Course evaluations, student belonging, school climate, program review, accreditation evidence.
- **Program evaluation.** Grant reporting, pre/post measures, community needs assessments, outcomes reporting.
- **HR and teams.** Engagement, onboarding, exit, climate, culture, and employee experience surveys.
- **Research support.** Validated scales, manuscript-ready exports, factor analysis with rotation choices.
- **Customer and client feedback.** NPS, CSAT, post-purchase, onboarding surveys with sentiment and theme extraction.

### Section 6: See the output first

**Kicker:** Before you sign up

**Headline:** What a ReliCheck report actually looks like.

**Subhead:** The fastest way to understand the value is to read a finished report. Open a sample and see the Survey Strength Score, the item flags, and the plain-language summary on a real survey.

**Buttons:** Browse sample reports. Open the interactive demo.

### Section 7: Closing CTA

**Headline:** Know whether your survey is strong enough to explain, defend, and act on.

**Subhead:** Start free. Upload results from another tool, upload the survey you already wrote, or build from scratch. The Survey Strength Score, reliability checks, and plain-language report are included from day one.

**Buttons:** Check my survey. View sample report.

---

## B. Revised navigation structure

**Current nav:** Product / Solutions / Templates / Resources / Pricing.

**Proposed nav (left to right):**

1. **Check a Survey** — the most important nav item, and the one that anchors the whole repositioning. Mega menu has Survey Checkup overview, the four checks (Question quality, Scale strength, Pattern clarity, Open-ended themes), and the Survey Strength Score.
2. **Import Data** — promotes the existing import-data.html page into the top-level nav. Mega menu shows source-specific paths (SurveyMonkey, Qualtrics, Google Forms, Microsoft Forms, Excel, CSV) plus "Connect a tool" with the coming-soon connector list.
3. **Build a Survey** — the form-builder lives here now, no longer the front door. Mega menu lists templates, AI question review, collect, branding/logic.
4. **Sample Reports** — the existing samples gallery, promoted because seeing the output is the highest-converting moment for non-technical visitors.
5. **Solutions** — keeps the existing personas grid (Education, HR, Program evaluation, Customer feedback, Research, Mixed methods).
6. **Pricing** — unchanged.

Things that move OUT of the top nav and into Resources (now a small footer-prominent group rather than a primary nav slot):

- Methodology
- Reliability guide
- Validity guide
- Survey design guide
- Compare (vs. SurveyMonkey / Qualtrics)
- Help center
- Blog
- Customer stories

Sign in and Get started buttons stay on the right side of the nav as they are today. Mobile drawer follows the same order as the desktop nav.

---

## C. Three hero variations

Each pair below could ship as a live A/B test, or one can be chosen as the default and the other two banked.

### Variation 1 (current direction, refined)

**Headline:** Surveys you can trust. Insights you can explain.

**Subheadline:** ReliCheck helps you know whether your survey results are strong enough to use. Upload results from another tool or build a survey here, then get a plain-language report showing what is strong, what is weak, and what to fix next.

Tone: confident, plain-language. The "trust" and "explain" framing carries the whole pitch.

### Variation 2 (more direct, more outcome-oriented)

**Headline:** Know whether your survey is strong enough to trust.

**Subheadline:** Upload results from SurveyMonkey, Qualtrics, Google Forms, or your own spreadsheet, or build a survey here. ReliCheck checks the questions, the scales, and the responses, then explains what is strong and what to fix in language anyone on your team can use.

Tone: leads with the user's question. Names the competitors so visitors who arrive from a comparison search land in their lane immediately.

### Variation 3 (positioning-forward, slightly more daring)

**Headline:** The survey checkup your data has been missing.

**Subheadline:** ReliCheck turns survey responses into credible evidence. Bring results from any tool or build one from scratch, then get a Survey Strength Score, item-level flags, and a plain-language report you can defend.

Tone: introduces "checkup" as a category-defining word. Makes the Survey Strength Score the noun that carries the page.

Recommendation: ship Variation 1 as default since it preserves continuity with the current copy, and queue Variation 3 as a stretch test for the moment we are ready to commit to "checkup" as a brand word.

---

## D. New Import Data page outline with copy

This page already exists at `import-data.html`. The current version pitches three generic upload paths (file, paste, connector). The repositioning brief asks for source-specific cards. Rewrite the middle section accordingly. The rest of the page stays as it is.

### Hero (current copy is good, retain)

**Kicker:** Import data

**Headline:** Already have survey results? Bring them to ReliCheck.

**Subheadline:** Upload results from SurveyMonkey, Qualtrics, Google Forms, Microsoft Forms, Excel, or CSV. ReliCheck checks the strength of your survey and turns your results into a plain-language report in minutes. You do not have to leave your current survey tool to use ReliCheck.

**Buttons:** Get started free. See a sample report.

### Source-specific path cards (new structure)

A row of five cards, each describing the import path for one source. Each card has the source name, a short instruction, a "what we read" note, and a CTA.

1. **SurveyMonkey export.** Download your responses as CSV or XLSX from SurveyMonkey's Analyze section. Drop the file into ReliCheck and we read your scales, items, and demographics. *Coming soon: direct API connector.* CTA: Upload CSV →
2. **Qualtrics export.** Export from Qualtrics as CSV, TSV, or SPSS. ReliCheck reads the column headers and asks you which columns are Likert items. The strength report runs from there. *Coming soon: direct API connector and .qsf instrument import.* CTA: Upload export →
3. **Google Forms / Sheets.** Open responses in Google Sheets, download as CSV, upload here. Or paste a block of cells straight into ReliCheck. *Coming soon: Google Sheets sync.* CTA: Upload or paste →
4. **Microsoft Forms.** Export responses as Excel from Microsoft Forms. Drop the .xlsx in ReliCheck and mark your Likert items in the column mapper. CTA: Upload Excel →
5. **Excel / CSV.** Any cleanly formatted spreadsheet works. One row per respondent, one column per question. ReliCheck handles wide and long formats. CTA: Upload your file →

Each card carries one short line at the bottom: "You do not need to leave [source name] to use ReliCheck."

### What happens next (retain existing 3-step block)

Map your scales. Run the strength check. Share the report. Existing copy stays.

### Closing CTA

Existing copy is good. No change.

---

## E. Survey Strength Score section with copy and visual layout

This section is the signature moment on the homepage. It already exists in a working form on `index.html`. The spec below is the canonical reference for the copy.

### Headline and subhead

**Kicker:** The ReliCheck Survey Strength Score

**Headline:** One score. Five checks. Clear next steps.

**Subhead:** Every survey gets a clear strength profile, like a credit score for your survey. One number for the headline, five plain-language checks for the detail, and a recommended action you can take this afternoon.

### Visual layout (two-column on desktop, stacked on mobile)

**Left column, anchored:** A circular SVG ring scoring 82 of 100. Underneath the ring: a green pill that reads "Strong." Below the pill: "Ready to report with minor cautions." Below the readout: a small legend with three bands. Green dot Strong, ready to report. Amber dot Needs review, items may need attention. Red dot Use caution, results may not be strong enough for major decisions.

**Right column, taller:** Five rows, each with a label on the left, a small score on the right, a thin horizontal progress bar underneath, and a one-line subhead. Numbers shown are illustrative.

- Question Clarity — 17 / 20. Are the questions easy to understand?
- Scale Strength — 22 / 25. Do related questions work together?
- Response Quality — 13 / 15. Do you have enough usable responses?
- Pattern Clarity — 17 / 20. Are the findings clear or scattered?
- Open-Ended Themes — 7 / 10. Do written responses reveal useful patterns?

### Footer line (centered, below the two columns)

"ReliCheck does the technical work underneath, then explains the results in language your team can actually use. The math behind the score (reliability checks, factor analysis, missing-data review, open-ended theme summaries) is one click away when you want to see it."

### Two band-comparable scoring rubric (for reference, not on the homepage)

This is for the marketing team and the methodology page, so the score bands are documented somewhere stable.

- 90 to 100. Excellent. Results are ready for high-confidence interpretation.
- 80 to 89. Strong. Results are sound with minor improvements recommended.
- 70 to 79. Usable. Results can be used, but several improvements are recommended.
- 60 to 69. Needs strengthening. Use cautiously and revise before major decisions.
- Below 60. Weak. Substantial revision is needed before interpretation.

---

## F. Revised "Analyze" / "Survey Checkup" page outline

The product's analysis dashboard, currently labeled "Analyze" in the workspace navigation, becomes "Survey Checkup." The marketing-side description and any cross-links use that name from now on.

### What the page does

It runs the full strength check on the active survey. The Survey Strength Score lands at the top, with the five domain tiles, the breakdown, the strengths and the items to review, and a "Go to strengthening plan" button. Deep-dive tabs sit underneath for visitors who want the full math.

### Section order on the in-app page

1. **Page header.** "ReliCheck Survey Strength Index" in a serif title, with sample size, completion percentage, and last response date as a small meta line.
2. **Big score card.** Circular ring score, status pill, plain-language readout.
3. **Six domain tiles.** Reliability Strength, Factor Structure, Item Quality, Response Quality, Open-ended Alignment, Actionability. Each tile shows the current score, the max, and a progress bar.
4. **Score breakdown plus Top strengths.** Weighted breakdown bars on the left, top strengths on the right.
5. **Needs review plus Recommended next step.** Red exclamation list of negative signals, plus a card with a recommended action and a "Go to strengthening plan" button that deep-links to the lowest-scoring domain's dashboard.
6. **Evidence row.** Three small cards. Reliability evidence (alpha and split-half). Factor readiness (KMO, factors retained, variance explained). Qualitative alignment (top theme, open responses, attention flags).

### Five deep-dive tabs (currently shipped, plain-language labels)

These already render in the analytics tab bar with two-line labels (plain-language primary, technical secondary).

- Strength Index. How strong is this survey?
- Do your questions work together? Reliability analysis.
- What do people's answers show? Description analysis.
- What is your survey really measuring? Validity analysis.
- What themes appear in written responses? Open-ended analysis.

### Page-level rename

In the workspace left navigation, the link currently labeled "Analytics" should read "Survey Checkup." That single label change does most of the repositioning work for this page.

---

## G. Sample report page outline

The current `samples.html` is a gallery of links. The brief asks for the sample page to lead with the answer to the visitor's actual question: can this survey be trusted? Restructure so the first thing visitors see is the answer, not the gallery.

### Section 1: Lead with the answer

**Headline:** See what a ReliCheck report looks like.

**Subhead:** Every ReliCheck report opens with the same question: can this survey be trusted? Here is what that looks like on a real survey.

**Featured sample card (large, full-width):**

- Survey: Employee Engagement Pulse 2026 (sample)
- **Can this survey be trusted?** Mostly yes.
- **Strength Score:** 82 / 100. Ready to report with minor cautions.
- **Use case:** Safe for internal planning and quarterly reporting.
- **Main caution:** Two items weaken the teamwork scale.
- **Recommended action:** Revise or remove those items before the next administration.
- CTA: Open the full sample.

### Section 2: What the report contains

A bulleted-by-card breakdown of the sections every ReliCheck report includes.

- **Survey Strength Score.** The headline number, status band, and band-specific guidance.
- **Executive summary.** AI-assisted plain-language overview of the finding, the sample, and the caveats.
- **Key findings.** Bullets pulled from the strongest signals across all six domains.
- **Reliability check.** Cronbach's alpha, split-half, item-total correlations, and "alpha if deleted."
- **Factor analysis summary.** Number of factors retained, variance explained, KMO, Bartlett's significance, factor names (AI-suggested, user-editable).
- **Open-ended themes.** Theme names, counts, sentiment, example quotes.
- **Item flags.** Items that hurt the scale, items with ceiling or floor effects, items with negative loadings.
- **Recommended fixes.** Per-item revision guidance, scales to split, scales to drop.
- **Technical appendix.** The full math, for the reader who wants it.

### Section 3: Sample gallery

Four to six sample reports, each as a card with title, audience, score, and a one-line takeaway. Open each one in a new tab.

### Section 4: Try the interactive demo

A single centered card linking to `samples/interactive-course-evaluation.html` (the live interactive sample). This card was previously trapped inside a 3-column grid with two empty columns; centered in a 560-pixel wrapper now.

### Section 5: Closing CTA

"Run a sample on your own data. Start free." Standard CTA pair.

---

## H. Wording changes across the site

Below are concrete before-and-after pairs. The pattern is the same throughout: a plain-language phrasing leads, the technical name comes underneath in smaller type or in a tooltip.

| Where | Before | After |
| --- | --- | --- |
| Hero secondary CTA | Build a new survey | View sample report |
| Hero tertiary link (new) | — | Build a new survey (smaller, lower-weight) |
| Top nav, item 1 | Product | Check a Survey |
| Top nav, item 2 | Solutions | Import Data |
| Top nav, item 3 | Templates | Build a Survey |
| Top nav, item 4 | Resources | Sample Reports |
| Top nav, item 5 | Pricing | Solutions |
| Top nav, item 6 | (sign-in chip) | Pricing |
| Workspace left nav | Analytics | Survey Checkup |
| Analytics tab 1 | Reliability Analysis | Do your questions work together? *Reliability analysis* |
| Analytics tab 2 | Description Analysis | What do people's answers show? *Description analysis* |
| Analytics tab 3 | Validity Analysis | What is your survey really measuring? *Validity analysis* |
| Analytics tab 4 | Open-Ended Analysis | What themes appear in written responses? *Open-ended analysis* |
| Footer Resources column heading | Resources | Learn |
| Methodology page intro | "ReliCheck applies the following statistical methods..." | "Here is the math behind the strength score, written for the reader who wants the details." |
| Pricing page hero | "Plans and pricing" | "What you get on each plan" |
| Reliability guide first sentence | "Reliability is whether your measurement is consistent." | "Reliability is whether your survey is a steady ruler. A steady ruler is the first thing you need before you can trust a measurement." |
| Validity guide first sentence | "Validity is whether your measurement reflects what you intended to measure." | "Validity is whether you brought the right ruler. A steady ruler still has to measure the right thing." |
| Compare page intro | "ReliCheck vs SurveyMonkey vs Qualtrics" | "Which tool should I use?" |
| Compare page table header | "Feature comparison" | "What each tool is built to do" |
| AI features page hero | "AI in ReliCheck" | "AI that explains, not AI that decides." |
| Sample report page lead | (gallery of links) | (the featured sample card from deliverable G above) |
| Empty state on the My Surveys page | "No surveys yet" | "No surveys yet. Upload one from another tool, or build a fresh one here." |
| Workspace home Quick Action 1 | New survey | New survey |
| Workspace home Quick Action 2 | (Upload survey, already added in Phase 50) | Upload survey |
| Workspace home Quick Action 5 | Latest results | Latest results |

Specific words to avoid in body copy when speaking to first-time visitors:

- Cronbach's alpha
- KMO
- Bartlett's test
- Factor loading
- Eigenvalue
- Sampling adequacy

These are not removed from the site. They appear in the methodology page, the reliability guide, the validity guide, and inside the analytics dashboard once a visitor has signed in and is reading their own report. They should not appear in headlines, hero copy, or feature card titles aimed at first-time visitors.

Words to use in body copy aimed at first-time visitors:

- Trust
- Defend
- Explain
- Check
- Score
- Strong
- Needs review
- Use caution
- Question quality
- Scale strength
- Response quality
- Pattern clarity
- Open-ended themes

---

## I. How this positioning keeps ReliCheck from looking like a smaller SurveyMonkey or Qualtrics

The risk with any new survey product is that visitors arrive, see a builder, and slot it mentally next to SurveyMonkey. Once that comparison sets in, ReliCheck loses. SurveyMonkey is bigger, has more templates, has more integrations, and has years of habit on its side. The same applies to Qualtrics on the enterprise end.

The repositioning answers the comparison before the visitor makes it. The primary CTA is not "Create a survey." It is "Check my survey." That single difference moves ReliCheck out of the builder category and into the credibility category. A visitor who clicks "Check my survey" cannot also be wondering why they would leave SurveyMonkey, because they are not being asked to leave it. They are being asked to check what they already have.

The Import Data page makes this concrete. SurveyMonkey, Qualtrics, Google Forms, Microsoft Forms, Excel, and CSV are listed as supported sources. Each card carries the line "You do not need to leave [source] to use ReliCheck." That single line turns SurveyMonkey and Qualtrics from competitors into upstream data sources.

The Survey Strength Score gives the product a noun that the other platforms do not have. SurveyMonkey has surveys. Qualtrics has experiences. ReliCheck has the Survey Strength Score. A noun the competitors do not own is the easiest way to be remembered.

The plain-language framing carries the rest. SurveyMonkey markets to people who need to collect feedback. Qualtrics markets to enterprises that need a feedback platform. ReliCheck markets to anyone whose numbers can get questioned: an instructor defending a course evaluation, an HR lead defending an engagement score, a program evaluator defending an outcome report to a funder, a researcher defending a manuscript. None of those people are looking for a form builder. They are looking for evidence that their numbers hold up. The site needs to speak directly to that question.

Technical credibility stays in place. Methodology page, reliability guide, validity guide, and the analytics deep-dive all carry the math. Reviewers and peer auditors still find what they need. But the visitor sees plain language first, and the math second, and that ordering is what keeps the product from looking like a generic form tool.

---

## What is already shipped against this spec

For reference, these pieces of the strategy are live as of 2026-05-11:

- Homepage hero with "Surveys you can trust" headline and the "Check my survey" primary CTA.
- Four Ways to Start section (currently four cards; will reduce to three primary cards plus a smaller fourth per this spec).
- Survey Strength Score showcase section with the 82-of-100 ring and the five plain-language dimension bars.
- Five plain-language feature cards ("Do the questions make sense?" through "What should you fix next?").
- Real-world teams persona section.
- See-the-output sample CTA above the closing band.
- New Import Data page with three upload paths (will expand to five source-specific cards per this spec).
- New Upload Your Survey page and end-to-end paste-or-.qsf-file flow.
- App analytics tabs with plain-language primary labels.
- App-side Survey Strength Index page with the six-domain composite, breakdown, evidence cards.

## What still needs work against this spec

1. Hero secondary button swap from "Build a new survey" to "View sample report." Move "Build a new survey" to a small third link.
2. Reduce Four Ways to Start to three primary cards (matches Donald's spec), with "Upload your survey" as a smaller fourth row.
3. Rebuild the top navigation per deliverable B above (Check a Survey, Import Data, Build a Survey, Sample Reports, Solutions, Pricing). This propagates to every marketing page, which is roughly 60 files.
4. Rebuild Import Data middle section with five source-specific cards (SurveyMonkey, Qualtrics, Google Forms, Microsoft Forms, Excel/CSV).
5. Restructure `samples.html` to lead with the featured "Can this survey be trusted?" sample card from deliverable G.
6. Rename "Analytics" to "Survey Checkup" in the workspace left navigation.
7. Rename "Resources" to "Learn" in the footer (small, low-risk).
8. Tighten the methodology, reliability guide, validity guide, AI features, and pricing page intros per the wording table in deliverable H.
9. Add the "Built for people whose numbers get questioned." kicker to the personas section on the homepage.

That list is what we work through next. Each item is bounded and can ship as a single FileZilla batch.
