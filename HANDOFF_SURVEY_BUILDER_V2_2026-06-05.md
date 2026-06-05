# HANDOFF — Survey Builder V2 (developV2.php) · 2026-06-05

## TL;DR
`developV2.php` is now **the live Survey Builder**. It started as a clean V4-shell
redesign that was missing most of the old `develop.php` feature set; over this
session it reached full parity **plus** a substantially hardened, psychometrically
sound item-assessment engine, and the live entry was repointed to it.

Everything below is **committed and pushed to `main`** (GitHub is the safety net;
saving any file here also auto-deploys to production in ~15s).

---

## Current state — it's live
- Hub card (`app-2026v4.php`), registry (`_studio_registry.php`), and the
  `survey-dev.php` landing all point at **`/developV2.php`**.
- `develop.php` (the original builder) **302-redirects to `/developV2.php`**,
  preserving the query string. `?project_id=N` opens that project on load.
- **Fallback:** `develop.php?legacy=1` loads the original builder (200, no
  redirect). **Revert the repoint:** delete the top block of `develop.php`.
- `develop.php` also carries **pre-existing WIP not authored this session**
  (AI-assist state fields, an `App.go` returnRoute param) — already live, committed
  as-is in 78b52e2. Review separately if it's half-finished.

---

## Architecture / key files
- **`developV2.php`** — the builder. Single-file vanilla-JS SPA; inline `<script>`
  (so a browser HARD-REFRESH `Cmd+Shift+R` is needed to pick up changes — the
  document itself caches). Persists to the proven `api/dev/*` endpoints.
- **`apps/sdsi/buildcheck-engine.js`** — the SHARED SDSI validity engine (strength
  score + per-item marks + the SIRI/RSSI foundation). Used by BOTH developV2 and
  `develop.php`. Has `node` tests: `buildcheck-engine.test.js` (71),
  `buildcheck-calibration.test.js` (calibration has 2 PRE-EXISTING stale
  band-label failures — not ours; assert with tolerance).
- **`apps/sdsi/launchcheck-engine.js`** (SIRI 100-pt) + `siri-readiness.js` —
  loaded by developV2 for the Analyze readiness check.
- **`api/dev/ai-refine.php`** — per-item AI help: actions `rewrite`, `clarity`,
  and the new **`review`** (deep, context-aware, dimension-tagged verdict).
- **`api/dev/ai-build.php`** / **`ai-suggest.php`** — the AI build + suggest paths.
- Public take-survey: `/s/:link_key` → `take.html` (already supports skip logic);
  data via `api/public/survey-dev.php` → `submit-dev.php`.

### GOTCHA (root cause of an early "nothing works")
Question-type labels MUST be the canonical names from `sds_item_type()` in
`api/dev/_dev_common.php` — `Multiple Choice`, `Rating Scale`, `Yes/No`,
`Short Answer`, `Long Answer`, `Numeric`, `Likert Scale`, etc. Any unrecognized
label silently saves as `Short Answer`. `QTYPES`, `defaultOptions`, `mapType`,
`normType` all use the canonical set.

---

## What was built this session

### Build flow (the shell people use)
- **Edit-in-place**: a `+ Add question` button drops a blank card already in edit
  mode; prompt + type + the per-type response editor all live on one card; `Save
  question` persists. `addBlankQ` is async and `await`s `saveItemsNow()` before
  pushing the next blank (items-save is a full upsert — avoids duplicates).
- **3 Start modes** → each goes through a **2-question setup** (Title + "What are
  you looking to get from this survey?") BEFORE the workspace. The purpose feeds
  the AI and the SIRI readiness check (lifts the "no purpose recorded" flag).
  - *Build it myself* (`enter('scratch')`), *Build with an assistant*
    (`enter('ai-assist')`, AI-suggest emphasized), *Have ReliCheck build it*
    (`aiDraft()` → real `ai-build.php`, tailored study).
- **23 question types** in 6 grouped categories + "Help me choose"; per-type
  editors (Likert points+anchors, Rating stars, Slider range, Matrix rows,
  Ranking, NPS, Consent, structural). Settings stored in the keys the public
  renderer reads (`likertPoints/likertLow/likertHigh`, `ratingStars`) so they work
  end to end. Legacy type names normalized on load (`normType`/`QTYPE_ALIAS`).

### Phases = Build → Analyze → Launch → Results  (NOTE the meaning)
- **Build** — write questions. Forward btn = "Check readiness →".
- **Analyze** — the **PRE-launch readiness checker** (the real SIRI Launch Check
  card; auto-runs on entry via `maybeRunSiri`).
- **Launch** — deploy only (de-crowded): publish link, Share/Email/QR/Invite/
  Preview, and instrument exports (Word-PDF / CSV / JSON).
- **Results** (`viewResults`) — the POST-response analysis (old "Analyze").

### Deploy + exports + skip patterns
- Share link (+ embed iframe), Email (mailto), QR (client-side qrcodejs), Invite
  list (mailto bcc), Preview; Word/PDF print doc, CSV, JSON exports.
- **Skip patterns / display logic**: editor authors `settings.showIf
  {questionId,op,value}`; `survey-dev.php` passes it through (normalized to the
  public `'i'<id>`); `take.html` already evaluates + strips hidden answers.

### Theme
- Rust-orange brand accent `--accent:#bf4726` on primary buttons, active/done
  stepper, Start-card icons. (User asked for rust, not bright orange.)

---

## THE VALIDITY ENGINE (most important — read before touching `buildcheck-engine.js`)

The user is a psychometrics expert and holds ReliCheck's credibility to this. The
locked principle: **judge the STEM's communicative function FIRST; the response
scale is secondary. A weak stem is weak on Likert / MC / dropdown / numeric /
rating / demographic.** Do NOT treat scale type as evidence of validity.

1. **Stem-first classifier** — `assessStemFunction(text,type,w,low)` flags by what
   the stem IS, with a function-aware rewrite + measurement-language `why`:
   - `stem_construct_label` (high) — bare concepts ("Leadership", "Safety",
     "Equity", "Support", "Development", "Communication") + `AMBIG_NOTE` for
     multivalent ones.
   - `stem_verb_fragment` (high) — "Rate"/"Describe" with no object.
   - `stem_metadata_field` (high) — "Respondent ID", timestamps, identifiers.
   - `stem_demographic_label` (medium) — "Role Level"/"Tenure"/"Department"/
     "Start Date" (answerable, needs question form).
   - `stem_not_answerable` (high/medium) — other short label/topic stems.
   - PASSES: true questions, Likert STATEMENTS (subject+verb via `STATEMENT_VERBS`,
     NOT by type), instructional prompts with an object. Dictionaries:
     `CONSTRUCT_LABELS`, `DEMO_REWRITES`, `CONCEPT_REWRITES`, `METADATA_RE`,
     `FRAGMENT_VERBS`.
2. **Bias word-list audit** — all four families (`LEADING`/`LOADED`/`ABSOLUTE`/
   `ASSUMPTIVE`) now match WHOLE words/phrases (`\b…\b`), not substrings, and
   topic-ambiguous entries were dropped. Fixes false accusations like "clearly" in
   "clearly defined", "never" in "nevertheless", "suffer" in "workloads suffer",
   "since you" in "since your".
3. **Click-to-explain** — every per-question mark is a clickable **"ⓘ why"**
   (`toggleExplain`/`explainPanel`) listing the specific flags + a note that
   "pulling it down" means overall SURVEY STRENGTH, not always the wording.
4. **Combined per-item verdict** — editor **"✦ Check this item"** (`checkItem`)
   merges the deterministic engine flags (instant) with `ai-refine.php?action=review`
   (the LLM reading the item in context for the stated POPULATION:
   answerability / construct / clarity / **cultural** fairness). The AI is handed
   the engine flags so it reinforces, not contradicts. One panel + one Apply-able
   rewrite. `itemVerdictBox` / `state.itemVerdict`.

**Invariants:** keep stem-first; never gate item validity on scale type; word
lists match whole words; never let a deterministic flag contradict the AI silently
(give the AI the flags). Run `node apps/sdsi/buildcheck-engine.test.js` (expect 71)
and the targeted batteries after ANY engine edit.

---

## How to verify quickly
- Hard-refresh first (`Cmd+Shift+R`) — inline JS caches.
- Build: `+ Add question` → set type → Save → check it persists (Network 200).
- Analyze: open it → SIRI card scores (e.g. project 100 ≈ 69/100).
- Launch: Publish → Open collection → take `/s/<key>` → submit → Results count.
- Validity: type "Leadership" as Likert → still "pulling it down"; click "ⓘ why"
  and "✦ Check this item" → combined verdict with a cultural note + rewrite.
- Engine tests: `node apps/sdsi/buildcheck-engine.test.js` → ALL PASS (71).

---

## Open items / suggested next steps
1. **Dictionaries** — hand `CONSTRUCT_LABELS` / `DEMO_REWRITES` to the user to
   expand for their populations (K-12, healthcare, higher-ed), or build a small
   admin list editable without touching engine code. (User-requested follow-up.)
2. **`develop.php` WIP** — decide whether the pre-existing AI-assist/returnRoute
   WIP should be finished, kept, or reverted (it's live + committed as-is).
3. **Study setup population field** — only Title + Purpose are asked; adding an
   optional "Who will answer?" would clear the SIRI "no intended audience" flag.
4. **Test-project cleanup** — many throwaway "Untitled survey" drafts from
   verification remain in the user's project list (IDs ~100–122); offer to archive.
5. **Calibration test** — 2 PRE-EXISTING stale band-label assertions fail
   ("Not ready for launch review" vs engine's "Not ready"); update the test
   strings if desired (not ours).

---

## Session commits (newest first)
```
78b52e2  Repoint the live Survey Builder entry to developV2.php
38bd07e  Combined per-item verdict: rules engine + AI clarity
a796e87  Audit bias word-lists for substring false positives
92a5b9c  Stem-first, function-first item assessment (scale secondary)
5b474e2  Fix leading-word false positive + click-to-explain marks
037f1ac  Flag label-style stems ("Role Level", "Tenure")
bdb381b  Ask Title + Purpose before the workspace (all build modes)
7a44fd5  Split Launch into Analyze+Launch (4 phases) + rust theme
a8ee786  Restore the three build modes on the Start screen
30aec98  Skip patterns (conditional display logic)
9869970  Port full old-system feature set into the V4 shell
```

Related memory: `project_developv2_builder.md`, `project_buildcheck_label_stems.md`.
