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

  /* ============================================================================
   * TAG STAGE (KNOWN_ISSUES §16 M2).
   *
   * Replaces the pre-M2 auto-emit `buildDataset`. The stage between parse
   * and score is now a user-confirmed tagging surface: parse → enter tag
   * stage with auto-filled proposals → user adjusts roles/constructs/
   * reverse-coded → click "Score my data" → materialize from confirmed
   * tags → existing score path.
   *
   * State lives in two globals so the dashboard can flip back to the tag
   * stage without re-parsing or re-inferring:
   *   window.RSSI_PARSED     — { headers, rows }
   *   window.RSSI_TAG_STATE  — { fileHash, fileName, reverseConfirmed,
   *                              columns: [{ name, role, construct,
   *                                reverseCoded, anchorCount, autoRole,
   *                                autoAnchorCount, sample, autoBadge,
   *                                userConfirmed }] }
   *
   * Auto-save: 500ms-debounced write to localStorage on every tag change,
   * keyed by core.fileContentHash. visibilitychange (hidden) + pagehide
   * trigger an immediate flush so tab-close-mid-tag never loses work.
   * ============================================================================ */

  const TAG_CACHE_PREFIX = 'rssi.tagcache.';
  const ROLES_FOR_DROPDOWN = ['likert','numeric','demographic','criterion','identifier','free_text','ignore'];
  const ROLE_LABELS = {
    likert:      'Likert (rating)',
    numeric:     'Numeric',
    demographic: 'Demographic',
    criterion:   'Criterion (outcome)',
    identifier:  'Identifier',
    free_text:   'Free text',
    ignore:      'Ignore',
  };

  let _saveTimer = null;
  function _scheduleSave() {
    if (_saveTimer) clearTimeout(_saveTimer);
    _saveTimer = setTimeout(_flushSave, 500);
  }
  function _flushSave() {
    if (_saveTimer) { clearTimeout(_saveTimer); _saveTimer = null; }
    const s = window.RSSI_TAG_STATE;
    if (!s || !s.fileHash) return;
    const slim = {
      fileName:         s.fileName,
      savedAt:          Date.now(),
      reverseConfirmed: !!s.reverseConfirmed,
      columns: s.columns.map(function (c) {
        return {
          name:          c.name,
          role:          c.role,
          construct:     c.construct || '',
          reverseCoded:  !!c.reverseCoded,
          anchorCount:   c.anchorCount == null ? null : Number(c.anchorCount),
          userConfirmed: !!c.userConfirmed,
        };
      }),
    };
    try { window.localStorage.setItem(TAG_CACHE_PREFIX + s.fileHash, JSON.stringify(slim)); }
    catch (e) { /* storage full or private mode — skip */ }
  }
  function _loadCache(fileHash) {
    if (!fileHash) return null;
    try {
      const raw = window.localStorage.getItem(TAG_CACHE_PREFIX + fileHash);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) { return null; }
  }

  /* Build initial tag state for a freshly-parsed file. Restores from
     localStorage cache when the content hash matches; otherwise seeds
     from inferColumnRoles. Auto-badge is true only on rows whose role
     matches the inferred autoRole AND the user hasn't already touched
     in a prior session (cached userConfirmed: true clears the badge). */
  function _buildInitialTagState(parsed, fileName, fileHash) {
    const core = window.RSSI_TAG_CORE;
    const auto = core.inferColumnRoles(parsed);
    const cache = _loadCache(fileHash);
    const cachedByName = {};
    if (cache && Array.isArray(cache.columns)) {
      cache.columns.forEach(function (c) { cachedByName[c.name] = c; });
    }
    const columns = auto.map(function (a) {
      const cached = cachedByName[a.name];
      if (cached) {
        return {
          name:            a.name,
          role:            cached.role || a.autoRole,
          construct:       cached.construct || '',
          reverseCoded:    !!cached.reverseCoded,
          anchorCount:     cached.anchorCount != null ? Number(cached.anchorCount) : a.autoAnchorCount,
          autoRole:        a.autoRole,
          autoAnchorCount: a.autoAnchorCount,
          sample:          a.sample,
          autoBadge:       false,                   // restored = user-tagged, no badge
          userConfirmed:   cached.userConfirmed === true,
        };
      }
      return {
        name:            a.name,
        role:            a.autoRole,
        construct:       '',
        reverseCoded:    false,
        anchorCount:     a.autoAnchorCount,
        autoRole:        a.autoRole,
        autoAnchorCount: a.autoAnchorCount,
        sample:          a.sample,
        autoBadge:       true,
        userConfirmed:   false,
      };
    });
    return {
      fileHash:         fileHash,
      fileName:         fileName,
      reverseConfirmed: cache ? !!cache.reverseConfirmed : false,
      columns:          columns,
    };
  }

  function _escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function _renderTagTable() {
    const tbody = document.getElementById('rssiTagTbody');
    if (!tbody) return;
    const s = window.RSSI_TAG_STATE;
    if (!s) { tbody.innerHTML = ''; return; }

    const rows = s.columns.map(function (c, i) {
      const opts = ROLES_FOR_DROPDOWN.map(function (r) {
        return '<option value="' + r + '"' + (r === c.role ? ' selected' : '') + '>' + ROLE_LABELS[r] + '</option>';
      }).join('');
      const badge = c.autoBadge ? '<span class="tag-auto-badge" title="Auto-detected — change to override">Auto</span>' : '';
      const isLikert = c.role === 'likert';
      const constructInput = isLikert
        ? '<input type="text" list="rssiConstructsUsed" data-row="' + i + '" data-field="construct" value="' + _escapeHtml(c.construct) + '" placeholder="e.g., Engagement">'
        : '<span style="color:var(--text-3);font-size:11.5px;">—</span>';
      const reverseInput = isLikert
        ? '<input type="checkbox" data-row="' + i + '" data-field="reverseCoded"' + (c.reverseCoded ? ' checked' : '') + '>'
        : '';
      const anchorInput = isLikert
        ? '<input type="number" min="2" max="11" data-row="' + i + '" data-field="anchorCount" value="' + (c.anchorCount == null ? '' : c.anchorCount) + '">'
        : '';
      const sample = c.sample.filter(function (v) { return v !== '' && v != null; }).slice(0, 3).join(' · ');
      return (
        '<tr data-row="' + i + '">' +
          '<td class="col-name" title="' + _escapeHtml(c.name) + '">' + _escapeHtml(c.name) + badge + '</td>' +
          '<td><select data-row="' + i + '" data-field="role">' + opts + '</select></td>' +
          '<td data-cell="construct">' + constructInput + '</td>' +
          '<td style="text-align:center;">' + reverseInput + '</td>' +
          '<td style="text-align:center;">' + anchorInput + '</td>' +
          '<td class="col-sample">' + _escapeHtml(sample) + '</td>' +
        '</tr>'
      );
    });
    tbody.innerHTML = rows.join('');

    const revCb = document.getElementById('rssiReverseConfirmed');
    if (revCb) revCb.checked = !!s.reverseConfirmed;

    _rebuildConstructDatalist();
    _updateValidation();
  }

  function _rebuildConstructDatalist() {
    const dl = document.getElementById('rssiConstructsUsed');
    if (!dl) return;
    const s = window.RSSI_TAG_STATE;
    const set = {};
    s.columns.forEach(function (c) {
      const v = (c.construct || '').trim();
      if (v && c.role === 'likert') set[v] = true;
    });
    dl.innerHTML = Object.keys(set).sort().map(function (v) {
      return '<option value="' + _escapeHtml(v) + '"></option>';
    }).join('');
  }

  function _updateValidation() {
    const core = window.RSSI_TAG_CORE;
    const s = window.RSSI_TAG_STATE;
    if (!core || !s) return;
    const { blockers, hints } = core.validateTags(s.columns, { reverse_coded_confirmed: s.reverseConfirmed });

    // Clear any existing inline hint rows.
    const tbody = document.getElementById('rssiTagTbody');
    if (tbody) {
      tbody.querySelectorAll('.tag-hint').forEach(function (n) { n.remove(); });
    }

    // Render hints inline beneath the referenced row's construct cell.
    // Phase 1 Q8 + greenlight: multiple_criterion copy makes the
    // skip-when-multiple behavior explicit (engine reads exactly one
    // criterion_column; materializeDataset omits it entirely when count != 1,
    // so the §4A criterion sub-component is skipped, not "first wins").
    function appendHint(rowIdx, text) {
      if (!tbody) return;
      const row = tbody.querySelector('tr[data-row="' + rowIdx + '"]');
      if (!row) return;
      const cell = row.querySelector('td[data-cell="construct"]') || row.querySelector('td:nth-child(3)');
      if (!cell) return;
      const span = document.createElement('span');
      span.className = 'tag-hint';
      span.textContent = text;
      cell.appendChild(span);
    }
    const colIdxByName = {};
    s.columns.forEach(function (c, i) { colIdxByName[c.name] = i; });

    hints.forEach(function (h) {
      if (h.kind === 'construct_too_small') {
        s.columns.forEach(function (c, i) {
          if (c.role === 'likert' && (c.construct || '').trim() === h.construct) {
            appendHint(i, 'Construct "' + h.construct + '" has only ' + h.count + ' item' + (h.count === 1 ? '' : 's') + ' — reliability stats need at least 3.');
          }
        });
      } else if (h.kind === 'multiple_criterion') {
        h.columns.forEach(function (name) {
          if (colIdxByName[name] != null) {
            appendHint(colIdxByName[name], 'Multiple criterion columns tagged (' + h.count + '). Criterion validity is skipped unless exactly one is tagged — pick one or untag the others.');
          }
        });
      } else if (h.kind === 'unusual_likert_range') {
        if (colIdxByName[h.column] != null) {
          appendHint(colIdxByName[h.column], 'Anchor count ' + h.anchorCount + ' is unusually wide for a Likert scale — most validated scales use 3–7 anchors.');
        }
      }
    });

    // Blockers gate the Score button. Only kind in M2 is no_likert_tagged.
    const btn  = document.getElementById('rssiScoreBtn');
    const msg  = document.getElementById('rssiTagBlockerMsg');
    if (btn && msg) {
      if (blockers.length === 0) {
        btn.removeAttribute('disabled');
        msg.textContent = '';
      } else {
        btn.setAttribute('disabled', 'disabled');
        const b = blockers[0];
        msg.textContent = b.kind === 'no_likert_tagged'
          ? 'Tag at least one column as Likert to score.'
          : 'Resolve the issue above to continue.';
      }
    }
  }

  /* User edits a single row field. Mutates state, clears the auto-badge,
     marks the row user-confirmed, then schedules save + re-renders the
     parts that need to react (datalist, validation, optionally the row
     itself when the role changed and dependent inputs need to appear/
     disappear). */
  function _onRowFieldChange(rowIdx, field, value) {
    const s = window.RSSI_TAG_STATE;
    if (!s) return;
    const col = s.columns[rowIdx];
    if (!col) return;
    let needFullRender = false;
    if (field === 'role') {
      col.role = value;
      // Role change can flip whether construct/reverse/anchor inputs
      // appear — re-render the table so the row's cells match.
      needFullRender = true;
      // Clear Likert-only fields when leaving Likert (avoids stale
      // construct/reverse hiding inside state).
      if (value !== 'likert') {
        col.construct = '';
        col.reverseCoded = false;
        // Keep anchorCount as the auto value for return-to-likert.
      }
    } else if (field === 'construct') {
      col.construct = value;
    } else if (field === 'reverseCoded') {
      col.reverseCoded = !!value;
    } else if (field === 'anchorCount') {
      col.anchorCount = value === '' ? null : Number(value);
    }
    col.autoBadge     = false;
    col.userConfirmed = true;
    _scheduleSave();
    if (needFullRender) {
      _renderTagTable();
    } else {
      _rebuildConstructDatalist();
      _updateValidation();
    }
  }

  function _onReverseConfirmedChange(checked) {
    const s = window.RSSI_TAG_STATE;
    if (!s) return;
    s.reverseConfirmed = !!checked;
    _scheduleSave();
    // Doesn't change blockers/hints from validateTags, but kept here in
    // case future hints depend on it.
    _updateValidation();
  }

  function _wireTagTableHandlers() {
    const tbody = document.getElementById('rssiTagTbody');
    if (!tbody || tbody._rssiWired) return;
    tbody._rssiWired = true;
    // Single delegated listener — survives table re-renders.
    tbody.addEventListener('change', function (e) {
      const t = e.target;
      if (!t || t.dataset == null) return;
      const r = t.getAttribute('data-row');
      const f = t.getAttribute('data-field');
      if (r == null || f == null) return;
      const v = (t.type === 'checkbox') ? t.checked : t.value;
      _onRowFieldChange(Number(r), f, v);
    });
    tbody.addEventListener('input', function (e) {
      const t = e.target;
      if (!t || t.tagName !== 'INPUT' || t.type === 'checkbox') return;
      const r = t.getAttribute('data-row');
      const f = t.getAttribute('data-field');
      if (r == null || f == null) return;
      _onRowFieldChange(Number(r), f, t.value);
    });
    const revCb = document.getElementById('rssiReverseConfirmed');
    if (revCb && !revCb._rssiWired) {
      revCb._rssiWired = true;
      revCb.addEventListener('change', function () { _onReverseConfirmedChange(revCb.checked); });
    }
    const scoreBtn = document.getElementById('rssiScoreBtn');
    if (scoreBtn && !scoreBtn._rssiWired) {
      scoreBtn._rssiWired = true;
      scoreBtn.addEventListener('click', _onScoreClick);
    }
  }

  function _materializeFromState() {
    const core = window.RSSI_TAG_CORE;
    const s = window.RSSI_TAG_STATE;
    if (!core || !s || !window.RSSI_PARSED) return null;
    const columnRoles = s.columns.map(function (c) {
      return {
        name:          c.name,
        role:          c.role,
        construct:     c.construct,
        reverseCoded:  c.reverseCoded,
        anchorCount:   c.anchorCount,
        userConfirmed: c.userConfirmed,
      };
    });
    return core.materializeDataset(
      window.RSSI_PARSED,
      columnRoles,
      { reverse_coded_confirmed: s.reverseConfirmed },
      s.fileName || ''
    );
  }

  function _onScoreClick() {
    const core = window.RSSI_TAG_CORE;
    const s = window.RSSI_TAG_STATE;
    if (!core || !s) return;
    const v = core.validateTags(s.columns, { reverse_coded_confirmed: s.reverseConfirmed });
    if (v.blockers.length > 0) return;        // Button shouldn't be enabled, but be defensive.
    _flushSave();                              // Persist before leaving the stage.
    const dataset = _materializeFromState();
    if (!dataset) return;
    const result = computeRSSI(dataset);
    handoffToDashboard(result, dataset, s.fileName || '');
    // Auto-save server-side after the dashboard renders. Skipped when
    // we're already viewing a previously-saved dataset (re-tag from
    // dashboard would otherwise create a duplicate row), and when the
    // demo flow is active.
    if (!window.RSSI_DATASET_ID && !window.RSSI_IS_DEMO && window.RSSI_PARSED) {
      _autoSaveDataset(window.RSSI_PARSED, s).catch(function () { /* silent */ });
    } else if (window.RSSI_DATASET_ID && !window.RSSI_IS_DEMO) {
      // Already a saved project — re-tag means update the existing row
      // (column_meta + settings only; data hasn't changed).
      _updateSavedTags(window.RSSI_DATASET_ID, s).catch(function () { /* silent */ });
    }
  }

  /* ────────────────────────────────────────────────────────────
   *  Auto-save (Phase 1 Q1 (a)). After the tag stage commits, persist
   *  the tagged dataset to /api/datasets/create.php using the same
   *  column_meta + settings schema the studios use, plus RSSI's role
   *  extensions (numeric / criterion / demographic / identifier) and
   *  the survey-level reverse_coded_confirmed gate.
   *
   *  Captures the returned dataset_id on window.RSSI_DATASET_ID and
   *  rewrites the URL with ?dataset_id=N via history.replaceState so a
   *  page reload (or share-link) re-hydrates without an upload step.
   *
   *  Skipped for the demo / sample flow (RSSI_IS_DEMO=true) and for
   *  datasets loaded via ?dataset_id|survey_id|mm_project_id — those
   *  are already saved.
   * ──────────────────────────────────────────────────────────── */
  function _buildColumnMetaForSave(tagState) {
    return tagState.columns
      .filter(function (c) { return c.role !== 'identifier' && c.role !== 'ignore' || c.role === 'ignore'; })
      .map(function (c) {
        const entry = {
          name:    c.name,
          type:    c.role,
          reverse: !!c.reverseCoded,
        };
        const con = (c.construct || '').trim();
        if (con) entry.construct = con;
        return entry;
      });
  }

  function _buildSettingsForSave(tagState) {
    // Pull the anchor count off the first Likert row that has one (RSSI's
    // tag stage stores anchor_count per Likert column; the dataset settings
    // schema carries a single survey-level likertPoints). When values
    // differ across columns, save the first; the dim-grid's actual
    // per-column anchor_count survives in column_meta on update via the
    // engine's transform (KNOWN_ISSUES §4 #4b).
    let kPoints = 5;
    for (const c of tagState.columns) {
      if (c.role === 'likert' && c.anchorCount != null) {
        const n = Number(c.anchorCount);
        if (Number.isFinite(n) && n >= 2 && n <= 11) { kPoints = n; break; }
      }
    }
    return {
      likertPoints: kPoints,
      likertLow:    'Strongly disagree',
      likertHigh:   'Strongly agree',
      reverse_coded_confirmed: !!tagState.reverseConfirmed,
    };
  }

  function _buildDataMatrixForSave(parsed, columnMeta) {
    // Project parsed.rows (object keyed by header) to a column-ordered
    // 2-D array in the same order as the column_meta we just built.
    const headers = columnMeta.map(function (cm) { return cm.name; });
    return parsed.rows.map(function (r) {
      return headers.map(function (h) {
        const v = r[h];
        return v == null ? '' : v;
      });
    });
  }

  function _autoSaveDataset(parsed, tagState) {
    const fileName = tagState.fileName || 'Untitled survey';
    const sourceFormat = /\.xlsx?$/i.test(fileName) ? 'xlsx' : 'csv';
    const title = fileName.replace(/\.(csv|xlsx?|tsv|txt)$/i, '');
    const column_meta = _buildColumnMetaForSave(tagState);
    const settings    = _buildSettingsForSave(tagState);
    const data        = _buildDataMatrixForSave(parsed, column_meta);

    return fetch('/api/datasets/create.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        title:           title,
        source_filename: fileName,
        source_format:   sourceFormat,
        column_meta:     column_meta,
        settings:        settings,
        data:            data,
      }),
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        if (!out.ok || !out.body || !out.body.id) {
          console.warn('Auto-save failed:', out.body);
          return null;
        }
        window.RSSI_DATASET_ID = out.body.id;
        // Update the URL so a reload / share opens the saved project.
        try {
          const url = new URL(window.location.href);
          url.searchParams.set('dataset_id', String(out.body.id));
          // Strip transient query params if present.
          url.searchParams.delete('frommodal');
          history.replaceState(null, '', url.toString());
        } catch (e) {}
        return out.body.id;
      });
  }

  function _updateSavedTags(datasetId, tagState) {
    // Re-tag from the dashboard → persist the new column_meta + settings
    // against the existing row. Uses update.php so we hit both in one
    // call (update_columns.php only covers column_meta).
    const column_meta = _buildColumnMetaForSave(tagState);
    const settings    = _buildSettingsForSave(tagState);
    return fetch('/api/datasets/update.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: datasetId, column_meta: column_meta, settings: settings }),
    }).then(function (r) { return r.json(); })
      .catch(function (e) { console.warn('Tag-update failed:', e); });
  }

  function _enterTagStage(parsed, file, arrayBuffer) {
    const core = window.RSSI_TAG_CORE;
    if (!core) {
      throw new Error('rssi-tag-core.js must load before rssi-upload.js (KNOWN_ISSUES §16).');
    }
    const fileHash = core.fileContentHash(arrayBuffer);
    window.RSSI_PARSED    = parsed;
    window.RSSI_TAG_STATE = _buildInitialTagState(parsed, file.name, fileHash);
    _renderTagTable();
    _wireTagTableHandlers();
    const root = document.getElementById('rssiAppRoot');
    if (root) root.setAttribute('data-stage', 'tag');
    // Clear any "parsing..." status banner; the tag stage is its own UI now.
    const status = document.getElementById('rssiUploadStatus');
    if (status) { status.innerHTML = ''; status.className = 'upload-status'; }
  }

  /* ────────────────────────────────────────────────────────────
   *  Load from a saved project (Phase 1 Q4–Q6).
   *
   *  URL params dispatch on init():
   *    ?dataset_id=N   → GET /api/datasets/get.php?id=N
   *    ?survey_id=N    → GET /api/surveys/responses-dataset.php?survey_id=N
   *    ?mm_project_id=N → GET /api/mm/project.php?id=N, follow its
   *                       linked dataset_id or survey_id.
   *
   *  All three paths converge on _enterFromSavedDataset(), which
   *  hydrates RSSI_PARSED + RSSI_TAG_STATE from the server payload
   *  and then either skips the tag stage (column_meta is tag-complete
   *  AND reverse_coded_confirmed) or shows it pre-populated.
   *
   *  When loaded from a saved row, RSSI_DATASET_ID is set so re-tag /
   *  re-score updates the existing row rather than creating a new one.
   * ──────────────────────────────────────────────────────────── */

  /* Convert a dataset API response (column_meta + array-of-arrays data)
     into the { headers, rows } shape RSSI_PARSED carries. */
  function _hydrateParsed(columnMeta, dataMatrix) {
    const headers = columnMeta.map(function (cm) { return String(cm.name || ''); });
    const rows = dataMatrix.map(function (row) {
      const obj = {};
      headers.forEach(function (h, i) {
        const v = row[i];
        obj[h] = (v == null) ? '' : String(v);
      });
      return obj;
    });
    return { headers: headers, rows: rows };
  }

  /* Build a v2 tag state from saved column_meta + settings. Treats every
     saved row as user-confirmed (no auto-badge) because the user already
     went through the tag stage when first scoring. */
  function _hydrateTagState(parsed, columnMeta, settings, fileName, datasetId) {
    const core = window.RSSI_TAG_CORE;
    const auto = core ? core.inferColumnRoles(parsed) : [];
    const autoByName = {};
    auto.forEach(function (a) { autoByName[a.name] = a; });

    const fallbackAnchor = (settings && Number.isFinite(Number(settings.likertPoints)))
      ? Number(settings.likertPoints) : null;

    const columns = columnMeta.map(function (cm) {
      const a = autoByName[cm.name] || { autoRole: cm.type, autoAnchorCount: fallbackAnchor, sample: [] };
      return {
        name:            cm.name,
        role:            cm.type || 'ignore',
        construct:       cm.construct || '',
        reverseCoded:    !!cm.reverse,
        anchorCount:     (cm.type === 'likert') ? fallbackAnchor : a.autoAnchorCount,
        autoRole:        a.autoRole,
        autoAnchorCount: a.autoAnchorCount,
        sample:          a.sample,
        autoBadge:       false,         // saved = user-tagged
        userConfirmed:   true,
      };
    });
    return {
      fileHash:         null,
      fileName:         fileName,
      datasetId:        datasetId,
      reverseConfirmed: !!(settings && settings.reverse_coded_confirmed),
      columns:          columns,
    };
  }

  /* Skip-tag-stage gate (Phase 1 Q6): straight to score iff every Likert
     column has a construct AND the survey-level reverse-confirmed flag
     is set. Otherwise show the tag stage pre-populated so the user can
     fill the gaps. */
  function _isTagComplete(tagState) {
    if (!tagState.reverseConfirmed) return false;
    const likertCols = tagState.columns.filter(function (c) { return c.role === 'likert'; });
    if (likertCols.length === 0) return false;
    return likertCols.every(function (c) { return (c.construct || '').trim() !== ''; });
  }

  function _enterFromSavedDataset(parsed, columnMeta, settings, fileName, datasetId) {
    window.RSSI_PARSED     = parsed;
    window.RSSI_DATASET_ID = datasetId || null;
    window.RSSI_TAG_STATE  = _hydrateTagState(parsed, columnMeta, settings, fileName, datasetId);

    const titleEl = document.getElementById('rssiDashTitle');
    if (titleEl && fileName) titleEl.textContent = fileName.replace(/\.(csv|xlsx?|tsv|txt)$/i, '');

    if (_isTagComplete(window.RSSI_TAG_STATE)) {
      // Straight to score → dashboard.
      const dataset = _materializeFromState();
      if (dataset) {
        const result = computeRSSI(dataset);
        handoffToDashboard(result, dataset, window.RSSI_TAG_STATE.fileName || '');
      }
    } else {
      // Show the tag stage pre-populated.
      _renderTagTable();
      _wireTagTableHandlers();
      const root = document.getElementById('rssiAppRoot');
      if (root) root.setAttribute('data-stage', 'tag');
    }
  }

  function _loadFromDataset(datasetId) {
    setStatus('<strong>Loading saved project…</strong>');
    return fetch('/api/datasets/get.php?id=' + encodeURIComponent(datasetId), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        if (!out.ok || !out.body || !out.body.dataset) {
          const msg = (out.body && out.body.error_message) || 'Could not load that project.';
          setStatus('<strong>' + msg + '</strong>', 'error');
          return;
        }
        const ds = out.body.dataset;
        const parsed = _hydrateParsed(ds.column_meta || [], ds.data || []);
        _enterFromSavedDataset(parsed, ds.column_meta || [], ds.settings || {}, ds.source_filename || ds.title || 'Saved project', ds.id);
      })
      .catch(function (err) {
        setStatus('<strong>Could not load that project:</strong> ' + (err && err.message ? err.message : String(err)), 'error');
      });
  }

  function _loadFromSurvey(surveyId) {
    setStatus('<strong>Loading survey responses…</strong>');
    return fetch('/api/surveys/responses-dataset.php?survey_id=' + encodeURIComponent(surveyId), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        if (!out.ok || !out.body || !out.body.payload || !out.body.payload.dataset) {
          const msg = (out.body && out.body.error_message) || 'Could not load that survey.';
          setStatus('<strong>' + msg + '</strong>', 'error');
          return;
        }
        // /api/surveys/responses-dataset.php returns the engine-shape dataset
        // (with variables[] rather than column_meta + data). Convert to the
        // tag-stage's expected shape.
        const eng = out.body.payload.dataset;
        const headers = (eng.variables || []).map(function (v) { return v.name; });
        const rowCount = (eng.variables && eng.variables[0]) ? (eng.variables[0].values || []).length : 0;
        const rows = [];
        for (let i = 0; i < rowCount; i++) {
          const obj = {};
          eng.variables.forEach(function (v) {
            const vv = (v.values || [])[i];
            obj[v.name] = (vv == null) ? '' : String(vv);
          });
          rows.push(obj);
        }
        const parsed = { headers: headers, rows: rows };
        // Build column_meta from variables: type derives from variable.types[0].
        const colMeta = (eng.variables || []).map(function (v) {
          const t = (v.types && v.types[0]) || 'open';
          const role = (t === 'likert')      ? 'likert'
                     : (t === 'numeric')     ? 'numeric'
                     : (t === 'categorical') ? 'demographic'
                     : (t === 'open')        ? 'free_text'
                     : 'ignore';
          return {
            name:      v.name,
            type:      role,
            reverse:   !!v.reverse_coded,
            construct: v.construct || undefined,
          };
        });
        const settings = {
          likertPoints: (eng.config && eng.config.likertPoints) || 5,
          reverse_coded_confirmed: !!(eng.config && eng.config.reverse_coded_confirmed),
        };
        _enterFromSavedDataset(parsed, colMeta, settings, eng.source || ('Survey #' + surveyId), null);
        // Survey-loaded datasets aren't owned by `datasets` rows — leave
        // RSSI_DATASET_ID null so a later "Score my data" creates a new
        // dataset row (the studios persist these via separate paths).
      })
      .catch(function (err) {
        setStatus('<strong>Could not load that survey:</strong> ' + (err && err.message ? err.message : String(err)), 'error');
      });
  }

  function _loadFromMMProject(mmProjectId) {
    setStatus('<strong>Loading MM project…</strong>');
    return fetch('/api/mm/project.php?id=' + encodeURIComponent(mmProjectId), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        if (!out.ok || !out.body || !out.body.project) {
          const msg = (out.body && out.body.error_message) || 'Could not load that MM project.';
          setStatus('<strong>' + msg + '</strong>', 'error');
          return;
        }
        const proj = out.body.project;
        // MM projects can link to a dataset OR a survey. Dispatch.
        if (proj.dataset_id) return _loadFromDataset(Number(proj.dataset_id));
        if (proj.survey_id)  return _loadFromSurvey(Number(proj.survey_id));
        setStatus('<strong>This MM project has no linked Likert data to score.</strong> Link a dataset or survey to it in the MM Studio, then try again.', 'error');
      })
      .catch(function (err) {
        setStatus('<strong>Could not load that MM project:</strong> ' + (err && err.message ? err.message : String(err)), 'error');
      });
  }

  /* Re-tag from the dashboard. Preserves the in-memory state — does NOT
     re-run inferColumnRoles (Phase 1 Q9). User-touched rows retain their
     tags; auto-badged rows keep their badges. */
  function _onRetagClick() {
    if (!window.RSSI_TAG_STATE || !window.RSSI_PARSED) return;
    const root = document.getElementById('rssiAppRoot');
    if (root) root.setAttribute('data-stage', 'tag');
    _renderTagTable();
    _wireTagTableHandlers();
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
   * Compose the full RSSI score blob — v2-native shape.
   *
   * The legacy six-key v1 `components` map is retired. The standalone
   * report surface now consumes the engine's canonical output directly:
   * three lenses, eight domains, disagreement readout, validity-forward
   * cap flag (Spec §3.2, §3.3, §3.5, §3.6). Per Spec §2, v1 labels
   * ("Survey Structure", "Reliability Readiness", "Scale Strength",
   * "Validity Alignment", "Response Risk", "Question Quality") are not
   * preserved — the renderer reads the canonical 8 domain keys directly.
   *
   * The headline `strength` field is the Respondent-Centered lens score
   * (Spec §3.4 default headline). The ring renders it; the lens triplet
   * elsewhere on the surface shows all three. `verdict` is the band of
   * that headline number.
   * ──────────────────────────────────────────────────────────── */
  function computeRSSI(dataset) {
    const likertVars = dataset.variables.filter(function (v) { return v.types[0] === 'likert'; });
    const engine = window.RSSI_MATH && window.RSSI_MATH.computeLensesFromDataset;
    if (typeof engine !== 'function') {
      throw new Error('Canonical lens engine not loaded — apps/strength-index/strength-index.js must be included before apps/rssi/rssi-upload.js (Spec §6).');
    }
    const lensResult = engine(dataset, (dataset && dataset.config) || {});
    const rssi = lensResult.rssi || {};

    // Headline number = Respondent-Centered lens (Spec §3.4).
    const strength = rssi.respondent_centered == null
      ? 0
      : Math.round(rssi.respondent_centered);
    const verdict = strength >= 85 ? 'Excellent'
                  : strength >= 70 ? 'Strong'
                  : strength >= 55 ? 'Good'
                  : strength >= 40 ? 'Fair'
                  : 'Weak';

    const summary = 'At ' + strength + ', this survey is in ' + verdict.toLowerCase() + ' shape. ' +
      (strength >= 70
        ? 'A few targeted fixes would move it into excellent territory.'
        : 'Focused revisions to the lowest-scoring dimensions would meaningfully lift the score.');

    // scaleCount (KNOWN_ISSUES #18 fix): derive from distinct
    // `v.construct` values across Likert variables — the same grouping
    // the engine itself uses internally (§4 reliability + §4A validity
    // + §4B construct alignment + §4E scale structure all group items
    // by `v.construct || v.scale`, see strength-index.js around line
    // 2119 / 2327 / 2543). This replaces the v1-era heuristic
    // `Math.ceil(likertVars.length / 5)` which assumed scales of ~5
    // items and had no relationship to the actual constructs the user
    // tagged in the tag stage.
    //
    // Edge cases mirror the engine:
    //   - 0 Likert items → 0 scales.
    //   - Likert items present, none with a construct → 1 implicit
    //     scale (the engine groups untagged Likert as a single scale
    //     when no constructs are assigned, even if §4B + §4A skip
    //     for lack of construct labels).
    //   - Mixed tagged + untagged Likert → distinct constructs + 1
    //     for the untagged group.
    const distinctConstructs = new Set();
    let untaggedLikertCount = 0;
    likertVars.forEach(function (v) {
      const c = (typeof v.construct === 'string') ? v.construct.trim() : '';
      if (c) distinctConstructs.add(c);
      else untaggedLikertCount++;
    });
    const scaleCount = likertVars.length === 0
      ? 0
      : distinctConstructs.size + (untaggedLikertCount > 0 ? 1 : 0);

    return {
      app_key: 'strength_index',
      app_name: 'ReliCheck Strength Survey Index',
      summary: summary,
      strength: strength,
      verdict: verdict,
      // Three-lens RSSI output (Spec §3.2–3.3). Pass through verbatim;
      // the surface reads psychometric_core / respondent_centered /
      // validity_forward / headline_lens / disagreement_readout /
      // validity_forward_capped directly.
      rssi:                 rssi,
      domain_subscores:     lensResult.domain_subscores || {},
      domain_details:       lensResult.domain_details   || {},
      skipped_domains:      lensResult.skipped_domains  || [],
      rssi_weights_version: lensResult.rssi_weights_version,
      dataset: {
        source:     dataset.source,
        rowCount:   dataset.rowCount,
        itemCount:  dataset.variables.length,
        scaleCount: scaleCount,
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

  /* Read the raw bytes once so we can both parse the file AND hash it
     for the tag-cache key. CSV/TSV parse decodes a copy of the buffer
     as UTF-8; XLSX parse hands the buffer straight to SheetJS. */
  function _readArrayBuffer(file) {
    return new Promise(function (resolve, reject) {
      const r = new FileReader();
      r.onload  = function (e) { resolve(e.target.result); };
      r.onerror = function () { reject(new Error('Could not read the file.')); };
      r.readAsArrayBuffer(file);
    });
  }

  function handleFile(file) {
    if (!file) return;
    setStatus('<strong>Parsing ' + file.name + '…</strong><div class="meta">' + (file.size / 1024).toFixed(1) + ' KB</div>');
    const name = (file.name || '').toLowerCase();
    const isXlsx = name.endsWith('.xlsx') || name.endsWith('.xls');

    _readArrayBuffer(file).then(function (buf) {
      if (isXlsx) {
        return loadXlsx().then(function (XLSX) {
          const wb = XLSX.read(buf, { type: 'array' });
          const sheet = wb.Sheets[wb.SheetNames[0]];
          const json = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
          if (!json.length) throw new Error('The spreadsheet has no data.');
          const headers = json[0].map(function (h) { return String(h).trim(); });
          const rows = json.slice(1).map(function (arr) {
            const row = {};
            headers.forEach(function (h, i) { row[h] = arr[i] != null ? String(arr[i]).trim() : ''; });
            return row;
          }).filter(function (r) {
            return Object.values(r).some(function (v) { return v !== ''; });
          });
          return { parsed: { headers: headers, rows: rows }, buf: buf };
        });
      }
      // CSV/TSV: decode the buffer as UTF-8 and parse.
      const text  = new TextDecoder('utf-8').decode(new Uint8Array(buf));
      const delim = detectDelimiter(text);
      return { parsed: parseDelimited(text, delim), buf: buf };
    }).then(function (out) {
      const parsed = out.parsed;
      if (!parsed.headers.length) throw new Error('No columns detected.');
      if (!parsed.rows.length)    throw new Error('No data rows detected.');
      // The pre-M2 < 3 Likert hard gate is retired (Phase 1 Q2 +
      // greenlight). validateTags's `no_likert_tagged` blocker is now
      // the single guard — surfaced inline at the tag stage rather than
      // as a parse-time error. Per-construct `construct_too_small`
      // covers the more meaningful "scale needs ≥ 3 items" warning.
      _enterTagStage(parsed, file, out.buf);
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
      if (qp.get('demo') === '1') {
        window.RSSI_IS_DEMO = true;
        loadDemo();
      }

      /* Load from a saved project. Three URL-param shapes, mutually
         exclusive: ?dataset_id=N | ?survey_id=N | ?mm_project_id=N.
         These bypass the upload stage and route straight into the tag
         stage (pre-populated) or the dashboard (when tag-complete).
         Anonymous demo flow still works via ?demo=1. */
      const dsId = qp.get('dataset_id');
      const svId = qp.get('survey_id');
      const mpId = qp.get('mm_project_id');
      if (dsId && /^\d+$/.test(dsId)) {
        _loadFromDataset(Number(dsId));
      } else if (svId && /^\d+$/.test(svId)) {
        _loadFromSurvey(Number(svId));
      } else if (mpId && /^\d+$/.test(mpId)) {
        _loadFromMMProject(Number(mpId));
      }

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

    /* Import picker modal: opens on the "Pull from a saved project"
       button on the upload stage. Lazy-loads each tab on first activation
       so the upload page doesn't pay for unused list endpoints. */
    _wireProjectPicker();

    /* Score another → back to upload stage. Tag state is intentionally
       NOT cleared: localStorage still holds it keyed by file hash, so a
       re-upload of the same file rehydrates the prior tags. */
    const again = document.getElementById('rssiUploadAgain');
    if (again) again.addEventListener('click', function () {
      _flushSave();                       // Save before leaving — belt-and-suspenders.
      const root = document.getElementById('rssiAppRoot');
      if (root) root.setAttribute('data-stage', 'upload');
      file.value = '';
      setStatus('', '');
      document.getElementById('rssiUploadStatus').classList.remove('show');
    });

    /* Re-tag columns → back to tag stage from dashboard, preserving
       in-memory state (Phase 1 Q9). */
    const retag = document.getElementById('rssiRetagBtn');
    if (retag) retag.addEventListener('click', _onRetagClick);

    /* Auto-save flush on tab close / tab switch / BFCache. visibilitychange
       (hidden) covers tab-switch and tab-close on every modern browser;
       pagehide is the Safari BFCache belt-and-suspenders (Phase 1 Q7 +
       greenlight). beforeunload deliberately omitted — unreliable on
       mobile, and the two listeners above already cover the case. */
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') _flushSave();
    });
    window.addEventListener('pagehide', _flushSave);
  }

  /* ────────────────────────────────────────────────────────────
   *  Project import picker (modal + tabs + lazy list-loaders).
   *
   *  Opens on the "Pull from a saved project" button on the upload
   *  stage. Three tabs:
   *    - Datasets    → /api/datasets/list.php
   *    - Surveys     → /api/surveys/list.php
   *    - MM Projects → /api/mm/projects.php
   *
   *  Each tab's list is fetched on first activation, then cached on
   *  the modal element. Click a row → navigate to the corresponding
   *  ?id_param=N URL on this page, which triggers _loadFromDataset /
   *  _loadFromSurvey / _loadFromMMProject during init().
   * ──────────────────────────────────────────────────────────── */
  const _PICKER_ENDPOINTS = {
    datasets: { url: '/api/datasets/list.php', key: 'datasets', param: 'dataset_id' },
    surveys:  { url: '/api/surveys/list.php',  key: 'surveys',  param: 'survey_id'  },
    mm:       { url: '/api/mm/projects.php',   key: 'projects', param: 'mm_project_id' },
  };

  function _wireProjectPicker() {
    const modal    = document.getElementById('rssiPickerModal');
    const openBtn  = document.getElementById('rssiOpenPicker');
    const closeBtn = document.getElementById('rssiPickerClose');
    const backdrop = document.getElementById('rssiPickerBackdrop');
    if (!modal || !openBtn) return;

    function open() {
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      // Lazy-load the currently-active tab on first open.
      const activeTab = modal.querySelector('.rssi-picker-tab.is-active');
      if (activeTab) _loadPickerTab(activeTab.getAttribute('data-tab'));
      document.addEventListener('keydown', onKey);
    }
    function close() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }

    openBtn.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', close);
    if (backdrop) backdrop.addEventListener('click', close);

    // Tab switching.
    modal.querySelectorAll('.rssi-picker-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        const which = tab.getAttribute('data-tab');
        modal.querySelectorAll('.rssi-picker-tab').forEach(function (t) {
          const on = t === tab;
          t.classList.toggle('is-active', on);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        modal.querySelectorAll('.rssi-picker-pane').forEach(function (p) {
          const on = p.getAttribute('data-pane') === which;
          p.hidden = !on;
          p.classList.toggle('is-active', on);
        });
        _loadPickerTab(which);
      });
    });

    // Delegated row click → navigate.
    modal.addEventListener('click', function (e) {
      const row = e.target.closest && e.target.closest('.rssi-picker-row[data-pick-id]');
      if (!row) return;
      const id    = row.getAttribute('data-pick-id');
      const param = row.getAttribute('data-pick-param');
      if (!id || !param) return;
      // Same page, new URL — triggers the load path in init() on the
      // fresh page load. (Using full reload rather than rewriting in-
      // place keeps the dashboard mount lifecycle clean.)
      const url = new URL(window.location.href);
      url.searchParams.delete('demo');
      url.searchParams.delete('frommodal');
      url.searchParams.delete('dataset_id');
      url.searchParams.delete('survey_id');
      url.searchParams.delete('mm_project_id');
      url.searchParams.set(param, id);
      window.location.href = url.toString();
    });
  }

  function _loadPickerTab(which) {
    const endpoint = _PICKER_ENDPOINTS[which];
    if (!endpoint) return;
    const modal = document.getElementById('rssiPickerModal');
    const pane  = modal && modal.querySelector('.rssi-picker-pane[data-pane="' + which + '"]');
    if (!pane) return;
    if (pane._rssiLoaded) return;       // cached
    pane._rssiLoaded = true;
    fetch(endpoint.url, { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        const rows = (out.body && (out.body[endpoint.key] || [])) || [];
        if (!out.ok) {
          pane.innerHTML = '<div class="rssi-picker-err">Could not load list.</div>';
          pane._rssiLoaded = false;     // allow retry
          return;
        }
        if (rows.length === 0) {
          const empty = (which === 'datasets')
            ? 'No saved datasets yet. Upload one to see it here.'
            : (which === 'surveys')
            ? 'No surveys yet. Build one in Survey Studio.'
            : 'No MM projects yet.';
          pane.innerHTML = '<div class="rssi-picker-empty">' + empty + '</div>';
          return;
        }
        pane.innerHTML = '<ul class="rssi-picker-list">' + rows.map(function (r) {
          return _renderPickerRow(r, which, endpoint.param);
        }).join('') + '</ul>';
      })
      .catch(function () {
        pane.innerHTML = '<div class="rssi-picker-err">Could not load list. Try again.</div>';
        pane._rssiLoaded = false;
      });
  }

  function _renderPickerRow(r, which, param) {
    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
      });
    }
    const id    = r.id;
    let title   = '';
    let meta    = '';
    if (which === 'datasets') {
      title = r.title || r.source_filename || 'Untitled';
      const bits = [];
      if (r.row_count)    bits.push(r.row_count + ' rows');
      if (r.likert_count) bits.push(r.likert_count + ' Likert items');
      if (r.updated_at)   bits.push('updated ' + new Date(r.updated_at).toLocaleDateString());
      meta = bits.join(' · ');
    } else if (which === 'surveys') {
      title = r.title || ('Survey #' + id);
      const bits = [];
      if (r.response_count) bits.push(r.response_count + ' responses');
      if (r.is_published)   bits.push('published');
      if (r.updated_at)     bits.push('updated ' + new Date(r.updated_at).toLocaleDateString());
      meta = bits.join(' · ');
    } else { // mm
      title = r.title || ('MM project #' + id);
      const bits = [];
      if (r.status)     bits.push(r.status);
      if (r.updated_at) bits.push('updated ' + new Date(r.updated_at).toLocaleDateString());
      meta = bits.join(' · ');
    }
    return (
      '<li><button type="button" class="rssi-picker-row" data-pick-id="' + esc(id) + '" data-pick-param="' + esc(param) + '">' +
        '<div class="rssi-picker-row-title">' + esc(title) + '</div>' +
        '<div class="rssi-picker-row-meta">' + esc(meta) + '</div>' +
        '<span class="rssi-picker-row-arrow">→</span>' +
      '</button></li>'
    );
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
