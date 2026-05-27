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
   * SIX-DOMAIN STRENGTH INDEX (lightweight standalone scorer)
   *
   * This is a simplified version of the math in
   * /apps/strength-index/strength-index.js — designed to produce
   * a directionally-correct headline number without dragging in
   * the full engine. Each component returns 0–100; the composite
   * is a weighted mean.
   *
   * Weights (sum to 100):
   *   reliability 25, item_quality 20, factor_structure 15,
   *   response_quality 15, open_ended 10, actionability 15
   * ──────────────────────────────────────────────────────────── */
  function num(v) { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function nonNull(arr) { return arr.filter(function (v) { return v != null; }); }
  function mean(arr) { return arr.length ? arr.reduce(function (s, v) { return s + v; }, 0) / arr.length : 0; }
  function variance(arr) {
    if (arr.length < 2) return 0;
    const m = mean(arr);
    return arr.reduce(function (s, v) { return s + (v - m) * (v - m); }, 0) / (arr.length - 1);
  }
  function pearson(a, b) {
    const n = Math.min(a.length, b.length);
    if (n < 3) return 0;
    let ma = 0, mb = 0;
    for (let i = 0; i < n; i++) { ma += a[i]; mb += b[i]; }
    ma /= n; mb /= n;
    let cov = 0, va = 0, vb = 0;
    for (let i = 0; i < n; i++) {
      cov += (a[i] - ma) * (b[i] - mb);
      va  += (a[i] - ma) * (a[i] - ma);
      vb  += (b[i] - mb) * (b[i] - mb);
    }
    const denom = Math.sqrt(va * vb);
    return denom === 0 ? 0 : cov / denom;
  }

  /* Cronbach alpha across Likert items (using complete cases) */
  function cronbachAlpha(items) {
    const k = items.length;
    if (k < 2) return null;
    // Complete cases
    const n = items[0].length;
    const complete = [];
    for (let i = 0; i < n; i++) {
      let ok = true;
      for (let j = 0; j < k; j++) { if (items[j][i] == null || isNaN(items[j][i])) { ok = false; break; } }
      if (ok) {
        const row = [];
        for (let j = 0; j < k; j++) row.push(items[j][i]);
        complete.push(row);
      }
    }
    if (complete.length < 5) return null;
    // Variance of each item + variance of sum
    const itemVars = [];
    for (let j = 0; j < k; j++) itemVars.push(variance(complete.map(function (r) { return r[j]; })));
    const sums = complete.map(function (r) { return r.reduce(function (a, b) { return a + b; }, 0); });
    const totalVar = variance(sums);
    if (totalVar === 0) return 0;
    const itemVarSum = itemVars.reduce(function (a, b) { return a + b; }, 0);
    return (k / (k - 1)) * (1 - itemVarSum / totalVar);
  }

  function scoreReliability(likertVars) {
    if (likertVars.length < 2) return { score: null, note: 'Not enough Likert items to compute reliability.', skip: true };
    const items = likertVars.map(function (v) { return v.values.map(num); });
    const alpha = cronbachAlpha(items);
    if (alpha == null) return { score: null, note: 'Could not compute alpha — too few complete cases.', skip: true };
    // Map alpha 0..1 to a 0..100 score, weighted toward common thresholds:
    // alpha 0.90 → 100, 0.80 → 88, 0.70 → 72, 0.60 → 50, < 0.50 → < 30
    const a = Math.max(0, alpha);
    const score = Math.round(Math.min(100, Math.max(0, a * 100 * 1.1 - 10)));
    let note;
    if (alpha >= 0.85) note = 'Excellent reliability — α = ' + alpha.toFixed(2) + ' across ' + likertVars.length + ' items.';
    else if (alpha >= 0.7) note = 'Good reliability — α = ' + alpha.toFixed(2) + '. Some scales could be tightened.';
    else if (alpha >= 0.6) note = 'Marginal reliability — α = ' + alpha.toFixed(2) + '. Consider revising weak items.';
    else note = 'Low reliability — α = ' + alpha.toFixed(2) + '. The scale is not hanging together; revise items.';
    return { score: score, note: note, alpha: alpha };
  }

  function scoreItemQuality(likertVars) {
    if (likertVars.length === 0) return { score: null, note: 'No Likert items detected.', skip: true };
    let flags = 0;
    let total = likertVars.length;
    let ceilFloor = 0, lowVar = 0, highMiss = 0;
    likertVars.forEach(function (v) {
      const vals = v.values.map(num);
      const nn = nonNull(vals);
      const missRate = (vals.length - nn.length) / vals.length;
      if (missRate > 0.10) { flags++; highMiss++; }
      const m = mean(nn);
      const sd = Math.sqrt(variance(nn));
      // ceiling/floor: mean within 0.5 of extremes (assumes 5-point scale)
      const maxV = Math.max.apply(null, nn);
      const minV = Math.min.apply(null, nn);
      if (m >= maxV - 0.5 || m <= minV + 0.5) { flags++; ceilFloor++; }
      if (sd < 0.6) { flags++; lowVar++; }
    });
    const flagRate = flags / (total * 3);
    const score = Math.round(Math.max(20, 100 - flagRate * 100));
    const parts = [];
    if (highMiss)  parts.push(highMiss + ' high-missing');
    if (ceilFloor) parts.push(ceilFloor + ' ceiling/floor');
    if (lowVar)    parts.push(lowVar + ' low-variance');
    const note = parts.length
      ? 'Item flags: ' + parts.join(', ') + ' of ' + total + ' Likert items.'
      : 'All ' + total + ' Likert items are within healthy ranges.';
    return { score: score, note: note };
  }

  function scoreFactorStructure(likertVars) {
    if (likertVars.length < 3) return { score: null, note: 'Too few Likert items for factor structure check.', skip: true };
    // Average pairwise correlation
    const items = likertVars.map(function (v) { return v.values.map(num); });
    let sum = 0, count = 0;
    for (let i = 0; i < items.length; i++) {
      for (let j = i + 1; j < items.length; j++) {
        const a = []; const b = [];
        for (let k = 0; k < items[i].length; k++) {
          if (items[i][k] != null && items[j][k] != null) { a.push(items[i][k]); b.push(items[j][k]); }
        }
        if (a.length > 5) { sum += pearson(a, b); count++; }
      }
    }
    if (count === 0) return { score: null, note: 'Not enough complete data for factor check.', skip: true };
    const avgR = sum / count;
    const score = Math.round(Math.min(100, Math.max(0, avgR * 100 * 2)));
    const note = 'Average inter-item r = ' + avgR.toFixed(2) + ' across ' + likertVars.length + ' items.';
    return { score: score, note: note };
  }

  function scoreResponseQuality(dataset) {
    const allVars = dataset.variables;
    if (!allVars.length) return { score: 50, note: 'No variables to check.' };
    // Overall completion rate
    let totalCells = 0, missing = 0;
    allVars.forEach(function (v) {
      v.values.forEach(function (x) {
        totalCells++;
        if (x === '' || x == null) missing++;
      });
    });
    const completionRate = totalCells > 0 ? 1 - (missing / totalCells) : 0;
    const score = Math.round(Math.min(100, completionRate * 110 - 10));
    let note;
    if (completionRate >= 0.95) note = 'Excellent completion — ' + Math.round(completionRate * 100) + '%.';
    else if (completionRate >= 0.85) note = 'Good completion — ' + Math.round(completionRate * 100) + '%.';
    else if (completionRate >= 0.70) note = 'Moderate completion — ' + Math.round(completionRate * 100) + '% with patches of missingness.';
    else note = 'Low completion — ' + Math.round(completionRate * 100) + '%. Investigate dropouts.';
    return { score: Math.max(20, score), note: note };
  }

  function scoreOpenEnded(dataset) {
    const openVars = dataset.variables.filter(function (v) { return v.types[0] === 'open'; });
    if (openVars.length === 0) return { score: 60, note: 'No open-ended questions in this survey.' };
    let totalResp = 0, totalLen = 0, n = 0;
    openVars.forEach(function (v) {
      v.values.forEach(function (x) {
        if (x && String(x).trim().length > 0) {
          totalResp++;
          totalLen += String(x).trim().split(/\s+/).length;
        }
        n++;
      });
    });
    const respRate = n > 0 ? totalResp / n : 0;
    const avgWords = totalResp > 0 ? totalLen / totalResp : 0;
    const score = Math.round(Math.min(100, respRate * 60 + Math.min(40, avgWords * 2)));
    const note = openVars.length + ' open-ended item' + (openVars.length === 1 ? '' : 's') + ' · ' + Math.round(respRate * 100) + '% response rate · ' + avgWords.toFixed(1) + ' avg words.';
    return { score: Math.max(30, score), note: note };
  }

  function scoreActionability(dataset) {
    const totalVars = dataset.variables.length;
    const likertCount = dataset.variables.filter(function (v) { return v.types[0] === 'likert'; }).length;
    const catCount    = dataset.variables.filter(function (v) { return v.types[0] === 'categorical'; }).length;
    // Reward surveys with structure: at least some Likert + at least one categorical (for grouping)
    let score = 60;
    if (likertCount >= 5) score += 15;
    if (likertCount >= 10) score += 10;
    if (catCount >= 1) score += 10;
    if (totalVars >= 10 && totalVars <= 50) score += 5; // sweet spot for survey length
    score = Math.min(100, score);
    const note = totalVars + ' variables (' + likertCount + ' Likert, ' + catCount + ' categorical). ' + (totalVars > 50 ? 'Survey may be too long.' : 'Length is in a healthy range.');
    return { score: score, note: note };
  }

  /* ────────────────────────────────────────────────────────────
   * Compose the full RSSI score blob.
   * ──────────────────────────────────────────────────────────── */
  function computeRSSI(dataset) {
    const likertVars = dataset.variables.filter(function (v) { return v.types[0] === 'likert'; });

    const reliability      = scoreReliability(likertVars);
    const itemQuality      = scoreItemQuality(likertVars);
    const factorStructure  = scoreFactorStructure(likertVars);
    const responseQuality  = scoreResponseQuality(dataset);
    const openEnded        = scoreOpenEnded(dataset);
    const actionability    = scoreActionability(dataset);

    // Component keys + labels match the template sidebar's "Diagnostic" section
    // exactly, so the sidebar can drive directly off them.
    const components = {
      survey_structure:     Object.assign({ label: 'Survey Structure',     weight: 15 }, actionability),
      question_quality:     Object.assign({ label: 'Question Quality',     weight: 20 }, itemQuality),
      scale_strength:       Object.assign({ label: 'Scale Strength',       weight: 15 }, factorStructure),
      reliability_readiness: Object.assign({ label: 'Reliability Readiness', weight: 25 }, reliability),
      validity_alignment:   Object.assign({ label: 'Validity Alignment',   weight: 15 }, responseQuality),
      response_risk:        Object.assign({ label: 'Response Risk',        weight: 10 }, openEnded),
    };

    // Only include components that were actually computed (skip == true means
    // we lacked the data — those components are reported but excluded from
    // the headline number rather than dragged-down to a 50 placeholder).
    let weighted = 0, totalW = 0;
    Object.keys(components).forEach(function (k) {
      const c = components[k];
      if (c.skip || c.score == null) return;
      weighted += c.score * c.weight;
      totalW   += c.weight;
    });
    const strength = totalW > 0 ? Math.round(weighted / totalW) : 0;
    const verdict = strength >= 85 ? 'Excellent'
                  : strength >= 70 ? 'Strong'
                  : strength >= 55 ? 'Good'
                  : strength >= 40 ? 'Fair'
                  : 'Weak';

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
      dataset: {
        source: dataset.source,
        rowCount: dataset.rowCount,
        itemCount: dataset.variables.length,
        scaleCount: likertVars.length > 0 ? Math.max(1, Math.ceil(likertVars.length / 5)) : 0,
      },
      computed_at: new Date().toISOString(),
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
