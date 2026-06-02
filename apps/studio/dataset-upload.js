// ReliCheck unified data upload widget.
// Replaces the per-studio upload mechanisms (analysis-upload.js, evidence-intake.js).
//
// Supported formats:
//   Tier 1 (in-browser): CSV, TSV, XLSX, JSON
//   Tier 2 (platform-aware): Qualtrics CSV/XLSX, Google Forms CSV
//   No paste option.
//
// API:
//   DatasetUpload.open(ctx)       — upload new data (modal)
//   DatasetUpload.openSaved(ctx)  — pick from saved datasets (modal)
//
// ctx = { kind, projectId, projectType, title, onLoaded(null, projectId) }
//   kind        — 'descriptive' | 'inferential' | 'mm' | (future studio slug)
//   projectId   — existing project to link into (0/null = create new)
//   projectType — 'analysis' | 'mm'
//   title       — default dataset title
//   onLoaded    — called as onLoaded(null, projectId) when ready
//
// Canonical analysis_type values are used in the confirmation table and stored
// in column_meta.analysis_type alongside the legacy type for backward compat.
(function () {
  'use strict';

  // ── Canonical type definitions ──────────────────────────────────────────────
  const ANALYSIS_TYPES = [
    { v: 'likert_item',         label: 'Likert / Scale item',      group: 'Quantitative' },
    { v: 'demographic_numeric', label: 'Numeric',                  group: 'Quantitative' },
    { v: 'scale_score',         label: 'Scale score (computed)',   group: 'Quantitative' },
    { v: 'demographic_nominal', label: 'Categorical',              group: 'Categorical' },
    { v: 'demographic_ordinal', label: 'Categorical (ordered)',    group: 'Categorical' },
    { v: 'binary',              label: 'Yes / No or Binary',       group: 'Categorical' },
    { v: 'open_ended',          label: 'Open-ended text',          group: 'Text' },
    { v: 'narrative',           label: 'Narrative / long text',    group: 'Text' },
    { v: 'identifier',          label: 'Identifier / ID',          group: 'Other' },
    { v: 'date_time',           label: 'Date / Time',              group: 'Other' },
    { v: 'metadata',            label: 'Metadata',                 group: 'Other' },
    { v: 'structural',          label: 'Ignore this column',       group: 'Other' },
  ];

  // Legacy type sent to create.php for backward compat (RSSI engine etc.)
  const AT_TO_DSTYPE = {
    'likert_item':         'likert',
    'scale_score':         'numeric',
    'open_ended':          'open',
    'narrative':           'open',
    'demographic_nominal': 'single',
    'demographic_ordinal': 'single',
    'demographic_numeric': 'numeric',
    'binary':              'single',
    'identifier':          'identifier',
    'date_time':           'open',
    'metadata':            'open',
    'computed_score':      'numeric',
    'qualitative_code':    'open',
    'theme':               'open',
    'structural':          'ignore',
  };

  // ── Utilities ───────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/[&<>"']/g, function (c) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
      });
  }

  // ── Type detection ──────────────────────────────────────────────────────────
  function detectAnalysisType(values, header, colIdx) {
    const h = String(header || '').toLowerCase().trim();

    // Header-name heuristics take priority.
    if (/^(respondent_?id|student_?id|case_?id|response_?id|participant_?id|uuid|email|phone)$/i.test(h)) return 'identifier';
    if (/^(timestamp|submitted_?at|completed_?at|date_?submitted|recorded_?date)$/i.test(h)) return 'date_time';

    const nm = values.filter(function (v) { return v != null && String(v).trim() !== ''; });
    if (!nm.length) return 'structural';

    const distinct = new Set(nm.map(String));
    const total    = nm.length;
    const nums     = nm.map(function (v) { const x = parseFloat(v); return isFinite(x) ? x : null; }).filter(function (x) { return x !== null; });
    const numFrac  = nums.length / total;

    if (numFrac >= 0.85) {
      const allInt = nums.every(function (x) { return Number.isInteger(x); });
      const lo     = Math.min.apply(null, nums);
      const hi     = Math.max.apply(null, nums);
      if (allInt && lo >= 0 && hi <= 11 && distinct.size <= 11) return 'likert_item';
      if (allInt && distinct.size === 2 && lo === 0 && hi === 1)  return 'binary';
      return 'demographic_numeric';
    }

    const avgLen  = nm.reduce(function (s, v) { return s + String(v).length; }, 0) / total;
    const dateFrac = nm.filter(function (v) {
      return /\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/]\d{1,2}[-/]\d{2,4}/.test(String(v));
    }).length / total;
    if (dateFrac >= 0.7) return 'date_time';

    // All-unique first column → identifier
    if (distinct.size === total && colIdx === 0) return 'identifier';

    if (avgLen > 40 || distinct.size > Math.max(20, total * 0.6)) return 'open_ended';
    if (distinct.size === 2) return 'binary';
    if (distinct.size <= 20 && avgLen <= 40) return 'demographic_nominal';
    return 'open_ended';
  }

  // ── CSV / TSV parser ────────────────────────────────────────────────────────
  function parseDelimited(text) {
    text = String(text || '').replace(/\r\n?/g, '\n').replace(/\n+$/, '');
    if (!text.trim()) return { headers: [], rows: [] };
    const firstLine = text.split('\n', 1)[0];
    const delim = firstLine.indexOf('\t') >= 0 ? '\t' : ',';
    const lines = splitLines(text, delim);
    if (!lines.length) return { headers: [], rows: [] };
    const headers = lines[0].map(function (h, i) { h = String(h).trim(); return h || ('Column ' + (i + 1)); });
    const rows = lines.slice(1).filter(function (r) { return r.some(function (c) { return String(c).trim() !== ''; }); });
    return { headers: headers, rows: rows };
  }

  function splitLines(text, delim) {
    const out = []; let row = [], field = '', q = false;
    for (let i = 0; i < text.length; i++) {
      const c = text[i];
      if (q) {
        if (c === '"') { if (text[i + 1] === '"') { field += '"'; i++; } else q = false; }
        else field += c;
      } else if (c === '"') { q = true; }
      else if (c === delim) { row.push(field); field = ''; }
      else if (c === '\n') { row.push(field); out.push(row); row = []; field = ''; }
      else field += c;
    }
    row.push(field); out.push(row);
    return out;
  }

  // ── JSON parser ─────────────────────────────────────────────────────────────
  function parseJson(text) {
    const data = JSON.parse(text);
    if (Array.isArray(data) && data.length > 0 && typeof data[0] === 'object' && !Array.isArray(data[0])) {
      const headers = Object.keys(data[0]);
      const rows = data.map(function (obj) {
        return headers.map(function (h) { return obj[h] != null ? String(obj[h]) : ''; });
      });
      return { headers: headers, rows: rows };
    }
    if (data && Array.isArray(data.headers) && Array.isArray(data.rows)) {
      return { headers: data.headers.map(String), rows: data.rows };
    }
    throw new Error('JSON must be an array of objects or {headers, rows}.');
  }

  // ── Platform detection and normalization ────────────────────────────────────
  // Qualtrics CSV has 3 header rows: var names / question text / ImportIds.
  // After standard parse: headers=row0, rows[0]=question text, rows[1]=ImportIds, rows[2+]=data.
  function detectQualtrics(parsed) {
    if (parsed.rows.length < 2) return false;
    const importIdRow = parsed.rows[1];
    return importIdRow.some(function (v) { return /^\{"ImportId"/.test(String(v)); });
  }

  function stripQualtricsHeader(parsed) {
    return { headers: parsed.headers, rows: parsed.rows.slice(2) };
  }

  // Google Forms: first column is Timestamp, auto-marked date_time by detector.
  // No structural strip needed — detectAnalysisType handles it via header heuristic.

  // ── Excel loader (SheetJS, lazy from CDN) ───────────────────────────────────
  let _xlsxPromise = null;
  function loadXlsx() {
    if (window.XLSX) return Promise.resolve(window.XLSX);
    if (_xlsxPromise) return _xlsxPromise;
    _xlsxPromise = new Promise(function (resolve, reject) {
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
      s.onload = function () { resolve(window.XLSX); };
      s.onerror = function () { reject(new Error('Could not load the Excel parser.')); };
      document.head.appendChild(s);
    });
    return _xlsxPromise;
  }

  function parseXlsx(file) {
    return loadXlsx().then(function (XLSX) {
      return new Promise(function (resolve, reject) {
        const r = new FileReader();
        r.onload = function (e) {
          try {
            const wb    = XLSX.read(e.target.result, { type: 'array' });
            const sheet = wb.Sheets[wb.SheetNames[0]];
            const json  = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
            if (!json.length) { reject(new Error('The spreadsheet has no data.')); return; }
            const headers = json[0].map(function (h, i) {
              h = String(h == null ? '' : h).trim(); return h || ('Column ' + (i + 1));
            });
            const rows = json.slice(1)
              .map(function (arr) {
                return headers.map(function (h, i) { return arr[i] != null ? String(arr[i]).trim() : ''; });
              })
              .filter(function (r) { return r.some(function (c) { return c !== ''; }); });
            resolve({ headers: headers, rows: rows });
          } catch (err) { reject(err); }
        };
        r.onerror = function () { reject(new Error('Could not read the file.')); };
        r.readAsArrayBuffer(file);
      });
    });
  }

  // ── File routing ─────────────────────────────────────────────────────────────
  function parseFile(file) {
    const name = (file.name || '').toLowerCase();
    if (/\.xlsx?$/.test(name)) return parseXlsx(file);
    if (/\.qsf$/.test(name)) {
      return Promise.reject(new Error(
        'A .qsf file is a Qualtrics survey definition, not a data file. ' +
        'Export your responses as CSV or XLSX from Qualtrics, then upload that file.'
      ));
    }
    // CSV, TSV, JSON — read as text
    return file.text().then(function (text) {
      if (/\.json$/.test(name)) return parseJson(text);
      return parseDelimited(text);
    });
  }

  function formatLabel(name) {
    const n = (name || '').toLowerCase();
    if (/\.xlsx?$/.test(n)) return 'xlsx';
    if (/\.tsv$/.test(n))   return 'tsv';
    if (/\.json$/.test(n))  return 'json';
    return 'csv';
  }

  // ── Payload builders ─────────────────────────────────────────────────────────
  function buildColumnMeta(headers, types) {
    return headers.map(function (name, ci) {
      const at = types[ci] || 'open_ended';
      return {
        name:          name,
        type:          AT_TO_DSTYPE[at] || 'open',
        analysis_type: at,
        reverse:       false,
      };
    });
  }

  function buildCreatePayload(parsed, types, title, fmt) {
    const colMeta = buildColumnMeta(parsed.headers, types);
    const data    = parsed.rows.map(function (r) {
      return parsed.headers.map(function (h, i) { return r[i] != null ? r[i] : ''; });
    });
    return {
      title:           title || 'Uploaded data',
      source_format:   fmt || 'csv',
      column_meta:     colMeta,
      settings:        { likertPoints: 5, likertLow: 'Strongly disagree', likertHigh: 'Strongly agree' },
      data:            data,
    };
  }

  // ── Type selector HTML ───────────────────────────────────────────────────────
  function typeSelectHtml(ci, current) {
    const groups = {};
    ANALYSIS_TYPES.forEach(function (t) {
      if (!groups[t.group]) groups[t.group] = [];
      groups[t.group].push(t);
    });
    let html = '<select class="du-type ed-in" data-col="' + ci + '">';
    Object.keys(groups).forEach(function (g) {
      html += '<optgroup label="' + esc(g) + '">';
      groups[g].forEach(function (t) {
        html += '<option value="' + t.v + '"' + (t.v === current ? ' selected' : '') + '>' + esc(t.label) + '</option>';
      });
      html += '</optgroup>';
    });
    html += '</select>';
    return html;
  }

  // ── Modal scaffold ───────────────────────────────────────────────────────────
  function makeModal(titleText, subText) {
    const overlay = document.createElement('div');
    overlay.className = 'au-overlay';
    overlay.innerHTML =
      '<div class="au-panel" role="dialog" aria-label="' + esc(titleText) + '">'
      + '<button class="au-close" aria-label="Close">&times;</button>'
      + '<h2 class="au-title">' + esc(titleText) + '</h2>'
      + '<p class="au-sub">' + esc(subText) + '</p>'
      + '<div class="au-stage" id="duStage"></div>'
      + '</div>';
    document.body.appendChild(overlay);
    const close = function () { overlay.remove(); };
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    overlay.querySelector('.au-close').addEventListener('click', close);
    return { overlay: overlay, stage: overlay.querySelector('#duStage'), close: close };
  }

  // ── Main upload flow ─────────────────────────────────────────────────────────
  function open(ctx) {
    ctx = ctx || {};
    const modal = makeModal('Bring in your data', 'Choose a file to upload. No data is required to start exploring.');
    const stage = modal.stage;
    let fileName   = ctx.title || 'Uploaded data';
    let sourceFormat = 'csv';

    function handleFile(f) {
      if (!f) return;
      fileName     = f.name.replace(/\.[^.]+$/, '');
      sourceFormat = formatLabel(f.name);
      stage.innerHTML = '<p class="au-sample" style="padding:16px 0">Reading file…</p>';
      parseFile(f)
        .then(function (parsed) {
          // Qualtrics detection and header strip.
          if (detectQualtrics(parsed)) parsed = stripQualtricsHeader(parsed);
          toConfirm(parsed);
        })
        .catch(function (err) {
          stage.innerHTML = '';
          showDrop();
          alert((err && err.message) ? err.message : 'Could not read that file. Check the format and try again.');
        });
    }

    function showDrop() {
      stage.innerHTML =
        '<div class="au-drop" id="duDrop">'
        + '<div class="au-drop-ico">&#8681;</div>'
        + '<div class="au-drop-h">Drop your data file here</div>'
        + '<div class="au-drop-sub">CSV · TSV · Excel (.xlsx) · JSON &nbsp;|&nbsp; up to 50 MB</div>'
        + '<div class="au-drop-sub" style="margin-top:4px;font-size:12px;color:#888">Also accepts Qualtrics and Google Forms exports</div>'
        + '<div class="au-drop-actions">'
        + '<button class="au-btn primary" id="duChoose">Choose file</button>'
        + '</div>'
        + '<input type="file" id="duFile" accept=".csv,.tsv,.txt,.xlsx,.xls,.json,.qsf" hidden>'
        + '</div>';
      const drop      = stage.querySelector('#duDrop');
      const fileInput = stage.querySelector('#duFile');
      stage.querySelector('#duChoose').addEventListener('click', function () { fileInput.click(); });
      fileInput.addEventListener('change', function () { handleFile(fileInput.files[0]); });
      ['dragover', 'dragenter'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
      });
      drop.addEventListener('drop', function (e) { handleFile(e.dataTransfer.files[0]); });
    }

    function toConfirm(parsed) {
      if (!parsed.headers.length || !parsed.rows.length) {
        alert('No rows found in that file. Check that it contains data rows below the header.');
        showDrop();
        return;
      }
      const types = parsed.headers.map(function (h, ci) {
        return detectAnalysisType(parsed.rows.map(function (r) { return r[ci]; }), h, ci);
      });
      const sample = function (ci) {
        return parsed.rows.slice(0, 3)
          .map(function (r) { return esc(String(r[ci] == null ? '' : r[ci])).slice(0, 22); })
          .filter(Boolean).join(', ');
      };
      stage.innerHTML =
        '<p class="au-confirm-h">Review column types. '
        + parsed.rows.length + ' rows &middot; ' + parsed.headers.length + ' columns.</p>'
        + '<div class="au-table-wrap"><table class="au-table">'
        + '<thead><tr><th>Column</th><th>Type</th><th>Sample values</th></tr></thead>'
        + '<tbody>'
        + parsed.headers.map(function (h, ci) {
          return '<tr><td class="au-col">' + esc(h) + '</td>'
            + '<td>' + typeSelectHtml(ci, types[ci]) + '</td>'
            + '<td class="au-sample">' + sample(ci) + '</td></tr>';
        }).join('')
        + '</tbody></table></div>'
        + '<div class="au-confirm-actions">'
        + '<button class="au-btn" id="duBack">&larr; Back</button>'
        + '<button class="au-btn primary" id="duUse">Use this data &rarr;</button>'
        + '</div>'
        + '<div class="au-msg" id="duMsg"></div>';

      stage.querySelector('#duBack').addEventListener('click', showDrop);
      stage.querySelector('#duUse').addEventListener('click', function () {
        const sels   = stage.querySelectorAll('.du-type');
        const chosen = [];
        sels.forEach(function (s) { chosen[+s.getAttribute('data-col')] = s.value; });
        // Keep at least one non-ignored column.
        if (chosen.every(function (t) { return t === 'structural'; })) {
          stage.querySelector('#duMsg').textContent = 'Every column is set to Ignore. Keep at least one.';
          return;
        }
        const payload = buildCreatePayload(parsed, chosen, fileName, sourceFormat);
        persist(payload, stage.querySelector('#duUse'), stage.querySelector('#duMsg'));
      });
    }

    function persist(payload, btn, msg) {
      btn.disabled = true; btn.textContent = 'Saving…';
      fetch('/api/datasets/create.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          const ds       = d && (d.dataset || d);
          const datasetId = ds && ds.id;
          if (!datasetId) throw new Error('Dataset create failed.');
          return attachAndOpen(datasetId, payload.title);
        })
        .catch(function () {
          btn.disabled = false; btn.textContent = 'Use this data →';
          if (msg) msg.textContent = 'Could not save your data. Please try again.';
        });
    }

    function attachAndOpen(datasetId, title) {
      function done(pid) { modal.close(); if (ctx.onLoaded) ctx.onLoaded(null, pid); }

      if (ctx.projectId) {
        const linkUrl = ctx.projectType === 'mm'
          ? '/api/mm/link-dataset.php'
          : '/api/analysis/link-dataset.php';
        return fetch(linkUrl, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: ctx.projectId, dataset_id: datasetId }),
        })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (!d || !d.ok) throw new Error('link failed'); done(ctx.projectId); });
      }

      // No existing project — create one.
      return fetch('/api/analysis/projects.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ kind: ctx.kind || 'descriptive', title: title, dataset_id: datasetId }),
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.ok || !d.project) throw new Error('project create failed');
          done(d.project.id);
        });
    }

    showDrop();
  }

  // ── Open saved dataset ───────────────────────────────────────────────────────
  function openSaved(ctx) {
    ctx = ctx || {};
    const modal = makeModal('Open a saved dataset', 'Your datasets from any ReliCheck studio.');
    const stage = modal.stage;
    stage.innerHTML = '<p class="au-sample" style="padding:10px">Loading…</p>';

    fetch('/api/datasets/list.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        const items = (d && Array.isArray(d.datasets)) ? d.datasets : [];
        if (!items.length) {
          stage.innerHTML = '<p class="au-sample" style="padding:10px">No saved datasets yet. Upload data to start.</p>';
          return;
        }
        stage.innerHTML = items.map(function (s) {
          return '<button class="au-row" data-id="' + s.id + '" data-title="' + esc(s.title || 'Untitled') + '">'
            + '<span class="au-row-title">' + esc(s.title || 'Untitled dataset') + '</span>'
            + '<span class="au-row-meta">' + (s.row_count || 0) + ' rows · ' + (s.column_count || 0)
            + ' columns · ' + esc(String(s.updated_at || '').slice(0, 10)) + '</span></button>';
        }).join('');

        stage.querySelectorAll('.au-row').forEach(function (b) {
          b.addEventListener('click', function () {
            const datasetId = +b.getAttribute('data-id');
            const title     = b.getAttribute('data-title');
            b.disabled = true;
            b.querySelector('.au-row-meta').textContent = 'Opening…';

            function done(pid) { modal.close(); if (ctx.onLoaded) ctx.onLoaded(null, pid); }

            let req;
            if (ctx.projectId) {
              const linkUrl = ctx.projectType === 'mm'
                ? '/api/mm/link-dataset.php'
                : '/api/analysis/link-dataset.php';
              req = fetch(linkUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ project_id: ctx.projectId, dataset_id: datasetId }),
              }).then(function (r) { return r.json(); }).then(function (d) { if (!d || !d.ok) throw 0; done(ctx.projectId); });
            } else {
              req = fetch('/api/analysis/projects.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ kind: ctx.kind || 'descriptive', title: title, dataset_id: datasetId }),
              }).then(function (r) { return r.json(); }).then(function (d) { if (!d || !d.ok || !d.project) throw 0; done(d.project.id); });
            }
            req.catch(function () {
              b.disabled = false;
              b.querySelector('.au-row-meta').textContent = 'Could not open — try again.';
            });
          });
        });
      })
      .catch(function () {
        stage.innerHTML = '<p class="au-msg" style="padding:10px">Could not load saved datasets.</p>';
      });
  }

  window.DatasetUpload = { open: open, openSaved: openSaved };
})();
