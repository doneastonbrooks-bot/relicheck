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
