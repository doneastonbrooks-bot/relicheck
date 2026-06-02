/* Qualitative Analysis Studio — workspace controller
 * Modules: Setup, Import, Familiarize, Coding, Codebook
 */
'use strict';

// ─── App ────────────────────────────────────────────────────────────────────
const App = (() => {
  const state = {
    module:   '',
    project:  BOOT.project,
    segments: null,
    codes:    null,
    stats:    null,
    docs:     null,
  };

  function go(moduleId) {
    // If no project yet, only allow setup
    if (!BOOT.projectId && moduleId !== 'setup') {
      return;
    }
    state.module = moduleId;
    _setActive(moduleId);
    _show('loading');
    const fn = Screens[moduleId];
    if (fn) {
      fn().catch(err => {
        console.error(moduleId, err);
        _setContent('<div class="qs-notice err">Failed to load module. ' + (err.message || '') + '</div>');
      });
    } else {
      _setContent('<div class="qs-notice warn">Module not yet built.</div>');
    }
  }

  function _setActive(moduleId) {
    document.querySelectorAll('.qs-step').forEach(el => {
      el.classList.toggle('active', el.dataset.module === moduleId);
    });
  }

  function _show(which) {
    document.getElementById('qsLoading').style.display  = (which === 'loading') ? 'flex'  : 'none';
    document.getElementById('qsContent').style.display  = (which === 'content') ? 'block' : 'none';
  }

  function _setContent(html) {
    document.getElementById('qsContent').innerHTML = html;
    _show('content');
  }

  function _setGuide(html) {
    document.getElementById('qsGuideBody').innerHTML = html;
  }

  async function api(path, opts = {}) {
    const r = await fetch(path, {
      headers: { 'Content-Type': 'application/json' },
      ...opts,
    });
    const data = await r.json();
    if (!data.ok) throw new Error(data.message || data.error || 'API error');
    return data;
  }

  // Initialize
  function init() {
    if (BOOT.projectId) {
      go('setup');
    } else {
      _setContent(Render.noProject());
      _show('content');
    }
  }

  return { go, api, state, _setContent, _setGuide, _show, init };
})();


// ─── Render helpers ──────────────────────────────────────────────────────────
const Render = {
  noProject() {
    return `<div class="qs-empty">
      <div class="qs-empty-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
        </svg>
      </div>
      <div class="qs-empty-h">No project selected</div>
      <div class="qs-empty-body">Go back to the Qualitative Studio and open or create a project.</div>
      <a href="/qual-studio.php" class="qs-btn qs-btn-primary">Back to Qual Studio</a>
    </div>`;
  },

  approachLabel(slug) {
    return {
      thematic:          'Thematic Analysis',
      content:           'Content Analysis',
      framework:         'Framework Analysis',
      open_ended_survey: 'Open-Ended Survey Analysis',
      document:          'Document Analysis',
    }[slug] || slug;
  },
};


// ─── Screens ─────────────────────────────────────────────────────────────────
const Screens = {

  // ── Setup ─────────────────────────────────────────────────────────────────
  async setup() {
    const p = App.state.project || {};
    App._setContent(`
      <h1 class="qs-module-h">Project Setup</h1>
      <p class="qs-module-sub">Define your research question, approach, and researcher stance before coding begins.</p>

      <div class="qs-card">
        <div class="qs-card-h">Project information</div>
        <div class="qs-form-row">
          <label>Project title <span style="color:#c0392b">*</span></label>
          <input class="qs-input" id="setup-title" value="${_esc(p.title || '')}" placeholder="e.g. Staff Experience Open-Ends 2026">
        </div>
        <div class="qs-form-grid-2">
          <div class="qs-form-row">
            <label>Analysis approach</label>
            <select class="qs-select" id="setup-approach">
              ${['thematic','content','framework','open_ended_survey','document'].map(v =>
                `<option value="${v}"${(p.analysis_approach||'thematic')===v?' selected':''}>${Render.approachLabel(v)}</option>`
              ).join('')}
            </select>
          </div>
          <div class="qs-form-row">
            <label>Data type</label>
            <select class="qs-select" id="setup-datatype">
              ${[['open_ended_survey','Open-Ended Survey'],['interview','Interview Transcript'],
                 ['focus_group','Focus Group Transcript'],['document','Document / Field Notes']].map(([v,l]) =>
                `<option value="${v}"${(p.data_type||'open_ended_survey')===v?' selected':''}>${l}</option>`
              ).join('')}
            </select>
          </div>
        </div>
        <div class="qs-form-row">
          <label>Research question
            <span class="hint">What are you trying to understand or explain through this analysis?</span>
          </label>
          <input class="qs-input" id="setup-rq" value="${_esc(p.research_question || '')}" placeholder="What are participants saying about...">
        </div>
        <div class="qs-form-row">
          <label>Purpose of analysis
            <span class="hint">Who will use these findings, and for what decision?</span>
          </label>
          <input class="qs-input" id="setup-purpose" value="${_esc(p.purpose || '')}" placeholder="To inform the 2026 program redesign...">
        </div>
        <div class="qs-form-row">
          <label>Participant / context description</label>
          <input class="qs-input" id="setup-participants" value="${_esc(p.participant_description || '')}" placeholder="e.g. 412 K-12 educators across 3 districts">
        </div>
      </div>

      <div class="qs-card">
        <div class="qs-card-h">Researcher stance memo
          <span style="font-size:12px;font-weight:400;color:var(--ink-3);margin-left:8px;">saved to audit trail</span>
        </div>
        <div class="qs-form-row">
          <label>
            <span class="hint">What assumptions, roles, experiences, or expectations might shape how you interpret this data? Being transparent about this is part of what makes qualitative findings defensible.</span>
          </label>
          <textarea class="qs-textarea" id="setup-stance" style="min-height:110px;" placeholder="I am an external evaluator with no prior relationship to this program...">${_esc(p.researcher_stance_memo || '')}</textarea>
        </div>
      </div>

      <div class="qs-card">
        <div class="qs-card-h">Notes</div>
        <div class="qs-form-row">
          <textarea class="qs-textarea" id="setup-notes" placeholder="Any additional context, data limitations, or assumptions...">${_esc(p.notes || '')}</textarea>
        </div>
      </div>

      <div id="setup-msg" style="display:none;margin-top:4px;font-size:13px;"></div>
      <div class="qs-btn-row">
        <button class="qs-btn qs-btn-primary" onclick="Screens.saveSetup()">Save setup</button>
        <button class="qs-btn qs-btn-secondary" onclick="App.go('import')">Next: Import data</button>
      </div>
    `);

    App._setGuide(`
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Why this matters</div>
        <p>The research question and researcher stance become part of the audit trail. Reviewers and trustworthiness checks reference them to assess whether the analysis is grounded and transparent.</p>
      </div>
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Approach guide</div>
        <p><strong>Thematic Analysis</strong> — flexible, suitable for most survey and interview data. Find patterns of meaning across responses.</p>
        <p><strong>Content Analysis</strong> — more structured, often with predetermined categories. Good for document and media analysis.</p>
        <p><strong>Framework Analysis</strong> — applies a pre-existing analytic framework. Common in policy and evaluation work.</p>
        <p><strong>Open-Ended Survey Analysis</strong> — purpose-built for mixed-method survey open-ends where participants are also part of a quantitative dataset.</p>
      </div>
    `);
  },

  async saveSetup() {
    const msg = document.getElementById('setup-msg');
    const body = {
      project_id:             BOOT.projectId,
      title:                  document.getElementById('setup-title').value.trim(),
      analysis_approach:      document.getElementById('setup-approach').value,
      data_type:              document.getElementById('setup-datatype').value,
      research_question:      document.getElementById('setup-rq').value.trim(),
      purpose:                document.getElementById('setup-purpose').value.trim(),
      participant_description:document.getElementById('setup-participants').value.trim(),
      researcher_stance_memo: document.getElementById('setup-stance').value.trim(),
      notes:                  document.getElementById('setup-notes').value.trim(),
    };
    if (!body.title) { msg.textContent='Title is required.'; msg.style.cssText='display:block;color:#c0392b;'; return; }
    msg.textContent='Saving...'; msg.style.cssText='display:block;color:var(--ink-3);';
    try {
      await App.api('/api/qual/save-project.php', { method:'POST', body:JSON.stringify(body) });
      // Update local state
      Object.assign(App.state.project || (App.state.project = {}), body);
      BOOT.project = App.state.project;
      document.getElementById('projectContext').innerHTML =
        `<span class="qs-proj-ctx">${_esc(body.title)}</span>`;
      msg.textContent='Saved.'; msg.style.cssText='display:block;color:var(--qs-accent);';
      setTimeout(() => { msg.style.display='none'; }, 2000);
    } catch(e) {
      msg.textContent='Error: ' + e.message; msg.style.cssText='display:block;color:#c0392b;';
    }
  },

  // ── Import ────────────────────────────────────────────────────────────────
  async import() {
    // Load current project stats to show existing docs
    let docs = [], segCount = 0;
    try {
      const d = await App.api(`/api/qual/get-project.php?project_id=${BOOT.projectId}`);
      docs     = d.documents || [];
      segCount = d.stats?.seg_count || 0;
      App.state.docs  = docs;
      App.state.stats = d.stats;
    } catch(e) { /* show empty state */ }

    const docsHtml = docs.length ? `
      <hr class="qs-module-divider">
      <div class="qs-card-h" style="margin-bottom:12px;">Imported data sources</div>
      ${docs.map(d => `
        <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-top:1px solid var(--line);">
          <span style="font-size:22px;">📄</span>
          <div style="flex:1;">
            <div style="font-size:14px;font-weight:700;color:var(--ink);">${_esc(d.title)}</div>
            <div style="font-size:12px;color:var(--ink-3);">${_esc(d.source_type)} &middot; imported ${_ago(d.created_at)}</div>
          </div>
          <span class="qs-status-chip approved">Linked</span>
        </div>
      `).join('')}
      <div style="margin-top:12px;">
        <strong style="color:var(--qs-accent);">${segCount}</strong>
        <span style="font-size:13px;color:var(--ink-3);"> codeable segments loaded</span>
      </div>
    ` : '';

    App._setContent(`
      <h1 class="qs-module-h">Data Import</h1>
      <p class="qs-module-sub">Upload your qualitative data. Open-ended text columns are detected and loaded as codeable segments with participant context.</p>

      <div class="qs-import-options">
        <div class="qs-import-card" id="uploadCard" onclick="Screens._openUpload()">
          <div class="qs-import-card-icon">📂</div>
          <h3>Upload a file</h3>
          <p>CSV or XLSX with open-ended responses. Participant metadata columns travel with each segment.</p>
        </div>
        <div class="qs-import-card" onclick="alert('Survey import coming soon.')">
          <div class="qs-import-card-icon">🔗</div>
          <h3>From ReliCheck survey</h3>
          <p>Connect an existing survey project and pull open-ended responses with full participant context.</p>
        </div>
      </div>

      <div id="import-status"></div>
      ${docsHtml}

      ${segCount > 0 ? `
        <div class="qs-btn-row" style="margin-top:24px;">
          <button class="qs-btn qs-btn-primary" onclick="App.go('familiarize')">Next: Familiarization</button>
        </div>
      ` : ''}
    `);

    App._setGuide(`
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">What gets imported</div>
        <p>The studio looks for columns classified as <strong>open-ended text</strong> in your dataset's data map. Each response becomes one codeable segment.</p>
      </div>
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Participant context</div>
        <p>Non-text columns (group, role, score, demographics) are attached to each segment as metadata. Coders see this context alongside the text.</p>
      </div>
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Data types accepted</div>
        <p>CSV, XLSX, or TSV. The upload widget handles detection automatically. Column types can be adjusted in the dataset's data map.</p>
      </div>
    `);
  },

  _openUpload() {
    if (typeof DatasetUpload === 'undefined') {
      alert('Upload widget not loaded. Please refresh.');
      return;
    }
    DatasetUpload.open({
      // projectType:'rssi' tells the widget to return the datasetId directly
      // without trying to link it — we call our own link-dataset.php in onLoaded.
      projectType: 'rssi',
      onLoaded: async (_err, datasetId) => {
        const statusEl = document.getElementById('import-status');
        if (statusEl) statusEl.innerHTML = '<div class="qs-notice info">Linking dataset and loading segments...</div>';
        try {
          const r = await App.api('/api/qual/link-dataset.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: BOOT.projectId, dataset_id: datasetId }),
          });
          // Reload import screen to show the linked doc
          App.state.segments = null;
          App.state.codes    = null;
          App.go('import');
        } catch(e) {
          if (statusEl) statusEl.innerHTML = `<div class="qs-notice err">Could not link dataset: ${_esc(e.message)}</div>`;
        }
      },
    });
  },

  // ── Familiarize ───────────────────────────────────────────────────────────
  async familiarize() {
    const d = await App.api(`/api/qual/get-project.php?project_id=${BOOT.projectId}`);
    const s = d.stats;
    App.state.stats = s;
    App.state.docs  = d.documents;
    const p = d.project || App.state.project || {};

    App._setContent(`
      <h1 class="qs-module-h">Familiarization</h1>
      <p class="qs-module-sub">Explore the corpus before formal coding. What do you notice before applying any framework?</p>

      <div class="qs-stats-grid">
        ${_stat(s.seg_count,   'Segments')}
        ${_stat(s.doc_count,   'Data sources')}
        ${_stat(s.total_words, 'Total words')}
        ${_stat(s.avg_words,   'Avg words / segment')}
        ${_stat(s.code_count,  'Codes in codebook')}
        ${_stat(s.application_count, 'Code applications')}
      </div>

      ${s.seg_count === 0 ? `
        <div class="qs-notice warn">No segments loaded yet. Go to <button class="qs-btn-ghost qs-btn" onclick="App.go('import')" style="display:inline;padding:0;">Data Import</button> to upload your data first.</div>
      ` : ''}

      ${d.documents.length > 0 ? `
        <div class="qs-card">
          <div class="qs-card-h">Data sources</div>
          ${d.documents.map(doc => `
            <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid var(--line);font-size:13.5px;">
              <span style="color:var(--qs-accent);font-size:20px;">📄</span>
              <div style="flex:1;font-weight:600;color:var(--ink);">${_esc(doc.title)}</div>
              <span style="color:var(--ink-3);">${_esc(doc.source_type)}</span>
            </div>
          `).join('')}
        </div>
      ` : ''}

      <div class="qs-card">
        <div class="qs-card-h">First Impressions Memo
          <span style="font-size:12px;font-weight:400;color:var(--ink-3);margin-left:8px;">analytic memo — saved to audit trail</span>
        </div>
        <p style="font-size:13.5px;color:var(--ink-3);line-height:1.6;margin-bottom:14px;">
          Before coding, what stands out to you? What surprises you? What patterns, tensions, or questions do you notice in the data?
        </p>
        <textarea class="qs-textarea" id="fam-memo" style="min-height:130px;"
          placeholder="I noticed several responses mentioned..."></textarea>
        <div id="fam-memo-msg" style="display:none;margin-top:6px;font-size:13px;"></div>
        <div class="qs-btn-row" style="margin-top:12px;">
          <button class="qs-btn qs-btn-primary" onclick="Screens._saveFamMemo()">Save memo</button>
          ${s.seg_count > 0 ? `<button class="qs-btn qs-btn-secondary" onclick="App.go('coding')">Start coding</button>` : ''}
        </div>
      </div>
    `);

    App._setGuide(`
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Why familiarize first</div>
        <p>Reading through all data before applying codes reduces bias from premature categorization. The First Impressions Memo becomes part of your reflexivity record.</p>
      </div>
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">What to look for</div>
        <p>Recurring language. Surprising answers. Emotional intensity. Responses that stand out as outliers. Questions the data raises but does not yet answer.</p>
      </div>
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Trustworthiness link</div>
        <p>This memo is evidence of <strong>reflexivity</strong> and early <strong>credibility</strong> work. The Trustworthiness Review will check that it exists.</p>
      </div>
    `);
  },

  async _saveFamMemo() {
    const body = document.getElementById('fam-memo').value.trim();
    const msg  = document.getElementById('fam-memo-msg');
    if (!body) { msg.textContent='Write something before saving.'; msg.style.cssText='display:block;color:#c0392b;'; return; }
    msg.textContent='Saving...'; msg.style.cssText='display:block;color:var(--ink-3);';
    try {
      await App.api('/api/qual/save-memo.php', {
        method:'POST',
        body:JSON.stringify({ project_id:BOOT.projectId, object_type:'project',
                              memo_type:'first_impressions', title:'First Impressions', body }),
      });
      msg.textContent='Memo saved.'; msg.style.cssText='display:block;color:var(--qs-accent);';
      setTimeout(() => { msg.style.display='none'; }, 2500);
    } catch(e) {
      msg.textContent='Error: '+e.message; msg.style.cssText='display:block;color:#c0392b;';
    }
  },

  // ── Coding workspace ──────────────────────────────────────────────────────
  async coding() {
    // Load codes first so the picker is ready
    const codesData = await App.api(`/api/qual/get-codes.php?project_id=${BOOT.projectId}`);
    App.state.codes = codesData.codes || [];

    let uncodedOnly = false;
    let searchQuery = '';
    let segments    = [];

    async function loadSegments() {
      const qs = `project_id=${BOOT.projectId}&limit=200${uncodedOnly ? '&uncoded=1' : ''}`;
      const d  = await App.api(`/api/qual/get-segments.php?${qs}`);
      segments = d.segments || [];
      App.state.segments = segments;
      renderList();
    }

    function renderList() {
      const list  = document.getElementById('seg-list');
      if (!list) return;

      const filtered = searchQuery
        ? segments.filter(s => s.raw_text.toLowerCase().includes(searchQuery.toLowerCase()))
        : segments;

      const coded   = filtered.filter(s => s.code_count > 0).length;
      const uncoded = filtered.filter(s => s.code_count === 0).length;

      document.getElementById('seg-counts').textContent =
        `${filtered.length} segments · ${coded} coded · ${uncoded} uncoded`;

      if (!filtered.length) {
        list.innerHTML = `<div class="qs-empty">
          <div class="qs-empty-h">No segments ${uncodedOnly ? 'left to code' : 'found'}</div>
          <div class="qs-empty-body">${uncodedOnly ? 'All segments have at least one code applied.' : 'No segments match your search.'}</div>
        </div>`;
        return;
      }

      list.innerHTML = filtered.map(seg => renderSegCard(seg)).join('');
    }

    function renderSegCard(seg) {
      const meta = seg.metadata_json || {};
      const metaItems = Object.entries(meta).slice(0, 4)
        .map(([k,v]) => `<span class="qs-seg-pid">${_esc(k)}: ${_esc(String(v))}</span>`).join('');
      const pid = seg.participant_id ? `<span class="qs-seg-pid">ID: ${_esc(seg.participant_id)}</span>` : '';
      const q   = seg.question_ref   ? `<span class="qs-seg-q">${_esc(seg.question_ref)}</span>` : '';

      const chips = (seg.codes || []).map(c => `
        <span class="qs-chip">
          ${_esc(c.name)}
          <button class="qs-chip-remove" title="Remove code"
            onclick="Screens._removeCode(${seg.id},${c.id},this)">&times;</button>
        </span>
      `).join('');

      const overCoded = seg.code_count >= 4;
      const flag = seg.code_count === 0
        ? '<span class="qs-flag uncoded">Uncoded</span>'
        : overCoded ? '<span class="qs-flag overcoded">Over-coded (4+)</span>' : '';

      return `
        <div class="qs-seg-card ${seg.code_count > 0 ? 'coded' : 'uncoded'}${overCoded ? ' overcoded' : ''}"
             id="seg-${seg.id}">
          <div class="qs-seg-meta">${pid}${q}${metaItems}</div>
          <div class="qs-seg-text">${_esc(seg.raw_text)}</div>
          <div class="qs-code-chips" id="chips-${seg.id}">${chips}</div>
          <div class="qs-seg-actions">
            <div class="qs-picker-wrap" id="picker-wrap-${seg.id}">
              <button class="qs-add-code-btn" onclick="Screens._togglePicker(${seg.id})">+ Add code</button>
              <div class="qs-picker" id="picker-${seg.id}" style="display:none;">
                ${App.state.codes.length ? App.state.codes.map(c =>
                  `<button class="qs-picker-item" onclick="Screens._applyCode(${seg.id},${c.id},'${_esc(c.name)}',this.closest('.qs-picker'))">${_esc(c.name)}</button>`
                ).join('') : '<div class="qs-picker-empty">No codes yet.</div>'}
                <div class="qs-picker-new">
                  <button class="qs-picker-new-btn" onclick="Screens._quickNewCode(${seg.id})">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New code
                  </button>
                </div>
              </div>
            </div>
            ${flag}
          </div>
        </div>`;
    }

    App._setContent(`
      <h1 class="qs-module-h">Coding Workspace</h1>
      <p class="qs-module-sub">Code each response. Participant context appears above each segment. Use the codebook to maintain consistency.</p>

      <div class="qs-filters">
        <input class="qs-search-input" id="seg-search" placeholder="Search segments..." oninput="Screens._codingSearch(this.value)">
        <button class="qs-filter-btn" id="filter-all" onclick="Screens._codingFilter(false)" style="border-color:var(--qs-accent);background:var(--qs-accent-soft);color:var(--qs-accent-ink);">All</button>
        <button class="qs-filter-btn" id="filter-uncoded" onclick="Screens._codingFilter(true)">Uncoded only</button>
        <button class="qs-btn qs-btn-secondary" onclick="App.go('codebook')" style="margin-left:auto;">Manage codebook</button>
      </div>

      <div id="seg-counts" style="font-size:13px;color:var(--ink-3);margin-bottom:14px;">Loading...</div>
      <div class="qs-seg-list" id="seg-list">
        <div class="qs-loading"><div class="qs-spinner"></div><span>Loading segments...</span></div>
      </div>
    `);

    // Expose filter/search handlers
    Screens._codingFilter = async (uOnly) => {
      uncodedOnly = uOnly;
      document.getElementById('filter-all').className    = 'qs-filter-btn' + (!uOnly ? ' active' : '');
      document.getElementById('filter-uncoded').className = 'qs-filter-btn' + (uOnly ? ' active' : '');
      await loadSegments();
    };
    Screens._codingSearch = (q) => {
      searchQuery = q;
      renderList();
    };

    App._setGuide(`
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Coding principles</div>
        <p>Codes should capture <strong>meaning</strong>, not just topic. Ask: what is this person saying, not just what words did they use?</p>
      </div>
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Flags</div>
        <p><strong>Uncoded</strong> — segment has no code yet.<br>
           <strong>Over-coded (4+)</strong> — may indicate an overly broad code or a complex response that deserves splitting.</p>
      </div>
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Multi-code support</div>
        <p>One segment can hold multiple codes. This is expected — a response can speak to more than one concept.</p>
      </div>
    `);

    await loadSegments();
  },

  _togglePicker(segId) {
    // Close all other pickers first
    document.querySelectorAll('.qs-picker').forEach(p => {
      if (p.id !== `picker-${segId}`) p.style.display = 'none';
    });
    const picker = document.getElementById(`picker-${segId}`);
    if (picker) picker.style.display = picker.style.display === 'none' ? 'block' : 'none';
    // Close on outside click
    setTimeout(() => {
      document.addEventListener('click', function handler(e) {
        if (!e.target.closest(`#picker-wrap-${segId}`)) {
          const p = document.getElementById(`picker-${segId}`);
          if (p) p.style.display = 'none';
          document.removeEventListener('click', handler);
        }
      });
    }, 10);
  },

  async _applyCode(segId, codeId, codeName, pickerEl) {
    if (pickerEl) pickerEl.style.display = 'none';
    try {
      await App.api('/api/qual/apply-code.php', {
        method: 'POST',
        body: JSON.stringify({ project_id: BOOT.projectId, segment_id: segId, code_id: codeId }),
      });
      // Optimistic update
      const seg = App.state.segments?.find(s => s.id === segId);
      if (seg) {
        if (!seg.codes.find(c => c.id === codeId)) {
          seg.codes.push({ id: codeId, name: codeName });
          seg.code_count = seg.codes.length;
        }
        const chipsEl = document.getElementById(`chips-${segId}`);
        const card    = document.getElementById(`seg-${segId}`);
        if (chipsEl && seg) {
          chipsEl.innerHTML = seg.codes.map(c => `
            <span class="qs-chip">${_esc(c.name)}
              <button class="qs-chip-remove" onclick="Screens._removeCode(${segId},${c.id},this)">&times;</button>
            </span>`).join('');
        }
        if (card) {
          card.classList.remove('uncoded');
          card.classList.add('coded');
        }
      }
    } catch(e) {
      alert('Could not apply code: ' + e.message);
    }
  },

  async _removeCode(segId, codeId, btnEl) {
    try {
      await App.api('/api/qual/remove-code.php', {
        method: 'POST',
        body: JSON.stringify({ project_id: BOOT.projectId, segment_id: segId, code_id: codeId }),
      });
      const seg = App.state.segments?.find(s => s.id === segId);
      if (seg) {
        seg.codes = seg.codes.filter(c => c.id !== codeId);
        seg.code_count = seg.codes.length;
        const chip = btnEl?.closest('.qs-chip');
        if (chip) chip.remove();
        if (seg.code_count === 0) {
          const card = document.getElementById(`seg-${segId}`);
          if (card) { card.classList.remove('coded'); card.classList.add('uncoded'); }
        }
      }
    } catch(e) { alert('Could not remove code: ' + e.message); }
  },

  async _quickNewCode(segId) {
    const name = prompt('New code name:');
    if (!name || !name.trim()) return;
    try {
      const r = await App.api('/api/qual/save-code.php', {
        method: 'POST',
        body: JSON.stringify({ project_id: BOOT.projectId, name: name.trim() }),
      });
      const newCode = { id: r.code_id, name: name.trim() };
      App.state.codes.push(newCode);
      // Apply immediately
      await Screens._applyCode(segId, newCode.id, newCode.name, document.getElementById(`picker-${segId}`));
    } catch(e) { alert('Could not create code: ' + e.message); }
  },

  // ── Codebook ──────────────────────────────────────────────────────────────
  async codebook() {
    const d = await App.api(`/api/qual/get-codes.php?project_id=${BOOT.projectId}`);
    App.state.codes = d.codes || [];
    let editingId = null;

    function renderTable() {
      const codes = App.state.codes;
      if (!codes.length) {
        return `<div class="qs-empty">
          <div class="qs-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div>
          <div class="qs-empty-h">No codes yet</div>
          <div class="qs-empty-body">Create your first code below, or apply codes from the Coding Workspace to add them here.</div>
        </div>`;
      }
      return `
        <div class="qs-table-wrap">
          <table class="qs-table">
            <thead><tr>
              <th>Code</th><th>Definition</th><th>Applications</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
              ${codes.map(c => `
                <tr>
                  <td style="font-weight:700;color:var(--ink);">${_esc(c.name)}</td>
                  <td style="color:var(--ink-2);max-width:260px;">${c.definition ? _esc(c.definition) : '<span style="color:var(--ink-3);font-style:italic;">No definition</span>'}</td>
                  <td style="text-align:center;color:var(--ink-3);">${c.application_count || 0}</td>
                  <td><span class="qs-status-chip ${c.status}">${_esc(c.status)}</span></td>
                  <td>
                    <button class="qs-btn qs-btn-secondary" style="padding:5px 12px;font-size:12px;"
                      onclick="Screens._editCode(${c.id})">Edit</button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>`;
    }

    App._setContent(`
      <h1 class="qs-module-h">Codebook Builder</h1>
      <p class="qs-module-sub">Define, refine, and manage the codes used in your analysis. A well-defined codebook is the backbone of credible qualitative findings.</p>

      <div id="cb-table">${renderTable()}</div>

      <hr class="qs-module-divider">

      <div class="qs-card" id="code-form-card">
        <div class="qs-code-panel-h" id="code-form-title">Add a new code</div>
        <div class="qs-form-row">
          <label>Code name <span style="color:#c0392b">*</span></label>
          <input class="qs-input" id="cb-name" placeholder="e.g. Lack of Communication">
        </div>
        <div class="qs-form-row">
          <label>Definition
            <span class="hint">What does this code capture? Be specific enough that two coders would agree.</span>
          </label>
          <textarea class="qs-textarea" id="cb-def" placeholder="Apply when a respondent describes..."></textarea>
        </div>
        <div class="qs-form-grid-2">
          <div class="qs-form-row">
            <label>Include when</label>
            <textarea class="qs-textarea" id="cb-include" style="min-height:70px;" placeholder="The response describes a gap or absence of..."></textarea>
          </div>
          <div class="qs-form-row">
            <label>Exclude when</label>
            <textarea class="qs-textarea" id="cb-exclude" style="min-height:70px;" placeholder="The response is about a different construct..."></textarea>
          </div>
        </div>
        <div class="qs-form-row">
          <label>Example quote</label>
          <input class="qs-input" id="cb-quote" placeholder='"I never know who to go to..."'>
        </div>
        <input type="hidden" id="cb-editing-id" value="">
        <div id="cb-msg" style="display:none;font-size:13px;margin-top:6px;"></div>
        <div class="qs-btn-row">
          <button class="qs-btn qs-btn-primary" id="cb-save-btn" onclick="Screens._saveCode()">Add code</button>
          <button class="qs-btn qs-btn-secondary" id="cb-cancel-btn" style="display:none;" onclick="Screens._cancelCodeEdit()">Cancel</button>
        </div>
      </div>
    `);

    Screens._editCode = (codeId) => {
      const code = App.state.codes.find(c => c.id === codeId);
      if (!code) return;
      document.getElementById('cb-editing-id').value  = codeId;
      document.getElementById('cb-name').value        = code.name || '';
      document.getElementById('cb-def').value         = code.definition || '';
      document.getElementById('cb-include').value     = code.include_when || '';
      document.getElementById('cb-exclude').value     = code.exclude_when || '';
      document.getElementById('cb-quote').value       = code.example_quote || '';
      document.getElementById('code-form-title').textContent = 'Edit code: ' + code.name;
      document.getElementById('cb-save-btn').textContent     = 'Save changes';
      document.getElementById('cb-cancel-btn').style.display = 'inline-flex';
      document.getElementById('code-form-card').scrollIntoView({ behavior:'smooth' });
    };

    Screens._cancelCodeEdit = () => {
      document.getElementById('cb-editing-id').value  = '';
      document.getElementById('cb-name').value        = '';
      document.getElementById('cb-def').value         = '';
      document.getElementById('cb-include').value     = '';
      document.getElementById('cb-exclude').value     = '';
      document.getElementById('cb-quote').value       = '';
      document.getElementById('code-form-title').textContent  = 'Add a new code';
      document.getElementById('cb-save-btn').textContent      = 'Add code';
      document.getElementById('cb-cancel-btn').style.display  = 'none';
    };

    Screens._saveCode = async () => {
      const msg     = document.getElementById('cb-msg');
      const name    = document.getElementById('cb-name').value.trim();
      const editId  = parseInt(document.getElementById('cb-editing-id').value) || 0;
      if (!name) { msg.textContent='Code name is required.'; msg.style.cssText='display:block;color:#c0392b;'; return; }
      msg.textContent='Saving...'; msg.style.cssText='display:block;color:var(--ink-3);';
      try {
        const body = {
          project_id:    BOOT.projectId,
          name,
          definition:    document.getElementById('cb-def').value.trim(),
          include_when:  document.getElementById('cb-include').value.trim(),
          exclude_when:  document.getElementById('cb-exclude').value.trim(),
          example_quote: document.getElementById('cb-quote').value.trim(),
        };
        if (editId) body.id = editId;
        const r = await App.api('/api/qual/save-code.php', { method:'POST', body:JSON.stringify(body) });
        // Refresh codebook
        const fresh = await App.api(`/api/qual/get-codes.php?project_id=${BOOT.projectId}`);
        App.state.codes = fresh.codes || [];
        document.getElementById('cb-table').innerHTML = renderTable();
        Screens._cancelCodeEdit();
        msg.textContent = editId ? 'Code updated.' : 'Code added.';
        msg.style.cssText='display:block;color:var(--qs-accent);';
        setTimeout(() => { msg.style.display='none'; }, 2500);
      } catch(e) {
        msg.textContent='Error: '+e.message; msg.style.cssText='display:block;color:#c0392b;';
      }
    };

    App._setGuide(`
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">What makes a good code</div>
        <p>A code name should be descriptive, not a single vague word. <strong>"Lack of administrative support"</strong> is a better code than <strong>"support"</strong>.</p>
      </div>
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Definitions matter</div>
        <p>Write a definition specific enough that a second coder reading it independently would apply the code to the same responses. This is what makes dual-coder kappa meaningful.</p>
      </div>
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Include / Exclude rules</div>
        <p>Boundary cases are where coders diverge most. The include/exclude rules are the most important fields for reliability.</p>
      </div>
      <div class="qs-guide-section">
        <div class="qs-guide-section-h">Code statuses</div>
        <p><strong>Draft</strong> — working code, may change.<br>
           <strong>Reviewed</strong> — reviewed by the team.<br>
           <strong>Approved</strong> — finalized, safe for reporting.</p>
      </div>
    `);
  },
};


// ─── Utilities ───────────────────────────────────────────────────────────────
function _esc(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function _stat(val, label) {
  return `<div class="qs-stat-card">
    <div class="qs-stat-num">${Number(val || 0).toLocaleString()}</div>
    <div class="qs-stat-label">${label}</div>
  </div>`;
}

function _ago(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  const mins = Math.floor((Date.now() - d) / 60000);
  if (mins < 60)  return mins + 'm ago';
  if (mins < 1440) return Math.floor(mins/60) + 'h ago';
  return Math.floor(mins/1440) + 'd ago';
}


// ─── Boot ────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  App.init();
});
