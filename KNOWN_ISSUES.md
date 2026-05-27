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

## 4. Platform-side data-contract checklist — seven changes unlock the full canonical engine

**Surfaced:** §4E Scale Structure build; grown to seven items across §4E/§4G/§4A/§4D builds.

**Problem.** Each domain build in the canonical engine has surfaced a piece of dataset-shape or configuration data the engine needs but the platform does not yet supply. The engine handles every missing piece gracefully (skip-and-rescale, V-F cap, sub-component skip with diagnostic), so the lens math is correct today; but several domains and sub-components are *dormant* or *partial-evaluation* in production until the platform-side data contract is extended. This entry consolidates the seven changes a platform engineer needs to land to unlock every domain end-to-end.

**Where the data comes from.** Per-Likert-item changes live in [api/surveys/_build_dataset.php](api/surveys/_build_dataset.php) for the Survey Builder path; the uploaded-datasets and wizards-driven paths each have their own transform (see #1b/#1c). Survey-level config flags flow through the invoking surface's configuration object. Two changes require new platform-layer UI (Setup Wizard for `reverse_coded_confirmed`; a demographic-tagging step).

**The seven changes — dependency map.**

| # | Change | Type | Unlocks |
|---|---|---|---|
| 1a | `scale` (or `construct`) per Likert variable — **Survey Builder path** via [api/surveys/_build_dataset.php](api/surveys/_build_dataset.php) | per-item dataset field | **DONE 2026-05-27.** §4A convergent + HTMT, §4B whole-domain, §4E partial all now activate for surveys built through the Survey Builder when `q.construct` is populated. §4A criterion still skips pending #6; §4E sub-2 still skips pending #2+#3; §4E sub-3 still skips pending #4 |
| 1b | `scale` (or `construct`) per Likert variable — **uploaded-datasets path** via [api/datasets/*](api/datasets/) | per-item dataset field | Same three-domain activation, but for users who upload XLSX/CSV via the datasets surface rather than the Survey Builder. `column_meta.construct` is already captured by [api/datasets/update_columns.php:55](api/datasets/update_columns.php:55); a separate transform is needed to read it through into the engine's `variables[]` shape. |
| 1c | `scale` (or `construct`) per Likert variable — **wizards-driven settings path** via [survey-wizard.php](survey-wizard.php) / [strength-survey-wizard.php](strength-survey-wizard.php) | per-item dataset field | Third source-of-truth: the wizards write `settings.scales` (project-level, keyed by variable name) but no transform consumes it. A separate transform is needed to map this settings map onto the dataset variables before they reach the engine. |
| 2 | `reverse_coded: bool` per Likert variable | per-item dataset field | §4E sub-2 (reverse-coded balance) reading |
| 3 | `reverse_coded_confirmed: bool` survey-level flag | config object | §4E sub-2 scoring (tri-state contract — needs Setup Wizard step) |
| 4 | `likert_range: [min, max]` or `anchor_count: int` per Likert variable | per-item dataset field | §4E sub-3 (format uniformity); §4G subs 1/2/4 (anchor count / midpoint / single-format); §4D fairness threshold normalization |
| 5 | `anchor_labels: [string, …]` per Likert variable (optional) | per-item dataset field | §4G sub-3 (anchor symmetry beyond default 2/2 — see §8) |
| 6 | `config.criterion_column` (column name) | config object | §4A criterion sub-component; removes §3.6 V-F cap |
| 7 | `is_demographic: true` per categorical variable **OR** `config.demographic_columns: [name, …]` | per-item flag or config list | §4D fairness half (DIF proxy) |

**Per-domain status with current data contract (as of 2026-05-27, after #1a landed).**

- **§4E Scale Structure**: **Survey Builder path ACTIVE** for surveys with constructs assigned (item-count, missingness, survey health score). Other two surfaces still whole-domain-skip pending #1b/#1c. #4 unlocks sub-3 across all surfaces. #2 + #3 together unlock sub-2 across all surfaces.
- **§4G Response Scale Review**: partial-evaluation. Survey Builder path now gets per-scale straight-lining (the #1a unlock). #4 unlocks anchor count / midpoint / single-format across surfaces. #5 unlocks real anchor-symmetry (currently default 2/2 per §8).
- **§4A Validity**: **Survey Builder path ACTIVE** (convergent + HTMT score). Other two surfaces still skip pending #1b/#1c. #6 unlocks criterion and disengages the §3.6 V-F cap.
- **§4B Construct Alignment**: **Survey Builder path ACTIVE** (per-scale PAF loadings + cross-loadings score). Other two surfaces still skip pending #1b/#1c.
- **§4D Bias & Clarity**: partial-evaluation today. Wording-half scores whenever item text is propagated via `v.label` (already populated by [_build_dataset.php](api/surveys/_build_dataset.php) in production — no change needed for that half). Fairness-half requires #7. Threshold normalization on the fairness half also benefits from #4; absent #4, the engine falls back to 0.5 absolute with a `scale_range_normalization_unavailable_using_absolute_threshold` diagnostic.

**Activation impact of #1a (Survey Builder path).** Users with surveys built through the Builder where `q.construct` is populated will see new scoring for §4A, §4B, and §4E starting with the 2026-05-27 commit. Their lens scores will shift accordingly (§4B alone adds ~+2 pts on `psychometric_core` and `validity_forward` per the harness CFA fixture). Users with surveys that don't have constructs assigned will see no change — the engine still whole-domain-skips when *any* Likert item is untagged.

**Implementation notes per change.**

1. **`scale` / `construct`** — three independent surfaces, three independent source fields, three independent transforms. Engine accepts either field name (`scale` or `construct`) and reads via `v.scale || v.construct`:
   - **#1a Survey Builder path (DONE 2026-05-27)** — sourced from `q.construct` on each question in `surveys.questions` JSON, populated by the Survey Builder UI ([app.html:8050](app.html:8050)) and the AI Construct Mapper ([app.html:8806](app.html:8806)). Propagated through [api/surveys/_build_dataset.php](api/surveys/_build_dataset.php) (Likert block + matrix block, sub-items inherit parent construct).
   - **#1b Uploaded-datasets path (remaining)** — sourced from `column_meta.construct` on the [datasets table](db/schema_phase7.sql) (already populated by [api/datasets/update_columns.php:55](api/datasets/update_columns.php:55)). A separate transform is needed to read it through into the engine's `variables[]` shape; consumer surfaces that load dataset JSON directly (e.g., the studio mount when sourced from a dataset rather than a survey) need to set `v.construct` from `column_meta`.
   - **#1c Wizards-driven settings path (remaining)** — sourced from `settings.scales` (project-level, keyed by dataset variable name) written by [survey-wizard.php:297](survey-wizard.php:297) and [strength-survey-wizard.php:297](strength-survey-wizard.php:297). No transform currently consumes it; a join-by-variable-name step is needed when the wizards path produces the engine input.
2. **`reverse_coded`** — sourced from `column_meta.reverse` (already in the [datasets table](db/schema_phase7.sql) per [api/datasets/create.php:4](api/datasets/create.php)).
3. **`reverse_coded_confirmed`** — survey-level flag captured by a Setup Wizard step (wizard does not exist yet; design it to capture this distinction during reverse-coding flagging). Engine reads via `config.reverse_coded_confirmed`.
4. **Likert format metadata** — sourced from the Survey Builder's question definition (already knows the anchor count). Engine accepts either `likert_range: [min, max]` or `anchor_count: int`.
5. **`anchor_labels`** — sourced from the Survey Builder's question definition. **Optional**: §4G ships a spec-sanctioned 2/2 default until labels arrive.
6. **`criterion_column`** — column name (item ID) of a numeric outcome variable. Surface that populates this field is open (Setup Wizard, evidence-intake config, or a dedicated criterion-mapping step). **Optional**: §4A scores convergent + HTMT without it; #6 unlocks criterion and removes the V-F cap.
7. **Demographic detection** — engine accepts EITHER signal:
   - Per-variable `is_demographic: true` flag on categorical variables in the dataset shape, OR
   - `config.demographic_columns: [name1, name2, …]` list in the config object.
   Whichever the platform supplies wins. The dataset transform already emits categorical variables (`types: ['categorical']`); marking demographics is either a per-question Survey Builder flag or a dedicated demographic-tagging step.

**Recommended order of landing.**

1. **First**: change #1 (scale assignments). Unlocks the most surface area. After this, §4A + §4E begin scoring and §4G adds per-scale straight-lining.
2. **Second**: change #4 (anchor metadata). Unlocks §4E sub-3, §4G subs 1/2/4, and §4D threshold normalization. Combined with #1, the largest end-to-end lift in production lens scores.
3. **Third (parallel-OK)**: change #2 (reverse_coded flags), #6 (criterion_column), #7 (demographic detection). Each is independent of the others and unlocks a discrete capability.
4. **Fourth**: change #3 (reverse_coded_confirmed). Requires Setup Wizard UI.
5. **Optional**: change #5 (anchor_labels). Future-evaluation expansion of §4G sub-3.

**First-run verification.** When the first of these lands, the affected domain will begin producing real scores in production for the first time. End-to-end verify against a known dataset (sub-scores match hand-checked values) before relying on the lens scores for user-facing reports — the harness's coverage is synthetic by design, and the first production run on real data deserves an end-to-end sanity check.

**Resolve during.** Platform-side dataset-transform + Setup Wizard work, separate conversation from any module-side build.

**Code touch-points.** [api/surveys/_build_dataset.php:22](api/surveys/_build_dataset.php) (transform — changes #1, #2, #4, #5, #7), Setup Wizard surface (not yet built — changes #3, #6, #7), [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) (consumer — already wired for all seven). Spec §4A, §4D, §4E, §4G.

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

---

## 10. §4D double-barreled detector — rule-based heuristic with documented false-positive risk

**Surfaced:** §4D Bias & Clarity build, Phase 1 Q6.

**Problem.** The double-barreled flag in §4D wording health detects items where a coordinating conjunction (`and` / `or`) joins what *appears* to be two clauses with verbs on each side. The detector is regex-based: it splits on ` and ` / ` or ` and checks each side against a curated English verb list. Without an NLP / POS tagger, this is a heuristic — it catches the most common double-barreled patterns but produces false positives on legitimate compound sentences.

**Example false positive.** *"I am happy and well-rested"* trips the detector: left side contains "am" (a verb in the list); right side contains "well-rested" — and although the right side has no listed verb, a variant like *"I am happy and feel well-rested"* would trip both sides. This is arguably one proposition expressed compactly, not two separate things being asked about. The detector cannot distinguish "X and Y are both adjectives describing one state" from "X is one thing being measured, Y is a separate thing being measured."

**Implication.** Users seeing a double-barreled flag should treat it as "worth reviewing," not "definitely broken." The flag deducts 1 pt from the 12-pt wording pool, so a single false-positive item costs <1 pt on the §4D sub-score; the cumulative impact on the lens scores is bounded. The diagnostic value (surfacing item phrasing that might confuse respondents) outweighs the false-positive cost in practice.

**Resolve during.** When a real POS tagger or NLP library is available to the engine (e.g., a future Python microservice or WASM-port decision per spec §11). Until then, the heuristic is acceptable for v2 phase one.

**Code touch-points.** [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) — `computeBiasClarity` → `hasDoubleBarreled` (regex + `DOUBLE_VERBS` list). Spec §4D wording row (b).

---

## 11. §4D wording detectors are English-only — internationalization deferred

**Surfaced:** §4D Bias & Clarity build, Phase 1 Q6.

**Problem.** Two of the three wording-health flags rely on English-language lists:
- The **leading-language lexicon** (`obviously`, `clearly`, `always`, `never`, `everyone`, `no one`) is English.
- The **double-barreled detector's verb list** (`is`, `are`, `has`, `have`, `do`, `does`, `can`, `should`, `helps`, `makes`, …) is English.

A survey in Spanish, French, Mandarin, or any non-English language will produce zero wording flags from these two detectors (false negatives across the board). The FK Grade Level formula is also language-specific — the formula was calibrated on English text, and the syllable-counting heuristic uses English vowel patterns. Applied to non-English text, FK values are not interpretable in any standard sense.

**Implication.** §4D's wording-half is only meaningful for English-language surveys at present. ReliCheck v2 doesn't appear to target non-English deployments today, so this is a deferred concern rather than an active bug. When non-English support enters the roadmap, each detector needs either a per-language list (verb + leading lexicon) or replacement with a language-agnostic approach.

**Resolve during.** Internationalization planning conversation (not yet scoped). Three response options:
1. **Per-language detector swap.** Maintain `WORDING_DETECTORS[locale]` with verb list, leading lexicon, and FK-equivalent readability formula (e.g., LIX for European languages) per language. Engine reads survey locale from config and selects the right detector.
2. **Defer the flag.** §4D wording half ships disabled for non-English surveys; only fairness scores. Spec interpretation: "no inspectable item text" applied per-language.
3. **NLP-library route.** A POS-tagged + multilingual NLP service replaces the rule-based detectors entirely. Aligns with the §11 spec note about a future Python microservice or WASM port.

**Code touch-points.** [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) — `computeBiasClarity` → `LEADING_LEX`, `DOUBLE_VERBS`, `fkGrade`. Spec §4D wording row.

---

## 12. §4D jargon detection deferred — out of scope for v2 phase one

**Surfaced:** §4D Bias & Clarity build, Phase 1 Q8.

**Problem.** The §4D conversation header mentioned "possibly jargon detection" as a wording-health flag, but the spec §4D body lists only three flags (FK > 12, double-barreled, leading lexicon). Jargon detection is intentionally NOT in the spec — the implementation cost (corpus frequency data, domain-specific thresholds, false-positive risk) is disproportionate to the diagnostic value in v2 phase one.

**Implication.** Items containing genuine jargon (e.g., *"Our organization's KPI alignment with OKRs supports cross-functional synergy"*) will not be flagged by the wording-health half unless they also trip FK > 12 or one of the other two detectors. This is a known scope decision, not an oversight.

**Resolve during.** Future v2 phase two work, if user feedback surfaces the need. The implementation would slot in as a fourth wording-health flag (1 pt deduction per flagged item, same per-flag pattern) without restructuring the domain. Likely approach: a static "academic / corporate jargon" wordlist, or a frequency-based detector against a general-English reference corpus. The decision tree (which wordlist? which corpus? which threshold?) is what makes this disproportionate today; a focused conversation can resolve all three once user demand materializes.

**Code touch-points.** [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) — `computeBiasClarity` → would add a `hasJargon(text)` helper alongside the existing three detectors. Spec §4D wording row (would add (d)).

---

## 13. §4B CFA implemented as iterated PAF, not full ML

**Surfaced:** §4B Construct Alignment build, Phase 1 Q1.

**Problem.** Spec §4B describes per-scale Confirmatory Factor Analysis but does not pin the estimator. The canonical implementation uses **iterated principal-axis factoring (PAF)**, not maximum-likelihood (ML) estimation. PAF refines communalities by replacing the correlation matrix's diagonal with prior-iteration squared loadings and re-running the top-eigenpair decomposition until max |Δh²| < 1e-5 or 100 iterations. ML would minimize the Wishart-based discrepancy function via a quasi-Newton or L-BFGS optimizer that the engine does not currently have.

**Implication.** On the §4B sanity-check fixture (2 scales × 4 items × N=500, λ=0.8, factor cor 0.3, quantized to 5-pt Likert), iterated PAF produces standardized loadings within **~0.002** of `semopy`'s ML output — an order of magnitude tighter than the ±0.05 tolerance the harness asserts. CFI and RMSEA derived from the PAF loadings via the ML discrepancy formula are within the same ±0.05 bound. The simplification is deliberate, approved during the Phase 1 audit (Q1 option (a)), and on tested data is essentially invisible to users; the harness assertion at ±0.05 remains the conservative guarantee.

The CFA-with-EFA-fallback path the spec describes (§4B and §11) is structurally a no-op under this estimator — they are the same algorithm. The engine still surfaces a `cfa_to_efa_fallback` diagnostic when R is singular so the user sees the fallback signal.

**Resolve during.** Future v2 phase two work, only if the PAF-vs-ML divergence becomes user-facing. The replacement would be a custom ML optimizer (~days of work, with convergence-handling, line search, and gradient computation), or — preferably — a decision to route §4B through a Python microservice or WASM port per spec §11. Until then, iterated PAF is the right v2 phase one choice.

**Code touch-points.** [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) — `computeConstructAlignment` → `iteratedPAFLoadings`. Spec §4B estimator note.

---

## 14. §4B dormant in production until scale assignments propagate

**Surfaced:** §4B Construct Alignment build, Phase 1 audit.

**Problem.** §4B requires per-Likert-item `scale` / `construct` membership to compute (every sub-component groups items by scale). The platform-side data contract does not yet supply this field — the same dependency tracked as item #1 in [§4 above](#4-platform-side-data-contract-checklist--seven-changes-unlock-the-full-canonical-engine). When the field is absent, §4B whole-domain skips (`construct_alignment: null`) and the §3.2 lens math absorbs it via skip-and-rescale.

**Implication.** Production lens scores today are unchanged by the §4B build — §4B's contribution to the weighted sum is zero until scale assignments propagate. On the §4B CFA sanity-check fixture (scale assignments present), the three lens scores shift by +1.4 to +2.5 points relative to the §4B-null baseline because §4B at score 100 is higher than the other domains' averages on that fixture. No new platform-side data contract item is required beyond §4 item #1; §4B and §4A go live together when that lands.

**Resolve during.** §4 item #1 landing on the platform side. No engine-side fix needed.

**Code touch-points.** Module side (no fix needed): [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) `computeConstructAlignment` — already wired and gated on scale assignments. Platform side: same dependency as §4 item #1. Spec §4B.

---

## 15. §4B Model Fit excludes k ≤ 3 scales

**Surfaced:** §4B Construct Alignment build, Phase 1 Q4 (spec extension).

**Problem.** The spec §4B Model Fit sub-component originally read "Single-item and two-item scales are excluded from this check (fit indices are not defined)." During the Phase 1 audit it became clear that **three-item scales** also need to be excluded: for k=3 the one-factor model has df = (k−1)(k−2)/2 = 0. With zero degrees of freedom, the model is just-identified — χ² equals zero and fit indices (CFI ≈ 1.0, RMSEA = 0) are perfect by construction regardless of how the data actually behaves. Reporting fit on a just-identified model would mislead users by implying excellent structural fit when the indices are mathematically forced to that value.

**Implication.** The spec §4B Model Fit row is updated to read "Single-item, two-item, and three-item scales excluded from fit scoring." Loadings and the weak-loading penalty still apply for k≥2 (the math is well-defined there); cross-loading detection via the pooled Promax EFA also applies (items participate in the pooled rotation regardless of their scale's k); only Model Fit excludes k≤3. The exclusion is engine-side: per-scale fit returns `{ fit: null, fit_exclude_reason: 'k_lte_3' }` and is dropped from the "every scale clears the threshold" quantifier without penalty.

**Resolve during.** Resolved at landing. Spec is updated; engine implements the rule; harness verifies via the two-item-scale fixture (`fit_exclude_reason === 'k_lte_3'`). No further work required.

**Code touch-points.** [apps/strength-index/strength-index.js](apps/strength-index/strength-index.js) — `computeConstructAlignment` → `perScale` loop's `if (k <= 3) { fitExcludeReason = 'k_lte_3'; }` branch. Spec §4B Model Fit row.

---


## 16. Standalone RSSI app has no scale-assignment UI — §4A/§4B/§4E whole-domain-skip on every standalone run

**Surfaced:** §4 item #1a end-to-end verification (2026-05-27).

**Problem.** The standalone RSSI app at [apps/rssi/rssi-upload.js](apps/rssi/rssi-upload.js) parses uploaded CSV client-side and forwards the detected Likert columns straight to the engine with no column-tagging step. Variables flow into `RSSI_MATH.computeLensesFromDataset` without `v.scale` or `v.construct` set on any of them. The engine therefore whole-domain-skips §4A Validity, §4B Construct Alignment, and §4E Scale Structure on every standalone RSSI run, regardless of how well-constructed the underlying instrument is.

This is a **fourth platform-side surface** distinct from the three transform paths in §4 item #1 (a/b/c). The other three surfaces have the data captured somewhere upstream and just need a transform to read it through; standalone RSSI has no upstream capture at all — there's no UI step where the user assigns items to scales.

**Implication.** Standalone RSSI users see three domains permanently null in their reports. The headline lens scores are computed without those domains and rescaled accordingly per §3, so the math is correct, but a meaningful portion of the engine's diagnostic power is unavailable on this surface. The studio-mount path (surveys built through the Survey Builder) now exercises these three domains as of §4 item #1a; standalone RSSI does not.

**Resolve during.** Standalone RSSI UX work, when prioritized. The fix requires:
1. A post-upload column-tagging step in `rssi-upload.js` where the user assigns each Likert column to a construct (free-text input, or an AI-suggest button mirroring the Survey Builder's Construct Mapper).
2. Wiring those assignments onto each variable as `v.construct` before calling `computeLensesFromDataset`.

Priority depends on which surface (studio mount vs. standalone) users actually use most. The studio mount is now the higher-leverage path because three additional domains score there.

**Code touch-points.** [apps/rssi/rssi-upload.js](apps/rssi/rssi-upload.js) (CSV parse + variable construction), [apps/rssi/rssi.js](apps/rssi/rssi.js) (host page). No engine change needed — same data contract as the studio mount.
