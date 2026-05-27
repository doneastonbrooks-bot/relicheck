# ReliCheck — Known Issues

Issues surfaced during build that span more than one conversation and need to outlive any single chat transcript. Add an entry here whenever a correctness finding, spec gap, or backwards-compat concern emerges that we are deferring rather than fixing immediately. Keep entries short; link out to the spec or code where appropriate.

---

## 1. §3.5 sentence 3 vs. §3.6 cap — weight-geometry mismatch

**Surfaced:** Phase 3 of the three-lens scoring build.

**Problem.** Spec §3.6 says "the disagreement readout absorbs the case where the cap drives Validity-Forward more than 10 points below the others." Working the math: the cap lowers the underlying Validity sub-score, which feeds Psychometric Core at weight 22, Respondent-Centered at weight 15, and Validity-Forward at weight 22. PC and VF weight Validity *equally*, so the cap drops them by equal absolute amounts; RC drops less. The cap therefore drives PC *and* VF together below RC, not VF differentially below both others. The §3.5 third diagnostic sentence ("Validity-Forward much lower than the other two") will not fire from the cap in isolation.

**Implication.** The third sentence currently fires only when additional downward pressure on VF-favored domains (Construct Alignment, Bias & Clarity) pushes VF below both other lenses by > 10 points. The cap-only scenario the spec describes resolves into a different pattern: "PC and VF both fall, RC holds."

**Resolve during.** Next weight-tuning conversation. Either:
1. Adjust weight vectors so VF weights Validity heavier than PC does (currently both 22), making the cap differentially affect VF, or
2. Revise sentence 3 copy to describe the cap-driven "PC and VF both fall, RC holds" pattern, or
3. Add a fourth sentence pattern for the parallel-drop case and route it from the cap engagement signal directly.

**Code touch-points.** [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) — `LENS_WEIGHTS`, `computeDisagreementReadout`, `DISAGREEMENT_SENTENCES`. Spec §3.5, §3.6.

---

## 2. v1 → v2 ω band cutover — saved reports will re-score lower

**Surfaced:** Phase 3 of the three-lens scoring build.

**Problem.** Spec §4.1 hardens the ω point bands relative to v1:
- ω in 0.60–0.699: v1 awarded 5/10, §4.1 awards 4/10.
- ω < 0.60: v1 awarded 2/10, §4.1 awards 0/10.
- α < 0.60: v1 awarded 2/8, §4.1 awards 0/8 (parallel α fix).
- α–ω agreement at gap ≤ 0.05: v1 conditioned the full 3 pts on both α ≥ 0.80 and ω ≥ 0.80; §4.1 removes that gate.

Any saved report from before the band fix will produce a lower headline score and lower lens scores when re-run, on the same data, if its α or ω fell in those bands. The harness's weak-fixture demonstrates the shift: reliability raw drops from ~7/25 (v1) to ~4/25 (§4.1) for an instrument with α=0.445 and ω=0.676.

**Implication.** This is a math correctness fix, not a regression. But "the same data produces a lower number now" is a user-visible change for any existing saved report. Whether and how to communicate this to users (banner on old reports, automatic recompute, version-pinning, side-by-side compare) is a product decision, not a math decision.

**Resolve during.** Next product conversation on versioning UX, paired with the §9 versioning storage work.

**Code touch-points.** [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) — `computeReliability()` ω band map and α–ω agreement logic. Spec §4.1.

---

## 3. `escapeHtml` implicit global dependency in `strength-index.js`

**Surfaced:** §6 engine consolidation work (prior to this build).

**Problem.** `apps/strength-index/strength-index.js` references a global `escapeHtml(s)` function nine times in `renderReliabilityDetail` but does not define it. The dependency is supplied today by an inline script block in the host PHP page (e.g., `rssi.php`). A page that includes `strength-index.js` purely for `window.RSSI_MATH` without also defining `escapeHtml` will throw when the reliability detail renders.

**Implication.** The engine is not yet fully self-contained, contrary to the §6 single-source-of-truth principle. The lens math + canonical primitives are safe (they never call `escapeHtml`); only the rich reliability-detail render fails.

**Resolve during.** Studio template migration conversation (when the 6-card render is replaced with the canonical 8-domain grid). Either inline a local `esc()` helper at the top of `strength-index.js` or guard the references.

**Code touch-points.** [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) — `renderReliabilityDetail()`. Spec §6 (already noted as a follow-up there).

---

## 4. §4E Scale Structure is dormant in production — four platform-side changes unlock it

**Surfaced:** §4E Scale Structure build.

**Problem.** §4E is fully implemented in [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) (`computeScaleStructure`) and exhaustively verified by [apps/strength-index/__harness/three-lens-verify.js](apps/strength-index/__harness/three-lens-verify.js) (54 assertions covering every sub-component, every band threshold, every skip path, and the rescale arithmetic). However, the domain returns `score: null` (whole-domain skip) on all current production data because the data the engine consumes — built by [api/surveys/_build_dataset.php:22](api/surveys/_build_dataset.php) — does not yet carry the four per-item / per-survey fields §4E needs. Until each of the four changes below lands, `domain_subscores.scale_structure` stays null in production, the lens math absorbs the skip via §3.2 skip-and-rescale, and §4E contributes nothing to the headline.

**The four changes that unlock §4E.** Each is a platform-side change with a specific code location:

1. **Propagate scale assignments into the dataset shape.** [api/surveys/_build_dataset.php:22–28](api/surveys/_build_dataset.php) emits Likert variables with `{ name, types, label, values }`. Add `scale` (or `construct`) per Likert variable, sourced from `column_meta.construct` on the [datasets table](db/schema_phase7.sql) (already populated by [api/datasets/update_columns.php](api/datasets/update_columns.php), see [api/datasets/create.php:4](api/datasets/create.php)). The engine accepts either `v.scale` or `v.construct` as the membership key — whichever fits the existing convention. Without this, the whole §4E domain skips and the other three changes below cannot be exercised.

2. **Propagate per-item reverse-coded flags.** Same file, [api/surveys/_build_dataset.php:22–28](api/surveys/_build_dataset.php). Add `reverse_coded: bool` per Likert variable, sourced from `column_meta.reverse` (already in the [datasets table](db/schema_phase7.sql) per [api/datasets/create.php:4](api/datasets/create.php)). Without this, every item is treated as not-reverse-coded for sub-component 2 scoring purposes.

3. **Setup Wizard captures `reverse_coded_confirmed` at the survey level.** When the user has reviewed reverse-coding flags and the answer is "none" (or "I've reviewed them all"), the Wizard sets a survey-level `reverse_coded_confirmed: true`, which the invoking surface passes into the engine via the configuration object (`config.reverse_coded_confirmed`). The wizard does not exist yet; design it to capture this distinction during the reverse-coding step. Without this flag, sub-component 2 skips and rescales (the honest default — the engine cannot distinguish "no reverse items" from "user hasn't reviewed yet" without an explicit signal). Spec §4E table row for "Reverse-coded balance" documents the contract.

4. **Propagate per-item Likert format metadata.** Same file again, [api/surveys/_build_dataset.php:22–28](api/surveys/_build_dataset.php). Add `likert_range: [min, max]` (e.g., `[1, 5]` or `[1, 7]`) and/or `anchor_count: int` per Likert variable. Source: the question definition in the Survey Builder (which already knows the anchor count). The schema convention is open — engine accepts either field. Without this, sub-component 3 (response-format uniformity) skips and rescales. **Note:** this same metadata also unlocks §4G sub-components 1 (anchor count), 2 (midpoint presence), and 4 (single-format-per-scale) — see §7.

5. **Propagate structured anchor labels (required only for full §4G anchor-symmetry evaluation).** Add `anchor_labels: [string, ...]` per Likert variable (e.g., `["Strongly Disagree", "Disagree", "Neutral", "Agree", "Strongly Agree"]`), sourced from the Survey Builder's question definition. Until this lands, §4G sub-component 3 (anchor symmetry) awards the spec-defined default of 2/2 per Phase 1 Q1 decision (the engine does not attempt heuristic symmetry detection from arbitrary label strings — see §8). When labels arrive, the engine can do real symmetry detection from structured inputs. Marked optional in the checklist: change 5 is a future-evaluation expansion, not a prerequisite for §4G to score.

6. **Capture the criterion column for §4A criterion validity.** The engine accepts `config.criterion_column` (the item ID / column name of a numeric outcome to correlate scale totals against). Whatever surface invokes the engine — Setup Wizard, evidence-intake config, or a dedicated criterion-mapping step — must populate this field for the §4A criterion sub-component to score. The engine handles the absence gracefully: the criterion sub-component skips, the §3.6 Validity-Forward cap engages, and the lens math absorbs the skip via per-subcomponent rescale. So this is purely a "to activate criterion validity, the platform must capture which column is the criterion" entry, not a correctness gate. When the configured column is missing from the dataset or non-numeric, the engine returns a structured error in `domain_details.validity.breakdown.criterion.error`, distinct from the skip-with-diagnostic path (too few paired observations). Marked optional in the checklist: §4A scores convergent + HTMT without criterion; change 6 unlocks the third sub-component and removes the V-F cap.

**Implication.** Until these four are wired, §4E is verified in the harness but unexercised in production. When the first of these changes lands, the engine will begin returning real §4E scores; that first run on real data should be verified end-to-end (sub-scores match hand-checked values on a known dataset). The combination of changes 1 + 4 unlocks the majority of the domain (sub-components 1, 3, 4, 5 score; sub-2 stays skipped); changes 2 + 3 then unlock sub-2.

**Resolve during.** Platform-side dataset-transform + Setup Wizard work, separate conversation from this module-side build.

**Code touch-points.** [api/surveys/_build_dataset.php:22](api/surveys/_build_dataset.php) (transform), Setup Wizard surface (not yet built), [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) `computeScaleStructure` (consumer). Spec §4E.

---

## 5. Numerical primitives duplicated between strength-index and instrument-quality engines

**Surfaced:** §4F Factor Readiness build.

**Problem.** `inverseAndDet`, `logGamma`, `regGammaP`, and `chiPValue` now exist in both [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) and [apps/instrument-quality/instrument-quality.js](apps/instrument-quality/instrument-quality.js). The strength-index copies were ported during the §4F upgrade so the canonical engine is self-contained (it needs Bartlett's χ² and a determinant-and-inverse-in-one-pass); the instrument-quality copies are the originals. The implementations are byte-equivalent (verified by the §4F sanity-check: hand-computed det = 0.68 and Bartlett χ² ≈ 37.4674 both match).

**Implication.** Two source-of-truth files for the same numerical routines. A bug fix in one would need to be propagated to the other; the harness's cross-port sanity-check would catch the drift but only at test time. Acceptable for now (both engines need these primitives and the duplication is contained to four small functions) but a candidate for consolidation when the codebase grows a shared math module.

**Resolve during.** Future shared-math consolidation pass. Candidate landing spot: a new `apps/_shared/math.js` (or similar) that both engines import. Until that lands, the harness's sanity-check pattern is the safety net — any port of numerical code between engines should include hand-computed reference-value checks of the kind the §4F harness uses.

**Code touch-points.** [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) (`inverseAndDet`, `logGamma`, `regGammaP`, `chiPValue`), [apps/instrument-quality/instrument-quality.js](apps/instrument-quality/instrument-quality.js) (originals).

---

## 6. §4F sub-score conveys factorability vs. scale-quality failure ambiguously

**Surfaced:** §4F Factor Readiness build, harness fixture 3.

**Problem.** Near-orthogonal items (e.g., equicorrelation r ≈ 0.02 across k items) produce a §4F raw of 3 / 15 → sub-score 20 / 100 (KMO 0/8 + Bartlett 0/4 + determinant 3/3, because the determinant only catches multicollinearity, not orthogonality). The sub-score is mathematically correct and feeds all three lenses correctly. But the *interpretive* meaning differs from a 20 produced by, say, a poor Cronbach's α in §4 Reliability: a 20 on §4F means the items do not share enough variance to be factor-analyzable at all (a *structural* finding about the data), not that the scale is performing poorly (a *quality* finding). A user looking at the headline sub-score cannot distinguish "your data is non-factorable" from "your data is factorable but performing poorly," and the spec's interpretation bands (Strong / Defensible / Needs Revision / Weak / High Risk) do not yet include factorability-specific language.

**Implication.** The diagnostic information is present in `domain_details.factor_readiness.breakdown` (which carries `kmo.pts`, `bartlett.pts`, `determinant.pts` separately) and in the `interp` string the module emits. Whether the report renders that breakdown prominently enough to make the factorability finding legible is a *platform-side* question — the module already exposes everything needed.

**Resolve during.** Report-rendering work on the platform side. The fix is platform-rendering, not module math: when `kmo.pts === 0 && bartlett.pts === 0`, surface a factorability-failure callout above the standard band interpretation. Optionally extend the §3 interpretation bands with factorability-specific copy when this pattern is detected.

**Code touch-points.** Module side (no fix needed): [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) `computeFactorStructure`. Platform side (eventual fix): report-rendering surfaces consuming `domain_details.factor_readiness.breakdown`. Spec §4F.

---

## 7. §4G Response Scale Review is partial-evaluation in production — lens scores will shift when platform-side metadata ships

**Surfaced:** §4G Response Scale Review build.

**Problem.** §4G is the first domain that produces a meaningful sub-score from raw data alone. Unlike §4E (which whole-domain-skips without scale assignments), §4G always scores at least 9/20 raw points from raw data (completion 3 + item missingness 3 + response-distribution shape 3) plus a spec-defined 2/2 default for anchor symmetry (see §8) — total 11/13 always-available pts (rescaled). The remaining 7 pts (anchor count 3 + midpoint presence 2 + single-format-per-scale 2) and the per-scale straight-lining sub-component (2 pts) require platform-side data that isn't wired yet:

- **Anchor metadata (`likert_range` or `anchor_count` per Likert variable)** unlocks anchor count, midpoint presence, and single-format-per-scale. Same data-contract requirement as §4 item 4 above; landing it unlocks §4E *and* the §4G Likert-design half together.
- **Scale assignments (`scale` / `construct` per Likert variable)** unlocks per-scale straight-lining. Same data-contract requirement as §4 item 1; landing it fixes v1's full-matrix straight-lining bug in production (the rebuild's whole purpose for this sub-component).

**Implication.** Production lens scores today reflect the partial-evaluation state: §4G sub-scores around 85 (raw ~11/13 → 85) for clean data, dropping for genuine completion/missingness/distribution problems. When platform-side metadata ships, the same data will produce higher (or sometimes lower) §4G sub-scores as the additional sub-components engage. On the harness's strong fixture, the swing from "no metadata" partial-eval to "full metadata" was +12 points on §4G sub-score, which translated to PC 93.44 → 98.24, RC 89.81 → 98.87, VF 91.91 → 98.72 (lens shifts of +4 to +9 points).

§4G is the third domain with platform-data-dependent shift potential (after §4E and the eventual §4A/§4B/§4D builds), and the magnitude on §4G is the largest yet because §4G ships *live* in production whereas §4E ships dormant.

**Resolve during.** Combined with §4 item 4 (anchor metadata propagation) and §4 item 1 (scale assignments) on the platform side. No engine-side fix needed — the math is correct now and will remain correct when more data arrives. The user-facing concern is the same as §2: re-running on identical data produces a different number. Whether and how to communicate this (banner on old reports, automatic recompute, version-pinning) is a product decision paired with the §9 versioning storage work.

**Code touch-points.** Module side (no fix needed): [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) `computeResponseScaleReview`. Platform side: [api/surveys/_build_dataset.php:22–28](api/surveys/_build_dataset.php) — items 1 + 4 in §4 above. Spec §4G.

---

## 8. §4G anchor symmetry awards default 2/2 until structured anchor labels ship

**Surfaced:** §4G Response Scale Review build, Phase 1 Q1.

**Problem.** Spec §4G sub-component 3 ("anchor symmetry") asks the engine to award full points when anchor endpoint labels are balanced (e.g., "Strongly Disagree" / "Strongly Agree") and 0 when they are unbalanced (e.g., "Disagree" / "Strongly Agree"). The spec explicitly defines a graceful default: "When anchor labels are absent: award 2 by default." The current data contract does not carry anchor labels at all, so the engine ships the default-2 behavior unconditionally.

Detecting symmetry from arbitrary label strings is a hard NLP problem and committing to a particular heuristic (mirror-length, common-negation pattern, synonym-pair detection, etc.) means committing to a particular failure mode. The Phase 1 decision was to *not* attempt heuristic detection now; instead, wait for the platform layer to provide structured anchor metadata (anchor type, polarity per anchor, midpoint flag) that the engine can act on cleanly.

**Implication.** Today every survey's §4G symmetry sub-component awards 2/2. A genuinely asymmetric anchor set is not flagged. The other 18 raw points of §4G are correctly computed; this is one sub-component of a partial-eval domain. The default is spec-sanctioned, not a bug.

**Resolve during.** Platform-side anchor-labels work (added as item 5 to the §4 checklist above). When structured anchor metadata is available, extend `computeResponseScaleReview` to inspect `anchor_labels`, classify polarity, and award 2 for balanced endpoint polarity, 0 otherwise. Until then, the default behavior is correct per spec.

**Code touch-points.** Module side (future): [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) `computeResponseScaleReview` — `subSymmetryPts` is currently a constant 2. Platform side: §4 item 5 (anchor-labels propagation). Spec §4G sub-component 3.

---

## 9. Corrected item-rest correlations feed both Reliability (§4) and Validity convergent (§4A) — cumulative-penalty effect

**Surfaced:** §4A Validity build, Phase 1 audit.

**Problem.** The corrected item-rest correlation now contributes to two domains:
- **§4 Reliability** uses item-rest as a sub-component of the 25-pt reliability score (3 pts available, with deductions for items with r < 0.30 and r < 0).
- **§4A Validity convergent (sub-component 1)** averages item-rest within each scale and bands the cross-scale mean to 20 pts.

A survey with weak items therefore loses points in *both* domains for the same underlying signal. This is intentional per spec — different lenses on the same statistic — but the cumulative effect on the headline lens score is real. A scale with mean item-rest = 0.25 loses ~1 pt from Reliability *and* lands in the 6/20 convergent band, dropping ~14 raw §4A points → ~23 sub-score points → ~3.5–5 weighted lens points depending on the lens.

**Implication.** The math is correct per spec §4 and §4A. The user-facing concern is whether the same diagnostic appearing in two domain narratives reads as "two distinct findings" or "the same finding double-counted." Whether this is a problem depends on:
1. How the report renders the two domains (if both surface the item-rest finding verbatim, users may interpret as duplication).
2. Whether lens-weighted lens scores shift more than the report-author intends when both domains penalize the same items.

**Resolve during.** Next weight-tuning conversation OR report-rendering work, whichever surfaces the issue first. Three response options when this becomes a user-facing concern:
1. **Accept as-is.** The cumulative penalty is the spec's intent — weak items should impact both reliability *and* validity conclusions.
2. **De-weight one domain.** Reduce the item-rest contribution in either §4 (3 pts of 25) or §4A (the convergent band) to soften double-counting.
3. **Reframe in the report.** Render the two domains with explicit cross-references so the user sees the connection rather than reading them as independent findings.

**Code touch-points.** [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) — `computeReliability` (item-rest deduction loop), `computeValidity` (convergent sub-component). Spec §4 row "Item-rest correlations," §4A row "Convergent validity."
