# Phase 3 install steps — file ingest (CSV/TSV/XLSX/SAV/DTA/JSON)

End state: open MM Studio, click "Open dataset," pick any supported file,
see a preview table with column types and labels, click Commit. Drag and
drop also works.

The work is in **three commands**, plus the rebuild + notarize cycle.

---

## 1. Copy new and updated files into the Mac project

All ten files from the handoff folder. **In Terminal, run:**

```
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase3_handoff/engine/mm_engine/ingest.py   /Users/don/Projects/relicheck-mm-studio-mac/engine/mm_engine/ingest.py && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase3_handoff/engine/mm_engine/ipc.py      /Users/don/Projects/relicheck-mm-studio-mac/engine/mm_engine/ipc.py && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase3_handoff/engine/mm_engine/__init__.py /Users/don/Projects/relicheck-mm-studio-mac/engine/mm_engine/__init__.py && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase3_handoff/engine/tests/test_ingest.py  /Users/don/Projects/relicheck-mm-studio-mac/engine/tests/test_ingest.py && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase3_handoff/src-tauri/Cargo.toml         /Users/don/Projects/relicheck-mm-studio-mac/src-tauri/Cargo.toml && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase3_handoff/src-tauri/src/main.rs        /Users/don/Projects/relicheck-mm-studio-mac/src-tauri/src/main.rs && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase3_handoff/src/main.js                  /Users/don/Projects/relicheck-mm-studio-mac/src/main.js && \
echo "files copied"
```

Expected: `files copied`.

## 2. Add the dialog permission to capabilities

The Phase 1 scaffold has a `default.json` already. We need to add two
permission lines without losing whatever else is in there.

**In Terminal, run:**

```
python3 -c "
import json, pathlib
p = pathlib.Path('/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/capabilities/default.json')
cfg = json.loads(p.read_text())
perms = cfg.setdefault('permissions', [])
for needed in ['dialog:default', 'dialog:allow-open']:
    if needed not in perms:
        perms.append(needed)
p.write_text(json.dumps(cfg, indent=2))
print('capabilities updated:', perms)
"
```

Expected: `capabilities updated: [..., 'dialog:default', 'dialog:allow-open']`.

## 3. Update the HTML to add the Phase 3 card

The existing `index.html` only has the Phase 2 card. We add a Phase 3
card with an Open button and a drop zone, right before Phase 2.

**In Terminal, run:**

```
python3 -c "
import pathlib
p = pathlib.Path('/Users/don/Projects/relicheck-mm-studio-mac/src/index.html')
s = p.read_text()
marker = '<div class=\"card\">\n        <h2>Phase 2 / Python sidecar test</h2>'
phase3 = '''<div class=\"card\">
        <h2>Phase 3 / Open dataset</h2>
        <p>Open a CSV, TSV, Excel, SPSS .sav, Stata .dta, or JSON file. Drag and drop also works.</p>
        <div id=\"ingest-drop\" style=\"margin-top:14px;padding:24px;border:2px dashed #b8c1cc;border-radius:8px;text-align:center;background:#fbfcfd;color:#5a6470;font-size:13px;\">
          Drop a data file here, or click below to browse.
        </div>
        <button id=\"ingest-open\" style=\"margin-top:14px;padding:8px 16px;font-size:14px;border-radius:8px;border:1px solid #5a6470;background:#23303d;color:#fff;cursor:pointer;\">Open file...</button>
        <div id=\"ingest-status\" style=\"margin-top:14px;font-size:13px;color:#5a6470;\"></div>
        <div id=\"ingest-preview\" style=\"margin-top:14px;\"></div>
      </div>

      ''' + marker
assert marker in s, 'Phase 2 marker not found in index.html'
p.write_text(s.replace(marker, phase3))
print('index.html updated')
"
```

Expected: `index.html updated`.

Also add a small CSS rule so the drop zone shows feedback when a drag
hovers. **In Terminal, run:**

```
python3 -c "
import pathlib
p = pathlib.Path('/Users/don/Projects/relicheck-mm-studio-mac/src/index.html')
s = p.read_text()
needle = '@media (prefers-color-scheme: dark)'
add = '#ingest-drop.dragging { border-color: #1f8a4d; background: #e7f6ed; color: #1f8a4d; }\n      '
if add not in s:
    s = s.replace(needle, add + needle, 1)
    p.write_text(s)
    print('drop-zone CSS added')
else:
    print('drop-zone CSS already present')
"
```

## 4. Install new Python deps into the bundled portable Python

The two new dependencies — pyreadstat (for SPSS) and openpyxl (for
Excel) — install into the *bundled* Python at `src-tauri/resources/python/`,
not into the dev venv. **In Terminal, run:**

```
cd /Users/don/Projects/relicheck-mm-studio-mac && \
src-tauri/resources/python/bin/python3 -m pip install pyreadstat openpyxl && \
src-tauri/resources/python/bin/python3 -m pip install /Users/don/Projects/relicheck-mm-studio-mac/engine && \
src-tauri/resources/python/bin/python3 -c "import mm_engine.ingest; print('ingest module loaded in bundled python')"
```

Expected output ends with `ingest module loaded in bundled python`.

Also install into the dev venv so `npm run tauri dev` keeps working:

```
cd /Users/don/Projects/relicheck-mm-studio-mac/engine && \
.venv/bin/pip install pyreadstat openpyxl && \
.venv/bin/pip install -e .
```

## 5. Re-sign every binary inside the bundled Python

Phase 2 left a habit: every time we touch `resources/python/`, we have
to re-sign before building. The new wheels include compiled `.so` files
that arrived unsigned.

**In Terminal, run:**

```
cd /Users/don/Projects/relicheck-mm-studio-mac && \
find src-tauri/resources/python -type f \( -name "*.so" -o -name "*.dylib" -o -name "python3*" -o -name "python" \) \
  -exec codesign --force --options runtime --timestamp --sign "Developer ID Application: Relicheck LLC (W5H55SY5T5)" {} \; 2>&1 | tail -3
```

Expected: three "replacing existing signature" lines, no errors.

## 6. Build, notarize, staple, verify

Same chain as Phase 2 batch 2.

**In Terminal, run:**

```
cd /Users/don/Projects/relicheck-mm-studio-mac && npm run tauri build 2>&1 | tail -10
```

Expected: `Finished 2 bundles at: .../ReliCheck MM Studio.app and .../ReliCheck MM Studio_0.1.0_aarch64.dmg`.

Then:

```
xcrun notarytool submit "/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/target/release/bundle/dmg/ReliCheck MM Studio_0.1.0_aarch64.dmg" --keychain-profile "ReliCheck-Notary" --wait
```

Expected: `status: Accepted` after a few minutes.

Then:

```
xcrun stapler staple "/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/target/release/bundle/dmg/ReliCheck MM Studio_0.1.0_aarch64.dmg" && \
spctl -a -v -t open --context context:primary-signature "/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/target/release/bundle/dmg/ReliCheck MM Studio_0.1.0_aarch64.dmg"
```

Expected: `The staple and validate action worked!` and then
`accepted, source=Notarized Developer ID`.

## 7. Verification on the M1 Pro

AirDrop the new DMG over. **On the M1 Pro first:**

```
rm -rf "/Applications/ReliCheck MM Studio.app"
```

Then mount the new DMG and drag to Applications. Open. You should see a
new "Phase 3 / Open dataset" card. Test each format with any sample
file you have lying around:

- CSV → preview should show columns with types
- XLSX → if it has multiple sheets, you'll get a picker
- SAV → variable labels should show under column names; value-labeled
  columns should be category type
- DTA → variable labels should show
- JSON → object-of-arrays or array-of-objects should both work

Then drag any of those files onto the window. The drop zone turns
green, the preview loads. Click Commit; the dataset is now active.
