# Handoff — Phase 4B: RSSI Scoring Engine v1

_Date: 2026-05-30._

> **Status: COMPLETE + VERIFIED.** Deterministic RSSI v1 scoring engine over
> the Phase 4A dataset object. 33/33 node tests pass; live-verified against the
> real #24 (score 95.2) and #23 (withheld) datasets. NO report UI, NO endpoint,
> NO Studios, NO AI Deep Check, NO factor analysis. SDSI/SIRI untouched.

RSSI = ReliCheck Survey Strength Index (post-data evidence strength;
reliability is one core domain, not the whole). Spine: memory `project_rssi_design`.

## What landed

**New file — `apps/rssi/rssi-engine.js`** (pure, deterministic, UMD module like
`apps/sdsi/buildcheck-engine.js`). Exports `RSSIEngine.score(dataset)` taking the
4A dataset object (the JSON from `api/dev/rssi-dataset.php`) and returning the
full structured result. No AI, no DOM, no UI, no network.

**New file — `apps/rssi/rssi-engine.test.js`** — 33 assertions, run with
`node apps/rssi/rssi-engine.test.js`. Seeded LCG PRNG builds reproducible 4A-shaped
fixtures (good 2-construct Likert survey, fence case, thin construct, dead item,
reverse-keyed item, no-structure, straight-lining, alpha sanity, band mapping).

### Locked v1 spine implemented (sums to 100)
- **Internal Consistency 35** (core): Cronbach alpha per construct over
  complete-case matrices, item-weighted roll-up. Negative inter-item
  correlations folded in as structure sub-evidence (a caution, not a separate
  domain). Whole-survey alpha fallback when no construct can be scored
  individually (states the limitation).
- **Item Performance 25**: corrected item-total r (→ per-item quality
  fraction), difficulty/floor/ceiling, dead items (zero variance),
  redundancy (inter-item r > .85). Emits `itemWarnings[]`.
- **Response Quality 20**: mean completion, missingness, straight-lining rate
  (identical value across all answered scorable items, ≥3 items eligible).
- **Score Interpretability 20**: per-construct distribution health from mean
  construct scores — usable variability (ideal SD ~ range/4) docked by
  floor/ceiling piling.

### N adequacy = cross-cutting fence (NOT scored)
- `analyzable_n < min_n` (30) → **withhold the whole number**, verdict
  "Insufficient data to judge", all domains `points: null, withheld: true`,
  safe descriptive evidence only.
- Per-construct: `< 3` scorable items OR `< 10` complete-case responses →
  that construct labeled not-enough-evidence and excluded from the roll-up
  (survey still scores from healthy constructs).
- Core guard: if Internal Consistency cannot be computed for ANY construct or
  the survey as a whole, the entire index is withheld (`level: no_structure`).

### Bands (interpret-centered)
85+ confident / 70–84.9 minor cautions / 55–69.9 caution / <55 not yet
reliable / fence override "Insufficient data to judge".

### Output shape
`{ ok, version:'rssi-v1', projectId, projectName, fence{analyzableN,minN,tooFew,
withheld,level,notes}, score|null, max:100, pct|null, band, bandKey, verdict,
domains[4]{key,label,max,points|null,fraction|null,withheld,evidence[]},
constructs[]{id,name,scorableCount,enoughItems,n,alpha|null,alphaBand,scored,note},
itemWarnings[]{itemId,label,construct,type,severity,detail},
excludedItems[]{itemId,label,fieldType,reason}, fenceNotes[], summary }`

## Verification (live, real data via browser eval of the module)
| Check | #24 | #23 |
|---|---|---|
| score | 95.2 (real) | null (withheld) |
| band/verdict | confident | Insufficient data to judge |
| construct-first alphas | Engagement .867 (good, n=36), Manager Support .901 (excellent, n=36) | none |
| domains | IC 34.4 / IP 25 / RQ 18.2 / SI 17.6 | all withheld |
| open-text / categorical | item 207 excluded, not broken, 0 false warnings | 12 items excluded, not broken |

Acceptance criteria all met: #24 real score; #23 not forced; internal
consistency construct-first; open-text labeled not-scored; N fence withholds;
structured domains/construct evidence/item warnings/fence notes/summary returned.

## Out of scope (NOT done, per instructions)
No RSSI report UI, no api/dev/rssi-score.php endpoint, no Studios, no AI Deep
Check, no factor analysis. SDSI and SIRI untouched.

## Suggested next phase
Phase 4C — wire the engine into the system: an authed `api/dev/rssi-score.php`
(reuse the 4A dataset builder, run the engine server-side or client-side like
SIRI) and the construct-first RSSI report UI in develop.php. Await go-ahead.

## Standing constraints (unchanged)
No em dashes in user copy. AI = "ReliCheck Intelligence". Additive only; do not
modify production surveys/responses tables. Do not rename sdsi code. Builder is
click-to-add.
