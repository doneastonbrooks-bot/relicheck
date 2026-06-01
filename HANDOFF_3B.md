# Handoff — Phase 2E + Phase 3A + Phase 3B COMPLETE

_Date: 2026-05-29. Hand this whole file to the next session._

---

## TL;DR

Three phases landed in this session. All live-verified on `relichecksurvey.com/develop.php?db=1`. Nothing left to do on any of them. All guardrails held: no publish/deploy/retrieve build, no RSSI, Studios, or AI Deep Check; SDSI remains the 50-pt Build Check; SIRI remains the 100-pt Launch Check; engine untouched (47/47 tests).

---

## Phase 2E — Pre-Launch Resolution Workflow (CLOSED)

**What landed:**

- `Screens.siri()` redesigned to separate **"Must fix before launch"** (hard blockers, `launchReady===false`) from **"Advisory improvements"** (`r.notes`), each finding with a **"Fix this"** button.
- `App._fixFor(key)` — deterministic lens-to-destination routing map.
- `App._fixArg(key)` — promoted to a shared helper; computes `{route, focus, arg}` for deep-linking (passes `focus` as second arg to `App.go`).
- `App.go(route, focus)` — extended to accept an optional `focus` element id; `App._focusAfterRender()` scrolls + CSS `.fixflash` highlight (called at end of every `render()`).
- Six Launch Readiness section anchors: `id="lr-consent|lr-instructions|lr-access|lr-fielding|lr-dignity|lr-sensitive"`.
- **Build workspace** now has an inline **Constructs panel** (`id="build-constructs"`: add/name/define/remove) and **per-item construct `<select>`** on every `.qcard` (`id="qcard-N"`).
- New `App.addConstruct/setConstructField/removeConstruct/setItemConstruct/_persistConstructs` — reuse `saveConstructs` (2B.3) + `saveItems` (2B.2); every change marks SDSI+SIRI stale.
- **Revise step** now has all five actions: Keep original (new), Accept ReliCheck Suggestion, Fix Myself, Fix with ReliCheck Intelligence, Mark for Later.
- Standalone `Screens.constructs()` was built then removed (orphaned — routing per user spec goes to Build + Build-constructs panel).
- `state.fixFocus:null` added; `.fixflash` CSS keyframe added.

**Routing table (per user's exact spec):**

| SIRI finding | Destination |
|---|---|
| consent_privacy | launch #lr-consent |
| respondent_instructions | launch #lr-instructions |
| access | launch #lr-access |
| fielding_plan | launch #lr-fielding |
| dignity_framing | launch #lr-dignity |
| sensitive_safety | launch #lr-sensitive |
| construct_definition, dimension_coverage, item_construct_alignment | build #build-constructs (first unmapped qcard for item_construct_alignment) |
| item_clarity, response_option_validity, response_scale_consistency | revise |
| purpose_alignment, administration_consistency | setup |
| redundancy_balance, scale_structure_readiness, completion_burden | build |

**Live verification:** SIRI 63 (blocked construct_definition) → routed to Build construct panel → added construct "Engagement" + mapped items via per-item selectors → re-ran SIRI → 84.6 "Good", all blockers cleared. Keep-original: active 1→0, kept 0→1, text unchanged.

---

## Phase 3A — Publish Readiness Gate (CLOSED)

**What landed:**

- `App._publishGate()` — pure state read returning `{status:'blocked'|'review'|'ready', headline, blockers[], reasons[], sdsiWarn}`. SIRI is the FINAL gate; SDSI is advisory only (warns if not run, stale, or `total < 40/50`).
- `App._fixArg(key)` promoted here (from being local to `siri()`) so the Publish Readiness gate can reuse Phase 2E routing.
- `Screens.publish()` rewritten ("Step 8 · Publish Readiness") — gate-driven UI: status badge (Blocked / Needs review / Ready to publish), per-blocker reason rows with "Fix this" buttons, SDSI advisory callout, disabled publish button unless `ready`.
- `publishNow()` guards on gate status; when `ready`, calls `project-publish.php` (Phase 3B), does NOT navigate to the old deploy mock.
- `state.publishReady:false` added.
- Legacy deploy mock "Published, link is live" text and fake URL neutralized → amber "Phase 3B" placeholder.

**Live verification (all gate states):** no-SIRI → blocked/disabled; SIRI passes → Ready/enabled; stale → Needs review/disabled; critical SDSI flag → blocked/disabled with routed Fix buttons; weak SDSI (30/50) → advisory-only, does not block.

---

## Phase 3B — Public Survey Link Foundation (CLOSED)

**What landed:**

**Backend:**
- `api/dev/project-publish.php` (NEW) — validates SIRI passed server-side (reads `siri_reviews.blocked`), generates a unique 8-char `link_key`, upserts into `deployment_settings.settings` JSON, sets `survey_projects.status = 'published'`. Idempotent (reuses existing key). Returns `{ok, link_key, deployment:{link_key, published_at, responses_open:false}}`.
- `api/dev/_dev_common.php` — `sds_project_payload()` now reads and returns `deployment_settings` as `payload.deployment`.
- `api/dev/project-update.php` — `published` added to allowed status values.

**Frontend (`develop.php`):**
- `state.deploymentSettings:null` — new state field; reset in `_resetStudy()`; hydrated from `payload.deployment` in `DB.hydrate()`; also auto-sets `publishReady=true` if a link key already exists.
- `publishNow()` now async — calls `project-publish.php`, stores the deployment in `state.deploymentSettings`, routes to `deploy` on success.
- `Screens.deploy()` — shows real `relichecksurvey.com/s/{link_key}` in an amber "Not yet live" banner when a link key exists; shows a "go to Publish Readiness" prompt otherwise.
- `Screens.publish()` — when ready, shows the link preview (`code` element) and "Survey link generated" confirmation; button label changes to "View deploy screen" once the link is generated.

**Live verification on project #23:** link key `hhx4uura` generated and persisted; restores on reload; deploy screen shows `relichecksurvey.com/s/hhx4uura` with "Not yet live" badge; `responses_open:false`; no live URL, no response collection.

---

## Environment / critical facts

- `develop.php` is **UNTRACKED in git** and auto-SFTP-deploys live to Ionos on save. Cache-bust with `&cb=<number>`.
- `localhost:8000` cannot auth — DB-mode live testing must use the logged-in Chrome tab (current tab id varies; use `App.openExisting(N)` not `App.openProject`).
- Test projects: **#23 is the clean one** (used throughout this session). #21 was the 2B.3 test project.
- Engine tests: `node apps/sdsi/launchcheck-engine.test.js` → **47/47 pass**. Never modify `buildcheck-engine.js`.
- `deployment_settings` table and `response_summaries` table were pre-provisioned in `db/schema_survey_dev_system.sql` — no schema migration needed.

---

## Suggested next phase

**Phase 3C: Response Collection Foundation** — flip `deployment_settings.responses_open = true` via a new endpoint, wire `take.html` to serve the project's items from `survey_items` via `api/public/survey.php` (that endpoint already exists and uses slugs from the main `surveys` table — you'll need to bridge from `link_key` in `deployment_settings` to something `take.html` can query, or create a lightweight adapter).

Do NOT start RSSI, Studios, or AI Deep Check. Do NOT modify the existing production `surveys` table or its schema.

---

## Standing constraints (never violate)

- Do NOT modify existing production Ionos MySQL tables — only CREATE new additive tables.
- Do NOT rename sdsi code. Do NOT change locked lens vocabularies/scoring/blocker logic.
- Never use em dashes in user-facing copy. Write plainly for non-expert users.
- Brand AI as "ReliCheck Intelligence", not generic "AI".
- Builder is click-to-add, NOT drag-and-drop.
- Never handle/enter credentials; never log into accounts.
- Do NOT start SIRI standalone review workflows, RSSI, Studios, AI Deep Check, or the full publish/deploy/retrieve pipelines.
- SIRI = 100-pt Launch Check. SDSI = 50-pt Build Check. Both are separate. RSSI is post-data, not wired here.
