# MM Studio Desktop App — Audit + Import API Reference

Author: Claude (audit pass, 2026-05-19)
Scope: inventory of the analysis surface inside Methods Mixed Studio as it
exists today inside `app-2026.html`, plus a sketch of the contract a future
desktop app would use to pull data from relichecksurvey.com.

This document does not propose any code changes yet. It exists so that when
the desktop build starts, the engine team is not guessing.

---

## 1. Where MM Studio lives today

There is no `mm-studio.html` file. MM Studio is rendered as a draft route
inside the single-page App 2.0 shell.

| Layer | Location |
|---|---|
| UI shell, all tabs, all chart code | `app-2026.html`, the `MM` object near line 29330 and the `mmTab*` / `mmRender*` functions below it |
| Nav entry (hidden behind draft flag) | `app-2026.html` line 5442, `data-route="mm"` |
| Backend, one file per action | `api/mm/*.php` |
| Shared backend helpers | `api/_mm.php`, `api/_stats.php`, `api/_ai.php` |

The MM nav item is gated by `MM.draftFlag()` and `data-draft-only="1"`, so it
ships hidden in production. Everything below is fully wired and works on
real projects — it is the surface a desktop port would inherit.

---

## 2. Project shell: tabs and what each does

The Studio renders a project as a tab strip. Tab routing is in `mmRenderTab`
(`app-2026.html` near line 30509). Tabs vary slightly by `pathway` (the
score-plus-comments pathway adds two extra tabs).

| Tab key | Label | Renderer | Backend action(s) | What it does |
|---|---|---|---|---|
| `themes` | Categories | `mmTabCategories` | `categories.php`, `clusters.php`, `recode-category.php`, `themes-per-question.php`, `coded-responses.php` | Build and rename categories of qualitative codes; cluster categories into themes; per-question theme breakdowns |
| `dataset` | Dataset | `mmTabDataset` | `dataset.php`, `variable-roles.php`, `dataset-cell.php` | Generate one row per respondent, one column per variable; mark each variable as Predictor, Outcome, or Neutral |
| `analysis` | Analysis | `mmTabAnalysis` | `analysis-suggest.php`, `analysis-run.php` | Suggest a stat test per (Predictor, Outcome) pair and run it on demand. **This is the quantitative core.** |
| `joint` | Joint Display | `mmTabJointDisplay` | `joint-display.php` | Build the canonical mixed-methods joint display: theme × group × representative quote |
| `integration` | Integration | `mmTabIntegration` | `integration.php` | AI-generated integration paragraphs per theme (qual + quant woven together) |
| `strength` | Strength Check | `mmTabStrengthCheck` | `strength-check.php`, `quality-brief.php` | Runs ~12 health checks across the project (sample size, missingness, coding agreement, etc.) |
| `score` | Score-to-Theme | `mmTabScoreToTheme` | `score-to-theme.php` | AI clusters comments into low/mid/high bands defined by a numeric score |
| `alignment` | Alignment | `mmTabAlignment` | `alignment.php` | AI evidence-alignment check (aligned / divergent / nuanced / insufficient) |
| `matrix` | Matrix | `mmTabMatrix` | `matrix.php` | Cross-tab views of categories × groups |
| `report` | Report | `mmTabReport` | `report.php`, `report-docx.php`, `report-export.php`, `report-rewrite.php` | Compile a Word/HTML report from everything above |

Setup tabs (`data`, `builder`) live inside Step 1 / Step 2 of the wizard.

---

## 3. Quantitative analyses (the part the desktop app must replicate locally)

This is the answer to the audit's central question: "what math has to run on
the user's machine?"

The Analysis tab supports exactly **four parametric tests** today, all
implemented in pure PHP in `api/_stats.php`. There is no SciPy, R, or
third-party stats library involved on the server.

| Test ID | Display label | When suggested | Implementation | Effect size reported |
|---|---|---|---|---|
| `chi_square` | Chi-square | Two categorical variables | `stats_chi_square` (`_stats.php:165`) | Cramér's V |
| `t_test` | Welch's t-test | One numeric, one 2-level categorical | `stats_t_test` (`_stats.php:213`) | Hedges' g (or Cohen's d) |
| `anova` | One-way ANOVA | One numeric, one 3+ level categorical | `stats_anova` (`_stats.php:262`) | Eta-squared |
| `pearson` | Pearson r | Two numeric variables | `stats_pearson` (`_stats.php:313`) | r itself |

Test selection logic is in `stats_suggest_test` (`_stats.php:367`). The
distribution functions (`stats_chisq_pvalue`, `stats_t_pvalue`,
`stats_f_pvalue`, plus the gamma / beta helpers) are also pure PHP — no
external dependency, all numerical recipes style.

**Other quantitative endpoints that are NOT classical inferential stats:**

| Endpoint | What it computes | Local-port difficulty |
|---|---|---|
| `score-to-theme.php` | n, mean, median, min/max, terciles → then AI summary | Trivial; the math is one line |
| `alignment.php` | n, mean, median → then AI summary | Trivial |
| `strength-check.php` | Health checks (table existence, row counts, agreement %) | Trivial; mostly DB introspection |
| Cronbach's alpha, IRT (graded response model), mixed-effects model | Yes, these exist | See section 5 — these are in the App 2.0 shell, not currently in MM Studio's Analysis tab |

The mixed-effects model (`Stats.mixedModel`, `app-2026.html:7671`) and the
polytomous IRT estimator (`app-2026.html:7987`) are already written in
client-side JavaScript inside App 2.0 — they would port to a desktop app as-is.

---

## 4. Data model: tables the desktop app must read or write

A desktop app that wants to mirror today's Studio needs these tables
(prefix `mm_`):

| Table | Purpose |
|---|---|
| `mm_projects` | One row per MM project (owner, pathway, title, settings) |
| `mm_text_responses` | The raw qualitative rows (text, optional numeric_value, optional group_value) |
| `mm_categories` | User-built code categories |
| `mm_clusters` | Themes that group categories |
| `mm_response_codes` | Many-to-many assignment of responses to categories |
| `mm_generated_variables` | Variables built from categories/scores, with `role` ∈ {predictor, outcome, neutral} and `var_type` |
| `mm_structured_datasets` | One row per dataset build; analyses always use the latest |
| `mm_dataset_cells` | Long-format cell store: (dataset_id, variable_id, response_id, cell_value) |
| `mm_analysis_results` | Persisted test results; one row per (dataset, predictor, outcome, test) |
| `mm_strength_checks` | Latest strength-check rows per project |

A local engine would mirror this exact schema in SQLite. The one piece that
does not exist as a single artifact is "the whole project," because the
project is spread across these tables. A desktop export/import would need
either a `.mmproj` zip containing all of the above as JSON, or a thin
"project bundle" endpoint on the server that returns the joined snapshot.

---

## 5. Code already client-side that the desktop app inherits for free

App 2.0 already runs significant stats in the browser via the global `Stats`
object. Anything here is JavaScript with no server round-trip, so a
desktop port that reuses the App 2.0 frontend gets it for free.

| Capability | Location |
|---|---|
| Two-level linear mixed-effects model (REML/EM) | `app-2026.html:7670` (`Stats.mixedModel`) |
| Graded Response Model (polytomous IRT via MML/EM) | `app-2026.html:7987` |
| Test information curves | `app-2026.html:17843` |
| Sentiment tally and rendering | `app-2026.html:20330` and below |

These are not exposed in MM Studio's Analysis tab today, but they are
candidates for the desktop build's "advanced analyses" surface.

---

## 6. relichecksurvey.com import — what already exists

Good news: a tokenized v1 API already exists. The desktop app's "Import
from ReliCheck" flow can be built on top of it with no new backend.

| Endpoint | Method | Returns | Notes |
|---|---|---|---|
| `/api/v1/me` | GET | Token owner profile | Use to validate the token after sign-in |
| `/api/v1/surveys` | GET | All surveys owned by the token's user | Used to populate the survey picker |
| `/api/v1/surveys?id=<id>` | GET | One survey with questions + settings | Used to fetch the schema before pulling responses |
| `/api/v1/responses?survey_id=<id>&limit=<n>&since=<cursor>` | GET | Responses; cursor-paginated by id | The desktop app should page until empty |

Auth: Bearer token. Tier-gated — the user's plan must include `api_access`.
Implementation in `api/_api_auth.php` (`require_api_token`).

The browser/session auth used by the Studio itself
(`api/_session.php`, cookie `relicheck_sid`) is not appropriate for a
desktop app — tokens are the right answer.

---

## 7. Proposed contract additions for the desktop build

These do not exist yet but are the smallest set the desktop app would need
beyond `/api/v1`:

### 7.1 Token issuance from inside the desktop app

Today, API tokens are issued via the web UI (account settings). The
desktop app should not require the user to copy/paste a token. Two
acceptable patterns:

- **Device code flow** (recommended). App shows a short code; user signs
  in on the web and approves the device. App polls
  `POST /api/v1/device/poll` until the token appears.
- **Embedded OAuth-style web view**. Heavier; only worth it if there's a
  reason to avoid the device code flow.

New endpoints:

```
POST /api/v1/device/start
  → { device_code, user_code, verification_url, expires_in, interval }

POST /api/v1/device/poll
  Body: { device_code }
  → 200 { access_token, user: { id, name, email } }
  → 428 { error: "authorization_pending" }
  → 403 { error: "denied" } | 410 { error: "expired" }

GET /api/v1/users/me/tier
  → { tier, features: ["api_access", "mm_studio", ...] }
```

### 7.2 Project bundle (for round-tripping work between desktop and web)

```
GET  /api/v1/mm/projects                       → list (id, title, updated_at)
GET  /api/v1/mm/projects/<id>/bundle           → full snapshot as JSON
POST /api/v1/mm/projects/<id>/bundle           → upload edited snapshot
POST /api/v1/mm/projects/import                → create new project from bundle
```

The bundle is the JSON projection of every `mm_*` table for one project.
The schema is fully described by section 4 above.

### 7.3 What the desktop app does NOT need from the server

- The four parametric tests (run locally, ship the same constants).
- Strength checks (local DB introspection over the bundle).
- DOCX report rendering (use a JS or Python docx library locally).
- Joint display rendering (pure presentation; client side already).

AI-backed features (integration paragraphs, score-to-theme, alignment)
have to stay server-side unless the desktop app ships its own model key.
Those should degrade gracefully when the user is offline.

---

## 8. Decision summary

The desktop port is unblocked on the analysis side. Specifically:

- **All four MM Studio inferential tests are pure PHP with no external
  dependencies.** Reimplementing them in JS or Python is a few-hundred-line
  job, not a research project.
- **Two heavier engines (mixed-effects, IRT) are already in client-side
  JavaScript** inside App 2.0 and port verbatim.
- **The data import path already exists** as `/api/v1/surveys` and
  `/api/v1/responses` with token auth.
- **The largest missing piece is round-tripping projects** between the
  desktop app and the web. That requires either a project bundle endpoint
  or an export/import file format. Section 7.2 sketches the bundle.

The two design choices that should be made before any shell work begins:

1. Local engine language. JS keeps one codebase for UI and stats. Python
   gets you scipy/statsmodels/pingouin if you want to expand the analysis
   surface beyond what `_stats.php` covers today.
2. How desktop and web stay in sync. Project bundles (section 7.2) are the
   simpler answer; live sync is a much bigger project.
