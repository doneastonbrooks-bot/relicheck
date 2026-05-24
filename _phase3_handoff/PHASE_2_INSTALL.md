# Phase 2 install steps — Python sidecar

Everything in this batch goes into `/Users/don/Projects/relicheck-mm-studio-mac/`.
Three new files, two replacements, one Python install, and a small webview
smoke test. End state: app launches, spawns Python in the background,
webview button calls `engine_call`, gets back a real scipy result.

## 1. Drop in the new files

From this delivery folder, copy the following into your Mac project. Local
paths only:

- `engine/pyproject.toml`            → `/Users/don/Projects/relicheck-mm-studio-mac/engine/pyproject.toml`
- `engine/mm_engine/__init__.py`     → `/Users/don/Projects/relicheck-mm-studio-mac/engine/mm_engine/__init__.py`
- `engine/mm_engine/tests.py`        → `/Users/don/Projects/relicheck-mm-studio-mac/engine/mm_engine/tests.py`
- `engine/mm_engine/suggest.py`      → `/Users/don/Projects/relicheck-mm-studio-mac/engine/mm_engine/suggest.py`
- `engine/mm_engine/ipc.py`          → `/Users/don/Projects/relicheck-mm-studio-mac/engine/mm_engine/ipc.py`
- `engine/tests/__init__.py`         → `/Users/don/Projects/relicheck-mm-studio-mac/engine/tests/__init__.py`
- `engine/tests/test_parity.py`      → `/Users/don/Projects/relicheck-mm-studio-mac/engine/tests/test_parity.py`
- `src-tauri/src/python_sidecar.rs`  → `/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/src/python_sidecar.rs`

## 2. Replace these two existing files

- `src-tauri/src/main.rs`            → `/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/src/main.rs`
- `src-tauri/Cargo.toml`             → `/Users/don/Projects/relicheck-mm-studio-mac/src-tauri/Cargo.toml`

(Cargo.toml is unchanged in this batch — included for completeness so you
have a known-good copy. Phase 7 bundling will add deps.)

## 3. Install the Python deps locally (one-time, dev only)

Open Terminal and run:

```
cd /Users/don/Projects/relicheck-mm-studio-mac/engine
python3 -m pip install -e '.[dev]'
```

Then run the unit tests to confirm the engine works on your machine:

```
cd /Users/don/Projects/relicheck-mm-studio-mac/engine
python3 -m pytest -q
```

Expected: `23 passed in <1s`.

## 4. Smoke-test the IPC layer by hand

Still in Terminal:

```
cd /Users/don/Projects/relicheck-mm-studio-mac/engine
echo '{"id":1,"op":"ping"}' | python3 -u -m mm_engine.ipc
```

Expected output (two lines):

```
{"event": "ready", "version": "0.1.0"}
{"id": 1, "ok": true, "result": {"pong": true}}
```

If that works, the Python side is healthy.

## 5. Run the Tauri shell in dev mode

```
cd /Users/don/Projects/relicheck-mm-studio-mac
npm run tauri dev
```

The app window opens. In Terminal you should see:

```
[mm-studio] python sidecar ready
```

That confirms Rust spawned Python and got the greeting line.

## 6. Confirm the webview can call the engine

The default Tauri scaffold has a button in `src/main.js` that calls a
`greet` command. We need a button that calls `engine_call` instead.

Open `src/main.js` (or `src/App.svelte` / `src/App.jsx` depending on what
the scaffold gave you — Phase 1 used the vanilla TypeScript template, so
it's likely `src/main.ts`).

Find the existing button click handler (the one that calls
`invoke('greet', ...)`). Add a second handler that does:

```javascript
const { invoke } = window.__TAURI__.core;

document.querySelector('#sidecar-test').addEventListener('click', async () => {
  const r = await invoke('engine_call', {
    op: 'analysis.run',
    args: {
      test: 'pearson',
      a: [1,2,3,4,5,6,7,8,9,10],
      b: [2.1,4.0,5.8,8.2,9.9,11.7,14.3,15.8,18.4,19.7]
    }
  });
  document.querySelector('#sidecar-out').textContent = JSON.stringify(r, null, 2);
});
```

And a button + output area in `index.html`:

```html
<button id="sidecar-test">Run Pearson via sidecar</button>
<pre id="sidecar-out"></pre>
```

Click the button. The `<pre>` should fill with something like:

```json
{
  "ok": true,
  "result": {
    "ok": true,
    "test_name": "pearson",
    "statistic": 0.9991375859002241,
    "p_value": 2.4e-12,
    "effect_size": 0.9982759155585276,
    "effect_label": "r_squared",
    "n_total": 10,
    ...
  }
}
```

If that JSON shows up, **Phase 2 is functionally complete in dev mode.**
The remaining work (bundling Python into the .app, re-notarizing, etc.)
is Task 7 — a separate batch.

## What's still ahead

| Task | Status |
|---|---|
| Python engine + parity tests | done |
| Rust sidecar + Tauri command | this batch |
| Bundled Python in `.app` | Phase 2.2 (next batch) |
| Re-notarize the DMG with sidecar inside | Phase 2.2 |
| Verification recipe on a clean Mac | Phase 2.3 |
