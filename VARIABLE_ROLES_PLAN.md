# Variable Role Assignment — Implementation Plan

## The problem in one sentence

The MM Studio (and Compare/Predictors tabs in the main Analyze view) lose column names during ingestion and give the user no control over which column is an ID, an open-text response, a Likert item, a categorical group, or an outcome — so every downstream analysis runs on misclassified data with anonymous variable labels.

## What "done" looks like

A user uploads `dei.xlsx` or `DFS data.xlsx`:

1. The Structure Data step of the wizard shows every column with its **real name** and the platform's **guessed role** (id / open-text / likert / group-categorical / continuous-numeric / ignore).
2. The user can edit any guess via dropdown.
3. The corrected metadata is persisted to the database.
4. Every analysis tab (Compare, ANOVA, Pearson, Chi-square, Regression, MM Studio recommended tests) uses the real column names and lets the user pick from variables filtered by appropriate role.

## What's in scope for tomorrow

### 1. Database — add variable_roles to datasets

The `datasets` table likely already has a `columns` JSON field or similar. Add a `variable_roles` column (JSON) keyed by column name with values from the role enum:

```json
{
  "RespondentID":             "id",
  "Race":                     "group_categorical",
  "Gender":                   "group_categorical",
  "Role":                     "group_categorical",
  "L1_OrgPromotesEquity":     "likert",
  "L2_ContributionsValued":   "likert",
  "L3_LeadershipCommitment":  "likert",
  "Q1_IdentityInfluencedTreatment": "open_text"
}
```

Migration file: `db/schema_phaseNNN_variable_roles.sql` adding the column with a default of `NULL` (meaning "use auto-detection").

### 2. API endpoints

In `api/` (server-side PHP):

- `GET /api/datasets/variable_roles.php?dataset_id=N` — return current roles for a dataset (auto-detected if not set, otherwise the saved values)
- `POST /api/datasets/variable_roles.php` — body `{ dataset_id, roles: {...} }` — save user-edited roles. Authenticated via _session.php as usual.

Use the existing `_db.php` pattern. Both endpoints should validate that the user owns the dataset.

### 3. Auto-detection — make it smarter

Current behavior (the bug): a column of integers `[1,2,3,4,5]` with no scale label is classified as Likert. That's wrong if the integers are codes for categories (Gender 1/2/3).

Better rules:

| Detection signal | Suggested role |
|---|---|
| Column is exclusively long strings (>20 chars avg) | `open_text` |
| Column is numeric, ≥4 distinct values, "agree/scale/rating/strongly" in name OR most rows in 1-7 range | `likert` |
| Column is numeric, ≤7 distinct values, no scale signal in name | **flag for user review** (default `group_categorical`, but show "we guessed — please confirm") |
| Column is numeric, many distinct values, range > 20 | `continuous_numeric` |
| Column name matches `/id$|_id$|^id$/i` | `id` |
| Otherwise | `unknown` — force user choice |

Critically: never silently treat a small-cardinality numeric column as a Likert scale.

### 4. Wizard UI — make Structure Data editable

The "Confirm what we found" page (visible in the May 23 screenshot) currently lists columns read-only. Change it so each row has:

- The real column name (left)
- A role dropdown (right) with: ID · Open text · Likert item · Categorical group · Continuous numeric · Ignore
- A small "5 distinct values: 1, 2, 3, 4, 5" hint to show the data
- A "we guessed: X" badge so the user sees what was inferred

Save on "Continue" — POST to `/api/datasets/variable_roles.php`.

Code location: search for `'Bring your data in'` in `app-2026.html` to find the wizard step. The dataset-confirmation render is somewhere near there.

### 5. Replace hardcoded outcome dropdowns

Currently the ANOVA card in MM Studio says only `OUTCOME: "Mean of all Likert items"` — no other options. Find and replace these hardcoded outcome lists:

- ANOVA card outcome dropdown
- Welch's t-test outcome dropdown
- Pearson r variable 1 / variable 2 dropdowns
- Chi-square row variable / column variable
- Compare tab in the main Analyze view
- Predictors tab outcome (Y)

Each should populate from variables matching the appropriate role. ANOVA outcome: any `likert` or `continuous_numeric`. ANOVA group: any `group_categorical` or low-cardinality column. Chi-square row/column: any `group_categorical`. Etc.

Code location: `mmRenderTab` and its sub-renderers in `app-2026.html`. The `recEligibility` object probably already classifies variables — extend it to use the saved roles.

### 6. Show real column names everywhere

Anywhere the UI shows `col_1 (7 values)` or `col_4 (Likert)` — that's the bug. Track down where the renaming happens (it's probably in the ingestion path or the eligibility computation) and replace with the actual column name.

## Out of scope for tomorrow (but worth noting)

- The `[object Object],[object Object]` bug in the AI summary template — a separate small fix in the Anthropic prompt template where an item array is being toString'd instead of joined.
- Predictors `n ≥ 200` requirement — that's a sample-size gate, not a bug. Surface it more gracefully so users know what would unlock it.
- The MM Studio "down" issue — was opcache delay, not a real bug. No action needed.

## Verification before declaring done

1. Re-upload `dei.xlsx`. Confirm Gender / Race / Role show as group-categorical, L1/L2/L3 show as Likert, Q1-Q6 show as open-text. User can override any of these.
2. Run ANOVA in MM Studio. Outcome dropdown should let me pick `L1_OrgPromotesEquity` (alone), `L2_ContributionsValued`, `L3_LeadershipCommitment`, or "Mean of all Likert items". Group dropdown should show `Gender (3 groups)`, `Race (5 groups)`, `Role (...)`.
3. Run Chi-square. Row and column dropdowns should show real categorical column names.
4. Re-upload a numeric-coded categorical dataset (DFS). Confirm those columns are flagged for user review, not silently called Likert.
5. Re-run all regression tests: `python3 test_data/verify_dei_analyses.py` and `python3 test_data/verify_test_retest.py` should still pass.

## File-touch checklist (rough)

- `db/schema_phaseNNN_variable_roles.sql` — new migration
- `api/datasets/variable_roles.php` — new endpoint (GET + POST)
- `api/datasets/columns.php` (or similar) — update auto-detection logic to be smarter and surface guesses
- `app-2026.html` — wizard step UI edit + dropdown population for MM Studio analyses + Compare/Predictors in main Analyze
- `test_data/dei.expected.json` + verify script — should still pass; if anything in role assignment changes the dropped-N behavior, update fixture deliberately

Resume with this file open.
