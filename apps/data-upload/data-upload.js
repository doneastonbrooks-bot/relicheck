// Data Upload app, client logic.
// -------------------------------------------------------------------
// Two steps. Step 1: drag-drop, paste, or choose a file. Step 2:
// confirm each variable's role via checkboxes. Auto-detects types
// from a sample of values; the user can override by checking other
// boxes (a variable can carry more than one role, e.g. ID + Categorical).
//
// In the current preview, "Continue to analysis" logs the typed dataset
// to the console. The server-side handoff plugs in later.

(function () {
  'use strict';

  // ---------- DOM ----------
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
  const step1        = document.querySelector('.upload-step[data-step="1"]');
  const step2        = document.querySelector('.upload-step[data-step="2"]');
  const varRowsEl    = document.getElementById('varRows');
  const varSummary   = document.getElementById('varSummary');
  const backBtn      = document.getElementById('backToStep1');
  const continueBtn  = document.getElementById('continueToAnalysis');

  // ---------- Types ----------
  const TYPES = ['id', 'categorical', 'likert', 'numeric', 'open', 'date'];

  // ---------- Drag and drop ----------
  ['dragenter', 'dragover'].forEach(evt => {
    dropzone.addEventListener(evt, (e) => {
      e.preventDefault();
      dropzone.classList.add('is-dragover');
    });
  });
  ['dragleave', 'drop'].forEach(evt => {
    dropzone.addEventListener(evt, (e) => {
      e.preventDefault();
      dropzone.classList.remove('is-dragover');
    });
  });
  dropzone.addEventListener('drop', (e) => {
    const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (file) handleFile(file);
  });

  // ---------- File picker ----------
  fileInput.addEventListener('change', (e) => {
    const file = e.target.files && e.target.files[0];
    if (file) handleFile(file);
    fileInput.value = '';
  });

  // ---------- Paste ----------
  pasteToggle.addEventListener('click', () => {
    pastePanel.hidden = false;
    pasteArea.focus();
  });
  pasteCancel.addEventListener('click', () => {
    pastePanel.hidden = true;
    pasteArea.value = '';
  });
  pasteSubmit.addEventListener('click', () => {
    const text = pasteArea.value.trim();
    if (!text) {
      pasteArea.focus();
      return;
    }
    parseAndAdvance(text, 'pasted data');
    pastePanel.hidden = true;
  });

  // ---------- Sample data ----------
  useSampleBtn.addEventListener('click', () => {
    parseAndAdvance(SAMPLE_CSV, 'sample data (Workplace Equity Survey)');
  });

  // ---------- Replace / Back ----------
  replaceBtn.addEventListener('click', resetToStep1);
  backBtn.addEventListener('click', resetToStep1);

  // ---------- Continue to analysis (preview only) ----------
  continueBtn.addEventListener('click', () => {
    const dataset = collectDataset();
    console.log('Dataset ready for the next analysis app:', dataset);
    continueBtn.disabled = true;
    continueBtn.innerHTML =
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg> Ready';
  });

  // ---------- File handler ----------
  function handleFile(file) {
    const reader = new FileReader();
    reader.onload = (e) => {
      parseAndAdvance(e.target.result, file.name);
    };
    reader.onerror = () => {
      alert('Could not read file. Try paste instead.');
    };
    reader.readAsText(file);
  }

  // ---------- Parser ----------
  // Simple CSV / TSV parser. Detects delimiter automatically.
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
    const out = [];
    let cur = '', inQ = false;
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

  // ---------- Auto-detect ----------
  function detectType(values) {
    const nonEmpty = values.filter(v => v !== '' && v != null);
    if (!nonEmpty.length) return ['open']; // default for empty columns

    const allNumeric  = nonEmpty.every(v => !isNaN(parseFloat(v)) && isFinite(v));
    const uniqueCount = new Set(nonEmpty).size;
    const total       = nonEmpty.length;
    const avgLen      = nonEmpty.reduce((s, v) => s + String(v).length, 0) / total;

    // ID: all numeric or short, all unique
    if (uniqueCount === total && (allNumeric || avgLen <= 10)) return ['id'];

    // Date / Time: looks like a date
    const dateLike = /^(\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/]\d{1,2}[-/]\d{2,4})/;
    if (nonEmpty.every(v => dateLike.test(String(v)))) return ['date'];

    // Likert: small integer range, typically 1..5 / 1..7 / 1..10
    if (allNumeric) {
      const nums = nonEmpty.map(v => parseFloat(v));
      const min  = Math.min.apply(null, nums);
      const max  = Math.max.apply(null, nums);
      const allInts = nums.every(n => Number.isInteger(n));
      if (allInts && min >= 0 && max <= 10 && uniqueCount <= 11) return ['likert'];
      return ['numeric'];
    }

    // Open-ended: long text, many unique values
    if (avgLen >= 24 || uniqueCount / total > 0.6) return ['open'];

    // Otherwise categorical
    return ['categorical'];
  }

  // ---------- Render Step 2 ----------
  let currentData = null;

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
      types: parsed.headers.map((_, colIdx) => {
        const colValues = parsed.rows.map(r => r[colIdx] != null ? r[colIdx] : '');
        return detectType(colValues);
      })
    };

    statusEl.hidden = false;
    statusText.textContent =
      'Loaded ' + sourceLabel + ': ' +
      parsed.headers.length + ' variable' + (parsed.headers.length === 1 ? '' : 's') + ', ' +
      parsed.rows.length + ' row' + (parsed.rows.length === 1 ? '' : 's') + '.';

    renderStep2(currentData);
    step1.classList.remove('is-current');
    step2.hidden = false;
    step2.classList.add('is-current');
    // Smooth scroll into view for context
    step2.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function resetToStep1() {
    step2.hidden = true;
    step2.classList.remove('is-current');
    step1.classList.add('is-current');
    statusEl.hidden = true;
    statusText.textContent = '';
    pasteArea.value = '';
    varRowsEl.innerHTML = '';
    currentData = null;
    continueBtn.disabled = false;
    continueBtn.innerHTML =
      'Continue to analysis ' +
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>';
  }

  function renderStep2(data) {
    varRowsEl.innerHTML = '';
    data.headers.forEach((header, colIdx) => {
      const sample = sampleValues(data.rows, colIdx, 4);
      const detected = data.types[colIdx];
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
    updateSummary();

    // Listen for checkbox changes to update the summary
    varRowsEl.querySelectorAll('input[type="checkbox"]').forEach(cb => {
      cb.addEventListener('change', updateSummary);
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

  function updateSummary() {
    if (!currentData) return;
    const rowEls = varRowsEl.querySelectorAll('.var-row');
    let typed = 0;
    rowEls.forEach(r => {
      if (r.querySelector('input[type="checkbox"]:checked')) typed++;
    });
    varSummary.textContent =
      typed + ' of ' + rowEls.length + ' variable' +
      (rowEls.length === 1 ? '' : 's') + ' typed.';
  }

  function collectDataset() {
    if (!currentData) return null;
    const dataset = {
      source: currentData.source,
      variables: currentData.headers.map((header, colIdx) => {
        const checks = varRowsEl.querySelectorAll(
          'input[type="checkbox"][data-col="' + colIdx + '"]:checked'
        );
        return {
          name: header,
          types: Array.from(checks).map(cb => cb.dataset.type),
          sample: sampleValues(currentData.rows, colIdx, 4)
        };
      }),
      rowCount: currentData.rows.length
    };
    return dataset;
  }

  function sampleValues(rows, colIdx, count) {
    const out = [];
    for (let i = 0; i < rows.length && out.length < count; i++) {
      const v = rows[i][colIdx];
      if (v !== '' && v != null) out.push(String(v).slice(0, 24));
    }
    return out;
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // ---------- Sample dataset ----------
  const SAMPLE_CSV =
    'respondent_id,age,gender,department,role_score,belonging_score,recommend_likelihood,response_date,openended_response\n' +
    '001,34,Female,Engineering,4,5,9,2026-04-12,"Good support from manager but workload is heavy."\n' +
    '002,28,Male,Operations,3,3,6,2026-04-12,"Clear expectations would help."\n' +
    '003,45,Female,Marketing,5,5,10,2026-04-13,"I really enjoy the team."\n' +
    '004,31,Non-binary,Engineering,4,4,8,2026-04-13,"The new tooling is great. Onboarding could be tighter."\n' +
    '005,52,Male,Sales,2,2,4,2026-04-14,"Communication from leadership is inconsistent."\n' +
    '006,29,Female,People,4,5,9,2026-04-14,"Strong mission, good people."\n' +
    '007,38,Male,Engineering,5,4,8,2026-04-15,"Pace is fast but rewarding."\n' +
    '008,41,Female,Operations,3,3,5,2026-04-15,"Need more cross-team visibility."\n';

})();
