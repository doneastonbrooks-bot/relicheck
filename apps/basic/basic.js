/* =============================================================================
   basic.js — ReliCheck Basic. Studio-STYLED guided flow with a faithful
   (Basic-only) clone of the SIRI survey builder.

   Steps, all in one template (left step rail + workspace):
     1) Build      — name the survey, then a real ReliCheck-style builder
                     (composer + type palette + live clarity checks + numbered
                     question cards), capped at 10 questions. This is the teaser:
                     it feels like authoring in ReliCheck.
     2) Readiness  — Basic SIRI score + a plain explanation (LaunchCheck engine)
     3) Share      — publish + open responses, share to up to 25 people
     4) Results    — Basic RSSI score if the data allows (RSSIEngine)

   Reuses the existing engines/endpoints; no studio engines mounted, no labs,
   no full reports/diagnostics/exports, no constructs panel (an upgrade reason).
   Reliability/strength are headline scores + explanation only. See
   [[project_relicheck_basic]].
   ============================================================================= */
(function () {
  'use strict';

  var root = document.getElementById('basicRoot');
  if (!root) return;

  var projectId = parseInt(root.getAttribute('data-project-id') || '0', 10) || 0;
  var BASIC_CAP = 25, MAX_ITEMS = 10;
  var state = {
    project: null, items: [], siri: null, rssi: null, deployment: null, responses: 0,
    step: 'build', composer: null, composerRef: null, busy: false
  };

  // Curated question types for Basic (a real subset of the SIRI builder).
  var QT = {
    'Multiple Choice': { label: 'Multiple Choice', editOpts: true, defOpts: ['Option 1', 'Option 2', 'Option 3'] },
    'Checkboxes':      { label: 'Multiple Answers / Checkboxes', editOpts: true, defOpts: ['Option 1', 'Option 2', 'Option 3'] },
    'Dropdown':        { label: 'Dropdown', editOpts: true, defOpts: ['Option 1', 'Option 2', 'Option 3'] },
    'Yes/No':          { label: 'Yes / No', defOpts: ['Yes', 'No'] },
    'Likert (5-pt)':   { label: 'Likert (5-pt)', scale: 5 },
    'Likert (7-pt)':   { label: 'Likert (7-pt)', scale: 7 },
    'Rating':          { label: 'Rating Scale', settings: { max: 5 } },
    'Short Answer':    { label: 'Short Answer' },
    'Long Answer':     { label: 'Long Answer' },
    'Numeric':         { label: 'Numeric' }
  };
  var QGROUPS = [
    { name: 'Rating questions', types: ['Likert (5-pt)', 'Likert (7-pt)', 'Rating'] },
    { name: 'Choice questions', types: ['Multiple Choice', 'Checkboxes', 'Dropdown', 'Yes/No'] },
    { name: 'Open & number',    types: ['Short Answer', 'Long Answer', 'Numeric'] }
  ];

  var STEPS = [
    { key: 'build',    n: 1, label: 'Build',         sub: 'Your questions' },
    { key: 'siri',     n: 2, label: 'SIRI score',    sub: 'Is it ready?' },
    { key: 'preview',  n: 3, label: 'Preview',       sub: 'How it looks' },
    { key: 'publish',  n: 4, label: 'Publish',       sub: 'Create the link' },
    { key: 'deploy',   n: 5, label: 'Deploy',        sub: 'Open to 25 people' },
    { key: 'retrieve', n: 6, label: 'Retrieve data', sub: 'Results & RSSI' }
  ];
  var ANALYZE_NOTE = 'Descriptive and statistical analysis live in the Descriptive Analysis and Inferential Statistics studios.';

  function e(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function fmt1(x) { return (x == null || isNaN(x)) ? '—' : Number(x).toFixed(1); }
  function typeLabel(t) { return (QT[t] && QT[t].label) || t; }

  async function api(path, opts) {
    opts = opts || {};
    var res = await fetch('/api/dev/' + path, {
      method: opts.method || 'GET', credentials: 'same-origin',
      headers: Object.assign({ Accept: 'application/json' }, opts.body ? { 'Content-Type': 'application/json' } : {}),
      body: opts.body ? JSON.stringify(opts.body) : undefined
    });
    var data = null; try { data = await res.json(); } catch (x) {}
    if (!res.ok || (data && data.error)) { var err = new Error((data && (data.message || data.error)) || ('Request failed (' + res.status + ')')); err.code = data && data.error; throw err; }
    return data;
  }

  function lockSvg() { return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>'; }
  function openInProject(link) {
    if (!link) return;
    link.addEventListener('click', function (ev) { ev.preventDefault(); try { window.localStorage.setItem('sds_project_id', String(projectId)); } catch (x) {} window.location.href = '/develop.php?db=1&project_id=' + encodeURIComponent(projectId); });
  }
  function upgradeTeaser(o) {
    return '<div class="rb-upgrade"><div class="rb-upgrade-head"><strong>' + e(o.title) + '</strong><p>' + e(o.blurb) + '</p></div>'
      + '<ul class="rb-locked-list">' + o.items.map(function (i) { return '<li>' + lockSvg() + e(i) + '</li>'; }).join('') + '</ul>'
      + '<div class="rb-upgrade-foot"><a class="rb-upgrade-link" href="/develop.php?db=1&project_id=' + projectId + '">' + e(o.cta) + ' →</a></div></div>';
  }

  // ---- progress / gating ----
  function siriOk() { return !!(state.siri && state.siri.total != null && !state.siri.blocked); }
  function done(k) {
    if (k === 'build') return !!(state.project && state.items.length);
    if (k === 'siri') return !!(state.siri && state.siri.total != null);
    if (k === 'preview') return done('publish');
    if (k === 'publish') return !!(state.deployment && state.deployment.link_key);
    if (k === 'deploy') return !!(state.deployment && state.deployment.responses_open);
    if (k === 'retrieve') return !!(state.rssi && (state.rssi.total != null || state.rssi.withheld));
    return false;
  }
  function locked(k) {
    if (k === 'build') return false;
    if (k === 'siri') return !(state.project && state.items.length);
    if (k === 'preview') return !done('siri');
    if (k === 'publish') return !done('siri');
    if (k === 'deploy') return !done('publish');
    if (k === 'retrieve') return !done('deploy');
    return true;
  }

  function buildProj() {
    var p = state.project || {}; var settings = (p.settings && typeof p.settings === 'object') ? p.settings : {};
    return {
      purpose: p.purpose || '', population: p.population || '', mode: p.response_mode || '', dataType: p.data_type || '',
      launchReadiness: settings.launchReadiness || {}, constructs: [],
      items: state.items.map(function (it, i) { return { item_ref: 'q' + (it.id || i), item_no: i + 1, type: it.type || '', prompt: it.prompt || '', options: it.options || [], settings: it.settings || {}, construct: '', required: !!it.required }; }),
      sections: []
    };
  }

  async function load() {
    var r = await api('project-load.php?id=' + projectId);
    state.project = r.project; state.items = r.items || []; state.siri = r.siri; state.rssi = r.rssi; state.deployment = r.deployment; state.responses = r.responses || 0;
  }
  async function persistItems() {
    var payload = state.items.map(function (it) { var o = { type: it.type, prompt: it.prompt }; if (it.id) o.id = it.id; if (it.options && it.options.length) o.options = it.options; if (it.settings && Object.keys(it.settings).length) o.settings = it.settings; o.required = !!it.required; return o; });
    var r = await api('items-save.php', { method: 'POST', body: { project_id: projectId, items: payload } });
    state.items = r.items || state.items;
    state.siri = null; // editing invalidates the readiness score
  }

  async function boot() {
    try {
      if (projectId > 0) {
        await load();
        state.step = 'results';
        for (var i = 0; i < STEPS.length; i++) { var k = STEPS[i].key; if (!locked(k) && !done(k)) { state.step = k; break; } }
      } else { state.step = 'build'; }
      render();
    } catch (x) { root.innerHTML = '<div class="rb-card"><p class="rb-err">' + e(x.message) + '</p></div>'; }
  }

  // ---- shell ----
  function render() {
    var title = state.project ? state.project.title : 'New Basic survey';
    var dc = STEPS.filter(function (s) { return done(s.key); }).length;
    root.innerHTML =
      '<div class="rb-head"><div class="rb-head-main"><span class="rb-eyebrow">ReliCheck Basic · Free</span>'
      + '<h1>' + e(title) + '</h1><p class="rb-sub">Build a short survey, see if it is ready, share it with up to ' + BASIC_CAP + ' people, and get a strength score.</p></div>'
      + '<span class="rb-progress">' + dc + ' / ' + STEPS.length + ' steps</span></div>'
      + '<div class="rb-body"><nav class="rb-rail" id="rbRail"></nav><section class="rb-main" id="rbMain"></section></div>'
      + '<p class="rb-foot-note">' + e(ANALYZE_NOTE) + '</p>';
    renderRail(); renderStep();
  }
  function renderRail() {
    var rail = document.getElementById('rbRail');
    rail.innerHTML = '<div class="rail-title">Your survey</div>' + STEPS.map(function (s) {
      var cls = 'rb-step', isLocked = locked(s.key);
      if (s.key === state.step) cls += ' is-active'; else if (done(s.key)) cls += ' is-done'; else if (isLocked) cls += ' is-locked';
      var num = (done(s.key) && s.key !== state.step) ? '✓' : s.n;
      return '<button class="' + cls + '" data-step="' + s.key + '"' + (isLocked ? ' disabled' : '') + '><span class="num">' + num + '</span><span class="lbl">' + e(s.label) + '<small>' + e(s.sub) + '</small></span></button>';
    }).join('');
    Array.prototype.forEach.call(rail.querySelectorAll('.rb-step'), function (b) { b.addEventListener('click', function () { var k = b.getAttribute('data-step'); if (!locked(k)) { state.step = k; render(); } }); });
  }
  function renderStep() {
    var m = document.getElementById('rbMain');
    if (state.step === 'build') return renderBuild(m);
    if (state.step === 'siri') return renderSiri(m);
    if (state.step === 'preview') return renderPreview(m);
    if (state.step === 'publish') return renderPublish(m);
    if (state.step === 'deploy') return renderDeploy(m);
    if (state.step === 'retrieve') return renderRetrieve(m);
  }

  // ============================ STEP 1 · BUILD ============================
  function renderBuild(m) {
    if (!projectId) {
      m.innerHTML = '<div class="rb-card rb-name-card"><div class="rb-card-head"><span class="rb-badge">Step 1</span><h2>Name your survey</h2></div>'
        + '<p class="rb-card-lede">Give your survey a title, then build your questions on the next screen.</p>'
        + '<div class="rb-field"><input class="rb-input" id="rbTitle" maxlength="120" placeholder="e.g. Team Climate Pulse"></div>'
        + '<div class="rb-actions"><button class="rb-btn" id="rbName" type="button">Start building →</button><span class="rb-mini" id="rbNameMsg"></span></div></div>';
      document.getElementById('rbName').addEventListener('click', startSurvey);
      var ti = document.getElementById('rbTitle'); if (ti) ti.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') startSurvey(); });
      return;
    }
    renderBuilder(m);
  }

  async function startSurvey() {
    var msg = document.getElementById('rbNameMsg'); var btn = document.getElementById('rbName');
    var title = (document.getElementById('rbTitle').value || '').trim() || 'Untitled Basic survey';
    btn.disabled = true; msg.textContent = 'Creating…';
    try {
      var r = await api('project-create.php', { method: 'POST', body: { title: title, tier: 'basic', source: 'scratch' } });
      projectId = r.project.id; state.project = r.project; state.items = r.items || [];
      if (history.replaceState) history.replaceState(null, '', '/relicheck-basic-workspace.php?project_id=' + projectId);
      render();
    } catch (x) { btn.disabled = false; msg.textContent = x.message; }
  }

  function renderBuilder(m) {
    var n = state.items.length;
    m.innerHTML =
      '<div class="rb-builder"><div class="rb-card" style="padding:18px 20px;margin-bottom:16px"><div class="rb-card-head"><span class="rb-badge">Step 1 · Build</span><h2>Build your survey</h2></div>'
      + '<p class="rb-card-lede">Write one question at a time. It drops into your survey below as you save. Up to ' + MAX_ITEMS + ' questions in Basic.</p></div>'
      + '<div class="rb-composer-grid"><div class="rb-build-main">'
      + composerCenter()
      + '<div class="rb-saved-head"><h2>' + n + ' question' + (n === 1 ? '' : 's') + ' in your survey</h2><span class="sp"></span>'
      + (n < MAX_ITEMS ? '<button class="rb-btn-ghost" id="rbNewQ" type="button">+ New question</button>' : '<span class="rb-mini">10-question limit reached</span>') + '</div>'
      + (n ? state.items.map(savedCard).join('') : '<p class="rb-cap-note">No questions yet. Pick a type on the right to begin.</p>')
      + (n ? '<div class="rb-actions"><button class="rb-btn" id="rbToReady" type="button">Continue to SIRI score →</button></div>' : '')
      + '</div>' + palette() + '</div></div>';

    wireBuilder(m);
  }

  function palette() {
    return '<aside class="rb-palette"><h3>Add a question</h3>'
      + QGROUPS.map(function (g) {
        return '<h3 style="margin-top:10px">' + e(g.name) + '</h3>' + g.types.map(function (t) {
          return '<button class="rb-ptype" data-type="' + e(t) + '" type="button">' + e(QT[t].label) + '</button>';
        }).join('');
      }).join('')
      + '<p class="rb-palette-note">Construct mapping, readiness fixes, and more open in full SIRI.</p></aside>';
  }

  function composerCenter() {
    var c = state.composer;
    if (!c) {
      return '<div class="rb-composer-empty rb-card" style="text-align:center;padding:34px 22px">'
        + '<h3 style="margin:0 0 6px;font-size:18px;font-weight:700;color:var(--ink-1,#1c2238)">Write your first question</h3>'
        + '<p class="rb-card-lede" style="margin:0">Pick a question type on the right. It opens here so you can write it, then save.</p></div>';
    }
    var editing = state.composerRef != null;
    var num = editing ? state.composerRef + 1 : state.items.length + 1;
    return '<div class="rb-composer"><div class="rb-composer-head"><span class="rb-composer-eyebrow">' + (editing ? 'Editing question ' + num : 'Question ' + num) + ' · ' + e(typeLabel(c.type)) + '</span>'
      + '<button class="rb-qclose" type="button" onclick="RB.cancel()" title="Cancel">×</button></div>'
      + '<textarea class="rb-composer-q" placeholder="Type your question here…" oninput="RB.setText(this.value)" onchange="RB.refresh()">' + e(c.t || '') + '</textarea>'
      + composerSettings(c)
      + '<div class="rb-composer-prev"><div class="lbl">How respondents see it</div>' + qPreview(c) + '</div>'
      + composerChecks(c)
      + '<div class="rb-composer-foot"><button class="rb-btn" type="button" onclick="RB.save()">' + (editing ? 'Update question' : 'Save question') + '</button>'
      + '<button class="rb-btn-ghost" type="button" onclick="RB.cancel()">Cancel</button></div></div>';
  }

  function composerSettings(c) {
    var def = QT[c.type] || {}; var body = '';
    body += '<div class="rb-field"><label>Response</label><button class="rb-reqtoggle ' + (c.required ? 'on' : '') + '" type="button" onclick="RB.toggleReq()">' + (c.required ? 'Required' : 'Optional') + '</button></div>';
    if (def.editOpts) {
      var opts = c.options || [];
      body += '<div class="rb-field"><label>Answer options</label>' + opts.map(function (op, oi) {
        return '<div class="rb-opt-row"><input value="' + e(op) + '" oninput="RB.setOpt(' + oi + ',this.value)"><button class="rb-opt-del" type="button" onclick="RB.delOpt(' + oi + ')">×</button></div>';
      }).join('') + '<button class="rb-btn-ghost" type="button" onclick="RB.addOpt()">+ Add option</button></div>';
    }
    if (c.type === 'Rating') {
      var mx = (c.settings && c.settings.max) || 5;
      body += '<div class="rb-field"><label>Highest rating</label><select onchange="RB.setSetting(\'max\',this.value)">' + [3, 4, 5, 7, 10].map(function (x) { return '<option ' + (x === mx ? 'selected' : '') + '>' + x + '</option>'; }).join('') + '</select></div>';
    }
    return body ? '<div class="rb-csettings">' + body + '</div>' : '';
  }

  function qPreview(q) {
    var t = q.type, opts = (q.options && q.options.length) ? q.options : null;
    var list = function (arr, kind) { return arr.map(function (x) { return '<label class="rb-pvopt"><input type="' + kind + '" disabled> ' + e(x) + '</label>'; }).join(''); };
    if (t === 'Multiple Choice') return list(opts || ['Option 1', 'Option 2'], 'radio');
    if (t === 'Checkboxes') return list(opts || ['Option 1', 'Option 2'], 'checkbox');
    if (t === 'Dropdown') return '<select class="rb-pvinput" disabled>' + (opts || ['Choose…']).map(function (x) { return '<option>' + e(x) + '</option>'; }).join('') + '</select>';
    if (t === 'Yes/No') return list(['Yes', 'No'], 'radio');
    if (t === 'Likert (5-pt)' || t === 'Likert (7-pt)') { var nn = t === 'Likert (7-pt)' ? 7 : 5; return '<div class="rb-pvscale">' + Array.from({ length: nn }, function (_, i) { return '<span>' + (i + 1) + '</span>'; }).join('') + '</div>'; }
    if (t === 'Rating') { var mm = (q.settings && q.settings.max) || 5; return '<div class="rb-pvscale">' + Array.from({ length: mm }, function () { return '<span>★</span>'; }).join('') + '</div>'; }
    if (t === 'Short Answer') return '<input class="rb-pvinput" disabled placeholder="Short answer">';
    if (t === 'Long Answer') return '<textarea class="rb-pvinput" rows="2" disabled placeholder="Long answer"></textarea>';
    if (t === 'Numeric') return '<input class="rb-pvinput" disabled placeholder="0">';
    return '<input class="rb-pvinput" disabled placeholder="Response">';
  }

  // Live clarity heuristics (teaser of ReliCheck's intelligence — front-end only).
  function composerChecks(c) {
    var t = (c.t || '').trim(), words = t ? t.split(/\s+/).length : 0;
    var dbl = /\b(and|or)\b|\/|,/.test(t) && words > 3, lng = words >= 20, vag = t !== '' && words > 0 && words < 4;
    var checks = [
      { nm: 'Single idea (not double-barreled)', ok: !dbl },
      { nm: 'Readable length', ok: !lng },
      { nm: 'Specific enough to interpret', ok: !vag }
    ];
    return '<div class="rb-checks"><div class="eyebrow">Quality checks · this question</div>'
      + checks.map(function (k) { return '<div class="rb-lens"><span class="rb-ckdot ' + (k.ok ? 'ok' : 'warn') + '"></span><span>' + e(k.nm) + '</span><span class="rb-pill ' + (k.ok ? 'ok' : 'warn') + '">' + (k.ok ? 'on track' : 'check') + '</span></div>'; }).join('')
      + '<p class="rb-cap-note">A quick read on the question you are writing. Full item review is in SIRI.</p></div>';
  }

  function savedCard(it, i) {
    var n = state.items.length; var active = state.composerRef === i;
    return '<div class="rb-qcard ' + (active ? 'active' : '') + '" data-edit="' + i + '"><div class="qn">' + (i + 1) + '</div>'
      + '<div class="rb-qcard-body"><div class="rb-qcard-top"><span class="rb-qcard-text">' + e(it.prompt || 'Untitled question') + '</span><span class="rb-qbadge">' + e(typeLabel(it.type)) + '</span></div>'
      + '<div class="rb-qprev">' + qPreview(it) + '</div></div>'
      + '<div class="rb-qcard-actions"><button class="rb-reqtoggle ' + (it.required ? 'on' : '') + '" data-req="' + i + '" title="Toggle required">' + (it.required ? 'Required' : 'Optional') + '</button>'
      + '<div class="rb-qbtns"><button data-edit="' + i + '" title="Edit">✎</button><button data-up="' + i + '" title="Up"' + (i === 0 ? ' disabled' : '') + '>↑</button><button data-down="' + i + '" title="Down"' + (i === n - 1 ? ' disabled' : '') + '>↓</button><button data-del="' + i + '" title="Delete">✕</button></div></div></div>';
  }

  function wireBuilder(m) {
    var nq = document.getElementById('rbNewQ'); if (nq) nq.addEventListener('click', function () { RB.cancel(); window.scrollTo(0, 0); });
    var toReady = document.getElementById('rbToReady'); if (toReady) toReady.addEventListener('click', function () { state.step = 'siri'; render(); });
    Array.prototype.forEach.call(m.querySelectorAll('.rb-ptype'), function (b) { b.addEventListener('click', function () { RB.newComposer(b.getAttribute('data-type')); }); });
    Array.prototype.forEach.call(m.querySelectorAll('[data-edit]'), function (b) { b.addEventListener('click', function (ev) { ev.stopPropagation(); RB.edit(parseInt(b.getAttribute('data-edit'), 10)); }); });
    Array.prototype.forEach.call(m.querySelectorAll('[data-del]'), function (b) { b.addEventListener('click', function (ev) { ev.stopPropagation(); RB.del(parseInt(b.getAttribute('data-del'), 10)); }); });
    Array.prototype.forEach.call(m.querySelectorAll('[data-up]'), function (b) { b.addEventListener('click', function (ev) { ev.stopPropagation(); RB.move(parseInt(b.getAttribute('data-up'), 10), -1); }); });
    Array.prototype.forEach.call(m.querySelectorAll('[data-down]'), function (b) { b.addEventListener('click', function (ev) { ev.stopPropagation(); RB.move(parseInt(b.getAttribute('data-down'), 10), 1); }); });
    Array.prototype.forEach.call(m.querySelectorAll('[data-req]'), function (b) { b.addEventListener('click', function (ev) { ev.stopPropagation(); RB.toggleSavedReq(parseInt(b.getAttribute('data-req'), 10)); }); });
  }

  function reBuild() { if (state.step === 'build') { renderBuilder(document.getElementById('rbMain')); } renderRail(); }

  // window.RB — composer/builder handlers (inline-callable, like the real builder's App.*)
  window.RB = {
    newComposer: function (type) {
      if (state.items.length >= MAX_ITEMS) return;
      var def = QT[type] || {};
      state.composer = { type: type, t: '', required: false, options: def.editOpts ? (def.defOpts || ['Option 1', 'Option 2']).slice() : (def.defOpts || null), settings: def.settings ? Object.assign({}, def.settings) : {} };
      state.composerRef = null; reBuild();
    },
    edit: function (i) { var it = state.items[i]; if (!it) return; state.composer = { type: it.type, t: it.prompt, required: !!it.required, options: (it.options || []).slice(), settings: Object.assign({}, it.settings || {}) }; state.composerRef = i; reBuild(); window.scrollTo(0, 0); },
    cancel: function () { state.composer = null; state.composerRef = null; reBuild(); },
    setText: function (v) { if (state.composer) state.composer.t = v; },
    refresh: function () { reBuild(); }, // on blur: refresh preview + clarity checks
    toggleReq: function () { if (state.composer) { state.composer.required = !state.composer.required; reBuild(); } },
    setOpt: function (oi, v) { if (state.composer && state.composer.options) state.composer.options[oi] = v; },
    addOpt: function () { if (state.composer) { state.composer.options = state.composer.options || []; state.composer.options.push('Option ' + (state.composer.options.length + 1)); reBuild(); } },
    delOpt: function (oi) { if (state.composer && state.composer.options) { state.composer.options.splice(oi, 1); reBuild(); } },
    setSetting: function (k, v) { if (state.composer) { state.composer.settings = state.composer.settings || {}; state.composer.settings[k] = parseInt(v, 10) || v; reBuild(); } },
    save: async function () {
      var c = state.composer; if (!c) return;
      if (!(c.t || '').trim()) { return; }
      var item = { type: c.type, prompt: c.t.trim() };
      if (QT[c.type] && QT[c.type].editOpts && c.options) item.options = c.options.slice();
      if (c.type === 'Yes/No') item.options = ['Yes', 'No'];
      if (c.settings && Object.keys(c.settings).length) item.settings = Object.assign({}, c.settings);
      item.required = !!c.required;
      if (state.composerRef != null) { item.id = state.items[state.composerRef].id; state.items[state.composerRef] = item; }
      else { state.items.push(item); }
      state.composer = null; state.composerRef = null;
      try { await persistItems(); } catch (x) {}
      reBuild();
    },
    del: async function (i) { state.items.splice(i, 1); if (state.composerRef === i) { state.composer = null; state.composerRef = null; } try { await persistItems(); } catch (x) {} reBuild(); },
    move: async function (i, d) { var j = i + d; if (j < 0 || j >= state.items.length) return; var t = state.items[i]; state.items[i] = state.items[j]; state.items[j] = t; try { await persistItems(); } catch (x) {} reBuild(); },
    toggleSavedReq: async function (i) { if (!state.items[i]) return; state.items[i].required = !state.items[i].required; try { await persistItems(); } catch (x) {} reBuild(); }
  };

  // ============================ STEP 2 · SIRI SCORE ============================
  // Just the score + a plain explanation, then advance. No re-run, no teaser,
  // no full-SIRI link here (per product direction).
  function renderSiri(m) {
    var s = state.siri;
    if (!s) {
      m.innerHTML = '<div class="rb-card"><div class="rb-card-head"><span class="rb-badge">Basic SIRI</span><h2>Is it ready to send?</h2></div>'
        + '<p class="rb-card-lede">Scoring your survey…</p><div class="rb-actions"><button class="rb-btn" id="rbSiriRun" type="button">Get Basic SIRI score</button><span class="rb-mini" id="rbSiriMsg"></span></div></div>';
      var b = document.getElementById('rbSiriRun'); if (b) b.addEventListener('click', runSiri);
      runSiri();
      return;
    }
    var pct = s.pct != null ? s.pct : s.total;
    var statusLine = pct >= 80 ? 'This survey looks ready to send.' : pct >= 60 ? 'Close — a few things would make it stronger before you send.' : 'Not ready yet — review your questions before sending.';
    var explain = 'Your <strong>Basic SIRI score</strong> estimates how ready this survey is to send out — whether your questions are clear, your scales are consistent, and it is set up to collect trustworthy answers. A higher score means fewer surprises once responses arrive.';
    m.innerHTML = '<div class="rb-card"><div class="rb-card-head"><span class="rb-badge">Basic SIRI</span><h2>Is it ready to send?</h2></div>'
      + '<div class="rb-scorerow"><div class="rb-score">' + fmt1(s.total) + '<span>/ ' + (s.max || 100) + '</span></div>' + (s.band ? '<span class="rb-band">' + e(s.band) + '</span>' : '') + '</div>'
      + '<div class="rb-explain">' + explain + '</div>'
      + '<p class="rb-status">' + e(statusLine) + '</p>'
      + '<div class="rb-actions"><button class="rb-btn" id="rbToPreview" type="button">Continue to preview →</button>'
      + '<button class="rb-btn-ghost" id="rbBackBuild" type="button">Back to Build</button></div></div>';
    var c = document.getElementById('rbToPreview'); if (c) c.addEventListener('click', function () { state.step = 'preview'; render(); });
    var bb = document.getElementById('rbBackBuild'); if (bb) bb.addEventListener('click', function () { state.step = 'build'; render(); });
  }
  function runSiri() {
    var msg = document.getElementById('rbSiriMsg');
    if (!(window.LaunchCheck && window.LaunchCheck.assess)) { if (msg) msg.textContent = 'Scoring engine unavailable.'; return; }
    try {
      var proj = buildProj();
      var sdsi = (window.BuildCheck && window.BuildCheck.assess) ? window.BuildCheck.assess(proj) : null;
      var r = window.LaunchCheck.assess(proj, { sdsiResult: sdsi });
      state.siri = { total: r.totalPoints, max: r.maxPoints, pct: r.pct, band: (r.band && r.band.label) || '', blocked: !!r.blocked, review: r };
      api('siri-save.php', { method: 'POST', body: { project_id: projectId, total: r.totalPoints, max: r.maxPoints, pct: r.pct, band: (r.band && r.band.label) || '', blocked: !!r.blocked, review: r } }).catch(function () {});
      renderRail(); renderStep();
    } catch (x) { if (msg) msg.textContent = x.message; }
  }

  // ============================ STEP 3 · PREVIEW ============================
  function renderPreview(m) {
    var items = state.items || [];
    var body = items.length
      ? '<div class="rb-preview-list">' + items.map(function (it, i) { return '<div class="rb-prevq"><div class="rb-prevq-head"><span class="qn">' + (i + 1) + '</span><span class="rb-prevq-text">' + e(it.prompt || 'Untitled question') + '</span></div><div class="rb-qprev">' + qPreview(it) + '</div></div>'; }).join('') + '</div>'
      : '<p class="rb-card-lede">No questions yet.</p>';
    m.innerHTML = '<div class="rb-card"><div class="rb-card-head"><span class="rb-badge">Step 3</span><h2>Preview</h2></div>'
      + '<p class="rb-card-lede">This is how your survey looks to the people you send it to.</p>' + body
      + '<div class="rb-actions"><button class="rb-btn-ghost" id="rbPrevBack" type="button">Back to Build</button><button class="rb-btn" id="rbPrevNext" type="button">Looks good — Publish →</button></div></div>';
    document.getElementById('rbPrevBack').addEventListener('click', function () { state.step = 'build'; render(); });
    document.getElementById('rbPrevNext').addEventListener('click', function () { state.step = 'publish'; render(); });
  }

  // ============================ STEP 4 · PUBLISH ============================
  function renderPublish(m) {
    if (state.deployment && state.deployment.link_key) {
      m.innerHTML = '<div class="rb-card"><div class="rb-card-head"><span class="rb-badge">Step 4</span><h2>Published</h2></div>'
        + '<p class="rb-status">Your survey is published. Next, open it for responses and share the link.</p>'
        + '<div class="rb-actions"><button class="rb-btn" id="rbToDeploy" type="button">Continue to deploy →</button></div></div>';
      document.getElementById('rbToDeploy').addEventListener('click', function () { state.step = 'deploy'; render(); });
      return;
    }
    m.innerHTML = '<div class="rb-card"><div class="rb-card-head"><span class="rb-badge">Step 4</span><h2>Publish</h2></div>'
      + '<p class="rb-card-lede">Publish your survey to generate a secure share link. You will open it for responses on the next step.</p>'
      + '<div class="rb-actions"><button class="rb-btn" id="rbPublish" type="button">Publish survey</button><span class="rb-mini" id="rbPubMsg"></span></div></div>';
    document.getElementById('rbPublish').addEventListener('click', doPublish);
  }
  async function doPublish() {
    var msg = document.getElementById('rbPubMsg'); var btn = document.getElementById('rbPublish'); btn.disabled = true; msg.textContent = 'Publishing…';
    try {
      var p = await api('project-publish.php', { method: 'POST', body: { project_id: projectId } }); state.deployment = p.deployment || state.deployment;
      await load(); state.step = 'deploy'; render();
    } catch (x) {
      // Never bounce to SIRI from here — stay on Publish and show why.
      btn.disabled = false;
      msg.textContent = (x.code === 'siri_blocked' || x.code === 'siri_required')
        ? 'Could not publish yet — give the SIRI score a moment to finish, then try again.'
        : x.message;
    }
  }

  // ============================ STEP 5 · DEPLOY ============================
  function renderDeploy(m) {
    var dep = state.deployment; var linkKey = dep && dep.link_key ? dep.link_key : ''; var open = !!(dep && dep.responses_open);
    var count = state.responses || 0; var full = count >= BASIC_CAP;
    if (!open) {
      m.innerHTML = '<div class="rb-card"><div class="rb-card-head"><span class="rb-badge">Step 5</span><h2>Deploy</h2></div>'
        + '<p class="rb-card-lede">Open your survey for responses and share the link with up to ' + BASIC_CAP + ' people.</p>'
        + '<div class="rb-actions"><button class="rb-btn" id="rbOpen" type="button">Open for responses</button><span class="rb-mini" id="rbOpenMsg"></span></div></div>';
      document.getElementById('rbOpen').addEventListener('click', doOpen);
      return;
    }
    var url = location.origin + '/take.html?slug=' + encodeURIComponent(linkKey); var pf = Math.min(100, Math.round((count / BASIC_CAP) * 100));
    m.innerHTML = '<div class="rb-card"><div class="rb-card-head"><span class="rb-badge">Step 5</span><h2>Deploy</h2></div>'
      + '<p class="rb-card-lede">Send this link to up to ' + BASIC_CAP + ' people. Responses come back automatically.</p>'
      + '<div class="rb-share"><input class="rb-input" id="rbUrl" readonly value="' + e(url) + '"><button class="rb-btn-ghost" id="rbCopy" type="button">Copy</button></div>'
      + '<div class="rb-count' + (full ? ' full' : '') + '"><strong>' + count + '</strong> / ' + BASIC_CAP + ' responses' + (full ? ' · limit reached' : '') + '</div><div class="rb-meter"><i style="width:' + pf + '%"></i></div>'
      + '<div class="rb-actions"><button class="rb-btn" id="rbToRetrieve" type="button">Retrieve data →</button><button class="rb-btn-ghost" id="rbRefresh" type="button">Refresh</button>' + (count > 0 ? '' : '<span class="rb-mini">You can retrieve results any time — a strength score appears once enough responses come in.</span>') + '</div></div>';
    var cp = document.getElementById('rbCopy'); if (cp) cp.addEventListener('click', function () { var i = document.getElementById('rbUrl'); i.select(); try { document.execCommand('copy'); cp.textContent = 'Copied'; setTimeout(function () { cp.textContent = 'Copy'; }, 1500); } catch (x) {} });
    document.getElementById('rbRefresh').addEventListener('click', async function () { try { await load(); renderRail(); renderStep(); } catch (x) {} });
    var tr = document.getElementById('rbToRetrieve'); if (tr) tr.addEventListener('click', function () { state.step = 'retrieve'; render(); });
  }
  async function doOpen() {
    var msg = document.getElementById('rbOpenMsg'); var btn = document.getElementById('rbOpen'); btn.disabled = true; msg.textContent = 'Opening…';
    try { var o = await api('project-open.php', { method: 'POST', body: { project_id: projectId, open: true } }); state.deployment = o.deployment || state.deployment; await load(); renderRail(); renderStep(); }
    catch (x) { btn.disabled = false; msg.textContent = x.message; }
  }

  // ============================ STEP 6 · RETRIEVE DATA ============================
  function renderRetrieve(m) {
    var r = state.rssi; var has = !!(r && (r.total != null || r.withheld)); var count = state.responses || 0;
    var upgrade = '<p class="rb-upgrade-line">Want the full breakdown — reliability, validity, item analysis, and a defensible report? <a class="rb-upgrade-link" href="/develop.php?db=1&project_id=' + projectId + '">Open in ReliCheck →</a></p>';
    if (!has) {
      m.innerHTML = '<div class="rb-card"><div class="rb-card-head"><span class="rb-badge">Step 6</span><h2>Retrieve data</h2></div>'
        + '<div class="rb-count"><strong>' + count + '</strong> / ' + BASIC_CAP + ' responses collected</div>'
        + '<p class="rb-card-lede" style="margin-top:14px">Get a Basic RSSI score to see how strong your collected responses are.</p>'
        + '<div class="rb-actions"><button class="rb-btn" id="rbRssiRun" type="button">Get Basic RSSI score</button><button class="rb-btn-ghost" id="rbRefresh2" type="button">Refresh</button><span class="rb-mini" id="rbRssiMsg"></span></div></div>';
      document.getElementById('rbRssiRun').addEventListener('click', runRssi);
      document.getElementById('rbRefresh2').addEventListener('click', async function () { try { await load(); renderStep(); } catch (x) {} });
      return;
    }
    var inner;
    if (r.withheld) {
      inner = '<div class="rb-scorerow"><div class="rb-score withheld">Not enough data yet</div></div><div class="rb-explain">A <strong>Basic RSSI score</strong> measures how strong and trustworthy your responses are. There are not enough responses yet to judge reliably, so we are holding the score rather than showing a misleading number. Collect more responses (up to ' + BASIC_CAP + ') and try again.</div>';
    } else {
      inner = '<div class="rb-scorerow"><div class="rb-score">' + fmt1(r.total) + '<span>/ ' + (r.max || 100) + '</span></div>' + (r.band ? '<span class="rb-band">' + e(r.band) + '</span>' : '') + '</div><div class="rb-explain">Your <strong>Basic RSSI score</strong> reflects how strong and trustworthy your collected responses are — whether the answers hang together and tell a consistent story.</div>';
    }
    m.innerHTML = '<div class="rb-card"><div class="rb-card-head"><span class="rb-badge">Basic RSSI</span><h2>Retrieve data</h2></div>'
      + '<div class="rb-count" style="margin-bottom:14px"><strong>' + count + '</strong> / ' + BASIC_CAP + ' responses</div>' + inner
      + '<div class="rb-actions"><button class="rb-btn-ghost" id="rbRssiRerun" type="button">Re-score</button></div>' + upgrade + '</div>';
    document.getElementById('rbRssiRerun').addEventListener('click', runRssi);
    openInProject(m.querySelector('.rb-upgrade-link'));
  }
  async function runRssi() {
    var msg = document.getElementById('rbRssiMsg');
    if (!(window.RSSIEngine && window.RSSIEngine.score)) { if (msg) msg.textContent = 'Scoring engine unavailable.'; return; }
    var btn = document.getElementById('rbRssiRun') || document.getElementById('rbRssiRerun'); if (btn) btn.disabled = true; if (msg) msg.textContent = 'Scoring…';
    try {
      var dataset = await api('rssi-dataset.php?project_id=' + projectId);
      var result = window.RSSIEngine.score(dataset);
      var saved = await api('rssi-run.php', { method: 'POST', body: { project_id: projectId, result: result } });
      state.rssi = saved.rssi || { total: result.score, max: result.max, band: result.band, withheld: !!(result.fence && result.fence.withheld) };
      renderRail(); renderStep();
    } catch (x) { if (btn) btn.disabled = false; if (msg) msg.textContent = x.message; }
  }

  boot();
})();
