---
title: "The ReliCheck Bible"
subtitle: "A complete reference for app.html"
date: "Generated 2026-05-19, currently Phase 165 (Mixed-Methods Studio beta path)."
documentclass: article
fontsize: 11pt
geometry: margin=1in
toc: true
toc-depth: 2
numbersections: false
colorlinks: true
linkcolor: black
---

\newpage

# Architecture overview

ReliCheck is a single-page web application that runs on IONOS shared PHP hosting against a MySQL database (schema name `dbs15641829`). The frontend ships as two files: `app.html` (the original surface, still the source of truth for surveys and tests) and `app-2026.html` (the App 2.0 shell that hosts the Mixed-Methods Studio and the in-progress Test and Item Analysis Suite). Authentication, persistence, AI calls (rebranded as ReliCheck Intelligence in user copy as of Phase 156), and any heavy backend work happen through PHP endpoints under `/api`. The same `app.html` serves authenticated logged-in work and unauthenticated public dashboard reads. By design the frontend is monolithic: one file per surface, one global `state` object, dozens of render functions that read from state and rewrite section innerHTML. There is no SPA framework, no bundler, no build step. Inline scripts run in a single `<script>` tag at the bottom of the document.

Note on naming: the file `app-2026.html` is referred to as "App 2.0" in every user-facing label, doc, and conversation. The string "app-2026" appears only in source paths and internal notes.

## Layout shape

- Top bar with the ReliCheck logo, a header strip, and account controls.
- Left sidebar with the workspace nav. Phase 91 restructured the sidebar into three collapsible groups: `projects` (Suites, My Surveys, My Tests, My Panels for 360, My Datasets), `current-survey` (Questions, Settings, Preview, Distribute, Responses, Analyze), and `current-test` (Open test).
- Main pane: a single `<main>` containing `<section class="view">` blocks. Exactly one is active at a time, controlled by `data-view` on body.
- Modal dialogs are appended directly to body when needed and removed on close.

## File layout on the server

- `/app.html`: the original frontend (surveys, tests, panels, suites, analytics dashboards).
- `/app-2026.html`: the App 2.0 shell that hosts the Mixed-Methods Studio (Phases 155 through 165) and the planned Test and Item Analysis Suite (see Roadmap).
- `/api/<area>/<action>.php`: backend endpoints grouped by area. As of Phase 165 there are 28 area directories: `account`, `admin`, `ai`, `auth`, `billing`, `calendar`, `channels`, `contacts`, `datasets`, `email`, `folders`, `google`, `home`, `hris`, `invitations`, `mm` (Mixed-Methods, added Phase 155), `panels`, `promo`, `public`, `public_dashboards`, `reminders`, `responses`, `schedules`, `snapshots`, `suites`, `surveys`, `tests`, `v1`, `webhooks`.
- `/api/_*.php`: shared utility modules (see the Backend API surface section for the full list).
- `/api/cron-*.php`: scheduled-job entry points. As of Phase 165: `cron-fire-pulse.php` (Phase 119), `cron-fire-calendar-followups.php` (Phase 128), plus the email-queue cron under `/api/email/queue_run.php`.
- `/db/schema_phaseN.sql`: idempotent migration files. Phases 155 through 165 each shipped their own schema file (`schema_phase155.sql` through `schema_phase165.sql`) carrying the Mixed-Methods table set.
- `/styles.css`, `/styles-2026.css`: marketing-site CSS. App-only chrome lives inline in `app.html`.

## Important operational rules

- PHP source must be pure ASCII. Em-dashes, non-ASCII quotes, and angle brackets in `//` comments cause IONOS to serve the file as plain text.
- A stray `?>` inside a `//` comment terminates PHP mode and exits the file.
- PHP and MySQL run in different timezones on IONOS. Compute expiries inline in SQL via `DATE_ADD(NOW(), INTERVAL N MINUTE)`.
- `SHOW LIKE` with a prepared-statement placeholder fails on IONOS MySQL. Use sanitized inline values.
- Scheduled jobs run via cron-job.org against `/api/cron-*` URLs. The IONOS panel is not used.

\newpage

# State model

A single global `state` object holds every piece of in-memory data the app needs. Re-renders read from state, mutations write to state, and most user actions end with a call to the relevant render function that re-walks state and rewrites the DOM.

## Top-level keys (initializer)

The `state = { ... }` literal at the top of the inline script (around line 5775) declares these twelve keys:

| Key            | Meaning                                                                       |
|----------------|-------------------------------------------------------------------------------|
| `user`         | The authenticated user, or `null`.                                            |
| `view`         | Name of the currently active section. Defaults to `surveys`.                  |
| `surveys`      | Array of survey metadata for My Surveys.                                      |
| `surveyId`     | ID of the open survey, or `null`.                                             |
| `survey`       | The full open survey: `id`, `title`, `desc`, `likertPoints`, `settings`, `questions[]`. Initialized via `emptySurvey()`. |
| `responses`    | Array of response rows for the open survey or dataset.                        |
| `datasets`     | Array of dataset metadata for My Datasets.                                    |
| `datasetId`    | ID of the open dataset. Mutually exclusive with `surveyId`.                   |
| `tests`        | Array of test metadata for My Tests (Phase 71).                               |
| `openTest`     | Test plus responses loaded for the test analytics view.                       |
| `testBuilder`  | Draft test under construction (Phase 74): `title`, `description`, `passThreshold`, `items[]`. |
| `tier`         | Pricing tier info: `tier`, `tier_label`, `limits`, `features`, `usage`, `catalog`. |

## Top-level keys (lazy-attached)

Several keys are attached on first use rather than in the initializer. Treat them as part of the state model when reading code:

| Key            | First attached                | Meaning                                                                                   |
|----------------|-------------------------------|-------------------------------------------------------------------------------------------|
| `analyticsTab` | analytics render path         | Currently selected analytics tab. See the Analytics tabs section for the 17 valid values. |
| `distribution` | `renderDistribution()`        | Per-Distribute-view state: contacts, invitations, schedules, channels, method selection.  |
| `suitesUi`     | `renderSuites()`              | Per-suite-view state: `mode` (`list`/`detail`), `suiteId`.                                |
| `surveysUI`    | `renderSurveys()`             | Phase 37a filter/sort/folder/selection state for My Surveys. Persisted to `rc.surveysUI`. |
| `surveysAux`   | `renderSurveys()`             | Folders, trends, and health caches that feed the My Surveys row chrome.                   |
| `home`         | `renderHome()`                | Phase 37b home snapshot and pinned-card preferences cache.                                |

## Per-survey persistent settings

Inside `state.survey.settings` the app stores per-survey UI preferences saved server-side as a JSON column on `surveys`. Touched keys, in order of introduction:

| Setting                  | Meaning                                                                                  |
|--------------------------|------------------------------------------------------------------------------------------|
| `mode`                   | Survey mode (`standard`, `cover`, etc.).                                                 |
| `construct`              | Default construct for new items.                                                         |
| `kAnonymityMin`          | Subgroup-hide threshold; counts below `k` are masked or hidden.                          |
| `groupByQid`             | Default group-by question for Compare and per-group reliability.                         |
| `purpose`                | Phase 57 Survey Purpose text. Drives the AI Purpose Check.                               |
| `arms`, `armingEnabled`  | Experimental-arm configuration (A/B split definitions).                                  |
| `factorAnalysis`         | Phase 46 EFA settings: `{ method, rotation, names }`. (No flat `faMethod` or `faRotation` keys; the bible used to misname these.) |
| `subgroups`              | Phase 65: `groupId`, `outcome` for the Subgroups tab.                                    |
| `invariance`             | Phase 66: `groupId` for the measurement-invariance card.                                 |
| `predictors`             | Phases 68, 69, 84: `mode`, `outcome`, `predictors[]`, `mediation`, `moderation`.         |
| `keyDriversDigestN`      | Phase 89: response-count threshold for the digest email.                                 |
| `keyDriversDigestSentN`  | Phase 89: last threshold the digest fired at.                                            |
| `coverPage`              | Phase 139: optional pre-survey cover page. `body`, `consent_mode` (`required`/`optional`/`none`), `consent_label`. Editor lives on the Distribute view, moved there from Settings since cover page is a deployment-time decision. |

Public-dashboard share links (Phase 42) are not stored in `settings`; they live in the `public_dashboard_links` table, accessed via `/api/public_dashboards/*`.

## Storage layers

- **In-memory state**: lost on reload.
- **localStorage**: cached AI narrator responses (`relicheck.narrator.<tab>.v1.*`), analytics-tab selection (`relicheck.analyticsTab`), per-tab sub-tab persistence (`relicheck.<key>.subTab.v1`), draft surveys (Phase 41 save-and-resume), MLM and IRT model preferences, Key Drivers picker state (`relicheck.keydrivers.v1.<surveyId>`), Settings subsection (Phase 90), per-user sidebar group collapsed state (Phase 91), Distribution method selection (Phase 122, `relicheck.distribution.method`), and My Surveys filter state (Phase 37a, `rc.surveysUI`).
- **Per-survey settings**: written to `surveys.settings` on every relevant save; loaded on `getSurvey`.
- **Server-side tables**: `surveys`, `responses`, `datasets`, `tests`, `test_responses`, `contacts`, `invitations`, `public_dashboard_links`, `webhooks`, notification configs, audit and email logs, `suite_surveys` and `suite_tests` joins, `survey_360_panels` and friends, `calendar_followups`, `survey_channels`, `survey_schedules`.

\newpage

# View system

Sections live in `app.html` and `switchView(name)` updates `state.view`, toggles the active class, and dispatches into the right render function. Side rail and header pills refresh on every switch. Phase 91 restructured the sidebar from a flat list into grouped dropdowns. Phase 144b added a Readiness pill to the survey `ctx-bar` (visible from every survey-context view). App 2.0 (`app-2026.html`) carries its own view system layered on top of the same chrome conventions; the Mixed-Methods Studio tab strip is documented in its own section.

As of Phase 165 there are fourteen top-level views in `app.html`:

- `view-home`. Phase 37b. Four-card customizable grid (Quick Actions, Pinned, Latest result, 7-day Activity).
- `view-surveys`. Phase 37a. Folders, favorites, archive, bulk actions, sparkline, sticky filter bar. Hashed-color avatars on each row (Phase 101a).
- `view-datasets`. Upload and list of CSV / Excel imports.
- `view-tests`. Phases 71 through 78, 97 through 100, 134a, 134d. My Tests with Test Builder (Phase 74), hosted slug-based take URL (Phase 78), and an 8-tab analytics dashboard (Phase 100).
- `view-panels`. Phase 129. 360 / multi-rater panels with subject reports (Phase 131a) and panel-launch automation.
- `view-suites`. Phases 133 through 135. Suites hub plus suite detail. Eight system suites including Phase 134a Test and Item Analysis. Cross-survey roll-up dashboard (Phase 135) on every suite detail view.
- `view-import-survey`. Phase 50. Upload an existing survey via paste or `.qsf` file.
- `view-details`. Phase 90. Settings page split into a left rail of subsections and a right panel.
- `view-builder`. Questions builder. Survey Purpose card, Construct Mapping card, Suggest Constructs (Phase 56), Generate Items / Recommend a Scale (Phase 79), skip logic, experimental arms.
- `view-take`. Take Survey preview.
- `view-distribution`. Phases 38 and 122. Distribution method hub. Phase 144b inserted a Pre-publish readiness check card at the very top, above the page header. Phase 139 moved the cover page editor here from Settings. As of Phase 145 there are nine active method tiles: Web Link, Email, QR, Embed, Slack and Teams, Pulse Schedule, Calendar Event, API and Webhooks, and Social Media (Phase 123). Two tiles are marked coming-soon: SMS / Text Message and Offline Mode.
- `view-responses`. Raw response list.
- `view-analytics`. Survey Results: the analytics dashboard with the sub-tab structure documented in the Analytics tabs section.
- `view-webhooks`. Per-user outbound webhooks (Phase 30).

\newpage

# Survey infrastructure

A survey is an ordered list of questions plus settings. Question types: `likert`, `single`, `multi`, `open`. Builder cards on `view-builder` include the Survey Purpose card (Phase 57), the Construct Mapping card (Phase 56), the Survey Questions card with skip logic and inline AI checks, and a right rail with the Add Question type picker plus the Generate Items and Recommend a Scale buttons (Phase 79).

The take view (`renderTake`) renders the same components a public respondent would see. Skip logic evaluates on every input via `applySkipLogic()`. Phase 139 added an optional cover page that appears before the questions on every channel (web link, email, QR, Slack, embed, AI chat take).

# Tests subsystem

Tests are a top-level entity for classroom test data. Each test has a fixed answer key and one row per student. The subsystem was introduced in Phase 71 and completed across Phases 73, 74, 78, and 97 through 100. The 8-tab analytics dashboard (Phase 100) covers Overview, Reliability, Difficulty, Quality, Answer Choices, Skill Performance, Pre-Post, and Item Health. Phase 134a added a Test and Item Analysis suite that joins tests through `suite_tests`. Phase 134d added `from_template.php` so users can spin up a new test from one of eight starter templates.

# Mixed-Methods Studio (Phases 155 through 165)

A new sub-product inside App 2.0 (`app-2026.html`). The Studio walks the user through a 20-step mixed-methods workflow, from importing open-text responses through coding, dataset construction, integration, strength checks, and the final report. Every step is its own panel and writes back to its own table; the workflow tolerates resuming at any step.

## Tab strip (under Studio)

`Categories`, `Dataset`, `Analysis`, `Joint Display`, `Integration`, `Strength Check`, `Report`. The earlier `Evidence Matrix` tab is hidden as of Phase 162 (rolled into Joint Display; the table proved redundant). Tabs render only if the underlying companion table exists (see "Endpoint guards" below).

## Database tables (Phases 155 through 165)

`mm_projects`, `mm_text_responses`, `mm_theme_categories`, `mm_coded_responses`, `mm_sentiment_scores`, `mm_generated_variables`, `mm_structured_datasets`, `mm_dataset_cells`, `mm_clusters`, `mm_cluster_members`, `mm_analysis_results`, `mm_joint_display_rows`, `mm_integration_rows`, `mm_strength_checks`, `mm_report_sections`, `mm_report_section_versions`, `mm_report_section_notes`, `mm_project_templates`.

Each table ships in the `db/schema_phase15N.sql` file matching the phase it was introduced. Versioning is additive; existing tables are never dropped, only extended with idempotent `ALTER TABLE` blocks.

## Endpoints under `/api/mm/`

About 25 files as of Phase 165: `project.php`, `projects.php`, `responses.php`, `build.php`, `categories.php`, `code-existing.php`, `themes-per-question.php`, `dataset.php`, `dataset-cell.php`, `variable-roles.php`, `clusters.php`, `analysis-suggest.php`, `analysis-run.php`, `joint-display.php`, `integration.php`, `strength-check.php`, `report.php`, `report-export.php`, `report-docx.php`, `report-rewrite.php`, `templates.php`. Every endpoint follows the standard shape (`require_method`, `check_origin`, `require_auth`, validate, `json_out`).

## Theme matching

The default `Apply themes to responses` action uses a local deterministic theme matcher (no AI call). It tokenizes the response, scores against per-theme keyword sets, and returns matches above a confidence floor. AI is not the default for this step because the user explicitly wants determinism and zero-cost replay. AI is available as an optional fallback for unmatched rows.

## AI rewrites (ReliCheck Intelligence)

Inside any report section, selecting text raises a floating bubble offering Intelligence rewrites (tighten, expand, plain language, etc.). Selections post to `report-rewrite.php`, which returns the rewritten span and writes a version snapshot. The internal source enum on `mm_report_sections` still uses the literal value `'ai'`; only the user-facing label was renamed.

## Version history

`mm_report_section_versions` keeps the last 10 versions per section. Older versions are pruned on write. Restore is a single click; the current row becomes a new version on restore so nothing is lost.

## Section notes and to-dos

`mm_report_section_notes` carries free-form notes plus optional to-do checkboxes per section. Notes never appear in the exported report; they exist only for the author.

## Project templates

`mm_project_templates` lets a user save a project's theme set + variable roles + report scaffold and re-apply it to a new project. Useful for repeating studies (annual surveys, multi-site evaluations).

## Exports

- **DOCX**: server-side via `api/mm/report-docx.php`. The endpoint builds the `.docx` package with PHP's built-in `ZipArchive` (no external library). Word XML parts are templated inline. No PhpWord, no PHPDocX dependency.
- **PDF**: client-side via `html2pdf.js` loaded from CDN on demand. The script tag is appended to head only when the user clicks Export PDF, so the page weight stays flat for the 99% of sessions that do not export.

## Endpoint guards (Phase 162 rule)

Endpoints that read companion tables from other phases (e.g., `joint-display.php` reading `mm_analysis_results`) MUST probe with `SHOW TABLES LIKE` and wrap every optional read in try/catch. Missing tables return `null` for that field, never HTTP 500 with HTML. The same rule applies to any cross-phase read in `app-2026.html`: server splits actionable lists into `runnable` and `skipped` so the client never shows a Run button for an operation that will fail.

# Analytics tabs

The Survey Results view holds a top tab bar with eight surfaced analyses plus a "More analyses" dropdown that lists nine deep-dive analyses. Phase 144 reclassified Trends from the surfaced row into the dropdown (the surfaced row is reserved for survey-quality reads; the dropdown holds data-outcome reads). Phase 144 added Survey Readiness as a new surfaced tab. Phase 145 added Equity Gaps as a new dropdown item.

**Surfaced row (8):** Strength Index, Survey Readiness, Description, Reliability, Validity, Open-Ended, Response Quality, Completion and Missing Data.

**More analyses dropdown (9):** Compare, Pre/Post, Subgroups, Equity Gaps, Predictors, Key Drivers, IRT, MLM, Trends.

The top tab bar hides on every non-overview page (Phase 108) because the Phase 110 `ctx-bar` above the page title carries the "Back to analyses" link plus the single Export PDF button. Phase 109 added a per-tab sub-tab bar inside every tab. Sub-tab structures vary; Summary / Detail / More analyses is the default for Quality, Completion, Trends, Readiness, and most deep-dive tabs. Reliability is Summary / Inter-item / Items / Per-group / More (Phase 106). Description is Summary / Per-item / Distributions / More. Validity is Summary / EFA / CFA / Invariance / More. Open-Ended is Summary / Themes / Responses / More.

The Phase 67 consistency rule still holds on the Summary sub-tab of every dashboard: card-wrapped header, AI narrator in the main column (in a two-column hero next to a Strength Index ring teaser on the surfaced tabs, Phase 105), "Ask your data" chat in the right rail.

## Tab descriptions

- **Strength Index** (`overview`). Phase 47, recalibrated in Phase 104. Composite 0 to 100 SSI score with six weighted domains. Reliability domain anchors on McDonald's omega (Phase 104) with an alpha-omega gap penalty.
- **Survey Readiness** (`readiness`). Phase 144. Always available (runs on the survey design itself, not on response data). 0 to 100 Readiness Score across six weighted domains: Items per construct (25), Construct coverage (20), Grouping variables (15), Survey purpose (15), Length sanity (15), Reverse-scoring balance (10). Verdict tiers `Ready` / `Almost ready` / `Needs work` / `Not ready`. Issue list with severity tiers (`blocker` / `warning` / `nudge`). Phase 144b added a hybrid placement: full chrome on Analytics, compact card on Distribute, and a Readiness score pill in the survey `ctx-bar` visible from every survey-context view.
- **Reliability** (`reliability`). Cronbach's alpha, McDonald's omega (Phase 60), split-half, SEM, item-total correlations, alpha-if-deleted, per-item descriptives. Phase 107 added Feldt-Woodruff CI on alpha and bootstrap CI on omega. Phase 111 added per-construct reliability. Phase 118 added test-retest reliability via ICC(3,1).
- **Description** (`description`). Per-item descriptives (mean, SD, distribution shape) and per-group rollups when a grouping variable is available. Two charts: response-over-time and distribution histogram.
- **Validity** (`validity`). EFA dashboard (Phase 46), CFA card (Phase 64), Measurement invariance card (Phase 66) with configural / metric / scalar verdict.
- **Open-Ended** (`openended`). Phases 43 and 54. Aggregate open-text responses across every open question. AI work gated behind a Generate button.
- **Response Quality** (`quality`). Phase 140. Surfaced tab. Straight-lining detection, duplicate vectors, low-effort open-ended answers, missingness by question, channel-level quality skew, AI narrator, Clean Dataset CSV export. Speeding detection deferred to Phase 140b.
- **Completion and Missing Data** (`completion`). Phase 141. Surfaced tab. Completion Score 0 to 100 ring, per-question and per-respondent missingness, completion funnel, modal drop-off point, by-group missingness, missingness heatmap, AI narrator.
- **Compare** (`compare`). Phase 61. Welch's t-test, Mann-Whitney U, one-way ANOVA, Kruskal-Wallis, Cohen's d with 95% CI, eta-squared, AI narrator.
- **Pre/Post** (`prepost`). Phase 62. Paired t-test or Wilcoxon signed-rank, Cohen's d_z, Jacobson-Truax Reliable Change Index at 90 / 95 / 99 percent confidence.
- **Subgroups** (`subgroups`). Phase 65. Per-subgroup mean / SD / 95% CI / Cohen's d vs everyone else. Hides rows when n is below the k-anonymity threshold.
- **Equity Gaps** (`equity`). Phase 145. Dropdown item between Subgroups and Predictors. For every detected grouping variable computes per-group means with 95% CI, an omnibus ANOVA, and pairwise Cohen's d. Per-axis verdict tier driven by max `|d|`: parity (<0.20) / small (<0.50) / meaningful (<0.80) / large (>=0.80). Composite Equity Gap Score 0 to 100 (100 = parity across every axis), score-to-status mapping parity (85+) / small (70 to 84) / meaningful (50 to 69) / large (<50). k-anonymity floor of 5 (or user setting if higher) hides small subgroups entirely.
- **Predictors** (`predictors`). Phases 68, 69, 84. Three modes via a pill toggle: Simple (OLS or logistic regression), Mediation (X to M to Y; bootstrap percentile or BCa CI on the indirect effect), Moderation (X x W interaction with Johnson-Neyman plot).
- **Key Drivers** (`keydrivers`). Phase 86. Pearson r per driver, standardized multiple regression betas with 95% CI, horizontal-bar importance chart, Action Priority Map (Phase 143) 2x2 SVG scatter (Fix First / Protect / Do Not Overinvest / Monitor) with Fix First callout.
- **IRT** (`irt`). Phases 70, 84, 85. Four models from a dropdown: Graded Response (default), 2PL, 3PL, MIRT (two-dimensional Graded Response with closed-form Varimax rotation).
- **MLM** (`mlm`). Phases 81, 83. Three modes: two-level linear (REML / EM), two-level logistic GLMM (PQL), three-level linear. Wald-z, Satterthwaite, or Kenward-Roger small-sample df corrections.
- **Trends** (`trends`). Phase 142, reclassified to dropdown in Phase 144. Wave detection from `Q_CHANNEL` channel tags or quarterly bins. Trend Score 0 to 100, per-wave composite-mean trend line, per-construct sparklines, Welch's t-test on current vs prior wave.

\newpage

# Stats library reference

The `Stats` object (around line 9853 in `app.html`) centralizes every numerical method the analytics dashboards use. Methods are pure: array inputs, primitive or array outputs, no DOM or state side effects. As of Phase 165 there are 65 named methods across nine categories (Phases 146 through 165 added no new `Stats` primitives; the Mixed-Methods Studio's numerical work lives in PHP under `/api/mm/`).

## Descriptive

`mean`, `variance`, `sd`, `min`, `max`, `skewness`, `kurtosis`, `histogram`, `pearson`.

## Reliability

`cronbachAlpha`, `reliabilitySummary`, `splitHalf`, `omegaTotal` (Phase 60), `alphaCI` (Phase 107, Feldt-Woodruff), `omegaCI` (Phase 107, bootstrap), `scaleScoreSD` (Phase 107), `intraclassCorrelation` (Phase 118), `_fInvFromRightTail`.

## Factor analysis (EFA)

`correlationMatrix`, `sampleCovarianceMatrix` (Phase 66), `sampleItemMeans` (Phase 66), `inverse`, `kmo`, `bartlettSphericity`, `jacobiEigen`, `factorAnalysis` (PCA), `factorAnalysisPAF`, `varimax`, `_varimax2D` (Phase 85).

## Group comparison

`cohensD`, `welchTTest`, `mannWhitneyU`, `oneWayANOVA`, `kruskalWallisH`, `pairedTTest`, `wilcoxonSignedRank`, `reliableChangeIndex`.

## Probability and quantiles

`incBeta`, `tCdfTwoSided`, `fCdfRight`, `normalPTwoSided`, `_normCdf`, `_normInvCdf` (Phase 84), `_tCdf` (Phase 83), `_lgamma` (Phase 83), `_lnGamma`, `_betaCF`, `_chiSqUpperP`, `_ncChiSqUpperP`, `_ncpForTail`, `_tCritTwoSided`.

## Modern psychometrics

`confirmatoryFactorAnalysis` (Phase 64), `measurementInvariance` (Phase 66), `gradedResponseModel` (Phase 70), `dichotomousIRT` (Phase 84, 2PL/3PL), `mirtGRM` (Phase 85).

## Regression

`olsRegression`, `logisticRegression`, `mediation` (Phases 69 and 84), `moderation` (Phases 69 and 84), `relativeWeights` (Phase 86, Johnson Relative Weights).

## Mixed-effects models

`mixedModel` (Phase 81), `glmmLogistic` (Phase 83), `mixedModel3level` (Phase 83), `dfCorrect2Level` (Phase 83).

## Composite scoring

`surveyStrength` (Phase 47, recalibrated Phase 104).

## Phase 145 note

Phase 145 (Equity Gaps) added no new `Stats` primitives. It composes existing `cohensD`, `oneWayANOVA`, and the per-respondent composite Likert mean computed inline. The new logic lives in `buildEquityGapsSnapshot()` (snapshot plus scoring) and `renderEquityGapsTab()` (presentation).

\newpage

# AI narrator pattern (ReliCheck Intelligence)

Every analytics card with an Intelligence summary follows the same Phase 54-plus pattern. The client builds a structured snapshot of the current analysis, posts it to a dedicated PHP narrator endpoint, and renders the returned tone-keyed card. Responses are cached in localStorage so revisiting a tab does not re-fire a model call.

## Naming (Phase 156 rebrand)

As of Phase 156 every user-visible "AI" string in `app-2026.html` was renamed:

- `AI Summary . [topic]` eyebrows on narrator cards across analytics tabs are now just the topic word (`Reliability`, `Difficulty`, `Quality`, etc.) with the credit `by ReliCheck Intelligence` underneath.
- `Generate with AI` tile in the survey creation modal is now `Generate with Intelligence`.
- `AI draft` pill in the Integration tab is now `Intelligence draft`.
- `AI` section pill on the Report tab is now `INTELLIGENCE`.
- Helper spinners (`Asking the AI to...`) are now `Asking ReliCheck Intelligence to...`.

The internal source enum value `'ai'` in the database (in `mm_report_sections`, narrator-cache rows, etc.) is unchanged. Only user-facing labels were renamed. Endpoint filenames (`narrate-*.php`, `_ai.php`, `ai_complete`) are also unchanged; renaming them would be a multi-week thrash with no user benefit.

## Endpoint contract

Every `/api/ai/narrate-X.php` endpoint accepts a POST with a snapshot object, validates and normalizes the fields, builds a snapshot text block, calls `ai_complete` with a system prompt that defines tone tiers, and returns JSON with these fields:

```json
{
  "ok": true,
  "tone": "good" | "ok" | "warn" | "bad",
  "tone_label": "<short pill>",
  "headline": "<one sentence>",
  "paragraph": "<two to four sentences>",
  "highlights": [ { "label": "...", "detail": "..." } ],
  "affected_items": [ { "type": "item|driver|...", "id": "..." } ],
  "model": "<provider model id>"
}
```

## Narrator endpoint catalog

As of Phase 165 there are 23 narrator endpoints in `/api/ai/` (the Mixed-Methods Studio uses Intelligence calls through `/api/mm/report-rewrite.php` and a few helper endpoints rather than the narrator pattern):

| Endpoint                    | Used by                                                                  |
|-----------------------------|--------------------------------------------------------------------------|
| `narrate-reliability.php`   | Reliability tab (Phase 55).                                              |
| `narrate-description.php`   | Description tab.                                                         |
| `narrate-validity.php`      | Validity tab (EFA-centric).                                              |
| `narrate-cfa.php`           | CFA card (Phase 64).                                                     |
| `narrate-invariance.php`    | Invariance card (Phase 66).                                              |
| `narrate-irt.php`           | IRT tab; Graded, 2PL, 3PL, MIRT (Phases 70, 84, 85).                     |
| `narrate-openended.php`     | Open-Ended tab.                                                          |
| `narrate-comparison.php`    | Compare tab.                                                             |
| `narrate-prepost.php`       | Pre/Post tab.                                                            |
| `narrate-subgroups.php`     | Subgroups tab (Phase 65).                                                |
| `narrate-predictors.php`    | Predictors tab in Simple mode.                                           |
| `narrate-mediation.php`     | Predictors tab in Mediation mode (Phases 69 and 84).                     |
| `narrate-moderation.php`    | Predictors tab in Moderation mode (Phases 69 and 84).                    |
| `narrate-mlm.php`           | MLM tab (Phases 81 and 83).                                              |
| `narrate-key-drivers.php`   | Key Drivers tab (Phase 86).                                              |
| `narrate-test.php`          | Test analytics view.                                                     |
| `narrate-360-subject.php`   | 360 subject report (Phase 131a).                                         |
| `narrate-suite-rollup.php`  | Suite roll-up dashboard (Phase 135).                                     |
| `narrate-quality.php`       | Response Quality tab (Phase 140).                                        |
| `narrate-completion.php`    | Completion and Missing Data tab (Phase 141).                             |
| `narrate-trends.php`        | Trends tab (Phase 142; reclassified to dropdown in Phase 144).           |
| `narrate-readiness.php`     | Survey Readiness tab (Phase 144).                                        |
| `narrate-equity-gaps.php`   | Equity Gaps tab (Phase 145). HR-framed Cohen's d translation, mentions hidden subgroups when k-anonymity dropouts occur. |

## Other AI endpoints (non-narrator)

The same `/api/ai/` directory holds these non-narrator endpoints (27 total as of Phase 165):

`analyze-upload.php`, `can-i-use.php` (Phase 51), `chat-data.php`, `check-purpose.php` (Phase 57), `check-question.php`, `draft-report.php` (Phase 79), `explain-reliability.php` (Phase 53; superseded by `narrate-reliability.php` but kept on disk), `extract-survey.php` (Phase 50), `extract-themes.php`, `extract-themes-from-texts.php`, `generate-items.php` (Phase 79), `generate-survey.php`, `improve-question.php`, `map-constructs.php` (Phase 56), `methodology.php`, `ping.php` (health check), `purpose-check.php`, `purpose-mini.php`, `purpose-mini-heredoc.php`, `recommend-scale.php` (Phase 79), `response-quality.php` (Phase 52), `review-survey.php`, `score-sentiment.php`, `suggest-factor-names.php`, `suggest-next-steps.php`, `summarize-report.php`, `translate-survey.php`.

\newpage

# Backend API surface

Endpoints follow a consistent shape: `require_method`, `check_origin`, `require_auth`, optional `check_rate_limit`, then read the JSON body or query string, validate, and return `json_out`. Errors go through `fail(code, message, http)`.

## Shared helper modules

Under `/api/`:

`_admin.php`, `_admin_audit.php`, `_admin_session.php`, `_ai.php`, `_api_auth.php`, `_channels.php`, `_config.example.php`, `_dataset_to_survey.php`, `_db.php`, `_email_compose.php`, `_email_dispatcher.php`, `_email_renderer.php`, `_email_resolver.php`, `_google.php`, `_helpers.php`, `_hris.php`, `_hris_bamboohr.php`, `_hris_rippling.php`, `_hris_workday.php`, `_invitations.php`, `_keydrivers_snapshot.php`, `_mailer.php`, `_panels.php`, `_ping.php`, `_ratelimit.php`, `_session.php`, `_smoke.php`, `_stripe.php`, `_suites.php`, `_team.php`, `_tiers.php`, `_totp.php`, `_webhooks.php`.

## Auth, account, surveys, responses, datasets

Auth (`/api/auth/`): login, signup, logout, session, Google OAuth handshake. Account (`/api/account/`): profile, change_password, prefs, tokens, team, tier, invitations, domain. Surveys (`/api/surveys/`): standard CRUD plus `duplicate`, `archive`, `bulk`, `favorite`, `move_folder`, `health`, `diagnose`, `trends`, `create_from_dataset`, `import_from_dataset`, `from_validated`, `templates` (around 50 starter templates after Phase 134c), `validated_scales` (Phase 39). Responses (`/api/responses/`): list, get, delete. Datasets (`/api/datasets/`): upload, list, get, delete.

## Tests (Phases 71, 73, 74, 78, 100, 134a, 134d)

`list`, `create`, `get`, `update`, `delete`, `add-responses` (Phase 74), `publish` (Phase 78), `templates` (Phase 134a), `from_template` (Phase 134d), plus the public-facing `test.php` and `test_submit.php` (Phase 78).

## Distribution (Phase 38), Schedules (Phase 119), Channels (Phase 121), Calendar (Phases 126, 128)

Per-survey contact lists (`/api/contacts/`), tokenized invitations (`/api/invitations/`), recurring pulse schedules with hourly cron (`/api/schedules/` plus `cron-fire-pulse.php`), Slack and Teams channel webhooks (`/api/channels/`), calendar event `.ics` generation with optional post-meeting auto-fire follow-ups (`/api/calendar/` plus `cron-fire-calendar-followups.php`).

## Panels (Phase 129), Suites (Phases 133, 134a, 135)

Panels: `create`, `get`, `list`, `delete`, `add-subjects`, `add-evaluators`, `launch`, `subject-report`. Suites: eight system suites (seven survey-typed plus Phase 134a Test and Item Analysis) with lazy seeding. `create`, `get`, `list`, `delete`, `add-survey`, `add-test`, `remove-survey`, `remove-test`, `rollup` (Phase 135 cross-suite quarter-vs-quarter aggregator).

## Public, public_dashboards, webhooks, snapshots, folders

Public (`/api/public/`): unauthenticated survey takes (`survey.php`, `submit.php`, `parse-answer.php`), the take-page assets, the `/api/public/stats.php` endpoint used by the marketing site, and Phase 78 hosted tests. Public dashboards (`/api/public_dashboards/`): create, list, delete (Phase 42). Webhooks (`/api/webhooks/`): per-user outbound webhooks (Phase 30). Snapshots (`/api/snapshots/`): saved analytics snapshots. Folders (`/api/folders/`): My Surveys folder management (Phase 37a).

## Admin, billing, email, promo, HRIS, reminders, v1

Admin (`/api/admin/`): audit, auth (2FA), cron, customers, email, memberships, promos, staff. Powers the full admin console (`/admin.html`). Billing (`/api/billing/`): Stripe checkout, portal, return, and webhook. Email (`/api/email/`): diagnostic, preferences, queue_run (the cron-driven dispatcher), resend, suppression, unsubscribe (Phase 41). Promo (`/api/promo/`): create, list, redeem, toggle. HRIS (`/api/hris/`): BambooHR, Rippling, Workday connectors. Reminders (`/api/reminders/`): per-user reminder config. Public API (`/api/v1/`): `me`, `surveys`, `responses`.

## AI

`/api/ai/narrate-*.php` plus the non-narrator AI endpoints listed in the AI section. Phase 145 added `narrate-equity-gaps.php`. Phase 156 rebranded user-visible AI labels to ReliCheck Intelligence; the `/api/ai/` directory itself was not renamed.

## Mixed-Methods (Phases 155 through 165)

`/api/mm/` holds about 25 endpoints powering the Mixed-Methods Studio in App 2.0. See the Mixed-Methods Studio section for the full file list and the per-table mapping.

\newpage

# CSS and chrome conventions

App chrome lives inline in `app.html`. The analytics dashboards must follow the consistency rule (saved as `feedback_analytics_dashboard_consistency.md`).

| Class                                                  | Purpose                                                          |
|--------------------------------------------------------|------------------------------------------------------------------|
| `.card`                                                | White rounded-corner panel with subtle border.                   |
| `.card-head h2`                                        | Section title inside a card. Fraunces serif at 22 to 24px.       |
| `.fa-section`                                          | Sub-section block inside a card.                                 |
| `.fa-kpi-tile`                                         | KPI tile with label, big number, sub-line.                       |
| `.data-table`                                          | Reference table style. Tabular-numerals on numeric cells.        |
| `.scroll-x`                                            | Horizontal scroll container for wide tables.                     |
| `.alert`                                               | Toast-style notice (`.warn` / `.info` / default).                |
| `.atab` / `.more-tab-item`                             | Analytics tab button. `data-atab` maps to dispatcher branch.     |
| `.qb-purpose-card` / `.qb-constructs-card`             | Builder section cards with left-accent border.                   |
| `.ds-pagehead` / `.ds-kpis` / `.ds-list`               | Datasets, Tests, similar list views.                             |
| `.side-group` / `.side-group-head` / `.side-group-body`| Phase 91 grouped sidebar nav.                                    |
| `.settings-split` / `.settings-nav` / `.settings-panels` / `.settings-panel` | Phase 90 Settings page chrome.             |
| `.narrator-target-flash`                               | Phase 93. Applied for 1.6s to elements the narrator pointed at.  |
| `.analytics-tabs-bar`                                  | Phase 105. Top analytics tab bar.                                |
| `.more-tab-wrap` / `.more-tab-trigger` / `.more-tab-menu` / `.more-tab-item` | Phase 105 More analyses dropdown.        |
| `.surfaced-hero`                                       | Phase 105. 1.6fr / 1fr two-column grid for AI narrator and SSI ring. |
| `.ssi-teaser-card` / `.ssi-teaser-ring`                | Phase 105. Compact 96px ring and status pill.                    |
| `.next-step-card`                                      | Phase 105. Recommended Next Step card.                           |
| `.sub-tab-bar` / `.sub-tab`                            | Phases 106 and 109. Underline-tab strip inside every analytics tab. |
| `.dist-method-hub` / `.dist-method-tile`               | Phase 122. Distribution method-grid hub.                         |
| `#distReadinessCard`                                   | Phase 144b. Pre-publish readiness card at the top of the Distribute view. |
| `.meta-pill-sm[data-act="readiness-jump"]`             | Phase 144b. Readiness pill in the survey `ctx-bar`.              |

## Analytics consistency rule (Phase 67)

- Every analytics tab leads with a card-wrapped header built via `_renderAnalyticsHeader(root, title, blurb)`.
- The AI narrator anchors in the main content column via `insertTabNarratorCard` with a `before:` reference. Never prepended to root.
- The "Ask your data" chat card is the only rail card.
- KPI tile rows, data tables, fit-indicator colors all reuse the existing classes.
- A new tab passes the consistency check if it looks visually identical to the Subgroups tab in the browser.

\newpage

# Phase log (selected highlights)

Surface-level index. Per-phase memory is in `spaces/.../memory/project_relicheck_phase*.md`. Phases listed in build order.

## Phases 1 through 87 (compressed)

Foundations through Key Driver Analysis. See the prior bible (Phase 87 covered the full historical detail).

## Phases 88 through 135 (compressed)

Bible refreshes (88, 95), narrator extensions (92, 93, 104, 105), Test analytics rebuild (96 through 100), Strength Index recalibration (104), analytics dashboard redesign (105 through 110), per-construct reliability and test-retest (111, 118), pulse schedules (119), AI conversational take mode (120), Slack and Teams (121), Distribution hub (122), Social media share (123), Calendar events (126, 128), 360 panels (129 through 131), HR template library (130), Suites (133 through 135).

Specific phases worth holding in mind:

- Phase 91. Sidebar regrouped into `projects` / `current-survey` / `current-test`.
- Phase 100. Test analytics rebuilt into the eight-tab dashboard.
- Phase 105. Surfaced-hero two-column layout with SSI ring teaser.
- Phase 106. Reliability sub-tabs (Summary / Inter-item / Items / Per-group / More).
- Phase 109. Per-tab sub-tab system introduced across analytics.
- Phase 110. `ctx-bar` introduced above analytics page titles.
- Phase 134a. Test and Item Analysis suite plus `templates.php` for tests.
- Phase 134d. Tests-from-template (`from_template.php`).

## Phase 136. Marketing catch-up for Phase 135.

`/suites.html` gained a six-card Suite roll-up section; `/overview.html` gained a callout box; `/ai-features.html` bumped to twenty-four tools and added a Suite Roll-Up Narrator card; `/index.html` count bump plus one-sentence mention.

## Phase 137. Demo suite (rolled back to a static sample page).

Built an opt-in Home card plus seed and remove endpoints plus `is_demo` schema. Donald flagged the cost-vs-value mismatch (every user already has suites plus 37 templates). Rolled back to a static `/samples/suite-rollup.html` page plus links from `/samples.html` and `/suites.html`. Three files, no schema, no `app.html` change.

## Phase 138. Reports sidebar button removed.

The Reports button under Current survey group was a silent duplicate of Analyze. Built a rewire, Donald asked for it stripped instead. Button plus dead code removed. One file (`app.html`).

## Phase 139. Optional cover page (informed consent / intro).

New per-survey optional cover page rendered before respondents see questions. The editor lives on the Distribute view (originally built in Settings; Donald flagged Settings as the wrong home since cover page is a deployment-time decision). Freeform body, three consent modes (required / optional / none), customizable checkbox label, and an IRB starter button. Gates both form and AI chat take mode. Three files (`app.html`, `take.html`, `api/public/survey.php`). No schema.

## Phase 140. Response Quality dashboard.

Surfaced analytics tab `Response Quality`. Straight-lining detection, duplicate vectors, short open-ended answers, missingness by question, channel comparison, AI narrator, Clean Dataset CSV export. Speeding plus completion-time histogram deferred to Phase 140b along with a `started_at` column. Two files (`app.html`, `api/ai/narrate-quality.php`). No schema.

## Phase 141. Completion and Missing Data dashboard.

New surfaced analytics tab. Completion Score 0 to 100 ring, per-question and per-respondent missingness, completion funnel, modal drop-off point, by-group missingness, missingness heatmap, AI narrator. Phase 140 Quality tab's missingness was trimmed to a teaser linking here. Two files (`app.html`, `api/ai/narrate-completion.php`). No schema.

## Phase 142. Trends dashboard.

Originally launched as the eighth surfaced analytics tab. Trend Score ring, per-wave composite trend line, per-construct sparklines, Welch's t-test on current-vs-prev wave, AI narrator. Auto-detects waves from `Q_CHANNEL` with quarterly-bin fallback. Ships with `db/test_dataset_phase142.sql` (four waves of engagement pulse data). Two files plus one SQL. Reclassified to More analyses dropdown in Phase 144 because Trends is a data-outcome read, not a survey-quality read.

## Phase 143. Importance-Performance Matrix (Action Priority Map).

New IPM card on the Key Drivers tab. Classical Martilla-James 2x2 with Fix First / Protect / Do Not Overinvest / Monitor quadrants, color-coded SVG scatter, per-quadrant counts, Fix First callout. One file (`app.html`).

## Phase 144. Survey Readiness plus Trends reclassification.

Two-part build. Part (a): Trends moved out of the surfaced top-row into the More analyses dropdown per Donald's tab classification rule (survey-quality reads go in the surfaced row; data-outcome reads go in the dropdown). Part (b): new surfaced `Survey readiness` tab grades the survey design itself; runs even with zero responses. 0 to 100 Readiness Score across six weighted domains: Items per construct (25), Construct coverage (20), Grouping variables (15), Survey purpose (15), Length sanity (15), Reverse-scoring balance (10). Issue list with severity tiers (`blocker` / `warning` / `nudge`). When the user lands on Overview with zero responses, auto-route to Readiness. Two files (`app.html`, `api/ai/narrate-readiness.php`). No schema.

## Phase 144b. Hybrid placement: Distribute card plus ctx-bar pill.

Donald flagged that burying Readiness inside Analytics was a discoverability problem. Hybrid placement keeps the Analytics surfaced tab unchanged and adds:

1. A compact `Pre-publish readiness check` card at the top of the Distribute view (above `dist-pagehead`). Score ring plus verdict pill plus top three issues plus a `View full readiness check` button. `data-dist-hub-only="1"` so it hides when drilling into a method.
2. A Readiness pill in the survey `ctx-bar` (visible from Builder, Settings, Take, Responses, Analytics, Distribute). Color-tied to verdict tier. Click jumps to the Distribute card.
3. Side-effect fix: `distributionCtxBar` was previously not rendered by `renderCtxBars` (latent since Phase 122). Now wired in.

The same `buildReadinessSnapshot()` powers all three surfaces so the math never drifts. One file (`app.html`).

## Phase 144 follow-up. SSI teaser bounce-loop fix.

The SSI teaser link `View full Strength Index` silently bounced back to Readiness when clicked with zero responses (the Phase 144 auto-route caught the tab switch). Fix: when `responses == 0`, the teaser swaps the `View full Strength Index` button for an inline nudge to the Distribute readiness card. The teaser label updates to `Needs at least one response to compute.` The bounce loop cannot occur because the broken link no longer exists. One file (`app.html`).

## Phase 145. Equity Gaps tab.

New ninth item in the More analyses dropdown, between Subgroups and Predictors. Cross-axis disparate-impact analysis. For every detected grouping variable (gender, race or ethnicity, age band, role, tenure, etc.) computes per-group means with 95% CI, an omnibus ANOVA, and pairwise Cohen's d. Per-axis verdict tier driven by max `|d|`: parity (<0.20) / small (<0.50) / meaningful (<0.80) / large (>=0.80). Composite Equity Gap Score 0 to 100 (100 = parity). k-anonymity floor of 5 (or user setting if higher) hides small subgroups entirely. AI narrator translates Cohen's d into HR language and names axes by their actual question label and groups by their actual option labels. Tab gate: `respN >= 15`. Builds on existing `Stats.cohensD` and `Stats.oneWayANOVA`; no new math primitives. Two files (`app.html`, `api/ai/narrate-equity-gaps.php`). No schema.

## Phases 146 through 154 (compressed)

App 2.0 shell scaffolding, sidebar parity work, and the groundwork that the Mixed-Methods Studio sits on. Detailed per-phase notes live in the memory directory.

## Phases 155 through 165. Mixed-Methods Studio.

A new sub-product inside App 2.0 (`app-2026.html`). 20-step workflow covering import, theme building, theme application, dataset construction, variable role tagging, clustering, analysis selection and execution, joint display, integration writing, strength checks, and the final report. Eighteen new `mm_*` tables (see the Mixed-Methods Studio section earlier). About 25 endpoints under `/api/mm/`. Schema files `db/schema_phase155.sql` through `db/schema_phase165.sql`. New tab strip: `Categories`, `Dataset`, `Analysis`, `Joint Display`, `Integration`, `Strength Check`, `Report` (Evidence Matrix hidden as redundant). DOCX export via `ZipArchive` in `api/mm/report-docx.php`. PDF export via `html2pdf.js` from CDN on demand. Local deterministic theme matcher is the default; AI is opt-in. Floating Intelligence-rewrite bubble on selected text inside report sections. Version history (last 10 per section). Section notes and to-dos. Project templates for reusable theme + variable-role sets.

## Phase 156. ReliCheck Intelligence rebrand.

All user-visible "AI" labels in `app-2026.html` renamed to "ReliCheck Intelligence" or the short form "Intelligence". Database enums and endpoint filenames unchanged. See the AI narrator section for the full label inventory.

\newpage

# Roadmap (not yet shipped)

## Test and Item Analysis Suite (target July through September 2026)

The existing `Test` tab in App 2.0 will be repositioned and renamed `Test and Item Analysis Suite`. Planned features:

- **DIF (Differential Item Functioning)** using Mantel-Haenszel chi-square plus logistic regression. Per-item DIF flag with effect-size tier.
- **Distractor analysis** with miskey detection: flags answer choices that high-scoring respondents pick more often than the keyed correct answer.
- **Item drift across administrations**: side-by-side per-item difficulty and discrimination across multiple test sessions to spot drift.
- **Classical vs IRT side-by-side**: a single view that shows CTT difficulty and discrimination next to IRT a- and b-parameters.
- **Test blueprint coverage**: a coverage table by learning objective showing item counts against the test blueprint.

Schema, endpoint surface, and `Stats` primitives are TBD. No deliverables before July.

\newpage

# Operations and deployment

- **Hosting**: IONOS shared PHP. The app is plain HTML and PHP, no Node, no PHP-FPM tweaks.
- **Deploy**: FileZilla against the IONOS account. Replace files in `/`, `/api`, `/db` as needed. Always read paste-style smoke output to confirm a 200 response with the expected content type before declaring done.
- **Database**: phpMyAdmin against `dbs15641829`. Schema migrations live at `db/schema_phaseN.sql` and are idempotent. Run by selecting the database in the top-left drop-down first.
- **Cron**: cron-job.org against `/api/cron-*` URLs. Same `email_cron_key` for every entry. The IONOS panel is not used. Active cron entries as of Phase 165: hourly pulse fire (`cron-fire-pulse.php`, Phase 119), daily calendar-followup fire (`cron-fire-calendar-followups.php`, Phase 128), and the email queue worker (`api/email/queue_run.php`, Phase 31).
- **Smoke tests**: every PHP edit gets the ASCII / em-dash / stray `?>` sweep before upload. Every JS edit gets `node -c` on the extracted inline JS.
- **Email**: 11 departmental addresses on `relichecksurvey.com`. `send_mail()` accepts `opts['from']` to choose the right sender.
- **Suite templates**: the suite-card `available` / `coming_soon` state in `api/_suites.php` is dynamic.

## Most common operational footguns

- **Wrong file uploaded**. An endpoint returns PHP source as the response body. Grep the file for `?>` inside `//` comments first.
- **SHOW LIKE with placeholder**. Fails silently on IONOS MySQL. Use sanitized inline values.
- **Cross-cutting endpoint changes**. Phase 18 broke the dashboard by editing an existing `list` / `get` / `update` endpoint. New features must be additive.
- **Timezone mismatch**. PHP and MySQL clocks differ. Compute expiries inline in SQL.
- **Mock vs real DB in tests**. Do not mock; integration tests must hit the real DB.
- **MySQL FK constraint names**. Must be globally unique per database, not per table.
- **Tab classification**. Survey-quality reads go in the surfaced top row; data-outcome reads go in the More analyses dropdown. Phase 144 caught Trends in the wrong row.
- **Multi-moment features**. When a feature serves more than one user moment (pre-publish and post-data), use the Phase 144b hybrid pattern: primary card at the moment-of-decision view, full chrome where the analysis lives, `ctx-bar` pill as the cross-view discoverability anchor.
- **Cross-phase reads**. Any endpoint that touches a companion table from another phase (e.g., `joint-display.php` reading `mm_analysis_results`) must probe with `SHOW TABLES LIKE` and try/catch the read. Missing tables return null, not HTTP 500 with HTML.
- **Runnable vs skipped**. Server-side action lists must be split into a runnable set and a skipped set. The client must never render a Run button for an op that will fail.
- **MM Studio padding**. When a Studio panel is reported as overflowing or having text "run over the edge", the first thing to check is padding (default 24px on all sides of inner cards), not text-overflow rules.

## Build handoff format (hard rule)

Every build delivery to the owner follows the same three-section format, in this order:

1. **Files to upload via FileZilla.** Repo-relative paths only (e.g., `api/mm/report-docx.php`). Never list absolute `/Volumes/...` paths. Never list remote URLs or "do not upload" callouts. The owner reads this as a checklist.
2. **SQL schema (inline copy-paste).** Paste the full SQL in a fenced code block in the reply, not just a file link. Every snippet starts with `USE dbs15641829;` on the first line. Without it phpMyAdmin throws `#1046 - No database selected`.
3. **Server-side verification steps.** A numbered smoke-test checklist using visible UI, FileZilla columns, phpMyAdmin SQL, or Network-tab observation. Never ask the owner to paste JavaScript in the DevTools Console. Bake diagnostics into the UI itself instead.

## Instructional copy rules

- **Use on-screen labels verbatim.** Step-by-step instructions to the owner copy headings, button text, and tab names exactly as they render. No paraphrasing.
- **App 2.0 is the spoken name** for `app-2026.html`. Use "App 2.0" in user-facing copy and conversation; "app-2026" only as a file label.
- **ReliCheck Intelligence is a proper noun.** Never write generic "AI" in user-facing copy. The short form "Intelligence" is allowed in inline labels.

End of document. Full per-phase memory is in `spaces/.../memory/project_relicheck_phase*.md`. Live feedback rules (no em-dashes, ASCII PHP source, three-strikes rule, k-anonymity defaults, build-handoff three-section rule, etc.) are in the `feedback_*.md` files.
