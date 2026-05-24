# Phase 4 install steps — Analysis tab (variable roles + suggest + run)

End state: after committing a dataset, a new "Phase 4 / Analysis" card
appears. Assign Predictor / Outcome / Either to each variable, click
"Load analysis pairs," see the matrix of suggested tests, click Run on
any row to compute the result against the full dataset (not just the
50-row preview). No new Python dependencies. No new Rust dependencies.
No notarize-rejection risk.

## 1. Copy the five files

**In Terminal, run:**

```
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase4_handoff/engine/mm_engine/dataset.py /Users/don/Projects/relicheck-mm-studio-mac/engine/mm_engine/dataset.py && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase4_handoff/engine/mm_engine/ingest.py  /Users/don/Projects/relicheck-mm-studio-mac/engine/mm_engine/ingest.py && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase4_handoff/engine/mm_engine/ipc.py     /Users/don/Projects/relicheck-mm-studio-mac/engine/mm_engine/ipc.py && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase4_handoff/engine/tests/test_phase4_flow.py /Users/don/Projects/relicheck-mm-studio-mac/engine/tests/test_phase4_flow.py && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase4_handoff/src/main.js /Users/don/Projects/relicheck-mm-studio-mac/src/main.js && \
echo "files copied"
```

Expected: `files copied`.

## 2. Add the Phase 4 card to index.html

A new card after the Phase 3 card. Same surgical-edit pattern as Phase 3.

**In Terminal, run:**

```
python3 -c "
import pathlib
p = pathlib.Path('/Users/don/Projects/relicheck-mm-studio-mac/src/index.html')
s = p.read_text()
marker = '<div class=\"card\">\n        <h2>Phase 2 / Python sidecar test</h2>'
phase4 = '''<div class=\"card\" id=\"analysis-card\" style=\"display:none;\">
        <h2>Phase 4 / Analysis</h2>
        <p>Mark each variable as Predictor or Outcome, then run the suggested tests.</p>
        <div id=\"analysis-roles\" style=\"margin-top:14px;\"></div>
        <div style=\"display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px;\">
          <button id=\"analysis-load\" style=\"padding:8px 16px;font-size:14px;border-radius:8px;border:1px solid #1f6feb;background:#1f6feb;color:#fff;cursor:pointer;font-weight:600;\">Load analysis pairs</button>
          <span id=\"analysis-msg\" style=\"color:#5a6470;font-size:13px;\"></span>
        </div>
        <div id=\"analysis-out\" style=\"margin-top:14px;\"></div>
      </div>

      ''' + marker
assert marker in s, 'Phase 2 marker not found'
p.write_text(s.replace(marker, phase4))
print('Phase 4 card added')
"
```

Expected: `Phase 4 card added`.

Note the card starts hidden (`display:none`). It becomes visible the
moment you click Commit in the Phase 3 card; the JS sets
`#analysis-card.style.display = ""` in the commit handler.

## 3. Update the bundled Python engine

The bundled Python at `src-tauri/resources/python/` has the old engine
code from Phase 3. Reinstall it so the new `dataset.py` and updated
`ingest.py` / `ipc.py` land in its site-packages.

**In Terminal, run:**

```
cd /Users/don/Projects/relicheck-mm-studio-mac && \
src-tauri/resources/python/bin/python3 -m pip install --upgrade --force-reinstall --no-deps /Users/don/Projects/relicheck-mm-studio-mac/engine && \
src-tauri/resources/python/bin/python3 -c "import mm_engine; print('bundled mm_engine version:', mm_engine.__version__); from mm_engine import dataset; print('dataset module loaded')"
```

The `--no-deps` flag skips reinstalling numpy/scipy/pandas/pyreadstat —
they're already there. `--force-reinstall` makes pip overwrite the old
mm_engine even if pip thinks the version hasn't changed.

Expected: `bundled mm_engine version: 0.2.0` and `dataset module loaded`.

Also update the dev venv:

```
cd /Users/don/Projects/relicheck-mm-studio-mac/engine && \
.venv/bin/pip install -e . && \
.venv/bin/python -m pytest -q
```

Expected: `45 passed`.

## 4. Try it in dev mode first

Before the full notarize cycle, prove it works locally.

**In Terminal, run:**

```
cd /Users/don/Projects/relicheck-mm-studio-mac && npm run tauri dev
```

The dev window opens. Two checks:

(a) **Sanity:** click "Run Pearson via sidecar" in the Phase 2 card.
JSON should still appear. This confirms the engine update didn't break
the Phase 2 smoke path.

(b) **Real workflow:** click "Open file..." in the Phase 3 card, pick
any CSV (the `_phase3_test_data/sample.csv` works). Preview appears.
Click Commit. **The Phase 4 card unhides.** Assign `score_pre` as
Predictor, `score_post` as Outcome (click the chips). Click "Load
analysis pairs." A row appears showing `score_pre x score_post`
suggesting Pearson. Click Run. A result panel appears underneath with
`r = 0.XX`, `p < .0001` or similar, the summary string.

If anything looks wrong at this stage, stop and tell me before
notarizing.

## 5. Rebuild .app + notarize + staple

Same chain as Phase 3.

**In Terminal, run:**

```
cd /Users/don/Projects/relicheck-mm-studio-mac && \
find src-tauri/resources/python -type f \( -name "*.so" -o -name "*.dylib" -o -name "python3*" -o -name "python" \) -exec codesign --force --options runtime --timestamp --sign "Developer ID Application: Relicheck LLC (W5H55SY5T5)" {} \; 2>&1 | tail -3 && \
npm run tauri build 2>&1 | tail -10
```

(The find/sign step is technically unneeded since we didn't add any new
compiled binaries — only Python source files changed. But running it is
safe and free, and it's habit-forming for the right reason: any time
`resources/python/` changes we re-sign, full stop.)

Then notarize:

```
xcrun notarytool submit "/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/target/release/bundle/dmg/ReliCheck MM Studio_0.1.0_aarch64.dmg" --keychain-profile "ReliCheck-Notary" --wait
```

Then staple and verify:

```
xcrun stapler staple "/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/target/release/bundle/dmg/ReliCheck MM Studio_0.1.0_aarch64.dmg" && \
spctl -a -v -t open --context context:primary-signature "/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/target/release/bundle/dmg/ReliCheck MM Studio_0.1.0_aarch64.dmg"
```

Expected last line: `accepted, source=Notarized Developer ID`.

## 6. Verification on the M1 Pro

AirDrop the new DMG, then on the M1 Pro:

```
rm -rf "/Applications/ReliCheck MM Studio.app"
```

Mount, drag to Applications, open. Five cards now (three Phase 1
checks, Phase 3 Open dataset, Phase 4 Analysis hidden until commit,
Phase 2 sidecar test).

The full pipeline test: open any CSV → commit → assign roles → load
pairs → run a Pearson and a t-test. If the results look reasonable,
**Phase 4 is done.**
