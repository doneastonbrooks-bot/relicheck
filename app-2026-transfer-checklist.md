# app-2026 Transfer Checklist

Living plan for moving the rest of classic `app.html` into `app-2026.html`. Built after Phase 133.

## Status legend

- [x] **Native** — works end-to-end in app-2026 without bouncing to classic
- [~] **Partial** — works but with missing sub-features or rough edges
- [ ] **Missing** — bounces to classic, stubbed, or doesn't exist yet

## Effort legend

- **S** (Small) — single render function, few hours
- **M** (Medium) — one route or one sub-feature with new state, half a day
- **L** (Large) — multi-day; new entity, new endpoints, or many UI surfaces
- **XL** (Extra Large) — multiple phases worth of work

## Priority legend

- **P0** — blocks daily use; user hits it every session
- **P1** — common workflow but workaround exists (classic still works)
- **P2** — polish, chrome consistency, less-common workflow
- **P3** — edge cases, advanced features, nice-to-have

---

## What's shipped (Phases 118-133)

For context. Don't re-do these.

- [x] Phase 118: Distribute restructure (Analyze chrome + 5-tab strip + Method tile hub)
- [x] Phase 119: Web Link / QR Code / Embed native panels
- [x] Phase 120: Narrator primitive + McDonald omega tile + Can I Use verdict card
- [x] Phase 121-123: Social / Calendar / API panels + Pulse Schedules + Slack/Teams Channels tabs
- [x] Phase 124: Description / Validity / Open-Ended / Compare / Pre-Post narrators
- [x] Phase 125: Subgroups / Predictors / Key Drivers / MLM / IRT narrators
- [x] Phase 126: Mediation / Moderation / CFA / Invariance narrators
- [x] Phase 127: Survey Readiness tab
- [x] Phase 128: Response Quality tab
- [x] Phase 129: Completion & Missing Data tab
- [x] Phase 130: Equity Gaps tab
- [x] Phase 131: Per-tab PDF export wiring
- [x] Phase 132: Native Builder editing (item editor, purpose editor, save)
- [x] Phase 133: PDF redesign to a published-report look

Tab strip is now 16 analytics tabs. All seven Distribute method tiles are native. Eleven of twelve analytics tabs have narrators (Strength Index uses Can I Use instead, by design).

---

## Remaining work by sidebar surface

### Home (`#/home`)

Native. Greeting, four quick-action cards, Recent projects, Plan usage. No known gaps.

- [ ] **P3 S** Add a Recent activity feed (last 14 days of completions, like classic Home Phase 37b).
- [ ] **P3 M** Customizable home grid (4-card layout the user can rearrange; classic `users.prefs` JSON column).

### Projects (`#/projects`)

Native rendering, but several Phase 37a features regressed.

- [ ] **P1 M** Folders (move surveys into folders, filter by folder).
- [ ] **P1 S** Favorites / pinned surveys.
- [ ] **P1 S** Archive view (soft-delete with restore).
- [ ] **P1 M** Bulk actions (multi-select, archive, move, delete).
- [ ] **P2 S** Sparkline per row (14-day completion trend).
- [ ] **P2 S** Health pill per row (alpha band, SSI band).
- [ ] **P2 S** Sticky filter bar with q + type + sort + page.

### Create / Upload (`#/create`)

Mostly handoff tiles. Only "Upload a dataset" is native.

- [ ] **P0 L** Create a survey from scratch (in-app builder onboarding flow + first item).
- [ ] **P1 L** Create a test (new Tests entity; see below).
- [ ] **P0 S** "Start from a template" tile should route to `#/templates` (currently bounces).
- [ ] **P1 M** Generate with AI (calls `/api/ai/generate-items.php` or `generate-survey.php`).
- [ ] **P1 M** Upload survey responses (CSV/XLSX importer, column mapping, dedupe).
- [ ] **P2 M** Upload test responses (same shape, plus answer-key row).
- [ ] **P1 M** Import survey definition (.qsf and .docx) via `extract-survey.php`.

### Builder (`#/builder/<id>`)

Phase 132 made it editable. Remaining gaps:

- [ ] **P1 M** Drag-and-drop reorder (HTML5 DnD or library).
- [ ] **P1 L** Skip Logic editor (per-question conditional show/hide).
- [ ] **P2 M** Design tab (theming: primary color, logo, fonts; persists on settings).
- [ ] **P2 S** Distribute tab inside Builder (routes to `#/distribute/<id>`).
- [ ] **P1 M** Settings tab inside Builder (title, description, language, time limit, anonymity, intro text).
- [ ] **P2 S** AI: Construct Mapper inline (`/api/ai/map-constructs.php`).
- [ ] **P2 S** AI: Purpose Checker inline (`/api/ai/check-purpose.php`).
- [ ] **P2 S** AI: Improve Question inline (`/api/ai/improve-question.php`).
- [ ] **P2 S** AI: Check Question inline (`/api/ai/check-question.php`).
- [ ] **P3 M** Per-question randomization controls.
- [ ] **P3 M** Branching arms (multi-path surveys; classic `renderArmsEditor`).

### Analyze (`#/analyze/<id>`)

16 tabs native. Strong coverage. Remaining gaps are around polish.

- [ ] **P2 M** Charts in PDF export (rasterize SVG ring, scree plot, factor loadings heatmap, JN plot, IRT curves).
- [ ] **P2 M** Full Report compile (single PDF across all tabs from Strength Index's action bar).
- [ ] **P3 S** Compare narrator: pass full Cohen-d CI fields (currently null).
- [ ] **P3 S** Pre-Post narrator: pass test statistic (currently null because string-formatted).
- [ ] **P3 S** Validity narrator: pass Bartlett's p when Stats library gains the test.
- [ ] **P3 S** Predictors narrator: pass `std_beta` when `fit.stdBeta` is added.
- [ ] **P3 S** Subgroups narrator: pass `d_ci_low` / `d_ci_high` when computed.

### Distribute (`#/distribute/<id>`)

Thread is closed after Phases 118-123. No known gaps inside Distribute proper.

- [ ] **P3 S** Web Link panel: per-link UTM helper.
- [ ] **P3 M** Calendar panel: send-as-Microsoft-Outlook variant in addition to .ics.

### Reports (`#/reports`)

Currently localStorage-only with placeholder content. The chrome is polished; the substance is not.

- [ ] **P0 L** Server-backed reports table (new `/api/reports/*` endpoints: list, create, get, update, delete).
- [ ] **P1 L** Real report content (pull live analytics from the source survey, not hardcoded prose).
- [ ] **P1 M** Share link with password + expiry.
- [ ] **P1 M** Schedule a report (auto-regenerate weekly/monthly).
- [ ] **P1 S** "Draft report paragraph" button on Strength Index that calls `/api/ai/draft-report.php` and writes into a new report.
- [ ] **P2 S** Report templates (Executive summary, Methods write-up, Findings briefing).

### Templates (`#/templates`)

Native. Validated scales + starter templates. No known gaps.

### AI Tools (`#/ai`)

Catalog only. Every tool bounces to classic.

- [ ] **P2 M** Per-tool standalone workspaces for the tools that make sense (Construct Mapper standalone, Purpose Checker standalone, Generate Items standalone).
- [ ] **P3 M** AI usage dashboard (credits used, history of tool calls).

### Team (`#/team`)

Native. No known gaps in this session.

### Account (`#/account`)

Profile + Password native. Several rows still bounce to classic.

- [ ] **P1 M** Integrations management (Google connect/disconnect, Qualtrics, Custom domain).
- [ ] **P1 L** Webhooks management (Phase 30 in classic; list, create, secret rotation, event picker).
- [ ] **P1 M** Two-factor authentication setup flow.
- [ ] **P2 M** Single sign-on configuration (Business/Enterprise only).
- [ ] **P2 S** Active sessions list (revoke individual sessions).
- [ ] **P2 M** API tokens (create, list, revoke).
- [ ] **P2 S** Danger zone: delete workspace flow.

### Help (`#/help`)

Stub. Doesn't exist as a real surface.

- [ ] **P2 L** Help center index (links to documentation, FAQ, contact support).
- [ ] **P3 M** In-app search across help articles.

---

## Missing top-level entities (need sidebar entries)

### Tests entity

Classic has the entire test pipeline: builder, take-test public flow, analytics dashboard with Overall / Reliability / Difficulty / Quality / Distractor / Skill / Skill Heatmap / Recommendations / Pre-Post / Item Health cards.

- [ ] **P1 L** Sidebar entry + `#/tests` route.
- [ ] **P1 L** Test Builder (CSV upload with answer-key row, or in-app authoring).
- [ ] **P1 XL** Test Analytics dashboard (port the 10 cards from classic; each is its own renderer).
- [ ] **P2 M** Test sharing (public link to take the test).

### Datasets list view

Datasets currently surface as rows in Projects via the adapter. No dedicated view.

- [ ] **P2 M** Sidebar entry + `#/datasets` route.
- [ ] **P2 S** Dataset detail page (column mapping, schema view).
- [ ] **P2 M** Re-upload to refresh a dataset.

### Responses list view

Per-respondent view of submitted responses.

- [ ] **P2 M** Per-survey responses table with filter / search / export.
- [ ] **P3 S** Per-respondent drill-down (single response detail).

### Webhooks management

Phase 30 in classic. Currently routed to via Account integrations row but the destination is classic.

- [ ] **P1 M** Native `#/webhooks` or surface inside Account.
- [ ] **P1 S** List webhooks, create, signing secret rotation.
- [ ] **P1 S** Event picker (response.created, survey.published, etc.).
- [ ] **P2 S** Test webhook (fire a sample payload).
- [ ] **P3 S** Delivery log per webhook.

### 360 Panels (Phase 129 in classic)

Sub-feature that binds an existing survey to subjects and evaluators.

- [ ] **P2 L** Sidebar entry + `#/panels` route.
- [ ] **P2 L** Panel detail (subjects list, evaluators per subject).
- [ ] **P2 M** Subject report (per-subject aggregate from evaluators).

### Suites (Phase 133 in classic)

Workflow packages: 360, HR, Pulse, Program Eval, Education, Researcher, CX.

- [ ] **P2 L** Sidebar entry + `#/suites` route.
- [ ] **P2 M** Suite detail (template list + bound surveys).

### Manager Dashboard / Group rollups

Classic has `renderManagerDashboard` and `renderGroupRollupsCard`. Cross-survey roll-up view for org admins.

- [ ] **P3 L** New `#/manager` route for org-level analytics.
- [ ] **P3 M** Group rollup card (per-team aggregate).

### Survey Details / Settings page

Classic `view-details` renders a per-survey settings page. In 2026 this is partly inside Builder Settings tab but the standalone page is missing.

- [ ] **P2 M** Native settings page or move it fully inside Builder.

---

## Cross-cutting threads

These don't fit a single route. They affect many surfaces at once.

### AI features still bouncing to classic

- [ ] **P2 S** Wire `extract-survey.php` (paste survey or upload .qsf) into Create / Upload.
- [ ] **P2 S** Wire `generate-items.php` into Builder's Add item flow.
- [ ] **P2 S** Wire `recommend-scale.php` into Templates.
- [ ] **P2 S** Wire `draft-report.php` into Reports.

### Charts and visualizations

- [ ] **P2 M** Rasterize SVG charts into PDF (html2canvas or canvas drawer).
- [ ] **P3 M** Print stylesheet so the analytics tabs print cleanly without using PDF export.

### Mobile / responsive polish

- [ ] **P2 M** Tab strip overflow handling at narrow widths.
- [ ] **P2 M** Touch targets in tables (Send tab checkboxes, edit/delete buttons).
- [ ] **P3 M** Bottom sheet pattern on phones for modals.

### Accessibility

- [ ] **P2 M** ARIA roles on tab strip, modals, tone pills.
- [ ] **P2 S** Keyboard navigation across tabs (arrow keys).
- [ ] **P3 M** Color-blind safe verification across all tone-pill palettes.

### Code health

- [ ] **P3 M** Extract narrator card chrome into a CSS class set (remove inline-style helpers).
- [ ] **P3 M** Prune orphaned `.dist-*` CSS (left in place during Phase 118 redesign).
- [ ] **P3 L** Split `app-2026.html` into modules if it crosses 25,000 lines (currently ~18,500).

---

## Recommended order of attack

A pragmatic sequence that closes the biggest user-facing gaps first.

1. **Reports backend** (P0 L): server-backed reports with real content. Closes the "polished but fake" gap users hit immediately after running an analysis.
2. **Create a survey native** (P0 L): unlocks the in-app onboarding path so users don't need classic for new projects.
3. **Builder Settings + Skip Logic** (P1 L+L): completes Builder to "feature-complete" status.
4. **Tests entity** (P1 XL): single largest missing piece. Classroom test workflow is currently classic-only end-to-end.
5. **Webhooks + Account Integrations** (P1 M+M): pulls the last common admin tasks out of classic.
6. **Projects polish** (P1 M): folders, favorites, archive, bulk actions, sparklines.
7. **Upload pathways** (P1 M+M): survey responses, test responses, .qsf import.
8. **Reports schedule + share** (P1 M+M): once backend exists, layer on share links and recurrence.
9. **Charts in PDF + Full Report compile** (P2 M+M): polish the export experience.
10. **AI tool standalone workspaces** (P2 M): catalog page becomes a real workbench.
11. **Datasets list / Responses list / Manager Dashboard / Panels / Suites** (P2-P3 batch): less common but classic users will miss them.
12. **Accessibility + mobile + code health** (P2-P3 batch): cross-cutting polish.

---

## How to use this checklist

- Mark items `[x]` as they ship. When you start a phase, paste the matching phase number into the line so future-you can find the memory file.
- The priority tiers are guides, not rules. Bump anything you hear about from a user.
- The order of attack is the recommended sequence, not gospel. Reorder when a specific user request lands.
- When a P0 / P1 ships, give the file a quick top-to-bottom pass to retag anything that became easier / harder than expected.
