/* studio-app-template.js v5 — app-specific JS for a ReliCheck studio.
 * Copy and rename alongside studio-app-template.php.
 * Reads window.BOOT (JSON-encoded by PHP) and boots the studio shell.
 *
 * LOCKED STEPS (do not change without explicit instruction + confirmation):
 *   start · overview · varmap · confirm/unlock
 *
 * References: apps/studio/studio-header.js, apps/studio/studio-footer.js,
 *             apps/studio/data-map.js, apps/studio/type-taxonomy.js
 */

(function () {
  'use strict';

  // ── State ────────────────────────────────────────────────────────────────
  var state = {
    step:             BOOT.initialStep || 'start',
    dataset:          null,   // loaded from localStorage or server
    compTab:          'explain',
    notes:            {},     // keyed by step id
    varmapConfirmed:  false,
    _varmapMounted:   false,
  };

  // ── Helpers ──────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function center() { return document.getElementById('centerInner'); }

  // ── Dataset ──────────────────────────────────────────────────────────────
  // Fetches the dataset from the server by datasetId.
  // Returns a Promise that resolves to a normalized dataset object, or null.
  function fetchDataset(datasetId) {
    if (!datasetId) return Promise.resolve(null);
    return fetch('/api/datasets/get.php?id=' + datasetId, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.dataset) return null;
        var ds = d.dataset;
        return {
          id:        ds.id,
          title:     ds.title,
          fileName:  ds.source_filename || ds.title,
          rowCount:  ds.row_count || 0,
          variables: ds.column_meta || [],
          rows:      ds.data || [],
        };
      })
      .catch(function () { return null; });
  }

  // ── Step rail ────────────────────────────────────────────────────────────
  function activateStep(stepId) {
    state.step = stepId;
    var steps = [].slice.call(document.querySelectorAll('.step'));
    var idx = steps.findIndex(function (s) { return s.getAttribute('data-step') === stepId; });
    steps.forEach(function (s, i) {
      s.setAttribute('data-active', s.getAttribute('data-step') === stepId ? '1' : '0');
      s.setAttribute('data-done',   (idx > -1 && i < idx) ? '1' : '0');
    });
    render();
    renderCompanion();
  }

  // ── Companion panel ───────────────────────────────────────────────────────
  // Mirrors MM Studio's renderCompanion() / setCompTab() / toggleCompanion().

  // Per-step guidance copy — replace each entry with real coaching text.
  var GUIDANCE = {
    start: '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">i</span> What it is</div>'
      + '<div class="cb-t">Pick a data source to begin. Upload a file or open a previously saved dataset from any ReliCheck studio.</div>'
      + '</div>',
    overview: '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">i</span> What it is</div>'
      + '<div class="cb-t">The Overview reads your dataset before any analysis runs: how many rows and variables you have, each variable\'s role, and where values are missing.</div>'
      + '</div>'
      + '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">M</span> What it measures</div>'
      + '<div class="cb-t">Row and variable counts, each variable\'s role, and how much data is missing.</div>'
      + '</div>'
      + '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">&#10003;</span> When to use it</div>'
      + '<div class="cb-t">At the start of any analysis, to understand your dataset before drawing conclusions.</div>'
      + '</div>',
    varmap: '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">i</span> What it is</div>'
      + '<div class="cb-t">ReliCheck auto-detects each variable\'s type. Review the map and correct anything it got wrong before analysis runs.</div>'
      + '</div>'
      + '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">M</span> What to check</div>'
      + '<div class="cb-t">Look at the <b>Analysis Type</b> column. Change any variable that was misclassified. Use <b>Role</b> to mark outcomes, predictors, or grouping variables.</div>'
      + '</div>'
      + '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">&#10003;</span> When you are ready</div>'
      + '<div class="cb-t">Click <b>Confirm data map</b> to lock the map and unlock the analysis pipeline. You can reopen the map at any time.</div>'
      + '</div>',
    // Add one entry per step id.
  };

  function renderCompanion() {
    var tabs   = document.getElementById('compTabs');
    var body   = document.getElementById('compBody');
    if (!tabs || !body) return;

    // Tab bar
    tabs.innerHTML = ['explain', 'notes', 'intelligence'].map(function (t) {
      var label = t === 'intelligence' ? 'Intelligence' : t[0].toUpperCase() + t.slice(1);
      return '<button class="comp-tab' + (state.compTab === t ? ' active' : '') + '" '
        + 'onclick="setCompTab(\'' + t + '\')">' + label + '</button>';
    }).join('');

    // Notes tab
    if (state.compTab === 'notes') {
      body.innerHTML = '<div class="comp-block">'
        + '<div class="cb-k"><span class="i">✎</span> Notes for this step</div>'
        + '<textarea class="notes-area" placeholder="Jot decisions for this step…" '
        + 'oninput="window._tplNoteSave(this.value)">'
        + esc(state.notes[state.step] || '') + '</textarea></div>';
      window._tplNoteSave = function (v) { state.notes[state.step] = v; };
      return;
    }

    // Intelligence tab
    if (state.compTab === 'intelligence') {
      body.innerHTML = '<div class="comp-block">'
        + '<div class="cb-k" style="color:var(--acc-deep)"><span class="i">✦</span> ReliCheck Intelligence</div>'
        + '<div class="ai-prompt">Ask about <b>' + esc(state.step) + '</b>, or pick a suggestion.</div>'
        + '<div class="ai-suggest">'
        + '<button class="ai-chip" onclick="window._tplAi(\'plain\')">Explain this step in plain language</button>'
        + '<button class="ai-chip" onclick="window._tplAi(\'write\')">Draft a sentence for my report</button>'
        + '<button class="ai-chip" onclick="window._tplAi(\'next\')">What should I do next?</button>'
        + '</div><div id="aiOut"></div></div>';
      window._tplAi = function (kind) {
        var msg = kind === 'plain' ? 'Replace with real AI call or static guidance.'
          : kind === 'write' ? 'Replace with real AI call to draft report language.'
          : 'Replace with real AI call for next-step advice.';
        var out = document.getElementById('aiOut');
        if (out) out.innerHTML = '<div class="ai-answer">' + esc(msg) + '</div>';
      };
      return;
    }

    // Explain tab (default)
    var guidance = GUIDANCE[state.step]
      || '<p style="color:var(--ink-3)">Add guidance for the <b>' + esc(state.step) + '</b> step in the GUIDANCE map.</p>';
    body.innerHTML = '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">i</span> On this step</div>'
      + '<div class="cb-t">' + guidance + '</div>'
      + '</div>';
  }

  function setCompTab(t) {
    state.compTab = t;
    renderCompanion();
  }

  function toggleCompanion() {
    document.body.classList.toggle('companion-collapsed');
  }

  // Expose globally so inline onclick attributes work.
  window.setCompTab      = setCompTab;
  window.toggleCompanion = toggleCompanion;

  // ── Start workstation ────────────────────────────────────────────────────
  function renderStart(host) {
    var ds = state.dataset;
    var hasData = !!(ds && (ds.rowCount || ds.rows));

    var html = '<div class="ws-header">'
      + '<div class="ws-eyebrow">Your Data</div>'
      + '<h1 class="ws-title">See what is <em>in your data.</em></h1>'
      + '<p class="ws-lede">Replace this line with your studio\'s one-sentence value proposition.</p>'
      + '</div>';

    if (hasData) {
      var rowCount = ds.rowCount || (ds.rows && ds.rows.length) || 0;
      var fileName = ds.fileName || ds.filename || 'Dataset';
      html += '<div class="loaded-bar">'
        + '<span class="loaded-dot"></span>'
        + '<span class="loaded-label">Loaded</span>'
        + '<span class="loaded-meta">' + esc(fileName) + ' &middot; ' + rowCount + ' rows</span>'
        + '<button class="btn" id="stToOverview">Go to Overview &rarr;</button>'
        + '</div>';
    }

    html += '<button class="begin-feature" id="stUpload">'
      + '<span class="bc-ico">&#8681;</span>'
      + '<div>'
      + '<h4>Upload data</h4>'
      + '<p>Drop an Excel (.xlsx), CSV, or TSV file and tag your columns.</p>'
      + '<span class="bc-go">Upload data &rarr;</span>'
      + '</div>'
      + '</button>'
      + '<div class="begin-or">Or</div>'
      + '<div class="begin-grid2">'
      + '<button class="begin-card2" id="stFromSiri">'
      + '<span class="bc-ico" style="font-size:17px">&#9889;</span>'
      + '<h4>Open from SIRI responses</h4>'
      + '<p>Analyze a published survey\'s collected responses.</p>'
      + '</button>'
      + '<button class="begin-card2" id="stProjects">'
      + '<span class="bc-ico" style="font-size:17px">&#9638;</span>'
      + '<h4>Open a saved project</h4>'
      + '<p>Your saved data, from any ReliCheck studio.</p>'
      + '</button>'
      + '</div>';

    host.innerHTML = html;

    var toOv = document.getElementById('stToOverview');
    if (toOv) toOv.addEventListener('click', function () { activateStep('overview'); });

    var upload = document.getElementById('stUpload');
    if (upload) upload.addEventListener('click', openUpload);

    var fromSiri = document.getElementById('stFromSiri');
    if (fromSiri) fromSiri.addEventListener('click', function () {
      // TODO: wire to SIRI project picker for this studio
    });

    var projects = document.getElementById('stProjects');
    if (projects) projects.addEventListener('click', function () {
      if (typeof DatasetUpload === 'undefined') { alert('Upload widget not loaded.'); return; }
      DatasetUpload.openSaved({
        projectType: BOOT.projectType,
        projectId:   BOOT.projectId || 0,
        onLoaded: function (_err, datasetId) {
          window.location.href = window.location.pathname + '?dataset_id=' + datasetId;
        },
      });
    });
  }

  function openUpload() {
    if (typeof DatasetUpload === 'undefined') { alert('Upload widget not loaded.'); return; }
    DatasetUpload.open({
      projectType: BOOT.projectType,
      projectId:   BOOT.projectId || 0,
      onLoaded: function (_err, datasetId) {
        window.location.href = window.location.pathname + '?dataset_id=' + datasetId;
      },
    });
  }

  function updateHeaderFromDataset() {
    var ds = state.dataset;
    if (!ds) return;
    var label = ds.title || ds.fileName || 'Dataset loaded';
    StudioHeader.setProject(label, true);
  }

  // ── Overview workstation ──────────────────────────────────────────────────
  function renderOverview(host) {
    var ds = state.dataset;

    if (!ds) {
      host.innerHTML = '<div class="ov-placeholder">No data yet. Go to <strong>Start</strong> to upload a file.</div>';
      return;
    }

    var rows     = ds.rows || [];
    var vars     = ds.variables || ds.columns || [];
    var rowCount = ds.rowCount || rows.length || 0;
    var varCount = vars.length;
    var numericCount = vars.filter(function (v) {
      var t = v.analysis_type || v.type || '';
      return t === 'likert_item' || t === 'demographic_numeric' || t === 'scale_score';
    }).length;
    var fileName = ds.fileName || ds.filename || ds.title || 'Dataset';

    var tableRows = vars.length ? vars.map(function (v) {
      var name = v.name || v.label || '';
      var type = v.analysis_type || v.type || '';
      var missing = 0;
      if (rows.length && name) {
        rows.forEach(function (row) {
          var val = row[name];
          if (val === null || val === undefined || val === '') missing++;
        });
      }
      var validN = rowCount - missing;
      return '<tr>'
        + '<td class="ov-td">' + esc(name) + '</td>'
        + '<td class="ov-td ov-td-type">' + esc(type) + '</td>'
        + '<td class="ov-td ov-td-num">' + validN + '</td>'
        + '<td class="ov-td ov-td-num">' + missing + '</td>'
        + '</tr>';
    }).join('') : '<tr><td class="ov-td" colspan="4" style="color:var(--ink-3)">No variable information available.</td></tr>';

    host.innerHTML = ''
      + '<h1 style="font-size:26px;font-weight:800;margin-bottom:6px">Overview</h1>'
      + '<p style="font-size:14px;color:var(--ink-2);margin-bottom:20px">What is in this dataset, before you analyze it.</p>'
      + '<button class="ov-how" id="ovHow">&#128218; How to use this</button>'
      + '<div class="ov-card">'
      + '<div class="ov-card-h">Dataset</div>'
      + '<div class="ov-stats">'
      + '<div><div class="ov-stat-n">' + rowCount + '</div><div class="ov-stat-l">Rows</div></div>'
      + '<div><div class="ov-stat-n">' + varCount + '</div><div class="ov-stat-l">Variables</div></div>'
      + '<div><div class="ov-stat-n">' + numericCount + '</div><div class="ov-stat-l">Numeric</div></div>'
      + '</div>'
      + '<div class="ov-source">Source: ' + esc(fileName) + '</div>'
      + '</div>'
      + '<div class="ov-card">'
      + '<div class="ov-card-h">Variables</div>'
      + '<table class="ov-table">'
      + '<thead><tr>'
      + '<th>Variable</th><th>Type</th><th style="text-align:right">Valid N</th><th style="text-align:right">Missing</th>'
      + '</tr></thead>'
      + '<tbody>' + tableRows + '</tbody>'
      + '</table>'
      + '</div>'
      + '<div class="ov-footer">'
      + '<button class="btn" id="ovMapVars">Map variables &rarr;</button>'
      + '</div>';

    var howBtn = document.getElementById('ovHow');
    if (howBtn) howBtn.addEventListener('click', openOverviewHelp);

    var mapBtn = document.getElementById('ovMapVars');
    if (mapBtn) mapBtn.addEventListener('click', function () { activateStep('varmap'); });

    if (StudioFooter && StudioFooter.setDataInfo) {
      StudioFooter.setDataInfo(rowCount, varCount);
    }
  }

  // ── Help modal ───────────────────────────────────────────────────────────
  // Generic modal used by "How to use this" buttons throughout the studio.
  function showHelpModal(title, bodyHtml) {
    var existing = document.getElementById('stHelpModal');
    if (existing) existing.remove();

    var el = document.createElement('div');
    el.id = 'stHelpModal';
    el.className = 'shm-backdrop';
    el.innerHTML = '<div class="shm-box" role="dialog" aria-modal="true" aria-label="' + esc(title) + '">'
      + '<div class="shm-head">'
      + '<h2 class="shm-title">' + esc(title) + '</h2>'
      + '<button class="shm-close" id="stHelpClose" aria-label="Close">&times;</button>'
      + '</div>'
      + '<div class="shm-body">' + bodyHtml + '</div>'
      + '<div class="shm-foot">'
      + '<button class="btn" id="stHelpGot">Got it</button>'
      + '</div>'
      + '</div>';

    document.body.appendChild(el);

    function close() { el.remove(); }
    document.getElementById('stHelpClose').addEventListener('click', close);
    document.getElementById('stHelpGot').addEventListener('click', close);
    el.addEventListener('click', function (e) { if (e.target === el) close(); });
  }

  function openOverviewHelp() {
    showHelpModal('How to use the Overview',
      '<p>The Overview reads your dataset before any analysis runs: how many rows and variables you have, each variable\'s role, and where values are missing.</p>'
      + '<ol>'
      + '<li><strong>Check the summary cards</strong> — Rows, Variables, and Numeric show the shape of your data.</li>'
      + '<li><strong>Scan the Variables table.</strong> Each row names a variable, its type, and how many values are missing.</li>'
      + '<li><strong>Flag high missing counts</strong> before you analyze — a variable with many missing values will weaken results.</li>'
      + '<li><strong>Click Map variables</strong> when the dataset looks right.</li>'
      + '</ol>'
      + '<div class="shm-example">'
      + '<div class="shm-ex-label">Worked example</div>'
      + '<p><strong>Reading it:</strong> 250 rows, 12 variables, 8 numeric, with 0 missing on the key items means you can analyze every case with confidence.</p>'
      + '</div>'
    );
  }

  // ── Variable Map workstation ──────────────────────────────────────────────
  function renderVarMap(host) {
    var ds = state.dataset;

    if (!ds) {
      host.innerHTML = '<div class="ov-placeholder">No data yet. Go to <strong>Start</strong> to upload a file.</div>';
      return;
    }

    if (!window.DataMap) {
      host.innerHTML = '<div class="ov-placeholder">DataMap component not loaded. Please refresh.</div>';
      return;
    }

    host.innerHTML = '<div id="dmContainer"></div>';
    var container = document.getElementById('dmContainer');

    if (state._varmapMounted) {
      DataMap.mount(container);
      return;
    }

    // Build rawVars from the dataset's column_meta, normalising to the shape DataMap expects.
    var rawVars = (ds.variables || []).map(function (v) {
      return {
        variable_name:  v.variable_name || v.name || v.label || '',
        display_label:  v.display_label || v.label || null,
        detected_type:  v.detected_type || v.storage_type || v.type || null,
        analysis_type:  v.analysis_type || null,
        role:           v.role || null,
        construct_id:   v.construct_id || null,
        reverse_scored: !!v.reverse_scored,
        include_in_analysis: v.include_in_analysis !== false,
      };
    });

    DataMap.init({
      container:   container,
      projectId:   BOOT.projectId,
      projectType: BOOT.projectType || 'analysis',
      rawVars:     rawVars,
      onConfirmed: function (vars) {
        state.varmapConfirmed = true;
        state._varmapMounted  = true;
        activateStep(state.step); // re-render rail to show confirmed state
      },
    });
    state._varmapMounted = true;
  }

  // ── Render ───────────────────────────────────────────────────────────────
  // Replace each case with your real step content.
  function render() {
    var el = center();
    if (!el) return;

    switch (state.step) {

      case 'start':
        renderStart(el);
        break;

      case 'overview':
        renderOverview(el);
        break;

      case 'varmap':
        renderVarMap(el);
        break;

      default:
        el.innerHTML = '<p style="color:var(--ink-3);font-size:14px;">Step: ' + esc(state.step) + '</p>';
    }
  }

  // ── Init ─────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {

    // 1. Uniform header
    StudioHeader.init({
      logoSrc:      '/RENAME-LOGO.png',              // studio mark / wordmark
      logoAlt:      'App Name Studio',
      logoHeight:   70,
      projectLabel: BOOT.projectLabel || 'New project',
      projectLive:  BOOT.projectLive,
      projectsUrl:  BOOT.projectsUrl,
      initials:     BOOT.initials,
    });

    // 2. Uniform footer
    StudioFooter.init();

    // 3. Step rail wiring
    document.querySelectorAll('.step').forEach(function (btn) {
      btn.addEventListener('click', function () {
        activateStep(btn.getAttribute('data-step'));
      });
    });

    // 4. Fetch dataset from server, then render
    fetchDataset(BOOT.datasetId).then(function (ds) {
      state.dataset = ds;
      updateHeaderFromDataset();
      // If a dataset is already loaded jump straight to overview
      var initialStep = BOOT.initialStep || (ds ? 'overview' : 'start');
      activateStep(initialStep);
    });

    // 6. Wire SIRI / RSSI footer info if project is known
    // if (BOOT.projectId) StudioHeader.loadRssiStub(BOOT.projectId);
    // StudioFooter.setSiriInfo({ score: null });  // or real data from BOOT
  });

})();
