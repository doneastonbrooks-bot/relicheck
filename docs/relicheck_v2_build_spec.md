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

Open-ended items are scored under Item / Prompt Quality, not as a separate domain. Actionability is removed from the RSSI entirely. Reporting Readiness is a separate report-side panel, owned and rendered by the invoking surface (not the module); the module emits the indicator data per Section 8.

**Canonical-formula note for cutover.** The comment at the top of `strength-index.php` reads "the 6-domain weighted composite is canonical and must NOT be altered." That comment is deliberately superseded by v2's eight-domain three-lens composite (Sections 3.2 and 3.3) and must be updated during the cutover. Any code or doc that asserts a 6-domain canonical formula is referring to v1 and is out of date.

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

When criterion evidence is absent from scoring — either because no `criterion_column` is configured (per Section 10) **or** because the configured column was skipped (missing from the dataset, non-numeric, or fewer than 10 paired complete observations per Section 4A):

- The criterion sub-component is skipped, not zero-filled. The raw total becomes a 40-point scale (convergent + discriminant) rescaling to a 0–100 sub-score by multiplying by 100/40 = 2.5, with a hard cap of 60 applied so the absence of criterion evidence cannot produce an indistinguishable headline from a fully-evidenced instrument.
- The cap engages on the authoritative skip signal — whether the criterion sub-component actually scored — not on config presence alone. A configured-but-skipped criterion triggers the cap exactly like an unconfigured one.
- The cap is surfaced on the Validity-Forward lens score with a "limited evidence" indicator.
- The Validity-Forward score is never silently lowered below the other two lenses without an explanation; the disagreement readout (Section 3.5) absorbs the case where the cap drives Validity-Forward more than 10 points below the others.

**Known issue — sentence 3 won't reliably fire from the cap alone under the current weight vectors.** Working the geometry: the cap lowers the underlying Validity sub-score, which feeds Psychometric Core at weight 22, Respondent-Centered at weight 15, and Validity-Forward at weight 22. Because PC and VF weight Validity *equally*, the cap drops them by equal absolute amounts; it drops RC less. So the cap drives PC and VF *together* below RC, not VF differentially below both others. The §3.5 third diagnostic sentence ("Validity-Forward much lower than the other two") therefore won't fire from the cap in isolation — it requires additional downward pressure on VF-favored domains (Construct Alignment, Bias & Clarity) to push VF below both others by > 10 points. Revisit during the next weight-tuning conversation: either adjust the weight vectors so VF differentiates from PC on Validity weight, or revise sentence 3 to describe the cap-driven "PC and VF both fall, RC holds" pattern. Captured in [KNOWN_ISSUES.md](../KNOWN_ISSUES.md).

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
| Criterion validity | 20 | When `criterion_column` is configured, correlate each scale's total score with the criterion on pairwise-complete rows. Map the maximum absolute correlation across scales to points: ≥ 0.50 → 20, 0.30–0.499 → 14, 0.20–0.299 → 8, < 0.20 → 0. **Minimum-N floor:** require ≥ 10 paired complete observations per scale to compute at all; below that, skip with a "too few paired observations" diagnostic. **Low-N warning:** when 10 ≤ N < 30, score the band but surface a low-N warning. When `criterion_column` is absent (unconfigured, missing from dataset, or non-numeric), skip this sub-component and apply the cap in Section 3.6. A missing or non-numeric configured column returns a structured error (configuration wrong) rather than a skip-with-diagnostic (data fine but insufficient). |

Raw total: 60 with criterion, 40 without. Rescale to 0–100 per Section 3.6. The cap engages whenever the criterion sub-component is skipped (configured-but-skipped triggers the cap exactly like unconfigured), irrespective of how high convergent and discriminant score.

**HTMT formula.** Discriminant validity uses the Heterotrait-Monotrait ratio per Henseler, Ringle & Sarstedt (2015), "A new criterion for assessing discriminant validity in variance-based structural equation modeling," *Journal of the Academy of Marketing Science* 43(1):115–135. For scales *i* and *j*, HTMT = MeanHetero(i,j) / sqrt(MeanMono(i) × MeanMono(j)) where the means are computed on the absolute values of the item-level correlations per Henseler 2015 §3.2 (sign-invariant convention). The 0.85 conservative cutoff and 0.90 liberal cutoff define the band boundaries above.

**Per-subcomponent skip-and-rescale.** Single-scale surveys skip the HTMT sub-component (no pairs to evaluate) but still score convergent + criterion when available. Scales with fewer than 2 items skip from the convergent computation. The engine sums only the present sub-components and rescales: `score = round(raw_present / max_present × 100)`. When per-item scale assignments are absent on any Likert item, the whole domain skips (`validity: null`) and the §3.2 lens math absorbs the skip.

---

## 4B. Construct Alignment domain sub-scoring

The Construct Alignment domain produces a 0–100 sub-score. Unit of analysis is the per-scale confirmatory factor model declared in the input schema (Section 10) by the invoking surface. Total 25 raw points; rescale ×4.

| Subcomponent | Points (of 25) | How to compute |
|---|---|---|
| Primary loading strength | 10 | Per scale, fit a single-factor CFA. Average the standardized loadings. Map cross-scale mean: ≥ 0.70 → 10, 0.60–0.699 → 7, 0.50–0.599 → 4, < 0.50 → 0. |
| Weak-loading penalty | 5 | Start at 5. Deduct 1 pt per item with standardized loading < 0.40, clamped to [0, 5]. |
| Cross-loadings | 5 | Fit an exploratory model with k = number of declared scales factors and oblique rotation. Flag any item whose secondary loading is within 0.20 of its primary and both ≥ 0.30. Start at 5, deduct 1 pt per cross-loaded item, clamped to [0, 5]. |
| Model fit | 5 | Use the per-scale CFA fit indices. Award 5 pts when every scale's CFI ≥ 0.95 and RMSEA ≤ 0.06. Award 3 pts when every scale's CFI ≥ 0.90 and RMSEA ≤ 0.08. Award 0 otherwise. Single-item and two-item scales are excluded from this check (fit indices are not defined). |

When CFA fails to converge for a scale, fall back to EFA with one factor and surface a warning flag, per Section 11.

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
| Reverse-coded balance | 3 | For each scale with k ≥ 4: 3 pts when at least one reverse-coded item is present (per Setup Wizard step 3 flags), else 0. For scales with k < 4, the check does not apply and the 3 pts are awarded by default. Average across scales. **Tri-state contract:** the engine reads a survey-level `reverse_coded_confirmed: true` flag from the configuration object. When the flag is set, the rule above applies and a "no flagged items in any k ≥ 4 scale" instrument scores 0 on this sub-component — the architectural finding the spec describes. When the flag is absent or false, this sub-component skips and the remaining 12 raw points rescale to 15. The flag distinguishes "user has reviewed reverse-coding and the answer is none" from "user hasn't completed reverse-coding flagging yet," which the engine cannot infer from item flags alone. The platform's Setup Wizard owns capturing this flag. |
| Response-format uniformity | 3 | For each scale: 3 pts when all items share the same response format (same Likert range, same anchor count), else 0. Per Section 12, mixed formats within a scale already surface as a configuration error; this sub-component awards the corresponding 3 pts as a domain-level signal. Average across scales. |
| Scale-level missingness pattern | 2 | For each scale: 2 pts when no respondent has all items missing within the scale; 1 pt when 1–5% do; 0 pts when > 5% do. Average across scales. |
| Survey-level item-count health | 2 | 2 pts when 5 ≤ total Likert items ≤ 30; 1 pt when total is 4 or 31–40; 0 pts when total < 4 or > 40. (This replaces v1's k<5 / k>30 logic from Actionability.) |

**Skip-and-rescale.** When a sub-component is skipped (per the reverse-coded tri-state above, or because per-item format metadata is absent for sub-component 3), the engine sums only the present sub-components and rescales: `score = round(raw_present / max_present × 100)`. When per-item scale assignments are absent on any Likert item, the whole domain skips (`scale_structure: null`) and the §3.2 lens math absorbs the skip.

**Dormancy in production.** §4E lands implemented and harness-verified but **dormant in production** until the platform-side data contract carries (a) per-item `scale` / `construct` membership, (b) per-item `reverse_coded` flags, (c) per-item `likert_range` or `anchor_count`, and (d) survey-level `reverse_coded_confirmed`. See [KNOWN_ISSUES.md](../KNOWN_ISSUES.md) §4 for the actionable platform-side changes that unlock the domain. The first run of §4E against real production inputs will be the first time the math touches non-synthetic data and deserves verification at that point.

---

## 4F. Factor Readiness domain sub-scoring

The Factor Readiness domain produces a 0–100 sub-score. Total 15 raw points; rescale ×(100/15). All three sub-components operate on the full pooled Likert matrix (not per-scale) because they answer "is the data factorable at all?"

| Subcomponent | Points (of 15) | How to compute |
|---|---|---|
| Kaiser-Meyer-Olkin (KMO) | 8 | KMO ≥ 0.80 → 8; 0.70–0.799 → 6; 0.60–0.699 → 3; < 0.60 → 0. |
| Bartlett's test of sphericity | 4 | p < 0.001 → 4; p < 0.01 → 3; p < 0.05 → 1; p ≥ 0.05 → 0. |
| Correlation-matrix determinant | 3 | det(R) ≥ 0.00001 → 3 (matrix is well-conditioned); 1e-7 ≤ det(R) < 1e-5 → 1; det(R) < 1e-7 → 0 (near-singular, multicollinearity risk). |

This domain answers whether the data *supports* factor analysis. Whether the user's *declared* factor structure holds is the Construct Alignment domain's job (Section 4B).

**Singular correlation matrix.** When the correlation matrix `R` is singular (inverse fails), KMO is undefined. Treat KMO as 0 pts in this case; the determinant will also be ≈ 0 and Bartlett's χ² cannot be computed from `ln(det)`, so both default to 0 pts as well. The domain returns 0 / 15 with a "correlation matrix is singular" diagnostic — the correct signal: data this collinear cannot be factor-analyzed.

**Skip path.** When fewer than 3 complete rows are available across the pooled Likert matrix, or when there are fewer than 2 Likert items, the whole domain skips (`factor_readiness: null`) and §3.2 skip-and-rescale absorbs the absence. No §4F-specific minimum-N applies above that floor; the three sub-components have different N-sensitivity profiles and a single threshold would be wrong for at least one.

---

## 4G. Response Scale Review domain sub-scoring

The Response Scale Review domain produces a 0–100 sub-score. Two halves: Likert design (12 pts) and respondent behavior (8 pts). Total 20 raw points; rescale ×5.

### Likert design (12 pts)

| Subcomponent | Points | How to compute |
|---|---|---|
| Anchor count | 3 | 5- or 7-point scale → 3; 4 or 6 → 2; 3 or 10+ → 1; 2 → 0. Per scale, averaged. |
| Midpoint presence | 2 | Odd number of anchors (allows neutral midpoint) → 2; even → 1. Per scale, averaged. |
| Anchor symmetry | 2 | When anchor labels are configured: balanced positive/negative endpoints → 2; unbalanced → 0. When anchor labels are absent: award 2 by default. Per scale, averaged. |
| Single-format-per-scale | 2 | All items in a scale share the same response range → 2; otherwise → 0. (Mixed formats already surface as a configuration error per Section 12; this sub-component records the result at the domain level.) Per scale, averaged. |
| Response-distribution shape | 3 | For each Likert item, count the proportion picking the modal anchor. Flag items where the modal proportion ≥ 0.60. Start at 3, deduct 0.5 pt per flagged item, clamped to [0, 3]. |

### Respondent behavior (8 pts)

| Subcomponent | Points | How to compute |
|---|---|---|
| Completion rate | 3 | ≥ 95% complete responses → 3; 85–94.9% → 2; 70–84.9% → 1; < 70% → 0. |
| Item missingness rate | 3 | ≤ 5% overall missing cells → 3; 5–10% → 2; 10–20% → 1; > 20% → 0. |
| Straight-lining rate | 2 | Computed *per scale*, not across the full Likert matrix. Average per-scale straight-line rate ≤ 2% → 2; 2–5% → 1; > 5% → 0. (v1 computed this across all items, which false-positives on single-scale surveys; v2 fixes this.) |

Sample size does **not** enter this score. It is surfaced as a warning per Section 12 ("low N") but does not deduct points.

---

## 5. Module Boundary

The RSSI module is a **pure function** from a prepared dataset and configuration to a structured result object. Everything else is the invoking surface's job.

### 5.1 Inputs the module receives

The module reads — and only reads — what the data contract in Section 10 specifies:

- A **response matrix** (rows × item IDs).
- An **item schema** with per-item metadata: item ID, scale or construct membership, response format, Likert range, reverse-coded flag, and the `is_demographic` flag when applicable.
- A **configuration object**: missing-data policy, minimum-N threshold, chosen lens, optional `criterion_column`, optional `demographic_columns`.

If any required field is absent or malformed, the module fails fast with a structured error. It does not prompt the user, it does not guess, it does not fall back to "all Likert items as one scale" (the v1 bug).

### 5.2 Outputs the module returns

Exactly the structured object in Section 10 (Output). Every field needed to render a Hub, render a Domain detail page, generate a Report, store a version, or compare two versions is present in that object. Rendering, persistence, comparison UI, and navigation are all surface concerns that read from this object.

### 5.3 What the module explicitly does not do

The following are platform / invoking-surface concerns and are out of scope for this spec:

- **Authentication and project ownership.** Handled by the surface that invokes the module (`rssi-upload.php`, `strength-index.php`, etc.).
- **File ingest and parsing.** CSV / XLSX parsing, column-type auto-detection, encoding decisions. The module receives a prepared dataset, not a file.
- **Setup Wizard surfaces.** The wizard that walks a user through confirming item structure, grouping items into scales, and flagging reverse-coded items is the platform's responsibility. Its *output* — a populated item schema — is the module's input.
- **Hub layout, navigation, screen chrome, settings panels.** The module emits the data; the surface renders it however its product design calls for.
- **Versioning storage, comparison UI, "scores updated" notifications, auto-recompute triggers on schema edits.** The module emits a complete versionable object (Section 9); persistence and version-diffing are platform concerns.
- **Report page rendering, PDF export, copy-to-clipboard affordances.** The module emits the data (Section 8); the surface renders the report.
- **Interactive item-toggle UI.** The module exposes a recompute interface (Section 7) that takes a subset of active items and returns updated reliability statistics; the toggling UI is surface-rendered.

When in doubt: if it can be answered by reading or writing the Section 10 contract, it is module work. If it requires DOM, layout, navigation, or persistence decisions, it is platform work.

---

## 6. Engine Consolidation

**`apps/strength-index/strength-index.js` is the single source of truth for psychometric computation across the entire ReliCheck product.** It exposes `window.RSSI_MATH` — the canonical implementations of Cronbach's α, item-rest correlation, item-total correlation, average inter-item r, α-if-deleted, and the reliability and item-quality narratives — and contains all sub-scoring described in Sections 4 and 4A–4G.

Both current surfaces consume from this engine:

1. **The in-studio Strength Index mount** (`apps/strength-index/render.php` via `_studio_mount.php` and the `api/surveys/_build_dataset.php` transform).
2. **The standalone RSSI analyzer** (`rssi-upload.php`, with `apps/rssi/rssi-reliability.js` and `apps/rssi/rssi-analyses.js` as thin renderer layers that delegate every calculation to `window.RSSI_MATH`).

**Any future surface that performs these calculations must also consume from `window.RSSI_MATH`, not implement its own copy.** Two surfaces producing different numbers for the same data is a product bug. This is the single source of truth rule for psychometrics in this codebase.

Operational notes:

- The math is exposed unconditionally on script load. The dataset-presence gate in `strength-index.js` runs after the exposure, so the file behaves correctly when included purely for the math by a page that does not render the strength-index UI.
- Consumers must load `strength-index.js` with `defer` before their own scripts so the global is populated by the time they run.
- The standalone surface's client-side parse loop (`apps/rssi/rssi-upload.js`) handles CSV/XLSX → dataset shape only; the scoring half is gone.
- `rssi-reliability.js`'s interactive-toggle UI survives as the renderer for the engine's recompute interface (Section 7), not as a parallel math implementation.

The eight Instrument Quality mount files at the repo root (`reliability.php`, `validity.php`, `construct-alignment.php`, `item-quality.php`, `bias-clarity.php`, `scale-structure.php`, `factor-readiness.php`, `response-scale.php`) are production surfaces for the broader Instrument Quality studio. They are **out of scope** for this spec and are not edited by v2 cutover work.

**Known follow-up — implicit `escapeHtml` dependency.** `strength-index.js` references a global `escapeHtml(s)` function nine times (in `renderReliabilityDetail`) but does not define it. The dependency is supplied today by an inline script block in the host PHP page (e.g., `rssi.php` defines one). The engine should be made self-contained — either by inlining a local `esc()` helper or by guarding the references — so that any page including `strength-index.js` for the math gets a fully working file regardless of host-page conventions. Deferred from the retirement work; surfaced here so it isn't forgotten.

---

## 7. Reliability domain computation (interactive toggle interface)

The Reliability domain carries the v1 product's defining feature: the user can toggle individual items in or out of a scale and watch reliability statistics recompute. The interactive *UI* is rendered by the invoking surface; the *computation* is the module's job.

### 7.1 Standard run

Per the inputs in Section 5.1, the module computes Reliability sub-scoring (Section 4) once per analysis run. Every scale yields:

- Cronbach's α, with 95% bootstrap CI when N ≥ 100.
- McDonald's ω total, with 95% bootstrap CI when N ≥ 100.
- α–ω agreement gap.
- Corrected item-rest correlation per item.
- α-if-deleted per item (the α the scale would have if that item were removed).
- ω-if-deleted per item, computed on demand (see 7.3).
- Inter-item correlation matrix (for the redundancy check).
- Per-item: mean, SD, missing rate, flag tone, plain-language interpretation.

These are emitted in the scales and items arrays of Section 10's output.

### 7.2 Recompute interface

The module exposes a `reliability.recompute(scale_id, active_item_ids[])` callable that:

- Takes a scale and a subset of item IDs marked active by the user (the unchecked items are excluded from this recompute, *not* from the underlying analysis version).
- Returns the same fields as 7.1 for the reduced scale: α, ω (computed when feasible), α-if-deleted for each remaining item, lift relative to the original scale's α.
- Completes in under 100 ms for k ≤ 50 and N ≤ 5,000 (α only); ω may take longer and the module exposes a separate `reliability.recompute_omega(...)` that the surface can call on demand.
- Does not mutate the underlying analysis version. The toggle state is exploratory. Only an explicit "lock in" action by the invoking surface promotes the toggle state to a new version (Section 9).

### 7.3 What the module does *not* do here

- It does not render the toggle table, the α-if-deleted highlight, the "vs. original +0.000" comparison readout, the scale selector dropdown, or the Reset / Download buttons.
- It does not store toggle state between calls. The invoking surface owns toggle state.
- It does not decide when ω is "too slow to compute live." The module exposes both the live recompute (α) and the on-demand recompute (ω); the surface chooses when to call which.

---

## 8. Output emission for the report

The module does not own a Report page. The standalone surface's `rssi-report.php` and the in-studio mount's render layer consume the Section 10 output and assemble the rendered report. The module's responsibility is to emit every datum the surface needs.

### 8.1 Data the module emits for the report

Already specified in Section 10's output schema; calling out the report-bound subset for clarity:

- `rssi.psychometric_core`, `rssi.respondent_centered`, `rssi.validity_forward` — the three lens scores.
- `rssi.headline_lens` — the lens the surface should render largest.
- `rssi.disagreement_readout` — the one-sentence interpretation when spread > 10 points, else null.
- `rssi.validity_forward_capped` — boolean, true when the criterion sub-component was skipped (Section 3.6).
- `domains.*` — all eight domain sub-scores with sub-component breakdowns and flags.
- `issues[]` — severity-ordered list of fixable issues with computed (never guessed) `estimated_lift`.
- `reporting_readiness` — the five-indicator panel data (sample size, missingness, three-lens completeness, provenance, methods paragraph status), each as a status pill value. The module computes the indicator states; the surface renders the pills and the "Report ready" / "Report not ready" headline.
- `interpretation.summary` and `interpretation.recommendations[]` — plain-language strings.
- `methods_paragraph` — the auto-generated paragraph (sample size, scale counts, α, ω, fit indices) the user can copy directly into a research paper. The module composes this string; the surface offers the copy-to-clipboard affordance.
- `rssi_weights_version`, `computed_at`, `version_id`, `analyst_id` — provenance fields the surface stamps onto the rendered report.

### 8.2 What the module does *not* do here

- It does not produce HTML, PDF, or any rendered form. It emits structured data only.
- It does not own export formats, page layout, color frames, or the "Print / Save PDF" affordance.
- It does not decide which lens to render as the headline beyond emitting `rssi.headline_lens` as the recommendation; the surface may override based on user preference.

---

## 9. Versioning emission

Every analysis run is versionable. The module emits a complete object suitable for the invoking platform to store as an immutable version row. Storage, version IDs, comparison UI, version selection, "scores updated" notifications, and auto-recompute triggers are all platform concerns.

### 9.1 Fields the module emits for versioning

- `input_data_hash` — a stable hash of the response matrix + item schema + configuration object. Two identical inputs hash identically; one changed reverse-coded flag changes the hash.
- `rssi_weights_version` — the canonical weights-config version (e.g., `"v2.0"`).
- `rssi_schema_version` — the canonical output-schema version. Distinct from weights so a weights-only revision and a schema-only revision are independently traceable.
- `computed_at` — ISO timestamp.
- `analyst_id` — passed through from the configuration object.
- Every score and flag in Section 10's output is by construction part of the version.

### 9.2 What the module does *not* do here

- It does not assign `version_id`. The platform assigns sequential IDs per survey (`v1`, `v2`, ...) at storage time.
- It does not store, compare, or diff versions. The platform owns persistence and the version-comparison surface.
- It does not detect "the schema changed, rerun now." The platform observes schema edits and invokes the module fresh; the module is stateless across calls.
- It does not surface "scores updated" notifications. The platform decides what to notify and when.

---

## 10. Data contracts

### Input

The analysis module accepts:

- A response matrix: rows are respondents, columns are item IDs.
- An item schema: per-item metadata including item ID, scale or construct membership, response format (Likert, binary, continuous, open-ended), Likert range, and a reverse-coded flag.
- A configuration object: missing-data policy (`listwise`, `pairwise`, `mean_impute`, `mice`), minimum N warning threshold (default 100), language for the user-facing report, the chosen lens, optional `criterion_column` (the item ID of a column to treat as a criterion variable for criterion validity, per Section 4A; when omitted, the Validity criterion sub-component is skipped and the Validity-Forward cap engages per Section 3.6), optional `demographic_columns` (list of item IDs to use for the DIF proxy in Section 4D; when empty, the Bias & Clarity fairness sub-component is skipped), and `analyst_id` (passed through to the output's provenance fields, per Section 9). `criterion_column`, `demographic_columns`, and the per-item reverse-coded flags are populated by the invoking surface (typically through a Setup Wizard that the platform owns); the module reads them from the schema and does not collect them itself.

**Note on `datasets.column_meta.reverse`.** The platform's existing `datasets` table already has a `reverse?` field on `column_meta` (see [db/schema_phase7.sql](../db/schema_phase7.sql)). The module's item schema is the canonical place reverse-coding flags are *read from*; whatever surface populates the schema (Setup Wizard, evidence-intake config, or another mechanism) is free to source those flags from `column_meta.reverse` when present. The module does not require flags to come from a specific column; it requires only that the schema it receives is complete and correct.

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

## 11. Statistical methods

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

## 12. Edge cases

- Scale with one item: α and ω undefined. Report null and warn "Single-item scale; reliability not estimable."
- Scale with two items: report Spearman-Brown corrected reliability alongside α; note the small-k caveat.
- Constant item (zero variance): exclude from reliability math, flag the item, continue.
- Sample size below 30 per scale: refuse to compute ω, compute α with strong warning, downgrade Reliability sub-score to at most 50% of full points.
- Sample size below 100: compute everything, suppress CIs, add "low N" warning.
- Missing data > 20% on any item: flag the item and the scale.
- Open-ended columns: never enter reliability math. Route only to Item / Prompt Quality.
- Mixed response formats within a single scale: refuse and surface a configuration error.
- No criterion data: Validity-Forward lens caps at the evidence available (see Section 3.6).

---

## 13. Tests

Use the repo's existing test runner. Cover:

1. The eight-domain taxonomy is used consistently in the module's output object keys (`domains.reliability`, `domains.validity`, …) and any internal labels emitted in `issues[].domain`, `interpretation`, and `methods_paragraph`. No alternate labels appear anywhere in the module's output.
2. A known-good synthetic dataset (α ≈ 0.85) returns α within ±0.02 and ω within ±0.03.
3. A scale containing one reverse-coded item produces correct α after the schema flag is honored.
4. A single-item scale returns nulls and the expected warning, not an exception.
5. A constant-item case excludes the item and continues.
6. Each of the three lens calculations on a fully populated synthetic case matches a hand-calculated value to the second decimal.
7. The disagreement readout fires when spread > 10 points and stays silent at spread ≤ 10 points.
8. The Validity-Forward cap engages when criterion data is absent and shows the "limited evidence" flag.
9. Input-schema validation: the module rejects with a structured error when (a) the item schema is missing a required `scale_id` on any Likert item, (b) a Likert item is missing its `reverse_coded` flag, or (c) the configuration object references a `criterion_column` or `demographic_columns` id that does not exist in the schema. Validation runs before any scoring begins.
10. Determinism: identical inputs (same response matrix, same item schema, same configuration object, same `input_data_hash`) produce identical outputs across runs. Random-seeded operations (bootstrap CIs) accept a seed in the configuration object and produce identical resamples for the same seed.
11. The Reliability recompute interface (Section 7.2) returns correct α-if-deleted values and matches a hand-computed result when an item is toggled out and the scale is recomputed.

Commit at least three fixture datasets: a strong-survey case, a weak-survey case, and the sample-data-360-leadership dataset shown in v1 screenshots (for regression testing the bug found in Section 0).

---

## 14. What to do when blocked

Pause and ask before:

- Inventing sub-component math for any of the seven non-Reliability domains not already defined in v1 or this brief.
- Changing any weight, threshold, point band, or lens definition.
- Picking a missing-data policy default not already in the repo.
- Choosing between alternative ω definitions (total vs hierarchical vs categorical) when the scale's structure is ambiguous.
- Carrying forward any v1 label, score, or component that conflicts with the eight-domain taxonomy in Section 2.

When in doubt, surface the question rather than guess. v1 already shipped one mathematical inconsistency that this rebuild exists to fix; do not introduce another.

---

## 15. Deliverables

Scope: this list is the **module's** deliverables. UI screens, the Setup Wizard, the Hub, version-storage tables, and the report-rendering layer are platform deliverables and are tracked elsewhere.

- Refactored analysis engine in `apps/strength-index/strength-index.js` (per Section 6) producing the data contract in Section 10.
- All eight domain sub-scoring implementations (Sections 4 and 4A–4G), pinned to the canonical weight tables and point bands.
- The three-lens RSSI computation (Section 3.3) and disagreement readout lookup (Section 3.5).
- The Reliability recompute interface (Section 7) usable by an invoking surface to drive interactive item-toggle UI.
- The output emission contract for the report and for versioning (Sections 8 and 9).
- Retirement or absorption of `apps/rssi/rssi-reliability.js` and `apps/rssi/rssi-analyses.js` into the canonical engine per Section 6, with both consuming surfaces (the standalone RSSI app and the in-studio `strength-index.php` mount) wired to the single engine.
- Update of the `strength-index.php` "6-domain canonical" comment per Section 2.
- Test suite (Section 13) including the three fixture datasets: a strong-survey case, a weak-survey case, and the sample-data-360-leadership regression fixture.
- A developer note at the top of the engine module listing every formula, threshold, weight, and library version pinned.
- Exported `RSSI_WEIGHTS_VERSION` and `RSSI_SCHEMA_VERSION` so saved versions remain traceable to the exact scoring revision that produced them.
