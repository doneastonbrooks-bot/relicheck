/* =============================================================================
   rssi-upload.js — RSSI upload + scoring engine.

   Drives the upload stage of /rssi.php:
   1. Drag-drop or file-picker → parse CSV / XLSX into rows
   2. Auto-detect Likert + categorical + numeric + open-ended columns
   3. Compute the six-domain Strength Survey Index
   4. Hand the result to rssi.js (via window.RSSI_RESULT) and flip the stage to "dashboard"
   ============================================================================= */
(function () {
  'use strict';

  /* ────────────────────────────────────────────────────────────
   * Lazy-loader for SheetJS (Excel parser, only fetched when needed)
   * ──────────────────────────────────────────────────────────── */
  let _xlsxPromise = null;
  function loadXlsx() {
    if (window.XLSX) return Promise.resolve(window.XLSX);
    if (_xlsxPromise) return _xlsxPromise;
    _xlsxPromise = new Promise(function (resolve, reject) {
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
      s.onload  = function () { resolve(window.XLSX); };
      s.onerror = function () { reject(new Error('Could not load Excel parser.')); };
      document.head.appendChild(s);
    });
    return _xlsxPromise;
  }

  /* ────────────────────────────────────────────────────────────
   * Parse CSV / TSV text into { headers, rows }
   * ──────────────────────────────────────────────────────────── */
  function parseDelimited(text, delim) {
    const lines = text.replace(/\r\n/g, '\n').split('\n').filter(function (l) { return l.length > 0; });
    if (!lines.length) return { headers: [], rows: [] };
    const splitLine = function (line) {
      // Very small CSV splitter: respects double-quoted fields containing commas.
      const out = [];
      let cur = '', inQ = false;
      for (let i = 0; i < line.length; i++) {
        const ch = line[i];
        if (ch === '"') { inQ = !inQ; continue; }
        if (ch === delim && !inQ) { out.push(cur); cur = ''; continue; }
        cur += ch;
      }
      out.push(cur);
      return out;
    };
    const headers = splitLine(lines[0]).map(function (h) { return String(h).trim(); });
    const rows = lines.slice(1).map(function (l) {
      const cells = splitLine(l);
      const row = {};
      headers.forEach(function (h, i) { row[h] = (cells[i] != null ? String(cells[i]).trim() : ''); });
      return row;
    });
    return { headers: headers, rows: rows };
  }

  function detectDelimiter(text) {
    const sample = text.slice(0, 2000);
    const commas = (sample.match(/,/g)  || []).length;
    const tabs   = (sample.match(/\t/g) || []).length;
    return tabs > commas ? '\t' : ',';
  }

  /* ────────────────────────────────────────────────────────────
   * Parse XLSX → { headers, rows }
   * ──────────────────────────────────────────────────────────── */
  function parseXlsx(file) {
    return loadXlsx().then(function (XLSX) {
      return new Promise(function (resolve, reject) {
        const r = new FileReader();
        r.onload = function (e) {
          try {
            const wb = XLSX.read(e.target.result, { type: 'array' });
            const sheet = wb.Sheets[wb.SheetNames[0]];
            const json = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
            if (!json.length) { reject(new Error('The spreadsheet has no data.')); return; }
            const headers = json[0].map(function (h) { return String(h).trim(); });
            const rows = json.slice(1).map(function (arr) {
              const row = {};
              headers.forEach(function (h, i) { row[h] = arr[i] != null ? String(arr[i]).trim() : ''; });
              return row;
            }).filter(function (r) {
              return Object.values(r).some(function (v) { return v !== ''; });
            });
            resolve({ headers: headers, rows: rows });
          } catch (err) { reject(err); }
        };
        r.onerror = function () { reject(new Error('Could not read the file.')); };
        r.readAsArrayBuffer(file);
      });
    });
  }

  /* ────────────────────────────────────────────────────────────
   * Auto-detect variable types from a parsed file.
   * Returns dataset in the shape rssi.js expects.
   * ──────────────────────────────────────────────────────────── */
  function buildDataset(parsed, sourceLabel) {
    const variables = parsed.headers.map(function (h) {
      const rawValues = parsed.rows.map(function (r) { return r[h]; });
      const nonEmpty = rawValues.filter(function (v) { return v !== '' && v != null; });

      let type = 'open';
      if (nonEmpty.length === 0) {
        type = 'open';
      } else {
        const numeric = nonEmpty.filter(function (v) { return !isNaN(parseFloat(v)) && isFinite(parseFloat(v)); });
        const isAllNumeric = numeric.length === nonEmpty.length;
        if (isAllNumeric) {
          const nums = numeric.map(parseFloat);
          const min = Math.min.apply(null, nums);
          const max = Math.max.apply(null, nums);
          const uniques = Array.from(new Set(nums)).length;
          // Likert: small integer range (1-7 typically), few unique values
          if (Number.isInteger(min) && Number.isInteger(max) && (max - min) >= 1 && (max - min) <= 10 && uniques <= 11) {
            type = 'likert';
          } else {
            type = 'numeric';
          }
        } else {
          // Categorical: limited distinct text values
          const uniqText = Array.from(new Set(nonEmpty.map(String)));
          if (uniqText.length <= Math.max(8, Math.floor(nonEmpty.length * 0.2))) {
            type = 'categorical';
          } else {
            type = 'open';
          }
        }
      }
      return {
        name: h,
        types: [type],
        label: h,
        values: type === 'likert' || type === 'numeric'
          ? rawValues.map(function (v) { return v === '' ? null : parseFloat(v); })
          : rawValues,
      };
    });
    return {
      source: sourceLabel,
      rowCount: parsed.rows.length,
      variables: variables,
    };
  }

  /* ────────────────────────────────────────────────────────────
   * STANDALONE COMPOSITE — DELEGATED TO CANONICAL ENGINE
   *
   * Per Spec §6, the canonical psychometrics engine at
   * /apps/strength-index/strength-index.js exposes
   * window.RSSI_MATH.computeLensesFromDataset as the single source
   * of truth for the three-lens RSSI composite. This surface
   * delegates to it so the standalone and in-studio surfaces
   * produce byte-for-byte identical lens scores for the same
   * dataset (Spec §3.2–3.3).
   *
   * The legacy six-domain weighted-mean composite that used to
   * live here (scoreReliability / scoreItemQuality /
   * scoreFactorStructure / scoreResponseQuality / scoreOpenEnded /
   * scoreActionability plus their local helpers) is retired.
   * computeRSSI below is now a thin shim that calls the canonical
   * engine and rebuilds the legacy v1-labelled `components` map
   * from canonical sub-scores for the existing rssi.js renderer.
   * ──────────────────────────────────────────────────────────── */

  /* ────────────────────────────────────────────────────────────
   * Compose the full RSSI score blob.
   *
   * Delegates the lens math to the canonical engine
   * (window.RSSI_MATH.computeLensesFromDataset) per Spec §6. The
   * standalone surface used to compute its own weighted-mean over
   * six v1-labelled components; that path is retired. The legacy
   * `components` map is rebuilt here from canonical sub-scores
   * for backward-compat with the existing rssi.js renderer (which
   * still reads the v1 keys), and will be retired once that
   * renderer is migrated to the canonical 8-domain taxonomy.
   * ──────────────────────────────────────────────────────────── */
  function computeRSSI(dataset) {
    const likertVars = dataset.variables.filter(function (v) { return v.types[0] === 'likert'; });
    const engine = window.RSSI_MATH && window.RSSI_MATH.computeLensesFromDataset;
    if (typeof engine !== 'function') {
      throw new Error('Canonical lens engine not loaded — apps/strength-index/strength-index.js must be included before apps/rssi/rssi-upload.js (Spec §6).');
    }
    const lensResult = engine(dataset);
    const rssi = lensResult.rssi;
    const d    = lensResult.domain_details || {};

    // Headline = Respondent-Centered lens (Spec §3.4 default).
    const strength = rssi.respondent_centered == null
      ? 0
      : Math.round(rssi.respondent_centered);
    const verdict = strength >= 85 ? 'Excellent'
                  : strength >= 70 ? 'Strong'
                  : strength >= 55 ? 'Good'
                  : strength >= 40 ? 'Fair'
                  : 'Weak';

    // Backward-compat legacy components map. Each v1 key inherits
    // its 0–100 score (and note when available) from the canonical
    // domain it maps to. The four canonical domains not yet built
    // (validity, construct_alignment, bias_clarity, scale_structure)
    // surface as `skip: true` so rssi.js excludes them from any
    // local rollups it still performs.
    function from(canonical, label, weight) {
      const det = d[canonical];
      if (!det || det.score == null) {
        return { label: label, weight: weight, score: null, note: 'Not yet computed in this build (Spec §' +
          (canonical === 'validity' ? '4A'
           : canonical === 'construct_alignment' ? '4B'
           : canonical === 'bias_clarity' ? '4D'
           : canonical === 'scale_structure' ? '4E'
           : '4F') + ').', skip: true };
      }
      return { label: label, weight: weight, score: det.score, note: det.note, raw: det.raw, max: det.max, interp: det.interp, tone: det.tone };
    }
    const components = {
      survey_structure:      from('scale_structure',        'Survey Structure',      15),
      question_quality:      from('item_prompt_quality',    'Question Quality',      20),
      scale_strength:        from('factor_readiness',       'Scale Strength',        15),
      reliability_readiness: from('reliability',            'Reliability Readiness', 25),
      validity_alignment:    from('validity',               'Validity Alignment',    15),
      response_risk:         from('response_scale_review',  'Response Risk',         10),
    };

    const summary = 'At ' + strength + ', this survey is in ' + verdict.toLowerCase() + ' shape. ' +
      (strength >= 70
        ? 'A few targeted fixes would move it into excellent territory.'
        : 'Focused revisions to the lowest-scoring dimensions would meaningfully lift the score.');

    return {
      app_key: 'strength_index',
      app_name: 'ReliCheck Strength Survey Index',
      summary: summary,
      strength: strength,
      verdict: verdict,
      components: components,
      // Three-lens RSSI output (Spec §3.2–3.3) — identical to the
      // in-studio mount's output for the same dataset (Spec §6).
      rssi:                 rssi,
      domain_subscores:     lensResult.domain_subscores,
      skipped_domains:      lensResult.skipped_domains,
      rssi_weights_version: lensResult.rssi_weights_version,
      dataset: {
        source: dataset.source,
        rowCount: dataset.rowCount,
        itemCount: dataset.variables.length,
        scaleCount: likertVars.length > 0 ? Math.max(1, Math.ceil(likertVars.length / 5)) : 0,
      },
      computed_at: lensResult.computed_at,
    };
  }

  /* ────────────────────────────────────────────────────────────
   * UI wiring
   * ──────────────────────────────────────────────────────────── */
  function setStatus(msg, kind) {
    const el = document.getElementById('rssiUploadStatus');
    if (!el) return;
    el.innerHTML = msg;
    el.className = 'upload-status show' + (kind === 'error' ? ' error' : '');
  }

  function handleFile(file) {
    if (!file) return;
    setStatus('<strong>Parsing ' + file.name + '…</strong><div class="meta">' + (file.size / 1024).toFixed(1) + ' KB</div>');
    const name = (file.name || '').toLowerCase();
    const isXlsx = name.endsWith('.xlsx') || name.endsWith('.xls');
    const parseP = isXlsx
      ? parseXlsx(file)
      : new Promise(function (resolve, reject) {
          const r = new FileReader();
          r.onload = function (e) {
            try {
              const text = String(e.target.result || '');
              const delim = detectDelimiter(text);
              resolve(parseDelimited(text, delim));
            } catch (err) { reject(err); }
          };
          r.onerror = function () { reject(new Error('Could not read the file.')); };
          r.readAsText(file);
        });

    parseP.then(function (parsed) {
      if (!parsed.headers.length) throw new Error('No columns detected.');
      if (!parsed.rows.length)    throw new Error('No data rows detected.');
      const dataset = buildDataset(parsed, file.name);

      // ── HARD GATE: RSSI requires Likert-scale data ──────────────────
      // RSSI scores the instrument quality of CLOSED-ENDED Likert items.
      // Open-ended-only datasets (e.g., interview transcripts) cannot be
      // scored — refuse instead of returning a misleading number.
      const likertVars = dataset.variables.filter(function (v) { return v.types[0] === 'likert'; });
      const numericVars = dataset.variables.filter(function (v) { return v.types[0] === 'numeric'; });
      const openVars    = dataset.variables.filter(function (v) { return v.types[0] === 'open'; });
      const catVars     = dataset.variables.filter(function (v) { return v.types[0] === 'categorical'; });

      if (likertVars.length < 3) {
        let message = '<strong>This dataset is not scorable by RSSI.</strong>';
        message += '<div class="meta" style="margin-top:6px;">RSSI measures the credibility of <strong>closed-ended Likert-scale</strong> instruments (e.g., 1&ndash;5 or 1&ndash;7 rating items). ';
        message += 'Your file has <strong>' + likertVars.length + ' Likert column' + (likertVars.length === 1 ? '' : 's') + '</strong> ';
        message += '(' + openVars.length + ' open-ended, ' + catVars.length + ' categorical, ' + numericVars.length + ' other numeric).</div>';
        message += '<div class="meta" style="margin-top:8px;">';
        if (openVars.length > 0 && likertVars.length === 0) {
          message += 'For qualitative or open-ended data, try the <strong>MM Studio</strong> instead &mdash; it&rsquo;s built for theme analysis and codebook work.';
        } else if (catVars.length > 0 && likertVars.length === 0) {
          message += 'If your Likert responses are stored as text labels (&ldquo;Strongly Agree&rdquo;, &ldquo;Agree&rdquo;&hellip;), recode them as numbers 1&ndash;5 (or 1&ndash;7) and re-upload.';
        } else {
          message += 'Add at least three Likert columns to your data, then re-upload.';
        }
        message += '</div>';
        setStatus(message, 'error');
        return; // Stop here. Do NOT score, do NOT switch to dashboard.
      }

      const result = computeRSSI(dataset);
      handoffToDashboard(result, dataset, file.name);
    }).catch(function (err) {
      setStatus('<strong>Could not parse file:</strong> ' + (err && err.message ? err.message : String(err)), 'error');
    });
  }

  /* Expose the scoring engine globally so other modules (the interactive
     Cronbach analyzer) can call it to recompute the full RSSI in real time. */
  window.RSSI_COMPUTE = computeRSSI;

  function handoffToDashboard(result, dataset, filename) {
    // Stash the computed result so rssi.js can read it.
    window.RSSI_RESULT = result;
    window.RSSI_DATASET = dataset;
    // Also persist a slim copy of the dataset (Likert items + name) so the
    // interactive reliability analyzer survives refresh.
    try {
      const slim = {
        source: dataset.source,
        variables: dataset.variables.filter(function (v) {
          return v.types && v.types.indexOf('likert') !== -1;
        }),
      };
      window.localStorage.setItem('rssi.dataset.cache', JSON.stringify(slim));
    } catch (e) { /* storage full or private mode */ }

    // ALSO persist the full dataset in the wrapped format the studio
    // analysis engines expect, under a relicheck.dataset.* key. This is
    // what the embedded /reliability.php /validity.php /etc. iframes read
    // (since iframes can't see window.RSSI_DATASET in the parent window).
    // The studio mount JS scans all relicheck.dataset.* keys and uses
    // the most recent one when no project_id is in the URL.
    try {
      const wrapped = {
        savedAt: Date.now(),
        studio: 'survey',
        payload: { dataset: dataset },
      };
      window.localStorage.setItem('relicheck.dataset.rssi-upload', JSON.stringify(wrapped));
    } catch (e) { /* storage full or private mode */ }
    // Save to localStorage as a "saved block" so /rssi-report.php and the
    // saved-blocks corpus can pick it up too.
    try {
      const projectId = 'rssi-upload';
      const storageKey = 'relicheck.report.' + projectId + '.default';
      const block = {
        id: 'strength_index:default:' + projectId,
        addedAt: Date.now(),
        studio: 'rssi',
        project: filename,
        app: 'strength_index',
        appName: result.app_name,
        summary: result.summary,
        payload: result,
      };
      const existing = JSON.parse(window.localStorage.getItem(storageKey) || '{"blocks":[]}');
      const idx = existing.blocks.findIndex(function (b) { return b.id === block.id; });
      if (idx >= 0) existing.blocks[idx] = block; else existing.blocks.push(block);
      existing.studio = 'rssi'; existing.project = filename;
      window.localStorage.setItem(storageKey, JSON.stringify(existing));
    } catch (e) {}

    // Flip stage → dashboard
    const root = document.getElementById('rssiAppRoot');
    if (root) root.setAttribute('data-stage', 'dashboard');
    // Update the title
    const titleEl = document.getElementById('rssiDashTitle');
    if (titleEl) titleEl.textContent = filename.replace(/\.(csv|xlsx?|tsv|txt)$/i, '');
    const printT = document.getElementById('rssiPrintTitle');
    if (printT) printT.textContent = filename;
    // rssi.js auto-renders on init; if it already ran, fire its render again.
    if (window.RSSI_RENDER_FROM_RESULT) {
      window.RSSI_RENDER_FROM_RESULT(result);
    } else {
      // rssi.js hasn't initialized yet — it'll pick up window.RSSI_RESULT on its own.
    }
    // Show optional rail blocks
    const wb = document.getElementById('rssiWhatBlock');
    const pb = document.getElementById('rssiPrioritiesBlock');
    if (wb) wb.style.display = '';
    if (pb) pb.style.display = '';
  }

  /* Demo mode — feeds the existing sample data through rssi.js */
  function loadDemo() {
    const root = document.getElementById('rssiAppRoot');
    if (root) root.setAttribute('data-stage', 'dashboard');
    if (window.RSSI_RENDER_FROM_RESULT) window.RSSI_RENDER_FROM_RESULT(null); // null → use SAMPLE
    const wb = document.getElementById('rssiWhatBlock');
    const pb = document.getElementById('rssiPrioritiesBlock');
    if (wb) wb.style.display = '';
    if (pb) pb.style.display = '';
    const titleEl = document.getElementById('rssiDashTitle');
    if (titleEl) titleEl.textContent = 'Employee Engagement Pulse (sample)';
  }

  function init() {
    const dz   = document.getElementById('rssiDropzone');
    const file = document.getElementById('rssiFileInput');
    const demo = document.getElementById('rssiTryDemo');
    if (!dz || !file) return;

    dz.addEventListener('click', function () { file.click(); });
    file.addEventListener('change', function () {
      if (file.files && file.files[0]) handleFile(file.files[0]);
    });
    ['dragenter', 'dragover'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('dragover'); });
    });
    dz.addEventListener('drop', function (e) {
      const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) handleFile(f);
    });
    if (demo) demo.addEventListener('click', function (e) { e.preventDefault(); loadDemo(); });

    /* Auto-trigger demo when ?demo=1 is in the URL (from the landing's
       "View Sample Report" tile). */
    try {
      const qp = new URLSearchParams(window.location.search);
      if (qp.get('demo') === '1') loadDemo();

      /* Pick up a file staged by the landing-page modal (data URL stored
         in sessionStorage under 'rssi.pendingFile'). */
      if (qp.get('frommodal') === '1') {
        const raw = sessionStorage.getItem('rssi.pendingFile');
        if (raw) {
          sessionStorage.removeItem('rssi.pendingFile');
          try {
            const obj = JSON.parse(raw);
            // Convert data URL back to a File and feed it through the
            // same handleFile path as a normal drag-drop.
            fetch(obj.data).then(function (r) { return r.blob(); }).then(function (blob) {
              const f = new File([blob], obj.name, { type: obj.type || blob.type });
              handleFile(f);
            }).catch(function (err) {
              setStatus('<strong>Could not load the staged file:</strong> ' + (err && err.message ? err.message : String(err)), 'error');
            });
          } catch (e) {
            setStatus('<strong>Could not load the staged file.</strong>', 'error');
          }
        }
      }
    } catch (e) {}

    /* Score another → back to upload stage */
    const again = document.getElementById('rssiUploadAgain');
    if (again) again.addEventListener('click', function () {
      const root = document.getElementById('rssiAppRoot');
      if (root) root.setAttribute('data-stage', 'upload');
      file.value = '';
      setStatus('', '');
      document.getElementById('rssiUploadStatus').classList.remove('show');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
