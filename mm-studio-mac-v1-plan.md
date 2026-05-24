# ReliCheck Mixed Methods Studio for Mac — v1 Build Plan

Author: Claude (planning pass, 2026-05-19)
Companion to: `mm-studio-desktop-audit.md`

Scope of this document: the smallest shippable Mac app that lets a user
open data (CSV, Excel, SPSS, or pulled from relichecksurvey.com), assign
variable roles, and run the four classical tests from Methods Mixed
Studio, with results they can export. No Categories, Joint Display, or
Report tabs in v1.

## Naming convention

| Surface | Name to use |
|---|---|
| Marketing site, download page, blog posts, support articles, product hero copy | **ReliCheck Mixed Methods Studio** |
| macOS app bundle (`.app`), window title, menu bar, About dialog, dock label | **ReliCheck MM Studio** |
| DMG filename, code identifiers, file extensions | `ReliCheck MM Studio.app`, `relicheck-mm-studio.dmg`, bundle id `com.relicheck.mm-studio` |
| Conversational shorthand inside the team | "MM Studio" (unchanged from current internal usage) |

The long form does the marketing work on first contact; the short form
keeps the chrome tidy and avoids truncation on small screens.

Stack decisions (set):
- Shell: Tauri (Rust + WKWebView), reusing the existing MM Studio HTML/JS
- Engine: Python sidecar, bundled, with numpy / scipy / pandas /
  statsmodels / pyreadstat / openpyxl
- Distribution: Apple Developer ID, notarized `.dmg`, public download from
  relichecksurvey.com plus license-key gating for paid tier

---

## 1. Why this stack

Tauri is the right shell here because the MM Studio UI already exists as
polished HTML inside `app-2026.html`. The shell does not need to render
anything; it just needs to host the existing screens, give them file
access, and broker calls to the stats engine. Tauri's WKWebView footprint
on macOS is roughly 10 MB versus Electron's 100+ MB, and signing /
notarization use the standard Apple toolchain you already have.

Python is the right engine because the four tests in `_stats.php` are the
floor, not the ceiling. Bundling scipy/statsmodels means the next analysis
the user asks for — multiple regression, factor analysis, paired t-test,
chi-square with Yates correction, Spearman, Kendall's tau — is one Python
function call away rather than a fresh numerical-recipes port.

The Python sidecar pattern is well-trodden: Tauri spawns `python` as a
subprocess on app launch, talks to it over stdin/stdout JSON, and shuts
it down on app quit. The user never sees Python; it lives inside the app
bundle at `MM Studio.app/Contents/Resources/python/`.

---

## 2. Project layout

```
relicheck-mm-studio-mac/
  src-tauri/
    Cargo.toml
    tauri.conf.json
    icons/
    build.rs
    src/
      main.rs                # Tauri shell entry
      python_sidecar.rs      # spawn + IPC with Python
      survey_api.rs          # relichecksurvey.com client (reqwest)
      file_ingest.rs         # CSV/XLSX/SAV → JSON
    resources/
      python/                # bundled CPython.framework
      engine/                # our Python module (see below)
  src/                       # the webview app
    index.html               # MM Studio shell (forked from app-2026.html)
    js/
      mm.js                  # MM object, ported from app-2026.html
      analysis.js            # mmTabAnalysis, ported
      dataset.js             # mmTabDataset, ported
      api.js                 # invoke() wrappers for Tauri commands
    css/
      mm.css                 # extracted MM Studio styles
  engine/                    # Python source (built into resources/engine/)
    pyproject.toml
    mm_engine/
      __init__.py
      ipc.py                 # stdin/stdout JSON loop
      tests.py               # chi_square, t_test, anova, pearson
      suggest.py             # stats_suggest_test() port
      ingest.py              # CSV/XLSX/SAV readers
      dataset.py             # variable roles, dataset build
      schema.py              # mm_* table mirrors in SQLAlchemy or plain dicts
  build/
    package.sh               # tauri build + codesign + notarize + staple
  README.md
```

The `engine/` Python source is what's actually shipped; it's the same
code whether running in CI tests or inside the app.

---

## 3. The four-test JSON contract

Every call from the webview to the Python engine goes through one Tauri
command, `engine_call`, that takes `{ op, args }` and returns `{ ok,
result?, error? }`. This is the entire engine API for v1.

```json
// Suggest
{ "op": "analysis.suggest",
  "args": { "dataset": <inline dataset>, "variables": [...] } }
→
{ "ok": true,
  "result": {
    "suggestions": [
      { "predictor_id": 1, "predictor_name": "...", "predictor_type": "category",
        "outcome_id":   3, "outcome_name":   "...", "outcome_type":   "numeric",
        "test": "t_test" }
    ],
    "skipped": [
      { "predictor_id": 2, "outcome_id": 4, "test": null,
        "skip_reason": "outcome has 1 distinct value" }
    ]
  } }

// Run one test
{ "op": "analysis.run",
  "args": { "dataset_id": 7, "predictor_id": 1, "outcome_id": 3,
            "test": "t_test" } }
→
{ "ok": true,
  "result": {
    "test": "t_test",
    "statistic": -2.41,
    "df1": null, "df2": 87.3,
    "p_value": 0.018,
    "effect_size": 0.51, "effect_label": "hedges_g",
    "n_total": 92,
    "summary": "Group A (M=3.8) scored lower than Group B (M=4.2), Welch's t(87.3)=-2.41, p=.018, g=0.51."
  } }
```

This contract is identical in shape to what `analysis-run.php` returns
today. Porting the four PHP test functions to Python is the single most
mechanical part of the project — each one is roughly 30 lines, and scipy
provides exact equivalents to the gamma / beta helpers in `_stats.php`,
so the Python versions are actually shorter.

The dataset is passed inline as a small JSON object in v1 (one row per
respondent, one column per variable). When projects get larger or the
desktop app starts persisting them, this moves to a local SQLite file
and the engine reads from it directly.

---

## 4. Data ingestion

Four sources, all handled in the Python engine via `mm_engine/ingest.py`:

| Source | Library | Notes |
|---|---|---|
| `.csv`, `.tsv` | pandas `read_csv` | Sniff delimiter; surface column types to the UI before commit |
| `.xlsx`, `.xls` | pandas + openpyxl | Multi-sheet picker if more than one sheet |
| `.sav` (SPSS) | pyreadstat | Preserves variable labels and value labels |
| relichecksurvey.com | reqwest in Rust | Calls `/api/v1/surveys` + `/api/v1/responses` then hands JSON to the engine |

Drag-and-drop lands in `file_ingest.rs`, which sniffs the extension and
routes the path to the engine with `op: "ingest.csv"` / `"ingest.xlsx"`
/ `"ingest.sav"`. The engine returns a normalized dataset (columns,
inferred types, first 50 rows for preview).

---

## 5. relichecksurvey.com import flow

End-to-end, the first time a user clicks "Import from ReliCheck":

1. User clicks Import from ReliCheck.
2. App calls `POST /api/v1/device/start` (new endpoint, section 7.1 of the
   audit doc). Receives a short user code and a verification URL.
3. App shows a modal: "Visit relichecksurvey.com/connect and enter code
   ABCD-1234." Modal includes a button that opens that URL in the user's
   default browser.
4. App polls `POST /api/v1/device/poll` every few seconds.
5. User signs in on the web, approves the device, sees a confirmation.
6. Next poll returns `{ access_token, user }`. App stores the token in
   the macOS Keychain under service `com.relicheck.mm-studio` (matches
   the app bundle id).
7. App calls `GET /api/v1/surveys`, shows a picker of the user's surveys.
8. User picks one. App calls `GET /api/v1/surveys?id=<id>` for the
   schema, then pages `GET /api/v1/responses?survey_id=<id>&since=<n>`
   until empty, accumulating responses.
9. The accumulated dataset is handed to the engine the same way an
   uploaded CSV would be.

Subsequent imports skip steps 2-6; the Keychain token is reused until it
401s, at which point the device flow runs again silently.

**Backend work needed on relichecksurvey.com:** the two
`/api/v1/device/*` endpoints. Everything else exists today.

---

## 6. Signing, notarization, distribution

You already have the Apple Developer ID, so this is a one-time setup, not
a research problem:

1. In Apple Developer portal, create a Developer ID Application
   certificate; download and install into Keychain Access.
2. In `src-tauri/tauri.conf.json`, set
   `bundle.macOS.signingIdentity` to the certificate's common name and
   `bundle.macOS.entitlements` to a file that grants:
   - `com.apple.security.network.client` (for the survey API import)
   - `com.apple.security.files.user-selected.read-write` (for file open)
   - `com.apple.security.cs.allow-jit` only if Python needs it (it does
     not, in CPython >=3.11)
3. `build/package.sh` runs:
   ```
   pnpm tauri build --target universal-apple-darwin
   codesign --deep --force --options runtime \
            --sign "Developer ID Application: <Your Name>" \
            "ReliCheck MM Studio.app"
   xcrun notarytool submit "ReliCheck MM Studio.dmg" \
            --apple-id <your apple id> --team-id <team id> \
            --password <app-specific password> --wait
   xcrun stapler staple "ReliCheck MM Studio.dmg"
   ```
4. Upload the stapled `.dmg` to relichecksurvey.com under
   `/downloads/relicheck-mm-studio.dmg`. Link from a marketing page
   titled "ReliCheck Mixed Methods Studio for Mac."

Universal binary covers both Apple Silicon and Intel Macs from one build.

---

## 7. Tier gating (free vs paid)

Public download with license-key gating is the right compromise: anyone
can install and try, paid features unlock with a key.

| Feature | Free | Paid |
|---|---|---|
| Open CSV / Excel / SPSS | yes | yes |
| Run the four classical tests | yes | yes |
| Pull surveys from relichecksurvey.com | no | yes |
| Save / open `.mmproj` project bundles | no | yes |
| Export results to CSV | yes | yes |
| Export results to Word (later) | no | yes |

The license key is just an API token issued by relichecksurvey.com
account settings; the app validates it once against
`GET /api/v1/users/me/tier` and caches the tier locally with a 7-day
re-check window. This avoids needing a separate licensing service.

---

## 8. Bundle size budget

Approximate uncompressed sizes on Apple Silicon:

| Component | Size |
|---|---|
| Tauri shell + WKWebView | ~10 MB |
| Webview HTML/JS/CSS | ~2 MB |
| Python.framework (3.12, slim) | ~30 MB |
| numpy + scipy + pandas | ~120 MB |
| statsmodels + pyreadstat + openpyxl | ~30 MB |
| Icons, fonts, sample data | ~5 MB |
| **Total `ReliCheck MM Studio.app` bundle** | **~200 MB** |
| **Compressed `relicheck-mm-studio.dmg`** | **~70 MB** |

A 70 MB download is normal for a Mac app in this category (JASP is 250 MB
compressed, jamovi is 350 MB). If 70 MB feels high, the heaviest single
dependency is scipy; dropping it and porting only the four tests to pure
Python saves about 60 MB but closes the door on easy expansion.

---

## 9. Phased timeline

These are calendar weeks, not engineering weeks, on the assumption you're
not the one writing the Rust.

| Phase | Output | Estimate |
|---|---|---|
| 1. Skeleton | Tauri app opens a window, loads a hello-world HTML, builds and notarizes a `.dmg` | 1 week |
| 2. Python sidecar | Engine subprocess speaks JSON; four tests ported and unit-tested against `_stats.php` output | 2 weeks |
| 3. File ingest | CSV/XLSX/SAV drag-drop produces a preview and a committed dataset | 1 week |
| 4. Analysis tab | Forked from MM Studio: variable roles, Load pairs, Run, results table, CSV export | 2 weeks |
| 5. ReliCheck import | Device-code flow, Keychain storage, survey picker, response paging | 2 weeks (including server-side `/api/v1/device/*`) |
| 6. Polish + first ship | App icon, About, error states, license gating, public download page | 1 week |
| **Total to public v1** | | **~9 weeks** |

Categories, Joint Display, Integration, Strength Check, and Report come
later as v1.1 through v1.5.

---

## 10. Risks and how they get mitigated

The two real risks are notarization friction and engine size.

Notarization sometimes fails on first submission because of unsigned
helper binaries inside the Python framework. The fix is well known:
`codesign --deep` with `--options runtime` and an entitlements file that
permits unsigned executable memory only if scipy actually needs it
(modern scipy does not). Phase 1 should end with a successfully notarized
hello-world `.dmg` so this is shaken out before any real code is added.

Bundle size is fine at 70 MB compressed, but Apple Silicon and Intel
both need their slice of every Python wheel. The build script must use
`pip install --platform macosx_11_0_arm64 --platform macosx_11_0_x86_64
--only-binary=:all:` to grab both wheels, then `lipo` them. This is
fiddly the first time; once `package.sh` works, it's automatic.

---

## 11. What to decide before phase 1

License-key naming is the one piece of copy that still needs to land.
Whatever the desktop app validates against must match what users see in
their relichecksurvey.com account settings. Suggest "Desktop license
key" in the UI (paired with "for ReliCheck Mixed Methods Studio" on the
account-settings page) and `device_token` everywhere in code.

Everything else above is recoverable if you change your mind midway.
