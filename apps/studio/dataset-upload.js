// ReliCheck unified data upload widget.
// Replaces the per-studio upload mechanisms (analysis-upload.js, evidence-intake.js).
//
// Supported formats:
//   Tier 1 (in-browser): CSV, TSV, XLSX, JSON
//   Tier 2 (platform-aware): Qualtrics CSV/XLSX, Google Forms CSV
//   No paste option.
//
// API:
//   DatasetUpload.open(ctx)          — full modal: title + description + file picker
//   DatasetUpload.embed(el, ctx)     — inline into an existing container (no modal)
//   DatasetUpload.openSaved(ctx)     — pick from saved datasets (modal)
//
// ctx = { kind, projectId, projectType, title, onLoaded(null, projectId) }
//   kind        — 'descriptive' | 'inferential' | 'mm' | (future slug)
//   projectId   — existing project to link into (0/null = create new)
//   projectType — 'analysis' | 'mm' | 'rssi' | 'survey' | 'qual'
//   title       — default dataset title (pre-fills title field)
//   onLoaded    — called as onLoaded(null, projectId) when ready
(function () {
  'use strict';

  // ── Inject widget CSS once ───────────────────────────────────────────────────
  if (!document.getElementById('du-styles')) {
    const s = document.createElement('style');
    s.id = 'du-styles';
    s.textContent = [
      /* au-* base — widget is self-contained; these mirror analysis-studio.css so the
         modal renders correctly on pages that do not load that stylesheet (MM, RSSI, etc.) */
      '.au-overlay{position:fixed;inset:0;background:rgba(15,23,42,.42);z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px}',
      '.au-panel{background:#fff;border-radius:18px;width:100%;max-width:640px;max-height:84vh;overflow:auto;box-shadow:0 24px 70px rgba(15,23,42,.32);padding:26px 28px;position:relative}',
      '.au-close{position:absolute;top:16px;right:18px;background:none;border:none;font-size:24px;line-height:1;color:#8a8f98;cursor:pointer}',
      '.au-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:10px;border:1px solid var(--line,#e6e8ec);background:#fff;color:var(--ink,#15171a);font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer}',
      '.au-btn.primary{background:var(--acc,#c2271b);border-color:var(--acc,#c2271b);color:#fff}',
      '.au-btn:disabled{opacity:.6;cursor:default}',
      '.au-msg{margin-top:10px;font-size:13px;color:#c2492f}',
      /* Modal panel overrides */
      '.du-panel{max-width:720px!important;padding:0!important;display:flex;flex-direction:column;max-height:92vh}',
      /* Header */
      '.du-header{display:flex;align-items:center;gap:14px;padding:20px 24px 16px;border-bottom:1px solid #e5e8ef;flex-shrink:0}',
      '.du-header-icon{flex-shrink:0;width:40px;height:40px}',
      '.du-header-title{font-size:18px;font-weight:700;color:#1c2238;line-height:1.2}',
      '.du-header-sub{font-size:13px;color:#7b8fad;margin-top:3px}',
      '.du-header .au-close{margin-left:auto;flex-shrink:0}',
      /* Body */
      '.du-body{padding:20px 24px;overflow-y:auto;flex:1}',
      /* Title + Description fields side by side */
      '.du-fields{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}',
      '.du-label{display:block;font-size:13px;font-weight:600;color:#3a4460;margin-bottom:5px}',
      '.du-req{color:#c2492f}',
      '.du-opt{font-weight:400;color:#9ba8be;font-size:12px;margin-left:4px}',
      '.du-input{width:100%;padding:8px 10px;border:1px solid #d4d9e4;border-radius:8px;font-size:14px;box-sizing:border-box;font-family:inherit}',
      '.du-input:focus{outline:none;border-color:#5b6fad;box-shadow:0 0 0 3px rgba(91,111,173,.12)}',
      '.du-textarea{resize:vertical;min-height:74px}',
      '.du-hint{font-size:12px;color:#9ba8be;margin-top:4px}',
      '.du-section-label{font-size:13px;font-weight:600;color:#3a4460;margin-bottom:10px}',
      /* Drop zone */
      '.du-dropzone{border:2px dashed #c8d0df;border-radius:12px;padding:28px 20px;text-align:center;transition:border-color .15s,background .15s;cursor:default}',
      '.du-dropzone.over{border-color:#5b6fad;background:#f0f2ff}',
      '.du-drop-icon{width:36px;height:36px;color:#7b8fad;margin:0 auto 10px;display:block}',
      '.du-drop-h{font-weight:600;font-size:15px;color:#1c2238;margin-bottom:4px}',
      '.du-drop-or{color:#9ba8be;font-size:13px;margin-bottom:10px}',
      '.du-file-types{color:#9ba8be;font-size:12px;margin-top:10px;line-height:1.5}',
      /* Chosen file row */
      '.du-chosen{margin-top:10px;font-size:13px;color:#3a4460;display:flex;align-items:center;gap:6px}',
      '.du-change{background:none;border:none;color:#5b6fad;cursor:pointer;font-size:13px;text-decoration:underline;padding:0;margin-left:4px}',
      /* Security note */
      '.du-security{color:#9ba8be;font-size:12px;margin-top:14px;display:flex;align-items:center;gap:5px}',
      /* Footer */
      '.du-footer{padding:14px 24px;border-top:1px solid #e5e8ef;display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-shrink:0}',
      /* Embed (inline, no modal) */
      '.du-embed-actions{margin-top:16px}',
      '.du-embed-submit{width:100%}',
      /* openSaved modal */
      '.au-title{font-size:20px;font-weight:800;color:#1c2238;margin:0 0 4px}',
      '.au-sub{font-size:13px;color:#7b8fad;margin:0 0 14px}',
      '.au-stage{display:flex;flex-direction:column;gap:8px}',
      '.au-row{display:flex;flex-direction:column;gap:4px;width:100%;text-align:left;padding:12px 16px;border:1px solid #e5e8ef;border-radius:10px;background:#fff;cursor:pointer;font-family:inherit;transition:border-color .12s,box-shadow .12s}',
      '.au-row:hover{border-color:#5b6fad;box-shadow:0 0 0 3px rgba(91,111,173,.1)}',
      '.au-row:disabled{opacity:.6;cursor:default}',
      '.au-row-title{font-size:14px;font-weight:700;color:#1c2238}',
      '.au-row-meta{font-size:12px;color:#7b8fad}',
      '.au-sample{color:#9ba8be;font-size:13px;margin:0}',
    ].join('');
    document.head.appendChild(s);
  }

  // ── Canonical type definitions ──────────────────────────────────────────────
  const ANALYSIS_TYPES = [
    { v: 'likert_item',         label: 'Likert / Scale item',    group: 'Quantitative' },
    { v: 'demographic_numeric', label: 'Numeric',                group: 'Quantitative' },
    { v: 'scale_score',         label: 'Scale score (computed)', group: 'Quantitative' },
    { v: 'demographic_nominal', label: 'Categorical',            group: 'Categorical' },
    { v: 'demographic_ordinal', label: 'Categorical (ordered)',  group: 'Categorical' },
    { v: 'binary',              label: 'Yes / No or Binary',     group: 'Categorical' },
    { v: 'open_ended',          label: 'Open-ended text',        group: 'Text' },
    { v: 'narrative',           label: 'Narrative / long text',  group: 'Text' },
    { v: 'identifier',          label: 'Identifier / ID',        group: 'Other' },
    { v: 'date_time',           label: 'Date / Time',            group: 'Other' },
    { v: 'metadata',            label: 'Metadata',               group: 'Other' },
    { v: 'structural',          label: 'Ignore this column',     group: 'Other' },
  ];

  // Legacy type sent to create.php alongside analysis_type for backward compat.
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
    if (/^(respondent_?id|student_?id|case_?id|response_?id|participant_?id|uuid|email|phone)$/i.test(h)) return 'identifier';
    if (/^(timestamp|submitted_?at|completed_?at|date_?submitted|recorded_?date)$/i.test(h)) return 'date_time';

    const nm    = values.filter(function (v) { return v != null && String(v).trim() !== ''; });
    if (!nm.length) return 'structural';

    const distinct = new Set(nm.map(String));
    const total    = nm.length;
    const nums     = nm.map(function (v) { const x = parseFloat(v); return isFinite(x) ? x : null; }).filter(function (x) { return x !== null; });
    const numFrac  = nums.length / total;

    if (numFrac >= 0.85) {
      const allInt = nums.every(function (x) { return Number.isInteger(x); });
      const lo = Math.min.apply(null, nums), hi = Math.max.apply(null, nums);
      if (allInt && lo >= 0 && hi <= 11 && distinct.size <= 11) return 'likert_item';
      if (allInt && distinct.size === 2 && lo === 0 && hi === 1)  return 'binary';
      return 'demographic_numeric';
    }

    const avgLen   = nm.reduce(function (s, v) { return s + String(v).length; }, 0) / total;
    const dateFrac = nm.filter(function (v) {
      return /\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/]\d{1,2}[-/]\d{2,4}/.test(String(v));
    }).length / total;
    if (dateFrac >= 0.7) return 'date_time';
    if (distinct.size === total && colIdx === 0) return 'identifier';
    if (avgLen > 40 || distinct.size > Math.max(20, total * 0.6)) return 'open_ended';
    if (distinct.size === 2) return 'binary';
    if (distinct.size <= 20 && avgLen <= 40) return 'demographic_nominal';
    return 'open_ended';
  }

  // ── Parsers ─────────────────────────────────────────────────────────────────
  function parseDelimited(text) {
    text = String(text || '').replace(/\r\n?/g, '\n').replace(/\n+$/, '');
    if (!text.trim()) return { headers: [], rows: [] };
    const firstLine = text.split('\n', 1)[0];
    const delim = firstLine.indexOf('\t') >= 0 ? '\t' : ',';
    const lines = splitLines(text, delim);
    if (!lines.length) return { headers: [], rows: [] };
    const headers = lines[0].map(function (h, i) { h = String(h).trim(); return h || ('Column ' + (i + 1)); });
    const rows    = lines.slice(1).filter(function (r) { return r.some(function (c) { return String(c).trim() !== ''; }); });
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
      else if (c === '\n')  { row.push(field); out.push(row); row = []; field = ''; }
      else field += c;
    }
    row.push(field); out.push(row);
    return out;
  }

  function parseJson(text) {
    const data = JSON.parse(text);
    if (Array.isArray(data) && data.length > 0 && typeof data[0] === 'object' && !Array.isArray(data[0])) {
      const headers = Object.keys(data[0]);
      const rows    = data.map(function (obj) { return headers.map(function (h) { return obj[h] != null ? String(obj[h]) : ''; }); });
      return { headers: headers, rows: rows };
    }
    if (data && Array.isArray(data.headers) && Array.isArray(data.rows)) {
      return { headers: data.headers.map(String), rows: data.rows };
    }
    throw new Error('JSON must be an array of objects or {headers, rows}.');
  }

  // ── Platform detection ───────────────────────────────────────────────────────
  function detectQualtrics(parsed) {
    if (parsed.rows.length < 2) return false;
    return parsed.rows[1].some(function (v) { return /^\{"ImportId"/.test(String(v)); });
  }

  function stripQualtricsHeader(parsed) {
    // Qualtrics has 3 header rows: question text (used as headers), ImportId row, Values row.
    return { headers: parsed.headers, rows: parsed.rows.slice(2) };
  }

  // SurveyMonkey exports have 2 header rows: row 0 = question text, row 1 = sub-question / option.
  // The real data starts at row 2. To avoid false positives on CSVs that just happen to have
  // "Respondent ID" or "Start Date" columns, we require at least one row-1 value that matches
  // a known SurveyMonkey sub-question label ("Response", "Open-Ended Response", etc.).
  function detectSurveyMonkey(parsed) {
    if (parsed.rows.length < 2) return false;
    var hasSmMeta = parsed.headers.some(function (h) {
      return /respondent.?id|start.?date|end.?date|ip.?address|email.?address|first.?name|last.?name/i.test(String(h));
    });
    if (!hasSmMeta) return false;
    // The sub-question row must contain at least one recognisable SurveyMonkey label.
    var subRow = parsed.rows[0] || [];
    return subRow.some(function (v) {
      return /^(response|open.?ended.?response|open\s*ended|other.*please.*specify|please\s*specify|column\s*\d+)$/i.test(String(v).trim());
    });
  }

  function stripSurveyMonkeyHeader(parsed) {
    // Merge row 0 (question) + row 1 (sub-question) into combined headers, skip both as data rows.
    var subRow = parsed.rows[0] || [];
    var combined = parsed.headers.map(function (h, i) {
      var sub = String(subRow[i] || '').trim();
      return sub && sub !== h ? h + ' - ' + sub : h;
    });
    return { headers: combined, rows: parsed.rows.slice(2) };
  }

  // ── SheetJS (lazy) ───────────────────────────────────────────────────────────
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
              .map(function (arr) { return headers.map(function (h, i) { return arr[i] != null ? String(arr[i]).trim() : ''; }); })
              .filter(function (r) { return r.some(function (c) { return c !== ''; }); });
            resolve({ headers: headers, rows: rows });
          } catch (err) { reject(err); }
        };
        r.onerror = function () { reject(new Error('Could not read the file.')); };
        r.readAsArrayBuffer(file);
      });
    });
  }

  function parseFile(file) {
    const name = (file.name || '').toLowerCase();
    if (/\.xlsx?$/.test(name)) return parseXlsx(file);
    if (/\.qsf$/.test(name)) {
      return Promise.reject(new Error(
        'A .qsf file is a Qualtrics survey definition, not a data file. ' +
        'Export your responses as CSV or XLSX from Qualtrics instead.'
      ));
    }
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

  // ── Build create.php payload ─────────────────────────────────────────────────
  function buildPayload(parsed, title, fmt) {
    const types = parsed.headers.map(function (h, ci) {
      return detectAnalysisType(parsed.rows.map(function (r) { return r[ci]; }), h, ci);
    });
    const columnMeta = parsed.headers.map(function (name, ci) {
      const at = types[ci] || 'open_ended';
      return { name: name, type: AT_TO_DSTYPE[at] || 'open', analysis_type: at, reverse: false };
    });
    const data = parsed.rows.map(function (r) {
      return parsed.headers.map(function (h, i) { return r[i] != null ? r[i] : ''; });
    });
    return {
      title:          title || 'Uploaded data',
      source_format:  fmt || 'csv',
      column_meta:    columnMeta,
      settings:       { likertPoints: 5, likertLow: 'Strongly disagree', likertHigh: 'Strongly agree' },
      data:           data,
    };
  }

  // ── Persist: create dataset + attach to project ───────────────────────────────
  function persistAndAttach(parsed, title, fmt, ctx) {
    return new Promise(function (resolve, reject) {
      const payload = buildPayload(parsed, title, fmt);
      if (ctx.description) payload.settings.description = ctx.description;

      fetch('/api/datasets/create.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          const ds        = d && (d.dataset || d);
          const datasetId = ds && ds.id;
          if (!datasetId) throw new Error('Dataset create failed.');
          return attach(datasetId, title, ctx);
        })
        .then(resolve)
        .catch(reject);
    });
  }

  function attach(datasetId, title, ctx) {
    // Standalone apps that return datasetId directly (no project record).
    if (ctx.projectType === 'rssi') {
      return Promise.resolve(datasetId);
    }
    // Analysis Studio — create a project if none exists, then link the dataset.
    if (ctx.projectType === 'analysis') {
      var apid = ctx.projectId ? +ctx.projectId : 0;
      var doAnalysisLink = function (projectId) {
        return fetch('/api/analysis/link-dataset.php', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: projectId, dataset_id: datasetId }),
        })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (!d || !d.ok) throw new Error('Link failed.'); return projectId; });
      };
      if (apid > 0) return doAnalysisLink(apid);
      // No project yet — create one, link the dataset, then open the workspace.
      var isInferential = ctx.kind === 'inferential';
      var defaultTitle = isInferential ? 'Inferential Analysis' : 'Descriptive Analysis';
      return fetch('/api/analysis/create-project.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: title || defaultTitle, kind: ctx.kind || 'descriptive' }),
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.ok || !d.project_id) throw new Error('Could not create project.');
          return doAnalysisLink(+d.project_id).then(function (pid) {
            var wsUrl = isInferential
              ? '/inferential-statistics-workspaceV4.php?project_id='
              : '/descriptive-analysis-workspaceV4.php?project_id=';
            window.location.replace(wsUrl + pid);
            return pid;
          });
        });
    }
    // Survey dev projects — link dataset to survey_projects.
    // Mirrors MM/qual: creates a project if none exists, then links.
    if (ctx.projectType === 'survey') {
      var spid = ctx.projectId ? +ctx.projectId : 0;
      var doSurveyLink = function (projectId) {
        return fetch('/api/dev/link-dataset.php', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: projectId, dataset_id: datasetId }),
        })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (!d || !d.ok) throw new Error('Link failed.'); return projectId; });
      };
      if (spid > 0) return doSurveyLink(spid);
      // source:'upload' marks this as an analyze-journey project (uploaded existing
      // response data), so the Survey Development System shows the short
      // Upload -> Variable Map -> RSSI rail instead of the full build pipeline.
      return fetch('/api/dev/project-create.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: title || 'Uploaded survey data', source: 'upload' }),
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.ok || !d.project || !d.project.id) throw new Error('Could not create project.');
          return doSurveyLink(+d.project.id);
        });
    }
    // Qual Studio — create a project if none exists, then link the dataset.
    if (ctx.projectType === 'qual') {
      var pid = ctx.projectId ? +ctx.projectId : 0;
      var doLink = function (projectId) {
        return fetch('/api/qual/link-dataset.php', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ project_id: projectId, dataset_id: datasetId }),
        })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (!d || !d.ok) throw new Error('Link failed.'); return projectId; });
      };
      if (pid > 0) return doLink(pid);
      return fetch('/api/qual/create-project.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: title || 'Qualitative Analysis', analysis_approach: 'thematic' }),
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.ok || !d.project_id) throw new Error('Could not create project.');
          return doLink(+d.project_id);
        });
    }
    // MM with an existing project — link dataset to it.
    if (ctx.projectId && ctx.projectType === 'mm') {
      return fetch('/api/mm/link-dataset.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ project_id: ctx.projectId, dataset_id: datasetId }),
      })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d || !d.ok) throw new Error('Link failed.'); return ctx.projectId; });
    }
    // MM with no pre-existing project — create the project then link.
    if (ctx.projectType === 'mm') {
      return fetch('/api/mm/projects.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: title, pathway: 'comments_only', notes: ctx.description || '' }),
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.ok || !d.project) throw new Error('Could not create MM project.');
          const pid = d.project.id;
          return fetch('/api/mm/link-dataset.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ project_id: pid, dataset_id: datasetId }),
          })
            .then(function (r2) { return r2.json(); })
            .then(function (d2) { if (!d2 || !d2.ok) throw new Error('Link failed.'); return pid; });
        });
    }
    return Promise.reject(new Error(
      'Unknown projectType "' + (ctx.projectType || '') + '". Supported: rssi, survey, qual, mm, analysis.'
    ));
  }

  // ── Drop zone (shared between open() and embed()) ────────────────────────────
  function renderDropZone(container, opts) {
    // opts: { onFile(file), accept, hint }
    container.innerHTML =
      '<div class="du-dropzone" id="duDrop">'
      + '<svg class="du-drop-icon" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
      + '<rect x="8" y="6" width="32" height="36" rx="3"/><polyline points="24 14 24 30"/><polyline points="17 23 24 30 31 23"/>'
      + '</svg>'
      + '<div class="du-drop-h">Drag and drop your file here</div>'
      + '<div class="du-drop-or">or</div>'
      + '<button type="button" class="au-btn primary" id="duBrowse">Browse files</button>'
      + '<input type="file" id="duFileIn" accept="' + (opts.accept || '.csv,.tsv,.txt,.xlsx,.xls,.json,.qsf') + '" hidden>'
      + '</div>'
      + '<div class="du-file-types">CSV &middot; TSV &middot; Excel (.xlsx) &middot; JSON &nbsp;&nbsp;|&nbsp;&nbsp; Also: Qualtrics, Google Forms exports</div>';

    const drop      = container.querySelector('#duDrop');
    const fileInput = container.querySelector('#duFileIn');

    container.querySelector('#duBrowse').addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
      if (fileInput.files[0]) opts.onFile(fileInput.files[0]);
    });
    ['dragover', 'dragenter'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function (e) {
      if (e.dataTransfer.files[0]) opts.onFile(e.dataTransfer.files[0]);
    });
  }

  // ── open(ctx) — full modal with title + description + file picker ─────────────
  function open(ctx) {
    ctx = ctx || {};
    const overlay = document.createElement('div');
    overlay.className = 'au-overlay';
    overlay.innerHTML =
      '<div class="au-panel du-panel" role="dialog" aria-label="Upload Data">'
      + '<div class="du-header">'
      + '<svg class="du-header-icon" viewBox="0 0 40 40" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
      + '<circle cx="20" cy="20" r="18"/><polyline points="20 12 20 24"/><polyline points="14 18 20 12 26 18"/>'
      + '</svg>'
      + '<div><div class="du-header-title">Upload Data</div><div class="du-header-sub">Upload a data file to analyze in ReliCheck.</div></div>'
      + '<button class="au-close" aria-label="Close">&times;</button>'
      + '</div>'
      + '<div class="du-body">'
      + '<div class="du-fields">'
      + '<div class="du-field">'
      + '<label class="du-label" for="duTitle">Title <span class="du-req">*</span></label>'
      + '<input id="duTitle" class="du-input" type="text" maxlength="200" placeholder="e.g., 2026 Team Survey Data" value="' + esc(ctx.title || '') + '">'
      + '<div class="du-hint">A short, descriptive name for your data file.</div>'
      + '</div>'
      + '<div class="du-field">'
      + '<label class="du-label" for="duDesc">Description <span class="du-opt">(optional)</span></label>'
      + '<textarea id="duDesc" class="du-input du-textarea" rows="3" maxlength="1000" placeholder="Add any notes about this dataset, such as source, purpose, or details."></textarea>'
      + '</div>'
      + '</div>'
      + '<div class="du-section-label">Select file <span class="du-req">*</span></div>'
      + '<div id="duDropWrap"></div>'
      + '<div class="du-chosen" id="duChosen" hidden></div>'
      + '<div class="du-security">'
      + '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M8 1.5L2 4v4c0 3 2.5 5.5 6 6 3.5-.5 6-3 6-6V4L8 1.5z"/></svg>'
      + ' Your data is secure.'
      + '</div>'
      + '</div>'
      + '<div class="du-footer">'
      + '<button type="button" class="au-btn" id="duCancel">Cancel</button>'
      + '<button type="button" class="au-btn primary" id="duUpload" disabled>&#8679; Upload Data</button>'
      + '</div>'
      + '</div>';
    document.body.appendChild(overlay);

    const close     = function () { overlay.remove(); };
    const titleEl   = overlay.querySelector('#duTitle');
    const descEl    = overlay.querySelector('#duDesc');
    const uploadBtn = overlay.querySelector('#duUpload');
    const chosenEl  = overlay.querySelector('#duChosen');
    const dropWrap  = overlay.querySelector('#duDropWrap');

    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    overlay.querySelector('.au-close').addEventListener('click', close);
    overlay.querySelector('#duCancel').addEventListener('click', close);

    let chosenFile = null;
    let chosenFormat = 'csv';

    function onFile(f) {
      chosenFile   = f;
      chosenFormat = formatLabel(f.name);
      if (!titleEl.value.trim()) {
        titleEl.value = f.name.replace(/\.[^.]+$/, '').replace(/[-_]/g, ' ');
      }
      chosenEl.hidden = false;
      chosenEl.innerHTML =
        '<svg viewBox="0 0 16 16" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" width="14" height="14"><polyline points="2 8 6 12 14 4"/></svg>'
        + ' <strong>' + esc(f.name) + '</strong>'
        + ' <button type="button" class="du-change" id="duChange">Change</button>';
      overlay.querySelector('#duChange').addEventListener('click', function () {
        chosenFile = null; chosenEl.hidden = true; uploadBtn.disabled = true;
        dropWrap.innerHTML = '';
        renderDropZone(dropWrap, { onFile: onFile });
      });
      uploadBtn.disabled = false;
    }
    renderDropZone(dropWrap, { onFile: onFile });

    uploadBtn.addEventListener('click', function () {
      const title = titleEl.value.trim();
      if (!title)       { titleEl.focus(); return; }
      if (!chosenFile)  { return; }
      uploadBtn.disabled = true; uploadBtn.textContent = 'Uploading…';

      parseFile(chosenFile)
        .then(function (parsed) {
          if (detectQualtrics(parsed)) parsed = stripQualtricsHeader(parsed);
          else if (detectSurveyMonkey(parsed)) parsed = stripSurveyMonkeyHeader(parsed);
          if (!parsed.headers.length || !parsed.rows.length) throw new Error('No data rows found. Check the file format.');
          const ctxWithDesc = Object.assign({}, ctx, { description: descEl.value.trim() });
          return persistAndAttach(parsed, title, chosenFormat, ctxWithDesc);
        })
        .then(function (projectId) {
          close();
          // Analysis attach() navigates to the workspace itself; skip the studio's
          // own onLoaded so it does not double-navigate.
          if (ctx.projectType === 'analysis') return;
          if (ctx.onLoaded) ctx.onLoaded(null, projectId);
        })
        .catch(function (err) {
          uploadBtn.disabled = false; uploadBtn.textContent = '⬆ Upload Data';
          alert((err && err.message) ? err.message : 'Upload failed. Please try again.');
        });
    });
  }

  // ── embed(container, ctx) — inline upload (no modal, for wizard use) ──────────
  // Renders just the drop zone + upload button directly into `container`.
  // Calls ctx.onLoaded(null, projectId) on success.
  function embed(container, ctx) {
    ctx = ctx || {};
    if (typeof container === 'string') container = document.querySelector(container);
    if (!container) return;

    container.innerHTML =
      '<div class="du-embed">'
      + '<div id="duEmbedDrop"></div>'
      + '<div class="du-chosen" id="duEmbedChosen" hidden></div>'
      + '<div class="du-embed-actions">'
      + '<button type="button" class="au-btn primary du-embed-submit" id="duEmbedSubmit" disabled>Upload Data &rarr;</button>'
      + '</div>'
      + '<div class="au-msg" id="duEmbedMsg"></div>'
      + '</div>';

    const chosenEl  = container.querySelector('#duEmbedChosen');
    const submitBtn = container.querySelector('#duEmbedSubmit');
    const msgEl     = container.querySelector('#duEmbedMsg');
    const dropWrap  = container.querySelector('#duEmbedDrop');

    let chosenFile   = null;
    let chosenFormat = 'csv';

    function wireDropZone() {
      renderDropZone(dropWrap, {
        onFile: function (f) {
          chosenFile   = f;
          chosenFormat = formatLabel(f.name);
          chosenEl.hidden = false;
          chosenEl.innerHTML =
            '<svg viewBox="0 0 16 16" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" width="14" height="14"><polyline points="2 8 6 12 14 4"/></svg>'
            + ' <strong>' + esc(f.name) + '</strong>'
            + ' <button type="button" class="du-change" id="duEmbedChange">Change</button>';
          container.querySelector('#duEmbedChange').addEventListener('click', function () {
            chosenFile = null; chosenEl.hidden = true; submitBtn.disabled = true;
            dropWrap.innerHTML = '';
            wireDropZone();
          });
          submitBtn.disabled = false;
        },
      });
    }
    wireDropZone();

    submitBtn.addEventListener('click', function () {
      if (!chosenFile) return;
      submitBtn.disabled = true; submitBtn.textContent = 'Uploading…'; msgEl.textContent = '';

      parseFile(chosenFile)
        .then(function (parsed) {
          if (detectQualtrics(parsed)) parsed = stripQualtricsHeader(parsed);
          else if (detectSurveyMonkey(parsed)) parsed = stripSurveyMonkeyHeader(parsed);
          if (!parsed.headers.length || !parsed.rows.length) throw new Error('No data rows found.');
          const title = chosenFile.name.replace(/\.[^.]+$/, '');
          return persistAndAttach(parsed, title, chosenFormat, ctx);
        })
        .then(function (projectId) {
          if (ctx.onLoaded) ctx.onLoaded(null, projectId);
        })
        .catch(function (err) {
          submitBtn.disabled = false; submitBtn.textContent = 'Upload Data →';
          msgEl.textContent = (err && err.message) ? err.message : 'Upload failed. Try again.';
        });
    });
  }

  // ── openSaved(ctx) — pick from saved datasets ─────────────────────────────────
  function openSaved(ctx) {
    ctx = ctx || {};
    const overlay = document.createElement('div');
    overlay.className = 'au-overlay';
    overlay.innerHTML =
      '<div class="au-panel" role="dialog" aria-label="Open a saved dataset">'
      + '<button class="au-close" aria-label="Close">&times;</button>'
      + '<h2 class="au-title">Open a saved dataset</h2>'
      + '<p class="au-sub">Your datasets from any ReliCheck studio.</p>'
      + '<div class="au-stage" id="duSaved"><p class="au-sample" style="padding:10px">Loading…</p></div>'
      + '</div>';
    document.body.appendChild(overlay);
    const close = function () { overlay.remove(); };
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    overlay.querySelector('.au-close').addEventListener('click', close);
    const list = overlay.querySelector('#duSaved');

    fetch('/api/datasets/list.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        const items = (d && Array.isArray(d.datasets)) ? d.datasets : [];
        if (!items.length) {
          list.innerHTML = '<p class="au-sample" style="padding:10px">No saved datasets yet.</p>';
          return;
        }
        list.innerHTML = items.map(function (s) {
          return '<button class="au-row" data-id="' + s.id + '" data-title="' + esc(s.title || 'Untitled') + '">'
            + '<span class="au-row-title">' + esc(s.title || 'Untitled dataset') + '</span>'
            + '<span class="au-row-meta">' + (s.row_count || 0) + ' rows &middot; ' + (s.column_count || 0) + ' columns &middot; ' + esc(String(s.updated_at || '').slice(0, 10)) + '</span>'
            + '</button>';
        }).join('');
        list.querySelectorAll('.au-row').forEach(function (b) {
          b.addEventListener('click', function () {
            const datasetId = +b.getAttribute('data-id');
            const title     = b.getAttribute('data-title');
            b.disabled = true; b.querySelector('.au-row-meta').textContent = 'Opening…';
            attach(datasetId, title, ctx)
              .then(function (pid) { close(); if (ctx.onLoaded) ctx.onLoaded(null, pid); })
              .catch(function () { b.disabled = false; b.querySelector('.au-row-meta').textContent = 'Could not open — try again.'; });
          });
        });
      })
      .catch(function () {
        list.innerHTML = '<p class="au-msg" style="padding:10px">Could not load saved datasets.</p>';
      });
  }

  window.DatasetUpload = { open: open, embed: embed, openSaved: openSaved };
})();
