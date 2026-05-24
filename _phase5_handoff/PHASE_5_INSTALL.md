# Phase 5 desktop install — Import from ReliCheck

Web side is already shipped and verified by curl. This batch wires the
Tauri shell to call those endpoints, store the resulting token in macOS
Keychain, list your surveys, fetch the responses, and drop them into
the same ingest pipeline as a normal CSV.

Six steps. The Cargo compile in step 4 is the slow one (~5-10 minutes
first time only — new HTTPS client and Keychain crates).

## 1. Copy four files

**In Terminal, run:**

```
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase5_handoff/src-tauri/Cargo.toml /Users/don/Projects/relicheck-mm-studio-mac/src-tauri/Cargo.toml && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase5_handoff/src-tauri/src/main.rs /Users/don/Projects/relicheck-mm-studio-mac/src-tauri/src/main.rs && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase5_handoff/src-tauri/src/survey_api.rs /Users/don/Projects/relicheck-mm-studio-mac/src-tauri/src/survey_api.rs && \
cp /Volumes/Doc\ Drive/Dropbox/Claude\ Work/Projects/relicheck/_phase5_handoff/src/main.js /Users/don/Projects/relicheck-mm-studio-mac/src/main.js && \
echo "files copied"
```

Expected: `files copied`.

## 2. Add the Phase 5 card to index.html

Same surgical-edit pattern as Phases 3 and 4 — uses a heredoc temp file
to avoid Terminal quoting issues.

**In Terminal, run:**

```
cat > /tmp/phase5_card.py << 'SCRIPT_EOF'
import pathlib
p = pathlib.Path('/Users/don/Projects/relicheck-mm-studio-mac/src/index.html')
s = p.read_text()
marker = '<div class="card" id="analysis-card"'
phase5 = '''<div class="card" id="relicheck-card">
        <h2>Phase 5 / Import from ReliCheck</h2>
        <p>Pull surveys and responses directly from your relichecksurvey.com account.</p>
        <div id="relicheck-status" style="margin-top:14px;font-size:13px;color:#5a6470;">Not signed in.</div>
        <button id="relicheck-start" style="margin-top:14px;padding:8px 16px;font-size:14px;border-radius:8px;border:1px solid #1f6feb;background:#1f6feb;color:#fff;cursor:pointer;font-weight:600;">Import from ReliCheck</button>
        <div id="relicheck-picker" style="margin-top:18px;"></div>
      </div>
      <div id="relicheck-modal" style="display:none;"></div>

      ''' + marker
assert marker in s, 'analysis card marker not found'
p.write_text(s.replace(marker, phase5))
print('Phase 5 card added')
SCRIPT_EOF
python3 /tmp/phase5_card.py
```

Expected: `Phase 5 card added`. Verify with:

```
grep -c "Phase 5 / Import from ReliCheck" /Users/don/Projects/relicheck-mm-studio-mac/src/index.html
```

Expected: `1`.

## 3. Update capabilities

The new `survey_*` Tauri commands need to be allowed by the capabilities
file. We also keep the dialog permissions from Phase 3.

**In Terminal, run:**

```
cat > /tmp/phase5_caps.py << 'SCRIPT_EOF'
import json, pathlib
p = pathlib.Path('/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/capabilities/default.json')
cfg = json.loads(p.read_text())
# Permissions list stays the same; Tauri 2 lets Rust commands run by default
# once they're registered in the invoke_handler. We just make sure the
# opener plugin's open-url permission is there for the verification link.
perms = cfg.setdefault('permissions', [])
for needed in ['opener:default', 'opener:allow-open-url']:
    if needed not in perms:
        perms.append(needed)
p.write_text(json.dumps(cfg, indent=2))
print('capabilities ok:', perms)
SCRIPT_EOF
python3 /tmp/phase5_caps.py
```

Expected: a list including `opener:allow-open-url` at the end.

## 4. Build it in dev mode first

This is the long compile (~5-10 minutes first time, ~30 sec after that).
`reqwest` + `rustls` + `security-framework` pull in ~80 transitive crates
that Cargo has to compile from source the first time.

**In Terminal, run:**

```
cd /Users/don/Projects/relicheck-mm-studio-mac && npm run tauri dev
```

What to expect during the compile: a long scrolling list of
`Compiling <crate-name> v<version>` lines. You may also see one
warning about `Cargo.lock` regeneration on first run; that's fine.

Done when you see:
```
    Finished `dev` profile [unoptimized + debuginfo] target(s) in <time>
     Running `target/debug/relicheck-mm-studio-mac`
[mm-studio] python sidecar ready
```

App window opens. Six cards visible now: three Phase 1, Phase 3, Phase 4
(initially hidden), Phase 5 (new, with `Import from ReliCheck` button),
Phase 2.

## 5. Dev-mode end-to-end test

Click **Import from ReliCheck**. A modal appears with a fresh pairing
code in big letters. Click the link inside the modal — it should open
relichecksurvey.com/connect.html in your default browser. Sign in if
needed. Type the code. Click Approve device.

Within 3 seconds of the green "Done" in the browser, the desktop modal
should disappear and the Phase 5 card should show:
- `Signed in as <your email>` plus a Sign out button
- A list of your real surveys, each with a `Response count` and
  `Import` button

Click `Import` on any survey. Tauri calls `/api/v1/responses`, converts
the JSON to CSV, writes it to /tmp, then hands the path to the existing
ingest pipeline. The Phase 3 card lights up with a preview table.
Click Commit -> Phase 4 card appears -> run an analysis.

If all of that works in dev mode, stop the dev server (Ctrl+C in the
Terminal tab) and proceed to step 6 for the notarized DMG.

## 6. Production rebuild + notarize + M1 Pro

Same chain as Phase 3 and 4. The new dylibs (if any) get caught by the
existing find/codesign pre-pass.

**In Terminal, run:**

```
cd /Users/don/Projects/relicheck-mm-studio-mac && \
find src-tauri/resources/python -type f \( -name "*.so" -o -name "*.dylib" -o -name "python3*" -o -name "python" \) -exec codesign --force --options runtime --timestamp --sign "Developer ID Application: Relicheck LLC (W5H55SY5T5)" {} \; 2>&1 | tail -3 && \
npm run tauri build 2>&1 | tail -10
```

(The release build will also be slower this time -- ~3-5 minutes -- because
release-mode compiles of the rust HTTPS client are heavier than dev-mode.)

Then notarize:

```
xcrun notarytool submit "/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/target/release/bundle/dmg/ReliCheck MM Studio_0.1.0_aarch64.dmg" --keychain-profile "ReliCheck-Notary" --wait
```

Staple + verify:

```
xcrun stapler staple "/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/target/release/bundle/dmg/ReliCheck MM Studio_0.1.0_aarch64.dmg" && \
spctl -a -v -t open --context context:primary-signature "/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/target/release/bundle/dmg/ReliCheck MM Studio_0.1.0_aarch64.dmg"
```

Expected ending: `accepted, source=Notarized Developer ID`.

AirDrop the DMG to the M1 Pro. Delete the old install
(`rm -rf "/Applications/ReliCheck MM Studio.app"`), install, open,
repeat step 5's import flow on the M1 Pro. The Keychain token there
will be a separate entry from the Mac mini's — each Mac signs in
independently.
