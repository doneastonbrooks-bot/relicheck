# Handoff — RSSI v1 Design Lock

_Date: 2026-05-30._

> **Status: DESIGN LOCKED, NOT BUILT.** No code, tables, or endpoints exist yet.
> This session locked the RSSI spine the way SDSI/SIRI spines were locked before
> building. Full spine recorded in memory `project_rssi_design.md`.

## What RSSI is
RSSI = ReliCheck Survey Strength Index, the **post-data** system. It measures
post-response **evidence strength** broadly; reliability is one core domain, NOT
the whole (do not call it "Reliability Survey Strength Index"). Lifecycle
partner to SIRI: SIRI (pre-launch, 100 pts) asks "is this instrument ready to
collect interpretable data?"; RSSI runs after collection and answers "did the
collected data perform reliably and support interpretation?" Borrow the old RSCI
alpha fence + deterministic flag detection deliberately; structure is the user's.

## Locked decisions
- **Shape:** 100-point index, weighted domains (parallel to SIRI).
- **Four scored domains (sum 100):**
  - Internal consistency **35** (the core; alpha/omega). Structure/dimensionality
    **folded in here as sub-evidence** for v1, not a separate domain.
  - Item performance **25** (item-total correlations, endorsement/difficulty,
    dead/redundant items).
  - Response quality **20** (completion, missingness, straight-lining, careless
    responding).
  - Score interpretability **20** (distribution health, floor/ceiling, usable
    variability).
- **Sample/N = cross-cutting fence, NOT scored.** When evidence is too thin,
  **label "not enough evidence" rather than force a reliability claim**, at both
  construct and survey level. Critically thin N withholds the number (verdict
  "Insufficient data to judge" + safe descriptive evidence only). Thin construct
  → that construct labeled "not enough evidence". Constructs missing/too thin →
  state limitation, fall back to whole-survey evidence.
- **Reporting unit: construct-first, then roll up.** Order: (1) overall RSSI
  score, (2) domain scores, (3) construct-level reliability, (4) item-level
  warnings inside each construct, (5) overall interpretation band.
- **Bands (interpret-centered):**
  - 85-100: Reliable enough to interpret with confidence
  - 70-84.9: Reliable with minor cautions
  - 55-69.9: Interpret with caution
  - below 55: Not yet reliable
  - fence override: Insufficient data to judge

## Standing constraints (unchanged)
No em dashes in user copy. AI = "ReliCheck Intelligence". Do NOT modify
production surveys/responses tables (new additive tables only). Do not rename
sdsi code. RSSI reads stored dev responses (`survey_dev_response_sessions` +
`survey_dev_answers`). Follow the user's lead on what to measure; honest-fences
stance (refuse to over-claim).

## Next step (await user instruction)
Scope RSSI Phase 1 build to this spine, incrementally (one verified layer at a
time), the way earlier scoring phases rolled out. Likely first layer: a
deterministic RSSI engine over stored responses for a single construct's internal
consistency, before wiring UI. Do NOT start building without the user's go-ahead.
