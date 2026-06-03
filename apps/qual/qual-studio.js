/* Qualitative Analysis Studio — workspace controller
 * Follows the studio template contract:
 *   StudioHeader → rail (numbered steps, data-done/data-active) →
 *   renderStart / renderOverview / renderDataMap / renderWork / renderReport
 *   → StudioFooter
 */
'use strict';

(function () {

  var CHECK = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  var state = {
    stepId:            'start',
    compTab:           'guidance',
    project:           BOOT.project,
    projectData:       null,   // { seg_count, doc_count, total_words, avg_words, code_count, docs[] }
    datamapConfirmed:  false,
    _datamapMounted:   false,
    codes:             null,
    segments:          null,
  };

  // ── Pipeline helpers ───────────────────────────────────────────────────────
  function steps()      { return BOOT.pipeline; }
  function activeStep() { return steps().find(function(s){ return s.id === state.stepId; }) || steps()[0]; }
  function stepIndex()  { return steps().findIndex(function(s){ return s.id === state.stepId; }); }

  function hasData() {
    return state.projectData && (state.projectData.seg_count > 0);
  }

  // Steps before the first work step that are considered "infrastructure" gates
  var GATE_STEPS = ['start', 'overview', 'datamap'];

  function isWorkUnlocked() {
    return state.datamapConfirmed && hasData();
  }

  // ── API ────────────────────────────────────────────────────────────────────
  function api(path, opts) {
    opts = opts || {};
    return fetch(path, Object.assign({ headers: { 'Content-Type': 'application/json' } }, opts))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.message || d.error || 'API error');
        return d;
      });
  }

  // ── Render ─────────────────────────────────────────────────────────────────
  function render() {
    renderRail();
    renderCenter();
    renderCompanion();
  }

  function renderRail() {
    var rail = document.getElementById('rail');
    if (!rail) return;
    var idx = stepIndex();
    rail.innerHTML = '<div class="rail-h">Qualitative Analysis</div>'
      + steps().map(function (s, i) {
        var active = s.id === state.stepId ? '1' : '0';
        // Done: all steps before current gate steps are done if data loaded;
        // work steps done if unlocked and before current index
        var done = '0';
        if (i < idx) {
          if (GATE_STEPS.indexOf(s.id) !== -1) {
            done = (s.id === 'start' || (s.id === 'overview' && hasData()) || (s.id === 'datamap' && state.datamapConfirmed)) ? '1' : '0';
          } else {
            done = isWorkUnlocked() ? '1' : '0';
          }
        }
        var soon = s.soon ? '<span class="step-soon">Soon</span>' : '';
        var disabled = (s.soon || (GATE_STEPS.indexOf(s.id) === -1 && !isWorkUnlocked() && s.id !== 'start'))
          ? ' disabled' : '';
        return '<button class="step" data-active="' + active + '" data-done="' + done + '" data-step="' + esc(s.id) + '"' + disabled + '>'
          + '<span class="num">' + (done === '1' ? CHECK : (i + 1)) + '</span>'
          + '<span class="lbl">' + esc(s.label) + '</span>'
          + soon
          + '<span class="tick">' + CHECK + '</span>'
          + '</button>';
      }).join('');

    rail.querySelectorAll('.step:not([disabled])').forEach(function (b) {
      b.addEventListener('click', function () {
        state.stepId = b.getAttribute('data-step');
        render();
      });
    });
  }

  function renderCenter() {
    var host = document.getElementById('centerInner');
    if (!host) return;
    var s = activeStep();
    if (s.mode === 'start')    return renderStart(host);
    if (s.mode === 'overview') return renderOverview(host);
    if (s.mode === 'datamap')  return renderDataMap(host);
    if (s.mode === 'report')   return renderReport(host);
    return renderWork(host, s);
  }

  function renderCompanion() {
    var body = document.getElementById('compBody');
    if (!body) return;
    var s = activeStep();
    var guidance = GUIDANCE[s.id] || '<p>Select a step from the left rail.</p>';
    body.innerHTML = guidance;
    document.querySelectorAll('.comp-tab').forEach(function (t) {
      t.addEventListener('click', function () {
        state.compTab = t.getAttribute('data-tab');
        document.querySelectorAll('.comp-tab').forEach(function (x) { x.classList.remove('on'); });
        t.classList.add('on');
        var content = state.compTab === 'guidance' ? guidance
          : state.compTab === 'notes' ? '<p style="color:var(--ink-3)">Notes coming soon.</p>'
          : '<p style="color:var(--ink-3)">ReliCheck Intelligence suggestions appear here as you work.</p>';
        body.innerHTML = content;
      });
    });
  }

  // ── Start ──────────────────────────────────────────────────────────────────
  function renderStart(host) {
    var segCount = state.projectData ? state.projectData.seg_count : 0;

    var html = '<div class="ws-header">'
      + '<div class="eyebrow">Step 1</div>'
      + '<h1 class="title">Data Intake</h1>'
      + '<p class="lede">Upload a CSV or XLSX with open-ended responses, or open an existing project below.</p>'
      + '</div>';

    if (BOOT.projectId && segCount > 0) {
      html += '<div class="begin-loaded"><span class="dot"></span>'
        + '<span style="font-weight:700;color:var(--ink-2)">Data loaded</span>'
        + '<span>' + esc(BOOT.projectLabel) + ' &middot; ' + segCount + ' segments</span>'
        + '<button class="btn primary" style="margin-left:auto" id="stOverview">Go to Overview &rarr;</button>'
        + '</div>';
    }

    html += '<button class="begin-feature" id="stUpload">'
      + '<span class="bc-ico">&#8681;</span>'
      + '<div><h4>Upload qualitative data</h4>'
      + '<p>CSV or XLSX with open-ended responses. The studio detects text columns and loads each response as a codeable segment with participant context.</p>'
      + '<span class="bc-go">Upload data &rarr;</span></div></button>'
      + '<div class="begin-sec">Or</div>'
      + '<div class="begin-grid2">'
      + '<button class="begin-card2" id="stProjects"><span class="bc-ico" style="font-size:18px">&#9638;</span><h4>Open a saved project</h4><p>Return to the project list to open a previous analysis.</p></button>'
      + '</div>';

    host.innerHTML = html;

    var ov = document.getElementById('stOverview');
    if (ov) ov.addEventListener('click', function () { state.stepId = 'overview'; render(); });

    var upload = document.getElementById('stUpload');
    if (upload) upload.addEventListener('click', openUpload);

    var projects = document.getElementById('stProjects');
    if (projects) projects.addEventListener('click', function () { window.location.href = '/qual-studio.php'; });
  }

  function openUpload() {
    if (typeof DatasetUpload === 'undefined') { alert('Upload widget not loaded.'); return; }
    DatasetUpload.open({
      projectType: 'rssi',  // returns datasetId directly; we link via qual/link-dataset.php
      onLoaded: function (_err, datasetId) {
        var notice = document.getElementById('centerInner');
        if (notice) notice.innerHTML = '<div class="notice info">Linking dataset and loading segments...</div>';

        function linkAndContinue(projectId) {
          api('/api/qual/link-dataset.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: projectId, dataset_id: datasetId }),
          }).then(function (r) {
            if (projectId !== BOOT.projectId) {
              // New project: redirect so the URL carries the project_id and refresh works.
              window.location.href = '/qual-studio-workspace.php?project_id=' + projectId + '&step=overview';
              return;
            }
            StudioFooter.setDataInfo(r.seg_count, 1);
            loadProjectData().then(function () { state.stepId = 'overview'; render(); });
          }).catch(function (e) {
            if (notice) notice.innerHTML = '<div class="notice err">Could not link dataset: ' + esc(e.message) + '</div>';
          });
        }

        if (!BOOT.projectId) {
          // No project yet — auto-create one, then link. User renames it in Project Setup.
          api('/api/qual/create-project.php', {
            method: 'POST',
            body: JSON.stringify({ title: 'Qualitative Analysis', analysis_approach: 'thematic' }),
          }).then(function (r) {
            linkAndContinue(r.project_id);
          }).catch(function (e) {
            if (notice) notice.innerHTML = '<div class="notice err">Could not create project: ' + esc(e.message) + '</div>';
          });
        } else {
          linkAndContinue(BOOT.projectId);
        }
      },
    });
  }

  // ── Overview ───────────────────────────────────────────────────────────────
  function renderOverview(host) {
    if (!hasData()) {
      host.innerHTML = '<div class="ws-header"><div class="eyebrow">Qualitative Analysis Studio</div>'
        + '<h1 class="title">Overview</h1></div>'
        + '<div class="placeholder">No data yet. Go to <strong>Start</strong> to upload a file.</div>';
      return;
    }
    var d = state.projectData;
    var p = state.project || {};
    var approachLabels = {
      thematic:'Thematic Analysis', content:'Content Analysis',
      framework:'Framework Analysis', open_ended_survey:'Open-Ended Survey Analysis',
      document:'Document Analysis',
    };
    var approach = approachLabels[p.analysis_approach] || (p.analysis_approach || '');

    var docsHtml = (d.documents || []).map(function (doc) {
      return '<tr><td style="font-weight:600">' + esc(doc.title) + '</td>'
        + '<td>' + esc(doc.source_type) + '</td></tr>';
    }).join('');

    host.innerHTML = '<div class="ws-header"><div class="eyebrow">Qualitative Analysis Studio</div>'
      + '<h1 class="title">Overview</h1>'
      + '<p class="lede">Confirm what was loaded before mapping variables and beginning analysis.</p></div>'
      + '<div class="panel"><div class="panel-h"><h3>Dataset</h3></div><div class="panel-b">'
      + '<div class="stat-row">'
      + _stat(d.seg_count,   'Segments') + _stat(d.doc_count, 'Sources')
      + _stat(d.total_words, 'Words')    + _stat(d.avg_words,  'Avg words / segment')
      + '</div>'
      + '<p style="margin:14px 0 0;color:var(--ink-3);font-size:13px">Project: ' + esc(BOOT.projectLabel)
      + (approach ? ' &middot; ' + esc(approach) : '') + '</p>'
      + '</div></div>'
      + (docsHtml ? '<div class="panel"><div class="panel-h"><h3>Data sources</h3></div><div class="panel-b">'
        + '<table style="width:100%;font-size:13.5px;border-collapse:collapse">'
        + '<thead><tr style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3)">'
        + '<th style="text-align:left;padding:0 0 8px">Source</th><th style="text-align:left;padding:0 0 8px">Type</th></tr></thead>'
        + '<tbody>' + docsHtml + '</tbody></table></div></div>' : '')
      + '<div style="margin-top:6px"><button class="btn primary" id="ovGo">Map variables &rarr;</button></div>';

    var go = document.getElementById('ovGo');
    if (go) go.addEventListener('click', function () { state.stepId = 'datamap'; render(); });
  }

  // ── Variable Map ───────────────────────────────────────────────────────────
  function renderDataMap(host) {
    if (!hasData()) {
      host.innerHTML = '<div class="ws-header"><div class="eyebrow">Qualitative Analysis Studio</div>'
        + '<h1 class="title">Variable Map</h1></div>'
        + '<div class="placeholder">No data yet. Go to <strong>Start</strong> to upload a file.</div>';
      return;
    }
    if (!window.DataMap) {
      host.innerHTML = '<div class="notice warn">DataMap component not loaded. Please refresh.</div>';
      return;
    }
    // Build the rawVars array from what we know about the linked dataset
    // DataMap mounts into a container div; we load variable_metadata via the shared component.
    host.innerHTML = '<div class="ws-header"><div class="eyebrow">Qualitative Analysis Studio</div>'
      + '<h1 class="title">Variable Map</h1>'
      + '<p class="lede">Confirm which columns are open-ended text, which are group variables, and which are participant metadata. Coding is only available after you confirm this map.</p></div>'
      + '<div id="dmContainer"></div>'
      + '<div style="margin-top:20px" id="dmConfirmBar"></div>';

    var container = document.getElementById('dmContainer');
    var confirmBar = document.getElementById('dmConfirmBar');

    if (state._datamapMounted && DataMap.mount) {
      DataMap.mount(container);
    } else {
      // Load variable_metadata for this project via the analysis endpoint
      // For qual projects, the dataset columns come from the linked dataset.
      // We use a lightweight variable list from the project data.
      var d = state.projectData;
      // DataMap needs rawVars: [{name, values}]. We proxy through the dataset's column_meta.
      fetch('/api/qual/get-variable-meta.php?project_id=' + BOOT.projectId)
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (!resp.ok || !resp.variables || !resp.variables.length) {
            container.innerHTML = '<div class="notice warn">No variable metadata found. Re-upload your data to regenerate it.</div>';
            showConfirmBar(confirmBar, true);
            return;
          }
          DataMap.init({
            projectId:   BOOT.projectId,
            projectType: 'qual',
            rcProjectId: resp.rc_project_id || null,
            rawVars:     resp.variables,
            datasetId:   resp.dataset_id,
            onSaved: function () { showConfirmBar(confirmBar, false); },
          });
          DataMap.mount(container);
          state._datamapMounted = true;
          showConfirmBar(confirmBar, false);
        })
        .catch(function (e) {
          container.innerHTML = '<div class="notice err">Could not load variable map: ' + esc(e.message) + '</div>';
          showConfirmBar(confirmBar, true);
        });
    }

    function showConfirmBar(el, skipMap) {
      el.innerHTML = skipMap
        ? '<button class="btn primary" id="dmConfirm">Confirm and continue &rarr;</button>'
        : '<button class="btn primary" id="dmConfirm">Confirm variable map &rarr;</button>';
      var btn = document.getElementById('dmConfirm');
      if (btn) btn.addEventListener('click', function () {
        state.datamapConfirmed = true;
        state.stepId = 'setup';
        render();
      });
    }
  }

  // ── Work steps ─────────────────────────────────────────────────────────────
  function renderWork(host, step) {
    if (!isWorkUnlocked()) {
      host.innerHTML = '<div class="ws-header"><div class="eyebrow">Qualitative Analysis Studio</div>'
        + '<h1 class="title">' + esc(step.label) + '</h1></div>'
        + '<div class="placeholder">Complete <strong>Start</strong>, <strong>Overview</strong>, and <strong>Variable Map</strong> before proceeding to analysis steps.</div>';
      return;
    }
    var tool = step.tool || step.id;
    var fn = WorkModules[tool];
    if (fn) return fn(host, step);
    host.innerHTML = '<div class="ws-header"><div class="eyebrow">Qualitative Analysis Studio</div>'
      + '<h1 class="title">' + esc(step.label) + '</h1></div>'
      + '<div class="placeholder">This step is coming in a future phase.</div>';
  }

  // ── Report ─────────────────────────────────────────────────────────────────
  function renderReport(host) {
    host.innerHTML = '<div class="ws-header"><div class="eyebrow">Qualitative Analysis Studio</div>'
      + '<h1 class="title">Report Builder</h1>'
      + '<p class="lede">Report generation is coming in a future phase. Your codes, themes, and quotes will appear here.</p></div>'
      + '<div class="placeholder">Not yet built.</div>';
  }

  // ── Work modules ───────────────────────────────────────────────────────────
  var WorkModules = {

    setup: function (host) {
      var p = state.project || {};
      host.innerHTML = '<div class="ws-header"><div class="eyebrow">Step 4</div>'
        + '<h1 class="title">Project Setup</h1>'
        + '<p class="lede">Define your research question, approach, and researcher stance before coding begins.</p></div>'
        + '<div class="panel"><div class="panel-h"><h3>Project information</h3></div><div class="panel-b">'
        + '<div class="field"><label>Project title <span style="color:#c0392b">*</span></label>'
        + '<input id="su-title" value="' + esc(p.title || '') + '" placeholder="e.g. Staff Experience Open-Ends 2026"></div>'
        + '<div class="grid2">'
        + '<div class="field"><label>Analysis approach</label><select id="su-approach">'
        + ['thematic','content','framework','open_ended_survey','document'].map(function (v) {
            var labels = {thematic:'Thematic Analysis',content:'Content Analysis',framework:'Framework Analysis',open_ended_survey:'Open-Ended Survey Analysis',document:'Document Analysis'};
            return '<option value="' + v + '"' + ((p.analysis_approach||'thematic')===v?' selected':'') + '>' + labels[v] + '</option>';
          }).join('')
        + '</select></div>'
        + '<div class="field"><label>Data type</label><select id="su-datatype">'
        + [['open_ended_survey','Open-Ended Survey'],['interview','Interview Transcript'],['focus_group','Focus Group Transcript'],['document','Document / Field Notes']].map(function (x) {
            return '<option value="' + x[0] + '"' + ((p.data_type||'open_ended_survey')===x[0]?' selected':'') + '>' + x[1] + '</option>';
          }).join('')
        + '</select></div></div>'
        + '<div class="field"><label>Research question<span class="hint">What are you trying to understand through this analysis?</span></label>'
        + '<input id="su-rq" value="' + esc(p.research_question || '') + '" placeholder="What are participants saying about..."></div>'
        + '<div class="field"><label>Purpose<span class="hint">Who will use these findings, and for what decision?</span></label>'
        + '<input id="su-purpose" value="' + esc(p.purpose || '') + '" placeholder="To inform the 2026 program redesign..."></div>'
        + '<div class="field"><label>Participant description</label>'
        + '<input id="su-participants" value="' + esc(p.participant_description || '') + '" placeholder="e.g. 412 K-12 educators across 3 districts"></div>'
        + '</div></div>'
        + '<div class="panel"><div class="panel-h"><h3>Researcher stance memo <span style="font-size:12px;font-weight:400;color:var(--ink-3)">saved to audit trail</span></h3></div><div class="panel-b">'
        + '<div class="field"><label><span class="hint">What assumptions, roles, or experiences might shape how you interpret this data? Being transparent about this is part of what makes qualitative findings defensible.</span></label>'
        + '<textarea id="su-stance" style="min-height:110px;" placeholder="I am an external evaluator...">' + esc(p.researcher_stance_memo || '') + '</textarea></div>'
        + '</div></div>'
        + '<div id="su-msg" style="display:none;font-size:13px;margin-top:4px;"></div>'
        + '<div class="btn-row"><button class="btn primary" id="su-save">Save setup</button>'
        + '<button class="btn" id="su-next">Next: Familiarization</button></div>';

      document.getElementById('su-save').addEventListener('click', function () {
        var msg  = document.getElementById('su-msg');
        var body = {
          project_id:             BOOT.projectId,
          title:                  document.getElementById('su-title').value.trim(),
          analysis_approach:      document.getElementById('su-approach').value,
          data_type:              document.getElementById('su-datatype').value,
          research_question:      document.getElementById('su-rq').value.trim(),
          purpose:                document.getElementById('su-purpose').value.trim(),
          participant_description:document.getElementById('su-participants').value.trim(),
          researcher_stance_memo: document.getElementById('su-stance').value.trim(),
        };
        if (!body.title) { msg.textContent='Title is required.'; msg.style.cssText='display:block;color:#c0392b;'; return; }
        msg.textContent='Saving...'; msg.style.cssText='display:block;color:var(--ink-3);';
        api('/api/qual/save-project.php', { method:'POST', body:JSON.stringify(body) })
          .then(function () {
            Object.assign(state.project || (state.project = {}), body);
            BOOT.project = state.project;
            StudioHeader.setProject(body.title, true);
            msg.textContent='Saved.'; msg.style.cssText='display:block;color:var(--acc);';
            setTimeout(function () { msg.style.display='none'; }, 2000);
          })
          .catch(function (e) { msg.textContent='Error: '+e.message; msg.style.cssText='display:block;color:#c0392b;'; });
      });
      document.getElementById('su-next').addEventListener('click', function () { state.stepId='familiarize'; render(); });
    },

    // ── Data Cleaning / De-identification ─────────────────────────────────────
    deident: function (host) {
      host.innerHTML = '<div class="ws-header"><div class="eyebrow">Data Cleaning</div>'
        + '<h1 class="title">Data Cleaning</h1>'
        + '<p class="lede">Scan for personal information (emails, phone numbers, names) before analysis begins. Masking is optional but recommended for data shared beyond the original research team.</p></div>'
        + '<div id="di-body"><div class="btn-row">'
        + '<button class="btn primary" id="di-scan">Scan for PII</button>'
        + '<button class="btn" id="di-skip">Skip, continue to Project Setup &rarr;</button>'
        + '</div></div>';

      document.getElementById('di-skip').addEventListener('click', function () { state.stepId='setup'; render(); });
      document.getElementById('di-scan').addEventListener('click', function () {
        var body = document.getElementById('di-body');
        body.innerHTML = '<div class="notice info">Scanning segments&hellip;</div>';
        fetch('/api/qual/scan-pii.php?project_id=' + BOOT.projectId)
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.ok) throw new Error(d.message || 'Scan failed.');
            renderPiiResults(body, d);
          })
          .catch(function (e) {
            body.innerHTML = '<div class="notice err">Scan error: ' + esc(e.message) + '</div>'
              + '<div class="btn-row"><button class="btn" id="di-skip2">Continue without scanning</button></div>';
            document.getElementById('di-skip2').addEventListener('click', function () { state.stepId='setup'; render(); });
          });
      });

      function renderPiiResults(body, d) {
        if (d.flag_count === 0) {
          body.innerHTML = '<div class="notice info">No PII patterns detected in ' + d.total_segments + ' segments.</div>'
            + '<div class="btn-row"><button class="btn primary" id="di-done">Continue to Project Setup &rarr;</button></div>';
          document.getElementById('di-done').addEventListener('click', function () { state.stepId='setup'; render(); });
          return;
        }

        var typeLabel = { email:'Email', phone:'Phone', ssn:'ID Number', name_intro:'Name' };
        var rows = d.flagged.map(function (f) {
          var patternList = f.patterns.map(function (p) {
            return '<span class="pii-badge">' + (typeLabel[p.type] || p.type) + ': ' + esc(p.match) + '</span>';
          }).join(' ');
          return '<div class="pii-row" id="pii-' + f.segment_id + '">'
            + '<div class="pii-patterns">' + patternList + '</div>'
            + '<div class="pii-text">' + esc(f.original) + '</div>'
            + '<div class="pii-actions">'
            + '<button class="btn primary" style="font-size:12px;padding:6px 12px" data-mask="' + f.segment_id + '">Mask this segment</button>'
            + '<button class="btn" style="font-size:12px;padding:6px 12px;margin-left:6px" data-skip="' + f.segment_id + '">Skip</button>'
            + '</div></div>';
        }).join('');

        body.innerHTML = '<div class="notice warn">'
          + d.flag_count + ' segment' + (d.flag_count !== 1 ? 's' : '') + ' with potential PII detected in ' + d.total_segments + ' total segments.'
          + '</div>'
          + '<div class="btn-row" style="margin-bottom:18px">'
          + '<button class="btn primary" id="di-mask-all">Mask all detected</button>'
          + '<button class="btn" id="di-skip3">Continue without changes</button>'
          + '</div>'
          + '<div id="pii-list" style="display:flex;flex-direction:column;gap:12px">' + rows + '</div>'
          + '<div class="btn-row" style="margin-top:20px"><button class="btn primary" id="di-continue">Continue to Project Setup &rarr;</button></div>';

        document.getElementById('di-skip3').addEventListener('click', function () { state.stepId='setup'; render(); });
        document.getElementById('di-continue').addEventListener('click', function () { state.stepId='setup'; render(); });

        function maskSegment(sid, btn) {
          if (btn) { btn.disabled = true; btn.textContent = 'Masking...'; }
          api('/api/qual/mask-pii.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: BOOT.projectId, segment_id: sid }),
          }).then(function (r) {
            var row = document.getElementById('pii-' + sid);
            if (row) {
              row.innerHTML = '<div class="pii-masked"><span class="pii-badge" style="background:var(--acc-soft);color:var(--acc-deep)">Masked</span> '
                + esc(r.masked_text) + '</div>';
            }
          }).catch(function (e) {
            if (btn) { btn.disabled = false; btn.textContent = 'Mask this segment'; }
            alert('Could not mask: ' + e.message);
          });
        }

        document.getElementById('di-mask-all').addEventListener('click', function () {
          d.flagged.forEach(function (f) { maskSegment(f.segment_id, null); });
        });
        body.addEventListener('click', function (e) {
          var btn = e.target.closest('[data-mask]');
          var skip = e.target.closest('[data-skip]');
          if (btn) maskSegment(+btn.dataset.mask, btn);
          if (skip) {
            var row = document.getElementById('pii-' + skip.dataset.skip);
            if (row) row.style.opacity = '.4';
          }
        });
      }
    },

    // ── Familiarization ───────────────────────────────────────────────────────
    familiarize: function (host) {
      var d = state.projectData;
      host.innerHTML = '<div class="ws-header"><div class="eyebrow">Familiarization</div>'
        + '<h1 class="title">Familiarization</h1>'
        + '<p class="lede">Explore the corpus before formal coding. Read through responses, record your first impressions, and let ReliCheck Intelligence surface recurring concepts.</p></div>'
        + '<div class="stats-grid">'
        + _statCard(d.seg_count, 'Segments')
        + _statCard(d.doc_count, 'Data sources')
        + _statCard(d.total_words, 'Total words')
        + _statCard(d.avg_words, 'Avg words / segment')
        + _statCard(d.code_count, 'Codes in codebook')
        + '</div>'
        + '<div class="panel"><div class="panel-h"><h3>First Impressions Memo '
        + '<span style="font-size:12px;font-weight:400;color:var(--ink-3)">saved to audit trail</span></h3></div><div class="panel-b">'
        + '<p style="font-size:13.5px;color:var(--ink-3);margin:0 0 14px;">Before coding, what stands out? What surprises you? What patterns, tensions, or questions do you notice?</p>'
        + '<textarea id="fam-memo" style="min-height:130px;" placeholder="I noticed several responses mentioned..."></textarea>'
        + '<div id="fam-msg" style="display:none;font-size:13px;margin-top:6px;"></div>'
        + '<div class="btn-row"><button class="btn primary" id="fam-save">Save memo</button>'
        + '<button class="btn" id="fam-next">Start coding</button></div>'
        + '</div></div>'
        + '<div class="panel"><div class="panel-h"><h3>Linguistic Concept Scan'
        + '<span style="font-size:12px;font-weight:400;color:var(--ink-3);margin-left:10px">powered by ReliCheck Intelligence</span></h3>'
        + '<p style="font-size:13px;color:var(--ink-3);margin:4px 0 0;">Analyzes a sample of responses and identifies recurring concepts before you begin formal coding. Results persist and can be re-run.</p></div>'
        + '<div class="panel-b" id="scan-body">'
        + '<div class="btn-row">'
        + '<button class="btn primary" id="scan-run">Run Concept Scan</button>'
        + '<button class="btn" id="scan-cached" style="display:none">Load previous scan</button>'
        + '</div></div></div>';

      document.getElementById('fam-save').addEventListener('click', function () {
        var body = (document.getElementById('fam-memo').value || '').trim();
        var msg  = document.getElementById('fam-msg');
        if (!body) { msg.textContent='Write something before saving.'; msg.style.cssText='display:block;color:#c0392b;'; return; }
        msg.textContent='Saving...'; msg.style.cssText='display:block;color:var(--ink-3);';
        api('/api/qual/save-memo.php', { method:'POST', body:JSON.stringify({
          project_id: BOOT.projectId, object_type:'project',
          memo_type:'first_impressions', title:'First Impressions', body: body
        })}).then(function () {
          msg.textContent='Memo saved.'; msg.style.cssText='display:block;color:var(--acc);';
          setTimeout(function () { msg.style.display='none'; }, 2500);
        }).catch(function (e) { msg.textContent='Error: '+e.message; msg.style.cssText='display:block;color:#c0392b;'; });
      });
      document.getElementById('fam-next').addEventListener('click', function () { state.stepId='coding'; render(); });

      function runScan(force) {
        var scanBody = document.getElementById('scan-body');
        if (!scanBody) return;
        scanBody.innerHTML = '<div class="notice info">Analyzing corpus with ReliCheck Intelligence&hellip; this may take 15-30 seconds.</div>';
        api('/api/qual/concept-scan.php', {
          method: 'POST',
          body: JSON.stringify({ project_id: BOOT.projectId, force: !!force }),
        }).then(function (r) {
          renderConceptScan(scanBody, r);
        }).catch(function (e) {
          scanBody.innerHTML = '<div class="notice err">Scan failed: ' + esc(e.message) + '</div>'
            + '<div class="btn-row"><button class="btn primary" id="scan-retry">Try again</button></div>';
          document.getElementById('scan-retry').addEventListener('click', function () { runScan(true); });
        });
      }

      function renderConceptScan(scanBody, r) {
        var etColors = {
          lexical:  'background:#eef3fa;color:#085fcc',
          phrase:   'background:#e8f5ee;color:#174d30',
          semantic: 'background:#fff8ee;color:#92400e',
        };
        var cards = (r.concepts || []).map(function (c) {
          var etStyle = etColors[c.evidence_type] || 'background:#f3f4f6;color:#374151';
          var quotes = (c.example_quotes || []).map(function (q) {
            return '<div class="concept-quote">&ldquo;' + esc(q) + '&rdquo;</div>';
          }).join('');
          return '<div class="concept-card">'
            + '<div class="concept-top">'
            + '<span class="concept-name">' + esc(c.concept) + '</span>'
            + '<span class="concept-et" style="' + etStyle + '">' + esc(c.evidence_type) + '</span>'
            + '<span class="concept-freq">' + (c.frequency || '?') + ' responses</span>'
            + '</div>'
            + quotes
            + '</div>';
        }).join('');

        var note = r.from_cache ? '<span style="font-size:12px;color:var(--ink-3)">Cached result</span>' : '';
        scanBody.innerHTML = '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">'
          + '<span style="font-size:13px;color:var(--ink-3)">' + (r.segments_scanned || 0) + ' segments scanned</span>'
          + note
          + '<button class="btn" style="font-size:12px;padding:5px 12px;margin-left:auto" id="scan-rerun">Re-run scan</button>'
          + '</div>'
          + '<div class="concept-grid">' + cards + '</div>';
        document.getElementById('scan-rerun').addEventListener('click', function () { runScan(true); });
      }

      // Auto-load cached scan if one exists
      api('/api/qual/concept-scan.php', {
        method: 'POST',
        body: JSON.stringify({ project_id: BOOT.projectId, force: false }),
      }).then(function (r) {
        var scanBody = document.getElementById('scan-body');
        if (scanBody && r.from_cache) renderConceptScan(scanBody, r);
      }).catch(function () {});

      document.getElementById('scan-run').addEventListener('click', function () { runScan(true); });
    },

    // ── Coding Workspace ──────────────────────────────────────────────────────
    coding: function (host) {
      loadCodesIfNeeded().then(function () {
        var uncodedOnly = false;
        var searchQuery = '';
        var segments    = [];

        function load() {
          var qs = 'project_id=' + BOOT.projectId + '&limit=200' + (uncodedOnly ? '&uncoded=1' : '');
          return api('/api/qual/get-segments.php?' + qs).then(function (d) {
            segments = d.segments || [];
            state.segments = segments;
            renderList();
          });
        }

        function renderList() {
          var list = document.getElementById('seg-list');
          if (!list) return;
          var filtered = searchQuery
            ? segments.filter(function (s) { return s.raw_text.toLowerCase().indexOf(searchQuery.toLowerCase()) !== -1; })
            : segments;
          var coded   = filtered.filter(function (s) { return s.code_count > 0; }).length;
          var uncoded = filtered.filter(function (s) { return s.code_count === 0; }).length;
          var countEl = document.getElementById('seg-counts');
          if (countEl) countEl.textContent = filtered.length + ' segments · ' + coded + ' coded · ' + uncoded + ' uncoded';

          if (!filtered.length) {
            list.innerHTML = '<div class="placeholder">No segments ' + (uncodedOnly ? 'left to code.' : 'found.') + '</div>';
            return;
          }
          list.innerHTML = filtered.map(renderSegCard).join('');
        }

        function renderSegCard(seg) {
          var meta = seg.metadata_json || {};
          var metaItems = Object.keys(meta).slice(0, 4).map(function (k) {
            return '<span class="seg-pid">' + esc(k) + ': ' + esc(String(meta[k])) + '</span>';
          }).join('');
          var pid = seg.participant_id ? '<span class="seg-pid">ID: ' + esc(seg.participant_id) + '</span>' : '';
          var q   = seg.question_ref   ? '<span class="seg-q">' + esc(seg.question_ref) + '</span>' : '';
          var chips = (seg.codes || []).map(function (c) {
            return '<span class="chip">' + esc(c.name)
              + '<button class="chip-x" data-seg="' + seg.id + '" data-code="' + c.id + '">&times;</button></span>';
          }).join('');
          var over = seg.code_count >= 4;
          var flag = seg.code_count === 0
            ? '<span class="flag uncoded">Uncoded</span>'
            : over ? '<span class="flag overcoded">Over-coded (4+)</span>' : '';
          var pickerItems = state.codes.length
            ? state.codes.map(function (c) {
                return '<button class="picker-item" data-seg="' + seg.id + '" data-code="' + c.id + '" data-name="' + esc(c.name) + '">' + esc(c.name) + '</button>';
              }).join('')
            : '<div class="picker-empty">No codes yet.</div>';

          return '<div class="seg-card ' + (seg.code_count > 0 ? 'coded' : '') + (over ? ' overcoded' : '') + '" id="seg-' + seg.id + '">'
            + '<div class="seg-meta">' + pid + q + metaItems + '</div>'
            + '<div class="seg-text">' + esc(seg.raw_text) + '</div>'
            + '<div class="code-chips" id="chips-' + seg.id + '">' + chips + '</div>'
            + '<div class="seg-actions">'
            + '<div class="picker-wrap" id="pw-' + seg.id + '">'
            + '<button class="add-code-btn" data-seg="' + seg.id + '">+ Add code</button>'
            + '<div class="picker" id="picker-' + seg.id + '" style="display:none;">'
            + pickerItems
            + '<div class="picker-new"><button class="picker-new-btn" data-seg="' + seg.id + '">'
            + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> New code</button></div>'
            + '</div></div>'
            + '<button class="ai-suggest-btn" data-seg="' + seg.id + '">&#9734; Suggest codes</button>'
            + flag + '</div>'
            + '<div class="ai-suggest-panel" id="aip-' + seg.id + '" style="display:none;"></div>'
            + '</div>';
        }

        host.innerHTML = '<div class="ws-header"><div class="eyebrow">Coding Workspace</div>'
          + '<h1 class="title">Coding Workspace</h1>'
          + '<p class="lede">Apply codes to each segment. Participant context appears above every response. Use ReliCheck Intelligence to get AI code suggestions.</p></div>'
          + '<div class="filters">'
          + '<input class="search-input" id="seg-search" placeholder="Search segments...">'
          + '<button class="filter-btn active" id="filter-all">All</button>'
          + '<button class="filter-btn" id="filter-uncoded">Uncoded only</button>'
          + '<button class="btn" style="margin-left:auto" onclick="QS.go(\'codebook\')">Manage codebook</button>'
          + '</div>'
          + '<div id="seg-counts" style="font-size:13px;color:var(--ink-3);margin-bottom:14px;">Loading...</div>'
          + '<div class="seg-list" id="seg-list"><div class="placeholder">Loading segments...</div></div>';

        document.getElementById('seg-search').addEventListener('input', function () {
          searchQuery = this.value; renderList();
        });
        document.getElementById('filter-all').addEventListener('click', function () {
          uncodedOnly = false;
          this.classList.add('active');
          document.getElementById('filter-uncoded').classList.remove('active');
          load();
        });
        document.getElementById('filter-uncoded').addEventListener('click', function () {
          uncodedOnly = true;
          this.classList.add('active');
          document.getElementById('filter-all').classList.remove('active');
          load();
        });

        // Event delegation for picker, apply, remove, new-code, AI suggest
        document.getElementById('seg-list').addEventListener('click', function (e) {
          var addBtn    = e.target.closest('.add-code-btn');
          var item      = e.target.closest('.picker-item');
          var newBtn    = e.target.closest('.picker-new-btn');
          var removeBtn = e.target.closest('.chip-x');
          var sugBtn    = e.target.closest('.ai-suggest-btn');
          var applyAi   = e.target.closest('.ai-apply-btn');
          var dismissAi = e.target.closest('.ai-dismiss-btn');

          if (addBtn) {
            var sid = addBtn.dataset.seg;
            document.querySelectorAll('.picker').forEach(function (p) {
              if (p.id !== 'picker-' + sid) p.style.display = 'none';
            });
            var picker = document.getElementById('picker-' + sid);
            if (picker) picker.style.display = picker.style.display === 'none' ? 'block' : 'none';
            setTimeout(function () {
              document.addEventListener('click', function close(ev) {
                if (!ev.target.closest('#pw-' + sid)) {
                  var p = document.getElementById('picker-' + sid);
                  if (p) p.style.display = 'none';
                  document.removeEventListener('click', close);
                }
              });
            }, 10);
          }
          if (item) {
            var sid = item.dataset.seg, cid = item.dataset.code, cname = item.dataset.name;
            var picker = document.getElementById('picker-' + sid);
            if (picker) picker.style.display = 'none';
            applyCode(+sid, +cid, cname);
          }
          if (newBtn) {
            var sid = newBtn.dataset.seg;
            var name = prompt('New code name:');
            if (!name || !name.trim()) return;
            api('/api/qual/save-code.php', { method:'POST', body:JSON.stringify({ project_id:BOOT.projectId, name:name.trim() }) })
              .then(function (r) {
                state.codes.push({ id: r.code_id, name: name.trim() });
                applyCode(+sid, r.code_id, name.trim());
              }).catch(function (e) { alert('Error: ' + e.message); });
          }
          if (removeBtn) {
            var sid = +removeBtn.dataset.seg, cid = +removeBtn.dataset.code;
            api('/api/qual/remove-code.php', { method:'POST', body:JSON.stringify({ project_id:BOOT.projectId, segment_id:sid, code_id:cid }) })
              .then(function () {
                var chip = removeBtn.closest('.chip'); if (chip) chip.remove();
                var seg = segments.find(function (s) { return s.id === sid; });
                if (seg) { seg.codes = seg.codes.filter(function (c) { return c.id !== cid; }); seg.code_count = seg.codes.length; }
                if (seg && seg.code_count === 0) { var card = document.getElementById('seg-' + sid); if (card) { card.classList.remove('coded'); } }
              }).catch(function (e) { alert('Error: ' + e.message); });
          }
          if (sugBtn) {
            var sid = +sugBtn.dataset.seg;
            var panel = document.getElementById('aip-' + sid);
            if (!panel) return;
            if (panel.style.display !== 'none' && panel.innerHTML !== '') { panel.style.display = 'none'; return; }
            panel.style.display = 'block';
            panel.innerHTML = '<div style="padding:12px 0 4px;font-size:12.5px;color:var(--ink-3);">ReliCheck Intelligence is analyzing this segment&hellip;</div>';
            sugBtn.disabled = true;
            api('/api/qual/suggest-codes.php', {
              method: 'POST',
              body: JSON.stringify({ project_id: BOOT.projectId, segment_id: sid }),
            }).then(function (r) {
              sugBtn.disabled = false;
              renderSuggestions(panel, sid, r.suggestions || []);
            }).catch(function (ex) {
              sugBtn.disabled = false;
              panel.innerHTML = '<div class="notice err" style="margin:8px 0">Could not get suggestions: ' + esc(ex.message) + '</div>';
            });
          }
          if (applyAi) {
            var sid   = +applyAi.dataset.seg;
            var cname = applyAi.dataset.name;
            var existCode = state.codes.find(function (c) { return c.name.toLowerCase() === cname.toLowerCase(); });
            if (existCode) {
              applyCode(sid, existCode.id, existCode.name);
              applyAi.closest('.ai-sug-row').style.opacity = '.4';
            } else {
              api('/api/qual/save-code.php', { method:'POST', body:JSON.stringify({ project_id:BOOT.projectId, name:cname }) })
                .then(function (r) {
                  state.codes.push({ id: r.code_id, name: cname });
                  applyCode(sid, r.code_id, cname);
                  applyAi.closest('.ai-sug-row').style.opacity = '.4';
                }).catch(function (ex) { alert('Error saving code: ' + ex.message); });
            }
          }
          if (dismissAi) {
            dismissAi.closest('.ai-sug-row').style.opacity = '.4';
          }
        });

        function renderSuggestions(panel, sid, suggestions) {
          if (!suggestions.length) {
            panel.innerHTML = '<div style="padding:8px 0;font-size:13px;color:var(--ink-3);">No suggestions. Consider adding more codes to the codebook first.</div>';
            return;
          }
          var etColors = {
            lexical:   '#085fcc',
            phrase:    '#174d30',
            semantic:  '#92400e',
            syntactic: '#6b21a8',
          };
          var confIcons = { high: '&#9679;&#9679;&#9679;', medium: '&#9679;&#9679;&#9675;', low: '&#9679;&#9675;&#9675;' };
          var rows = suggestions.map(function (s) {
            var etColor = etColors[s.evidence_type] || '#374151';
            var existBadge = s.is_existing
              ? '<span style="font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:2px 6px;border-radius:999px;background:var(--acc-soft);color:var(--acc-deep);margin-left:6px">in codebook</span>'
              : '<span style="font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:2px 6px;border-radius:999px;background:#f3f4f6;color:#6b7280;margin-left:6px">new</span>';
            return '<div class="ai-sug-row">'
              + '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:4px">'
              + '<span style="font-size:14px;font-weight:700">' + esc(s.name) + '</span>'
              + existBadge
              + '<span style="font-size:10.5px;padding:2px 7px;border-radius:999px;font-weight:700;color:' + etColor + ';background:' + etColor + '18">' + esc(s.evidence_type) + '</span>'
              + '<span style="font-size:11px;color:var(--ink-3);margin-left:2px" title="Confidence">' + (confIcons[s.confidence] || '') + '</span>'
              + '</div>'
              + '<div style="font-size:12.5px;color:var(--ink-2);margin-bottom:8px;line-height:1.45">' + esc(s.rationale) + '</div>'
              + '<div style="display:flex;gap:8px">'
              + '<button class="btn primary ai-apply-btn" style="font-size:12px;padding:5px 12px" data-seg="' + sid + '" data-name="' + esc(s.name) + '">Apply</button>'
              + '<button class="btn ai-dismiss-btn" style="font-size:12px;padding:5px 10px">Dismiss</button>'
              + '</div></div>';
          }).join('');
          panel.innerHTML = '<div class="ai-sug-list">'
            + '<div style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:10px">&#9734; ReliCheck Intelligence suggestions</div>'
            + rows + '</div>';
        }

        function applyCode(sid, cid, cname) {
          api('/api/qual/apply-code.php', { method:'POST', body:JSON.stringify({ project_id:BOOT.projectId, segment_id:sid, code_id:cid }) })
            .then(function () {
              var seg = segments.find(function (s) { return s.id === sid; });
              if (seg && !seg.codes.find(function (c) { return c.id === cid; })) {
                seg.codes.push({ id: cid, name: cname }); seg.code_count = seg.codes.length;
              }
              var chipsEl = document.getElementById('chips-' + sid);
              if (chipsEl && seg) {
                chipsEl.innerHTML = seg.codes.map(function (c) {
                  return '<span class="chip">' + esc(c.name)
                    + '<button class="chip-x" data-seg="' + sid + '" data-code="' + c.id + '">&times;</button></span>';
                }).join('');
              }
              var card = document.getElementById('seg-' + sid);
              if (card && seg && seg.code_count > 0) card.classList.add('coded');
            }).catch(function (e) { alert('Could not apply code: ' + e.message); });
        }

        load();
      });
    },

    codebook: function (host) {
      loadCodesIfNeeded().then(function () {
        function renderTable() {
          if (!state.codes.length) {
            return '<div class="placeholder">No codes yet. Add your first code below or create one from the Coding Workspace.</div>';
          }
          return '<div class="cb-table-wrap"><table class="cb-table">'
            + '<thead><tr><th>Code</th><th>Definition</th><th>Applications</th><th>Status</th><th></th></tr></thead><tbody>'
            + state.codes.map(function (c) {
                return '<tr>'
                  + '<td style="font-weight:700">' + esc(c.name) + '</td>'
                  + '<td style="color:var(--ink-2);max-width:260px">' + (c.definition ? esc(c.definition) : '<span style="color:var(--ink-3);font-style:italic">No definition</span>') + '</td>'
                  + '<td style="text-align:center;color:var(--ink-3)">' + (c.application_count || 0) + '</td>'
                  + '<td><span class="status-chip ' + esc(c.status) + '">' + esc(c.status) + '</span></td>'
                  + '<td><button class="btn" style="padding:5px 12px;font-size:12px" data-edit="' + c.id + '">Edit</button></td>'
                  + '</tr>';
              }).join('')
            + '</tbody></table></div>';
        }

        host.innerHTML = '<div class="ws-header"><div class="eyebrow">Step 7</div>'
          + '<h1 class="title">Codebook Builder</h1>'
          + '<p class="lede">Define, refine, and manage the codes used in your analysis. A well-defined codebook is the backbone of credible qualitative findings.</p></div>'
          + '<div id="cb-table">' + renderTable() + '</div>'
          + '<hr style="border:none;border-top:1px solid var(--line);margin:28px 0">'
          + '<div class="panel" id="code-form-panel"><div class="panel-h"><h3 id="cb-form-title">Add a new code</h3></div><div class="panel-b">'
          + '<div class="field"><label>Code name <span style="color:#c0392b">*</span></label><input id="cb-name" placeholder="e.g. Lack of Communication"></div>'
          + '<div class="field"><label>Definition<span class="hint">What does this code capture? Be specific enough that two coders would agree.</span></label><textarea id="cb-def" placeholder="Apply when a respondent describes..."></textarea></div>'
          + '<div class="grid2">'
          + '<div class="field"><label>Include when</label><textarea id="cb-include" style="min-height:70px;" placeholder="The response describes a gap or absence of..."></textarea></div>'
          + '<div class="field"><label>Exclude when</label><textarea id="cb-exclude" style="min-height:70px;" placeholder="The response is about a different construct..."></textarea></div>'
          + '</div>'
          + '<div class="field"><label>Example quote</label><input id="cb-quote" placeholder="&quot;I never know who to go to...&quot;"></div>'
          + '<input type="hidden" id="cb-editing-id">'
          + '<div id="cb-msg" style="display:none;font-size:13px;margin-top:6px;"></div>'
          + '<div class="btn-row">'
          + '<button class="btn primary" id="cb-save">Add code</button>'
          + '<button class="btn" id="cb-cancel" style="display:none">Cancel</button>'
          + '</div></div></div>';

        function clearForm() {
          ['cb-name','cb-def','cb-include','cb-exclude','cb-quote'].forEach(function (id) { document.getElementById(id).value = ''; });
          document.getElementById('cb-editing-id').value = '';
          document.getElementById('cb-form-title').textContent = 'Add a new code';
          document.getElementById('cb-save').textContent = 'Add code';
          document.getElementById('cb-cancel').style.display = 'none';
        }

        document.getElementById('cb-table').addEventListener('click', function (e) {
          var btn = e.target.closest('[data-edit]');
          if (!btn) return;
          var code = state.codes.find(function (c) { return c.id === +btn.dataset.edit; });
          if (!code) return;
          document.getElementById('cb-editing-id').value = code.id;
          document.getElementById('cb-name').value    = code.name || '';
          document.getElementById('cb-def').value     = code.definition || '';
          document.getElementById('cb-include').value = code.include_when || '';
          document.getElementById('cb-exclude').value = code.exclude_when || '';
          document.getElementById('cb-quote').value   = code.example_quote || '';
          document.getElementById('cb-form-title').textContent = 'Edit code: ' + code.name;
          document.getElementById('cb-save').textContent = 'Save changes';
          document.getElementById('cb-cancel').style.display = 'inline-flex';
          document.getElementById('code-form-panel').scrollIntoView({ behavior: 'smooth' });
        });
        document.getElementById('cb-cancel').addEventListener('click', clearForm);
        document.getElementById('cb-save').addEventListener('click', function () {
          var msg    = document.getElementById('cb-msg');
          var name   = document.getElementById('cb-name').value.trim();
          var editId = +document.getElementById('cb-editing-id').value || 0;
          if (!name) { msg.textContent='Code name is required.'; msg.style.cssText='display:block;color:#c0392b;'; return; }
          msg.textContent='Saving...'; msg.style.cssText='display:block;color:var(--ink-3);';
          var body = { project_id:BOOT.projectId, name:name,
            definition:   document.getElementById('cb-def').value.trim(),
            include_when: document.getElementById('cb-include').value.trim(),
            exclude_when: document.getElementById('cb-exclude').value.trim(),
            example_quote:document.getElementById('cb-quote').value.trim() };
          if (editId) body.id = editId;
          api('/api/qual/save-code.php', { method:'POST', body:JSON.stringify(body) })
            .then(function () { return api('/api/qual/get-codes.php?project_id=' + BOOT.projectId); })
            .then(function (r) {
              state.codes = r.codes || [];
              document.getElementById('cb-table').innerHTML = renderTable();
              clearForm();
              msg.textContent = editId ? 'Code updated.' : 'Code added.';
              msg.style.cssText='display:block;color:var(--acc);';
              setTimeout(function () { msg.style.display='none'; }, 2500);
            })
            .catch(function (e) { msg.textContent='Error: '+e.message; msg.style.cssText='display:block;color:#c0392b;'; });
        });
      });
    },
    // ── Category Builder ──────────────────────────────────────────────────────
    categories: function (host) {
      var catState = { categories: [], unassigned: [] };

      function load() {
        return api('/api/qual/get-categories.php?project_id=' + BOOT.projectId)
          .then(function (d) {
            catState.categories = d.categories || [];
            catState.unassigned = d.unassigned  || [];
            renderPage();
          });
      }

      function renderPage() {
        var hasCategories = catState.categories.length > 0;

        var unassignedHtml = '';
        if (catState.unassigned.length) {
          var items = catState.unassigned.map(function (c) {
            var catOpts = catState.categories.map(function (cat) {
              return '<option value="' + cat.id + '">' + esc(cat.name) + '</option>';
            }).join('');
            return '<div class="cat-code-row" id="ucode-' + c.id + '">'
              + '<span class="chip" style="background:var(--bg);border:1px solid var(--line);color:var(--ink-2)">' + esc(c.name) + '</span>'
              + (c.application_count > 0 ? '<span style="font-size:11.5px;color:var(--ink-3)">' + c.application_count + ' applied</span>' : '')
              + (catState.categories.length
                ? '<div style="display:flex;align-items:center;gap:6px;margin-left:auto">'
                  + '<select class="cat-assign-sel" data-code="' + c.id + '" style="font-size:12px;padding:4px 8px;border:1px solid #d1d5db;border-radius:8px;max-width:160px">'
                  + '<option value="">Assign to...</option>' + catOpts
                  + '</select></div>'
                : '<span style="font-size:12px;color:var(--ink-3);margin-left:auto">Create a category first</span>')
              + '</div>';
          }).join('');
          unassignedHtml = '<div class="panel"><div class="panel-h"><h3>Unassigned codes <span style="font-size:13px;font-weight:400;color:var(--ink-3)">(' + catState.unassigned.length + ')</span></h3></div>'
            + '<div class="panel-b" style="padding-bottom:12px"><div style="max-height:360px;overflow-y:auto;display:flex;flex-direction:column;gap:8px">' + items + '</div></div></div>';
        } else if (!hasCategories) {
          unassignedHtml = '<div class="placeholder">No codes in the codebook yet. Build your codebook before grouping codes into categories.</div>';
        } else {
          unassignedHtml = '<div style="font-size:13.5px;color:var(--ink-3);margin-bottom:18px">All codes are assigned to a category.</div>';
        }

        var catsHtml = catState.categories.map(function (cat) {
          var codeChips = cat.codes.length
            ? cat.codes.map(function (c) {
                return '<span class="chip" style="background:var(--acc-soft);color:var(--acc-deep)">' + esc(c.name)
                  + '<button class="chip-x cat-unassign" data-code="' + c.id + '" title="Remove from category">&times;</button></span>';
              }).join('')
            : '<span style="font-size:12.5px;color:var(--ink-3);font-style:italic">No codes assigned</span>';

          return '<div class="panel cat-card" id="cat-' + cat.id + '">'
            + '<div class="panel-h" style="display:flex;align-items:flex-start;gap:8px">'
            + '<div style="flex:1"><h3 style="margin-bottom:' + (cat.description ? '4' : '0') + 'px">' + esc(cat.name) + '</h3>'
            + (cat.description ? '<p style="margin:0 0 0;font-size:13px;color:var(--ink-3)">' + esc(cat.description) + '</p>' : '')
            + '</div>'
            + '<button class="btn" style="font-size:12px;padding:5px 12px;flex-shrink:0" data-edit-cat="' + cat.id + '">Edit</button>'
            + '</div>'
            + '<div class="panel-b"><div class="code-chips">' + codeChips + '</div></div></div>';
        }).join('');

        host.innerHTML = '<div class="ws-header"><div class="eyebrow">Category Builder</div>'
          + '<h1 class="title">Category Builder</h1>'
          + '<p class="lede">Group related codes into categories. Categories become the building blocks of themes.</p></div>'
          + unassignedHtml
          + '<div style="margin-bottom:20px">'
          + '<h3 style="font-size:15px;font-weight:700;margin:0 0 12px">Categories</h3>'
          + (hasCategories ? '<div id="cat-list">' + catsHtml + '</div>' : '<div id="cat-list"></div>')
          + '</div>'
          + '<div class="panel" id="cat-form-panel"><div class="panel-h"><h3 id="cat-form-title">Add a category</h3></div><div class="panel-b">'
          + '<div class="field"><label>Category name <span style="color:#c0392b">*</span></label>'
          + '<input id="cat-name" placeholder="e.g. Communication Barriers"></div>'
          + '<div class="field"><label>Description <span style="font-weight:400;color:var(--ink-3)">(optional)</span></label>'
          + '<input id="cat-desc" placeholder="What kind of codes belong here?"></div>'
          + '<input type="hidden" id="cat-editing-id">'
          + '<div id="cat-msg" style="display:none;font-size:13px;margin-top:4px"></div>'
          + '<div class="btn-row"><button class="btn primary" id="cat-save">Add category</button>'
          + '<button class="btn" id="cat-cancel" style="display:none">Cancel</button>'
          + '</div></div></div>'
          + '<div class="btn-row" style="margin-top:8px">'
          + '<button class="btn primary" id="cat-to-themes">Next: Build themes &rarr;</button>'
          + '</div>';

        // Form handlers
        document.getElementById('cat-save').addEventListener('click', function () {
          var msg    = document.getElementById('cat-msg');
          var name   = (document.getElementById('cat-name').value || '').trim();
          var desc   = (document.getElementById('cat-desc').value || '').trim();
          var editId = +(document.getElementById('cat-editing-id').value) || 0;
          if (!name) { msg.textContent = 'Name is required.'; msg.style.cssText = 'display:block;color:#c0392b;'; return; }
          msg.textContent = 'Saving...'; msg.style.cssText = 'display:block;color:var(--ink-3);';
          api('/api/qual/save-category.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: BOOT.projectId, id: editId || undefined, name: name, description: desc }),
          }).then(function () { return load(); })
            .then(function () { clearCatForm(); msg.style.display = 'none'; })
            .catch(function (e) { msg.textContent = 'Error: ' + e.message; msg.style.cssText = 'display:block;color:#c0392b;'; });
        });

        document.getElementById('cat-cancel').addEventListener('click', clearCatForm);
        document.getElementById('cat-to-themes').addEventListener('click', function () { state.stepId = 'themes'; render(); });

        // Edit category button
        host.addEventListener('click', function (e) {
          var editBtn = e.target.closest('[data-edit-cat]');
          if (editBtn) {
            var cat = catState.categories.find(function (c) { return c.id == editBtn.dataset.editCat; });
            if (!cat) return;
            document.getElementById('cat-editing-id').value = cat.id;
            document.getElementById('cat-name').value = cat.name;
            document.getElementById('cat-desc').value = cat.description || '';
            document.getElementById('cat-form-title').textContent = 'Edit category: ' + cat.name;
            document.getElementById('cat-save').textContent = 'Save changes';
            document.getElementById('cat-cancel').style.display = 'inline-flex';
            document.getElementById('cat-form-panel').scrollIntoView({ behavior: 'smooth' });
          }

          var unassignBtn = e.target.closest('.cat-unassign');
          if (unassignBtn) {
            var codeId = +unassignBtn.dataset.code;
            api('/api/qual/assign-code-category.php', {
              method: 'POST',
              body: JSON.stringify({ project_id: BOOT.projectId, code_id: codeId, category_id: 0 }),
            }).then(load).catch(function (ex) { alert('Error: ' + ex.message); });
          }
        });

        // Assign dropdown
        host.addEventListener('change', function (e) {
          var sel = e.target.closest('.cat-assign-sel');
          if (!sel || !sel.value) return;
          var codeId = +sel.dataset.code;
          var catId  = +sel.value;
          sel.disabled = true;
          api('/api/qual/assign-code-category.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: BOOT.projectId, code_id: codeId, category_id: catId }),
          }).then(load).catch(function (ex) { sel.disabled = false; alert('Error: ' + ex.message); });
        });
      }

      function clearCatForm() {
        document.getElementById('cat-editing-id').value = '';
        document.getElementById('cat-name').value = '';
        document.getElementById('cat-desc').value = '';
        document.getElementById('cat-form-title').textContent = 'Add a category';
        document.getElementById('cat-save').textContent = 'Add category';
        document.getElementById('cat-cancel').style.display = 'none';
        var msg = document.getElementById('cat-msg');
        if (msg) msg.style.display = 'none';
      }

      host.innerHTML = '<div class="placeholder">Loading categories...</div>';
      load();
    },

    // ── Theme Builder ─────────────────────────────────────────────────────────
    themes: function (host) {
      var tState = { themes: [], allCategories: [], editing: null, showForm: false };

      function load() {
        return api('/api/qual/get-themes.php?project_id=' + BOOT.projectId)
          .then(function (d) {
            tState.themes         = d.themes         || [];
            tState.allCategories  = d.all_categories || [];
            renderPage();
          });
      }

      function renderPage() {
        var noCategories = !tState.allCategories.length;

        var themeCards = tState.themes.map(function (t) {
          var catTags = t.categories.length
            ? t.categories.map(function (c) { return '<span class="chip" style="background:var(--acc-soft);color:var(--acc-deep);font-size:12px">' + esc(c.name) + '</span>'; }).join('')
            : '<span style="font-size:12.5px;color:var(--ink-3);font-style:italic">No categories linked</span>';

          var availCats = tState.allCategories.map(function (cat) {
            var linked = t.categories.some(function (tc) { return tc.id == cat.id; });
            return '<label class="cat-check-row">'
              + '<input type="checkbox" class="tc-check" data-theme="' + t.id + '" data-cat="' + cat.id + '"'
              + (linked ? ' checked' : '') + '> '
              + esc(cat.name) + '</label>';
          }).join('');

          return '<div class="panel theme-card" id="theme-' + t.id + '">'
            + '<div class="panel-h">'
            + '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px">'
            + '<h3>' + esc(t.name) + '</h3>'
            + '<button class="btn" style="font-size:12px;padding:5px 12px;flex-shrink:0" data-edit-theme="' + t.id + '">Edit</button>'
            + '</div>'
            + '<div style="margin-top:10px;padding:12px 14px;background:var(--acc-soft);border-radius:10px">'
            + '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--acc-deep);margin-bottom:4px">Finding</div>'
            + '<div style="font-size:14px;color:var(--acc-deep);line-height:1.55;font-style:italic">&ldquo;' + esc(t.interpretive_claim) + '&rdquo;</div>'
            + '</div>'
            + (t.notes ? '<p style="font-size:13px;color:var(--ink-3);margin:10px 0 0;line-height:1.5">' + esc(t.notes) + '</p>' : '')
            + '</div>'
            + '<div class="panel-b">'
            + '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:8px">Supporting categories</div>'
            + '<div class="code-chips" style="margin-bottom:12px">' + catTags + '</div>'
            + (tState.allCategories.length
              ? '<details style="font-size:13px"><summary style="cursor:pointer;color:var(--acc);font-weight:600">Link categories&hellip;</summary>'
                + '<div style="margin-top:10px;display:flex;flex-direction:column;gap:6px">' + availCats + '</div></details>'
              : '<span style="font-size:12.5px;color:var(--ink-3)">Build categories first to link them to themes.</span>')
            + '</div></div>';
        }).join('');

        var formHtml = '<div class="panel" id="theme-form-panel"><div class="panel-h"><h3 id="theme-form-title">Add a theme</h3></div><div class="panel-b">'
          + '<div class="field"><label>Theme name <span style="color:#c0392b">*</span></label>'
          + '<input id="th-name" placeholder="e.g. Systemic barriers to participation"></div>'
          + '<div class="field"><label>Interpretive claim <span style="color:#c0392b">*</span>'
          + '<span class="hint">State a finding, not a label. What does this theme tell you about participants\' experience?</span></label>'
          + '<textarea id="th-claim" style="min-height:100px" placeholder="Participants described feeling excluded from decision-making processes, even when they actively sought opportunities to contribute."></textarea></div>'
          + '<div class="field"><label>Notes <span style="font-weight:400;color:var(--ink-3)">(optional)</span>'
          + '<span class="hint">Analytic notes, questions, or caveats about this theme.</span></label>'
          + '<textarea id="th-notes" style="min-height:70px" placeholder="Consider whether this theme overlaps with..."></textarea></div>'
          + '<input type="hidden" id="th-editing-id">'
          + '<div id="th-msg" style="display:none;font-size:13px;margin-top:4px"></div>'
          + '<div class="btn-row"><button class="btn primary" id="th-save">Add theme</button>'
          + '<button class="btn" id="th-cancel" style="display:none">Cancel</button>'
          + '</div></div></div>';

        host.innerHTML = '<div class="ws-header"><div class="eyebrow">Theme Builder</div>'
          + '<h1 class="title">Theme Builder</h1>'
          + '<p class="lede">Themes are interpretive claims, not topic labels. Each theme answers a question about participants\' experience and is supported by one or more categories.</p></div>'
          + (noCategories
            ? '<div class="notice warn" style="margin-bottom:18px">No categories yet. Go to <strong>Category Builder</strong> first to group your codes before building themes.</div>'
            : '')
          + (tState.themes.length ? '<div id="theme-list" style="margin-bottom:20px">' + themeCards + '</div>' : '<div id="theme-list"></div>')
          + formHtml;

        // Save theme
        document.getElementById('th-save').addEventListener('click', function () {
          var msg    = document.getElementById('th-msg');
          var name   = (document.getElementById('th-name').value  || '').trim();
          var claim  = (document.getElementById('th-claim').value || '').trim();
          var notes  = (document.getElementById('th-notes').value || '').trim();
          var editId = +(document.getElementById('th-editing-id').value) || 0;
          if (!name)  { msg.textContent = 'Theme name is required.';        msg.style.cssText = 'display:block;color:#c0392b;'; return; }
          if (!claim) { msg.textContent = 'An interpretive claim is required. Themes are findings, not labels.'; msg.style.cssText = 'display:block;color:#c0392b;'; return; }
          msg.textContent = 'Saving...'; msg.style.cssText = 'display:block;color:var(--ink-3);';
          api('/api/qual/save-theme.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: BOOT.projectId, id: editId || undefined, name: name, interpretive_claim: claim, notes: notes }),
          }).then(function () { return load(); })
            .then(function () { clearThemeForm(); msg.style.display = 'none'; })
            .catch(function (e) { msg.textContent = 'Error: ' + e.message; msg.style.cssText = 'display:block;color:#c0392b;'; });
        });

        document.getElementById('th-cancel').addEventListener('click', clearThemeForm);

        // Edit theme
        host.addEventListener('click', function (e) {
          var editBtn = e.target.closest('[data-edit-theme]');
          if (editBtn) {
            var theme = tState.themes.find(function (t) { return t.id == editBtn.dataset.editTheme; });
            if (!theme) return;
            document.getElementById('th-editing-id').value = theme.id;
            document.getElementById('th-name').value  = theme.name;
            document.getElementById('th-claim').value = theme.interpretive_claim || '';
            document.getElementById('th-notes').value = theme.notes || '';
            document.getElementById('theme-form-title').textContent = 'Edit theme: ' + theme.name;
            document.getElementById('th-save').textContent = 'Save changes';
            document.getElementById('th-cancel').style.display = 'inline-flex';
            document.getElementById('theme-form-panel').scrollIntoView({ behavior: 'smooth' });
          }
        });

        // Toggle category on/off a theme
        host.addEventListener('change', function (e) {
          var cb = e.target.closest('.tc-check');
          if (!cb) return;
          cb.disabled = true;
          api('/api/qual/link-theme-category.php', {
            method: 'POST',
            body: JSON.stringify({
              project_id:  BOOT.projectId,
              theme_id:    +cb.dataset.theme,
              category_id: +cb.dataset.cat,
              action:      cb.checked ? 'add' : 'remove',
            }),
          }).then(load).catch(function (ex) { cb.disabled = false; alert('Error: ' + ex.message); });
        });
      }

      function clearThemeForm() {
        document.getElementById('th-editing-id').value = '';
        document.getElementById('th-name').value  = '';
        document.getElementById('th-claim').value = '';
        document.getElementById('th-notes').value = '';
        document.getElementById('theme-form-title').textContent = 'Add a theme';
        document.getElementById('th-save').textContent = 'Add theme';
        document.getElementById('th-cancel').style.display = 'none';
        var msg = document.getElementById('th-msg');
        if (msg) msg.style.display = 'none';
      }

      host.innerHTML = '<div class="placeholder">Loading themes...</div>';
      load();
    },
  };

  // ── Helpers ────────────────────────────────────────────────────────────────
  function loadCodesIfNeeded() {
    if (state.codes) return Promise.resolve();
    return api('/api/qual/get-codes.php?project_id=' + BOOT.projectId)
      .then(function (d) { state.codes = d.codes || []; });
  }

  function loadProjectData() {
    if (!BOOT.projectId) return Promise.resolve();
    return api('/api/qual/get-project.php?project_id=' + BOOT.projectId)
      .then(function (d) {
        state.projectData = d.stats || {};
        state.projectData.documents = d.documents || [];
        state.project = d.project || state.project;
        if (d.stats) StudioFooter.setDataInfo(d.stats.seg_count, d.stats.doc_count);
      });
  }

  function _stat(val, label) {
    return '<div><div class="stat-num">' + Number(val || 0).toLocaleString() + '</div>'
      + '<div class="stat-lbl">' + label + '</div></div>';
  }
  function _statCard(val, label) {
    return '<div class="stat-card"><div class="num">' + Number(val || 0).toLocaleString() + '</div>'
      + '<div class="lbl">' + label + '</div></div>';
  }

  // ── Guidance copy ──────────────────────────────────────────────────────────
  var GUIDANCE = {
    start:       '<p>Bring in your qualitative data here. Upload a CSV or XLSX file with open-ended text columns. Participant metadata columns will travel with each segment.</p><p>If you already have data loaded, proceed to <strong>Overview</strong> to review what was imported.</p>',
    overview:    '<p>Review what was loaded before mapping variables. Check the segment count and data sources look correct. If something is wrong, go back to <strong>Start</strong> and re-upload.</p>',
    datamap:     '<p>Confirm which columns are <strong>open-ended text</strong> (these become codeable segments), which are <strong>group variables</strong>, and which are <strong>participant metadata</strong>.</p><p>Coding is only available after you confirm this map.</p>',
    deident:     '<p><strong>Data Cleaning</strong> scans for emails, phone numbers, social security numbers, and name introductions using pattern matching.</p><p>Masking replaces detected values with tokens like <code>[EMAIL]</code> or <code>[NAME]</code>. This step is optional but recommended when data will be shared or archived beyond the original research context.</p>',
    setup:       '<p>The research question and researcher stance become part of the audit trail. Reviewers and trustworthiness checks will reference them to assess whether the analysis is grounded and transparent.</p>',
    familiarize: '<p>Read through the data before coding. The First Impressions Memo is evidence of <strong>reflexivity</strong> and early <strong>credibility</strong> work.</p><p>The <strong>Linguistic Concept Scan</strong> uses ReliCheck Intelligence to surface recurring concepts across the corpus — organized by evidence type — before you begin formal coding. Results are cached and can be re-run at any time.</p>',
    coding:      '<p>Codes should capture <strong>meaning</strong>, not just topic. Ask: what is this person saying, not just what words did they use?</p><p>Use <strong>Suggest codes</strong> (the star button on each segment) to get AI suggestions classified by evidence type: lexical, phrase, semantic, or syntactic. Apply suggestions you agree with; dismiss the rest.</p>',
    codebook:    '<p>A code name should be descriptive, not a single vague word. <strong>"Lack of administrative support"</strong> is better than <strong>"support."</strong></p><p>Write definitions specific enough that a second coder would apply the code to the same responses.</p>',
    categories:  '<p>Categories group related codes into higher-level buckets. They are not themes yet -- they are organizational containers.</p><p>A good category collects codes that share a <strong>common focus</strong>. Assign each code to exactly one category. Unassigned codes stay visible at the top until you place them.</p>',
    themes:      '<p>A theme is <strong>an interpretive claim</strong>, not a topic label. "Communication" is a topic. "Participants felt excluded from communication channels that shaped their work" is a theme.</p><p>Every theme needs a claim before it can be saved. The claim is your finding -- it states what the data shows, not just what it is about.</p><p>Link supporting categories to show which evidence grounds the theme.</p>',
    report:      '<p>Report generation is coming in a future phase. Your approved codes, themes, and quotes will appear here.</p>',
  };

  // ── Init ───────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    StudioHeader.init({
      logoSrc:      '/Qual-Studio-long.png',
      logoAlt:      'Qualitative Analysis Studio',
      logoHeight:   70,
      projectLabel: BOOT.projectLabel || 'New project',
      projectLive:  BOOT.projectLive,
      projectsUrl:  BOOT.projectsUrl,
      initials:     BOOT.initials,
    });
    StudioFooter.init();

    // Companion tab wiring
    document.querySelectorAll('.comp-tab').forEach(function (t) {
      t.addEventListener('click', function () {
        document.querySelectorAll('.comp-tab').forEach(function (x) { x.classList.remove('on'); });
        t.classList.add('on');
        state.compTab = t.getAttribute('data-tab');
        renderCompanion();
      });
    });

    if (BOOT.projectId) {
      loadProjectData().then(function () {
        state.project = BOOT.project;
        if (BOOT.initialStep) {
          // Honour an explicit landing step (e.g. after a fresh upload redirect).
          state.stepId = BOOT.initialStep;
        } else if (hasData()) {
          // Returning to an existing project with data — jump past the gate steps.
          state.datamapConfirmed = true;
          state.stepId = 'setup';
        }
        render();
      });
    } else {
      render();
    }
  });

  // Expose minimal global so inline event handlers inside rendered HTML can navigate steps.
  window.QS = { go: function (stepId) { state.stepId = stepId; render(); } };

})();
