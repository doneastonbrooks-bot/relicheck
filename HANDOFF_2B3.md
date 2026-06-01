# Handoff — Phase 2B.3 (construct persistence) COMPLETE + parser fixes

_Date: 2026-05-29. Hand this whole file to the next session._

## TL;DR
Phase 2B.3 (persist construct definitions to DB + hydrate on load) is **done and live-verified**. Two parser bugs were found and fixed during the live test. The full 14-step live test passed on `relichecksurvey.com/develop.php?db=1`. Nothing left to do on 2B.3 itself.

## Project facts (carry forward)
- `develop.php` is a single-file PHP+HTML+vanilla-JS SPA at the relicheck project root. It is **UNTRACKED in git**.
- **Auto-SFTP deploy to Ionos**: every saved file is immediately live on relichecksurvey.com. There can be a short propagation/browser-cache delay — bust it by appending `&cb=<number>` to the URL.
- `?db=1` = DB mode (real persistence); `?debug=1` = dlog tracing. Default (no `?db=1`) = mock.
- The localhost:8000 preview server **cannot authenticate** (require_auth → 401), so DB-mode live testing must be done in the user's logged-in Chrome via the Chrome MCP. Current tab: id `852052859`.
- DB schema in `api/dev/_dev_common.php`: `survey_constructs(id, project_id, position, name, definition, created_at, updated_at)` — **no metadata/settings column**. `survey_items` has **no construct column** — the item→construct mapping rides inside the item's `settings` JSON.

## What changed (all in `develop.php` unless noted)

### Construct persistence (the 2B.3 work — already verified)
- `api/dev/constructs-save.php` (NEW): full upsert by id, name-dedup so repeated saves never duplicate, deletes omitted, reassigns position. Returns saved constructs with ids.
- `api/dev/project-create.php` (MODIFIED): inserts `constructs` (name-deduped) within the create transaction.
- Client wiring in develop.php: `DB.constructsWire()`, `DB.realignItemConstructs()` (name-anchored id backfill), `DB.itemSettingsOut`/`itemHydrate` embed/lift `construct`+`constructId` in item settings JSON, `App.saveConstructs()`, and `App.saveItems()` calls `saveConstructs()` after items save. `DB.hydrate()` restores `state.survey.constructs` and lifts each item's `construct` name on load.

### Parser fixes (found during live test — both lint-clean and live-confirmed)
Around lines 632–690 of `develop.php`:
1. **`isBareAnswerToken(s)`** — bare unmarked answer lines (`Yes`/`No`/`True`/`False`/`Agree`/`Disagree`, etc.) now group as options under their stem instead of each becoming its own item.
2. **`isNumberedStem(s)`** — a numbered line like `2. The orientation…` previously matched the generic option-marker regex (`\d+[.)]`) and got swallowed as an option of the previous item. Now numbered lines always start a new item, and a numbered stem (or a fresh scale instruction) also **closes an open rating block** so the next numbered item isn't absorbed as another rated sub-stem.

Convention encoded: **numbers = question stems; letters / bullets / bare words = answer options.** (Trade-off: a survey that uses `1. 2. 3.` for multiple-choice OPTIONS would now read those as new stems. Acceptable for this user's survey style.)

## Live test result (project #21, all 14 steps PASS)
- Import parsed 6 items correctly: Yes/No `[Yes,No]`, True/False `[True,False]`, 3× Rating(max 5) from the split rating block, 1× Rating(max 7).
- 2 constructs persisted: Program Experience (#5), Instructional Quality (#6).
- 4 rating items mapped to constructs; saved.
- **SDSI #1**: 40.2/50 (80%, "Solid design, minor improvements") — Construct Clarity 1.8/8, Reliability Readiness 2.6/6.
- Hard reload + reopen #21: constructs, all 4 item→construct mappings (name + constructId), Yes/No options, True/False options, rating split — all persisted; saved SDSI review reloaded.
- **SDSI #2**: identical 40.2 / 1.8 / 2.6 — nothing falsely dropped. SIRI did not appear or merge into SDSI (max still 50).

## Cleanup note
Live DB test projects **#19 and #20 are junk** (created before the parser fix, wrong item splits). They can be archived/ignored. #21 is the good one.

## Standing constraints (do NOT violate)
- Do NOT modify existing production Ionos MySQL tables — only CREATE new additive tables.
- Do NOT rename sdsi code. Do NOT change locked lens vocabularies / scoring / blocker logic.
- Do NOT wire all scoring at once (phase order: 2A persistence → 2B SDSI 50-pt → 2C SIRI; SIRI stays stubbed).
- Never use em dashes in user-facing copy. Write plainly for non-expert users. Brand AI as "ReliCheck Intelligence".
- Builder is click-to-add, NOT drag-and-drop.
- Never handle/enter credentials; never log into accounts for the user.
- Do NOT start SIRI, RSSI, Studios, AI Deep Check, publish, deploy, or retrieve.

## Suggested next step (not started)
Phase 2C / SIRI is explicitly out of scope until directed. The SIRI 100-pt dashboard is already built and verified (see memory). Confirm with the user what the next phase is before touching scoring.
