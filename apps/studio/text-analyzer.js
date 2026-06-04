/* text-analyzer.js — ReliCheck OpenText App
 * Two outputs: suggested themes (AI first pass) + quantify to measurable variables.
 * File uploads use the universal DatasetUpload widget (CSV, TSV, XLSX, JSON, Qualtrics, GForms).
 */

(function () {
  'use strict';

  // ── State ────────────────────────────────────────────────────────────────
  var state = {
    step:            'input',
    inputTab:        'paste',    // 'paste' | 'upload'
    pasteText:       '',
    pasteFormat:     'lines',    // 'lines' | 'doc'
    dataset:         null,       // fetched dataset object from DatasetUpload
    textColumn:      '',         // column the user selected for text responses
    responses:       [],         // final array of strings to analyze
    culturalContext: '',
    themes:          null,       // {summary, themes[], counter_patterns[], ...}
    matrix:          null,       // {matrix[], theme_names[], frequencies{}}
    analyzing:       false,
    quantifying:     false,
    compTab:         'explain',
    notes:           {},
  };

  // ── Helpers ──────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function center() { return document.getElementById('centerInner'); }

  function buildResponses() {
    if (state.inputTab === 'upload') {
      if (!state.dataset || !state.textColumn) return [];
      return (state.dataset.rows || []).map(function (row) {
        return (row[state.textColumn] || '').trim();
      }).filter(Boolean);
    }
    var text = state.pasteText.trim();
    if (!text) return [];
    if (state.pasteFormat === 'doc') return [text];
    return text.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
  }

  // Fetch a dataset from the server by id.
  // data is stored as [[cell,cell,...], ...] — convert to [{colName: val, ...}] objects.
  function fetchDataset(datasetId) {
    if (!datasetId) return Promise.resolve(null);
    return fetch('/api/datasets/get.php?id=' + datasetId, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.dataset) return null;
        var ds   = d.dataset;
        var cols = ds.column_meta || [];
        var rows = (ds.data || []).map(function (arr) {
          var obj = {};
          cols.forEach(function (col, i) {
            obj[col.name] = arr[i] !== undefined ? String(arr[i]) : '';
          });
          return obj;
        });
        return {
          id:        ds.id,
          title:     ds.title,
          fileName:  ds.source_filename || ds.title,
          rowCount:  ds.row_count || 0,
          variables: cols,
          rows:      rows,
        };
      })
      .catch(function () { return null; });
  }

  // Pick the best text column from a dataset's variables (open_ended / narrative first).
  function pickTextColumn(variables) {
    var textTypes = ['open_ended', 'narrative', 'open'];
    for (var i = 0; i < variables.length; i++) {
      var at = variables[i].analysis_type || variables[i].type || '';
      if (textTypes.indexOf(at) >= 0) return variables[i].name || '';
    }
    var skip = ['identifier', 'date_time', 'metadata', 'structural', 'ignore'];
    for (var j = 0; j < variables.length; j++) {
      var t = variables[j].analysis_type || variables[j].type || '';
      if (skip.indexOf(t) < 0) return variables[j].name || '';
    }
    return variables.length > 0 ? (variables[0].name || '') : '';
  }

  function shortColLabel(name) {
    return name.length > 55 ? name.slice(0, 52) + '...' : name;
  }

  // ── Step rail ────────────────────────────────────────────────────────────
  function activateStep(stepId) {
    state.step = stepId;
    var steps = [].slice.call(document.querySelectorAll('.step'));
    var idx   = steps.findIndex(function (s) { return s.getAttribute('data-step') === stepId; });
    steps.forEach(function (s, i) {
      s.setAttribute('data-active', s.getAttribute('data-step') === stepId ? '1' : '0');
      s.setAttribute('data-done',   (idx > -1 && i < idx) ? '1' : '0');
    });
    render();
    renderCompanion();
  }

  // ── Companion ────────────────────────────────────────────────────────────
  var GUIDANCE = {
    input: '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">i</span> What it is</div>'
      + '<div class="cb-t">Provide the text you want to analyze. Paste responses one per line, or upload a file (Excel, CSV, TSV, JSON) using the upload widget.</div>'
      + '</div>'
      + '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">i</span> Cultural context</div>'
      + '<div class="cb-t">Describe who the respondents are and what setting the data comes from. This helps ReliCheck Intelligence interpret themes through the right lens rather than a generic default.</div>'
      + '</div>',
    themes: '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">i</span> What these are</div>'
      + '<div class="cb-t">These are <b>suggested themes</b> from a first-pass AI analysis. They are preliminary observations, not validated qualitative findings. Review the quote candidates to judge whether each theme reflects your data.</div>'
      + '</div>'
      + '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">i</span> What to do next</div>'
      + '<div class="cb-t">If the patterns look right, click <b>Quantify these themes</b> to get a theme presence matrix. Or export to Qualitative Studio for deeper, human-guided analysis.</div>'
      + '</div>'
      + '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">!</span> Remember</div>'
      + '<div class="cb-t">ReliCheck OpenText helps you see the patterns. Qualitative Studio helps you interpret and defend them. These are starting points, not conclusions.</div>'
      + '</div>',
    quantify: '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">i</span> What this is</div>'
      + '<div class="cb-t">Each response has been scored for preliminary theme presence. This turns qualitative patterns into measurable variables you can analyze alongside survey data.</div>'
      + '</div>'
      + '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">i</span> How to use it</div>'
      + '<div class="cb-t">Export the dataset to use in MM Studio or Inferential Studio. Theme frequency becomes a count variable. Theme presence per respondent is a binary (0/1) variable.</div>'
      + '</div>'
      + '<div class="comp-block">'
      + '<div class="cb-k"><span class="i">!</span> Caveat</div>'
      + '<div class="cb-t">Presence scores are preliminary. Review and validate before drawing quantitative conclusions.</div>'
      + '</div>',
  };

  function renderCompanion() {
    var tabs = document.getElementById('compTabs');
    var body = document.getElementById('compBody');
    if (!tabs || !body) return;

    tabs.innerHTML = ['explain', 'notes', 'intelligence'].map(function (t) {
      var label = t === 'intelligence' ? 'Intelligence' : t[0].toUpperCase() + t.slice(1);
      return '<button class="comp-tab' + (state.compTab === t ? ' active' : '') + '" '
        + 'onclick="setCompTab(\'' + t + '\')">' + label + '</button>';
    }).join('');

    if (state.compTab === 'notes') {
      body.innerHTML = '<div class="comp-block">'
        + '<div class="cb-k"><span class="i">&#9998;</span> Notes</div>'
        + '<textarea class="notes-area" placeholder="Jot decisions for this step..." '
        + 'oninput="window._taNotesSave(this.value)">'
        + esc(state.notes[state.step] || '') + '</textarea></div>';
      window._taNotesSave = function (v) { state.notes[state.step] = v; };
      return;
    }

    if (state.compTab === 'intelligence') {
      body.innerHTML = '<div class="comp-block">'
        + '<div class="cb-k" style="color:var(--acc-deep)"><span class="i">&#10022;</span> ReliCheck Intelligence</div>'
        + '<div class="ai-prompt">Ask about <b>' + esc(state.step) + '</b>, or pick a suggestion.</div>'
        + '<div class="ai-suggest">'
        + '<button class="ai-chip" onclick="window._taAi(\'what\')">What should I look for in these themes?</button>'
        + '<button class="ai-chip" onclick="window._taAi(\'limit\')">What are the limits of AI thematic analysis?</button>'
        + '<button class="ai-chip" onclick="window._taAi(\'next\')">How do I take this into Qualitative Studio?</button>'
        + '</div><div id="aiOut"></div></div>';
      window._taAi = function (kind) {
        var msg = kind === 'what'
          ? 'Look for themes that appear across many responses (high prominence) and check whether the quote candidates actually reflect the theme description. Be especially skeptical of broadly-named themes — they may mask more specific patterns.'
          : kind === 'limit'
          ? 'AI thematic analysis is pattern matching, not interpretation. It can surface word frequency and semantic proximity but cannot understand context, power dynamics, or what a response means to the person who wrote it. Use these themes as entry points, not conclusions.'
          : 'Export the themes as a draft from this step, then open Qualitative Studio and import the draft. You\'ll be able to accept, revise, merge, or reject each suggested theme before any coding begins.';
        var out = document.getElementById('aiOut');
        if (out) out.innerHTML = '<div class="ai-answer">' + esc(msg) + '</div>';
      };
      return;
    }

    var guidance = GUIDANCE[state.step]
      || '<p style="color:var(--ink-3)">Add guidance for the <b>' + esc(state.step) + '</b> step.</p>';
    body.innerHTML = guidance;
  }

  function setCompTab(t) { state.compTab = t; renderCompanion(); }
  function toggleCompanion() { document.body.classList.toggle('companion-collapsed'); }
  window.setCompTab      = setCompTab;
  window.toggleCompanion = toggleCompanion;

  // ── Step: Input ──────────────────────────────────────────────────────────
  function renderInput(host) {
    var responses = buildResponses();
    var count     = responses.length;

    // ── Paste panel ──
    var pastePanel = ''
      + '<textarea class="ta-paste-area" id="taPaste" placeholder="Paste responses here. One response per line works best for survey data. Paste a single document for interview transcripts or feedback reports.">'
      + esc(state.pasteText) + '</textarea>'
      + '<div class="ta-format-row">'
      + '<label for="taFormat">Format:</label>'
      + '<select id="taFormat">'
      + '<option value="lines"' + (state.pasteFormat === 'lines' ? ' selected' : '') + '>One response per line</option>'
      + '<option value="doc"'   + (state.pasteFormat === 'doc'   ? ' selected' : '') + '>Single document</option>'
      + '</select>'
      + '</div>';

    // ── Upload panel ──
    var uploadPanel;
    if (state.dataset) {
      var vars      = state.dataset.variables || [];
      var textTypes = ['open_ended', 'narrative', 'open'];
      var colOpts   = vars.map(function (v) {
        var name   = v.name || '';
        var at     = v.analysis_type || v.type || '';
        var isText = textTypes.indexOf(at) >= 0;
        var tag    = isText ? ' ✓ text' : '';
        return '<option value="' + esc(name) + '"' + (state.textColumn === name ? ' selected' : '') + '>'
          + esc(shortColLabel(name)) + esc(tag) + '</option>';
      }).join('');

      var colCount = buildResponses().length;
      var countHtml = colCount > 0
        ? '<div class="ta-dc-count good">&#10003; ' + colCount + ' response' + (colCount !== 1 ? 's' : '') + ' ready</div>'
        : '<div class="ta-dc-count empty">No text found in this column &mdash; try another</div>';

      uploadPanel = '<div class="ta-dataset-card">'
        + '<div class="ta-dc-head">'
        + '<div class="ta-dc-dot"></div>'
        + '<div class="ta-dc-title">' + esc(state.dataset.title || state.dataset.fileName || 'Dataset') + '</div>'
        + '<div class="ta-dc-meta">' + (state.dataset.rowCount || 0) + ' rows</div>'
        + '<button class="ta-dc-change" id="taChangeDataset">Change</button>'
        + '</div>'
        + '<div class="ta-dc-body">'
        + '<div class="ta-dc-label">Text column</div>'
        + '<select class="ta-dc-select" id="taTextCol">' + colOpts + '</select>'
        + countHtml
        + '</div>'
        + '</div>';
    } else {
      uploadPanel = '<div class="ta-upload-zone">'
        + '<div class="ta-uz-icon">&#8681;</div>'
        + '<div class="ta-uz-title">Upload a dataset</div>'
        + '<div class="ta-uz-types">Excel (.xlsx), CSV, TSV, or JSON<br>Qualtrics and Google Forms exports supported</div>'
        + '<div class="ta-uz-actions">'
        + '<button class="ta-uz-btn" id="taUploadBtn">Browse files</button>'
        + '<button class="ta-uz-saved" id="taOpenSavedBtn">Open saved dataset &rarr;</button>'
        + '</div>'
        + '</div>';
    }

    var countBar = (state.inputTab === 'paste' && count > 0)
      ? '<div class="ta-loaded-bar" style="margin-top:14px">'
        + '<div class="tl-dot"></div>'
        + '<span class="tl-count">' + count + ' response' + (count !== 1 ? 's' : '') + '</span>'
        + '<span class="tl-label">ready to analyze</span>'
        + '</div>'
      : '';

    host.innerHTML = ''
      + '<div class="ws-header">'
      + '<div class="ws-eyebrow">ReliCheck OpenText</div>'
      + '<h1 class="ws-title">See what might be <em>in your text.</em></h1>'
      + '<p class="ws-lede">Paste survey responses, feedback, or transcript text. Or upload a file. ReliCheck Intelligence surfaces emerging themes and patterns as a starting point for deeper analysis.</p>'
      + '</div>'

      + '<div class="ta-tabs">'
      + '<button class="ta-tab' + (state.inputTab === 'paste'  ? ' active' : '') + '" id="taTabPaste">Paste text</button>'
      + '<button class="ta-tab' + (state.inputTab === 'upload' ? ' active' : '') + '" id="taTabUpload">Upload file</button>'
      + '</div>'

      + '<div id="taInputPanel">'
      + (state.inputTab === 'paste' ? pastePanel : uploadPanel)
      + '</div>'

      + countBar

      + '<div class="ta-ctx-card">'
      + '<label class="ta-ctx-label" for="taContext">Cultural and organizational context <span style="font-weight:400;color:var(--ink-3)">(optional but recommended)</span></label>'
      + '<p class="ta-ctx-hint">Who are these respondents? What setting, community, or organization does this data come from?</p>'
      + '<textarea class="ta-ctx-field" id="taContext" rows="3" '
      + 'placeholder="Example: K-12 teachers in rural districts responding to questions about administrative workload...">'
      + esc(state.culturalContext) + '</textarea>'
      + '</div>'

      + '<div class="ta-analyze-row">'
      + '<button class="btn" id="taAnalyzeBtn"' + (count === 0 ? ' disabled' : '') + '>Analyze text &#8594;</button>'
      + (count > 0
          ? '<span class="ta-count-note">' + count + ' response' + (count !== 1 ? 's' : '') + '</span>'
          : '<span class="ta-count-note">' + (state.inputTab === 'upload' ? 'Upload a file or open a saved dataset above' : 'Paste responses above to continue') + '</span>')
      + '</div>';

    // ── Wire paste events ──
    document.getElementById('taTabPaste').addEventListener('click', function () {
      state.inputTab = 'paste'; renderInput(host);
    });
    document.getElementById('taTabUpload').addEventListener('click', function () {
      state.inputTab = 'upload'; renderInput(host);
    });

    if (state.inputTab === 'paste') {
      var pasteEl = document.getElementById('taPaste');
      pasteEl.addEventListener('input', function () {
        state.pasteText = pasteEl.value;
        syncAnalyzeBtn();
      });
      var fmtEl = document.getElementById('taFormat');
      fmtEl.addEventListener('change', function () {
        state.pasteFormat = fmtEl.value;
        syncAnalyzeBtn();
      });
    }

    // ── Wire upload events ──
    if (state.inputTab === 'upload') {
      var uploadBtn = document.getElementById('taUploadBtn');
      if (uploadBtn) {
        uploadBtn.addEventListener('click', function () {
          if (typeof DatasetUpload === 'undefined') { alert('Upload widget not loaded.'); return; }
          DatasetUpload.open({
            projectType: 'analysis',
            onLoaded: function (_err, datasetId) {
              fetchDataset(datasetId).then(function (ds) {
                if (!ds) { alert('Could not load dataset. Please try again.'); return; }
                state.dataset    = ds;
                state.textColumn = pickTextColumn(ds.variables);
                renderInput(host);
              });
            },
          });
        });
      }

      var savedBtn = document.getElementById('taOpenSavedBtn');
      if (savedBtn) {
        savedBtn.addEventListener('click', function () {
          if (typeof DatasetUpload === 'undefined') { alert('Upload widget not loaded.'); return; }
          DatasetUpload.openSaved({
            projectType: 'analysis',
            onLoaded: function (_err, datasetId) {
              fetchDataset(datasetId).then(function (ds) {
                if (!ds) { alert('Could not load dataset. Please try again.'); return; }
                state.dataset    = ds;
                state.textColumn = pickTextColumn(ds.variables);
                renderInput(host);
              });
            },
          });
        });
      }

      var changeBtn = document.getElementById('taChangeDataset');
      if (changeBtn) {
        changeBtn.addEventListener('click', function () {
          state.dataset    = null;
          state.textColumn = '';
          renderInput(host);
        });
      }

      var colEl = document.getElementById('taTextCol');
      if (colEl) {
        colEl.addEventListener('change', function () {
          state.textColumn = colEl.value;
          renderInput(host);
        });
      }
    }

    // ── Context field ──
    var ctxEl = document.getElementById('taContext');
    if (ctxEl) {
      ctxEl.addEventListener('input', function () { state.culturalContext = ctxEl.value; });
    }

    // ── Analyze button ──
    var analyzeBtn = document.getElementById('taAnalyzeBtn');
    if (analyzeBtn) {
      analyzeBtn.addEventListener('click', function () {
        var r = buildResponses();
        if (r.length === 0) return;
        state.responses = r;
        state.culturalContext = (document.getElementById('taContext') || {}).value || state.culturalContext;
        analyzeThemes();
      });
    }
  }

  function syncAnalyzeBtn() {
    var btn   = document.getElementById('taAnalyzeBtn');
    var note  = document.getElementById('taCountNote');
    var count = buildResponses().length;
    if (btn) btn.disabled = count === 0;
    if (note) note.textContent = count > 0 ? count + ' response' + (count !== 1 ? 's' : '') : '';
  }

  // ── Step: Suggested Themes ───────────────────────────────────────────────
  function renderThemes(host) {
    if (state.analyzing) {
      host.innerHTML = '<div class="ta-loading">'
        + '<div class="tl-spinner"></div>'
        + '<p>Analyzing text with ReliCheck Intelligence...</p>'
        + '<p class="tl-note">This may take up to 30 seconds for larger datasets.</p>'
        + '</div>';
      return;
    }

    if (!state.themes) {
      host.innerHTML = '<div style="padding:32px;text-align:center;color:var(--ink-3)">'
        + '<p>No analysis yet. Go to <strong>Input</strong> and click Analyze text.</p>'
        + '</div>';
      return;
    }

    var d = state.themes;

    var sampledNote = d.sampled
      ? '<div style="background:#fff8ee;border:1px solid #fed7aa;border-radius:10px;padding:11px 14px;'
        + 'font-size:12.5px;color:#92400e;margin-bottom:16px">&#9432; Your dataset has '
        + d.response_count + ' responses. Themes were identified from a sample of '
        + d.analyzed_count + '.</div>'
      : '';

    var themeCards = d.themes.map(function (t, i) {
      var promClass = 'tc-prom-' + t.prominence;
      var promLabel = t.prominence.charAt(0).toUpperCase() + t.prominence.slice(1) + ' prominence';
      var quotes = t.quotes.map(function (q) {
        return '<div class="ta-quote-candidate">&ldquo;' + esc(q) + '&rdquo;</div>';
      }).join('');
      return '<div class="ta-theme-card">'
        + '<div class="tc-head">'
        + '<div class="tc-name">Suggested theme ' + (i + 1) + ': ' + esc(t.name) + '</div>'
        + '<span class="tc-prom ' + promClass + '">' + esc(promLabel) + '</span>'
        + '</div>'
        + '<div class="tc-desc">' + esc(t.description) + '</div>'
        + (quotes ? '<div class="tc-quotes-label">Quote candidates</div><div class="tc-quotes">' + quotes + '</div>' : '')
        + (t.prominence_note ? '<div class="tc-note">&#9432; ' + esc(t.prominence_note) + '</div>' : '')
        + '</div>';
    }).join('');

    var counterItems = (d.counter_patterns || []).map(function (cp) {
      return '<div class="ta-counter-item">' + esc(cp) + '</div>';
    }).join('');

    host.innerHTML = ''
      + '<div class="ta-step-head">'
      + '<h1>Suggested Themes</h1>'
      + '<p>Emerging patterns from ' + d.analyzed_count + ' response' + (d.analyzed_count !== 1 ? 's' : '') + '. Preliminary first pass only.</p>'
      + '</div>'

      + '<div class="ta-preliminary-badge">&#10022; Preliminary &mdash; needs review</div>'

      + sampledNote

      + (d.summary ? '<div class="ta-summary-card">'
        + '<div class="ts-label">Preliminary summary</div>'
        + '<p>' + esc(d.summary) + '</p>'
        + '</div>' : '')

      + '<div class="ta-themes-grid">' + themeCards + '</div>'

      + (counterItems ? '<div class="ta-counter-card">'
        + '<div class="tc-label">Counter-patterns and complications</div>'
        + '<div class="ta-counter-list">' + counterItems + '</div>'
        + '</div>' : '')

      + '<div class="ta-transfer-row">'
      + '<span class="tr-label">Next:</span>'
      + '<button class="btn" id="taQuantifyBtn">Quantify these themes &#8594;</button>'
      + '<button class="btn btn-ghost" id="taSaveThemesBtn">Save this analysis</button>'
      + '<button class="btn btn-ghost" id="taReanalyzeBtn">&#8592; Re-analyze</button>'
      + '</div>';

    document.getElementById('taQuantifyBtn').addEventListener('click', quantifyThemes);
    document.getElementById('taSaveThemesBtn').addEventListener('click', function () { showSaveModal(); });
    document.getElementById('taReanalyzeBtn').addEventListener('click', function () { activateStep('input'); });
  }

  // ── Step: Quantify ───────────────────────────────────────────────────────
  function renderQuantify(host) {
    if (state.quantifying) {
      host.innerHTML = '<div class="ta-loading">'
        + '<div class="tl-spinner"></div>'
        + '<p>Scoring theme presence with ReliCheck Intelligence...</p>'
        + '<p class="tl-note">This takes a moment for large response sets.</p>'
        + '</div>';
      return;
    }

    if (!state.matrix) {
      host.innerHTML = '<div style="padding:32px;text-align:center;color:var(--ink-3)">'
        + '<p>No quantification yet. Go to <strong>Suggested Themes</strong> and click Quantify.</p>'
        + '</div>';
      return;
    }

    var d          = state.matrix;
    var themeNames = d.theme_names;

    var thHeaders = themeNames.map(function (n) {
      return '<th title="' + esc(n) + '">' + esc(n.length > 18 ? n.slice(0, 16) + '...' : n) + '</th>';
    }).join('');

    var dataRows = d.matrix.map(function (row) {
      var cells = themeNames.map(function (name) {
        return row.theme_presence[name]
          ? '<td><span class="ta-present">&#10003;</span></td>'
          : '<td><span class="ta-absent">&ndash;</span></td>';
      }).join('');
      return '<tr><td>' + esc(row.preview) + '</td>' + cells + '</tr>';
    }).join('');

    var freqStats = themeNames.map(function (name) {
      var n   = d.frequencies[name] || 0;
      var pct = d.scored_count > 0 ? Math.round(100 * n / d.scored_count) : 0;
      return '<div class="ta-stat">'
        + '<div class="tst-n">' + n + '</div>'
        + '<div class="tst-l">' + esc(name.length > 20 ? name.slice(0, 18) + '...' : name) + ' (' + pct + '%)</div>'
        + '</div>';
    }).join('');

    host.innerHTML = ''
      + '<div class="ta-step-head">'
      + '<h1>Theme Presence by Response</h1>'
      + '<p>Preliminary presence scores for ' + d.scored_count + ' response' + (d.scored_count !== 1 ? 's' : '') + ' across ' + themeNames.length + ' suggested theme' + (themeNames.length !== 1 ? 's' : '') + '.</p>'
      + '</div>'

      + '<div class="ta-preliminary-badge">&#10022; Preliminary &mdash; review before use in analysis</div>'

      + '<div class="ta-matrix-card">'
      + '<div class="ta-matrix-head">'
      + '<div><h3>Presence matrix</h3><p>&#10003; = present &nbsp;&nbsp; &ndash; = not detected</p></div>'
      + '</div>'
      + '<div class="ta-matrix-wrap">'
      + '<table class="ta-matrix-table">'
      + '<thead><tr><th>Response preview</th>' + thHeaders + '</tr></thead>'
      + '<tbody>' + dataRows + '</tbody>'
      + '</table>'
      + '</div>'
      + '<div class="ta-stats-row">' + freqStats + '</div>'
      + '</div>'

      + '<div class="ta-transfer-row">'
      + '<button class="btn" id="taSaveMatrixBtn">Save this analysis</button>'
      + '<button class="btn btn-ghost" id="taBackThemesBtn">&#8592; Back to themes</button>'
      + '</div>';

    document.getElementById('taSaveMatrixBtn').addEventListener('click', function () { showSaveModal(); });
    document.getElementById('taBackThemesBtn').addEventListener('click', function () { activateStep('themes'); });
  }

  // ── API calls ────────────────────────────────────────────────────────────
  function analyzeThemes() {
    state.analyzing = true;
    state.themes    = null;
    activateStep('themes');

    fetch('/api/text/analyze-themes.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify({
        responses:        state.responses,
        cultural_context: state.culturalContext,
      }),
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        state.analyzing = false;
        if (!d.ok) { showError('themes', d.message || 'Analysis failed. Please try again.'); return; }
        state.themes = d.data;
        render();
      })
      .catch(function () {
        state.analyzing = false;
        showError('themes', 'Could not reach the server. Please check your connection and try again.');
      });
  }

  function quantifyThemes() {
    if (!state.themes || !state.themes.themes || !state.themes.themes.length) return;
    state.quantifying = true;
    state.matrix      = null;
    activateStep('quantify');

    fetch('/api/text/quantify-themes.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify({
        themes:           state.themes.themes.map(function (t) { return { name: t.name, description: t.description }; }),
        responses:        state.responses,
        cultural_context: state.culturalContext,
      }),
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        state.quantifying = false;
        if (!d.ok) { showError('quantify', d.message || 'Quantification failed. Please try again.'); return; }
        state.matrix = d.data;
        render();
      })
      .catch(function () {
        state.quantifying = false;
        showError('quantify', 'Could not reach the server. Please check your connection and try again.');
      });
  }

  function showError(step, msg) {
    if (state.step !== step) activateStep(step);
    var el = center();
    if (!el) return;
    el.innerHTML = '<div class="ta-err">&#9888; ' + esc(msg) + '</div>'
      + '<button class="btn btn-ghost" onclick="window._taBack()">&#8592; Back to Input</button>';
    window._taBack = function () { activateStep('input'); };
  }

  // ── Save modal ───────────────────────────────────────────────────────────
  function showSaveModal() {
    if (!state.themes) return;
    var hasMatrix   = !!(state.matrix && state.matrix.matrix && state.matrix.matrix.length);
    var themeCount  = state.themes.themes ? state.themes.themes.length : 0;
    var respCount   = state.responses.length;

    var existing = document.getElementById('taSaveModal');
    if (existing) existing.remove();

    var defaultTitle = state.dataset
      ? (state.dataset.title || 'OpenText Analysis')
      : 'OpenText Analysis';

    var el = document.createElement('div');
    el.id = 'taSaveModal';
    el.className = 'ta-modal-backdrop';
    el.innerHTML = ''
      + '<div class="ta-modal-box" role="dialog" aria-modal="true">'
      + '<div class="ta-modal-head">'
      + '<h2>Save this analysis</h2>'
      + '<button class="ta-modal-close" id="taSaveClose" aria-label="Close">&times;</button>'
      + '</div>'
      + '<div class="ta-modal-body">'
      + '<label class="ta-modal-label" for="taSaveTitle">Title</label>'
      + '<input class="ta-modal-input" id="taSaveTitle" type="text" value="' + esc(defaultTitle) + '" placeholder="Give this analysis a name" />'
      + '<div class="ta-modal-summary">'
      + '<div class="ta-ms-row"><span class="ta-ms-n">' + themeCount + '</span><span class="ta-ms-l">suggested theme' + (themeCount !== 1 ? 's' : '') + '</span></div>'
      + '<div class="ta-ms-row"><span class="ta-ms-n">' + respCount + '</span><span class="ta-ms-l">response' + (respCount !== 1 ? 's' : '') + '</span></div>'
      + (hasMatrix ? '<div class="ta-ms-row"><span class="ta-ms-n">&#10003;</span><span class="ta-ms-l">theme presence scores included</span></div>' : '')
      + '</div>'
      + '<p class="ta-modal-note">Saved analyses appear in your projects and can be opened in any ReliCheck studio.</p>'
      + '</div>'
      + '<div class="ta-modal-foot">'
      + '<button class="btn" id="taSaveConfirm">Save analysis</button>'
      + '<button class="btn btn-ghost" id="taSaveCancel">Cancel</button>'
      + '</div>'
      + '</div>';

    document.body.appendChild(el);

    function close() { el.remove(); }
    document.getElementById('taSaveClose').addEventListener('click', close);
    document.getElementById('taSaveCancel').addEventListener('click', close);
    el.addEventListener('click', function (e) { if (e.target === el) close(); });

    var titleInput = document.getElementById('taSaveTitle');
    titleInput.select();

    document.getElementById('taSaveConfirm').addEventListener('click', function () {
      var title = (titleInput.value || '').trim() || 'OpenText Analysis';
      close();
      saveAnalysis(title);
    });
  }

  function saveAnalysis(title) {
    var saveBtn = document.querySelector('[id^="taSave"]');
    // Show saving state on current step
    var el = center();
    if (el) {
      el.insertAdjacentHTML('afterbegin',
        '<div class="ta-saving-bar" id="taSavingBar">'
        + '<div class="tl-spinner" style="width:18px;height:18px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:10px"></div>'
        + 'Saving analysis...'
        + '</div>');
    }

    fetch('/api/text/save-analysis.php', {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify({
        title:            title,
        responses:        state.responses,
        themes:           state.themes ? state.themes.themes : [],
        matrix:           state.matrix || null,
        cultural_context: state.culturalContext,
      }),
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var bar = document.getElementById('taSavingBar');
        if (bar) bar.remove();
        if (!d.ok) {
          alert('Could not save: ' + (d.message || 'Unknown error'));
          return;
        }
        showSaveSuccess(d.dataset_id, d.title, d.row_count);
      })
      .catch(function () {
        var bar = document.getElementById('taSavingBar');
        if (bar) bar.remove();
        alert('Could not reach the server. Please try again.');
      });
  }

  function showSaveSuccess(datasetId, title, rowCount) {
    var el = center();
    if (!el) return;

    var dsParam = '?dataset_id=' + datasetId;

    el.innerHTML = ''
      + '<div class="ta-success-card">'
      + '<div class="ta-sc-check">&#10003;</div>'
      + '<h2 class="ta-sc-title">Analysis saved</h2>'
      + '<p class="ta-sc-name">' + esc(title) + '</p>'
      + '<p class="ta-sc-meta">' + rowCount + ' rows &middot; saved to your projects</p>'
      + '</div>'

      + '<div class="ta-open-grid">'
      + '<div class="ta-og-label">Open in a studio</div>'
      + '<div class="ta-og-cards">'
      + studioOpenCard('/qual-studio-workspaceV3.php' + dsParam, 'Qualitative Studio', 'Code themes, build a codebook, and validate findings.')
      + studioOpenCard('/mmstudioV4.php'              + dsParam, 'MM Studio',           'Connect these themes to survey scores and group comparisons.')
      + studioOpenCard('/studio-da.php'               + dsParam, 'Descriptive Studio',  'See theme frequency distributions and cross-tabs.')
      + studioOpenCard('/studio-is.php'               + dsParam, 'Inferential Studio',  'Test whether theme presence differs by group.')
      + '</div>'
      + '</div>'

      + '<div class="ta-sc-footer">'
      + '<a class="btn" href="/app-2026v4.php">Go to your projects &rarr;</a>'
      + '<button class="btn btn-ghost" id="taNewAnalysisBtn">Start a new analysis</button>'
      + '</div>';

    document.getElementById('taNewAnalysisBtn').addEventListener('click', function () {
      state.themes    = null;
      state.matrix    = null;
      state.responses = [];
      activateStep('input');
    });
  }

  function studioOpenCard(href, name, desc) {
    return '<a class="ta-og-card" href="' + esc(href) + '">'
      + '<div class="ta-og-name">' + esc(name) + '</div>'
      + '<div class="ta-og-desc">' + esc(desc) + '</div>'
      + '<span class="ta-og-arrow">Open &rarr;</span>'
      + '</a>';
  }

  // ── Render ───────────────────────────────────────────────────────────────
  function render() {
    var el = center();
    if (!el) return;
    switch (state.step) {
      case 'input':    renderInput(el);    break;
      case 'themes':   renderThemes(el);   break;
      case 'quantify': renderQuantify(el); break;
      default:
        el.innerHTML = '<p style="color:var(--ink-3)">Unknown step: ' + esc(state.step) + '</p>';
    }
  }

  // ── Init ─────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {

    StudioHeader.init({
      logoSrc:      '/OpenText-long.png',
      logoAlt:      'ReliCheck OpenText',
      logoHeight:   70,
      projectLabel: 'ReliCheck OpenText',
      projectLive:  false,
      projectsUrl:  '/app-2026v4.php',
      initials:     BOOT.initials,
    });

    StudioFooter.init();

    document.querySelectorAll('.step').forEach(function (btn) {
      btn.addEventListener('click', function () { activateStep(btn.getAttribute('data-step')); });
    });

    activateStep(BOOT.initialStep || 'input');
  });

})();
