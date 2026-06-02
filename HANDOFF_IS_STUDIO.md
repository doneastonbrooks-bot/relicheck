# Inferential Statistics Studio (IS / I-Studio) — handoff (2026-06-02, late)

## WORK ONLY ON THE v4 PATH
- User works in the **v4 shell** via `?v4=1`: `inferential-statistics-workspace.php?v4=1`
  → includes `_analysis_studio_v4_shell.php` → loads `apps/analysis-studio/analysis-studio.js`.
- The DEFAULT (no `?v4=1`) loads the OLD `_analysis_studio_shell.php`. **User does NOT use it. Do not edit the old shell.**
- All fixes below are on the v4 shell + `analysis-studio.js`. Verified live on relichecksurvey.com (served file == local) and committed/pushed to `main`.

## The three regression wirings the user asked for
1. **Effect size — DONE (live).** Backend `api/analysis/infer.php` already returns the R² effect size in the standard `{type,value,interpretation,meaning}` shape. The regression renderer just lacked the tab. Added the `effect` tab to `renderRegResults` in `analysis-studio.js` (commit c3cc0f7).
2. **"It just runs" / stuck button — FIXED (live).** Root cause: the run `.then` set `busy=false` and updated only `#regResults`, never the Run button, so it stayed "Running…". Fix: `.then` and `.catch` now reset `#regRun` to `▷ Run regression` (commit 6b6d688). NOTE: t-test / ANOVA / chi-square / correlation have the **identical** pattern and the same stuck-button — apply the same one-line reset to `#ttRun / #anRun / #csRun / #corRun` (NOT yet done).
3. **Report — works, it is manual.** After a step runs, the shell's `onResult` inserts a "Save to report" bar; clicking it POSTs to `api/analysis/results.php` (saves one snapshot per tool); the Report step lists them. Empty Report = nothing has been clicked into it yet, not a bug. If the user wants it to auto-collect every run, that is a new feature, not a fix.

## Cache (why edits "didn't show")
- The JS IS cache-busted: `<script src="...analysis-studio.js?v=<filemtime>">` (shell line ~221). That part works.
- But the HTML page itself was being browser-cached, so it kept serving the OLD `?v=`. Fix: added `Cache-Control: no-store` + `Pragma: no-cache` to `_analysis_studio_v4_shell.php` (commit 6b6d688) so the page is always fetched fresh. After this, edits show on a **plain reload** (no hard refresh).

## Deploy / verification facts
- Files auto-upload from a **terminal uploader on the user's Mac → IONOS** (relichecksurvey.com). Mac notifications come from THAT, not Dropbox (Dropbox sync is off). No notification ≠ no deploy.
- Verify a deploy by curling the live URL and diffing against local (e.g. `curl .../analysis-studio.js | grep <marker>`). Tonight every edit was confirmed live this way.
- Commits tonight on `main`: `c3cc0f7` (effect tab), `6b6d688` (button reset + no-store).

## REMAINING (small)
- Apply the Run-button reset to the other four inferential tools (same one-liner as regression).
- Optional: decide if the Report should auto-collect runs or keep the manual "Save to report" model.
