# Correction for the RSCI build: threshold model, not prediction

## The core correction
RSCI is NOT a predictor of RSSI, and it is NOT a comparison against the
eventual observed score. RSCI scores the instrument against fixed design
standards. Its only job is to surface as much validity and reliability error
as can be caught at design time, before any data exists.

Do not score RSCI by how close it lands to what RSSI later computes. The two
read the same target (instrument quality) but from different information.
RSCI sees only what is fixed at design time (wording, structure, scale
construction, coverage). It cannot see what administration adds later (weak
sample, low motivation, careless responding, a population that reads items
differently). Those factors almost always pull observed quality DOWN from the
design ceiling. So a design score runs optimistic by construction, and the
predicted score sitting ABOVE the observed score is the expected state, not
an error.

## Remove this if it exists anywhere
Any logic that calibrates, tunes, or grades RSCI against RSSI:
- predicted-vs-observed accuracy tracking,
- "calibration loop" / retraining RSCI to agree with field results,
- any metric that treats the predicted-minus-observed gap as RSCI being wrong.

This runs backwards. Teaching RSCI to match the field means teaching it to
predict pessimistically, which destroys the one thing it is for: an honest
read of the design on its own terms. The gap between predicted and observed
is a finding about the sample and administration conditions (the environment
layer), not a demerit against the instrument or against RSCI.

## What RSCI is, stated plainly
- A deterministic linter that scores the instrument against design thresholds.
- Two dimensions only: validity and reliability (the two-and-two rollup and
  the alpha fence stay exactly as built).
- It maximizes error detection at design time. Catch every design-level
  validity and reliability problem the rules can see, and report it.
- It makes NO attempt to estimate the number RSSI will produce.

## The one decision to set: pass thresholds
RSCI needs explicit pass-lines, the points where predicted validity and
predicted reliability count as design-ready. Two defensible options:

1. Predicted pass-line set ABOVE the observed pass-line (a safety margin).
   Rationale: since real conditions erode quality, require a design to clear
   a higher bar so it can lose ground in the field and still land acceptable.
2. Same pass-line for both, accepting that passing predicted is a weaker
   promise than passing observed.

Pick one deliberately. Default suggestion: option 1, a margin, because the
slippage is expected and one-directional. Set the actual numbers with me; do
not hardcode a guess.

## Relationship to RSSI (for the reconciliation pass)
When rssi.php is reconciled to the same four-group / two-rollup shape, keep
RSSI as the independent observed read. Show predicted (RSCI) and observed
(RSSI) side by side on the same two dimensions so they are readable together,
but DO NOT make either score depend on, calibrate to, or grade the other.
They share a frame, not a formula.

## Also still open (unchanged from prior note)
- Empty-survey rule: revisit. A survey with no items should not show a
  passing score on either dimension (current behavior returns validity 100,
  reliability 50). Suppress or cap both until at least one item exists.
- Browser verification: neither page has rendered against a live session yet.
- No AI narration until the above is settled.
