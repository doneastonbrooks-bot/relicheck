# Build direction: ReliCheck Survey Checker Index (RSCI), the pre-deployment mirror of RSSI

## Core principle
A flawed instrument poisons every downstream result. So instrument quality
is assessed before data exists, on the same dimensions RSSI later measures
from data. Garbage in, garbage out is the whole reason this tool exists.

## What this is
RSSI scores an instrument AFTER data (observed). RSCI scores the same
instrument BEFORE data (predicted), using deterministic linters. Same visual
system as RSSI, same "deterministic rules first, AI narration later" pattern.
RSCI and RSSI are siblings: ReliCheck's predicted index and observed index,
each usable on its own.

## Two dimensions, four check groups, clean two-and-two split
Only two dimensions are reported by this tool: VALIDITY and RELIABILITY.
Comparison and predictability are NOT computed here; they live downstream in
outcome analytics and are out of scope for this build.

Four deterministic check groups roll up two into each dimension. No weights,
no primary/secondary. A check group feeds exactly one dimension.

    VALIDITY    = Question quality + Construct coverage
    RELIABILITY = Scale strength   + Survey flow

- Question quality (validity): per-item wording linters — double-barreled,
  leading/loaded, vague quantifiers, absolutes, double negatives,
  reading level, ambiguous or missing stem.
- Construct coverage (validity): purpose-to-item alignment, coverage gaps,
  each construct represented. The validity score comes from content/purpose
  coverage and factor-structure match, never from item correlation.
- Scale strength (reliability): point count, balanced anchors, midpoint,
  labeled points, no overlapping ranges.
- Survey flow (reliability): length, item order, demographic placement,
  open-ended load, estimated completion time and fatigue risk.

## The one fence that must hold
Internal consistency (Cronbach's alpha) and items-per-construct score on
RELIABILITY only. They are computed from a construct's items so they sit
near construct coverage, but they must never raise the validity score. This
prevents the reliable-but-not-valid trap (high alpha read as proof of
validity). Enforce this in the rollup, not just in copy.

## The mapping table (design -> predicted -> observed)
Each row keeps one identity across stages. Heading is fixed; only the mode
changes.

| Check group       | Builder (design)                                              | Assessment (predicted)                                       | Survey analytics (observed)                                  | Rolls up into |
|-------------------|---------------------------------------------------------------|--------------------------------------------------------------|--------------------------------------------------------------|---------------|
| Question quality  | Target the intended thing: single-barreled, neutral, unbiased | Flag double-barreled, leading, loaded, vague, absolute; reading level | Item non-response, odd distributions, item unlike its mates | Validity      |
| Construct coverage| Cover the construct; map every item to purpose; no gaps       | Purpose-to-item alignment, coverage gaps, each construct present | Factor structure / dimensionality matches intended constructs | Validity      |
| Scale strength    | Precision: point count, balanced anchors, midpoint, labels    | Check balance, midpoint, labels, point count, range overlap  | Variance, floor/ceiling, full use of points, response styles | Reliability   |
| Survey flow       | Length, order, demographic placement, open-ended load         | Estimate completion time, fatigue risk, priming/order risk   | Dropout, completion time, drop-off points, late-item decline | Reliability   |

## Predicted lines up with observed
Each check group produces a 0-100 sub-score. The four sub-scores roll up
two-and-two into predicted validity and predicted reliability = the
pre-publish score. RSSI must report observed validity and observed
reliability built from the SAME four check groups, so the predicted and
observed cards line up one to one. Reconcile RSSI's current cards
(Question Quality, Scale Strength, Reliability, Validity) to this scheme:
show the four check groups as inputs and validity + reliability as the two
rollups, on both the predicted and observed sides.

## Architecture (confirm against the repo)
- One engine = one JS module, deterministic, in-browser, no API key needed.
- Two surfaces from that one module:
  1. a standalone assessment page (mirror of rssi.php), and
  2. an embedded side panel inside survey-builder.php.
- Every part of the larger system is both an entry point and an exit point:
  a user can start here with an instrument made anywhere and leave with a
  portable artifact. So the standalone page is required, not optional.
- AI narration (plain-language fixes, rewrites) layers on top later. Ship
  deterministic scoring first.

## Repo facts to re-verify before coding
- rssi.php (landing: ring score + metric cards), rssi-upload.php, rssi-report.php.
- survey-builder.php is currently a plain split-pane editor with no assessment.
- /survey-ai-builder.php is a TBD placeholder route.
- Legacy app.html / app-2026 holds Purpose Checker, Construct Mapper,
  Item Generator. Reference only, not the new studio.

## First task
Scan the repo and confirm the structure above. Then build the deterministic
assessment engine module: the four check groups, the two-and-two rollup,
the alpha fence. Wire it into the standalone page and the builder side panel.
No AI yet. Then ask before adding narration.
