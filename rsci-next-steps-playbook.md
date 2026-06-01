# RSCI next-steps playbook — paste-ready prompts, in order

Work top to bottom. Run one step, read the reply, then move to the next.
Do not skip ahead; each step assumes the previous one came back clean.

----------------------------------------------------------------------
## STEP 1 — Status check (already in the repo: rsci-status-check.md)
Tell the session to read and run it. This is the assessment-before-action
step. Read the reply before doing anything else.
----------------------------------------------------------------------


----------------------------------------------------------------------
## STEP 2 — Browser verification
The engine is proven in Node. Neither page has rendered against a live
session. Close that before anything structural.

NOTE: both pages are login-gated. The session said it has not stood up the
DB/session. So one of two things has to happen:
  (a) you give the session a way to run the app and reach a logged-in page, OR
  (b) you open the pages yourself, look, and report back (a screenshot is
      enough), and we skip handing browser access to the session.

If (a), paste this and fill in the bracket:

```
Verify both RSCI surfaces render in a browser against a running instance,
not just in Node. Here is how to run the app locally and reach a logged-in
session: [FILL IN: your dev command / URL / login steps].

Drive the demo path on both surfaces and report what actually rendered
(screenshots or a per-state description):
1. rsci.php — load with a sample survey. Confirm two rings (validity,
   reliability), each with its two check-group cards, per-finding dimension
   tags, the fence note, and that the three states (ready / flag / not_ready)
   render and color correctly.
2. survey-builder.php side panel — open it, run assessment on a draft.
   Confirm both dimension scores, the four group cards, the fence note, and
   the badge (weaker dimension) render.
3. Live edge cases: empty survey (both not_ready), clean survey (both ready),
   messy survey (validity drops, reliability holds).

Do not fix anything yet. If something is broken, tell me what and where first.
```

If (b), you run the two pages yourself, screenshot each state, and bring the
results back here or to the session as the basis for Step 3.

----------------------------------------------------------------------
## STEP 3 — Fix only what the browser found (template)
Use only if Step 2 turned up rendering or behavior problems. Paste the
specific problems where marked.

```
From the browser check, these did not render or behaved wrong:
[PASTE the specific problems, one per line].

Fix only those. Do not change the engine logic, the two-and-two rollup, the
alpha fence, or the thresholds. Re-verify the same states in the browser
after the fix and show me. No other changes.
```

----------------------------------------------------------------------
## STEP 4 — RSSI reconciliation (the last structural piece)
Only after the RSCI pages are confirmed rendering. This is what closes the
seam: predicted and observed reading on one shared frame.

```
Reconcile the observed side (RSSI) to share RSCI's frame. Goal: predicted
(RSCI) and observed (RSSI) read on the same two dimensions (validity,
reliability) and the same four check groups (question quality + construct
coverage -> validity; scale strength + survey flow -> reliability), shown
side by side so they are readable together.

Hard rule: shared frame, NEVER shared formula. Neither score may depend on,
calibrate to, or grade the other. RSSI stays a fully independent observed
read computed from data; RSCI stays the design-time read. Do not reintroduce
any predicted-vs-observed accuracy or calibration logic.

In apps/rssi/ and rssi.php:
- Regroup RSSI's current cards (Question Quality, Scale Strength, Reliability,
  Validity) into the same shape: four check groups as inputs, validity and
  reliability as the two rollups.
- Use the same dimension and group names and the same visual treatment as
  rsci.php so the two pages are visually parallel.
- Keep RSSI's observed metrics (actual internal consistency, dropout, item
  performance) feeding the groups; only the framing changes, not the
  measurement.
- Apply the alpha fence on the observed side too: internal consistency counts
  toward reliability only, never validity.

Report what changed and confirm both pages present the same two-dimension,
four-group shape. Do not touch RSCI's engine. No AI narration.
```

----------------------------------------------------------------------
## STEP 5 — AI narration (the parked layer)
Only after everything above is confirmed.

```
Add the AI narration layer on top of the deterministic scores, not replacing
them. For each finding and each dimension, generate a plain-language
explanation and, where useful, a concrete rewrite suggestion. The numbers and
the ready/flag/not_ready states stay computed by the deterministic engine;
the AI only explains and suggests. Keep it additive and visually separated
from the scores. Match how the rest of the app handles API access. Show me
where it renders on one surface before wiring it everywhere.
```

----------------------------------------------------------------------
## DECISIONS THAT ARE YOURS (settle as they come up)
- Thresholds: starting constants are validity 80 / reliability 80 = ready,
  65 = flag floor. Decide whether the predicted bar sits ABOVE the observed
  bar (a safety margin) and what the actual numbers are. These are starting
  lines, not findings.
- Badge framing: current behavior shows the weaker dimension as the headline.
  Confirm that, or choose to show both with no single badge.
- Public route/name for the RSCI standalone page (rsci.php is in place;
  confirm that is the route you want exposed).
- Empty-survey: already decided (both not_ready). No action needed.

## LATER, NOT NOW
- Retune the thresholds once a handful of real surveys have run through both
  RSCI and RSSI and you can see how far designs slip from predicted to
  observed. That gap is what tells you whether an 80 bar leaves enough margin.
  Do not retune on guesses before you have paired results.
