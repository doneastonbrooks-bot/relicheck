# Claude Code Brief — ReliCheck v2 Build Spec

This document is the complete architecture and build spec for ReliCheck v2. The product has a working v1, and v2 restructures it around a consistent taxonomy, a three-lens scoring system, a hub-and-detail layout, and a setup wizard. Carry the strongest parts of v1 forward; refactor or remove the rest. Treat this brief as authoritative when it conflicts with v1.

---

## 0. Before you write any code

1. Read `README.md`, the framework config (`package.json`, `pyproject.toml`, or equivalent), and the existing analysis, scoring, and view files in the repo. Confirm the language, framework, package manager, test runner, lint config, and styling conventions.
2. Audit the v1 ReliCheck Strength Score implementation. Specifically: load the sample-data-360-leadership dataset shown in the existing screenshots and compute the composite by hand. The v1 composite of 58 with a Reliability sub-score of 8 (out of 25 in v1 weights) is mathematically inconsistent. Identify whether the bug is in the weighting, the sub-scoring, or both, and document the finding before touching code.
3. Identify which v1 components are reusable as-is (most notably the interactive item analysis table) and which must be refactored or discarded.
4. Read this entire brief before writing the first line. The architecture pieces depend on one another.

If anything in the repo contradicts this brief, stop and surface the conflict. Do not make architectural decisions on your own.

---

## 1. Objective

Build ReliCheck v2: a web-based instrument-quality analysis tool that ingests survey data, computes a three-lens Survey Strength Index across eight domains, and presents the results through a hub-and-detail layout that serves both non-statistician users and methodologists.

What v2 fixes about v1:

- A single, consistent eight-domain taxonomy used in the nav, the score cards, the report panel, and the math. v1 has three different domain lists that do not reconcile.
- A three-lens scoring system (Psychometric Core, Respondent-Centered, Validity-Forward) over the same eight domain sub-scores. v1 has one opaque composite.
- A hub-and-detail navigation pattern with the eight domains as both nav and content. v1 mixes a sidebar nav with disconnected score cards.
- A first-run setup wizard that gates scoring until item structure, scale groupings, and reverse-coding are confirmed. v1 has no setup gate, so users can land on misleading scores.
- A version system so every rerun is preserved and comparable. v1 has cryptic version labels and no comparison.

What v2 keeps from v1:

- The interactive item analysis table with toggleable items and live Cronbach's α recompute. This is the product's defining feature.
- The big-number-plus-plain-language pattern on the headline score.
- The improvement priorities list, reframed as "Issues to Fix" with severity ordering.
- The Print/Save PDF affordance on the report.

---

## 2. The non-negotiable taxonomy

These eight domains are the single source of truth. Use them in the left nav, the Hub cards, the report sections, the score-card grid, the API response, and every database field. Do not introduce synonyms or alternate names anywhere in the product.

| # | Domain | What it measures | Sub-scoring spec |
|---|---|---|---|
| 1 | Reliability | Internal consistency of each scale (α, ω, α–ω agreement, item-rest correlations, redundancy) | Section 4 |
| 2 | Validity | Convergent, discriminant (HTMT), and criterion validity of each scale | Section 4A |
| 3 | Construct Alignment | Per-scale CFA loadings, fit indices, and cross-loadings | Section 4B |
| 4 | Item / Prompt Quality | Per-item statistical health for Likert items; response rate, length, placeholder rate, and duplicate-text rate for open-ended prompts | Section 4C |
| 5 | Bias & Clarity Review | Wording health (reading grade, double-barreled, leading/loaded language) and fairness (DIF proxy across demographic slices) | Section 4D |
| 6 | Scale Structure | Item count per scale, reverse-coded balance, response-format uniformity, scale-level missingness pattern | Section 4E |
| 7 | Factor Readiness | KMO, Bartlett's test of sphericity, and correlation-matrix determinant | Section 4F |
| 8 | Response Scale Review | Likert design (anchor count, midpoint, symmetry, single-format-per-scale, per-item response-distribution shape) and respondent behavior (completion rate, missingness rate, straight-lining rate) | Section 4G |

Open-ended items are scored under Item / Prompt Quality, not as a separate domain. Actionability is removed from the RSSI entirely. Reporting Readiness is a separate report-side panel, never part of the eight-domain composite (see Section 11).

If the v1 codebase contains labels such as "Scale Strength," "Survey Structure," "Question Quality," "Reliability Readiness," "Validity Alignment," or "Response Risk," map them to the eight canonical domains above. Do not preserve v1 labels in v2.

---

## 3. Scoring architecture

### 3.1 Domain sub-scores

Each of the eight domains produces a 0–100 sub-score, computed once per analysis run. Sub-scoring math for the Reliability domain is fully specified in Section 4. Sub-scoring math for the other seven domains must be sourced from the v1 codebase, audited against this brief, and confirmed with the project owner before reuse. If any domain lacks a defined sub-component breakdown, stop and ask.

### 3.2 The three lenses

The same eight domain sub-scores feed three different RSSI calculations, each with a different weight vector. The user chooses which lens is the headline; all three appear on every report.

| Domain | A. Psychometric Core | B. Respondent-Centered (default) | C. Validity-Forward |
|---|---|---|---|
| Reliability | 22 | 15 | 15 |
| Validity | 22 | 15 | 22 |
| Construct Alignment | 14 | 10 | 15 |
| Item / Prompt Quality | 12 | 18 | 14 |
| Bias & Clarity Review | 8 | 14 | 10 |
| Scale Structure | 6 | 8 | 6 |
| Factor Readiness | 10 | 8 | 10 |
| Response Scale Review | 6 | 12 | 8 |
| **Total** | **100** | **100** | **100** |

Implement the weights as a versioned configuration object, not as hard-coded literals scattered through the code. Tag the configuration with `RSSI_WEIGHTS_VERSION = "v2.0"`.

### 3.3 RSSI computation

For each lens:

```
RSSI_lens = Σ (D_i_subscore × weight_i) / 100
```

Where `D_i_subscore` is the 0–100 sub-score for domain `i` and `weight_i` is that domain's weight under the chosen lens. The result is 0–100.

### 3.4 Default lens behavior

- New users land with Respondent-Centered as the headline lens.
- The chosen lens is remembered per survey, not per user. Different surveys can use different lenses.
- All three lens scores compute on every run and persist in every saved version.

### 3.5 The disagreement readout

When the spread between the highest and lowest of the three lens scores exceeds 10 points, surface a one-sentence interpretation. Implement the three diagnostic sentences below as a lookup based on which lens is highest and which is lowest:

- Psychometric Core much higher than Respondent-Centered: "Your scales are statistically sound, but the items may be hard for respondents to answer clearly. Review item wording before deployment."
- Respondent-Centered much higher than Psychometric Core: "Your items read well, but the underlying scales are not holding together statistically. Consider adding items or refining constructs."
- Validity-Forward much lower than the other two: "Your survey is internally consistent, but you may not yet have evidence it measures what you claim. Add criterion data or pilot against an established instrument."

Add additional pairings for other lens-disagreement patterns; surface them as the project owner refines copy. When spread is 10 points or less, hide the readout. Always show a small "?" affordance that opens an explanation of the three lenses.

### 3.6 Validity-Forward evidence cap

Validity (Section 4A) splits into three equally-weighted sub-components: convergent validity (20 pts of 60), discriminant validity via HTMT (20 pts of 60), and criterion validity (20 pts of 60). The 60-point raw total rescales to a 0–100 sub-score by multiplying by 100/60 = 1.667.

When criterion data is absent (no `criterion_column` is configured, per Section 13):

- The criterion sub-component is skipped, not zero-filled. The raw total becomes a 40-point scale (convergent + discriminant) rescaling to a 0–100 sub-score by multiplying by 100/40 = 2.5, with a hard cap of 60 applied so the absence of criterion evidence cannot produce an indistinguishable headline from a fully-evidenced instrument.
- The cap is surfaced on the Validity-Forward lens score with a "limited evidence" indicator.
- The Validity-Forward score is never silently lowered below the other two lenses without an explanation; the disagreement readout (Section 3.5) absorbs the case where the cap drives Validity-Forward more than 10 points below the others.

---

## 4. Reliability domain sub-scoring (fully specified)

The Reliability domain produces a 0–100 sub-score. The points break down as follows; convert the 25-point total to a 0–100 sub-score by multiplying by 4.

| Subcomponent | Points (of 25) | How to compute |
|---|---|---|
| Cronbach's α | 8 | Compute per scale. Weight scales by item count when multiple scales exist. Map weighted α to points using the band in Section 4.1. |
| McDonald's ω total | 10 | Fit a single-factor model per scale (or accept the configured factor structure). Compute ω from standardized loadings. Map to points using the band in Section 4.1. |
| α–ω agreement | 3 | Full points when `abs(α − ω) ≤ 0.05`. Partial points for `0.05 < diff ≤ 0.10`. Zero for larger gaps. |
| Item-rest correlations | 3 | Use corrected item-rest correlations (each item correlated with the sum of the other items in its scale). Start at 3 pts. Deduct 1.0 pt per item with 0 ≤ r < 0.30 ("weak"). Deduct 1.5 pts per item with r < 0 ("negative"). Clamp to [0, 3]. This continuous deduction is carried forward from v1 because it preserves more diagnostic information than a tiered cutoff. |
| Redundancy check | 1 | Flag inter-item correlations > 0.85 within a scale. Award the point when no redundant pairs exist. |

### 4.1 α and ω point bands

| Coefficient value | α points (of 8) | ω points (of 10) |
|---|---|---|
| ≥ 0.90 | 8 | 10 |
| 0.80–0.899 | 7 | 9 |
| 0.70–0.799 | 5 | 7 |
| 0.60–0.699 | 3 | 4 |
| < 0.60 | 0 | 0 |

These bands are the canonical v2 values (confirmed during the Section 0 audit). v1's softer floors (α < 0.60 → 2 pts, ω < 0.60 → 2 pts, α 0.70–0.799 → 5.5 pts) are superseded; v2 ships harder zero floors so an instrument that fails the 0.60 reliability threshold cannot quietly contribute points.

α–ω agreement (Section 4 table) is awarded purely on the absolute gap |α − ω|. v1 gated full points on both α and ω being ≥ 0.80; v2 removes that gate. The agreement point measures whether the two coefficients tell the same story, independent of magnitude.

---

## 4A. Validity domain sub-scoring

The Validity domain produces a 0–100 sub-score. Three sub-components, each 20 raw points (60 total when criterion data exists; 40 when absent — see Section 3.6 for the rescale and cap).

| Subcomponent | Points | How to compute |
|---|---|---|
| Convergent validity | 20 | For each scale, compute the average corrected item-total correlation. Map the cross-scale mean to points: ≥ 0.50 → 20, 0.40–0.499 → 16, 0.30–0.399 → 12, 0.20–0.299 → 6, < 0.20 → 0. |
| Discriminant validity (HTMT) | 20 | For each pair of scales, compute the Heterotrait-Monotrait ratio. Map the maximum HTMT across pairs to points: ≤ 0.85 → 20, 0.85–0.90 → 14, 0.90–0.95 → 8, > 0.95 → 0. Use HTMT, not raw inter-scale correlation. |
| Criterion validity | 20 | When `criterion_column` is configured, correlate each scale's total score with the criterion. Map the maximum absolute correlation across scales to points: ≥ 0.50 → 20, 0.30–0.499 → 14, 0.20–0.299 → 8, < 0.20 → 0. When `criterion_column` is absent, skip this sub-component and apply the cap in Section 3.6. |

Raw total: 60 with criterion, 40 without. Rescale to 0–100 per Section 3.6. The cap engages whenever the criterion sub-component is skipped, irrespective of how high convergent and discriminant score.

---

## 4B. Construct Alignment domain sub-scoring

The Construct Alignment domain produces a 0–100 sub-score. Unit of analysis is the per-scale confirmatory factor model declared by the user in the Setup Wizard (Section 8 step 2). Total 25 raw points; rescale ×4.

| Subcomponent | Points (of 25) | How to compute |
|---|---|---|
| Primary loading strength | 10 | Per scale, fit a single-factor CFA. Average the standardized loadings. Map cross-scale mean: ≥ 0.70 → 10, 0.60–0.699 → 7, 0.50–0.599 → 4, < 0.50 → 0. |
| Weak-loading penalty | 5 | Start at 5. Deduct 1 pt per item with standardized loading < 0.40, clamped to [0, 5]. |
| Cross-loadings | 5 | Fit an exploratory model with k = number of declared scales factors and oblique rotation. Flag any item whose secondary loading is within 0.20 of its primary and both ≥ 0.30. Start at 5, deduct 1 pt per cross-loaded item, clamped to [0, 5]. |
| Model fit | 5 | Use the per-scale CFA fit indices. Award 5 pts when every scale's CFI ≥ 0.95 and RMSEA ≤ 0.06. Award 3 pts when every scale's CFI ≥ 0.90 and RMSEA ≤ 0.08. Award 0 otherwise. Single-item and two-item scales are excluded from this check (fit indices are not defined). |

When CFA fails to converge for a scale, fall back to EFA with one factor and surface a warning flag, per Section 14.

---

## 4C. Item / Prompt Quality domain sub-scoring

The Item / Prompt Quality domain produces a 0–100 sub-score. Likert and open-ended sub-components are weighted by item count. Total 20 raw points; rescale ×5.

### Likert items (per item, then aggregated)

Start each Likert item at full credit. Deduct against these flags:

- Ceiling / floor: ≥ 70% of responses at the top or bottom anchor.
- Low variance: SD < 15% of the response range.
- Skew: |skew| > 2.
- Kurtosis: |excess kurtosis| > 5.
- Item missingness: ≥ 20%.

Each flagged item costs 1 point against the 20-point Likert pool. A single global cap of 12 points applies — total Likert deductions cannot exceed 12, regardless of how many items trip flags. This replaces v1's per-type caps. Item-rest negative-correlation flags are not duplicated here; they live in Reliability (Section 4).

### Open-ended items (per open-ended field, then aggregated)

Start each open-ended field at full credit. Deduct against:

- Response rate < 70%.
- Average word count < 5 (placeholder-shaped answers).
- Placeholder text rate (answers matching `n/a`, `na`, `none`, `asdf`, `xxx`, single characters, or pure punctuation) ≥ 10%.
- Duplicate-text rate (identical normalized answers across respondents) ≥ 20%.

Each flag costs 1 point against the open-ended pool. No sentiment or topic analysis in v2 phase one.

### Combining Likert and open-ended

The 20-point raw total is allocated proportionally by item count:

```
likert_share = n_likert_items / (n_likert_items + n_open_items)
raw = 20 × (likert_share × likert_score_frac + (1 − likert_share) × open_score_frac)
```

When `n_open_items = 0`, the open-ended sub-component is **skipped, not zero-filled**. The raw total is computed entirely from Likert items. v1's "neutral 7 when no open-ends exist" rule does not apply in v2.

---

## 4D. Bias & Clarity Review domain sub-scoring

The Bias & Clarity Review domain produces a 0–100 sub-score. Total 20 raw points; rescale ×5.

| Subcomponent | Points (of 20) | How to compute |
|---|---|---|
| Wording health | 12 | Start at 12. Per Likert and open-ended item, deduct 1 pt for: (a) Flesch-Kincaid reading grade > 12, (b) double-barreled construction (item text contains a coordinating conjunction joining two distinct propositions — heuristic detector on "and" / "or" with a verb on each side), (c) leading or loaded language (item contains hedges or absolutes from a static lexicon: "obviously," "clearly," "always," "never," "everyone," "no one"). Clamp deductions to [0, 12]. |
| Fairness (DIF proxy) | 8 | When demographic columns are configured: for each Likert item, compute the response-mean difference across the largest two demographic groups. Flag items with |mean_diff| ≥ 0.5 points (on a 5-pt scale) as DIF candidates. Start at 8, deduct 1 pt per flagged item, clamped to [0, 8]. Use this cheap demographic-slice proxy, not full Mantel-Haenszel, in v2 phase one. When no demographic columns are configured: skip this sub-component, rescale wording-health alone to 0–100 by multiplying by 100/12 ≈ 8.33, and surface a "no demographics provided" indicator. Do not zero-fill the fairness component. |

Wording health is always score-able. Fairness requires demographics; when absent, the domain still produces a score but flags the absence visibly on the domain detail page.

---

## 4E. Scale Structure domain sub-scoring

The Scale Structure domain produces a 0–100 sub-score. Unit of analysis is the per-scale architecture declared in the Setup Wizard. Total 15 raw points; rescale ×(100/15).

| Subcomponent | Points (of 15) | How to compute |
|---|---|---|
| Item count per scale | 5 | For each scale: 5 pts when 4 ≤ k ≤ 8; 3 pts when k = 3; 2 pts when k = 9–15; 1 pt when k = 2; 0 pts when k = 1 or k > 15. Average across scales. |
| Reverse-coded balance | 3 | For each scale with k ≥ 4: 3 pts when at least one reverse-coded item is present (per Setup Wizard step 3 flags), else 0. For scales with k < 4, the check does not apply and the 3 pts are awarded by default. Average across scales. |
| Response-format uniformity | 3 | For each scale: 3 pts when all items share the same response format (same Likert range, same anchor count), else 0. Per Section 15, mixed formats within a scale already surface as a configuration error; this sub-component awards the corresponding 3 pts as a domain-level signal. Average across scales. |
| Scale-level missingness pattern | 2 | For each scale: 2 pts when no respondent has all items missing within the scale; 1 pt when 1–5% do; 0 pts when > 5% do. Average across scales. |
| Survey-level item-count health | 2 | 2 pts when 5 ≤ total Likert items ≤ 30; 1 pt when total is 4 or 31–40; 0 pts when total < 4 or > 40. (This replaces v1's k<5 / k>30 logic from Actionability.) |

---

## 4F. Factor Readiness domain sub-scoring

The Factor Readiness domain produces a 0–100 sub-score. Total 15 raw points; rescale ×(100/15). All three sub-components operate on the full pooled Likert matrix (not per-scale) because they answer "is the data factorable at all?"

| Subcomponent | Points (of 15) | How to compute |
|---|---|---|
| Kaiser-Meyer-Olkin (KMO) | 8 | KMO ≥ 0.80 → 8; 0.70–0.799 → 6; 0.60–0.699 → 3; < 0.60 → 0. |
| Bartlett's test of sphericity | 4 | p < 0.001 → 4; p < 0.01 → 3; p < 0.05 → 1; p ≥ 0.05 → 0. |
| Correlation-matrix determinant | 3 | det(R) ≥ 0.00001 → 3 (matrix is well-conditioned); 1e-7 ≤ det(R) < 1e-5 → 1; det(R) < 1e-7 → 0 (near-singular, multicollinearity risk). |

This domain answers whether the data *supports* factor analysis. Whether the user's *declared* factor structure holds is the Construct Alignment domain's job (Section 4B).

---

## 4G. Response Scale Review domain sub-scoring

The Response Scale Review domain produces a 0–100 sub-score. Two halves: Likert design (12 pts) and respondent behavior (8 pts). Total 20 raw points; rescale ×5.

### Likert design (12 pts)

| Subcomponent | Points | How to compute |
|---|---|---|
| Anchor count | 3 | 5- or 7-point scale → 3; 4 or 6 → 2; 3 or 10+ → 1; 2 → 0. Per scale, averaged. |
| Midpoint presence | 2 | Odd number of anchors (allows neutral midpoint) → 2; even → 1. Per scale, averaged. |
| Anchor symmetry | 2 | When anchor labels are configured: balanced positive/negative endpoints → 2; unbalanced → 0. When anchor labels are absent: award 2 by default. Per scale, averaged. |
| Single-format-per-scale | 2 | All items in a scale share the same response range → 2; otherwise → 0. (Mixed formats already surface as a configuration error per Section 15; this sub-component records the result at the domain level.) Per scale, averaged. |
| Response-distribution shape | 3 | For each Likert item, count the proportion picking the modal anchor. Flag items where the modal proportion ≥ 0.60. Start at 3, deduct 0.5 pt per flagged item, clamped to [0, 3]. |

### Respondent behavior (8 pts)

| Subcomponent | Points | How to compute |
|---|---|---|
| Completion rate | 3 | ≥ 95% complete responses → 3; 85–94.9% → 2; 70–84.9% → 1; < 70% → 0. |
| Item missingness rate | 3 | ≤ 5% overall missing cells → 3; 5–10% → 2; 10–20% → 1; > 20% → 0. |
| Straight-lining rate | 2 | Computed *per scale*, not across the full Likert matrix. Average per-scale straight-line rate ≤ 2% → 2; 2–5% → 1; > 5% → 0. (v1 computed this across all items, which false-positives on single-scale surveys; v2 fixes this.) |

Sample size does **not** enter this score. It is surfaced as a warning per Section 15 ("low N") but does not deduct points.

---

## 5. User journey

### Stage 1 — Entry
Global "New Survey Analysis" action on the main dashboard. The user uploads a CSV or connects an integration. ReliCheck parses the file and detects column types as a starting guess.

### Stage 2 — Setup (first-run only)
Three steps, none skippable, gated:

1. Confirm item structure. For each column: Likert, open-ended, demographic, or excluded. ReliCheck pre-fills a guess; the user corrects.
2. Group items into scales. The user assigns items to constructs. ReliCheck can suggest groupings via cluster analysis. The user owns the final map.
3. Flag reverse-coded items. Plain-language prompt: "Which items are worded so that 'strongly agree' means something *negative* about the construct?"

When all three steps are complete, the user lands on the Hub.

### Stage 3 — Hub
The home base. Detailed in Section 7.

### Stage 4 — Domain detail
Eight pages, one per domain. The user reaches them from the Hub cards, the left nav, or the Issues to Fix list. Detailed in Section 9.

### Stage 5 — Report
Single exportable view, web and PDF. Detailed in Section 11.

---

## 6. Screens (seven primary types)

1. Main dashboard (list of surveys + new survey action)
2. Upload screen
3. Setup wizard (three steps, gated)
4. Hub (survey home page)
5. Domain detail page template, instantiated eight times
6. Report view with PDF export
7. Version comparison view

Settings live as a persistent affordance, not as a primary screen.

---

## 7. The Hub

Top to bottom, four regions.

### Region 1 — Headline (top ~20%)

- Three gauges side by side. The chosen lens is the large center gauge; the other two are smaller side gauges with labels.
- Below the gauges: the disagreement readout sentence, only when spread > 10 points.
- Top-right corner of the region: survey name, current version number, last-recomputed timestamp, and a "Compare to previous version" link.
- A small "switch lens" affordance lets the user change which lens is the headline.

### Region 2 — Issues to Fix (~25%)

- Severity-ordered (high, medium, low), then chronological within severity.
- Each item shows: a short plain-language description of the problem, which domain it lives in, severity badge, and a "Fix this" link to the relevant domain detail page.
- When lift can be calculated by hypothetically applying the fix and recomputing, show the lift as "Est. lift +N." Lifts must be computed, never guessed.
- When there are no issues: a single-line confirmation reading "No outstanding issues. Survey is ready for use." Collapse the region's vertical space accordingly.

### Region 3 — Domain Cards (~55%)

- Eight cards in a 4×2 grid on desktop, 2×4 on medium screens, 1×8 on mobile.
- Each card shows: domain name, current sub-score (e.g., "82 / 100"), a color frame (green ≥ 80, yellow 60–79, red < 60), a one-sentence summary, and a change indicator when the score has shifted since the previous version.
- Card click navigates to that domain's detail page.

### Region 4 — Metadata (collapsed by default)

- N respondents, N items, N scales detected, missing-data rate, current lens, current missing-data policy.
- Reveals on click. Closed for most users.

---

## 8. Setup wizard

Three sequential steps, no skip, no save-and-exit until complete (save-and-exit can come post-v2).

### Step 1 — Confirm item structure
- Show all columns with detected type (Likert, open-ended, demographic, excluded).
- User corrects.
- Validation: at least one Likert column must exist, or the wizard refuses to proceed.

### Step 2 — Group items into scales
- Suggest groupings via correlation clustering or column-name heuristics (e.g., "COMM1, COMM2, COMM3" → Communication scale).
- User assigns, renames, splits, merges.
- Validation: every Likert item must belong to exactly one scale, or be explicitly excluded from scoring.

### Step 3 — Flag reverse-coded items
- Plain-language prompt with examples.
- ReliCheck can suggest reverse-coded items by detecting negative correlations against scale total.
- User confirms or corrects.
- Validation: after marking, no item in any scale may have a negative corrected item-rest correlation. If one remains, surface a warning and force the user to either flip it or exclude it.

After Step 3 passes validation, the user lands on the Hub and the first analysis run executes.

---

## 9. Domain detail page template

Eight pages share this layout. Section 10 covers the one exception (Reliability, which carries the interactive item analysis).

Top to bottom:

1. **Header.** Domain name, current sub-score, color frame, one-sentence summary.
2. **What this domain measures.** Two sentences of plain language. Static copy per domain, written by the project owner.
3. **Sub-component breakdown.** Each sub-component (e.g., for Reliability: α, ω, agreement, item-rest, redundancy), its score, and a status indicator.
4. **Flagged items or scales.** Tabular list of every item or scale contributing a problem to this domain, with plain-language explanations.
5. **Recommended actions.** Bullet list of specific fixes, each with a "Fix this" link if the fix is actionable in-product (e.g., toggling a reverse-coded flag) or instructions if the fix is editorial.
6. **Technical detail toggle.** Collapsed by default. Reveals statistical specifics for methodologists: formulas, exact values, citations.

---

## 10. Reliability domain detail (the killer feature)

The Reliability page carries the interactive item analysis from v1. Preserve and refine.

Required elements:

- Header showing Cronbach's α for the currently selected scale, color-coded (red < 0.60, yellow 0.60–0.79, green ≥ 0.80), labeled with the qualitative band ("Low," "Acceptable," "Good," "Excellent").
- Comparison readout: "vs. original +0.000" updates as the user toggles items off, showing the lift gained by exclusion.
- Items table with columns: USE (checkbox), ITEM, N, MEAN, SD, ITEM-TOTAL R, α IF DELETED.
- Green highlight on the α IF DELETED column for items whose removal would raise α.
- "Reset to original" button.
- "Download" affordance for the table.
- Live recompute on every toggle, with no page reload.

Add for v2:

- A scale selector at the top when more than one scale exists in the survey.
- A "lock in changes" affordance that promotes the current toggle state to a new analysis version (rather than only existing as exploration).
- A McDonald's ω readout alongside α, computed live where feasible. When live recompute of ω is too slow, recompute on demand via a "Recalculate ω" button.

---

## 11. Report view

A single, printable, exportable page that pulls together:

- The three-lens RSSI scores with the chosen lens as the headline.
- The disagreement readout (when active).
- All eight domain sub-scores in a compact grid.
- The top five issues from the Issues to Fix list.
- A separate **Reporting Readiness** panel (formerly Actionability in v1; now extracted from the RSSI entirely). This panel describes whether the report itself is presentable, separate from instrument quality. It is never part of the eight-domain composite. Five sub-indicators, each rendered as a status pill (✓ / ⚠ / ✗) on the panel:
  1. **Sample size adequacy.** ✓ when N ≥ 100; ⚠ when 30 ≤ N < 100; ✗ when N < 30.
  2. **Missingness rate.** ✓ when overall missing cells ≤ 5%; ⚠ when 5–20%; ✗ when > 20%.
  3. **Three-lens completeness.** ✓ when all three lens scores are computed and none is capped; ⚠ when any lens is capped (e.g., Validity-Forward without criterion data); ✗ when any lens failed to compute.
  4. **Provenance.** ✓ when the version stamp, the RSSI weights version, and the analyst identifier are all populated; ✗ otherwise.
  5. **Methods paragraph.** ✓ when the auto-generated methods paragraph (sample size, scale counts, α, ω, fit indices) is present and non-empty; ✗ otherwise.

  When all five are ✓, the panel shows "Report ready." When any indicator is ✗, the panel shows "Report not ready" with a one-line explanation of the blocker. The Reporting Readiness panel never produces a numeric score that contributes to the RSSI.
- A methods-section paragraph the user can copy directly into a research paper, auto-generated from the analysis (sample size, scale counts, α, ω, fit indices).
- The version number, the analyst, and the timestamp.

Export formats: web view, PDF, copy-to-clipboard for the methods paragraph.

---

## 12. Versioning

Every analysis run creates a new version. Versions are immutable.

- Version IDs are sequential per survey: v1, v2, v3...
- Every version stores: input data hash, scale and reverse-coding configuration, lens weights version, all eight sub-scores, all three RSSI scores, every flagged item or scale, the analyst, and the timestamp.
- Auto-recompute triggers a new version whenever the user changes scale assignments, reverse-coding flags, or excluded items.
- The "scores updated" notification surfaces after auto-recompute with a link to view the diff.
- Version comparison is a separate page (Screen 7), not inline on the Hub.

---

## 13. Data contracts

### Input

The analysis module accepts:

- A response matrix: rows are respondents, columns are item IDs.
- An item schema: per-item metadata including item ID, scale or construct membership, response format (Likert, binary, continuous, open-ended), Likert range, and a reverse-coded flag.
- A configuration object: missing-data policy (`listwise`, `pairwise`, `mean_impute`, `mice`), minimum N warning threshold (default 100), language for the user-facing report, the chosen lens, optional `criterion_column` (the item ID of a column to treat as a criterion variable for criterion validity, per Section 4A; when omitted, the Validity criterion sub-component is skipped and the Validity-Forward cap engages per Section 3.6), and optional `demographic_columns` (list of item IDs to use for the DIF proxy in Section 4D; when empty, the Bias & Clarity fairness sub-component is skipped). The user identifies `criterion_column` and `demographic_columns` during the Setup Wizard.

### Output

A structured object:

```
{
  "rssi": {
    "psychometric_core": <0-100>,
    "respondent_centered": <0-100>,
    "validity_forward": <0-100>,
    "headline_lens": "respondent_centered",
    "disagreement_readout": "<sentence or null>",
    "validity_forward_capped": <bool>
  },
  "domains": {
    "reliability": { "subscore": <0-100>, "subcomponents": {...}, "flags": [...] },
    "validity": { ... },
    "construct_alignment": { ... },
    "item_prompt_quality": { ... },
    "bias_clarity": { ... },
    "scale_structure": { ... },
    "factor_readiness": { ... },
    "response_scale_review": { ... }
  },
  "scales": [
    { "scale_id": "...", "alpha": ..., "omega": ..., "n_items": ..., "n_respondents": ..., "items": [...] }
  ],
  "items": [
    { "item_id": "...", "scale": "...", "diagnostics": {...}, "flags": [...] }
  ],
  "issues": [
    { "id": "...", "domain": "...", "severity": "high|medium|low", "description": "...", "estimated_lift": <number or null>, "fix_link": "..." }
  ],
  "reporting_readiness": { "score": <0-100>, "indicators": [...] },
  "warnings": [ ... ],
  "interpretation": { "summary": "<plain language>", "recommendations": [ ... ] },
  "rssi_weights_version": "v2.0",
  "computed_at": "<ISO timestamp>",
  "version_id": "v3"
}
```

---

## 14. Statistical methods

- Treat Likert items as ordinal by default. Use polychoric correlations for the ω factor model on fully ordinal scales. Allow a config override to force Pearson.
- For α: standard formula `α = (k / (k − 1)) * (1 − Σvar_i / var_total)`.
- Reverse-code items flagged in the schema before any reliability computation.
- For ω total: confirmatory single-factor model per scale. Fall back to EFA with one factor if CFA fails to converge; flag the fallback in the output.
- Bootstrap 95% confidence intervals for α and ω using 1000 resamples when N ≥ 100. Skip bootstrap and warn below that threshold.
- Library suggestions, by stack:
  - Python: `pingouin` (α), `factor_analyzer` (EFA, polychoric), `semopy` (CFA, ω), `numpy`, `pandas`.
  - R: `psych::alpha`, `psych::omega`, `lavaan` for CFA.
  - Node/JS: no mature psychometrics library. Propose a Python microservice or WASM port and wait for a decision before implementing native JS formulas.

---

## 15. Edge cases

- Scale with one item: α and ω undefined. Report null and warn "Single-item scale; reliability not estimable."
- Scale with two items: report Spearman-Brown corrected reliability alongside α; note the small-k caveat.
- Constant item (zero variance): exclude from reliability math, flag the item, continue.
- Sample size below 30 per scale: refuse to compute ω, compute α with strong warning, downgrade Reliability sub-score to at most 50% of full points.
- Sample size below 100: compute everything, suppress CIs, add "low N" warning.
- Missing data > 20% on any item: flag the item and the scale.
- Open-ended columns: never enter reliability math. Route only to Item / Prompt Quality.
- Mixed response formats within a single scale: refuse and surface a configuration error.
- No criterion data: Validity-Forward lens caps at the evidence available (see Section 3.6).
- Setup incomplete: hard gate. No scores compute or display until all three setup steps pass validation.

---

## 16. Tests

Use the repo's existing test runner. Cover:

1. The eight-domain taxonomy is used consistently in the nav, the Hub cards, the report grid, and the API response. No alternate labels appear anywhere.
2. A known-good synthetic dataset (α ≈ 0.85) returns α within ±0.02 and ω within ±0.03.
3. A scale containing one reverse-coded item produces correct α after the schema flag is honored.
4. A single-item scale returns nulls and the expected warning, not an exception.
5. A constant-item case excludes the item and continues.
6. Each of the three lens calculations on a fully populated synthetic case matches a hand-calculated value to the second decimal.
7. The disagreement readout fires when spread > 10 points and stays silent at spread ≤ 10 points.
8. The Validity-Forward cap engages when criterion data is absent and shows the "limited evidence" flag.
9. Setup wizard validation rejects: a survey with no Likert items, a scale with unassigned items, an item with negative item-rest after reverse-coding.
10. Auto-recompute triggers a new version on each edit, and the previous version remains immutable.
11. The interactive item analysis recomputes α live on toggle and shows correct "α if deleted" values.
12. The PDF export of the report renders without missing fields and matches the web view's content.

Commit at least three fixture datasets: a strong-survey case, a weak-survey case, and the sample-data-360-leadership dataset shown in v1 screenshots (for regression testing the bug found in Section 0).

---

## 17. What to do when blocked

Pause and ask before:

- Inventing sub-component math for any of the seven non-Reliability domains not already defined in v1 or this brief.
- Changing any weight, threshold, point band, or lens definition.
- Picking a missing-data policy default not already in the repo.
- Choosing between alternative ω definitions (total vs hierarchical vs categorical) when the scale's structure is ambiguous.
- Carrying forward any v1 label, score, or component that conflicts with the eight-domain taxonomy in Section 2.

When in doubt, surface the question rather than guess. v1 already shipped one mathematical inconsistency that this rebuild exists to fix; do not introduce another.

---

## 18. Deliverables

- Refactored analysis module producing the data contract in Section 13.
- Seven primary screens (Section 6) built or refactored to match this brief.
- The setup wizard (Section 8) with all three validation gates.
- The version system (Section 12).
- Test suite (Section 16) including three fixture datasets.
- A developer note at the top of the analysis module listing every formula, threshold, weight, and library version pinned.
- An exported `RSSI_WEIGHTS_VERSION` and `RSSI_SCHEMA_VERSION` so saved versions remain traceable to the exact scoring revision that produced them.
