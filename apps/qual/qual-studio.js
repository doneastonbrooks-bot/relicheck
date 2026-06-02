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
    var hasProject = !!BOOT.projectId;
    var segCount   = state.projectData ? state.projectData.seg_count : 0;

    var html = '<div class="ws-header"><div class="eyebrow">Qualitative Analysis Studio</div>'
      + '<h1 class="start-hero">Turn words into <span class="accent">evidence.</span></h1>'
      + '<p class="lede">Bring in your qualitative data, confirm what was loaded, and map your variables before coding begins.</p>'
      + '</div>';

    if (hasProject && segCount > 0) {
      html += '<div class="begin-loaded"><span class="dot"></span>'
        + '<span style="font-weight:700;color:var(--ink-2)">Data loaded</span>'
        + '<span>' + esc(BOOT.projectLabel) + ' &middot; ' + segCount + ' segments</span>'
        + '<button class="btn primary" style="margin-left:auto" id="stOverview">Go to Overview &rarr;</button>'
        + '</div>';
    }

    if (!hasProject) {
      // No project yet — show creation form
      html += '<div class="panel"><div class="panel-h"><h3>New project</h3></div><div class="panel-b">'
        + '<div class="field"><label>Project title <span style="color:#c0392b">*</span></label>'
        + '<input id="st-title" placeholder="e.g. Staff Experience Open-Ends 2026"></div>'
        + '<div class="grid2">'
        + '<div class="field"><label>Analysis approach</label><select id="st-approach">'
        + '<option value="thematic">Thematic Analysis</option>'
        + '<option value="content">Content Analysis</option>'
        + '<option value="framework">Framework Analysis</option>'
        + '<option value="open_ended_survey">Open-Ended Survey Analysis</option>'
        + '<option value="document">Document Analysis</option>'
        + '</select></div>'
        + '<div class="field"><label>Research question <span style="font-weight:400;color:var(--ink-3)">(optional)</span></label>'
        + '<input id="st-rq" placeholder="What are participants saying about..."></div>'
        + '</div>'
        + '<div id="st-err" style="display:none;font-size:13px;color:#c0392b;margin-bottom:8px;"></div>'
        + '<div class="btn-row"><button class="btn primary" id="st-create">Create project</button>'
        + '<a href="/qual-studio.php" class="btn">All projects</a></div>'
        + '</div></div>';
    } else {
      // Project exists — show upload card
      html += '<button class="begin-feature" id="stUpload">'
        + '<span class="bc-ico">&#8681;</span>'
        + '<div><h4>Upload qualitative data</h4>'
        + '<p>CSV or XLSX with open-ended responses. The studio detects text columns and loads each response as a codeable segment with participant context.</p>'
        + '<span class="bc-go">Upload data &rarr;</span></div></button>'
        + '<div class="begin-sec">Or</div>'
        + '<div class="begin-grid2">'
        + '<button class="begin-card2" id="stProjects"><span class="bc-ico" style="font-size:18px">&#9638;</span><h4>Open a different project</h4><p>Return to the project list.</p></button>'
        + '</div>';
    }

    host.innerHTML = html;

    var ov = document.getElementById('stOverview');
    if (ov) ov.addEventListener('click', function () { state.stepId = 'overview'; render(); });

    var create = document.getElementById('st-create');
    if (create) create.addEventListener('click', function () {
      var title = (document.getElementById('st-title').value || '').trim();
      var err   = document.getElementById('st-err');
      if (!title) { err.textContent = 'A project title is required.'; err.style.display = 'block'; return; }
      err.style.display = 'none';
      create.disabled = true; create.textContent = 'Creating...';
      api('/api/qual/create-project.php', {
        method: 'POST',
        body: JSON.stringify({
          title: title,
          analysis_approach: (document.getElementById('st-approach') || {}).value || 'thematic',
          research_question: (document.getElementById('st-rq') || {}).value.trim(),
        }),
      }).then(function (r) {
        window.location.href = '/qual-studio-workspace.php?project_id=' + r.project_id;
      }).catch(function (e) {
        err.textContent = 'Error: ' + e.message; err.style.display = 'block';
        create.disabled = false; create.textContent = 'Create project';
      });
    });

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
        api('/api/qual/link-dataset.php', {
          method: 'POST',
          body: JSON.stringify({ project_id: BOOT.projectId, dataset_id: datasetId }),
        }).then(function (r) {
          StudioFooter.setDataInfo(r.seg_count, 1);
          loadProjectData().then(function () { state.stepId = 'overview'; render(); });
        }).catch(function (e) {
          if (notice) notice.innerHTML = '<div class="notice err">Could not link dataset: ' + esc(e.message) + '</div>';
        });
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

    familiarize: function (host) {
      var d = state.projectData;
      host.innerHTML = '<div class="ws-header"><div class="eyebrow">Step 5</div>'
        + '<h1 class="title">Familiarization</h1>'
        + '<p class="lede">Explore the corpus before formal coding. Read through responses and record your first impressions.</p></div>'
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
        + '</div></div>';

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
    },

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
            + flag + '</div></div>';
        }

        host.innerHTML = '<div class="ws-header"><div class="eyebrow">Step 6</div>'
          + '<h1 class="title">Coding Workspace</h1>'
          + '<p class="lede">Apply codes to each segment. Participant context appears above every response.</p></div>'
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

        // Event delegation for picker, apply, remove, new-code
        document.getElementById('seg-list').addEventListener('click', function (e) {
          var addBtn   = e.target.closest('.add-code-btn');
          var item     = e.target.closest('.picker-item');
          var newBtn   = e.target.closest('.picker-new-btn');
          var removeBtn= e.target.closest('.chip-x');

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
        });

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
    setup:       '<p>The research question and researcher stance become part of the audit trail. Reviewers and trustworthiness checks will reference them to assess whether the analysis is grounded and transparent.</p>',
    familiarize: '<p>Read through the data before coding. The First Impressions Memo is evidence of <strong>reflexivity</strong> and early <strong>credibility</strong> work. The Trustworthiness Review will check that it exists.</p>',
    coding:      '<p>Codes should capture <strong>meaning</strong>, not just topic. Ask: what is this person saying, not just what words did they use?</p><p><strong>Uncoded</strong> segments have no code yet. <strong>Over-coded (4+)</strong> may indicate an overly broad code.</p>',
    codebook:    '<p>A code name should be descriptive, not a single vague word. <strong>"Lack of administrative support"</strong> is better than <strong>"support."</strong></p><p>Write definitions specific enough that a second coder would apply the code to the same responses.</p>',
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
        // If data is already loaded, auto-advance past start
        if (hasData()) {
          state.datamapConfirmed = true; // assume map was confirmed on prior session
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
