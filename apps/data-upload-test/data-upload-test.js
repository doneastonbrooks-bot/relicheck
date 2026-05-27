// Test Data Upload app, client logic.
// -------------------------------------------------------------------
// Three steps:
//   1. drag/paste/choose a CSV / TSV
//   2. confirm each column's role (ID, Categorical, Numeric,
//      Item Response, Total Score, Date) via checkboxes
//   3. enter the answer key per item (correct answer + max points
//      + item type via segmented control)
//
// The result is a dataset + answer key object the next item-analysis
// app consumes. In the current preview "Score and continue" logs the
// payload to the console; server-side handoff plugs in later.

(function () {
  'use strict';

  // ---------- DOM ----------
  const dropzone     = document.getElementById('tUploadDropzone');
  const fileInput    = document.getElementById('tUploadFileInput');
  const pasteToggle  = document.getElementById('tPasteToggle');
  const pastePanel   = document.getElementById('tPastePanel');
  const pasteArea    = document.getElementById('tPasteArea');
  const pasteSubmit  = document.getElementById('tPasteSubmit');
  const pasteCancel  = document.getElementById('tPasteCancel');
  const useSampleBtn = document.getElementById('tUseSampleData');
  const replaceBtn   = document.getElementById('tReplaceData');
  const statusEl     = document.getElementById('tUploadStatus');
  const statusText   = document.getElementById('tUploadStatusText');
  const step1        = document.querySelector('.upload-app-test .upload-step[data-step="1"]');
  const step2        = document.querySelector('.upload-app-test .upload-step[data-step="2"]');
  const step3        = document.querySelector('.upload-app-test .upload-step[data-step="3"]');
  const varRowsEl    = document.getElementById('tVarRows');
  const varSummary   = document.getElementById('tVarSummary');
  const keyRowsEl    = document.getElementById('tKeyRows');
  const keySummary   = document.getElementById('tKeySummary');
  const backStep2Btn = document.getElementById('tBackToStep1');
  const backStep3Btn = document.getElementById('tBackToStep2');
  const continueStep3Btn = document.getElementById('tContinueToStep3');
  const continueBtn  = document.getElementById('tContinueToAnalysis');

  const TYPES = ['id', 'categorical', 'numeric', 'item', 'total', 'date'];
  const ITEM_TYPES = [
    { key: 'mc',   label: 'MC' },
    { key: 'tf',   label: 'T/F' },
    { key: 'cr',   label: 'Constructed' },
    { key: 'rub',  label: 'Rubric' },
  ];

  // ---------- Drag and drop ----------
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

  // ---------- File picker / paste / sample ----------
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
    parseAndAdvance(SAMPLE_CSV, 'sample test (8 items, 20 students)');
  });

  // ---------- Step navigation ----------
  replaceBtn.addEventListener('click', resetToStep1);
  backStep2Btn.addEventListener('click', resetToStep1);
  backStep3Btn.addEventListener('click', goToStep2);
  continueStep3Btn.addEventListener('click', goToStep3);
  continueBtn.addEventListener('click', () => {
    const payload = collectPayload();
    console.log('Test dataset + answer key ready:', payload);
    continueBtn.disabled = true;
    continueBtn.innerHTML =
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg> Scored';
  });

  // ---------- File handler ----------
  function handleFile(file) {
    const reader = new FileReader();
    reader.onload = (e) => parseAndAdvance(e.target.result, file.name);
    reader.onerror = () => alert('Could not read file. Try paste instead.');
    reader.readAsText(file);
  }

  // ---------- Parser ----------
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

  // ---------- Auto-detect column roles ----------
  function detectColumnType(header, values) {
    const h = header.toLowerCase();
    const nonEmpty = values.filter(v => v !== '' && v != null);
    if (!nonEmpty.length) return ['item'];

    // Header hints (cheap wins)
    if (/^(id|student_id|case_id|respondent)/.test(h)) return ['id'];
    if (/(total|score_total|raw_score)/.test(h))      return ['total', 'numeric'];
    if (/(date|time|submitted)/.test(h))              return ['date'];
    if (/^item[_\s-]?\d+/.test(h) || /^q\d+$/.test(h)) return ['item'];

    // Date pattern
    const dateLike = /^(\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/]\d{1,2}[-/]\d{2,4})/;
    if (nonEmpty.every(v => dateLike.test(String(v)))) return ['date'];

    // All numeric
    const allNumeric = nonEmpty.every(v => !isNaN(parseFloat(v)) && isFinite(v));
    const uniqueCount = new Set(nonEmpty).size;
    if (allNumeric) {
      if (uniqueCount === nonEmpty.length) return ['id'];
      return ['numeric'];
    }

    // Few unique short codes → likely item response (MC letter answers) or categorical
    const shortAlpha = nonEmpty.every(v => String(v).length <= 4);
    if (shortAlpha && uniqueCount <= 6) return ['item'];

    return ['categorical'];
  }

  // ---------- Render Step 2 ----------
  let currentData = null;
  let currentItemKeys = []; // column indices flagged as 'item' for step 3

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
        return detectColumnType(header, colValues);
      })
    };
    statusEl.hidden = false;
    statusText.textContent =
      'Loaded ' + sourceLabel + ': ' +
      parsed.headers.length + ' column' + (parsed.headers.length === 1 ? '' : 's') + ', ' +
      parsed.rows.length + ' row' + (parsed.rows.length === 1 ? '' : 's') + '.';
    renderStep2();
    goToStep2();
  }

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
    updateVarSummary();
    varRowsEl.querySelectorAll('input[type="checkbox"]').forEach(cb => {
      cb.addEventListener('change', updateVarSummary);
    });
  }

  function typeCell(type, isChecked, colIdx) {
    const id = 'tcb-' + colIdx + '-' + type;
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

  function updateVarSummary() {
    const rowEls = varRowsEl.querySelectorAll('.var-row');
    let typed = 0; let items = 0;
    rowEls.forEach(r => {
      if (r.querySelector('input[type="checkbox"]:checked')) typed++;
      if (r.querySelector('input[type="checkbox"][data-type="item"]:checked')) items++;
    });
    varSummary.textContent =
      typed + ' of ' + rowEls.length + ' column' +
      (rowEls.length === 1 ? '' : 's') + ' tagged, ' +
      items + ' item' + (items === 1 ? '' : 's') + ' to score.';
  }

  // ---------- Step 3: answer key ----------
  function goToStep2() {
    step1.classList.remove('is-current');
    step2.hidden = false;
    step2.classList.add('is-current');
    step3.hidden = true;
    step3.classList.remove('is-current');
    step2.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function goToStep3() {
    // Collect which columns are marked as 'item'
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
    keyRowsEl.querySelectorAll('.key-input').forEach(input => {
      input.addEventListener('input', updateKeySummary);
    });
    keyRowsEl.querySelectorAll('.seg-control button').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const row = btn.closest('.key-row');
        row.querySelectorAll('.seg-control button').forEach(b => b.classList.toggle('is-on', b === btn));
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

  // ---------- Helpers ----------
  function resetToStep1() {
    step2.hidden = true; step2.classList.remove('is-current');
    step3.hidden = true; step3.classList.remove('is-current');
    step1.classList.add('is-current');
    statusEl.hidden = true;
    statusText.textContent = '';
    pasteArea.value = '';
    varRowsEl.innerHTML = '';
    keyRowsEl.innerHTML = '';
    currentData = null; currentItemKeys = [];
    continueBtn.disabled = false;
    continueBtn.innerHTML =
      'Score and continue ' +
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>';
  }

  function collectPayload() {
    if (!currentData) return null;
    const dataset = {
      source: currentData.source,
      columns: currentData.headers.map((header, colIdx) => {
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
    const answerKey = [];
    keyRowsEl.querySelectorAll('.key-row').forEach(r => {
      const colIdx = parseInt(r.dataset.col, 10);
      const correct = r.querySelector('input[data-field="correct"]').value.trim();
      const max     = parseFloat(r.querySelector('input[data-field="max"]').value) || 0;
      const typeBtn = r.querySelector('.seg-control button.is-on');
      answerKey.push({
        item:    currentData.headers[colIdx],
        correct: correct,
        max:     max,
        type:    typeBtn ? typeBtn.dataset.itype : 'mc'
      });
    });
    return { dataset, answerKey };
  }

  function sampleValues(rows, colIdx, count) {
    const out = [];
    for (let i = 0; i < rows.length && out.length < count; i++) {
      const v = rows[i][colIdx];
      if (v !== '' && v != null) out.push(String(v).slice(0, 18));
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

  // ---------- Sample test dataset (8 items, 20 students) ----------
  const SAMPLE_CSV =
    'student_id,gender,grade,item_1,item_2,item_3,item_4,item_5,item_6,item_7,item_8,total_score,test_date\n' +
    '001,F,8,A,B,C,1,A,D,B,2,15,2026-04-12\n' +
    '002,M,8,B,B,A,1,A,C,B,2,12,2026-04-12\n' +
    '003,F,8,A,C,C,0,A,D,A,2,14,2026-04-12\n' +
    '004,F,8,A,B,C,1,B,D,B,3,17,2026-04-12\n' +
    '005,M,8,C,B,C,1,A,D,B,2,15,2026-04-12\n' +
    '006,M,8,A,A,C,0,A,B,B,1,11,2026-04-13\n' +
    '007,F,8,A,B,B,1,A,D,B,2,14,2026-04-13\n' +
    '008,F,8,A,B,C,1,A,D,C,2,15,2026-04-13\n' +
    '009,M,8,B,B,C,1,C,D,B,2,13,2026-04-13\n' +
    '010,F,8,A,B,A,0,A,D,B,3,15,2026-04-13\n' +
    '011,M,8,A,B,C,1,A,D,A,1,13,2026-04-13\n' +
    '012,F,8,A,B,C,1,A,D,B,2,16,2026-04-14\n' +
    '013,M,8,D,B,C,1,A,D,B,2,14,2026-04-14\n' +
    '014,F,8,A,B,C,0,A,D,B,2,14,2026-04-14\n' +
    '015,M,8,A,C,C,1,A,A,B,2,13,2026-04-14\n' +
    '016,F,8,A,B,C,1,A,D,B,3,17,2026-04-14\n' +
    '017,M,8,A,B,D,1,A,D,B,2,15,2026-04-15\n' +
    '018,F,8,A,B,C,1,A,D,B,2,16,2026-04-15\n' +
    '019,F,8,B,B,C,1,A,D,B,2,14,2026-04-15\n' +
    '020,M,8,A,A,C,1,A,D,C,2,13,2026-04-15\n';

})();
