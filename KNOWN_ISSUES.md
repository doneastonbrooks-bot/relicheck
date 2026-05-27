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

4. **Propagate per-item Likert format metadata.** Same file again, [api/surveys/_build_dataset.php:22–28](api/surveys/_build_dataset.php). Add `likert_range: [min, max]` (e.g., `[1, 5]` or `[1, 7]`) and/or `anchor_count: int` per Likert variable. Source: the question definition in the Survey Builder (which already knows the anchor count). The schema convention is open — engine accepts either field. Without this, sub-component 3 (response-format uniformity) skips and rescales.

**Implication.** Until these four are wired, §4E is verified in the harness but unexercised in production. When the first of these changes lands, the engine will begin returning real §4E scores; that first run on real data should be verified end-to-end (sub-scores match hand-checked values on a known dataset). The combination of changes 1 + 4 unlocks the majority of the domain (sub-components 1, 3, 4, 5 score; sub-2 stays skipped); changes 2 + 3 then unlock sub-2.

**Resolve during.** Platform-side dataset-transform + Setup Wizard work, separate conversation from this module-side build.

**Code touch-points.** [api/surveys/_build_dataset.php:22](api/surveys/_build_dataset.php) (transform), Setup Wizard surface (not yet built), [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) `computeScaleStructure` (consumer). Spec §4E.
