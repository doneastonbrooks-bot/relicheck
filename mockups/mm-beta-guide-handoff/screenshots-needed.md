# Screenshots Needed for the Beta Guide

The guide currently has three placeholder image blocks. The HTML page is wired to look for the PNGs at these paths in the project root (siblings of `mm-beta-guide.html`):

```
/mm-guide-phase1.png
/mm-guide-phase2.png
/mm-guide-phase3.png
```

If the file is present, it shows up. If not, a striped placeholder appears in its place. So the workflow is: take the screenshot, save it with one of those exact names in the project root, and refresh the HTML.

## How to reach each view

Sign in to relicheck.com, then navigate to `app-2026.html?draft=1#/mm`. The "MM Studio (DRAFT)" link in the sidebar takes you to the same place. From the Studio dashboard:

### `mm-guide-phase1.png` — Phase 1: Get data in

1. Click any project in the Recent Projects list (or use "Start a new project" to make one)
2. Click the **Step 1. Get data in** button in the three-step strip
3. Scroll until the whole page fits in the viewport: walkthrough card at top, the four stat cards, the three-step strip, and the "Add data to this project" panel with the three intake routes (Upload a file / Link an existing survey / Paste rows)
4. Capture the full content area starting from just under the top app bar through the bottom of the intake routes

**What should be visible:**
- "How this works" walkthrough card with three numbered phases
- Stat cards (RESPONSES, CATEGORIES, CODED, STATUS)
- Three-step strip with Step 1 marked complete (or active, depending on the project state)
- "Add data to this project" panel with the three buttons
- The "Open the beta user guide ›" link inside the walkthrough card

### `mm-guide-phase2.png` — Phase 2: Builder

1. From the same project, click the **Step 2. Builder** button
2. Wait for the page to settle
3. Frame the shot to show: the Quality Brief card, the Qualitative-to-Quantitative Builder with the three mode cards (Hybrid recommended / Auto-Structure / Guided), and Back / Next: Settings buttons

**What should be visible:**
- "Open-Ended Response Quality Brief" card with "Run quality check" button on the right
- "Qualitative-to-Quantitative Builder" panel with three mode radio cards
- Hybrid card highlighted as recommended
- The "Builder · Step A of C  Pick a mode" eyebrow at the top

### `mm-guide-phase3.png` — Phase 3: Analyze and report

This is the most important screenshot because it shows the Recommended Order strip we built into the guided walkthrough.

1. Open the `Untitled Mixed-Methods project` with **52 responses and 6 categories** in the Recent projects list (it was built during the screenshot session). If you do not see one with those exact numbers, run the Builder on any 30+ row project first.
2. The page lands on Step 3 automatically when Categories > 0
3. Scroll so the top is the project title

**What should be visible:**
- Walkthrough card at the top (Phase 1 ✓, Phase 2 ✓, Phase 3 active)
- Stat cards (52 RESPONSES, 6 CATEGORIES, 0 CODED, active STATUS)
- Three-step strip with Step 3 active
- **Recommended Order strip:** "1 Categories ✓, 2 Dataset, 3 Analysis, 4 Joint Display, 5 Integration, 6 Strength Check, 7 Report" with "Next: Dataset — …" prompt on the right
- The eight-tab row below: Categories, Dataset (active), Analysis, Joint Display, Integration, Strength Check, Report

## Sizing & framing

- Browser at 1440 wide or higher to give the studio room to breathe
- DPR 2 (Retina) for the PNGs so they hold up in print
- Capture the content area only; the macOS chrome and browser tabs are not part of the deliverable
- PNG, not JPEG; PNG handles the soft UI gradients cleanly

## If you want a fourth screenshot

The **Studio dashboard** view is a strong "first impression" frame (purple hero with the welcome banner, Quick Actions grid, Recent Projects list). Save as `mm-guide-dashboard.png` if you want to use it as an extra cover or intro visual. The current guide does not reference this file, but adding a 4th image slot is a 5-minute change.

## Synthetic data for screenshots

If you want to build out a fresh project for clean screenshots, use the bundled CSV:

- File: `mm-studio-demo-engagement.csv`
- 51 rows of a workplace engagement survey with: participant_id, role, tenure, engagement_score (1-5 Likert), open_comment
- Pre-tuned so the Builder will surface seven coherent themes (career growth, manager support, workload, tools and process, compensation, remote and flexibility, team culture) plus enough low-quality rows to make the Quality Brief light up

Upload it via the "Upload a file" route in Phase 1. Set the pathway to **scores + comments** so the Phase 3 view includes the Score-to-Theme and Alignment tabs.

## Final delivery

Once the three PNGs are saved as `mm-guide-phase1.png`, `mm-guide-phase2.png`, `mm-guide-phase3.png` in the relicheck project root, the HTML guide auto-picks them up on next page load. To regenerate the PDF with the new screenshots embedded, the build script lives in the chat history and can be re-run on request.
