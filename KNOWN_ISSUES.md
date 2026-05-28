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
| 1b | `scale` (or `construct`) per Likert variable — **uploaded-datasets path** via [api/datasets/*](api/datasets/) | per-item dataset field | **DONE 2026-05-27.** Same three-domain activation (§4A/§4B/§4E) as #1a, on the uploaded-datasets path. [api/_dataset_to_survey.php](api/_dataset_to_survey.php) `dts_build_questions_and_index()` now reads `column_meta.construct` and emits it as `q.construct` on the synthesized survey question (trim-and-omit-when-empty contract matching [_build_dataset.php](api/surveys/_build_dataset.php)); from there the #1a transform layer propagates it onto `variables[].construct`. End-to-end verified via a new unit-level assertion on the transform plus a synthetic end-to-end check; the standalone RSSI app surface (§16) is now the remaining largest activation gap. **Closed by §16 M2 on 2026-05-27** — the standalone tag stage now activates the same three domains when users tag. |
| 1c | `scale` (or `construct`) per Likert variable — **wizards-driven settings path** via [survey-wizard.php](survey-wizard.php) / [strength-survey-wizard.php](strength-survey-wizard.php) | per-item dataset field | **DONE 2026-05-27.** Same three-domain activation (§4A/§4B/§4E) as #1a, on the wizards path. [api/surveys/_build_dataset.php](api/surveys/_build_dataset.php) now reads `surveys.settings.scales` (keyed by variable name) when `q.construct` is empty, applying a three-level fallback for matrix sub-rows (per-row wizard tag → parent-question wizard tag). |
| 2 | `reverse_coded: bool` per Likert variable — **wizards-driven settings path** | per-item dataset field | **DONE 2026-05-27 (wizards path).** §4E sub-2 reads `v.reverse_coded` on Likert variables. [api/surveys/_build_dataset.php](api/surveys/_build_dataset.php) now reads per-question `q.reverse` (Builder, when present) with fallback to `settings.scales[vname].reverse` (wizard); matrix sub-items use the same three-level fallback as construct. Builder-path activation (q.reverse) and uploaded-datasets path (column_meta.reverse → q.reverse) tracked as follow-ups #2a/#2b. |
| 2a | Survey Builder reverse-confirmation UI parity | UI + config flow | §4E sub-2 activation when surveys are authored via the Builder rather than the wizards. Builder already has a per-item `q.reverse` checkbox ([app.html:8119](app.html:8119)) but **no survey-level "I've reviewed every item" confirmation control**. Until landed, Builder-authored surveys will have per-item reverse flags propagating (the Phase 2 transform reads `q.reverse` first) but will see §4E sub-2 skip-and-rescale on every run because `config.reverse_coded_confirmed` is never populated for the Builder path. |
| 2b | Datasets-upload-wizard reverse-confirmation UI parity | UI + config flow | §4E sub-2 activation for uploaded datasets routed through `dts_build_questions_and_index()`. The per-item half already works (`column_meta.reverse` is propagated to `q.reverse` by [api/_dataset_to_survey.php:58](api/_dataset_to_survey.php:58), then to `v.reverse_coded` by the Phase 2 transform). What's missing is a survey-level confirmation step in the Datasets-upload wizard at [app.html:37897](app.html:37897). |
| 3 | `reverse_coded_confirmed: bool` survey-level flag — **wizards-driven settings path** | config object | **DONE 2026-05-27 (wizards path).** Captured by a new checkbox at the bottom of Step 3 in both [survey-wizard.php](survey-wizard.php) and [strength-survey-wizard.php](strength-survey-wizard.php), persisted to `surveys.settings.reverse_coded_confirmed`, propagated to a new `dataset.config` block by [_build_dataset.php](api/surveys/_build_dataset.php), and read at the engine seam by [strength-index.js:3421](apps/strength-index/strength-index.js:3421) (and the parity-only standalone seam at [rssi-upload.js:186](apps/rssi/rssi-upload.js:186)). Tri-state contract honored end-to-end: synthetic E2E confirms `reverse_coded_confirmed: true` + no flagged k≥4 items produces §4E=75 (sub-2 fires architectural finding, scores 0, rescaled) vs §4E=100 when the flag is absent (sub-2 skip-and-rescale). Builder + Datasets-upload-wizard parity tracked as #2a/#2b. |
| 4 | `anchor_count: int` per Likert variable | per-item dataset field | **DONE 2026-05-27.** §4E sub-3 (format uniformity), §4G subs 1/2/4 (anchor count / midpoint / single-format), and §4D fairness threshold normalization all activate when `settings.likertPoints` (survey-level default) or `q.likertPoints` (Builder per-question override) is populated. Builder, wizards, and uploaded-datasets paths all propagate. Heterogeneous-Likert ceiling for uploads tracked as #4b. |
| 4b | Per-column anchor count for heterogeneous-Likert uploads | per-column dataset field | §4G sub-4 (single-format-per-scale) and §4E sub-3 (format uniformity) **falsely score full points** when an uploaded dataset mixes column-level Likert formats (e.g., a 5-point block alongside a 7-point block). The #4 transform propagates the dataset-wide `settings.likertPoints` uniformly to every Likert column, masking the mixed-format case. Resolution: extend the Datasets-upload wizard with a per-column Likert-points selector and persist as `column_meta.likertPoints`; [api/_dataset_to_survey.php:75](api/_dataset_to_survey.php:75) already reads this slot (per-column override beats dataset-wide), so the engine half activates automatically when the UI ships. Documents the ceiling; doesn't block #4. |
| 5 | `anchor_labels: [string, …]` per Likert variable (optional) | per-item dataset field | §4G sub-3 (anchor symmetry beyond default 2/2 — see §8) |
| 6 | `config.criterion_column` (column name) | config object | §4A criterion sub-component; removes §3.6 V-F cap |
| 7 | `is_demographic: true` per categorical variable **OR** `config.demographic_columns: [name, …]` | per-item flag or config list | §4D fairness half (DIF proxy) |

**Per-domain status with current data contract (as of 2026-05-27, after #1a + #1c + #1b landed).**

All four surfaces (Survey Builder, wizards-driven settings, uploaded XLSX/CSV datasets, standalone RSSI app) now flow construct assignments through to the engine. The standalone surface ships its tag stage in §16 M2 (2026-05-27); users who complete tagging activate §4A/§4B/§4E, matching the three transform paths.

- **§4E Scale Structure**: **ACTIVE across all three survey-construction surfaces** for items with constructs assigned (item-count, missingness, survey health score). **Sub-component 2 (reverse-coded balance) ACTIVATES on the wizards-driven settings path** when the user ticks the new Step-3 confirmation checkbox (Phase 2, 2026-05-27 — #2 + #3 wizards-path landing). On the Survey Builder path and the Datasets-upload-wizard path, sub-2 still skip-and-rescales because those surfaces lack a survey-level confirmation control (#2a / #2b — per-item reverse propagation works on all three paths; only the survey-level confirmation differs). **Standalone RSSI (§16) activates §4E across all sub-components when the user completes the tag stage** (M2, 2026-05-27) — including sub-2 via the bottom-bar reverse-coded confirmation checkbox. **Sub-component 3 (response-format uniformity) ACTIVATES across all three survey-construction surfaces as of 2026-05-27 (#4)** — per-item `anchor_count` propagates via `q.likertPoints` (Builder per-question) → `settings.likertPoints` (survey-level default). Heterogeneous-Likert uploads still score sub-3 as uniform (ceiling tracked as #4b).
- **§4G Response Scale Review**: partial-evaluation. Per-scale straight-lining now active across all three survey-construction surfaces. **Subs 1/2/4 (anchor count / midpoint presence / single-format-per-scale) ACTIVATE across all three survey-construction surfaces as of 2026-05-27 (#4)** via the same `anchor_count` propagation. #5 unlocks real anchor-symmetry (currently default 2/2 per §8).
- **§4A Validity**: **ACTIVE across all three survey-construction surfaces AND standalone RSSI** (convergent + HTMT score). Standalone RSSI activates when the user tags constructs in §16 M2 tag stage (2026-05-27). #6 unlocks criterion and disengages the §3.6 V-F cap; standalone users can also populate `config.criterion_column` via the Criterion role in the tag stage.
- **§4B Construct Alignment**: **ACTIVE across all three survey-construction surfaces AND standalone RSSI** (per-scale PAF loadings + cross-loadings score). Standalone RSSI activates when the user tags constructs in §16 M2 tag stage (2026-05-27).
- **§4D Bias & Clarity**: partial-evaluation today. Wording-half scores whenever item text is propagated via `v.label` (already populated by [_build_dataset.php](api/surveys/_build_dataset.php) in production — no change needed for that half). Fairness-half requires #7. **Threshold normalization on the fairness half now activates across all three survey-construction surfaces as of 2026-05-27 (#4)** — `anchor_count` propagates and the `scale_range_normalization_unavailable_using_absolute_threshold` diagnostic is no longer emitted on populated surveys. Once #7 lands, fairness-half scores against scale-normalized thresholds (0.125·range) rather than the absolute-0.5 fallback.

**Activation impact of #1a (Survey Builder path).** Users with surveys built through the Builder where `q.construct` is populated will see new scoring for §4A, §4B, and §4E starting with the 2026-05-27 commit. Their lens scores will shift accordingly (§4B alone adds ~+2 pts on `psychometric_core` and `validity_forward` per the harness CFA fixture). Users with surveys that don't have constructs assigned will see no change — the engine still whole-domain-skips when *any* Likert item is untagged.

**Activation impact of #1c (wizards-driven settings path).** Users who completed Step 3 of `survey-wizard.php` or `strength-survey-wizard.php` ("Confirm scales and scoring") on surveys whose Builder-side `q.construct` field is empty now get the same §4A/§4B/§4E activation as the Builder path. End-to-end verified on a synthetic 60-row wizard-shaped fixture (2 four-item scales + a 3-row matrix tagged only at the parent level): §4A=60, §4B=100 (three scales grouped including the matrix parent-fallback), §4E=93. Users who completed the wizard but did not assign any scale names see no change (untagged items continue to whole-domain-skip, same as the Builder path).

**Implementation notes per change.**

1. **`scale` / `construct`** — three independent surfaces, three independent source fields, three independent transforms. Engine accepts either field name (`scale` or `construct`) and reads via `v.scale || v.construct`:
   - **#1a Survey Builder path (DONE 2026-05-27)** — sourced from `q.construct` on each question in `surveys.questions` JSON, populated by the Survey Builder UI ([app.html:8050](app.html:8050)) and the AI Construct Mapper ([app.html:8806](app.html:8806)). Propagated through [api/surveys/_build_dataset.php](api/surveys/_build_dataset.php) (Likert block + matrix block, sub-items inherit parent construct).
   - **#1b Uploaded-datasets path (DONE 2026-05-27)** — sourced from `column_meta.construct` on the [datasets table](db/schema_phase7.sql) (populated by [api/datasets/update_columns.php:55](api/datasets/update_columns.php:55) and the Datasets upload wizard at [app.html:37897](app.html:37897)). The user routes a dataset to the engine via [api/surveys/create_from_dataset.php](api/surveys/create_from_dataset.php), which calls `dts_build_questions_and_index()` in [api/_dataset_to_survey.php](api/_dataset_to_survey.php). That transform's Likert branch now reads `column_meta.construct` and emits it as `q.construct` on the synthesized survey question (trim-and-omit-when-empty contract), from which the #1a transform layer in [_build_dataset.php](api/surveys/_build_dataset.php) propagates it to `variables[].construct`. No new consumer surface needed — the path joins the survey-studio activation chain after `dts_build_questions_and_index()`.
   - **#1c Wizards-driven settings path (DONE 2026-05-27)** — sourced from `settings.scales` (project-level, keyed by dataset variable name) written by [survey-wizard.php:297](survey-wizard.php:297) and [strength-survey-wizard.php:297](strength-survey-wizard.php:297). Propagated through the same [api/surveys/_build_dataset.php](api/surveys/_build_dataset.php) transform extended in #1a: the function now accepts a `$settings` parameter and reads `settings.scales[varname].scale` as a fallback when `q.construct` is empty. Precedence is `q.construct` → per-row wizard tag → parent-question wizard tag (matrix only). Wizard's `direction` and `reverse` fields stay parked in `settings.scales` pending #2/#3.
2. **`reverse_coded`** — three sources, same precedence convention as construct:
   - **Survey Builder**: `q.reverse` from app.html ("Reverse-scored" checkbox at [app.html:8119](app.html:8119)). Builder wins when set (presence-of-key signals "Builder has an opinion," including explicit `false`).
   - **Wizards-driven (DONE 2026-05-27)**: `settings.scales[vname].reverse` written by Step 3 of [survey-wizard.php:320](survey-wizard.php:320) / [strength-survey-wizard.php:320](strength-survey-wizard.php:320). [_build_dataset.php](api/surveys/_build_dataset.php) reads this as the fallback when `q.reverse` is absent; matrix sub-items get the same three-level fallback as construct (parent Builder → per-row wizard → parent wizard). Engine reads `v.reverse_coded` (boolean, omitted when no surface had an opinion — distinct from explicit `false`).
   - **Uploaded-datasets**: `column_meta.reverse` (already in the [datasets table](db/schema_phase7.sql) per [api/datasets/create.php:4](api/datasets/create.php), propagated to `q.reverse` by [api/_dataset_to_survey.php:58](api/_dataset_to_survey.php:58)). The Phase 2 [_build_dataset.php](api/surveys/_build_dataset.php) read of `q.reverse` activates this path automatically — verified by reading the synthesized survey shape, no transform change needed.
3. **`reverse_coded_confirmed`** — survey-level flag persisted on `surveys.settings.reverse_coded_confirmed` and propagated by [_build_dataset.php](api/surveys/_build_dataset.php) into a new top-level `dataset.config` block (not on `variables[]` — it's survey-level config, not per-item metadata). The engine call sites at [strength-index.js:3421](apps/strength-index/strength-index.js:3421) and [rssi-upload.js:186](apps/rssi/rssi-upload.js:186) now read `(dataset && dataset.config) || {}` as the second argument to `computeLensesFromDataset`. Engine reads via `config.reverse_coded_confirmed`. Captured by the new Step 3 checkbox in both wizards (DONE 2026-05-27). Builder + Datasets-upload-wizard parity remains pending as #2a / #2b.
4. **Likert format metadata (DONE 2026-05-27)** — emit field is `anchor_count: int` (engine accepts `likert_range` too but the transform layer emits the minimal shape per Phase 1 Q5).
   - **Survey Builder path** — sourced from `q.likertPoints` (per-question override, [app.html:8127](app.html:8127)) with fallback to `surveys.settings.likertPoints` (survey-level default, [app.html:6139](app.html:6139)). Both already captured in production; no new UI surface area.
   - **Wizards-driven settings path** — falls back to `surveys.settings.likertPoints` (the wizard does not capture a per-row anchor count; the underlying dataset's `settings.likertPoints` is copied onto the survey row by [api/surveys/create_from_dataset.php:65](api/surveys/create_from_dataset.php:65)).
   - **Uploaded-datasets path** — sourced from `datasets.settings.likertPoints` (a dataset-wide setting written by the Datasets upload wizard and validated at [api/datasets/update.php:64](api/datasets/update.php:64) to [2,11]). Baked into each synthesized Likert question's `q.likertPoints` by [api/_dataset_to_survey.php](api/_dataset_to_survey.php) `dts_build_questions_and_index()` (now accepts a third `$dsSettings` parameter). [api/surveys/create_from_dataset.php:44](api/surveys/create_from_dataset.php:44) threads `$dsSettings` through. Per-column override slot (`column_meta.likertPoints`) is read by the same transform with precedence over the dataset-wide value, future-proofing the heterogeneous-Likert ceiling documented as #4b.
   - **Transform layer** — [api/surveys/_build_dataset.php](api/surveys/_build_dataset.php) Likert branch derives `$effLikertPoints = $questionLikertPoints ?? $surveyLikertPoints` and emits `v.anchor_count` only when valid (integer 2..11). Matrix sub-items inherit the parent's `q.likertPoints` via single-level fallback (Phase 1 Q6 — Builder UI sets points at the matrix-question level, never per row). Omit-when-absent contract preserves the pre-#4 engine skip behavior on §4G/§4D for legacy records.
   - **Harness** — unit-level: [api/__harness/dts_anchor_count_propagation.php](api/__harness/dts_anchor_count_propagation.php) covers all precedence rules (per-question wins, survey-level fallback, both-absent omit, invalid values rejected, matrix inheritance, dataset-import baking, 2-arg backwards-compat). End-to-end: [api/__harness/dts_e2e_anchor_count.php](api/__harness/dts_e2e_anchor_count.php) + [api/__harness/dts_e2e_anchor_count_engine.js](api/__harness/dts_e2e_anchor_count_engine.js) demonstrates the unlock — control (no metadata) skips §4G subs 1/2/4 and emits §4D's `scale_range_normalization_unavailable_using_absolute_threshold`; treatment (`settings.likertPoints=5`) activates all three §4G subs and clears the §4D diagnostic.
5. **`anchor_labels`** — sourced from the Survey Builder's question definition. **Optional**: §4G ships a spec-sanctioned 2/2 default until labels arrive.
6. **`criterion_column`** — column name (item ID) of a numeric outcome variable. Surface that populates this field is open (Setup Wizard, evidence-intake config, or a dedicated criterion-mapping step). **Optional**: §4A scores convergent + HTMT without it; #6 unlocks criterion and removes the V-F cap.
7. **Demographic detection** — engine accepts EITHER signal:
   - Per-variable `is_demographic: true` flag on categorical variables in the dataset shape, OR
   - `config.demographic_columns: [name1, name2, …]` list in the config object.
   Whichever the platform supplies wins. The dataset transform already emits categorical variables (`types: ['categorical']`); marking demographics is either a per-question Survey Builder flag or a dedicated demographic-tagging step.

**Recommended order of landing.**

1. **First**: change #1 (scale assignments). Unlocks the most surface area. After this, §4A + §4E begin scoring and §4G adds per-scale straight-lining.
2. **Second (DONE 2026-05-27)**: change #4 (anchor metadata). Unlocks §4E sub-3, §4G subs 1/2/4, and §4D threshold normalization. Combined with #1, the largest end-to-end lift in production lens scores.
3. **Third (parallel-OK)**: change #2 (reverse_coded flags), #6 (criterion_column), #7 (demographic detection). Each is independent of the others and unlocks a discrete capability.
4. **Fourth**: change #3 (reverse_coded_confirmed). Requires Setup Wizard UI.
5. **Optional**: change #5 (anchor_labels). Future-evaluation expansion of §4G sub-3.

**First-run verification.** When the first of these lands, the affected domain will begin producing real scores in production for the first time. End-to-end verify against a known dataset (sub-scores match hand-checked values) before relying on the lens scores for user-facing reports — the harness's coverage is synthetic by design, and the first production run on real data deserves an end-to-end sanity check.

**Verification-pattern note (established by #1b, reinforced by #2/#3).** Platform-side transform changes now use a two-layer harness pattern that runs without the database or the browser:

1. **Unit-level PHP assertion** — a focused PHP script under `api/__harness/` that calls the transform directly with synthetic input and asserts on the emitted shape (precedence rules, trim-and-omit contracts, tri-state behavior). Pattern files: [api/__harness/dts_construct_propagation.php](api/__harness/dts_construct_propagation.php) (#1b), [api/__harness/dts_reverse_coded_propagation.php](api/__harness/dts_reverse_coded_propagation.php) (#2/#3).
2. **Synthetic end-to-end** — a PHP script emits a JSON dataset that a Node shim feeds into the canonical engine, asserting on the engine output (which sub-components activate, what scores fire, which skip-and-rescale). Pattern files: [api/__harness/dts_e2e_uploaded_path.php](api/__harness/dts_e2e_uploaded_path.php) + [api/__harness/dts_e2e_engine_shim.js](api/__harness/dts_e2e_engine_shim.js) (#1b), [api/__harness/dts_e2e_reverse_coded_confirmed.php](api/__harness/dts_e2e_reverse_coded_confirmed.php) + [api/__harness/dts_e2e_reverse_coded_confirmed_engine.js](api/__harness/dts_e2e_reverse_coded_confirmed_engine.js) (#2/#3).

This pattern is parallel to the engine-side `apps/strength-index/__harness/three-lens-verify.js` (which exercises the math) — the transform harness exercises the platform→engine contract. Together they cover the seam §17 was written to defend. Future platform-side commits to the transform layer should land both layers as part of the change; the unit layer catches contract bugs cheaply, the E2E layer catches the integration-seam bugs the unit layer misses.

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


## 16. Standalone RSSI app tag stage — §4A/§4B/§4E activation rollout

**Surfaced:** §4 item #1a end-to-end verification (2026-05-27). **M1 landed 2026-05-27** (pure-function extraction + activation contract harness). **M2 landed 2026-05-27** (tag stage minimum-viable UI).

**Problem (pre-M2).** The standalone RSSI app at [apps/rssi/rssi-upload.js](apps/rssi/rssi-upload.js) parsed uploaded CSV client-side and forwarded the detected Likert columns straight to the engine with no column-tagging step. Variables flowed into `RSSI_MATH.computeLensesFromDataset` without `v.construct`, `v.reverse_coded`, or `v.anchor_count` set on any of them, and `dataset.config` was empty. The engine therefore whole-domain-skipped §4A Validity, §4B Construct Alignment, and §4E Scale Structure on every standalone RSSI run.

**Status after M2 (2026-05-27).** The standalone surface now has a tag stage between parse and score where the user assigns each column a role (Likert / Numeric / Demographic / Criterion / Identifier / Free text / Ignore), names a construct for each Likert item, flags reverse-coded items, and ticks a survey-level "I've reviewed every item for reverse-coding" confirmation. The tag stage materializes via `RSSI_TAG_CORE.materializeDataset` with the user's confirmed tags, populating `v.construct`, `v.reverse_coded`, `v.anchor_count`, `dataset.config.demographic_columns`, `dataset.config.criterion_column`, and `dataset.config.reverse_coded_confirmed`. The three previously-skipped domains (§4A, §4B, §4E) now activate when the user tags — the activation contract is locked by [apps/__harness/rssi_tag_emit.test.js](apps/__harness/rssi_tag_emit.test.js) with 96 assertions across three engine-activation scenarios and tag-core unit checks.

**M3–M7 still pending.** Per the milestone plan: M3 dashboard rewidening (the dim-grid migrates to the canonical 8-domain taxonomy; construct_alignment lands as the 7th card), M4 parse normalizations (BOM strip, sentinel-null mapping, locale-decimal toggle) and richer error handling, M5 real fileContentHash (M2 ships an FNV-1a stub; M5 replaces with the spec'd SHA-256 + sampled-large-file rule), M6 authenticated persistence (M2 saves tags to localStorage only), M7 identifier auto-detect and visual polish.

**Activation impact of M2.** Users who upload to the standalone RSSI surface and complete the tag stage now see §4A/§4B/§4E score, matching the studio-mount path. Users who score with auto-detected roles only (no construct names entered) see the pre-M2 behavior: those three domains whole-domain-skip because `v.construct` is absent. The hard-gate that previously refused datasets with < 3 auto-detected Likert columns is retired — `validateTags` now blocks "Score my data" only when zero columns are tagged Likert, and the more meaningful `construct_too_small` hint (< 3 items per construct) surfaces inline at the row.

**Code touch-points.** [apps/rssi/rssi-tag-core.js](apps/rssi/rssi-tag-core.js) (pure functions: inferColumnRoles, materializeDataset, validateTags, dedupeHeaders, fileContentHash, applyParseNormalizations), [apps/rssi/rssi-upload.js](apps/rssi/rssi-upload.js) (tag stage UI + auto-save), [apps/rssi/rssi.css](apps/rssi/rssi.css) (stage visibility + tag-table styling), [rssi-upload.php](rssi-upload.php) (tag-stage markup + Re-tag button), [apps/__harness/rssi_tag_emit.test.js](apps/__harness/rssi_tag_emit.test.js) (activation contract). No engine change needed — same data contract as the studio mount.

---

## 17. Verification process — platform-side commits must check that API-integration callers are tracked

**Surfaced:** §4 item #1c follow-up audit (2026-05-27). The transform-layer activation work for #1a (Survey Builder path) and #1c (wizards-driven settings path) was repeatedly verified end-to-end via the strength-index harness shim — the real PHP transform called in a real PHP process, dataset JSON piped through the canonical engine, three-domain activation confirmed. The harness loop passed both times. What it did not catch: the two production callers of that transform — [api/surveys/responses-dataset.php](api/surveys/responses-dataset.php) and [_studio_mount.php](_studio_mount.php) — were sitting **untracked** in the working tree, with zero git history, across at least one prior #1-series commit. The transform extension may not have actually reached production users until the callers were brought under version control in the follow-up commit.

**Problem.** The harness-shim verification approach has a structural blind spot. It calls the transform directly with synthetic input and feeds the output through the engine. That proves the transform → engine seam is correct. It cannot prove that the *callers* the harness is standing in for actually exist in tracked form, or that they pass the new parameter through, or that they match what's deployed. An untracked caller can drift arbitrarily from the version the harness exercised, and no automated check would notice.

**Implication.** Activation commits that look complete (harness green, transform updated, KNOWN_ISSUES.md marked DONE) can in fact be inert on production until the integration layer separately picks up the contract change. Worse, in the #1a/#1c case, the integration layer wasn't even *tracked* — meaning the production version, whatever it is, may have predated the transform-side work entirely and never invoked the new parameter at all.

**Process change — required on every platform-side commit.** Before declaring a platform-side activation complete:

1. **Caller-side tracking check.** For every file that calls the function/endpoint being changed, run `git ls-files --error-unmatch <path>` (or equivalent). If any caller is untracked, flag it as a finding rather than ship the activation as complete.
2. **Caller-side contract check.** For every tracked caller, confirm it threads the new parameter / consumes the new field. A transform that accepts a new parameter with a defaulted value is backward-compatible, but the activation is only real once the callers pass the value through.
3. **Deployment-reference scan.** `grep -l <changed-file>` across the tracked set to catch documentation, specs, and other tracked files that reference the changed surface. Update those if they describe outdated contracts.

The end-to-end harness shim is a necessary check but not a sufficient one. Treat it as proving the math/engine half; the integration half needs the three steps above.

**Implication for repo-hygiene work.** This discovery suggests the broader untracked-PHP backlog (currently sitting at ~70+ files per `git status`) is more urgent than the raw file count implies. The untracked set is not "files the user hasn't decided about yet" — at least some of it is **load-bearing production infrastructure that tracked code explicitly depends on**. Future repo-hygiene passes should:

- Prioritize tracking files that are referenced (via `include`, `require_once`, or documentation links) from already-tracked code. Those are by definition load-bearing.
- Specifically check the seven untracked files referenced from [docs/relicheck_v2_build_spec.md](docs/relicheck_v2_build_spec.md) and any untracked endpoint under `api/` that's invoked by tracked HTML/JS, before treating the rest of the untracked set as lower priority.
- Treat raw file counts as misleading. The risk is concentrated in the small subset of untracked files the tracked code names directly.

**Resolve during.** Process-only change — applies to every future platform-side commit. The first commit it applies retroactively to is the #1c follow-up tracking commit that surfaced this issue.

**Code touch-points.** No code change. This entry exists as a documented step in the platform-side workflow. Reference it in commit-message checklists or pre-commit reviewer notes.
