// ReliCheck Evidence Intake Wizard, shared engine.
// -------------------------------------------------------------------
// Reads window.INTAKE_CONFIG (set inline by the mounting page from the
// per-studio config file) and drives the step UI rendered by render.php.
// Same engine handles 2-step Survey/MM/360 flows and 3-step TIA flow;
// step presence is determined by the config.
//
// Behavior parity with the previous two-app implementation is preserved
// for this Pass 1 refactor. Detector branches per cfg.detector_kind
// ('survey' | 'tia'); the gender→item bug noted in the test detector
// is preserved for now and gets fixed in a later pass.

(function () {
  'use strict';

  const cfg = window.INTAKE_CONFIG;
  if (!cfg) { console.warn('Evidence Intake: no config loaded'); return; }

  // ----- Resolve steps from config -----
  const mapStep    = cfg.steps.find(s => s.key === 'map');
  const keyStep    = cfg.steps.find(s => s.key === 'answer_key');
  const TYPES      = mapStep ? mapStep.column_keys : [];
  const ITEM_TYPES = keyStep ? keyStep.item_types  : [];

  // ----- DOM elements -----
  const dropzone     = document.getElementById('uploadDropzone');
  const fileInput    = document.getElementById('uploadFileInput');
  const pasteToggle  = document.getElementById('pasteToggle');
  const pastePanel   = document.getElementById('pastePanel');
  const pasteArea    = document.getElementById('pasteArea');
  const pasteSubmit  = document.getElementById('pasteSubmit');
  const pasteCancel  = document.getElementById('pasteCancel');
  const useSampleBtn = document.getElementById('useSampleData');
  const replaceBtn   = document.getElementById('replaceData');
  const statusEl     = document.getElementById('uploadStatus');
  const statusText   = document.getElementById('uploadStatusText');

  const step1 = document.querySelector('.upload-app .upload-step[data-step="1"]');
  const step2 = document.querySelector('.upload-app .upload-step[data-step="2"]');
  const step3 = document.querySelector('.upload-app .upload-step[data-step="3"]');

  const varRowsEl       = document.getElementById('varRows');
  const varSummary      = document.getElementById('varSummary');
  const continueFromMap = document.getElementById('continueFromMap');
  const backToStep1     = document.getElementById('backToStep1');

  const keyRowsEl       = step3 ? document.getElementById('keyRows')       : null;
  const keySummary      = step3 ? document.getElementById('keySummary')    : null;
  const continueFromKey = step3 ? document.getElementById('continueFromKey'): null;
  const backToStep2     = step3 ? document.getElementById('backToStep2')   : null;

  if (!dropzone) { console.warn('Evidence Intake: no dropzone'); return; }

  let currentData     = null;
  let currentItemKeys = []; // column indices flagged 'item' (TIA only)

  // ----- Drag and drop -----
  ['dragenter', 'dragover'].forEach(evt => {
    dropzone.addEventListener(evt, (e) => { e.preventDefault(); dropzone.classList.add('is-dragover'); });
  });
  ['dragleave', 'drop'].forEach(evt => {
    dropzone.addEventListener(evt, (e) => { e.preventDefault(); dropzone.classList.remove('is-dragover'); });
  });
  dropzone.addEventListener('drop', (e) => {
    const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (file) handleFile(file);
  });

  // ----- File picker / paste / sample -----
  fileInput.addEventListener('change', (e) => {
    const file = e.target.files && e.target.files[0];
    if (file) handleFile(file);
    fileInput.value = '';
  });
  pasteToggle.addEventListener('click', () => { pastePanel.hidden = false; pasteArea.focus(); });
  pasteCancel.addEventListener('click', () => { pastePanel.hidden = true; pasteArea.value = ''; });
  pasteSubmit.addEventListener('click', () => {
    const text = pasteArea.value.trim();
    if (!text) { pasteArea.focus(); return; }
    parseAndAdvance(text, 'pasted data');
    pastePanel.hidden = true;
  });
  useSampleBtn.addEventListener('click', () => {
    parseAndAdvance(cfg.sample_csv, cfg.sample_label_loaded || 'sample data');
  });

  // ----- Step navigation -----
  replaceBtn.addEventListener('click', resetToStep1);
  backToStep1.addEventListener('click', resetToStep1);
  if (backToStep2) backToStep2.addEventListener('click', goToStep2);

  continueFromMap.addEventListener('click', () => {
    if (step3) {
      goToStep3();
    } else {
      finishWizard();
    }
  });
  if (continueFromKey) {
    continueFromKey.addEventListener('click', finishWizard);
  }

  // ----- File handler -----
  // Branches on file extension. CSV/TSV/TXT go through FileReader.readAsText
  // (the old path); .xlsx and .xls go through SheetJS, which is loaded from
  // CDN on first Excel upload so we don't pay the cost on plain CSV uploads.
  function handleFile(file) {
    const name = (file && file.name || '').toLowerCase();
    const isExcel = /\.(xlsx|xlsm|xlsb|xls)$/.test(name);
    if (isExcel) {
      ensureSheetJS().then(() => readExcelAsCsv(file))
        .then((csv) => parseAndAdvance(csv, file.name))
        .catch((err) => {
          console.error('Excel read failed:', err);
          alert('Could not read that Excel file. Try saving it as CSV and uploading again, or paste the data instead.');
        });
      return;
    }
    const reader = new FileReader();
    reader.onload = (e) => parseAndAdvance(e.target.result, file.name);
    reader.onerror = () => alert('Could not read file. Try paste instead.');
    reader.readAsText(file);
  }

  // Lazy-load SheetJS (XLSX.js) once. Resolves with the global XLSX namespace.
  let _xlsxPromise = null;
  function ensureSheetJS() {
    if (window.XLSX) return Promise.resolve(window.XLSX);
    if (_xlsxPromise) return _xlsxPromise;
    _xlsxPromise = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
      s.onload  = () => resolve(window.XLSX);
      s.onerror = () => reject(new Error('Could not load Excel reader library.'));
      document.head.appendChild(s);
    });
    return _xlsxPromise;
  }

  // Read an .xlsx/.xls into the first sheet's CSV. We hand that CSV to the
  // existing parseAndAdvance so type detection, header mapping, and the rest
  // of the wizard logic don't have to know that Excel was the source.
  function readExcelAsCsv(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = (e) => {
        try {
          const data = new Uint8Array(e.target.result);
          const wb   = window.XLSX.read(data, { type: 'array' });
          const firstSheetName = wb.SheetNames[0];
          if (!firstSheetName) { reject(new Error('No sheets in workbook.')); return; }
          const ws  = wb.Sheets[firstSheetName];
          const csv = window.XLSX.utils.sheet_to_csv(ws, { blankrows: false });
          resolve(csv);
        } catch (err) { reject(err); }
      };
      reader.onerror = () => reject(new Error('Could not read file bytes.'));
      reader.readAsArrayBuffer(file);
    });
  }

  // ----- CSV / TSV parser -----
  function parseDelimited(text) {
    text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (!text) return { headers: [], rows: [] };
    const firstLine = text.split('\n', 1)[0];
    const delim = firstLine.split('\t').length > firstLine.split(',').length ? '\t' : ',';
    const lines = text.split('\n');
    const rows = lines.map(line => splitRespectQuotes(line, delim));
    const headers = rows.shift().map(h => h.trim());
    return { headers, rows };
  }
  function splitRespectQuotes(line, delim) {
    const out = []; let cur = '', inQ = false;
    for (let i = 0; i < line.length; i++) {
      const ch = line[i];
      if (inQ) {
        if (ch === '"' && line[i + 1] === '"') { cur += '"'; i++; }
        else if (ch === '"') inQ = false;
        else cur += ch;
      } else {
        if (ch === '"') inQ = true;
        else if (ch === delim) { out.push(cur); cur = ''; }
        else cur += ch;
      }
    }
    out.push(cur);
    return out.map(s => s.trim());
  }

  // ----- Auto-detect (branches on cfg.detector_kind) -----
  function detectType(values, header) {
    return cfg.detector_kind === 'tia' ? detectTia(values, header) : detectSurvey(values);
  }

  function detectSurvey(values) {
    const nonEmpty = values.filter(v => v !== '' && v != null);
    if (!nonEmpty.length) return ['open'];
    const allNumeric  = nonEmpty.every(v => !isNaN(parseFloat(v)) && isFinite(v));
    const uniqueCount = new Set(nonEmpty).size;
    const total       = nonEmpty.length;
    const avgLen      = nonEmpty.reduce((s, v) => s + String(v).length, 0) / total;

    if (uniqueCount === total && (allNumeric || avgLen <= 10)) return ['id'];

    const dateLike = /^(\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/]\d{1,2}[-/]\d{2,4})/;
    if (nonEmpty.every(v => dateLike.test(String(v)))) return ['date'];

    if (allNumeric) {
      const nums = nonEmpty.map(v => parseFloat(v));
      const min  = Math.min.apply(null, nums);
      const max  = Math.max.apply(null, nums);
      const allInts = nums.every(n => Number.isInteger(n));
      if (allInts && min >= 0 && max <= 10 && uniqueCount <= 11) return ['likert'];
      return ['numeric'];
    }

    if (avgLen >= 24 || uniqueCount / total > 0.6) return ['open'];
    return ['categorical'];
  }

  function detectTia(values, header) {
    const h = (header || '').toLowerCase();
    const nonEmpty = values.filter(v => v !== '' && v != null);
    if (!nonEmpty.length) return ['item'];

    if (/^(id|student_id|case_id|respondent)/.test(h)) return ['id'];
    if (/(total|score_total|raw_score)/.test(h))      return ['total', 'numeric'];
    if (/(date|time|submitted)/.test(h))              return ['date'];
    if (/^item[_\s-]?\d+/.test(h) || /^q\d+$/.test(h)) return ['item'];

    const dateLike = /^(\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/]\d{1,2}[-/]\d{2,4})/;
    if (nonEmpty.every(v => dateLike.test(String(v)))) return ['date'];

    const allNumeric  = nonEmpty.every(v => !isNaN(parseFloat(v)) && isFinite(v));
    const uniqueCount = new Set(nonEmpty).size;
    if (allNumeric) {
      if (uniqueCount === nonEmpty.length) return ['id'];
      return ['numeric'];
    }

    const shortAlpha = nonEmpty.every(v => String(v).length <= 4);
    if (shortAlpha && uniqueCount <= 6) return ['item']; // known limitation: gender (F/M) hits this
    return ['categorical'];
  }

  // ----- Parse + advance to step 2 -----
  function parseAndAdvance(text, sourceLabel) {
    const parsed = parseDelimited(text);
    if (!parsed.headers.length) {
      alert('Could not find columns in that data. Try again.');
      return;
    }
    currentData = {
      source: sourceLabel,
      headers: parsed.headers,
      rows: parsed.rows,
      types: parsed.headers.map((header, colIdx) => {
        const colValues = parsed.rows.map(r => r[colIdx] != null ? r[colIdx] : '');
        return detectType(colValues, header);
      })
    };
    const noun = (cfg.detector_kind === 'tia') ? 'column' : 'variable';
    statusEl.hidden = false;
    statusText.textContent =
      'Loaded ' + sourceLabel + ': ' +
      parsed.headers.length + ' ' + noun + (parsed.headers.length === 1 ? '' : 's') + ', ' +
      parsed.rows.length + ' row' + (parsed.rows.length === 1 ? '' : 's') + '.';
    renderStep2();
    goToStep2();
  }

  // ----- Step 2 render -----
  function renderStep2() {
    varRowsEl.innerHTML = '';
    currentData.headers.forEach((header, colIdx) => {
      const sample = sampleValues(currentData.rows, colIdx, 4);
      const detected = currentData.types[colIdx];
      const row = document.createElement('div');
      row.className = 'var-row';
      row.setAttribute('role', 'row');
      row.dataset.col = colIdx;
      row.innerHTML =
        '<div class="var-col var-col-name" role="cell" title="' + escapeHtml(header) + '">' + escapeHtml(header) + '</div>' +
        '<div class="var-col var-col-sample" role="cell" title="' + escapeHtml(sample.join(' · ')) + '">' + escapeHtml(sample.join(' · ')) + '</div>' +
        TYPES.map(t => typeCell(t, detected.indexOf(t) !== -1, colIdx)).join('');
      varRowsEl.appendChild(row);
    });
    updateMapSummary();
    varRowsEl.querySelectorAll('input[type="checkbox"]').forEach(cb => {
      cb.addEventListener('change', updateMapSummary);
    });
  }

  function typeCell(type, isChecked, colIdx) {
    const id = 'cb-' + colIdx + '-' + type;
    return (
      '<div class="var-col var-col-type" role="cell">' +
        '<label class="var-check" for="' + id + '">' +
          '<input id="' + id + '" type="checkbox" data-type="' + type + '" data-col="' + colIdx + '"' + (isChecked ? ' checked' : '') + '>' +
          '<span class="check-box" aria-hidden="true">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>' +
          '</span>' +
        '</label>' +
      '</div>'
    );
  }

  function updateMapSummary() {
    const rowEls = varRowsEl.querySelectorAll('.var-row');
    const noun = (cfg.detector_kind === 'tia') ? 'column' : 'variable';
    let typed = 0;
    rowEls.forEach(r => { if (r.querySelector('input[type="checkbox"]:checked')) typed++; });
    if (step3) {
      // TIA: also surface item count for the next step
      let items = 0;
      rowEls.forEach(r => { if (r.querySelector('input[type="checkbox"][data-type="item"]:checked')) items++; });
      varSummary.textContent =
        typed + ' of ' + rowEls.length + ' ' + noun + (rowEls.length === 1 ? '' : 's') + ' tagged, ' +
        items + ' item' + (items === 1 ? '' : 's') + ' to score.';
    } else {
      varSummary.textContent =
        typed + ' of ' + rowEls.length + ' ' + noun + (rowEls.length === 1 ? '' : 's') + ' typed.';
    }
  }

  // ----- Step 3 (TIA): answer key -----
  function goToStep2() {
    step1.classList.remove('is-current');
    step2.hidden = false;
    step2.classList.add('is-current');
    if (step3) { step3.hidden = true; step3.classList.remove('is-current'); }
    step2.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function goToStep3() {
    currentItemKeys = [];
    varRowsEl.querySelectorAll('input[type="checkbox"][data-type="item"]:checked').forEach(cb => {
      currentItemKeys.push(parseInt(cb.dataset.col, 10));
    });
    if (currentItemKeys.length === 0) {
      alert('Mark at least one column as "Item Response" to enter an answer key.');
      return;
    }
    renderStep3();
    step2.classList.remove('is-current');
    step3.hidden = false;
    step3.classList.add('is-current');
    step3.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function renderStep3() {
    keyRowsEl.innerHTML = '';
    currentItemKeys.forEach((colIdx) => {
      const header = currentData.headers[colIdx];
      const colValues = currentData.rows.map(r => r[colIdx] != null ? r[colIdx] : '');
      const sample = sampleValues(currentData.rows, colIdx, 5);
      const guess = mostCommon(colValues.filter(v => v !== '' && v != null));
      const detectedType = guessItemType(colValues);
      const row = document.createElement('div');
      row.className = 'key-row';
      row.setAttribute('role', 'row');
      row.dataset.col = colIdx;
      row.innerHTML =
        '<div class="key-col key-col-name" role="cell" title="' + escapeHtml(header) + '">' + escapeHtml(header) + '</div>' +
        '<div class="key-col key-col-sample" role="cell" title="' + escapeHtml(sample.join(' · ')) + '">' + escapeHtml(sample.join(' · ')) + '</div>' +
        '<div class="key-col key-col-correct" role="cell">' +
          '<input class="key-input" type="text" data-col="' + colIdx + '" data-field="correct" value="' + escapeHtml(guess) + '" placeholder="e.g. A">' +
        '</div>' +
        '<div class="key-col key-col-max" role="cell">' +
          '<input class="key-input key-input-points" type="number" min="0" step="1" data-col="' + colIdx + '" data-field="max" value="1">' +
        '</div>' +
        '<div class="key-col key-col-type" role="cell">' + segControl(colIdx, detectedType) + '</div>';
      keyRowsEl.appendChild(row);
    });
    updateKeySummary();
    keyRowsEl.querySelectorAll('.key-input').forEach(input => input.addEventListener('input', updateKeySummary));
    keyRowsEl.querySelectorAll('.seg-control button').forEach(btn => {
      btn.addEventListener('click', () => {
        const r = btn.closest('.key-row');
        r.querySelectorAll('.seg-control button').forEach(b => b.classList.toggle('is-on', b === btn));
        updateKeySummary();
      });
    });
  }

  function segControl(colIdx, current) {
    return (
      '<div class="seg-control" data-col="' + colIdx + '">' +
        ITEM_TYPES.map(t =>
          '<button type="button" data-itype="' + t.key + '"' +
            (t.key === current ? ' class="is-on"' : '') + '>' + t.label + '</button>'
        ).join('') +
      '</div>'
    );
  }

  function updateKeySummary() {
    const rowEls = keyRowsEl.querySelectorAll('.key-row');
    let complete = 0;
    rowEls.forEach(r => {
      const correct = r.querySelector('input[data-field="correct"]');
      const max     = r.querySelector('input[data-field="max"]');
      if (correct && correct.value.trim() && max && parseFloat(max.value) > 0) complete++;
    });
    keySummary.textContent = complete + ' of ' + rowEls.length + ' items scored.';
  }

  // ----- Finish: persist + hand off to the next analysis app -----
  // Per-studio next destinations. The MM next-route is computed at click
  // time below because it depends on whether the wizard is running.
  const NEXT_ROUTE = {
    survey: '/studio-survey-projects.php',
    tia:    '/studio-tia-projects.php',
    '360':  '/studio-360-projects.php',
    // mm: see resolveNextRoute() below
  };

  // Resolve the destination after Ready. If a per-studio wizard is active
  // (?wizard=1 in the URL, set when entering from /<studio>-wizard.php),
  // return to that wizard's next step. Otherwise land on the studio picker.
  const WIZARD_RETURN = {
    mm:     '/mm-wizard.php?step=3',
    survey: '/survey-wizard.php?step=3',
    tia:    '/tia-wizard.php?step=3',
    '360':  '/360-wizard.php?step=3',
  };
  function resolveNextRoute() {
    const qp  = new URLSearchParams(window.location.search);
    const pid = qp.get('project_id');
    if (qp.get('wizard') === '1' && pid && WIZARD_RETURN[cfg.slug]) {
      return WIZARD_RETURN[cfg.slug] + '&project_id=' + encodeURIComponent(pid);
    }
    return NEXT_ROUTE[cfg.slug];
  }

  function finishWizard() {
    const payload = collectPayload();
    console.log('Evidence Intake payload:', payload);

    // Persist the dataset under a PROJECT-scoped key so a user with
    // multiple projects in the same studio doesn't conflate uploads.
    // Per [[relicheck-reports-model]]: relicheck.dataset.<project_id>.
    // Wrapped in try/catch in case storage is full or unavailable.
    const projectId = (window.RELICHECK_PROJECT_ID && String(window.RELICHECK_PROJECT_ID)) || 'untitled-project';
    try {
      window.localStorage.setItem(
        'relicheck.dataset.' + projectId,
        JSON.stringify({ savedAt: Date.now(), studio: cfg.slug, payload: payload })
      );
    } catch (e) {
      console.warn('Could not save dataset to localStorage:', e);
    }

    // If we're embedded in a wizard popup (the host sets RELICHECK_WIZARD_HOST
    // before loading this script), fire a custom event so the wizard advances
    // to its next step without a full-page redirect. Falls back to the normal
    // redirect path otherwise.
    if (window.RELICHECK_WIZARD_HOST === true) {
      try {
        document.dispatchEvent(new CustomEvent('relicheck:intake-complete', { detail: { payload: payload } }));
      } catch (e) {
        document.dispatchEvent(new Event('relicheck:intake-complete'));
      }
      const wbtn = step3 ? continueFromKey : continueFromMap;
      if (wbtn) {
        wbtn.disabled = true;
        wbtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg> Loaded';
      }
      return; // wizard host owns the next-step navigation
    }
    const next = resolveNextRoute();
    const btn = step3 ? continueFromKey : continueFromMap;
    btn.disabled = true;
    btn.innerHTML =
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg> ' +
      (next ? 'Continuing…' : (step3 ? 'Scored' : 'Ready'));

    if (next) {
      // Brief delay so the user sees the success state before navigation.
      setTimeout(() => { window.location.href = next; }, 400);
    }
  }

  function resetToStep1() {
    step2.hidden = true;
    step2.classList.remove('is-current');
    if (step3) { step3.hidden = true; step3.classList.remove('is-current'); }
    step1.classList.add('is-current');
    statusEl.hidden = true;
    statusText.textContent = '';
    pasteArea.value = '';
    varRowsEl.innerHTML = '';
    if (keyRowsEl) keyRowsEl.innerHTML = '';
    currentData = null;
    currentItemKeys = [];
    const btn = step3 ? continueFromKey : continueFromMap;
    btn.disabled = false;
    // Restore original label from config
    const finalStep = step3 ? cfg.steps.find(s => s.key === 'answer_key') : cfg.steps.find(s => s.key === 'map');
    if (finalStep) {
      btn.innerHTML = escapeHtml(finalStep.continue_label) +
        ' <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>';
    }
  }

  // ----- Helpers -----
  function collectPayload() {
    if (!currentData) return null;
    const dataset = {
      source: currentData.source,
      variables: currentData.headers.map((header, colIdx) => {
        const checks = varRowsEl.querySelectorAll(
          'input[type="checkbox"][data-col="' + colIdx + '"]:checked'
        );
        // Include full values (not just a sample) so downstream
        // analysis apps can compute against the real dataset.
        const values = currentData.rows.map(r => r[colIdx] != null ? r[colIdx] : '');
        return {
          name: header,
          types: Array.from(checks).map(cb => cb.dataset.type),
          sample: sampleValues(currentData.rows, colIdx, 4),
          values: values
        };
      }),
      rowCount: currentData.rows.length
    };
    const out = { studio: cfg.slug, dataset };
    if (step3 && keyRowsEl) {
      out.answer_key = [];
      keyRowsEl.querySelectorAll('.key-row').forEach(r => {
        const colIdx = parseInt(r.dataset.col, 10);
        const correct = r.querySelector('input[data-field="correct"]').value.trim();
        const max     = parseFloat(r.querySelector('input[data-field="max"]').value) || 0;
        const typeBtn = r.querySelector('.seg-control button.is-on');
        out.answer_key.push({
          item:    currentData.headers[colIdx],
          correct: correct,
          max:     max,
          type:    typeBtn ? typeBtn.dataset.itype : 'mc'
        });
      });
    }
    return out;
  }

  function sampleValues(rows, colIdx, count) {
    const out = [];
    for (let i = 0; i < rows.length && out.length < count; i++) {
      const v = rows[i][colIdx];
      if (v !== '' && v != null) out.push(String(v).slice(0, 24));
    }
    return out;
  }
  function mostCommon(arr) {
    const counts = Object.create(null);
    arr.forEach(v => { counts[v] = (counts[v] || 0) + 1; });
    let best = '', bestN = 0;
    Object.keys(counts).forEach(k => { if (counts[k] > bestN) { best = k; bestN = counts[k]; } });
    return best;
  }
  function guessItemType(values) {
    const nonEmpty = values.filter(v => v !== '' && v != null);
    if (!nonEmpty.length) return 'mc';
    const unique = new Set(nonEmpty);
    if (unique.size <= 2 && nonEmpty.every(v => /^(0|1|true|false|t|f)$/i.test(String(v)))) return 'tf';
    if (unique.size <= 6 && nonEmpty.every(v => String(v).length <= 3)) return 'mc';
    if (nonEmpty.every(v => !isNaN(parseFloat(v)))) return 'rub';
    return 'cr';
  }
  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
})();
