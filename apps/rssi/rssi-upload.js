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
    // Pass dataset.config through to the engine for parity with the
    // studio strength-index host (strength-index.js:3421). The standalone
    // RSSI surface has no UI to populate config today (KNOWN_ISSUES.md §16),
    // so dataset.config will be empty in practice — keeping the signature
    // consistent so when §16's UI work lands the integration seam is
    // already correct.
    const lensResult = engine(dataset, (dataset && dataset.config) || {});
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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
