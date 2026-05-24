# ReliCheck — Where the platform meets, drifts from, and could push beyond the purpose statement

*Date: 2026-05-23 · Honest read after a full day of touching every surface.*

## The purpose, in one sentence

People collect data because something matters; ReliCheck helps them know whether that data is strong enough to act on. Trust in the questions. Trust in the analysis. Trust in the interpretation. Trust in the decision that follows.

## Where the platform IS holding the purpose

These are working. Don't dilute them.

- **The 6-stop MM Studio pipeline** (Set Up → Structure Data → Choose Design → Analyze → Integrate Evidence → Defend the Report) is the cleanest UX expression of the purpose anywhere on the platform. It IS the connected process the vision document promises — quality, analysis, interpretation, and reporting flowing as one journey, not four tools.
- **The Strength Index composite** (e.g. 73/100 "Usable") is the most differentiated UI artifact on the entire site. It's a credibility score, not a feature dashboard. No competitor has anything like it.
- **The Readiness tab** running before data is collected, flagging missing constructs and grouping variables — that's the "before you collect, check the design" stance, hard-coded.
- **The Quality tab** (straight-lining, duplicate vectors, short open-ended) is the platform actively asking "can this be defended" — concrete, methodological, non-cosmetic.
- **The MM Studio sidebar teaching content** we added tonight expresses the *teach* pillar — hand-authored, method-rule-driven, AI-optional.
- **Graceful "not eligible" / "AI not configured" / "not paired"** messages instead of silent failures. The platform tells the user what it can and can't support. That's defensibility as a default behavior.

## Where the platform DRIFTS from the purpose

These are the gaps. Each is fixable; each matters.

### 1. The *anonymous* homepage doesn't carry the positioning weight
**Important distinction:** there are two first screens with two different jobs.
- The **logged-in dashboard** ("What would you like to work on today?" + Quick Actions) is for someone who's already chosen ReliCheck and wants to start work. That line is *good* on this surface — warm, personal, respects the user's agency. Keep it.
- The **anonymous homepage** at relichecksurvey.com (for someone who's never heard of ReliCheck) is where the positioning weight belongs. That's the surface that needs the moment-of-doubt language: *Your survey is in. The numbers look right. Are they strong enough to support the decision you're about to make?* — not a feature pitch.

The original drift in this analysis was applying the positioning critique to the dashboard. Correction: dashboard is fine; the *anonymous landing page* is the one that needs to do the positioning work.

### 2. The Strength Index is buried as a tab
The composite credibility score is the most distinctive thing the platform does. It currently sits as the first tab in a strip of 13. It should be the **headline outcome** of any analysis — visible on every project card, in the project header, in the report. Right now the user has to know to click it. That undersells the differentiator.

### 3. "Trust" isn't visualized as a journey
The vision hammers trust in **questions → analysis → interpretation → decision**. The interface doesn't show this as a progressive build. There's no checkpoint between Analysis and Report that says "based on what's been checked so far, here's what this conclusion can carry." That checkpoint is the heart of the platform's stance — it should be a UX object, not a paragraph in the vision document.

### 4. Three studios that aren't actually three studios
The vision names Mixed-Methods, Test & Item Analysis, and 360 as three studios with one philosophy. The reality:
- **MM Studio**: closed beta, real workflow, three-column shell ✓
- **Test & Item Analysis**: doesn't have its own entrance — its analyses live as tabs inside Analyze
- **360**: appears as "Panels (360)" in the sidebar but doesn't read as a studio in the way MM does

The vision describes three doors; users currently see one door (MM Studio) and a tab strip pretending to be the other two.

### 5. AI's visual weight contradicts the philosophy
You said: AI is supplemental, not the driver. The interface still gives AI panels prominent right-rail real estate on every tab. When the API key is configured, those panels expand to large narrative summaries. Visually, AI gets equal or greater weight than the methodological output. That sends a signal opposite to your stated stance.

### 6. The labels are task-based, not purpose-based
The main left nav reads as a SaaS feature list: Home, Projects, Datasets, Tests, Create/Upload, Builder, Analyze, Distribute, Reports. Compare to a purpose-based framing:
- "Analyze" → *Check your evidence*
- "Reports" → *Defend your decision*
- "Builder" → *Design your instrument*

The current names are accurate; the alternative names are *true to the platform's stance*. Naming is a values choice.

### 7. The 6-stop pipeline doesn't travel
MM Studio teaches users a connected workflow. The main Analyze view doesn't — it's a tab strip. The same pedagogical scaffolding that makes MM Studio coherent should be applied to the main site. A first-time researcher coming in through Analyze should see the same journey logic, not a feature toolkit.

### 8. The report layer doesn't express defensibility
The vision says move from "I need a report" → "I can defend this conclusion." Every report ReliCheck produces should carry a **defensibility panel** at the top: what this conclusion can support, what it cannot, what would need more data. Right now reports are competent; they aren't yet *the move from "I have a report" to "I can defend my decision."*

### 9. Vocabulary inconsistency
Across surfaces I saw "Verdict unavailable," "Reliability is questionable," "Strength Index," "Recommended inferential tests," "Statistically significant." Some of this is research vocabulary (good, sounds like methodology). Some is product vocabulary ("Verdict unavailable" sounds like a slot machine). A house voice — calibrated to researcher, with confidence-grade language — would make the platform feel like one thing.

## Strong recommendations — to hold to the purpose

These protect what's working. Do them defensively, even if nothing else changes.

1. **Promote the Strength Index everywhere.** Project cards should show it. The project header should show it. Reports should open with it. It's your most distinctive artifact; treat it that way.
2. **Audit AI panel real estate.** Decide: should AI summaries be optional/collapsible, smaller, opt-in? Today they're prominent. Make a deliberate choice rather than letting it stay where Cowork left it.
3. **Keep the MM Studio sidebar teaching pattern.** Don't let future AI tools "simplify" the methodological structure away. The sidebar IS the curriculum.
4. **Hold the 6-stop framing.** Don't let it become 4 stops or 8. The number and the order encode methodological commitments.
5. **Keep "not eligible" graceful failures.** Future temptation will be to make everything green-light all the time. Resist. Honesty about what each test can and can't run on the data IS the brand.

## Strong recommendations — to push the field

These are how the platform moves from "credible product" to "shifts the category." These are bigger moves; treat them as the roadmap.

1. **Make defensibility a first-class UX object.** Build a **Defensibility Panel** that appears on every analysis output, naming exactly what claim can be supported by the evidence, what the limitations are, and what additional data would strengthen it. This is the visualization of the trust-journey the vision document describes. No competitor has this because no competitor's product is *about* this.

2. **Build the three studios as three studios.** Test & Item Analysis Studio and 360 Studio should have the same three-column shell, same workflow ribbon, same teaching sidebar that MM Studio has. The vision says one philosophy, three doors. Build three doors.

3. **Rename the nav by purpose.** Even if just on a marketing landing page first: "Check your evidence" beats "Analyze." "Defend your decision" beats "Reports." This is positioning expressed through interaction. SAP and Salesforce don't call their products "ERP" or "CRM" in the UI; they use the user's verbs. ReliCheck can do the same.

4. **Add a "Defensibility Score" alongside the Strength Index.** Where Strength Index measures the *instrument*, Defensibility Score measures the *claim*. "This claim, on this data, with these methods, can be defended at level X." Make researchers' work *literally* easier to defend in front of reviewers, dissertation committees, leadership.

5. **Publish methodology, not features.** Most platforms ship product. ReliCheck could ship *methodological standards*. Each studio could have a published, citable framework document — what counts as defensible reliability, defensible factor structure, defensible joint display. That's how you push the field, not just the product. Researchers cite frameworks; that's how a tool becomes a standard.

6. **The MM Studio desktop companion you mentioned earlier** is the right move for a different reason than I think you originally framed it: it positions ReliCheck not as "SaaS for surveys" but as **"methodology infrastructure"** — something a researcher installs because they need it for their work, like SPSS or R, not because their HR team picked it. That's a category move, not a product move.

7. **The teaching panel should grow into a Curriculum.** Tonight we added 2-3 sentence teaching content per sidebar item. The natural evolution: each item could have a 3-minute video, a downloadable handout, a citable framework. The platform doesn't just give methodology — it teaches methodology. That's the *teach* pillar from your vision, scaled up.

## One sentence positioning that follows from this analysis

> *ReliCheck is for the moment after you have data, before you have a decision — when you need to know if your evidence is strong enough to defend.*

Every choice — naming, hierarchy, AI weight, report framing — can be tested against that line. If a feature doesn't help a researcher get from "I have data" to "I can defend a decision," it's not core to ReliCheck. That's both the discipline and the positioning.

---

This analysis is committed to the repo. We can pull it up tomorrow when you're ready to act on any of these.
