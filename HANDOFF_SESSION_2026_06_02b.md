# Session Handoff — 2026-06-02 (afternoon)

## What this session accomplished

### 1. Domain templates for SIRI (Item 5 from previous handoff)

Four new domain templates added to `api/dev/_dev_common.php` (`sds_seed_system_templates`):

| Slug | Category | Name | Constructs | Items |
|---|---|---|---|---|
| `t-360-comp` | 360 Feedback | Competency-Based 360 | Communication, Decision Making, Developing Others, Accountability, Integrity | 25 Likert + 3 open |
| `t-prog-eval` | Program Evaluation | Program Evaluation Survey | Goal Attainment, Implementation Fidelity, Participant Experience | 15 Likert + 3 open |
| `t-hr-climate` | HR / Organizational | Workforce Climate Survey | Org Climate, Inclusion, Leadership Trust, Workload & Wellbeing | 20 Likert + 2 open |
| `t-item-review` | Assessment | Test Item Review (SME Panel) | Content Accuracy, Item Clarity, Bias Review, Difficulty Calibration | 16 Likert + 2 open |

**Key fix:** The seeder's early-return guard (`if ($count > 0) return;`) was removed. INSERT IGNORE now handles idempotency per slug, so new templates can be added to existing databases without a DB reset. This affects all future template additions.

Section Text dividers group items by construct inside the builder.

---

### 2. Studio template contract locked

A formal contract comment was written into `_analysis_studio_v4_shell.php` (lines 30–56) defining the required structure every studio must follow:

```
1. UNIFORM HEADER  — logo left (70px), project context, RSSI stub, avatar
2. START           — data intake
3. OVERVIEW        — data review gate
4. CONSTRUCTS      — studio-specific pipeline steps (via BOOT.pipeline)
5. REPORT          — shared report system (stub; designed outside)
6. UNIFORM FOOTER  — ReliCheck logo left, SIRI popup, RSSI popup, data count right
```

Logo height standard: **70px for all studio wordmarks**. All long logos (MM, DA, IS, 360, TIA, SIRI, SS) are 2172 × 724 px at 72 dpi — built identically, display identically at 70px.

---

### 3. Studio header + footer plug-ins (NEW files)

Two new self-contained JS plug-ins created at `apps/studio/`:

#### `apps/studio/studio-header.js`

Renders into `<div id="studioHeader"></div>`.

**API:**
```js
StudioHeader.init({
  logoSrc,        // studio long wordmark path
  logoAlt,        // alt text
  logoHeight,     // px (default 70 — do not override unless special case)
  projectLabel,   // project name shown in context pill
  projectLive,    // bool → green dot when true
  projectsUrl,    // "All projects" link href
  initials        // two-letter user avatar
})
StudioHeader.setProject(label, live)     // update context pill after init
StudioHeader.loadRssiStub(surveyPid)     // fetch rssi-check.php + show badge + notify footer
StudioHeader.setRssiStub(data)           // show badge from already-known data + notify footer
window.loadRssiStub(pid)                 // alias kept for platform-shell pages (360, TIA)
```

The RSSI badge has three visual states: `rssi-confident` (green, pct ≥ 85), `rssi-developing` (amber, pct 55–84), `rssi-withheld` (gray). Hidden when no RSSI data exists.

Both `loadRssiStub` and `setRssiStub` automatically call `StudioFooter.setRssiInfo()` if StudioFooter is present — header badge and footer popup share the same data from one call.

#### `apps/studio/studio-footer.js`

Renders into `<div id="studioFooter"></div>`.

**Layout:** `[ReliCheck logo — left]  [⚡ SIRI]  [⊙ RSSI]  [N rows · N vars — right]`

**API:**
```js
StudioFooter.init()                      // no arguments
StudioFooter.setSiriInfo({ score, band, link })
StudioFooter.setRssiInfo({ score, pct, band, withheld, tier, link, has_rssi })
StudioFooter.setDataInfo(rows, vars)     // update right-corner count
StudioFooter.showChip(text)              // legacy alias
StudioFooter.hideChip()
```

SIRI and RSSI buttons open popup cards above the dock. Popup shows score + band + "View" link if data is loaded; "No score yet / Go to tool" otherwise. Only one popup open at a time; closes on outside click or ×.

The dock has `z-index:10` so popups layer correctly above body content.

**What the footer does NOT contain:** Upload button, Saved button, navigation links. Those belong in the Start step.

---

### 4. RSSI check endpoint

`api/dev/rssi-check.php` — lightweight GET endpoint.

```
GET /api/dev/rssi-check.php?project_id=N   (N = survey_projects.id, caller-owned)
Returns: { ok, has_rssi, score, pct, band, withheld, tier, link }
```

Used by `StudioHeader.loadRssiStub()`. Link routes to `rssi-app.php?project_id=N`.

---

### 5. Studio wiring status

| Studio | Header | Footer | RSSI badge | SIRI popup data | RSSI popup data | Data count |
|---|---|---|---|---|---|---|
| **Descriptive** | `StudioHeader.init()` in shell | `StudioFooter.init()` in shell | `loadRssiStub(surveyPid)` via `applyDataset` | — (no score yet) | fetched when SIRI data loaded | `showChip()` in `applyDataset` |
| **Inferential** | same as Descriptive | same | same | — | same | same |
| **MM Studio** | `StudioHeader.init()` + design-switch injected | `StudioFooter.init()` | `setRssiStub()` from BOOT.scores | `setSiriInfo()` from BOOT.scores.siri | via `setRssiStub` cross-notify | `setDataInfo()` from BOOT.rawinfo |
| **360 Studio** | `_platform_shell_header.php` (has `#tbRssi` stub + `window.loadRssiStub` alias) | `_platform_shell_footer.php` — **NOT wired** | not wired | not wired | not wired | not wired |
| **TIA Studio** | same as 360 | same — **NOT wired** | not wired | not wired | not wired | not wired |

**360 and TIA are the remaining gap.** They need `#studioHeader` + `#studioFooter` divs, `StudioHeader.init()` and `StudioFooter.init()` calls, and their platform shell includes replaced or wrapped. Both are unclear status — confirm before doing RE infra work on them.

---

### 6. MM Studio design-switch preservation

MM Studio has a studio-specific design picker pill (`#designSwitch` — Convergent / Explanatory / Exploratory) that lived inside the original topbar. After `StudioHeader.init()` renders the uniform header, MM Studio's JS inserts `#designSwitch` as a DOM element between the project context and the spacer:

```js
const bar = document.querySelector('#studioHeader .sh-bar');
const spacer = bar.querySelector('.sh-spacer');
const ds = document.createElement('div');
ds.id = 'designSwitch';
bar.insertBefore(ds, spacer);
```

`renderSwitch()` then populates `#designSwitch` as it always did. No changes needed to MM's pipeline JS.

---

### 7. Fallback guard in MM Studio

Both `StudioHeader` and `StudioFooter` init calls in `mmstudioV4.php` are wrapped in `typeof StudioHeader !== 'undefined'` guards. If either JS file fails to load (e.g. deployment delay for the new `apps/studio/` directory), a lightweight inline fallback renders the MM logo and `#designSwitch` slot directly so the studio remains functional.

---

## Files changed this session

| File | Change |
|---|---|
| `api/dev/_dev_common.php` | 4 new domain templates; seeder early-return guard removed |
| `api/dev/rssi-check.php` | NEW — RSSI badge data endpoint |
| `apps/studio/studio-header.js` | NEW — uniform header plug-in |
| `apps/studio/studio-footer.js` | NEW — uniform footer plug-in |
| `_analysis_studio_v4_shell.php` | Template contract comment; plug-ins wired; header/footer HTML replaced with divs |
| `mmstudioV4.php` | Header/footer HTML replaced; plug-ins wired; surveyId added to BOOT; fallback guards |
| `_platform_shell_header.php` | `#tbRssi` stub added; `window.loadRssiStub` function added |
| `platform-shell.css` | RSSI badge CSS added |

---

## Where to pick up next session

### Immediate: Wire 360 and TIA onto the studio template

Both `360-wizard.php` and `tia-wizard.php` use `_platform_shell_header.php` / `_platform_shell_footer.php`. They need:
1. `<div id="studioHeader"></div>` and `<div id="studioFooter"></div>` replacing their current header/footer HTML
2. Script includes for `studio-header.js` and `studio-footer.js`
3. `StudioHeader.init({ logoSrc: '/360-studio-long.png', ... })` and `StudioFooter.init()`
4. Confirm what `$shell_project_label` maps to for each studio's project context

**Before touching 360/TIA**, confirm their status (the status table in the previous handoff said "Status unclear — confirm before RE infra work").

### After 360/TIA: Report system

The `mode='report'` slot exists in the shell but the shared report system (the component that plugs into it) has not been designed or built. It was explicitly deferred as "outside the template." This is the last piece of the template contract.

D/I Studios have a working report system (save-to-report + print/Word/Google Docs). MM Studio has its own. The goal is a single shared system that all studios call the same way.

### RE infrastructure work (unchanged from last session)

Still in order:
1. Unified type taxonomy
2. Unified data upload/parser
3. Unified project table
4. Unified export
5. Wire RE connections (SIRI Journey steps 02–06, RSSI Journey)

---

## Standing rules (unchanged)

- **Auto-deploy is LIVE** — saving a file uploads to prod in ~15s. No accidental edits.
- **Never rename `sdsi` code** — internal engine namespace stays as-is.
- **RE build principles** — build outside first; rules before plugging; test all sub-systems before system-wide.
- **No em dashes** in user-facing copy.
- **AI = ReliCheck Intelligence** in user-facing copy.
- **MM Studio** — V4 only. Edit with care (RE infrastructure rollouts only).
- **Logo height** — 70px for all studio long wordmarks. Do not override per-studio.
- **Project Snapshot** (`project-snapshot.php`) is active shared infrastructure — not an MM backup.

---

## Commits this session (in order)

1. `0fc6fff` — Add 4 domain templates: 360 Feedback, Program Eval, HR Climate, Item Review
2. `b0e19fa` — Lock studio template contract in v4 shell header comment
3. `b832022` — Add RSSI stub to studio topbar + rssi-check endpoint
4. `7915a87` — Wire RSSI topbar stub into all studios
5. `d1a10ce` — Create studio-header.js + studio-footer.js plug-ins; wire all studios
6. `5938bcd` — Fix MM Studio logo + harden plug-in against load failure
7. `db1abd8` — Redesign footer: SIRI+RSSI popup buttons; standardize logo at 70px
8. `2538465` — Fix logo height: inline style on img, no CSS variable
