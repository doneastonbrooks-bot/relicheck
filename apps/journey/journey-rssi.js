/* journey-rssi.js — wires the RSSI step-rail app to the ORIGINAL production RSSI
   calculation (unchanged), and gives the new app its OWN CSV upload entry (no detour
   through rssi-upload.php).

   - With ?dataset_id=N  → get.php → RSSI_TAG_CORE.materializeDataset →
     RSSI_MATH.computeLensesFromDataset → headline = Math.round(respondent_centered).
     This is the SAME Survey Strength Index score rssi-upload.php produces; no formula
     change. The four display cards map to production domain subscores (display only).
   - With no ?dataset_id  → shows the upload entry: parse a CSV, auto-detect columns
     via RSSI_TAG_CORE.inferColumnRoles(), save through the EXISTING standalone
     persistence (/api/datasets/create.php — same storage as rssi-upload.php; no
     second model), then redirect to ?dataset_id=NEW_ID. "View sample data" keeps the
     mock prototype so it is not the only no-dataset behavior. */
(function () {
  'use strict';

  var qs; try { qs = new URLSearchParams(window.location.search); } catch (e) { qs = null; }
  var dsId = qs ? qs.get('dataset_id') : null;
  var sample = qs ? (qs.get('sample') === '1' || qs.get('demo') === '1') : false;
  var C = 2 * Math.PI * 64;

  if (dsId) { hideUpload(); loadAndScore(dsId); }
  else if (sample) { hideUpload(); if (window.JOURNEY_GO) window.JOURNEY_GO('score'); }
  else { wireUpload(); }

  // Lens flip cards: click anywhere on a card to flip ring ⇄ definition.
  document.addEventListener('click', function (e) {
    var card = e.target.closest && e.target.closest('.rs-flip');
    if (card) card.classList.toggle('flipped');
  });

  function hideUpload() { var c = document.getElementById('rsUploadCard'); if (c) c.style.display = 'none'; }

  /* ───────── Shared scenario state — decisions collected across the labs ─────────
     Reliability Lab writes removedItems; Data Quality writes excludedRows + reasons;
     Validity Lab writes the construct model. Scenario Builder subscribes and re-scores
     the dataset minus those decisions. Reset propagates back to each lab. */
  var SCENARIO = { removedItems: [], excludedRows: {}, excludedReasons: {}, constructs: null, _subs: [], _resetters: [] };
  function scenarioNotify() { SCENARIO._subs.forEach(function (fn) { try { fn(); } catch (e) {} }); }
  function scenarioReset() { SCENARIO._resetters.forEach(function (fn) { try { fn(); } catch (e) {} }); }
  // Re-score the raw dataset dropping excluded respondent rows and removed Likert items.
  function rescoreDataset(excludedRows, removedItemsMap) {
    var TAG = window.RSSI_TAG_CORE, MATH = window.RSSI_MATH, raw = window.RSSI_APP_RAW;
    if (!TAG || !MATH || !raw || !raw.meta || !raw.data) return { score: null, n: 0, items: 0 };
    var meta = raw.meta, settings = raw.settings || {};
    var keepCol = []; meta.forEach(function (c, i) { if (removedItemsMap && removedItemsMap[c.name]) return; keepCol.push(i); });
    var headers = keepCol.map(function (i) { return meta[i].name; });
    var keptRows = raw.data.filter(function (row, r) { return !(excludedRows && excludedRows[r]); });
    var rows = keptRows.map(function (row) { var o = {}; keepCol.forEach(function (i) { o[meta[i].name] = (row[i] == null ? '' : String(row[i])); }); return o; });
    var fa = settings.likertPoints || 5;
    var roles = keepCol.map(function (i) { var c = meta[i]; return { name: c.name, role: c.type || 'ignore', construct: c.construct || '', reverseCoded: !!c.reverse, anchorCount: (c.type === 'likert') ? fa : null }; });
    var demo = keepCol.map(function (i) { return meta[i]; }).filter(function (c) { return c.type === 'demographic'; }).map(function (c) { return c.name; });
    var cfg = { reverse_coded_confirmed: !!settings.reverse_coded_confirmed, demographic_columns: demo };
    try {
      var dsx = TAG.materializeDataset({ headers: headers, rows: rows }, roles, cfg, raw.title || 'dataset');
      var merged = Object.assign({}, dsx.config || {}, { demographic_columns: demo });
      var lens = MATH.computeLensesFromDataset(dsx, merged);
      var rc = (lens && lens.rssi) ? lens.rssi.respondent_centered : null;
      var likertKept = roles.filter(function (r) { return r.role === 'likert'; }).length;
      return { score: (rc != null && isFinite(rc)) ? Math.round(rc) : null, n: keptRows.length, items: likertKept };
    } catch (e) { return { score: null, n: keptRows.length, items: 0 }; }
  }
  function sbStatus(msg, kind) {
    var s = document.getElementById('sbStatus'); if (!s) return;
    if (!msg) { s.style.display = 'none'; s.innerHTML = ''; return; }
    s.style.display = 'block'; s.className = 'rel-save-status ' + (kind || 'info'); s.innerHTML = msg;
  }

  /* ───────────── Load a saved dataset → score → render ─────────────
     Reproduces the ORIGINAL production RSSI calculation byte-for-byte:
     get.php (column_meta + raw data + settings) → RSSI_TAG_CORE.materializeDataset
     (role = column_meta.type, exactly like rssi-upload.js _hydrateTagState) →
     RSSI_MATH.computeLensesFromDataset → headline = Math.round(respondent_centered).
     This is the SAME score rssi-upload.php shows; no formula change. */
  function loadAndScore(id) {
    var TAG = window.RSSI_TAG_CORE, MATH = window.RSSI_MATH;
    if (!TAG || typeof TAG.materializeDataset !== 'function' ||
        !MATH || typeof MATH.computeLensesFromDataset !== 'function') {
      showLoadError('Scoring engine did not load. Please refresh.'); return;
    }
    fetch('/api/datasets/get.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        var d = out.body && out.body.dataset;
        if (!out.ok || !d) {
          showLoadError((out.body && (out.body.error_message || out.body.message)) || 'Could not load that dataset.');
          return;
        }
        var meta = d.column_meta || [];
        var rows2d = d.data || [];
        var settings = d.settings || {};
        // Rebuild the parser shape {headers, rows:[{header:value}]}.
        var headers = meta.map(function (c) { return c.name; });
        var rows = rows2d.map(function (row) {
          var o = {}; meta.forEach(function (c, i) { o[c.name] = (row[i] == null ? '' : String(row[i])); }); return o;
        });
        var parsed = { headers: headers, rows: rows };
        // columnRoles: role = column_meta.type, exactly as production reopen (_hydrateTagState).
        var fallbackAnchor = settings.likertPoints || 5;
        var columnRoles = meta.map(function (c) {
          return {
            name:         c.name,
            role:         c.type || 'ignore',
            construct:    c.construct || '',
            reverseCoded: !!c.reverse,
            anchorCount:  (c.type === 'likert') ? fallbackAnchor : null
          };
        });
        // respondent_centered depends on config.demographic_columns, which
        // materializeDataset strips from its own config — so we re-attach it the
        // same way production reopen does (all demographic-typed columns by name).
        var demographicCols = meta.filter(function (c) { return c.type === 'demographic'; })
                                  .map(function (c) { return c.name; });
        var config = { reverse_coded_confirmed: !!settings.reverse_coded_confirmed, demographic_columns: demographicCols };
        var dataset, lens;
        try {
          dataset = TAG.materializeDataset(parsed, columnRoles, config, d.title || 'Saved dataset');
          var merged = Object.assign({}, dataset.config || {}, { demographic_columns: demographicCols });
          lens = MATH.computeLensesFromDataset(dataset, merged);
        } catch (e) { showLoadError('Scoring error: ' + (e && e.message ? e.message : e)); return; }
        dataset.__title = d.title;
        dataset.__rowCount = (d.row_count != null ? d.row_count : rows.length);
        window.RSSI_APP_DATASET = dataset;
        window.RSSI_APP_RESULT = lens;
        // Raw payload kept so the Reliability Lab can save a trimmed copy with ALL
        // columns (demographics/identifiers/open text) carried over, not just Likert.
        window.RSSI_APP_RAW = { meta: meta, data: rows2d, settings: settings, title: d.title || 'Saved dataset' };
        render(lens, dataset);
        initReliabilityLab(dataset);
        initValidityLab(dataset);
        initItemAnalysis(dataset);
        initDataQuality(dataset);
        initScenarioBuilder();
        initReport();
        if (window.JOURNEY_GO) window.JOURNEY_GO('score');   // land on the wired Strength Score
      })
      .catch(function (e) { showLoadError('Network error: ' + (e && e.message ? e.message : e)); });
  }

  /* ───────────── Upload entry (CSV → save → ?dataset_id=NEW_ID) ───────────── */
  function wireUpload() {
    var drop = document.getElementById('rsDrop');
    var file = document.getElementById('rsFile');
    var sample = document.getElementById('rsViewSample');
    if (sample) sample.addEventListener('click', function (e) { e.preventDefault(); hideUpload(); });
    if (!drop || !file) return;
    drop.addEventListener('click', function () { file.click(); });
    file.addEventListener('change', function () { if (file.files && file.files[0]) handleFile(file.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('drag'); }); });
    drop.addEventListener('drop', function (e) { var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]; if (f) handleFile(f); });
  }

  function status(msg, kind) {
    var s = document.getElementById('rsUploadStatus'); if (!s) return;
    s.style.display = 'block'; s.className = 'rs-status ' + (kind || 'info'); s.innerHTML = msg;
  }

  function handleFile(f) {
    if (!/\.csv$/i.test(f.name) && f.type !== 'text/csv') { status('Please choose a CSV file. XLSX support is coming.', 'err'); return; }
    status('Reading <b>' + esc(f.name) + '</b> &hellip; ' + Math.round(f.size / 1024) + ' KB', 'info');
    var reader = new FileReader();
    reader.onload = function (ev) {
      try {
        var parsed = parseCSV(String(ev.target.result));
        if (!parsed.headers.length || !parsed.rows.length) { status('That file has no rows to score.', 'err'); return; }
        saveDataset(parsed, f.name);
      } catch (err) { status('Could not parse the file: ' + esc(err && err.message ? err.message : String(err)), 'err'); }
    };
    reader.onerror = function () { status('Could not read the file.', 'err'); };
    reader.readAsText(f);
  }

  function saveDataset(parsed, fileName) {
    status('Detecting columns and saving&hellip;', 'info');
    var roles = (window.RSSI_TAG_CORE && RSSI_TAG_CORE.inferColumnRoles) ? RSSI_TAG_CORE.inferColumnRoles(parsed) : null;
    // auto-detect role -> create.php type (only likert/numeric/binary are scorable).
    var typeMap = { likert: 'likert', numeric: 'numeric', demographic: 'demographic', free_text: 'open', identifier: 'identifier', categorical: 'single' };
    var column_meta = [];
    var maxAnchor = 5;
    parsed.headers.forEach(function (h, i) {
      var ar = (roles && roles[i]) ? roles[i].autoRole : 'open';
      if (roles && roles[i] && roles[i].autoAnchorCount) maxAnchor = Math.max(maxAnchor, roles[i].autoAnchorCount);
      column_meta.push({ name: h, type: (typeMap[ar] || 'open'), reverse: false });
    });
    var data = parsed.rows.map(function (r) {
      return parsed.headers.map(function (h) {
        var v = r[h];
        if (v === '' || v == null) return '';
        var n = Number(v);
        return (v !== '' && !isNaN(n) && isFinite(n)) ? n : v;   // keep numbers numeric
      });
    });
    var settings = { likertPoints: maxAnchor, likertLow: 'Strongly disagree', likertHigh: 'Strongly agree', reverse_coded_confirmed: false };
    var title = fileName.replace(/\.(csv|tsv|txt)$/i, '') || 'Uploaded dataset';

    fetch('/api/datasets/create.php', {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title: title, source_filename: fileName, source_format: 'csv', column_meta: column_meta, settings: settings, data: data })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        if (!out.ok || !out.body || !out.body.dataset || !out.body.dataset.id) {
          status('Save failed: ' + esc((out.body && (out.body.error_message || out.body.message)) || 'unknown error'), 'err');
          return;
        }
        status('Saved. Opening your RSSI&hellip;', 'info');
        window.location.href = '/rssi-app.php?dataset_id=' + encodeURIComponent(out.body.dataset.id);
      })
      .catch(function (e) { status('Save failed: ' + esc(e && e.message ? e.message : String(e)), 'err'); });
  }

  /* Minimal RFC-4180-ish CSV parser (quoted fields, embedded commas/newlines). */
  function parseCSV(text) {
    text = text.replace(/^﻿/, '');
    var rows = [], cur = [], val = '', i = 0, inQ = false, c;
    while (i < text.length) {
      c = text[i];
      if (inQ) {
        if (c === '"') { if (text[i + 1] === '"') { val += '"'; i += 2; continue; } inQ = false; i++; continue; }
        val += c; i++; continue;
      }
      if (c === '"') { inQ = true; i++; continue; }
      if (c === ',') { cur.push(val); val = ''; i++; continue; }
      if (c === '\r') { i++; continue; }
      if (c === '\n') { cur.push(val); rows.push(cur); cur = []; val = ''; i++; continue; }
      val += c; i++;
    }
    if (val !== '' || cur.length) { cur.push(val); rows.push(cur); }
    rows = rows.filter(function (r) { return r.length > 1 || (r.length === 1 && String(r[0]).trim() !== ''); });
    if (!rows.length) return { headers: [], rows: [] };
    var headers = rows[0].map(function (h, idx) { var s = String(h).trim(); return s === '' ? ('Column ' + (idx + 1)) : s; });
    var out = [];
    for (var r = 1; r < rows.length; r++) {
      var row = rows[r], obj = {};
      for (var k = 0; k < headers.length; k++) obj[headers[k]] = (k < row.length ? String(row[k]) : '');
      out.push(obj);
    }
    return { headers: headers, rows: out };
  }

  /* ───────────── Render the Strength Score from an engine result ───────────── */
  function setText(id, t) { var el = document.getElementById(id); if (el) el.textContent = t; }
  function setHTML(id, h) { var el = document.getElementById(id); if (el) el.innerHTML = h; }

  function showLoadError(msg) {
    setText('rsScoreNum', '—'); setText('rsScoreOut', '');
    setText('rsHeroHead', 'Could not load this dataset');
    setText('rsHeroSub', msg);
    var chip = document.getElementById('jrChip'); if (chip) chip.style.display = 'none';
    if (window.JOURNEY_GO) window.JOURNEY_GO('score');
  }

  // Production banding (verbatim from rssi-upload.js computeRSSI).
  function verdictFor(s) {
    return s >= 85 ? 'Excellent' : s >= 70 ? 'Strong' : s >= 55 ? 'Good' : s >= 40 ? 'Fair' : 'Weak';
  }
  var BAND_TINT = {
    Excellent: ['#e7f4ef', '#0a7a5f'], Strong: ['#e7f4ef', '#0a7a5f'],
    Good: ['#e7f4ef', '#0a7a5f'], Fair: ['#f8efdc', '#c8902a'], Weak: ['#fbe9e6', '#d04030']
  };
  // The three production lenses (each a different weighting of the same eight domains).
  var LENS_KEYS = ['psychometric_core', 'respondent_centered', 'validity_forward'];

  // Semantic score color: green = good, dark yellow = fair, red = poor.
  function bandClass(v) { return (v == null || !isFinite(v)) ? '' : (v >= 70 ? 'good' : v >= 40 ? 'warn' : 'bad'); }
  function bandColor(v) { return v >= 70 ? '#2FA85B' : v >= 40 ? '#C8902A' : '#E0402F'; }
  function conRow(labelHTML, score) {
    var c = bandClass(score), s = (score == null || !isFinite(score)) ? '—' : Math.round(score);
    return '<div class="rs-con"><span class="cn">' + labelHTML + '</span><span class="cv ' + c + '">' + s + '</span></div>';
  }
  function setStat(id, v) { var e = document.getElementById(id); if (e) e.textContent = String(v); }

  function render(lens, ds) {
    lens = lens || {};
    var rssi = lens.rssi || {};
    var subs = lens.domain_subscores || {};
    var rc = rssi.respondent_centered;
    var has = (rc != null && isFinite(rc));
    var strength = has ? Math.round(rc) : 0;
    var verdict = has ? verdictFor(strength) : 'Insufficient data to judge';

    // response + construct counts
    var vars = ds.variables || [];
    var likert = vars.filter(function (v) { return v.types && v.types[0] === 'likert'; });
    var cons = {}; likert.forEach(function (v) { var c = (v.construct || '').trim(); if (c) cons[c] = 1; });
    var nCon = Object.keys(cons).length;
    var totalN = (ds.__rowCount != null) ? ds.__rowCount : (ds.rowCount || (likert[0] && likert[0].values ? likert[0].values.length : 0));
    var title = ds.__title || ds.source || 'Saved dataset';

    setHTML('jrCtx', '<b>' + esc(title) + '</b> · ' + totalN + ' responses');
    var chip = document.getElementById('jrChip');
    if (chip) { chip.style.display = ''; setText('jrChipText', has ? ('RSSI ' + strength + ' / 100') : 'RSSI · withheld'); }

    // Three lens rings (Respondent-Centered highlighted). Each ring's color is
    // semantic (green/yellow/red by its own value); the headline is just larger.
    var Cr = 2 * Math.PI * 52;
    [].forEach.call(document.querySelectorAll('#rsLenses .rs-ringtile'), function (tile) {
      var lv = rssi[tile.getAttribute('data-lk')];
      var ok = (lv != null && isFinite(lv));
      var val = ok ? Math.round(lv) : 0;
      var fg = tile.querySelector('.fg');
      if (fg) {
        fg.setAttribute('stroke-dasharray', Cr.toFixed(2));
        fg.setAttribute('stroke-dashoffset', (Cr * (1 - Math.max(0, Math.min(100, val)) / 100)).toFixed(2));
        fg.style.stroke = ok ? bandColor(val) : '#c5cbd4';
      }
      var num = tile.querySelector('[data-f="num"]'); if (num) num.textContent = ok ? String(val) : '—';
    });

    var band = document.getElementById('rsBand');
    var NEUTRAL = ['#eef1f6', '#4a5578'];
    var tint = has ? (BAND_TINT[verdict] || NEUTRAL) : NEUTRAL;
    if (band) { band.style.background = tint[0]; band.style.color = tint[1]; }
    setText('rsBandText', has ? verdict : 'Insufficient data to judge');

    setText('rsHeroHead', has
      ? ('At ' + strength + ', this survey is in ' + verdict.toLowerCase() + ' shape.')
      : 'Not enough analyzable data to judge strength yet.');
    setText('rsHeroSub', has
      ? ((nCon ? (nCon + ' construct' + (nCon === 1 ? '' : 's') + ' · ') : '') + totalN + ' responses. This is the same Survey Strength Index score as your report. Explore the labs to see what drives it.')
      : 'Add more responses or tag your scales, then re-score.');
    setText('rsMetaResp', String(totalN));
    setText('rsMetaCon', String(nCon));
    setText('rsMetaWhen', 'just now');

    var fence = document.getElementById('rsFence');
    if (fence) fence.style.display = 'none';

    // Eight diagnostic domains — score 0–100, or "—" / dimmed when not evaluated (null).
    [].forEach.call(document.querySelectorAll('#rsDomains .jr-dom'), function (card) {
      var sv = subs[card.getAttribute('data-dk')];
      var na = (sv == null || !isFinite(sv));
      var val = na ? 0 : Math.max(0, Math.min(100, sv));
      var pts = card.querySelector('[data-f="pts"]'); if (pts) pts.innerHTML = na ? '&mdash;' : String(Math.round(sv));
      var mx = card.querySelector('[data-f="max"]'); if (mx) mx.parentNode.style.display = na ? 'none' : '';
      var bar = card.querySelector('[data-f="bar"]'); if (bar) bar.style.width = val + '%';
      var meter = card.querySelector('.jr-meter'); if (meter) meter.className = 'jr-meter ' + (na ? '' : bandClass(val));
      card.className = 'jr-dom' + (na ? ' is-na' : '');
    });

    // ── Orientation stat strip (wired to the loaded dataset) ──
    var ssum = ((lens.domain_details && lens.domain_details.reliability && lens.domain_details.reliability.diagnostics) || {}).scaleSummary || {};
    var conNames = Object.keys((function () { var m = {}; likert.forEach(function (v) { var c = (v.construct || '').trim(); if (c) m[c] = 1; }); return m; })());
    var miss = (ssum.missingRate != null && isFinite(ssum.missingRate)) ? ssum.missingRate : 0;
    setStat('rsOriResp', totalN);
    setStat('rsOriItems', likert.length);
    setStat('rsOriScales', conNames.length || (lens.dataset && lens.dataset.scaleCount) || 1);
    var ce = document.getElementById('rsOriComplete'); if (ce) ce.innerHTML = Math.round(100 - miss * 100) + '<small>%</small>';
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }

  /* ───────────── Reliability Lab — live Cronbach's α (reliability only) ─────────────
     Recomputes α and the item table from the production RSSI_MATH primitives as the
     user drags items out of / back into the scale. Does NOT touch the RSSI score. */
  function initReliabilityLab(ds) {
    var MATH = window.RSSI_MATH;
    var tbody = document.getElementById('relTableBody');
    var dropZone = document.getElementById('relRemovedZone');
    var scaleZone = document.getElementById('relScaleZone');
    if (!MATH || !tbody || !dropZone || !scaleZone) return;

    var items = (ds.variables || [])
      .filter(function (v) { return v.types && v.types[0] === 'likert'; })
      .map(function (v) { return { name: v.name, values: v.values || [] }; });
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="5" class="rel-placeholder">No Likert items in this dataset.</td></tr>'; return; }

    var removed = {};   // index → true
    var origAlpha = MATH.cronbachAlpha(items.map(function (it) { return it.values; }));
    var BAND_TINT = { good: ['#e6f6ec', '#1f7a44'], warn: ['#fbefd6', '#a86a14'], bad: ['#fbe3e0', '#b3271a'], neutral: ['#eceef3', '#5f6368'] };

    function incIdx() { var a = []; for (var i = 0; i < items.length; i++) if (!removed[i]) a.push(i); return a; }
    function vals(arr) { return arr.map(function (i) { return items[i].values; }); }
    function fmt(x) { return (x == null || !isFinite(x)) ? '—' : x.toFixed(2); }
    function signalFor(r) {
      if (r == null || !isFinite(r)) return { cls: 'warn', label: '—' };
      if (r < 0) return { cls: 'bad', label: 'Reverse?' };
      if (r < 0.30) return { cls: 'warn', label: 'Weak' };
      return { cls: 'good', label: 'Good' };
    }

    function recompute() {
      var inc = incIdx(), incVals = vals(inc);
      var alpha = (incVals.length >= 2) ? MATH.cronbachAlpha(incVals) : null;
      var nCases = incVals.length ? MATH.completeCases(incVals).length : 0;
      var band = MATH.bandForAlpha(alpha);

      var aEl = document.getElementById('relAlpha');
      if (aEl) { aEl.textContent = (alpha == null) ? '—' : alpha.toFixed(2); aEl.className = 'rel-alpha-num ' + (band.class || 'neutral'); }
      var bEl = document.getElementById('relBand');
      if (bEl) { var t = BAND_TINT[band.class] || BAND_TINT.neutral; bEl.textContent = band.label; bEl.style.background = t[0]; bEl.style.color = t[1]; }
      setText('relBandText', band.label);

      // reliability-only summary (no RSSI numbers)
      var nRem = Object.keys(removed).length;
      // The save affordance is offered the moment the scale is adjusted.
      var sBtn = document.getElementById('relSaveDs');
      if (sBtn) {
        sBtn.disabled = (nRem === 0);
        sBtn.textContent = nRem ? ('Save trimmed dataset (' + nRem + ' removed)') : 'Save trimmed dataset';
      }
      setText('relInCount', String(inc.length));
      setText('relOutCount', String(nRem));
      setText('relOrig', (origAlpha == null) ? '—' : origAlpha.toFixed(2));
      setText('relKept', String(inc.length));
      setText('relRemCount', String(nRem));
      var avgR = (incVals.length >= 2) ? MATH.avgInterItemR(incVals) : null;
      setText('relAvgR', (avgR == null || !isFinite(avgR)) ? '—' : avgR.toFixed(2));
      var dEl = document.getElementById('relDelta');
      if (dEl) {
        var delta = (alpha != null && origAlpha != null) ? (alpha - origAlpha) : null;
        dEl.textContent = (delta == null) ? '—' : ((delta > 0 ? '+' : '') + delta.toFixed(2));
        dEl.className = (delta == null || Math.abs(delta) < 0.005) ? 'neutral' : (delta > 0 ? 'good' : 'bad');
      }

      var rows = inc.map(function (i, pos) {
        var rest = (incVals.length >= 2) ? MATH.itemTotal(incVals, pos) : null;
        var without = inc.filter(function (k) { return k !== i; });
        var aIf = (without.length >= 2) ? MATH.cronbachAlpha(vals(without)) : null;
        var sig = signalFor(rest);
        return '<tr data-idx="' + i + '">'
          + '<td class="rel-item-name">' + esc(items[i].name) + '</td>'
          + '<td>' + fmt(rest) + '</td>'
          + '<td>' + fmt(aIf) + '</td>'
          + '<td><span class="sig ' + sig.cls + '">' + sig.label + '</span></td>'
          + '<td style="text-align:right"><button type="button" class="rel-act-btn" data-remove="' + i + '">Remove</button></td>'
          + '</tr>';
      }).join('');
      tbody.innerHTML = rows || '<tr><td colspan="5" class="rel-placeholder">All items removed — drag some back to compute α.</td></tr>';

      var out = Object.keys(removed).map(Number);
      dropZone.innerHTML = out.length
        ? out.map(function (i) {
            return '<div class="rel-chip" data-idx="' + i + '"><span class="rc-name">' + esc(items[i].name) + '</span><button type="button" class="rel-act-btn" data-return="' + i + '">Return</button></div>';
          }).join('')
        : '<div class="rel-drop-empty">Items you remove from the scale appear here. Press Return to put one back.</div>';

      // Publish removed items to the shared scenario state.
      SCENARIO.removedItems = out.map(function (i) { return items[i].name; });
      scenarioNotify();
    }
    SCENARIO._resetters.push(function () { removed = {}; recompute(); });

    // Remove / Return via buttons
    tbody.addEventListener('click', function (e) { var b = e.target.closest('[data-remove]'); if (b) { removed[+b.getAttribute('data-remove')] = true; recompute(); } });
    dropZone.addEventListener('click', function (e) { var b = e.target.closest('[data-return]'); if (b) { delete removed[+b.getAttribute('data-return')]; recompute(); } });

    // ─── Save the trimmed scale as a NEW dataset (original untouched) ───
    // Carries over every non-Likert column (demographics, identifiers, open text)
    // plus the Likert items still in the scale; drops only the removed items.
    // Clicking the button opens a modal asking for a title + description first.
    var saveBtn = document.getElementById('relSaveDs');
    var modal = document.getElementById('relSaveModal');
    var titleInp = document.getElementById('relDsTitle');
    var descInp = document.getElementById('relDsDesc');
    var confirmBtn = document.getElementById('relSaveConfirm');
    var cancelBtn = document.getElementById('relSaveCancel');

    function removedCount() {
      var rn = {}; Object.keys(removed).map(Number).forEach(function (i) { if (items[i]) rn[items[i].name] = true; });
      return Object.keys(rn).length;
    }
    function suggestedTitle() {
      var raw = window.RSSI_APP_RAW;
      var base = ((raw && raw.title) || 'Saved dataset').replace(/\s*\(reliability-trimmed[^)]*\)\s*$/i, '');
      var n = removedCount();
      return base + ' (reliability-trimmed, ' + n + ' item' + (n === 1 ? '' : 's') + ' removed)';
    }
    function openModal() {
      if (!modal) return;
      if (titleInp) titleInp.value = suggestedTitle();
      if (descInp) descInp.value = '';
      relModalStatus('');
      modal.style.display = 'flex'; modal.setAttribute('aria-hidden', 'false');
      if (titleInp) { titleInp.focus(); titleInp.select(); }
    }
    function closeModal() { if (modal) { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); } }

    if (saveBtn) saveBtn.addEventListener('click', openModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal && modal.style.display === 'flex') closeModal(); });

    if (confirmBtn) confirmBtn.addEventListener('click', function () {
      var raw = window.RSSI_APP_RAW;
      if (!raw || !raw.meta || !raw.data) { relModalStatus('Cannot save: the original dataset is not available. Reload and try again.', 'err'); return; }
      var title = (titleInp && titleInp.value.trim()) || suggestedTitle();
      var description = (descInp && descInp.value.trim()) || '';
      var removedNames = {};
      Object.keys(removed).map(Number).forEach(function (i) { if (items[i]) removedNames[items[i].name] = true; });
      // Kept column indices into the ORIGINAL meta/data (drop removed Likert items only).
      var keepIdx = [];
      raw.meta.forEach(function (c, i) { if (!removedNames[c.name]) keepIdx.push(i); });
      var newMeta = keepIdx.map(function (i) { return raw.meta[i]; });
      var newData = raw.data.map(function (row) { return keepIdx.map(function (i) { return (row[i] == null ? '' : row[i]); }); });
      var nDropped = Object.keys(removedNames).length;
      var baseTitle = (raw.title || 'Saved dataset').replace(/\s*\(reliability-trimmed[^)]*\)\s*$/i, '');
      var settings = Object.assign({}, raw.settings || {});
      if (description) settings.description = description;

      confirmBtn.disabled = true;
      relModalStatus('Saving the trimmed dataset&hellip;', 'info');
      fetch('/api/datasets/create.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: title, source_filename: (baseTitle + '-trimmed.csv'), source_format: 'csv', column_meta: newMeta, settings: settings, data: newData })
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (out) {
          confirmBtn.disabled = false;
          if (!out.ok || !out.body || !out.body.dataset || !out.body.dataset.id) {
            relModalStatus('Save failed: ' + esc((out.body && (out.body.error_message || out.body.message)) || 'unknown error'), 'err');
            return;
          }
          var url = '/rssi-app.php?dataset_id=' + encodeURIComponent(out.body.dataset.id);
          closeModal();
          relSaveStatus('Saved “' + esc(title) + '” as a new dataset (' + nDropped + ' item' + (nDropped === 1 ? '' : 's') + ' removed). <a href="' + url + '">Open it &rsaquo;</a> Your original dataset is unchanged.', 'good');
        })
        .catch(function (e) { confirmBtn.disabled = false; relModalStatus('Save failed: ' + esc(e && e.message ? e.message : String(e)), 'err'); });
    });

    recompute();
  }
  function relSaveStatus(msg, kind) {
    var s = document.getElementById('relSaveStatus'); if (!s) return;
    s.style.display = 'block'; s.className = 'rel-save-status ' + (kind || 'info'); s.innerHTML = msg;
  }
  function relModalStatus(msg, kind) {
    var s = document.getElementById('relModalStatus'); if (!s) return;
    if (!msg) { s.style.display = 'none'; s.innerHTML = ''; return; }
    s.style.display = 'block'; s.className = 'jr-modal-status ' + (kind || 'info'); s.innerHTML = msg;
  }

  /* ───────────── Item Analysis — per-item health (real, from RSSI_MATH) ─────────────
     For every Likert item: mean, SD, missing %, response distribution, and item-rest
     correlation (MATH.itemTotal vs the whole Likert set, same primitive the Reliability
     Lab uses). Flags (weak / reverse / low-variance / high-missingness / floor-ceiling)
     drive the centre table's Signal column, the left filters, and the right read-out.
     Non-destructive: this is inspection only; it never changes the RSSI score. */
  function initItemAnalysis(ds) {
    var MATH = window.RSSI_MATH;
    var body = document.getElementById('iaItemsBody');
    if (!MATH || !body) return;

    var items = (ds.variables || [])
      .filter(function (v) { return v.types && v.types[0] === 'likert'; })
      .map(function (v) { return { name: v.name, values: v.values || [], construct: (v.construct || '').trim(), reverse: !!v.reverse }; });
    if (!items.length) { body.innerHTML = '<tr><td colspan="6" class="rel-placeholder">No Likert items in this dataset.</td></tr>'; return; }

    // Anchor count: the largest whole response seen (capped at 11), default 5.
    var maxAnchor = 5;
    items.forEach(function (it) { it.values.forEach(function (v) { var n = Number(v); if (isFinite(n) && Math.round(n) > maxAnchor) maxAnchor = Math.round(n); }); });
    if (maxAnchor > 11) maxAnchor = 11;

    var allVals = items.map(function (it) { return it.values; });

    items.forEach(function (it, idx) {
      var nums = [], miss = 0;
      it.values.forEach(function (v) { if (v === '' || v == null || isNaN(Number(v))) { miss++; } else nums.push(Number(v)); });
      var n = nums.length;
      var mean = n ? nums.reduce(function (a, b) { return a + b; }, 0) / n : null;
      var sd = (n > 1) ? Math.sqrt(nums.reduce(function (a, b) { return a + (b - mean) * (b - mean); }, 0) / (n - 1)) : null;
      var total = it.values.length;
      var missPct = total ? Math.round(miss / total * 100) : 0;
      var dist = []; for (var b = 0; b < maxAnchor; b++) dist.push(0);
      nums.forEach(function (v) { var k = Math.round(v); if (k >= 1 && k <= maxAnchor) dist[k - 1]++; });
      var distPct = dist.map(function (c) { return n ? c / n * 100 : 0; });
      var rest = MATH.itemTotal(allVals, idx);
      var flags = {};
      if (rest != null && isFinite(rest) && rest >= 0 && rest < 0.30) flags.weak = true;
      if ((rest != null && isFinite(rest) && rest < 0) || it.reverse || /_r\b|reverse/i.test(it.name)) flags.reverse = true;
      if (sd != null && sd < 0.50) flags.lowvar = true;
      if (missPct > 10) flags.missing = true;
      var topPct = distPct[maxAnchor - 1] || 0, botPct = distPct[0] || 0;
      if (topPct >= 60 || botPct >= 60) flags.extreme = true;
      it.stats = { mean: mean, sd: sd, missPct: missPct, dist: distPct, rest: rest, flags: flags, n: n };
    });

    // Distinct constructs (fall back to one "All Likert items" group when untagged).
    var conNames = [];
    items.forEach(function (it) { if (it.construct && conNames.indexOf(it.construct) < 0) conNames.push(it.construct); });
    var untagged = conNames.length === 0;
    var groups = untagged ? ['All Likert items'] : conNames;
    function groupOf(it) { return untagged ? 'All Likert items' : (it.construct || '(no construct)'); }
    if (!untagged) { items.forEach(function (it) { if (!it.construct && groups.indexOf('(no construct)') < 0) groups.push('(no construct)'); }); }

    var conOn = {}; groups.forEach(function (g) { conOn[g] = true; });
    var flagOn = {};   // empty = no flag restriction

    var CB = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    var conWrap = document.getElementById('iaConstructFilters');
    var flagWrap = document.getElementById('iaFlagFilters');
    var countEl = document.getElementById('iaCount');
    var readout = document.getElementById('iaReadout');
    var selectedName = null;

    function fmt(x) { return (x == null || !isFinite(x)) ? '—' : x.toFixed(2); }
    function signalOf(f) {
      if (f.reverse) return { cls: 'bad', label: 'Reverse?' };
      if (f.weak) return { cls: 'warn', label: 'Weak' };
      if (f.extreme) return { cls: 'warn', label: 'Floor / ceiling' };
      if (f.lowvar) return { cls: 'warn', label: 'Low variance' };
      if (f.missing) return { cls: 'warn', label: 'Missing data' };
      return { cls: 'good', label: 'Healthy' };
    }

    // Left construct filters (built from the real groups).
    conWrap.innerHTML = groups.map(function (g) {
      var cnt = items.filter(function (it) { return groupOf(it) === g; }).length;
      return '<div class="jr-row" data-on="1" data-con="' + esc(g) + '"><span class="cb">' + CB + '</span><span class="lbl">' + esc(g) + '</span><span class="meta">' + cnt + '</span></div>';
    }).join('');

    // Flag counts in the left panel.
    var flagKeys = ['weak', 'reverse', 'lowvar', 'missing', 'extreme'];
    var flagTotals = {}; flagKeys.forEach(function (k) { flagTotals[k] = 0; });
    items.forEach(function (it) { flagKeys.forEach(function (k) { if (it.stats.flags[k]) flagTotals[k]++; }); });
    if (flagWrap) [].forEach.call(flagWrap.querySelectorAll('[data-flag]'), function (row) {
      var k = row.getAttribute('data-flag'); var c = row.querySelector('[data-flag-count]');
      if (c) c.textContent = flagTotals[k] || 0;
    });

    function visibleItems() {
      return items.filter(function (it) {
        if (!conOn[groupOf(it)]) return false;
        var activeFlags = flagKeys.filter(function (k) { return flagOn[k]; });
        if (activeFlags.length && !activeFlags.some(function (k) { return it.stats.flags[k]; })) return false;
        return true;
      });
    }

    function renderTable() {
      var vis = visibleItems();
      if (countEl) countEl.textContent = 'Items · ' + vis.length + ' of ' + items.length;
      if (!vis.length) { body.innerHTML = '<tr><td colspan="6" class="rel-placeholder">No items match these filters.</td></tr>'; return; }
      body.innerHTML = vis.map(function (it) {
        var s = it.stats, sig = signalOf(s.flags);
        var bars = s.dist.map(function (p) { return '<i style="height:' + Math.max(2, Math.round(p / 100 * 22)) + 'px"></i>'; }).join('');
        return '<tr data-item="' + esc(it.name) + '"' + (it.name === selectedName ? ' class="rowsel"' : '') + '>'
          + '<td class="item">' + esc(it.name) + '</td>'
          + '<td>' + (s.mean == null ? '—' : s.mean.toFixed(1)) + '</td>'
          + '<td>' + fmt(s.sd) + '</td>'
          + '<td><span class="jr-dist">' + bars + '</span></td>'
          + '<td>' + s.missPct + '%</td>'
          + '<td><span class="sig ' + sig.cls + '">' + sig.label + '</span></td></tr>';
      }).join('');
    }

    function interpret(it) {
      var f = it.stats.flags, s = it.stats, parts = [], actions = [];
      if (f.reverse) {
        parts.push('This item moves opposite to the rest of its scale (item-rest r ' + fmt(s.rest) + '). That usually means it is reverse-worded and was not reverse-scored, or it does not belong with these items.');
        actions.push('Reverse-score this item, then re-check the scale in the Reliability Lab.');
        actions.push('If it is not reverse-worded, consider dropping it.');
      } else if (f.weak) {
        parts.push('This item correlates only weakly with the rest of its scale (item-rest r ' + fmt(s.rest) + ', below 0.30), so it adds little to a consistent score.');
        actions.push('Reword it to point more clearly at the construct.');
        actions.push('Try removing it in the Reliability Lab and watch alpha respond.');
      }
      if (f.lowvar) { parts.push('Responses barely vary (SD ' + fmt(s.sd) + '). An item nearly everyone answers the same way cannot separate respondents.'); actions.push('Reword so it discriminates between people who differ.'); }
      if (f.extreme) { parts.push('Answers pile up at one end of the scale (a floor or ceiling), so the item is not capturing a range of views.'); actions.push('Reword or rescale so responses spread out.'); }
      if (f.missing) { parts.push(s.missPct + '% of respondents skipped this item; high missingness can bias the scale.'); actions.push('Check the wording for confusion or sensitivity and consider why people skip it.'); }
      if (!parts.length) { parts.push('This item looks healthy: it varies normally, has little missing data, and agrees with the rest of its scale (item-rest r ' + fmt(s.rest) + '). No action needed.'); }
      return { text: parts.join(' '), actions: actions };
    }

    var CHECK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    function renderReadout() {
      if (!readout) return;
      var it = null; for (var i = 0; i < items.length; i++) if (items[i].name === selectedName) { it = items[i]; break; }
      if (!it) { readout.innerHTML = '<p class="jr-read-empty" style="font-size:13px;color:var(--ink-5)">Select an item in the table to see what its statistics mean and what you can do about it.</p>'; return; }
      var s = it.stats, info = interpret(it);
      var metaBits = [groupOf(it), 'mean ' + (s.mean == null ? '—' : s.mean.toFixed(1)), 'SD ' + fmt(s.sd), 'item-rest r ' + fmt(s.rest), 'missing ' + s.missPct + '%', 'n ' + s.n];
      readout.innerHTML = '<h4>' + esc(it.name) + '</h4>'
        + '<div class="meta">' + metaBits.map(esc).join(' · ') + '</div>'
        + '<p>' + esc(info.text) + '</p>'
        + (info.actions.length ? '<ul class="jr-act-list">' + info.actions.map(function (a) { return '<li><span class="k">' + CHECK + '</span>' + esc(a) + '</li>'; }).join('') + '</ul>' : '');
    }

    // Wiring: construct + flag filter toggles, row selection.
    conWrap.addEventListener('click', function (e) {
      var row = e.target.closest('[data-con]'); if (!row) return;
      var g = row.getAttribute('data-con'); conOn[g] = !conOn[g];
      row.setAttribute('data-on', conOn[g] ? '1' : '0'); renderTable();
    });
    if (flagWrap) flagWrap.addEventListener('click', function (e) {
      var row = e.target.closest('[data-flag]'); if (!row) return;
      var k = row.getAttribute('data-flag'); flagOn[k] = !flagOn[k];
      row.setAttribute('data-on', flagOn[k] ? '1' : '0'); renderTable();
    });
    body.addEventListener('click', function (e) {
      var tr = e.target.closest('[data-item]'); if (!tr) return;
      selectedName = tr.getAttribute('data-item');
      [].forEach.call(body.querySelectorAll('tr'), function (r) { r.classList.toggle('rowsel', r.getAttribute('data-item') === selectedName); });
      renderReadout();
    });

    renderTable();
    renderReadout();
  }

  /* ───────────── Data Quality — respondent-level screening (real) ─────────────
     Per respondent, from the raw rows: straight-lining (no within-person spread),
     inconsistency (forward vs reverse-scored items disagree), and incompleteness.
     Toggling a flag on the left excludes that group; the RSSI impact panel RE-SCORES
     the dataset minus the excluded rows through the SAME pipeline as the initial load
     (materializeDataset → computeLensesFromDataset → respondent_centered), so the
     number is the real Strength Score, not an estimate. Preview only; saved data is
     never mutated here. */
  function initDataQuality(ds) {
    var TAG = window.RSSI_TAG_CORE, MATH = window.RSSI_MATH, raw = window.RSSI_APP_RAW;
    var body = document.getElementById('dqRespBody');
    if (!TAG || !MATH || !raw || !raw.meta || !raw.data || !body) return;

    var likertCols = [];
    raw.meta.forEach(function (c, i) { if (c.type === 'likert') likertCols.push({ idx: i, name: c.name, reverse: !!c.reverse || /_r\b|reverse/i.test(c.name) }); });
    if (!likertCols.length) { body.innerHTML = '<tr><td colspan="4" class="rel-placeholder">No Likert items to screen.</td></tr>'; return; }

    var maxAnchor = 5;
    raw.data.forEach(function (row) { likertCols.forEach(function (c) { var n = Number(row[c.idx]); if (isFinite(n) && Math.round(n) > maxAnchor) maxAnchor = Math.round(n); }); });
    if (maxAnchor > 11) maxAnchor = 11;

    function mean(a) { return a.length ? a.reduce(function (x, y) { return x + y; }, 0) / a.length : null; }

    // Per-respondent diagnostics.
    var resp = raw.data.map(function (row, r) {
      var vals = [], fwd = [], rev = [], miss = 0;
      likertCols.forEach(function (c) {
        var v = row[c.idx], n = Number(v);
        if (v === '' || v == null || isNaN(n)) { miss++; }
        else { vals.push(n); if (c.reverse) rev.push(n); else fwd.push(n); }
      });
      var total = likertCols.length, answered = vals.length;
      var missPct = total ? miss / total : 0;
      var sd = null;
      if (answered > 1) { var m = mean(vals); sd = Math.sqrt(vals.reduce(function (a, b) { return a + (b - m) * (b - m); }, 0) / (answered - 1)); }
      var flags = {};
      if (answered >= Math.max(3, Math.ceil(total * 0.5)) && sd != null && sd < 0.30) flags.straight = true;
      if (fwd.length >= 2 && rev.length >= 1) {
        var revScored = rev.map(function (x) { return (maxAnchor + 1) - x; });
        if (Math.abs(mean(fwd) - mean(revScored)) >= 1.5) flags.inconsistent = true;
      }
      if (missPct > 0.20) flags.incomplete = true;
      return { id: 'R' + (r + 1), idx: r, vals: vals, answered: answered, missPct: missPct, sd: sd, flags: flags };
    });

    var flagKeys = ['straight', 'inconsistent', 'incomplete'];
    var flagLabel = { straight: 'Straight-liner', inconsistent: 'Inconsistent', incomplete: 'Incomplete' };
    var totals = {}; flagKeys.forEach(function (k) { totals[k] = 0; });
    resp.forEach(function (p) { flagKeys.forEach(function (k) { if (p.flags[k]) totals[k]++; }); });

    var flagWrap = document.getElementById('dqFlagFilters');
    var countEl = document.getElementById('dqCount');
    var excludeOn = {};   // flag → exclude this group?
    if (flagWrap) [].forEach.call(flagWrap.querySelectorAll('[data-flag]'), function (row) {
      var k = row.getAttribute('data-flag'); var c = row.querySelector('[data-flag-count]');
      if (c) c.textContent = totals[k] || 0;
    });

    var flagged = resp.filter(function (p) { return flagKeys.some(function (k) { return p.flags[k]; }); });

    // Original score from the already-computed result (no recompute needed).
    var origRC = window.RSSI_APP_RESULT && window.RSSI_APP_RESULT.rssi ? window.RSSI_APP_RESULT.rssi.respondent_centered : null;
    var origScore = (origRC != null && isFinite(origRC)) ? Math.round(origRC) : null;
    var totalN = raw.data.length;

    // Re-score the dataset with a set of excluded row indices, via the load pipeline.
    function rescore(excludedSet) {
      var meta = raw.meta, settings = raw.settings || {};
      var headers = meta.map(function (c) { return c.name; });
      var kept = raw.data.filter(function (row, i) { return !excludedSet[i]; });
      var rows = kept.map(function (row) { var o = {}; meta.forEach(function (c, i) { o[c.name] = (row[i] == null ? '' : String(row[i])); }); return o; });
      var fallbackAnchor = settings.likertPoints || 5;
      var columnRoles = meta.map(function (c) {
        return { name: c.name, role: c.type || 'ignore', construct: c.construct || '', reverseCoded: !!c.reverse, anchorCount: (c.type === 'likert') ? fallbackAnchor : null };
      });
      var demographicCols = meta.filter(function (c) { return c.type === 'demographic'; }).map(function (c) { return c.name; });
      var config = { reverse_coded_confirmed: !!settings.reverse_coded_confirmed, demographic_columns: demographicCols };
      try {
        var dataset = TAG.materializeDataset({ headers: headers, rows: rows }, columnRoles, config, raw.title || 'dataset');
        var merged = Object.assign({}, dataset.config || {}, { demographic_columns: demographicCols });
        var lens = MATH.computeLensesFromDataset(dataset, merged);
        var rc = (lens && lens.rssi) ? lens.rssi.respondent_centered : null;
        return { score: (rc != null && isFinite(rc)) ? Math.round(rc) : null, n: kept.length };
      } catch (e) { return { score: null, n: kept.length }; }
    }

    function excludedIndices() {
      var set = {};
      resp.forEach(function (p) { if (flagKeys.some(function (k) { return excludeOn[k] && p.flags[k]; })) set[p.idx] = true; });
      return set;
    }
    function isExcluded(p) { return flagKeys.some(function (k) { return excludeOn[k] && p.flags[k]; }); }

    function patternBars(p) {
      // A sparkline of this respondent's answers; flat = straight-lining.
      return '<span class="jr-dist">' + p.vals.slice(0, 16).map(function (v) {
        return '<i style="height:' + Math.max(2, Math.round(v / maxAnchor * 20)) + 'px"></i>';
      }).join('') + '</span>';
    }
    function flagChipsFor(p) {
      var ks = flagKeys.filter(function (k) { return p.flags[k]; });
      return ks.map(function (k) { return '<span class="sig ' + (excludeOn[k] ? 'bad' : 'warn') + '">' + flagLabel[k] + '</span>'; }).join(' ');
    }

    function renderTable() {
      if (!flagged.length) { body.innerHTML = '<tr><td colspan="4" class="rel-placeholder">No quality issues detected. Every respondent looks attentive.</td></tr>'; if (countEl) countEl.textContent = 'Respondents · 0 flagged'; return; }
      if (countEl) countEl.textContent = 'Respondents · ' + flagged.length + ' flagged of ' + totalN;
      body.innerHTML = flagged.map(function (p) {
        var ex = isExcluded(p);
        return '<tr><td class="item">' + p.id + '</td>'
          + '<td>' + patternBars(p) + '</td>'
          + '<td>' + flagChipsFor(p) + '</td>'
          + '<td>' + (ex ? '<span class="sig bad">Excluded</span>' : '<span class="sig good">Kept</span>') + '</td></tr>';
      }).join('');
    }

    function publishScenario() {
      var set = excludedIndices();
      SCENARIO.excludedRows = set;
      var reasons = {};
      flagKeys.forEach(function (k) { if (excludeOn[k]) reasons[k] = 0; });
      resp.forEach(function (p) { flagKeys.forEach(function (k) { if (excludeOn[k] && p.flags[k]) reasons[k]++; }); });
      SCENARIO.excludedReasons = reasons;
      scenarioNotify();
    }

    function renderImpact() {
      publishScenario();
      var setText2 = function (id, t) { var el = document.getElementById(id); if (el) el.textContent = t; };
      setText2('dqAll', (origScore == null ? '—' : origScore) + ' · n ' + totalN);
      var anyExcluded = flagKeys.some(function (k) { return excludeOn[k]; });
      if (!anyExcluded) {
        setText2('dqBig', origScore == null ? '—' : String(origScore));
        setText2('dqDelta', 'No exclusions yet');
        setText2('dqKept', (origScore == null ? '—' : origScore) + ' · n ' + totalN);
        setText2('dqExcl', '0');
        return;
      }
      var set = excludedIndices(), nExcl = Object.keys(set).length;
      var res = rescore(set);
      setText2('dqBig', res.score == null ? '—' : String(res.score));
      setText2('dqKept', (res.score == null ? '—' : res.score) + ' · n ' + res.n);
      setText2('dqExcl', String(nExcl));
      var delEl = document.getElementById('dqDelta');
      if (delEl) {
        if (res.score == null || origScore == null) { delEl.textContent = '—'; delEl.className = 'delta'; }
        else { var d = res.score - origScore; delEl.textContent = (d > 0 ? '+' : '') + d + ' vs all responses'; delEl.className = 'delta'; }
      }
    }

    if (flagWrap) flagWrap.addEventListener('click', function (e) {
      var row = e.target.closest('[data-flag]'); if (!row) return;
      var k = row.getAttribute('data-flag'); excludeOn[k] = !excludeOn[k];
      row.setAttribute('data-on', excludeOn[k] ? '1' : '0');
      renderTable(); renderImpact();
    });
    SCENARIO._resetters.push(function () {
      flagKeys.forEach(function (k) {
        excludeOn[k] = false;
        var row = flagWrap && flagWrap.querySelector('[data-flag="' + k + '"]');
        if (row) row.setAttribute('data-on', '0');
      });
      renderTable(); renderImpact();
    });

    renderTable();
    renderImpact();
  }

  /* ───────────── Scenario Builder — collect lab decisions, compare, save (real) ─────────────
     Subscribes to the shared SCENARIO state. Shows original vs revised RSSI (revised =
     rescoreDataset minus removed items and excluded respondents), lists every decision,
     and can save the revised dataset as a NEW dataset (original untouched). Reset clears
     the decisions back through each lab. */
  function initScenarioBuilder() {
    if (!document.getElementById('sbOrigNum')) return;
    var raw = window.RSSI_APP_RAW;
    var totalN = raw ? raw.data.length : 0;
    var totalLikert = raw ? raw.meta.filter(function (c) { return c.type === 'likert'; }).length : 0;
    var origRC = window.RSSI_APP_RESULT && window.RSSI_APP_RESULT.rssi ? window.RSSI_APP_RESULT.rssi.respondent_centered : null;
    var origScore = (origRC != null && isFinite(origRC)) ? Math.round(origRC) : null;
    var LBL = { straight: 'Straight-liners', inconsistent: 'Inconsistent', incomplete: 'Incomplete' };
    function set3(id, t) { var e = document.getElementById(id); if (e) e.textContent = t; }
    function setH(id, h) { var e = document.getElementById(id); if (e) e.innerHTML = h; }

    function render() {
      var removedMap = {}; SCENARIO.removedItems.forEach(function (n) { removedMap[n] = true; });
      var nRemoved = SCENARIO.removedItems.length;
      var exclSet = SCENARIO.excludedRows || {}, nExcl = Object.keys(exclSet).length;
      var changed = nRemoved > 0 || nExcl > 0;

      set3('sbOrigNum', origScore == null ? '—' : String(origScore));
      set3('sbOrigBd', 'All ' + totalN + ' responses · ' + totalLikert + ' items');
      var rev = changed ? rescoreDataset(exclSet, removedMap) : { score: origScore, n: totalN, items: totalLikert };
      set3('sbRevNum', rev.score == null ? '—' : String(rev.score));
      set3('sbRevBd', rev.n + ' responses · ' + rev.items + ' items');

      setH('sbItemsRemoved', nRemoved
        ? SCENARIO.removedItems.map(function (n) { return '<li><span>' + esc(n) + '</span><b>&minus;</b></li>'; }).join('')
        : '<li class="none">None removed in the Reliability Lab.</li>');
      set3('sbItemsRemovedCount', String(nRemoved));
      setH('sbItemsKept', '<li><span>Likert items in the score</span><b>' + rev.items + ' / ' + totalLikert + '</b></li>');

      var reasons = SCENARIO.excludedReasons || {};
      var rkeys = Object.keys(reasons).filter(function (k) { return reasons[k] > 0; });
      setH('sbRespExcl', nExcl
        ? rkeys.map(function (k) { return '<li><span>' + (LBL[k] || k) + '</span><b>' + reasons[k] + '</b></li>'; }).join('')
        : '<li class="none">None excluded in Data Quality.</li>');
      set3('sbRespCount', String(nExcl));

      setH('sbConstructs', (SCENARIO.constructs && SCENARIO.constructs.length)
        ? SCENARIO.constructs.map(function (c) { return '<li><span>' + esc(c.name) + '</span><b>' + c.items.length + '</b></li>'; }).join('')
        : '<li class="none">No model built in the Validity Lab.</li>');

      var co = document.getElementById('sbCallout');
      if (co) {
        if (!changed) { co.style.display = 'none'; }
        else {
          var msgs = [];
          if (nRemoved > 0) msgs.push('Removing items can raise reliability while narrowing what a scale measures; make sure each construct still covers the idea you set out to measure.');
          if (nExcl > 0) msgs.push('Excluding ' + nExcl + ' of ' + totalN + ' responses changes your sample; keep enough responses for the result to stay defensible.');
          var d = (rev.score != null && origScore != null) ? (rev.score - origScore) : null;
          if (d != null) msgs.push('The revised Strength Score is ' + (d > 0 ? '+' + d : '' + d) + ' versus the original. A higher number is not automatically a better survey.');
          set3('sbCalloutP', msgs.join(' '));
          co.style.display = '';
        }
      }
    }
    SCENARIO._subs.push(render);

    var resetBtn = document.getElementById('sbReset');
    if (resetBtn) resetBtn.addEventListener('click', function (e) { e.preventDefault(); scenarioReset(); sbStatus(''); render(); });

    var saveBtn = document.getElementById('sbSave');
    if (saveBtn) saveBtn.addEventListener('click', function (e) {
      e.preventDefault();
      var raw2 = window.RSSI_APP_RAW; if (!raw2) { sbStatus('Cannot save: the dataset is unavailable.', 'err'); return; }
      var removedMap = {}; SCENARIO.removedItems.forEach(function (n) { removedMap[n] = true; });
      var exclSet = SCENARIO.excludedRows || {};
      var nRemoved = SCENARIO.removedItems.length, nExcl = Object.keys(exclSet).length;
      if (!nRemoved && !nExcl) { sbStatus('No changes to save yet. Remove items in the Reliability Lab or exclude respondents in Data Quality first.', 'info'); return; }
      var meta = raw2.meta, keepCol = [];
      meta.forEach(function (c, i) { if (!removedMap[c.name]) keepCol.push(i); });
      var newMeta = keepCol.map(function (i) { return meta[i]; });
      var newData = raw2.data.filter(function (row, r) { return !exclSet[r]; }).map(function (row) { return keepCol.map(function (i) { return (row[i] == null ? '' : row[i]); }); });
      var base = (raw2.title || 'Saved dataset').replace(/\s*\(revised scenario[^)]*\)\s*$/i, '');
      var title = base + ' (revised scenario)';
      saveBtn.style.pointerEvents = 'none';
      sbStatus('Saving the revised scenario&hellip;', 'info');
      fetch('/api/datasets/create.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ title: title, source_filename: base + '-scenario.csv', source_format: 'csv', column_meta: newMeta, settings: raw2.settings || {}, data: newData }) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (out) {
          saveBtn.style.pointerEvents = '';
          if (!out.ok || !out.body || !out.body.dataset || !out.body.dataset.id) { sbStatus('Save failed: ' + esc((out.body && (out.body.error_message || out.body.message)) || 'unknown error'), 'err'); return; }
          var url = '/rssi-app.php?dataset_id=' + encodeURIComponent(out.body.dataset.id);
          sbStatus('Saved “' + esc(title) + '” (' + nRemoved + ' item' + (nRemoved === 1 ? '' : 's') + ' removed, ' + nExcl + ' response' + (nExcl === 1 ? '' : 's') + ' excluded). <a href="' + url + '">Open it &rsaquo;</a> Your original dataset is unchanged.', 'good');
        })
        .catch(function (e2) { saveBtn.style.pointerEvents = ''; sbStatus('Save failed: ' + esc(e2 && e2.message ? e2.message : String(e2)), 'err'); });
    });

    render();
  }

  /* ───────────── Report — printable reports built from live data + scenario ─────────────
     Each card opens a self-contained print window (Print / Save-as-PDF). The Official
     report reads the live lens result; Revised reads the scenario decisions and re-scores;
     Construct/Item recompute from the dataset; Appendix + Summary are derived prose. */
  var REPORT_LENS = [['psychometric_core', 'Psychometric Core', 'statistical stability and structure'], ['respondent_centered', 'Respondent-Centered', 'clarity and answerability (the headline)'], ['validity_forward', 'Validity-Forward', 'whether it measures what it claims']];
  var REPORT_DOMAINS = [['reliability', 'Reliability'], ['item_prompt_quality', 'Item & Prompt Quality'], ['bias_clarity', 'Bias & Clarity Review'], ['factor_readiness', 'Factor Readiness'], ['response_scale_review', 'Response Scale Review'], ['validity', 'Validity'], ['construct_alignment', 'Construct Alignment'], ['scale_structure', 'Scale Structure']];
  function reportLogoImg() {
    var o = (typeof location !== 'undefined' && location.origin) ? location.origin : '';
    return '<img src="' + o + '/RSSI-logo.png" alt="ReliCheck Survey Strength Index" style="height:34px;width:auto;display:block;margin:0 0 16px">';
  }
  function rptNum(v) { return (v == null || !isFinite(v)) ? '—' : String(Math.round(v)); }
  function rptFix(v) { return (v == null || !isFinite(v)) ? '—' : v.toFixed(2); }
  function reportItemStats() {
    var MATH = window.RSSI_MATH, ds = window.RSSI_APP_DATASET; if (!MATH || !ds) return [];
    var items = (ds.variables || []).filter(function (v) { return v.types && v.types[0] === 'likert'; }).map(function (v) { return { name: v.name, values: v.values || [], reverse: !!v.reverse }; });
    var allVals = items.map(function (it) { return it.values; });
    return items.map(function (it, idx) {
      var nums = [], miss = 0; it.values.forEach(function (v) { var n = Number(v); if (v === '' || v == null || isNaN(n)) miss++; else nums.push(n); });
      var n = nums.length, mean = n ? nums.reduce(function (a, b) { return a + b; }, 0) / n : null;
      var sd = (n > 1) ? Math.sqrt(nums.reduce(function (a, b) { return a + (b - mean) * (b - mean); }, 0) / (n - 1)) : null;
      var missPct = it.values.length ? Math.round(miss / it.values.length * 100) : 0;
      var rest = MATH.itemTotal(allVals, idx);
      var sig = 'Healthy';
      if (rest != null && isFinite(rest) && rest < 0) sig = 'Reverse?';
      else if (rest != null && isFinite(rest) && rest < 0.30) sig = 'Weak';
      else if (sd != null && sd < 0.50) sig = 'Low variance';
      else if (missPct > 10) sig = 'Missing data';
      return { name: it.name, mean: mean, sd: sd, missPct: missPct, rest: rest, sig: sig };
    });
  }
  function initReport() {
    var wrap = document.querySelector('[data-step="report"] .jr-reports');
    if (!wrap) return;
    var selected = 'official';
    function select(t) { selected = t; [].forEach.call(wrap.querySelectorAll('[data-report]'), function (c) { c.setAttribute('data-sel', c.getAttribute('data-report') === t ? '1' : '0'); }); }
    wrap.addEventListener('click', function (e) { var c = e.target.closest('[data-report]'); if (!c) return; e.preventDefault(); select(c.getAttribute('data-report')); buildReport(c.getAttribute('data-report')); });
    var gen = document.getElementById('rptGenerate'); if (gen) gen.addEventListener('click', function (e) { e.preventDefault(); buildReport(selected); });
    var all = document.getElementById('rptAll'); if (all) all.addEventListener('click', function (e) { e.preventDefault(); buildReport('all'); });
    select('official');
  }
  function buildReport(type) {
    var lens = window.RSSI_APP_RESULT || {}, ds = window.RSSI_APP_DATASET || {}, raw = window.RSSI_APP_RAW;
    var rssi = lens.rssi || {}, subs = lens.domain_subscores || {};
    var title = (raw && raw.title) || ds.__title || 'Saved dataset';
    var when = new Date().toLocaleString();
    var totalN = (ds.__rowCount != null) ? ds.__rowCount : (raw ? raw.data.length : 0);
    var likertCount = raw ? raw.meta.filter(function (c) { return c.type === 'likert'; }).length : 0;
    var rc = rssi.respondent_centered;
    var score = (rc != null && isFinite(rc)) ? Math.round(rc) : null;
    var band = (score != null) ? verdictFor(score) : 'Insufficient data to judge';
    function claimText(b) {
      if (b === 'Excellent' || b === 'Strong') return 'The evidence supports interpreting and reporting these scores with confidence. State the construct each score represents and the sample it rests on.';
      if (b === 'Good') return 'The evidence supports interpretation with minor cautions. Report the scores, but note any weak items or thin constructs.';
      if (b === 'Fair') return 'Interpret with care. Treat the scores as provisional and address the weaker domains before making strong claims.';
      return 'Do not over-rely on these scores yet. Strengthen reliability, validity, or sample size before drawing firm conclusions.';
    }
    function sec(t, h) { return '<h2>' + t + '</h2>' + h; }
    function officialSection() {
      var lensRows = REPORT_LENS.map(function (L) { return '<tr><td class="nm">' + L[1] + '</td><td>' + rptNum(rssi[L[0]]) + '</td><td>' + L[2] + '</td></tr>'; }).join('');
      var domRows = REPORT_DOMAINS.map(function (D) { var v = subs[D[0]]; return '<tr><td class="nm">' + D[1] + '</td><td>' + (v == null || !isFinite(v) ? 'not evaluated' : rptNum(v)) + '</td></tr>'; }).join('');
      return sec('Official RSSI result',
        '<p class="big">' + (score == null ? '—' : score) + ' <span class="muted">/ 100 · ' + esc(band) + '</span></p>'
        + '<p>Based on all <b>' + totalN + '</b> responses across <b>' + likertCount + '</b> Likert items. The headline is the Respondent-Centered lens of the Survey Strength Index.</p>'
        + '<h3>Three lenses</h3><table class="rep-tbl"><thead><tr><th>Lens</th><th>Score</th><th>Emphasis</th></tr></thead><tbody>' + lensRows + '</tbody></table>'
        + '<h3>Eight diagnostic domains</h3><table class="rep-tbl"><thead><tr><th>Domain</th><th>Score</th></tr></thead><tbody>' + domRows + '</tbody></table>'
        + '<h3>What you can say</h3><p>' + claimText(band) + '</p>'
        + '<p class="muted">Reliability (internal consistency) is necessary but not sufficient; a consistent scale can still measure the wrong thing. Read this score alongside validity and item analysis.</p>');
    }
    function revisedSection() {
      var removedMap = {}; SCENARIO.removedItems.forEach(function (n) { removedMap[n] = true; });
      var nRemoved = SCENARIO.removedItems.length, exclSet = SCENARIO.excludedRows || {}, nExcl = Object.keys(exclSet).length;
      var changed = nRemoved > 0 || nExcl > 0;
      var rev = changed ? rescoreDataset(exclSet, removedMap) : { score: score, n: totalN, items: likertCount };
      var reasons = SCENARIO.excludedReasons || {}, LBL = { straight: 'Straight-liners', inconsistent: 'Inconsistent', incomplete: 'Incomplete' };
      var rkeys = Object.keys(reasons).filter(function (k) { return reasons[k] > 0; });
      return sec('Revised scenario',
        '<table class="rep-tbl"><thead><tr><th></th><th>Original</th><th>Revised</th></tr></thead><tbody>'
        + '<tr><td class="nm">Strength Score</td><td>' + (score == null ? '—' : score) + '</td><td>' + (rev.score == null ? '—' : rev.score) + '</td></tr>'
        + '<tr><td class="nm">Responses</td><td>' + totalN + '</td><td>' + rev.n + '</td></tr>'
        + '<tr><td class="nm">Likert items</td><td>' + likertCount + '</td><td>' + rev.items + '</td></tr></tbody></table>'
        + '<h3>Decisions logged</h3>'
        + '<p><b>Items removed (' + nRemoved + '):</b> ' + (nRemoved ? SCENARIO.removedItems.map(esc).join(', ') : 'none') + '</p>'
        + '<p><b>Respondents excluded (' + nExcl + '):</b> ' + (nExcl ? rkeys.map(function (k) { return (LBL[k] || k) + ' ' + reasons[k]; }).join(', ') : 'none') + '</p>'
        + '<p class="muted">A higher revised score is not automatically a better survey. Document the reason for every change; removing items or responses can narrow what the survey measures or shrink the sample.</p>');
    }
    function constructSection() {
      var MATH = window.RSSI_MATH, cons = SCENARIO.constructs || [];
      if (!cons.length) return sec('Construct-level report', '<p class="muted">No construct model was built in the Validity Lab. Build or apply a model there, then regenerate this report.</p>');
      var byName = {}; (ds.variables || []).forEach(function (v) { byName[v.name] = v.values || []; });
      var rows = cons.map(function (c) {
        var vals = c.items.map(function (n) { return byName[n] || []; }).filter(function (a) { return a.length; });
        var a = (vals.length >= 2 && MATH) ? MATH.cronbachAlpha(vals) : null;
        return '<tr><td class="nm">' + esc(c.name) + '</td><td>' + c.items.length + '</td><td>' + rptFix(a) + '</td></tr>';
      }).join('');
      return sec('Construct-level report', '<table class="rep-tbl"><thead><tr><th>Construct</th><th>Items</th><th>&alpha;</th></tr></thead><tbody>' + rows + '</tbody></table><p class="muted">Aim for &alpha; ≥ .80. A low or negative &alpha; usually points to a reverse-coded or off-construct item.</p>');
    }
    function itemSection() {
      var stats = reportItemStats();
      if (!stats.length) return sec('Item diagnostic report', '<p class="muted">No Likert items to report.</p>');
      var rows = stats.map(function (s) { return '<tr><td class="nm">' + esc(s.name) + '</td><td>' + (s.mean == null ? '—' : s.mean.toFixed(1)) + '</td><td>' + rptFix(s.sd) + '</td><td>' + s.missPct + '%</td><td>' + rptFix(s.rest) + '</td><td>' + s.sig + '</td></tr>'; }).join('');
      return sec('Item diagnostic report', '<table class="rep-tbl"><thead><tr><th>Item</th><th>Mean</th><th>SD</th><th>Miss</th><th>Item-rest r</th><th>Signal</th></tr></thead><tbody>' + rows + '</tbody></table>');
    }
    function appendixSection() {
      return sec('Technical appendix',
        '<ul class="rep-ul">'
        + '<li><b>Model.</b> The Survey Strength Index reports one instrument through three lenses (Psychometric Core, Respondent-Centered, Validity-Forward), each a different weighting of eight diagnostic domains.</li>'
        + '<li><b>Reliability.</b> Cronbach&rsquo;s &alpha; on complete cases, with item-rest correlations and &alpha;-if-removed per item.</li>'
        + '<li><b>Validity.</b> Exploratory factor analysis (correlation matrix, Kaiser criterion, varimax rotation) and a confirmatory check (&alpha;, AVE, CR, CFI, RMSEA, HTMT) on the constructs you build.</li>'
        + '<li><b>Missing data.</b> Complete-case handling within each computation.</li>'
        + '<li><b>Sample.</b> ' + totalN + ' responses · ' + likertCount + ' Likert items.</li>'
        + '<li><b>Scoring.</b> Headline = Respondent-Centered lens, rounded. Bands: 85+ Excellent, 70+ Strong, 55+ Good, 40+ Fair, otherwise Weak.</li>'
        + '</ul>');
    }
    function summarySection() {
      return sec('Plain-language summary',
        '<p class="big">' + (score == null ? '—' : score) + ' <span class="muted">/ 100 · ' + esc(band) + '</span></p>'
        + '<p>This survey was answered by ' + totalN + ' people across ' + likertCount + ' questions. On a 0&ndash;100 scale of how trustworthy the evidence is, it scored <b>' + (score == null ? '—' : score) + '</b>, which is ' + esc(band.toLowerCase()) + '.</p>'
        + '<p>' + claimText(band) + '</p>'
        + '<p class="muted">A strong score means the questions are working well together, not that every conclusion is automatically correct. Use it alongside good judgment about what the survey was designed to measure.</p>');
    }
    var sections;
    if (type === 'official') sections = officialSection();
    else if (type === 'revised') sections = revisedSection();
    else if (type === 'construct') sections = constructSection();
    else if (type === 'item') sections = itemSection();
    else if (type === 'appendix') sections = appendixSection();
    else if (type === 'summary') sections = summarySection();
    else sections = officialSection() + revisedSection() + constructSection() + itemSection() + appendixSection();

    var doc = '<!doctype html><html><head><meta charset="utf-8"><title>RSSI report — ' + esc(title) + '</title>'
      + '<style>'
      + 'body{font:14px/1.6 Georgia,serif;color:#2b2b2b;max-width:820px;margin:32px auto;padding:0 24px}'
      + 'h1{font-size:24px;margin:0 0 4px}h2{font-size:17px;border-bottom:2px solid #ddd;padding-bottom:5px;margin:28px 0 12px}h3{font-size:14px;margin:18px 0 6px}'
      + '.meta{color:#777;font-size:12px;margin-bottom:8px}.muted{color:#888}.big{font-size:30px;font-weight:bold;margin:6px 0}'
      + 'table{border-collapse:collapse;width:100%;margin:8px 0;font:12px/1.4 Arial,sans-serif}th,td{border:1px solid #ccc;padding:5px 8px;text-align:center}th{background:#f4f4f4}td.nm{text-align:left}'
      + '.rep-ul{font-size:13px;line-height:1.7}'
      + '@media print{body{margin:0}h2{page-break-after:avoid}table{page-break-inside:avoid}}'
      + '</style></head><body>'
      + reportLogoImg() + '<h1>RSSI report</h1><div class="meta">' + esc(title) + ' · generated ' + esc(when) + ' · ReliCheck</div>'
      + sections
      + '<script>window.onload=function(){window.print();};<\/script></body></html>';
    var w = window.open('', '_blank');
    if (!w) { var s = document.getElementById('rptStatus'); if (s) { s.style.display = 'block'; s.className = 'rel-save-status err'; s.textContent = 'Please allow pop-ups to open the report.'; } return; }
    w.document.open(); w.document.write(doc); w.document.close();
    var st = document.getElementById('rptStatus'); if (st) { st.style.display = 'block'; st.className = 'rel-save-status good'; st.textContent = 'Report opened in a new tab. Use your browser’s Print dialog to save it as a PDF.'; }
  }

  /* ───────────── Validity Lab — EFA on entry (real, from RSSI_MATH) ─────────────
     correlationMatrix → jacobiEigen (eigenvalues+vectors) → Kaiser factor count →
     PCA loadings → varimax rotation; KMO + Bartlett via factorReadinessFromR.
     Live CFA / construct builder lands in the next pass. */
  function initValidityLab(ds) {
    var MATH = window.RSSI_MATH;
    var body = document.getElementById('valLoadBody');
    if (!MATH || !body) return;

    var items = (ds.variables || [])
      .filter(function (v) { return v.types && v.types[0] === 'likert'; })
      .map(function (v) { return { name: v.name, values: v.values || [] }; });
    if (items.length < 3) { body.innerHTML = '<tr><td colspan="2" class="rel-placeholder">An EFA needs at least 3 Likert items.</td></tr>'; return; }

    var cc = MATH.completeCases(items.map(function (it) { return it.values; }));   // rows × k
    var n = cc.length, k = items.length;
    if (n < 10) { body.innerHTML = '<tr><td colspan="2" class="rel-placeholder">Not enough complete responses for a factor analysis.</td></tr>'; return; }
    var cols = []; for (var j = 0; j < k; j++) cols.push(cc.map(function (r) { return r[j]; }));

    var R = MATH._correlationMatrix(cols);
    var eigs = MATH._jacobiEigen(R);
    var eigVals = eigs.map(function (e) { return e.value; });
    var nF = Math.max(1, Math.min(k - 1, eigs.filter(function (e) { return e.value > 1; }).length));

    // PCA loadings then varimax rotation
    var L = [];
    for (var i = 0; i < k; i++) {
      var row = [];
      for (var f = 0; f < nF; f++) row.push(eigs[f].vector[i] * Math.sqrt(Math.max(eigs[f].value, 0)));
      L.push(row);
    }
    var rot = (nF >= 2) ? MATH._varimax(L.map(function (r) { return r.slice(); })) : L;
    // cosmetic: flip each factor so its largest loading reads positive
    for (var fc = 0; fc < nF; fc++) {
      var mx = 0, sign = 1;
      for (var ri = 0; ri < k; ri++) { if (Math.abs(rot[ri][fc]) > mx) { mx = Math.abs(rot[ri][fc]); sign = rot[ri][fc] < 0 ? -1 : 1; } }
      if (sign < 0) for (var rj = 0; rj < k; rj++) rot[rj][fc] *= -1;
    }

    var adq = MATH.factorReadinessFromR(R, n);

    // EFA summary panel
    setText('valFactors', String(nF));
    setText('valNItems', String(k));
    var kmoEl = document.getElementById('valKMO');
    if (kmoEl) {
      var kmo = adq.kmo;
      kmoEl.textContent = (kmo == null) ? '—' : kmo.toFixed(2) + ' (' + (kmo >= 0.80 ? 'great' : kmo >= 0.70 ? 'good' : kmo >= 0.60 ? 'middling' : 'low') + ')';
      kmoEl.className = (kmo == null) ? '' : (kmo >= 0.70 ? 'good' : kmo >= 0.60 ? 'warn' : 'bad');
    }
    var btEl = document.getElementById('valBartlett');
    if (btEl) {
      var p = adq.bartlettP;
      btEl.textContent = (p == null) ? '—' : (p < 0.05 ? 'significant' : 'not significant');
      btEl.className = (p != null && p < 0.05) ? 'good' : 'warn';
    }

    // EFA loadings table, with an "Assign to" dropdown per item (assignment lives here)
    var head = document.getElementById('valLoadHead');
    if (head) { var h = '<tr><th>Item</th>'; for (var hf = 0; hf < nF; hf++) h += '<th>F' + (hf + 1) + '</th>'; head.innerHTML = h + '<th>Assign to</th></tr>'; }
    setText('valLoadCount', k + ' items · ' + nF + ' factor' + (nF === 1 ? '' : 's'));

    var domFs = [], crossCount = 0;
    var rows = items.map(function (it, i2) {
      var loads = rot[i2];
      var domF = 0, domAbs = 0;
      loads.forEach(function (v, f) { if (Math.abs(v) > domAbs) { domAbs = Math.abs(v); domF = f; } });
      domFs[i2] = domF;
      var cells = loads.map(function (v, f) {
        var cls = (f === domF) ? 'load-hi' : (Math.abs(v) >= 0.40 ? 'load-cross' : '');
        return '<td class="' + cls + '">' + v.toFixed(2) + '</td>';
      }).join('');
      if (loads.some(function (v, f) { return f !== domF && Math.abs(v) >= 0.40; })) crossCount++;
      return '<tr><td class="rel-item-name">' + esc(it.name) + '</td>' + cells
        + '<td class="val-assign-cell"><select class="val-assign" data-i="' + i2 + '"></select></td></tr>';
    }).join('');
    body.innerHTML = rows;

    setText('valCrossSummary', crossCount === 0
      ? 'Every item loads cleanly on a single factor (no secondary loading of 0.40 or higher). Use the “Assign to” column to build your constructs.'
      : crossCount + ' of ' + k + ' items also load 0.40 or higher on a second factor (the amber cells); look at those when you assign. Use the “Assign to” column to build your constructs.');

    // ─── Construct builder + live CFA ───
    var constructs = [], assign = {}, conSeq = 0;
    var lastCFA = [], lastHTMT = [];   // captured each recompute, for the printable report
    var tbl = document.getElementById('valLoadTbl');
    var conList = document.getElementById('valConList');

    function colsOf(idxs) {
      var ccx = MATH.completeCases(idxs.map(function (i) { return items[i].values; }));
      var out = []; for (var j = 0; j < idxs.length; j++) out.push(ccx.map(function (r) { return r[j]; }));
      return { cols: out, n: ccx.length };
    }
    function firstFactor(subR) {
      var e = MATH._jacobiEigen(subR)[0];
      var lam = e.vector.map(function (vv) { return vv * Math.sqrt(Math.max(e.value, 0)); });
      var pos = 0; lam.forEach(function (v) { pos += v; });
      if (pos < 0) lam = lam.map(function (v) { return -v; });
      return lam.map(function (v) { return Math.max(-1, Math.min(1, v)); });
    }
    function addConstruct(name) { constructs.push({ id: ++conSeq, name: name || ('Construct ' + conSeq) }); }
    function removeConstruct(id) { constructs = constructs.filter(function (c) { return c.id !== id; }); Object.keys(assign).forEach(function (kk) { if (assign[kk] === id) delete assign[kk]; }); }
    function itemsOf(id) { var a = []; for (var i = 0; i < k; i++) if (assign[i] === id) a.push(i); return a; }
    function conById(id) { for (var i = 0; i < constructs.length; i++) if (constructs[i].id === id) return constructs[i]; return null; }

    function renderBuilder() {
      setText('valConCount', String(constructs.length));
      if (!conList) return;
      conList.innerHTML = constructs.length
        ? constructs.map(function (c) {
            return '<div class="val-con"><input type="text" data-name="' + c.id + '" value="' + esc(c.name) + '">'
              + '<span class="vc-count">' + itemsOf(c.id).length + ' items</span>'
              + '<button type="button" class="vc-del" data-del="' + c.id + '" title="Remove">&times;</button></div>';
          }).join('')
        : '<div class="val-con-empty">No constructs yet. Click “+ Add construct” to define your first scale, then assign its items.</div>';
    }
    function renderAssign() {
      [].forEach.call(tbl.querySelectorAll('select.val-assign'), function (sel) {
        var i = +sel.getAttribute('data-i');
        sel.innerHTML = '<option value="">—</option>' + constructs.map(function (c) {
          return '<option value="' + c.id + '"' + (assign[i] === c.id ? ' selected' : '') + '>' + esc(c.name) + '</option>';
        }).join('');
        sel.value = assign[i] != null ? String(assign[i]) : '';
      });
    }
    function fcell(v, good, warn, lowerBetter) {
      if (v == null || !isFinite(v)) return '<td>—</td>';
      var cls = lowerBetter ? (v <= good ? 'good' : v <= warn ? 'warn' : 'bad') : (v >= good ? 'good' : v >= warn ? 'warn' : 'bad');
      return '<td class="' + cls + '">' + v.toFixed(2) + '</td>';
    }
    function recomputeCFA() {
      var bodyC = document.getElementById('valCfaBody');
      var empty = document.getElementById('valCfaEmpty'), results = document.getElementById('valCfaResults');
      if (!bodyC) return;
      var active = constructs.filter(function (c) { return itemsOf(c.id).length >= 2; });
      if (!active.length) { if (empty) empty.style.display = ''; if (results) results.style.display = 'none'; setText('valCfaFit', '—'); return; }
      if (empty) empty.style.display = 'none'; if (results) results.style.display = '';

      lastCFA = active.map(function (c) {
        var idxs = itemsOf(c.id);
        var vals = idxs.map(function (i) { return items[i].values; });
        var alpha = MATH.cronbachAlpha(vals);
        var co = colsOf(idxs), subR = MATH._correlationMatrix(co.cols), lam = firstFactor(subR);
        var sumAbs = 0, sumL2 = 0, sumResid = 0;
        lam.forEach(function (v) { var l2 = v * v; sumAbs += Math.abs(v); sumL2 += l2; sumResid += (1 - l2); });
        var ave = sumL2 / idxs.length;
        var cr = (sumAbs * sumAbs) / ((sumAbs * sumAbs) + sumResid);
        var fit = (idxs.length >= 3) ? MATH._mlDiscrepancyFit(subR, lam, co.n) : null;
        var cfi = (fit && !fit.singular) ? fit.cfi : null;
        var rmsea = (fit && !fit.singular) ? fit.rmsea : null;
        return { name: c.name, items: idxs.map(function (i) { return items[i].name; }), alpha: alpha, ave: ave, cr: cr, cfi: cfi, rmsea: rmsea };
      });
      bodyC.innerHTML = lastCFA.map(function (r) {
        return '<tr><td>' + esc(r.name) + '</td><td>' + r.items.length + '</td>'
          + fcell(r.alpha, 0.80, 0.70) + fcell(r.ave, 0.50, 0.40) + fcell(r.cr, 0.70, 0.60)
          + fcell(r.cfi, 0.95, 0.90) + fcell(r.rmsea, 0.06, 0.08, true) + '</tr>';
      }).join('');

      var htmtBox = document.getElementById('valHtmt');
      lastHTMT = [];
      if (active.length >= 2) {
        var allIdx = [], scaleIdx = [], nameById = {};
        active.forEach(function (c) { nameById[c.id] = c.name; itemsOf(c.id).forEach(function (i) { allIdx.push(i); scaleIdx.push(c.id); }); });
        var co2 = colsOf(allIdx), Rall = MATH._correlationMatrix(co2.cols), ht = MATH.htmtFromR(Rall, scaleIdx);
        if (ht && ht.pairs && ht.pairs.length) {
          lastHTMT = ht.pairs.map(function (p) { return { a: nameById[p.a], b: nameById[p.b], htmt: p.htmt, ok: p.htmt < 0.85 }; });
        }
        htmtBox.innerHTML = lastHTMT.length
          ? lastHTMT.map(function (p) {
              return '<div class="val-htmt-row"><span>' + esc(p.a) + ' vs ' + esc(p.b) + '</span>'
                + '<span><span class="hv ' + (p.ok ? 'good' : 'bad') + '">' + p.htmt.toFixed(2) + '</span> '
                + '<span class="hb ' + (p.ok ? 'good' : 'bad') + '">' + (p.ok ? 'distinct' : 'overlap') + '</span></span></div>';
            }).join('')
          : '<div class="val-con-empty">Not enough overlap to compute HTMT.</div>';
      } else if (htmtBox) {
        htmtBox.innerHTML = '<div class="val-con-empty">Add a second construct with 2+ items to test discriminant validity.</div>';
      }

      setText('valCfaFit', active.length + ' construct' + (active.length === 1 ? '' : 's') + ' tested');
      setText('valCfaNote', 'Targets: α ≥ .80, AVE ≥ .50, CR ≥ .70, CFI ≥ .95, RMSEA ≤ .06. CFI and RMSEA need at least 3 items in a construct.');
    }
    function publishConstructs() {
      // Publish the current construct model to the shared scenario state.
      SCENARIO.constructs = constructs.map(function (c) {
        return { name: c.name, items: itemsOf(c.id).map(function (i) { return items[i].name; }) };
      });
      scenarioNotify();
    }
    function renderAll() { renderBuilder(); renderAssign(); recomputeCFA(); publishConstructs(); }

    var addBtn = document.getElementById('valAddCon'), sugBtn = document.getElementById('valSuggest');
    if (addBtn) addBtn.addEventListener('click', function () { addConstruct(); renderAll(); });
    if (sugBtn) sugBtn.addEventListener('click', function () {
      constructs = []; assign = {}; conSeq = 0;
      // When one factor dominates (a general factor), skip it and suggest the
      // specific sub-factors; otherwise assign each item to its dominant factor.
      var general = (nF >= 2 && eigVals[0] > 2.5 * eigVals[1]);
      var fStart = general ? 1 : 0;
      var targets = [];
      for (var i = 0; i < k; i++) {
        var best = -1, ba = 0;
        for (var f = fStart; f < nF; f++) { if (Math.abs(rot[i][f]) > ba) { ba = Math.abs(rot[i][f]); best = f; } }
        targets[i] = (ba >= 0.30) ? best : -1;
      }
      var cnt = {}; targets.forEach(function (t) { if (t >= 0) cnt[t] = (cnt[t] || 0) + 1; });
      var fid = {}, label = 1;
      for (var f2 = fStart; f2 < nF; f2++) { if ((cnt[f2] || 0) >= 2) { addConstruct('Factor ' + (label++)); fid[f2] = conSeq; } }
      for (var i2 = 0; i2 < k; i2++) { if (targets[i2] >= 0 && fid[targets[i2]] != null) assign[i2] = fid[targets[i2]]; }
      renderAll();
    });

    // ReliCheck Intelligence — separate section: reads item wording, proposes named
    // clusters for REVIEW, then applies to the self-driven model only on the user's click.
    var aiBtn = document.getElementById('valAI'), aiStatus = document.getElementById('valAIStatus');
    var aiResults = document.getElementById('valAIResults'), aiCards = document.getElementById('valAICards'), aiApply = document.getElementById('valAIApply');
    var aiProposal = null;
    function setAI(msg, kind) { if (!aiStatus) return; if (!msg) { aiStatus.style.display = 'none'; return; } aiStatus.style.display = 'block'; aiStatus.className = 'val-ai-status ' + (kind || 'info'); aiStatus.textContent = msg; }
    if (aiBtn) aiBtn.addEventListener('click', function () {
      aiBtn.disabled = true; if (aiResults) aiResults.style.display = 'none';
      setAI('ReliCheck Intelligence is reading your items and proposing constructs…', 'info');
      var payload = { items_to_map: items.map(function (it, i) { return { id: String(i), prompt: it.name, type: 'likert' }; }), existing_constructs: [] };
      fetch('/api/ai/map-constructs.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (out) {
          aiBtn.disabled = false;
          if (!out.ok || !out.body || !Array.isArray(out.body.constructs)) {
            setAI('ReliCheck Intelligence could not suggest clusters: ' + ((out.body && (out.body.error_message || out.body.message)) || 'please try again.'), 'err');
            return;
          }
          aiProposal = out.body.constructs.map(function (c) {
            var idxs = (c.item_ids || []).map(function (id) { return parseInt(id, 10); }).filter(function (i) { return !isNaN(i) && i >= 0 && i < items.length; });
            return { name: c.name || 'Construct', idxs: idxs, rationale: c.rationale || '' };
          }).filter(function (p) { return p.idxs.length; });
          if (aiCards) aiCards.innerHTML = aiProposal.map(function (p) {
            return '<div class="val-ai-card"><h4>' + esc(p.name) + '<span class="n">' + p.idxs.length + ' item' + (p.idxs.length === 1 ? '' : 's') + '</span></h4>'
              + (p.rationale ? '<div class="rat">' + esc(p.rationale) + '</div>' : '')
              + '<div class="its">' + p.idxs.map(function (i) { return '<span class="it">' + esc(items[i].name) + '</span>'; }).join('') + '</div></div>';
          }).join('');
          if (aiResults) aiResults.style.display = '';
          setAI('ReliCheck Intelligence suggested ' + aiProposal.length + ' construct' + (aiProposal.length === 1 ? '' : 's') + '. Review them below, then apply to your model or keep your own.', 'info');
        })
        .catch(function (e) { aiBtn.disabled = false; setAI('Network error: ' + (e && e.message ? e.message : e), 'err'); });
    });
    if (aiApply) aiApply.addEventListener('click', function () {
      if (!aiProposal || !aiProposal.length) return;
      constructs = []; assign = {}; conSeq = 0;
      aiProposal.forEach(function (p) { addConstruct(p.name); var cid = conSeq; p.idxs.forEach(function (i) { assign[i] = cid; }); });
      renderAll();
      setAI('Applied. Your model above now uses ReliCheck Intelligence’s constructs — adjust anything you like.', 'info');
      var b = document.getElementById('valBuilder'); if (b) b.scrollIntoView({ block: 'center' });
    });
    if (conList) {
      conList.addEventListener('input', function (e) {
        var inp = e.target.closest('[data-name]'); if (!inp) return;
        var c = conById(+inp.getAttribute('data-name')); if (c) { c.name = inp.value; renderAssign(); recomputeCFA(); publishConstructs(); }
      });
      conList.addEventListener('click', function (e) {
        var d = e.target.closest('[data-del]'); if (!d) return;
        removeConstruct(+d.getAttribute('data-del')); renderAll();
      });
    }
    if (tbl) tbl.addEventListener('change', function (e) {
      var sel = e.target.closest('select.val-assign'); if (!sel) return;
      var i = +sel.getAttribute('data-i');
      if (sel.value) assign[i] = +sel.value; else delete assign[i];
      renderBuilder(); recomputeCFA(); publishConstructs();
    });

    // ─── Export the factor loadings + construct model as a printable report ───
    // Opens a self-contained window (EFA summary, full loadings matrix, the user's
    // constructs with item assignments, CFA stats, and HTMT) for Print / Save-as-PDF.
    var exportBtn = document.getElementById('valExport');
    if (exportBtn) exportBtn.addEventListener('click', function () { buildValidityReport(); });

    function fmtN(v, d) { return (v == null || !isFinite(v)) ? '&mdash;' : v.toFixed(d == null ? 2 : d); }
    function buildValidityReport() {
      var title = (window.RSSI_APP_RAW && window.RSSI_APP_RAW.title) || (ds && ds.__title) || 'Saved dataset';
      var when = new Date().toLocaleString();
      var kmo = adq.kmo, bp = adq.bartlettP;

      // EFA loadings matrix (item × factor), dominant factor bolded.
      var headCells = '<th>Item</th>'; for (var f = 0; f < nF; f++) headCells += '<th>F' + (f + 1) + '</th>';
      var loadRows = items.map(function (it, i) {
        var cells = rot[i].map(function (v, fi) {
          var cls = (fi === domFs[i]) ? 'hi' : (Math.abs(v) >= 0.40 ? 'cross' : '');
          return '<td class="' + cls + '">' + v.toFixed(2) + '</td>';
        }).join('');
        return '<tr><td class="nm">' + esc(it.name) + '</td>' + cells + '</tr>';
      }).join('');

      // The user's constructs and their assigned items.
      var conBlocks = constructs.length
        ? constructs.map(function (c) {
            var names = itemsOf(c.id).map(function (i) { return items[i].name; });
            return '<div class="con"><h4>' + esc(c.name) + ' <span>' + names.length + ' item' + (names.length === 1 ? '' : 's') + '</span></h4>'
              + (names.length ? '<ul>' + names.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') + '</ul>' : '<p class="muted">No items assigned.</p>')
              + '</div>';
          }).join('')
        : '<p class="muted">No constructs were built.</p>';

      // CFA table (captured from the live computation).
      var cfaTbl = lastCFA.length
        ? '<table class="rep-tbl"><thead><tr><th>Construct</th><th>Items</th><th>&alpha;</th><th>AVE</th><th>CR</th><th>CFI</th><th>RMSEA</th></tr></thead><tbody>'
          + lastCFA.map(function (r) {
              return '<tr><td>' + esc(r.name) + '</td><td>' + r.items.length + '</td><td>' + fmtN(r.alpha) + '</td><td>' + fmtN(r.ave)
                + '</td><td>' + fmtN(r.cr) + '</td><td>' + fmtN(r.cfi) + '</td><td>' + fmtN(r.rmsea) + '</td></tr>';
            }).join('')
          + '</tbody></table>'
        : '<p class="muted">No construct had 2 or more items, so no confirmatory model was run.</p>';

      var htmtTbl = lastHTMT.length
        ? '<table class="rep-tbl"><thead><tr><th>Pair</th><th>HTMT</th><th>Verdict</th></tr></thead><tbody>'
          + lastHTMT.map(function (p) {
              return '<tr><td>' + esc(p.a) + ' vs ' + esc(p.b) + '</td><td>' + p.htmt.toFixed(2) + '</td><td>' + (p.ok ? 'distinct' : 'overlap') + '</td></tr>';
            }).join('')
          + '</tbody></table>'
        : '<p class="muted">Discriminant validity (HTMT) needs at least two constructs of 2+ items each.</p>';

      var doc = '<!doctype html><html><head><meta charset="utf-8"><title>Validity Lab report &mdash; ' + esc(title) + '</title>'
        + '<style>'
        + 'body{font:14px/1.5 Georgia,serif;color:#2b2b2b;max-width:840px;margin:32px auto;padding:0 24px}'
        + 'h1{font-size:24px;margin:0 0 4px}h2{font-size:16px;border-bottom:2px solid #ddd;padding-bottom:4px;margin:28px 0 12px}'
        + 'h4{margin:12px 0 4px;font-size:14px}h4 span{font-weight:normal;color:#777;font-size:12px}'
        + '.meta{color:#777;font-size:12px;margin-bottom:8px}.muted{color:#888}'
        + 'table{border-collapse:collapse;width:100%;margin:8px 0;font:12px/1.4 Arial,sans-serif}'
        + 'th,td{border:1px solid #ccc;padding:5px 8px;text-align:center}th{background:#f4f4f4}td.nm{text-align:left}'
        + 'td.hi{font-weight:bold;background:#e6f6ec}td.cross{background:#fbefd6}'
        + '.con{margin:8px 0}.con ul{margin:4px 0 0 18px}.legend{font-size:11px;color:#777;margin:4px 0}'
        + '.note{font-size:12px;color:#555;margin-top:6px}'
        + '@media print{body{margin:0}h2{page-break-after:avoid}table{page-break-inside:avoid}}'
        + '</style></head><body>'
        + reportLogoImg() + '<h1>Validity Lab report</h1>'
        + '<div class="meta">' + esc(title) + ' &middot; generated ' + esc(when) + ' &middot; ReliCheck</div>'
        + '<h2>Exploratory factor analysis</h2>'
        + '<p>Factors (eigenvalue &gt; 1): <b>' + nF + '</b> &nbsp;&middot;&nbsp; Items analyzed: <b>' + k + '</b> '
        + '&nbsp;&middot;&nbsp; KMO: <b>' + fmtN(kmo) + '</b> &nbsp;&middot;&nbsp; Bartlett: <b>'
        + (bp == null ? '&mdash;' : (bp < 0.05 ? 'significant' : 'not significant')) + '</b></p>'
        + '<h2>Factor loadings</h2>'
        + '<p class="legend">Shaded green = item&rsquo;s dominant factor. Shaded amber = also loads 0.40 or higher on a second factor (cross-loader).</p>'
        + '<table class="rep-tbl"><thead><tr>' + headCells + '</tr></thead><tbody>' + loadRows + '</tbody></table>'
        + '<h2>Constructs (your model)</h2>' + conBlocks
        + '<h2>Confirmatory model (CFA)</h2>' + cfaTbl
        + '<p class="note">Targets: &alpha; &ge; .80, AVE &ge; .50, CR &ge; .70, CFI &ge; .95, RMSEA &le; .06. CFI and RMSEA need at least 3 items in a construct.</p>'
        + '<h2>Discriminant validity (HTMT)</h2>' + htmtTbl
        + '<p class="note">HTMT below 0.85 indicates two constructs are distinct.</p>'
        + '<script>window.onload=function(){window.print();};<\/script>'
        + '</body></html>';

      var w = window.open('', '_blank');
      if (!w) { alert('Please allow pop-ups to open the report.'); return; }
      w.document.open(); w.document.write(doc); w.document.close();
    }

    renderAll();
  }
})();
