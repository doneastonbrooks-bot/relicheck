// ReliCheck Data Map — shared studio component.
// Renders a variable classification table between the Overview and Pipeline steps.
// Reads/writes variable_metadata via the RE API. Drives the pipeline by telling
// the studio which analysis operations each variable is eligible for.
//
// Requires type-taxonomy.js (window.RCTaxonomy) to be loaded first.
//
// DataMap.init(opts)            — mount and load. opts:
//   container   {Element}       DOM element to render into (required)
//   projectId   {number}        project id (required)
//   projectType {string}        'survey'|'analysis'|'mm' (default 'survey')
//   rawVars     {Array}         detected variables from the dataset (fallback
//                                 when no saved metadata exists yet). Each:
//                                 { variable_name, display_label?, detected_type?,
//                                   source?, analysis_type?, role?, construct_id?,
//                                   allowed_values?, reverse_scored?, include_in_analysis?,
//                                   position? }
//   constructs  {Array}         [{id, name}] for the construct assignment dropdown
//   onConfirmed {Function}      called with (variables[]) after user confirms the map
//
// DataMap.update(rawVars)       — replace raw variable list and re-render
// DataMap.isConfirmed()         — true after user clicks Confirm data map
// DataMap.getVariables()        — current classified variable list

(function () {
  'use strict';

  // ── CSS ─────────────────────────────────────────────────────────────────────
  var CSS = ''
    + '.rdm-wrap{font-family:var(--font,-apple-system,BlinkMacSystemFont,"SF Pro Text",Inter,system-ui,sans-serif);font-size:14px;color:var(--ink,#15171a)}'
    + '.rdm-head{margin-bottom:18px}'
    + '.rdm-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3,#8a8f98);margin-bottom:8px}'
    + '.rdm-title{font-size:22px;font-weight:700;letter-spacing:-.02em;margin:0 0 6px}'
    + '.rdm-lede{font-size:14px;color:var(--ink-2,#5f6368);margin:0 0 16px;line-height:1.55;max-width:660px}'
    // Filter bar
    + '.rdm-filters{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center}'
    + '.rdm-f{border:1px solid var(--line,#e6e8ec);border-radius:20px;padding:5px 13px;font-size:12.5px;font-weight:600;color:var(--ink-2,#5f6368);background:#fff;cursor:pointer;font-family:inherit}'
    + '.rdm-f:hover{border-color:var(--btn,#7c3aed);color:var(--btn,#7c3aed)}'
    + '.rdm-f.on{background:var(--btn,#7c3aed);color:#fff;border-color:var(--btn,#7c3aed)}'
    + '.rdm-count{font-size:12px;color:var(--ink-3,#8a8f98);margin-left:4px}'
    // Table container — fixed height, sticky header
    + '.rdm-scroll{max-height:420px;overflow-y:auto;border:1px solid var(--line,#e6e8ec);border-radius:12px}'
    + '.rdm-tbl{width:100%;border-collapse:collapse;font-size:13px}'
    + '.rdm-tbl thead{position:sticky;top:0;z-index:2;background:var(--bg,#f5f6f8)}'
    + '.rdm-tbl th{padding:9px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-3,#8a8f98);border-bottom:1px solid var(--line,#e6e8ec);white-space:nowrap}'
    + '.rdm-tbl td{padding:8px 10px;border-bottom:1px solid var(--line-2,#eef0f3);vertical-align:middle}'
    + '.rdm-tbl tr:last-child td{border-bottom:none}'
    + '.rdm-tbl tr:hover td{background:var(--bg,#f5f6f8)}'
    // Variable name cell
    + '.rdm-vname{font-weight:600;color:var(--ink,#15171a);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '.rdm-vlabel{font-size:11.5px;color:var(--ink-3,#8a8f98);margin-top:1px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    // Detected type chip
    + '.rdm-chip{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11.5px;font-weight:600;background:var(--bg,#f5f6f8);color:var(--ink-2,#5f6368);border:1px solid var(--line,#e6e8ec);white-space:nowrap}'
    // Dropdowns and inputs
    + '.rdm-sel{font-size:12.5px;font-family:inherit;border:1px solid var(--line,#e6e8ec);border-radius:7px;padding:5px 7px;color:var(--ink,#15171a);background:#fff;cursor:pointer;min-width:140px;max-width:200px}'
    + '.rdm-sel:focus{outline:none;border-color:var(--btn,#7c3aed)}'
    + '.rdm-con{font-size:12.5px;font-family:inherit;border:1px solid var(--line,#e6e8ec);border-radius:7px;padding:5px 7px;color:var(--ink,#15171a);background:#fff;width:150px}'
    + '.rdm-con:focus{outline:none;border-color:var(--btn,#7c3aed)}'
    // Toggle
    + '.rdm-tog{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:var(--ink-2,#5f6368);cursor:pointer;white-space:nowrap}'
    + '.rdm-tog input{cursor:pointer}'
    // Suggested analyses
    + '.rdm-analyses{font-size:11.5px;color:var(--ink-2,#5f6368);line-height:1.55;max-width:220px}'
    + '.rdm-none{font-size:11.5px;color:var(--ink-3,#8a8f98);font-style:italic}'
    // Save bar
    + '.rdm-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px;padding:12px 14px;background:var(--panel,#fff);border:1px solid var(--line,#e6e8ec);border-radius:12px}'
    + '.rdm-bar-note{font-size:12.5px;color:var(--ink-2,#5f6368)}'
    + '.rdm-bar-note.ok{color:#1f9e44;font-weight:600}'
    + '.rdm-bar-note.warn{color:#b45309}'
    // Empty / loading states
    + '.rdm-msg{padding:28px 20px;text-align:center;color:var(--ink-3,#8a8f98);font-size:14px}'
    // Excluded row styling
    + '.rdm-tbl tr.rdm-excl td{opacity:.45}'
    ;

  // ── State ────────────────────────────────────────────────────────────────────
  var _container = null;
  var _opts      = {};
  var _vars      = [];        // current classified variable list (edits applied)
  var _saved     = false;     // true after last successful API save
  var _confirmed = false;     // true after user clicks "Confirm data map"
  var _loading   = false;
  var _saving    = false;
  var _err       = '';
  var _filter    = 'all';     // 'all'|'quantitative'|'qualitative'|'categorical'|'other'

  // ── Helpers ──────────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function injectCss() {
    if (document.getElementById('rdm-css')) return;
    var el = document.createElement('style');
    el.id = 'rdm-css';
    el.textContent = CSS;
    document.head.appendChild(el);
  }

  function taxon() { return window.RCTaxonomy || null; }

  function filterGroup(analysisType) {
    var tx = taxon();
    if (!tx) return 'other';
    if (tx.isQuantitative(analysisType)) return 'quantitative';
    if (tx.isQualitative(analysisType))  return 'qualitative';
    if (['demographic_nominal', 'demographic_ordinal'].indexOf(analysisType) !== -1) return 'categorical';
    return 'other';
  }

  function visibleVars() {
    if (_filter === 'all') return _vars;
    return _vars.filter(function (v) { return filterGroup(v.analysis_type) === _filter; });
  }

  function countByFilter(f) {
    if (f === 'all') return _vars.length;
    return _vars.filter(function (v) { return filterGroup(v.analysis_type) === f; }).length;
  }

  // Merge saved API rows onto raw variables, preserving detected_type.
  function mergeApiRows(raw, apiRows) {
    var byName = {};
    apiRows.forEach(function (r) { byName[r.variable_name] = r; });
    return raw.map(function (v, i) {
      var saved = byName[v.variable_name];
      if (saved) {
        return Object.assign({}, v, {
          analysis_type:       saved.analysis_type,
          measurement_level:   saved.measurement_level,
          role:                saved.role,
          construct_id:        saved.construct_id,
          allowed_values:      saved.allowed_values,
          reverse_scored:      saved.reverse_scored,
          include_in_analysis: saved.include_in_analysis,
          position:            saved.position != null ? saved.position : i,
          _saved_id:           saved.id,
        });
      }
      return Object.assign({ position: i }, v);
    });
  }

  // Infer analysis_type from detected_type if not already set.
  function inferAnalysisType(v) {
    var tx = taxon();
    if (!tx) return v.analysis_type || 'open_ended';
    if (v.analysis_type && tx.isValid(v.analysis_type)) return v.analysis_type;
    if (v.detected_type) {
      return tx.resolveDisplayType(v.detected_type, v.construct_id || null);
    }
    return 'open_ended';
  }

  function applyDefaults(vars) {
    return vars.map(function (v, i) {
      var at = inferAnalysisType(v);
      var tx = taxon();
      return Object.assign({
        source:              'dataset_column',
        analysis_type:       at,
        measurement_level:   tx ? (tx.measurementLevel[at] || 'none') : null,
        role:                null,
        construct_id:        null,
        allowed_values:      null,
        reverse_scored:      false,
        include_in_analysis: true,
        position:            i,
      }, v, { analysis_type: at });
    });
  }

  // ── API calls ────────────────────────────────────────────────────────────────
  function apiLoad(cb) {
    var url = '/api/dev/variable-meta-load.php?project_id=' + _opts.projectId
            + '&project_type=' + encodeURIComponent(_opts.projectType || 'survey');
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { cb(null, j); })
      .catch(function (e) { cb(e, null); });
  }

  function apiSave(vars, cb) {
    var payload = {
      project_id:   _opts.projectId,
      project_type: _opts.projectType || 'survey',
      variables:    vars.map(function (v, i) {
        return {
          variable_name:       v.variable_name,
          display_label:       v.display_label || null,
          source:              v.source || 'dataset_column',
          survey_item_id:      v.survey_item_id || null,
          dataset_id:          v.dataset_id || null,
          storage_type:        v.storage_type || null,
          analysis_type:       v.analysis_type,
          role:                v.role || null,
          construct_id:        v.construct_id || null,
          allowed_values:      v.allowed_values || null,
          reverse_scored:      !!v.reverse_scored,
          include_in_analysis: v.include_in_analysis !== false,
          position:            v.position != null ? v.position : i,
        };
      }),
    };
    fetch('/api/dev/variable-meta-save.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify(payload),
    })
      .then(function (r) { return r.json(); })
      .then(function (j) { cb(null, j); })
      .catch(function (e) { cb(e, null); });
  }

  // ── Render ───────────────────────────────────────────────────────────────────
  function render() {
    if (!_container) return;
    _container.innerHTML = buildHtml();
  }

  function buildHtml() {
    if (_loading) {
      return '<div class="rdm-wrap"><div class="rdm-msg">Loading variable map…</div></div>';
    }
    if (_err) {
      return '<div class="rdm-wrap"><div class="rdm-msg">' + esc(_err)
           + ' <button class="btn" onclick="DataMap._retry()">Retry</button></div></div>';
    }
    if (!_vars.length) {
      return '<div class="rdm-wrap"><div class="rdm-msg">No variables detected. Load a dataset first.</div></div>';
    }

    var tx = taxon();
    var filters = [
      ['all',          'All',          countByFilter('all')],
      ['quantitative', 'Quantitative', countByFilter('quantitative')],
      ['qualitative',  'Qualitative',  countByFilter('qualitative')],
      ['categorical',  'Categorical',  countByFilter('categorical')],
      ['other',        'Other',        countByFilter('other')],
    ];

    var filterBar = '<div class="rdm-filters">'
      + filters.filter(function (f) { return f[2] > 0 || f[0] === 'all'; }).map(function (f) {
          return '<button class="rdm-f' + (_filter === f[0] ? ' on' : '')
               + '" onclick="DataMap._setFilter(\'' + f[0] + '\')">'
               + esc(f[1]) + ' <span class="rdm-count">' + f[2] + '</span></button>';
        }).join('')
      + '</div>';

    var vis = visibleVars();
    var tableBody = vis.length
      ? vis.map(function (v) { return buildRow(v); }).join('')
      : '<tr><td colspan="7" style="text-align:center;color:var(--ink-3,#8a8f98);padding:18px">No variables in this group.</td></tr>';

    var table = '<div class="rdm-scroll"><table class="rdm-tbl">'
      + '<thead><tr>'
      + '<th>Variable</th>'
      + '<th>Detected Type</th>'
      + '<th>Analysis Type</th>'
      + '<th>Role</th>'
      + '<th>Construct</th>'
      + '<th style="text-align:center">Rev / Include</th>'
      + '<th>Suggested Analyses</th>'
      + '</tr></thead>'
      + '<tbody>' + tableBody + '</tbody>'
      + '</table></div>';

    var confirmed = _confirmed;
    var dirty = !_saved;
    var note = _saving ? 'Saving…'
             : confirmed ? '<span class="rdm-bar-note ok">Map confirmed — pipeline unlocked</span>'
             : dirty     ? '<span class="rdm-bar-note warn">Unsaved changes</span>'
             :             '<span class="rdm-bar-note">Map saved</span>';

    var saveBar = '<div class="rdm-bar">'
      + '<button class="btn primary" onclick="DataMap._save(false)" '
      + (_saving ? 'disabled' : '') + '>'
      + (_saving ? 'Saving…' : 'Save map') + '</button>'
      + (confirmed
          ? '<button class="btn" onclick="DataMap._unconfirm()">Reopen map</button>'
          : '<button class="btn" onclick="DataMap._save(true)" '
            + (_saving ? 'disabled' : '') + '>Confirm data map →</button>')
      + note
      + '</div>';

    return '<div class="rdm-wrap">'
         + '<div class="rdm-head">'
         + '<div class="rdm-eyebrow">Data map · classify before you analyze</div>'
         + '<h2 class="rdm-title">Variable Map</h2>'
         + '<p class="rdm-lede">Confirm the analysis type for each variable. The right column shows what analyses become available. '
         + 'When ready, click <strong>Confirm data map</strong> to unlock the analysis pipeline.</p>'
         + '</div>'
         + filterBar
         + table
         + saveBar
         + '</div>';
  }

  function buildRow(v) {
    var tx = taxon();
    var idx = _vars.indexOf(v);
    var excluded = !v.include_in_analysis;

    // Variable name cell
    var nameCell = '<td><div class="rdm-vname" title="' + esc(v.variable_name) + '">'
                 + esc(v.variable_name) + '</div>'
                 + (v.display_label && v.display_label !== v.variable_name
                     ? '<div class="rdm-vlabel" title="' + esc(v.display_label) + '">'
                       + esc(v.display_label) + '</div>'
                     : '')
                 + '</td>';

    // Detected type chip
    var detected = v.detected_type || (v.storage_type ? v.storage_type : '—');
    var detectedCell = '<td><span class="rdm-chip">' + esc(detected) + '</span></td>';

    // Analysis type dropdown — grouped
    var atCell = '<td>' + buildAnalysisTypeSelect(idx, v.analysis_type) + '</td>';

    // Role dropdown
    var roleCell = '<td>' + buildRoleSelect(idx, v.role, v.analysis_type) + '</td>';

    // Construct cell — only for likert_item
    var conCell = '<td>';
    if (v.analysis_type === 'likert_item' && _opts.constructs && _opts.constructs.length) {
      conCell += buildConstructSelect(idx, v.construct_id);
    } else if (v.analysis_type === 'likert_item') {
      conCell += '<input class="rdm-con" placeholder="Construct name" value="'
               + esc(v._construct_name || '') + '" '
               + 'oninput="DataMap._setConstructName(' + idx + ',this.value)">';
    } else {
      conCell += '<span class="rdm-none">—</span>';
    }
    conCell += '</td>';

    // Rev / Include toggles
    var togCell = '<td style="text-align:center">'
      + (v.analysis_type === 'likert_item'
          ? '<label class="rdm-tog" style="display:block;margin-bottom:5px" title="Reverse score before aggregating">'
            + '<input type="checkbox" ' + (v.reverse_scored ? 'checked' : '')
            + ' onchange="DataMap._toggleReverse(' + idx + ',this.checked)"> Rev</label>'
          : '')
      + '<label class="rdm-tog" title="Include in analysis">'
        + '<input type="checkbox" ' + (v.include_in_analysis ? 'checked' : '')
        + ' onchange="DataMap._toggleInclude(' + idx + ',this.checked)"> Include</label>'
      + '</td>';

    // Suggested analyses
    var analyses = (tx && !excluded) ? tx.getAllowedAnalysesLabeled(v.analysis_type) : [];
    var analysesCell = '<td><div class="rdm-analyses">'
      + (excluded
          ? '<span class="rdm-none">Excluded</span>'
          : analyses.length
            ? analyses.map(function (a) { return esc(a.label); }).join(', ')
            : '<span class="rdm-none">None</span>')
      + '</div></td>';

    return '<tr class="' + (excluded ? 'rdm-excl' : '') + '">'
         + nameCell + detectedCell + atCell + roleCell + conCell + togCell + analysesCell
         + '</tr>';
  }

  function buildAnalysisTypeSelect(idx, current) {
    var groups = [
      { label: 'Quantitative',
        types: ['likert_item', 'scale_score', 'demographic_numeric', 'binary'] },
      { label: 'Qualitative',
        types: ['open_ended', 'narrative', 'qualitative_code', 'theme'] },
      { label: 'Categorical',
        types: ['demographic_nominal', 'demographic_ordinal'] },
      { label: 'System / Other',
        types: ['identifier', 'computed_score', 'date_time', 'metadata', 'file_reference', 'structural'] },
    ];
    var tx = taxon();
    var html = '<select class="rdm-sel" onchange="DataMap._setType(' + idx + ',this.value)">';
    groups.forEach(function (g) {
      html += '<optgroup label="' + esc(g.label) + '">';
      g.types.forEach(function (t) {
        var label = (tx && tx.labels[t]) ? tx.labels[t] : t;
        html += '<option value="' + esc(t) + '"' + (t === current ? ' selected' : '') + '>'
              + esc(label) + '</option>';
      });
      html += '</optgroup>';
    });
    html += '</select>';
    return html;
  }

  function buildRoleSelect(idx, current, analysisType) {
    var tx = taxon();
    var options = [
      { value: '',          label: '— none —' },
      { value: 'item',      label: 'Scale item' },
      { value: 'outcome',   label: 'Outcome' },
      { value: 'predictor', label: 'Predictor' },
      { value: 'grouping',  label: 'Grouping' },
      { value: 'linking',   label: 'Linking / ID' },
      { value: 'contextual',label: 'Contextual' },
    ];
    var html = '<select class="rdm-sel" onchange="DataMap._setRole(' + idx + ',this.value)">';
    options.forEach(function (o) {
      html += '<option value="' + esc(o.value) + '"' + (o.value === (current || '') ? ' selected' : '') + '>'
            + esc(o.label) + '</option>';
    });
    html += '</select>';
    return html;
  }

  function buildConstructSelect(idx, currentId) {
    var html = '<select class="rdm-sel" onchange="DataMap._setConstruct(' + idx + ',+this.value||null)">'
             + '<option value="">— none —</option>';
    (_opts.constructs || []).forEach(function (c) {
      html += '<option value="' + c.id + '"' + (c.id === currentId ? ' selected' : '') + '>'
            + esc(c.name) + '</option>';
    });
    html += '</select>';
    return html;
  }

  // ── Public event handlers (called from inline onclick) ────────────────────────
  function _setFilter(f)  { _filter = f; render(); }
  function _retry()       { _load(); }

  function _setType(idx, val) {
    var tx = taxon();
    if (tx && !tx.isValid(val)) return;
    _vars[idx] = Object.assign({}, _vars[idx], { analysis_type: val });
    if (val !== 'likert_item') {
      _vars[idx].reverse_scored = false;
    }
    _saved = false;
    render();
  }

  function _setRole(idx, val) {
    _vars[idx] = Object.assign({}, _vars[idx], { role: val || null });
    _saved = false;
    render();
  }

  function _setConstruct(idx, id) {
    _vars[idx] = Object.assign({}, _vars[idx], { construct_id: id || null });
    _saved = false;
    render();
  }

  function _setConstructName(idx, name) {
    _vars[idx] = Object.assign({}, _vars[idx], { _construct_name: name });
    _saved = false;
    // Don't re-render on keystroke — save bar reflects dirty state
  }

  function _toggleReverse(idx, on) {
    _vars[idx] = Object.assign({}, _vars[idx], { reverse_scored: on });
    _saved = false;
  }

  function _toggleInclude(idx, on) {
    _vars[idx] = Object.assign({}, _vars[idx], { include_in_analysis: on });
    _saved = false;
    render();
  }

  function _save(confirmAfter) {
    if (_saving) return;
    _saving = true;
    _err = '';
    render();
    apiSave(_vars, function (err, j) {
      _saving = false;
      if (err || !j || !j.ok) {
        _err = (j && j.message) || 'Could not save the variable map.';
      } else {
        _saved = true;
        if (confirmAfter) {
          _confirmed = true;
          if (_opts.onConfirmed) _opts.onConfirmed(_vars.slice());
        }
      }
      render();
    });
  }

  function _unconfirm() {
    _confirmed = false;
    render();
  }

  // ── Load flow ────────────────────────────────────────────────────────────────
  function _load() {
    _loading = true;
    _err = '';
    render();
    apiLoad(function (err, j) {
      _loading = false;
      var rawWithDefaults = applyDefaults(_opts.rawVars || []);
      if (!err && j && j.ok && j.variables && j.variables.length) {
        // Merge saved metadata onto raw variables, preserving detected_type.
        _vars = mergeApiRows(rawWithDefaults, j.variables);
        _saved = true;
      } else {
        // No saved metadata yet — use inferred defaults.
        _vars = rawWithDefaults;
        _saved = false;
      }
      render();
    });
  }

  // ── Public API ────────────────────────────────────────────────────────────────
  window.DataMap = {

    init: function (opts) {
      if (!opts || !opts.container || !opts.projectId) {
        console.error('DataMap.init: container and projectId are required.');
        return;
      }
      injectCss();
      _container  = opts.container;
      _opts       = opts;
      _vars       = [];
      _saved      = false;
      _confirmed  = false;
      _loading    = false;
      _saving     = false;
      _err        = '';
      _filter     = 'all';
      _load();
    },

    update: function (rawVars) {
      _opts.rawVars = rawVars || [];
      _load();
    },

    isConfirmed: function () { return _confirmed; },

    getVariables: function () { return _vars.slice(); },

    // Internal handlers — exposed for inline onclick
    _setFilter:       _setFilter,
    _retry:           _retry,
    _setType:         _setType,
    _setRole:         _setRole,
    _setConstruct:    _setConstruct,
    _setConstructName: _setConstructName,
    _toggleReverse:   _toggleReverse,
    _toggleInclude:   _toggleInclude,
    _save:            _save,
    _unconfirm:       _unconfirm,
  };

}());
