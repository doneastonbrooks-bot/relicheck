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
    dataset:          null,
    compTab:          'explain',
    notes:            {},
    varmapConfirmed:  false,
    _varmapMounted:   false,
    codes:            null,
    project:          BOOT.project || null,
  };

  // ── Helpers ──────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function center() { return document.getElementById('centerInner'); }

  function api(path, opts) {
    opts = opts || {};
    return fetch(path, Object.assign({ headers: { 'Content-Type': 'application/json' } }, opts))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.message || d.error || 'API error');
        return d;
      });
  }

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

  function _cb(icon, label, body) {
    return '<div class="comp-block"><div class="cb-k"><span class="i">' + icon + '</span> ' + label + '</div><div class="cb-t">' + body + '</div></div>';
  }
  function _cbWhy(body) {
    return '<div class="comp-block comp-why"><div class="cb-k">&#10022; Why this order</div><div class="cb-t">' + body + '</div></div>';
  }
  function _cbEx(body) {
    return '<div style="background:var(--bg);border-radius:12px;padding:14px 16px;font-size:13px;line-height:1.65;margin-top:4px"><div class="cb-k" style="margin-bottom:8px">Worked example</div>' + body + '</div>';
  }

  var GUIDANCE = {
    start: _cb('i', 'What it is',
        'The Qual Studio walks you through a complete qualitative analysis: from raw text to coded segments, trustworthy themes, and a report ready to share. Begin by uploading a dataset or opening a saved project.')
      + _cb('&#8594;', 'Where to go',
        'Click <b>Upload data</b> to bring in a CSV, XLSX, or Qualtrics export. Open-ended columns are detected automatically and segmented into individual responses for coding. If you have already started a project, pick it from the list below.')
      + _cb('&#10003;', 'When you are ready',
        'Once a dataset is loaded, the rail unlocks and you move to Project Setup to record your research question before any analysis begins.'),

    setup: _cbWhy('Documenting your research question, approach, and stance before you touch the data is not bureaucracy. It protects your interpretation from drift: when a theme surprises you later, you can trace it back to what you were actually trying to learn.')
      + _cb('i', 'What it is',
        'Project Setup records the frame for your analysis: the research question, the approach you are using (thematic, content, framework, etc.), who the participants are, and your researcher stance memo.')
      + _cb('&#128203;', 'Researcher stance',
        'The stance memo asks what assumptions, roles, or experiences you bring to interpretation. This is not self-criticism. It is how qualitative findings become defensible: readers know what lens was in play. A sentence or two is enough.')
      + _cb('&#10003;', 'When you are ready',
        'Fill in at least the title and research question, then save. You can return to update the memo at any time. Click <b>Next: Data Entry</b> to continue.')
      + _cbEx('<b>Research question:</b> What do early-career teachers say about barriers to implementing student-led discussion?<br><b>Participants:</b> 68 K-12 teachers, years 1-3, 4 districts.<br><b>Stance:</b> I am an external evaluator familiar with the intervention; I may over-read references to the program design.'),

    upload: _cb('i', 'What it is',
        'This step is where your raw data arrives. Upload a spreadsheet or CSV with open-ended columns, import from a SIRI survey, or open a previously saved dataset from any ReliCheck studio.')
      + _cb('M', 'What gets detected',
        'ReliCheck scans each column for open-ended text (average length, distinct values) and tags it automatically. Columns tagged <b>open</b> become the segments you will code. Numeric and demographic columns become participant metadata.')
      + _cb('&#10003;', 'When you are ready',
        'After loading, click <b>Go to Overview</b> to review what was detected before you confirm the variable map.')
      + _cbEx('<b>A typical file:</b> 400 rows, 1 Likert block (12 cols), 3 open-ended items (3 cols), 4 demographic items. ReliCheck tags the 3 open cols as open-ended and the rest as metadata. Each of the 400 × 3 = 1,200 open cells becomes one segment.'),

    datamap: _cbWhy('Column Setup is the last checkpoint before coding begins. Getting this right here takes 30 seconds. Getting it wrong means your segments will be missing, or you will be coding a column that was never meant to be coded.')
      + _cb('i', 'What it is',
        'ReliCheck detects which columns look like open-ended text and pre-selects them. Column Setup is where you confirm or correct that detection, and tell ReliCheck what to do with each remaining column.')
      + _cb('M', 'The four roles',
        '<b>Code this</b> — the column becomes segments in the Coding Workspace. Every non-empty cell is one segment.<br><b>Participant ID</b> — links all of a participant\'s segments together so you can see patterns by person.<br><b>Participant context</b> — attaches to every segment as background (grade level, site, role, etc.) without being coded itself.<br><b>Skip</b> — excluded entirely.')
      + _cb('&#10003;', 'When you are ready',
        'Make sure at least one column is set to <b>Code this</b>, then click <b>Confirm and build segments</b>. ReliCheck creates the segment table immediately. You can re-run this step any time by coming back and re-confirming.')
      + _cbEx('<b>Typical 15-column survey file:</b> Q1-Q10 are Likert items (set to Skip — nothing to code). Q11, Q12, Q13 are open-ended (set to Code this). "RespondentID" is set to Participant ID. "Grade" and "School" are set to Participant context. Result: 3 coded columns x row count = your total segment count.'),

    cleaning: _cbWhy('Qualitative data often contains emails, phone numbers, or names that respondents typed without thinking. Masking these before analysis means your coded excerpts can be shared with a second coder or cited in a report without inadvertently identifying anyone.')
      + _cb('i', 'What it is',
        'The PII scan checks every open-ended segment for patterns that look like personal information: email addresses, phone numbers, ID numbers, and name introductions such as "My name is...".')
      + _cb('M', 'What it does',
        'Flagged segments show the matched pattern and the original text. You choose to <b>Mask</b> (replace with [MASKED]) or <b>Skip</b> (leave as-is). Masking is written back to the segment so all subsequent steps use the cleaned text.')
      + _cb('&#10003;', 'When you are ready',
        'If the scan finds nothing, continue immediately. If it flags segments, review each one before continuing. You can always re-run the scan later.')
      + _cbEx('"I talked to my supervisor Sarah Johnson (sarah.j@districtmail.org) about this." Scan flags: Email: sarah.j@districtmail.org. Masking produces: "I talked to my supervisor Sarah Johnson ([MASKED]) about this." The meaning is preserved; the identifier is gone.'),

    familiarization: _cbWhy('Good coding does not begin with a blank codebook. It begins with genuine curiosity about the data. Familiarization is the stage where you earn the right to interpret: read enough to be surprised before you start labeling.')
      + _cb('i', 'What it is',
        'Familiarization is your first pass through the corpus before any formal coding. You read widely, notice patterns, record initial impressions, and let unexpected responses challenge your assumptions.')
      + _cb('&#128218;', 'First Impressions Memo',
        'Write down what strikes you before you start coding: what themes seem to recur, what surprises you, what tensions or contradictions you notice, what questions the data raises. This memo becomes part of your audit trail and is referenced in the Trustworthiness report.')
      + _cb('&#10003;', 'When you are ready',
        'Save your memo, then click <b>Start coding</b>. You do not need to read every segment here; 20-30% of the corpus is typically enough to establish orientation. The Concept Scan in this step can help surface patterns you might miss.')
      + _cbEx('<b>Memo excerpt:</b> "A striking number of responses mention time, but in two very different registers: not having enough time to try new approaches, and not feeling time pressure was taken seriously by administration. These may be different constructs. Watch for this during coding."'),

    coding: _cbWhy('Codes are the smallest meaningful unit in your analysis. They are not summaries or category names; they are close-to-the-data labels that preserve what the participant actually said. Good codes are specific enough to be falsifiable: a different researcher should reach the same code reading the same segment.')
      + _cb('i', 'What it is',
        'The Coding Workspace shows every open-ended segment. You apply one or more codes to each segment, building a codebook as you go. Codes you create here are shared across all segments in the project.')
      + _cb('M', 'What makes a good code',
        'Start with what the text actually says (descriptive coding), not what it means (interpretive). Keep codes short: 2-4 words. Avoid evaluative words like "good" or "problematic" unless the participant used them. A segment can have multiple codes when it addresses more than one idea.')
      + _cb('&#10003;', 'When you are ready',
        'Work through uncoded segments using the <b>Uncoded only</b> filter. When the list is clear, move to the Codebook to write definitions and memos before categories begin.')
      + _cbEx('<b>Segment:</b> "I love the idea but we just never get enough planning time to make it work."<br><b>Codes applied:</b> Positive attitude toward initiative, Time scarcity barrier.<br><b>Not:</b> "Support but barriers" (too interpretive at this stage) or "Positive" (too vague).'),

    codebook: _cbWhy('A codebook without definitions is just a list of names. Two coders using the same code name but different intuitions will produce incomparable results. Definitions are what make your coding replicable, and replicability is what makes your findings defensible.')
      + _cb('i', 'What it is',
        'The Codebook is the master list of every code you have created. For each code, you can write a definition (what this code means and what it covers), inclusion rules (what counts), exclusion rules (what does not count), and a memo about how the code evolved.')
      + _cb('M', 'What to write',
        'A good definition is 1-3 sentences. It answers: what must be present for this code to apply, what is the boundary with adjacent codes, and is there a prototypical example? Inclusion/exclusion rules handle the hard cases.')
      + _cb('&#10003;', 'When you are ready',
        'Every code used in your analysis should have at least a one-sentence definition before you invite a second coder or move to categories. Click <b>Dual Coder</b> in the rail to set up inter-rater reliability.')
      + _cbEx('<b>Code:</b> Time scarcity barrier<br><b>Definition:</b> Participant explicitly mentions not having enough time as a reason why the practice cannot or does not happen.<br><b>Include:</b> References to scheduling constraints, workload, lack of prep period.<br><b>Exclude:</b> Statements about time where time is not named as a barrier (e.g., "it took a long time" without implying the time was insufficient).'),

    dual: _cbWhy('Inter-rater reliability (IRR) is qualitative research\'s answer to the replication question. It does not mean two coders must agree on everything; it means disagreements are surfaced, discussed, and resolved through interpretation rather than error. The process strengthens the codebook as much as the statistic.')
      + _cb('i', 'What it is',
        'The Dual Coder step lets a second researcher code a sample of segments using the same codebook. ReliCheck then calculates Cohen\'s Kappa as an IRR measure and shows where the two coders agreed and disagreed.')
      + _cb('M', 'What Kappa means',
        'Kappa corrects for chance agreement. Values above 0.70 are typically considered acceptable for qualitative research; above 0.80 is strong. Values below 0.60 usually mean the codebook definitions need sharpening, not that one coder is wrong.')
      + _cb('&#10003;', 'When you are ready',
        'Review every disagreement before calculating a final Kappa. Disagreements are data: they reveal where the construct boundary is ambiguous. Refine definitions, recode the disputed segments together, and document the resolution in the codebook memo.')
      + _cbEx('<b>Kappa = 0.63 on "Time scarcity barrier".</b> Review shows Coder B applied it to segments like "I wish I had more time to reflect" where time is mentioned but not as a barrier to the practice. Exclusion rule updated: exclude reflective time references unless the participant explicitly links time to an obstacle.'),

    categories: _cbWhy('Categories are the first level of abstraction above codes. They are built by grouping codes that address the same underlying idea, not by sorting codes into pre-existing buckets. This step should feel analytical, not organizational.')
      + _cb('i', 'What it is',
        'Categories bring together codes that share a meaningful relationship. Where a code stays close to what a participant said, a category begins to name the pattern across many participants. A single project typically yields 4-8 categories before themes emerge.')
      + _cb('M', 'How to group',
        'Lay out your codebook and look for codes that answer the same question or describe the same phenomenon from different angles. Give each category a name that captures the relationship, not just the most common code. Write a category definition before you move on.')
      + _cb('&#10003;', 'When you are ready',
        'Every code should belong to at least one category. If a code does not fit anywhere, it may be a miscoded segment, a standalone finding, or the seed of a new category. Review outliers before advancing to Themes.')
      + _cbEx('<b>Codes:</b> Time scarcity barrier, Scheduling conflict, No planning period, Administration unresponsive to time requests.<br><b>Category:</b> Structural time constraints.<br><b>Definition:</b> Participant-reported barriers rooted in how time is allocated at the institutional level, distinct from individual time management.'),

    themes: _cbWhy('A theme is not a category with a fancier name. It is a statement about what the data means at the level that answers your research question. The shift from category to theme is the shift from description to interpretation, and it is where your researcher judgment matters most.')
      + _cb('i', 'What it is',
        'Themes are the central analytic outputs of qualitative analysis. Each theme makes a claim about the data: it states a pattern, a tension, or a relationship that recurs across participants and is relevant to your research question.')
      + _cb('M', 'What makes a good theme',
        'A theme should be expressible as a sentence that a non-researcher could understand. It should be supported by multiple categories and multiple participants. It should be distinctive from other themes in your analysis. If two themes are very similar, they may be the same theme.')
      + _cb('&#10003;', 'When you are ready',
        'Aim for 3-6 themes for most studies. Write a narrative description for each theme before moving to Quotes, because the narrative is what you will cite in your report. The Representative Quotes step will populate evidence for each theme.')
      + _cbEx('<b>Category:</b> Structural time constraints.<br><b>Theme:</b> Teachers experience time not as a personal resource to manage but as an institutional constraint imposed on them, making uptake contingent on structural change rather than individual motivation.<br><b>Why it is a theme, not a category:</b> It makes a claim about what the pattern means, connects it to two other categories (administrator responsiveness, reform design), and speaks directly to the research question.'),

    quotes: _cbWhy('Quotes in a qualitative report do two things: they give readers access to the raw voice of participants, and they give you an obligation to represent that voice accurately. Selecting the most vivid quote is not the same as selecting the most representative one.')
      + _cb('i', 'What it is',
        'The Representative Quotes step lets you select specific segments as the primary evidence for each theme. These are the quotes you will feature in your report, with participant metadata attached for context.')
      + _cb('M', 'How to select',
        'Choose quotes that a skeptical reader could verify as supporting your theme interpretation. Avoid quotes that only work if you explain them at length. Aim for 2-4 quotes per theme: one that is typical, one that is unexpected or complicating, and one from a distinct participant subgroup if relevant.')
      + _cb('&#10003;', 'When you are ready',
        'Save your selections and move to Trustworthiness. The quotes you select here will appear in the generated report, attributed to participant IDs (not names) unless you configured otherwise.')
      + _cbEx('<b>Theme:</b> Structural time constraints.<br><b>Typical quote:</b> "We get one 45-minute planning period a week. That\'s not enough to even read the materials, let alone try something new."<br><b>Complicating quote:</b> "When the principal gave us an extra hour last spring, it actually happened. So it can change. It just doesn\'t."'),

    trustworthiness: _cbWhy('Validity in qualitative research is not a checklist. It is a set of practices that help readers evaluate whether your interpretations are credible, transferable, and dependable. Trustworthiness is what you demonstrate, not what you claim.')
      + _cb('i', 'What it is',
        'The Trustworthiness step reviews your project against Lincoln and Guba\'s criteria for qualitative rigor: credibility, transferability, dependability, and confirmability. ReliCheck checks what it can from the project record and asks you to document the rest.')
      + _cb('M', 'What each criterion means',
        '<b>Credibility:</b> Did the findings emerge from the data, or were they imposed on it? (Prolonged engagement, member checking, negative case analysis.)<br><b>Transferability:</b> Could someone apply these findings to another context? (Thick description of participants and setting.)<br><b>Dependability:</b> Could the process be audited? (Audit trail, codebook, IRR.)<br><b>Confirmability:</b> Can the researcher\'s influence be evaluated? (Reflexivity memo, stance documentation.)')
      + _cb('&#10003;', 'When you are ready',
        'A trustworthiness statement is not optional if your findings will be shared. Fill in each criterion honestly. A study with strong credibility and low transferability is still a rigorous study, as long as you are transparent about the scope.')
      + _cbEx('<b>Credibility gap:</b> You coded all the data yourself with no second coder. Document this as a limitation and note the compensating strategies: 8 weeks of data immersion, a reflective memo at every stage, and review of negative cases.<br><b>Not a gap:</b> Low transferability is not a flaw in a site-specific study. Thick description lets the reader decide whether findings apply to their own context.'),

    report: _cbWhy('Qualitative reports fail not because the analysis was weak but because the write-up does not connect themes to evidence or evidence to the research question. The structure matters: findings before interpretation, interpretation before recommendation.')
      + _cb('i', 'What it is',
        'The Report step generates a structured write-up of your analysis: project framing, methodology, findings by theme with supporting quotes, trustworthiness statement, and limitations. You can edit every section before exporting.')
      + _cb('M', 'What to check before generating',
        'Every theme should have at least 2 representative quotes selected. The researcher stance memo should be saved. The trustworthiness criteria should be filled in. The research question in Project Setup should reflect what you actually investigated.')
      + _cb('&#10003;', 'When you are ready',
        'Generate the report, review each section, then export to Word or PDF. The report cites participant IDs, not names. If you need to include real names, replace IDs manually after export.')
      + _cbEx('<b>Finding section structure:</b><br>Theme name (bold heading)<br>Narrative interpretation (2-4 sentences stating what the theme means and why it matters)<br>Supporting quotes (indented, italicized, attributed by participant ID)<br>Connection to research question (1-2 sentences explicit link)<br>Negative cases or caveats, if any'),
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

    // Explain tab (default) — output GUIDANCE blocks directly, no extra wrapper
    body.innerHTML = GUIDANCE[state.step]
      || '<p style="color:var(--ink-3);padding:8px 0">Add guidance for the <b>' + esc(state.step) + '</b> step in the GUIDANCE map.</p>';
  }

  function setCompTab(t) {
    state.compTab = t;
    renderCompanion();
  }

  function toggleCompanion() {
    document.body.classList.toggle('companion-collapsed');
  }

  // Expose globally so inline onclick attributes work.
  window.setCompTab          = setCompTab;
  window.toggleCompanion     = toggleCompanion;
  window.openSelectedProject = openSelectedProject;

  // ── Start workstation ────────────────────────────────────────────────────
  function renderStart(host) {
    host.innerHTML = '<div class="ws-header">'
      + '<div class="ws-eyebrow">Qualitative Analysis Studio</div>'
      + '<h1 class="ws-title">Continue a project, or start new.</h1>'
      + '</div>'
      + '<div class="ov-card" style="margin-bottom:20px">'
      + '<div class="ov-card-h" style="display:flex;align-items:center;justify-content:space-between">'
      + '<span>Your saved projects</span>'
      + '<a href="/qual-studio.php" style="font-size:12px;font-weight:700;color:var(--acc);text-decoration:none">View all &rarr;</a>'
      + '</div>'
      + '<div id="st-projects-body"><p style="color:var(--ink-3);font-size:13px">Loading&hellip;</p></div>'
      + '</div>'
      + '<div class="begin-or">Or start a new project</div>'
      + '<button class="begin-feature" onclick="openUpload()">'
      + '<div class="bc-ico">&#8593;</div>'
      + '<div><h4>Upload a dataset</h4>'
      + '<p>CSV, XLSX, or Qualtrics export. Open-ended columns are detected and segmented automatically.</p>'
      + '<div class="bc-go">Upload &rarr;</div></div>'
      + '</button>';

    var body = document.getElementById('st-projects-body');
    fetch('/api/qual/list-projects.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok || !d.projects || d.projects.length === 0) {
          body.innerHTML = '<p style="color:var(--ink-3);font-size:13px;padding:4px 0">No saved projects yet. Upload a dataset to begin.</p>';
          return;
        }
        var opts = d.projects.map(function (p) {
          var meta = [];
          if (p.seg_count) meta.push(p.seg_count + ' seg');
          if (p.code_count) meta.push(p.code_count + ' codes');
          var label = esc(p.title || 'Untitled') + (meta.length ? ' — ' + esc(meta.join(', ')) : '');
          return '<option value="' + p.id + '">' + label + '</option>';
        }).join('');
        body.innerHTML = '<div style="display:flex;gap:10px;align-items:center">'
          + '<select id="st-project-select" style="flex:1;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;color:var(--ink);background:var(--bg);cursor:pointer">'
          + '<option value="">-- Choose a project --</option>'
          + opts
          + '</select>'
          + '<button id="st-project-open" onclick="openSelectedProject()" style="padding:9px 18px;background:var(--btn);color:#fff;border:none;border-radius:8px;font-size:13.5px;font-weight:700;cursor:pointer;white-space:nowrap">Open &rarr;</button>'
          + '</div>';
      })
      .catch(function () {
        body.innerHTML = '<p style="color:var(--ink-3);font-size:13px">Could not load projects.</p>';
      });
  }

  function openSelectedProject() {
    var sel = document.getElementById('st-project-select');
    if (!sel || !sel.value) return;
    window.location.href = '/qual-studio-workspaceV3.php?project_id=' + sel.value;
  }

  function openUpload() {
    if (typeof DatasetUpload === 'undefined') { alert('Upload widget not loaded.'); return; }
    DatasetUpload.open({
      projectType: BOOT.projectType,
      projectId:   BOOT.projectId || 0,
      onLoaded: function (_err, projectId) {
        window.location.href = '/qual-studio-workspaceV3.php?project_id=' + projectId + '&step=cleaning';
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
    if (mapBtn) mapBtn.addEventListener('click', function () { activateStep('datamap'); });

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

  // ── Column Setup workstation ──────────────────────────────────────────────
  // Qual-specific replacement for the full DataMap. Four roles only:
  // open_ended → coded segments | participant_id → links segments to people
  // participant_info → metadata on each segment | skip → excluded entirely
  function renderColumnSetup(host) {
    if (!BOOT.projectId) {
      host.innerHTML = '<div class="ov-placeholder">No project loaded.</div>';
      return;
    }
    host.innerHTML = '<div class="ov-placeholder">Loading columns&hellip;</div>';

    fetch('/api/qual/get-variable-meta.php?project_id=' + BOOT.projectId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok || !d.variables || !d.variables.length) {
          host.innerHTML = '<div class="ov-placeholder">No dataset linked yet. Go to <strong>Upload</strong> first.</div>';
          return;
        }
        _renderColSetupForm(host, d.variables);
      })
      .catch(function () {
        host.innerHTML = '<div class="ov-placeholder">Could not load column information. Refresh and try again.</div>';
      });
  }

  var QUAL_ROLES = [
    ['open_ended',       'Code this',          'Open-ended response — each cell becomes a coded segment'],
    ['participant_id',   'Participant ID',      'Links segments back to the same person across questions'],
    ['participant_info', 'Participant context', 'Attaches to every segment as background (age, site, role, etc.)'],
    ['skip',             'Skip',               'Exclude this column from the analysis entirely'],
  ];

  function _colDefaultRole(v) {
    if (v.qual_role) return v.qual_role;
    var at = v.analysis_type || '';
    if (at === 'open_ended' || at === 'narrative') return 'open_ended';
    if (at === 'identifier') return 'participant_id';
    return 'participant_info';
  }

  function _renderColSetupForm(host, variables) {
    var roleOpts = QUAL_ROLES.map(function (r) {
      return { val: r[0], label: r[1] };
    });

    var rows = variables.map(function (v, i) {
      var name    = v.name || v.variable_name || '';
      var defRole = _colDefaultRole(v);
      var selOpts = roleOpts.map(function (o) {
        return '<option value="' + o.val + '"' + (defRole === o.val ? ' selected' : '') + '>' + o.label + '</option>';
      }).join('');
      var roleBadge = defRole === 'open_ended'
        ? '<span style="font-size:11px;padding:1px 7px;border-radius:6px;background:var(--acc-soft);color:var(--acc-deep);font-weight:700">auto</span>'
        : '';
      return '<tr>'
        + '<td class="ov-td" style="font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
          + esc(name) + ' ' + roleBadge + '</td>'
        + '<td class="ov-td"><select class="col-role-sel" data-col="' + esc(name) + '" '
          + 'style="width:100%;padding:6px 10px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:12.5px;background:var(--panel)">'
          + selOpts + '</select></td>'
        + '</tr>';
    }).join('');

    // Role legend
    var legend = QUAL_ROLES.map(function (r) {
      return '<div style="display:flex;gap:8px;align-items:flex-start;font-size:12.5px;margin-bottom:6px">'
        + '<span style="font-weight:700;min-width:130px;color:var(--ink)">' + r[1] + '</span>'
        + '<span style="color:var(--ink-3)">' + r[2] + '</span></div>';
    }).join('');

    host.innerHTML = '<div class="ws-header">'
      + '<div class="ws-eyebrow">Step 04</div>'
      + '<h1 class="ws-title">Column Setup</h1>'
      + '<p class="ws-lede">Tell ReliCheck which columns hold responses to code, which identify participants, and which provide background context.</p>'
      + '</div>'
      + '<div class="ov-card">'
      + '<div class="ov-card-h">Column roles '
        + '<span style="font-size:12px;font-weight:400;color:var(--ink-3)">' + variables.length + ' columns detected</span>'
      + '</div>'
      + '<div style="max-height:400px;overflow-y:auto">'
      + '<table class="ov-table"><thead><tr><th>Column</th><th>Role</th></tr></thead>'
      + '<tbody>' + rows + '</tbody></table>'
      + '</div>'
      + '<div style="margin-top:14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">'
      + '<button class="btn" id="cs-confirm">Confirm and build segments</button>'
      + '<span id="cs-msg" style="font-size:13px"></span>'
      + '</div>'
      + '</div>'
      + '<div class="ov-card" style="margin-top:0">'
      + '<div class="ov-card-h">Role guide</div>'
      + legend
      + '</div>';

    document.getElementById('cs-confirm').addEventListener('click', function () {
      var btn = this;
      var msg = document.getElementById('cs-msg');
      btn.disabled    = true;
      btn.textContent = 'Building…';
      msg.textContent = '';

      var sels    = [].slice.call(document.querySelectorAll('.col-role-sel'));
      var columns = sels.map(function (s) { return { name: s.dataset.col, qual_role: s.value }; });
      var openCount = columns.filter(function (c) { return c.qual_role === 'open_ended'; }).length;

      if (!openCount) {
        btn.disabled    = false;
        btn.textContent = 'Confirm and build segments';
        msg.style.color = '#c0392b';
        msg.textContent = 'Mark at least one column as “Code this” to create segments.';
        return;
      }

      api('/api/qual/save-column-roles.php', {
        method: 'POST',
        body:   JSON.stringify({ project_id: BOOT.projectId, columns: columns }),
      }).then(function (r) {
        btn.disabled    = false;
        btn.textContent = 'Confirm and build segments';
        var n = r.seg_count || 0;
        msg.style.color = n > 0 ? 'var(--acc)' : '#c0392b';
        msg.textContent = n > 0
          ? n + ' segment' + (n !== 1 ? 's' : '') + ' created. Moving to Data Cleaning…'
          : 'No segments created. Check that your open-ended columns have text.';
        if (n > 0) setTimeout(function () { activateStep('cleaning'); }, 1400);
      }).catch(function (e) {
        btn.disabled    = false;
        btn.textContent = 'Confirm and build segments';
        msg.style.color = '#c0392b';
        msg.textContent = 'Error: ' + e.message;
      });
    });
  }

  // ── Variable Map workstation (unused in Qual Studio — kept for reference) ──
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
        activateStep('setup');
      },
    });
    state._varmapMounted = true;
  }

  // ── Project Setup ────────────────────────────────────────────────────────
  function renderSetup(host) {
    var p = BOOT.project || {};
    host.innerHTML = '<div class="ws-header">'
      + '<div class="ws-eyebrow">Step 02</div>'
      + '<h1 class="ws-title">Project Setup</h1>'
      + '<p class="ws-lede">Define your research question, approach, and researcher stance before coding begins.</p>'
      + '</div>'
      + '<div class="ov-card"><div class="ov-card-h">Project information</div><div style="padding:0 0 8px">'
      + '<div style="margin-bottom:14px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px">Project title <span style="color:#c0392b">*</span></label>'
      + '<input id="su-title" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" value="' + esc(p.title || '') + '" placeholder="e.g. Staff Experience Open-Ends 2026"></div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">'
      + '<div><label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px">Analysis approach</label><select id="su-approach" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px">'
      + ['thematic','content','framework','open_ended_survey','document'].map(function (v) {
          var labels = { thematic:'Thematic Analysis', content:'Content Analysis', framework:'Framework Analysis', open_ended_survey:'Open-Ended Survey Analysis', document:'Document Analysis' };
          return '<option value="' + v + '"' + ((p.analysis_approach || 'thematic') === v ? ' selected' : '') + '>' + labels[v] + '</option>';
        }).join('')
      + '</select></div>'
      + '<div><label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px">Data type</label><select id="su-datatype" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px">'
      + [['open_ended_survey','Open-Ended Survey'],['interview','Interview Transcript'],['focus_group','Focus Group Transcript'],['document','Document / Field Notes']].map(function (x) {
          return '<option value="' + x[0] + '"' + ((p.data_type || 'open_ended_survey') === x[0] ? ' selected' : '') + '>' + x[1] + '</option>';
        }).join('')
      + '</select></div></div>'
      + '<div style="margin-bottom:14px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px">Research question <span style="display:block;font-weight:400;color:var(--ink-3)">What are you trying to understand through this analysis?</span></label>'
      + '<input id="su-rq" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" value="' + esc(p.research_question || '') + '" placeholder="What are participants saying about..."></div>'
      + '<div style="margin-bottom:14px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px">Purpose <span style="display:block;font-weight:400;color:var(--ink-3)">Who will use these findings, and for what decision?</span></label>'
      + '<input id="su-purpose" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" value="' + esc(p.purpose || '') + '" placeholder="To inform the 2026 program redesign..."></div>'
      + '<div style="margin-bottom:14px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px">Participant description</label>'
      + '<input id="su-participants" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" value="' + esc(p.participant_description || '') + '" placeholder="e.g. 412 K-12 educators across 3 districts"></div>'
      + '</div></div>'
      + '<div class="ov-card"><div class="ov-card-h">Researcher stance memo <span style="font-size:12px;font-weight:400;color:var(--ink-3)">saved to audit trail</span></div>'
      + '<div style="padding:0 0 8px"><label style="display:block;font-size:12px;color:var(--ink-3);margin-bottom:8px">What assumptions, roles, or experiences might shape how you interpret this data? Being transparent about this is part of what makes qualitative findings defensible.</label>'
      + '<textarea id="su-stance" style="width:100%;min-height:110px;padding:10px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px;resize:vertical" placeholder="I am an external evaluator...">' + esc(p.researcher_stance_memo || '') + '</textarea>'
      + '</div></div>'
      + ContextualLens.panel('project', p, 'su_cl_')
      + '<div id="su-msg" style="display:none;font-size:13px;margin-top:4px"></div>'
      + '<div style="display:flex;gap:10px;margin-top:8px">'
      + '<button class="btn" id="su-save">Save setup</button>'
      + '<button class="btn" id="su-next" style="background:none;color:var(--ink-2);border:1px solid var(--line)">Next: Data Entry / Upload &rarr;</button>'
      + '</div>';

    document.getElementById('su-save').addEventListener('click', function () {
      var msg  = document.getElementById('su-msg');
      var body = {
        title:                   document.getElementById('su-title').value.trim(),
        analysis_approach:       document.getElementById('su-approach').value,
        data_type:               document.getElementById('su-datatype').value,
        research_question:       document.getElementById('su-rq').value.trim(),
        purpose:                 document.getElementById('su-purpose').value.trim(),
        participant_description: document.getElementById('su-participants').value.trim(),
        researcher_stance_memo:  document.getElementById('su-stance').value.trim(),
      };
      Object.assign(body, ContextualLens.gather('project', 'su_cl_'));
      if (!body.title) { msg.textContent = 'Title is required.'; msg.style.cssText = 'display:block;color:#c0392b;'; return; }
      msg.textContent = 'Saving...'; msg.style.cssText = 'display:block;color:var(--ink-3);';

      var savePromise;
      if (!BOOT.projectId) {
        savePromise = api('/api/qual/create-project.php', { method: 'POST', body: JSON.stringify(body) })
          .then(function (d) {
            BOOT.projectId = d.project_id;
            window.history.replaceState({}, '', '/qual-studio-workspaceV3.php?project_id=' + d.project_id + '&step=setup');
          });
      } else {
        savePromise = api('/api/qual/save-project.php', { method: 'POST', body: JSON.stringify(Object.assign({ project_id: BOOT.projectId }, body)) });
      }

      savePromise
        .then(function () {
          BOOT.project = Object.assign(BOOT.project || {}, body);
          StudioHeader.setProject(body.title, true);
          msg.textContent = 'Saved.'; msg.style.cssText = 'display:block;color:var(--acc);';
          setTimeout(function () { msg.style.display = 'none'; }, 2000);
        })
        .catch(function (e) { msg.textContent = 'Error: ' + e.message; msg.style.cssText = 'display:block;color:#c0392b;'; });
    });
    document.getElementById('su-next').addEventListener('click', function () { activateStep('upload'); });
  }

  // ── Data Entry / Upload ──────────────────────────────────────────────────
  function renderUpload(host) {
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
    if (toOv) toOv.addEventListener('click', function () { activateStep('datamap'); });

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
        onLoaded: function (_err, projectId) {
          window.location.href = '/qual-studio-workspaceV3.php?project_id=' + projectId + '&step=datamap';
        },
      });
    });
  }

  // ── Data Cleaning ─────────────────────────────────────────────────────────
  function renderDeident(host) {
    host.innerHTML = '<div class="ws-header">'
      + '<div class="ws-eyebrow">Step 03</div>'
      + '<h1 class="ws-title">Data Cleaning</h1>'
      + '<p class="ws-lede">Scan for personal information (emails, phone numbers, names) before analysis begins. Masking is optional but recommended for data shared beyond the original research team.</p>'
      + '</div>'
      + '<div id="di-body"><div class="btn-row">'
      + '<button class="btn" id="di-scan">Scan for PII</button>'
      + '<button class="btn" id="di-skip" style="background:none;color:var(--ink-2);border:1px solid var(--line)">Skip, continue to Familiarization &rarr;</button>'
      + '</div></div>';

    document.getElementById('di-skip').addEventListener('click', function () { activateStep('familiarization'); });
    document.getElementById('di-scan').addEventListener('click', function () {
      var body = document.getElementById('di-body');
      body.innerHTML = '<p style="color:var(--ink-3)">Scanning segments&hellip;</p>';
      fetch('/api/qual/scan-pii.php?project_id=' + BOOT.projectId)
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) throw new Error(d.message || 'Scan failed.');
          renderPiiResults(body, d);
        })
        .catch(function (e) {
          body.innerHTML = '<p style="color:var(--ink-3)">Scan error: ' + esc(e.message) + '</p>'
            + '<div class="btn-row"><button class="btn" id="di-skip2">Continue without scanning</button></div>';
          document.getElementById('di-skip2').addEventListener('click', function () { activateStep('familiarization'); });
        });
    });

    function renderPiiResults(body, d) {
      if (d.flag_count === 0) {
        body.innerHTML = '<p style="color:var(--ink-2)">No PII patterns detected in ' + d.total_segments + ' segments.</p>'
          + '<div class="btn-row"><button class="btn" id="di-done">Continue to Familiarization &rarr;</button></div>';
        document.getElementById('di-done').addEventListener('click', function () { activateStep('familiarization'); });
        return;
      }

      var typeLabel = { email: 'Email', phone: 'Phone', ssn: 'ID Number', name_intro: 'Name' };
      var rows = d.flagged.map(function (f) {
        var patternList = f.patterns.map(function (p) {
          return '<span class="pii-badge" style="font-size:11px;padding:2px 7px;border-radius:6px;background:var(--acc-soft);color:var(--acc-deep);font-weight:700;margin-right:4px">' + (typeLabel[p.type] || p.type) + ': ' + esc(p.match) + '</span>';
        }).join('');
        return '<div id="pii-' + f.segment_id + '" style="display:flex;align-items:center;gap:10px;padding:7px 10px;border-bottom:1px solid var(--line-2);font-size:12.5px">'
          + '<div style="flex:1;min-width:0">'
          + '<div style="margin-bottom:3px">' + patternList + '</div>'
          + '<div style="color:var(--ink-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(f.original) + '</div>'
          + '</div>'
          + '<div style="display:flex;gap:6px;flex:none">'
          + '<button class="btn" style="font-size:11px;padding:4px 10px" data-mask="' + f.segment_id + '">Mask</button>'
          + '<button style="font-size:11px;padding:4px 10px;border:1px solid var(--line);border-radius:8px;background:none;color:var(--ink-3);cursor:pointer" data-skip="' + f.segment_id + '">Skip</button>'
          + '</div></div>';
      }).join('');

      body.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">'
        + '<span style="font-size:13px;color:var(--ink-2)">' + d.flag_count + ' segment' + (d.flag_count !== 1 ? 's' : '') + ' flagged of ' + d.total_segments + ' total</span>'
        + '<div style="display:flex;gap:8px">'
        + '<button class="btn" id="di-mask-all" style="font-size:12px;padding:6px 12px">Mask all</button>'
        + '<button id="di-skip3" style="font-size:12px;padding:6px 12px;border:1px solid var(--line);border-radius:8px;background:none;color:var(--ink-2);cursor:pointer">Continue without changes</button>'
        + '</div></div>'
        + '<div id="pii-list" style="max-height:360px;overflow-y:auto;border:1px solid var(--line);border-radius:10px">' + rows + '</div>'
        + '<div style="margin-top:12px"><button class="btn" id="di-continue">Continue to Familiarization &rarr;</button></div>';

      document.getElementById('di-skip3').addEventListener('click', function () { activateStep('familiarization'); });
      document.getElementById('di-continue').addEventListener('click', function () { activateStep('familiarization'); });

      function maskSegment(sid, btn) {
        if (btn) { btn.disabled = true; btn.textContent = 'Masking...'; }
        api('/api/qual/mask-pii.php', {
          method: 'POST',
          body: JSON.stringify({ project_id: BOOT.projectId, segment_id: sid }),
        }).then(function (r) {
          var row = document.getElementById('pii-' + sid);
          if (row) row.innerHTML = '<div class="pii-masked"><span class="pii-badge" style="background:var(--acc-soft);color:var(--acc-deep)">Masked</span> ' + esc(r.masked_text) + '</div>';
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
  }

  // ── Familiarization ──────────────────────────────────────────────────────
  function renderFamiliarization(host) {
    host.innerHTML = '<div class="ws-header">'
      + '<div class="ws-eyebrow">Step 06</div>'
      + '<h1 class="ws-title">Familiarization</h1>'
      + '<p class="ws-lede">Explore the corpus before formal coding. Read through responses, record your first impressions, and let ReliCheck Intelligence surface recurring concepts.</p>'
      + '</div>'
      + '<div id="fam-stats" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px"></div>'
      + '<div class="ov-card"><div class="ov-card-h">First Impressions Memo <span style="font-size:12px;font-weight:400;color:var(--ink-3)">saved to audit trail</span></div>'
      + '<p style="font-size:13px;color:var(--ink-3);margin:0 0 10px">Before coding, what stands out? What surprises you? What patterns, tensions, or questions do you notice?</p>'
      + '<textarea id="fam-memo" style="width:100%;min-height:120px;padding:10px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px;resize:vertical" placeholder="I noticed several responses mentioned..."></textarea>'
      + '<div id="fam-msg" style="display:none;font-size:13px;margin-top:6px"></div>'
      + '<div style="display:flex;gap:10px;margin-top:10px">'
      + '<button class="btn" id="fam-save">Save memo</button>'
      + '<button class="btn" id="fam-next" style="background:none;color:var(--ink-2);border:1px solid var(--line)">Start coding &rarr;</button>'
      + '</div></div>'
      + '<div class="ov-card"><div class="ov-card-h">Linguistic Concept Scan <span style="font-size:12px;font-weight:400;color:var(--ink-3)">powered by ReliCheck Intelligence</span></div>'
      + '<p style="font-size:13px;color:var(--ink-3);margin:0 0 12px">Analyzes a sample of responses and identifies recurring concepts before you begin formal coding.</p>'
      + '<div id="scan-body">'
      + '<button class="btn" id="scan-run">Run Concept Scan</button>'
      + '</div></div>';

    // Load stats
    if (BOOT.projectId) {
      api('/api/qual/get-project.php?project_id=' + BOOT.projectId)
        .then(function (d) {
          var s = d.stats || {};
          var el = document.getElementById('fam-stats');
          if (el) el.innerHTML = [
            [s.seg_count,   'Segments'],
            [s.doc_count,   'Data sources'],
            [s.total_words, 'Total words'],
            [s.avg_words,   'Avg words / segment'],
            [s.code_count,  'Codes in codebook'],
          ].map(function (x) {
            return '<div style="background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px 18px;min-width:110px;text-align:center">'
              + '<div style="font-size:24px;font-weight:800;color:var(--ink)">' + Number(x[0] || 0).toLocaleString() + '</div>'
              + '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);margin-top:4px">' + x[1] + '</div>'
              + '</div>';
          }).join('');
        }).catch(function () {});
    }

    document.getElementById('fam-save').addEventListener('click', function () {
      var body = (document.getElementById('fam-memo').value || '').trim();
      var msg  = document.getElementById('fam-msg');
      if (!body) { msg.textContent = 'Write something before saving.'; msg.style.cssText = 'display:block;color:#c0392b;'; return; }
      msg.textContent = 'Saving...'; msg.style.cssText = 'display:block;color:var(--ink-3);';
      api('/api/qual/save-memo.php', { method: 'POST', body: JSON.stringify({
        project_id: BOOT.projectId, object_type: 'project',
        memo_type: 'first_impressions', title: 'First Impressions', body: body,
      })}).then(function () {
        msg.textContent = 'Memo saved.'; msg.style.cssText = 'display:block;color:var(--acc);';
        setTimeout(function () { msg.style.display = 'none'; }, 2500);
      }).catch(function (e) { msg.textContent = 'Error: ' + e.message; msg.style.cssText = 'display:block;color:#c0392b;'; });
    });

    document.getElementById('fam-next').addEventListener('click', function () { activateStep('coding'); });

    function runScan(force) {
      var scanBody = document.getElementById('scan-body');
      if (!scanBody) return;
      scanBody.innerHTML = '<p style="color:var(--ink-3);font-size:13px">Analyzing corpus with ReliCheck Intelligence&hellip; this may take 15-30 seconds.</p>';
      api('/api/qual/concept-scan.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, force: !!force }) })
        .then(function (r) { renderConceptScan(scanBody, r); })
        .catch(function (e) {
          scanBody.innerHTML = '<p style="color:#c0392b;font-size:13px">Scan failed: ' + esc(e.message) + '</p>'
            + '<button class="btn" id="scan-retry">Try again</button>';
          document.getElementById('scan-retry').addEventListener('click', function () { runScan(true); });
        });
    }

    function renderConceptScan(scanBody, r) {
      var etColors = { lexical: 'background:#eef3fa;color:#085fcc', phrase: 'background:#e8f5ee;color:#174d30', semantic: 'background:#fff8ee;color:#92400e' };
      var cards = (r.concepts || []).map(function (c) {
        var etStyle = etColors[c.evidence_type] || 'background:#f3f4f6;color:#374151';
        var quotes = (c.example_quotes || []).map(function (q) {
          return '<div style="font-size:12px;color:var(--ink-2);border-left:3px solid var(--line);padding-left:8px;margin-top:6px">&ldquo;' + esc(q) + '&rdquo;</div>';
        }).join('');
        return '<div style="border:1px solid var(--line);border-radius:10px;padding:12px 14px;background:var(--panel)">'
          + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">'
          + '<span style="font-weight:700;font-size:13.5px">' + esc(c.concept) + '</span>'
          + '<span style="font-size:11px;padding:2px 8px;border-radius:6px;' + etStyle + '">' + esc(c.evidence_type) + '</span>'
          + '<span style="font-size:12px;color:var(--ink-3);margin-left:auto">' + (c.frequency || '?') + ' responses</span>'
          + '</div>' + quotes + '</div>';
      }).join('');
      var note = r.from_cache ? '<span style="font-size:12px;color:var(--ink-3)">Cached result &middot; </span>' : '';
      scanBody.innerHTML = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">'
        + note + '<span style="font-size:13px;color:var(--ink-3)">' + (r.segments_scanned || 0) + ' segments scanned</span>'
        + '<button class="btn" id="scan-rerun" style="font-size:12px;padding:5px 12px;margin-left:auto">Re-run</button>'
        + '</div>'
        + '<div style="display:flex;flex-direction:column;gap:10px;max-height:360px;overflow-y:auto">' + cards + '</div>';
      document.getElementById('scan-rerun').addEventListener('click', function () { runScan(true); });
    }

    // Auto-load cached scan
    api('/api/qual/concept-scan.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, force: false }) })
      .then(function (r) { var sb = document.getElementById('scan-body'); if (sb && r.from_cache) renderConceptScan(sb, r); })
      .catch(function () {});

    document.getElementById('scan-run').addEventListener('click', function () { runScan(true); });
  }

  // ── Shared helpers ────────────────────────────────────────────────────────
  function loadCodesIfNeeded() {
    if (state.codes) return Promise.resolve();
    return api('/api/qual/get-codes.php?project_id=' + BOOT.projectId)
      .then(function (d) { state.codes = d.codes || []; });
  }

  function _statCard(val, label) {
    return '<div style="background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px 18px;min-width:110px;text-align:center">'
      + '<div style="font-size:24px;font-weight:800;color:var(--ink)">' + Number(val || 0).toLocaleString() + '</div>'
      + '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);margin-top:4px">' + label + '</div>'
      + '</div>';
  }

  // ── Coding Workspace ──────────────────────────────────────────────────────
  function renderCoding(host) {
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
        if (!filtered.length) { list.innerHTML = '<div style="color:var(--ink-3);padding:24px;text-align:center">No segments ' + (uncodedOnly ? 'left to code.' : 'found.') + '</div>'; return; }
        list.innerHTML = filtered.map(renderSegCard).join('');
      }

      function renderSegCard(seg) {
        var meta = seg.metadata_json || {};
        var metaItems = Object.keys(meta).slice(0, 4).map(function (k) {
          return '<span style="font-size:11px;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:1px 7px;color:var(--ink-3)">' + esc(k) + ': ' + esc(String(meta[k])) + '</span>';
        }).join('');
        var pid  = seg.participant_id ? '<span style="font-size:11px;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:1px 7px;color:var(--ink-3)">ID: ' + esc(seg.participant_id) + '</span>' : '';
        var q    = seg.question_ref   ? '<span style="font-size:11px;color:var(--ink-3)">' + esc(seg.question_ref) + '</span>' : '';
        var chips = (seg.codes || []).map(function (c) {
          return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;padding:2px 8px;border-radius:8px;background:var(--acc-soft);color:var(--acc-deep);font-weight:600">' + esc(c.name)
            + '<button style="border:none;background:none;cursor:pointer;color:var(--acc-deep);font-size:13px;line-height:1;padding:0" class="chip-x" data-seg="' + seg.id + '" data-code="' + c.id + '">&times;</button></span>';
        }).join('');
        var over = seg.code_count >= 4;
        var flag = seg.code_count === 0
          ? '<span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;background:#fff8ee;color:#b45309">Uncoded</span>'
          : over ? '<span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;background:#fef2f2;color:#c0392b">Over-coded (4+)</span>' : '';
        var pickerItems = state.codes.length
          ? state.codes.map(function (c) {
              return '<button style="display:block;width:100%;text-align:left;padding:7px 12px;border:none;background:none;cursor:pointer;font-size:13px;font-family:inherit" class="picker-item" data-seg="' + seg.id + '" data-code="' + c.id + '" data-name="' + esc(c.name) + '">' + esc(c.name) + '</button>';
            }).join('')
          : '<div style="padding:8px 12px;font-size:12.5px;color:var(--ink-3)">No codes yet.</div>';

        return '<div style="border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:10px;background:var(--panel)" id="seg-' + seg.id + '">'
          + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">' + pid + q + metaItems + '</div>'
          + '<div style="font-size:13.5px;line-height:1.6;color:var(--ink);margin-bottom:10px">' + esc(seg.raw_text) + '</div>'
          + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px" id="chips-' + seg.id + '">' + chips + '</div>'
          + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">'
          + '<div style="position:relative" id="pw-' + seg.id + '">'
          + '<button style="font-size:12px;padding:4px 12px;border:1px solid var(--line);border-radius:8px;background:var(--panel);cursor:pointer;font-family:inherit;color:var(--ink-2)" class="add-code-btn" data-seg="' + seg.id + '">+ Add code</button>'
          + '<div id="picker-' + seg.id + '" style="display:none;position:absolute;top:100%;left:0;z-index:100;background:var(--panel);border:1px solid var(--line);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.1);min-width:180px;max-height:220px;overflow-y:auto">'
          + pickerItems
          + '<div style="border-top:1px solid var(--line);padding:6px 8px"><button style="display:block;width:100%;text-align:left;padding:6px 8px;border:none;background:none;cursor:pointer;font-size:12.5px;font-weight:700;color:var(--acc);font-family:inherit" class="picker-new-btn" data-seg="' + seg.id + '">+ New code</button></div>'
          + '</div></div>'
          + '<button style="font-size:12px;padding:4px 12px;border:1px solid var(--line);border-radius:8px;background:var(--panel);cursor:pointer;font-family:inherit;color:var(--ink-2)" class="ai-suggest-btn" data-seg="' + seg.id + '">&#9734; Suggest codes</button>'
          + flag + '</div>'
          + '<div id="aip-' + seg.id + '" style="display:none;margin-top:8px"></div>'
          + '</div>';
      }

      host.innerHTML = '<div class="ws-header">'
        + '<div class="ws-eyebrow">Step 07</div>'
        + '<h1 class="ws-title">Coding Workspace</h1>'
        + '<p class="ws-lede">Apply codes to each segment. Use ReliCheck Intelligence to get AI code suggestions.</p>'
        + '</div>'
        + '<div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">'
        + '<input style="flex:1;min-width:180px;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" id="seg-search" placeholder="Search segments...">'
        + '<button class="btn" id="filter-all" style="background:var(--acc)">All</button>'
        + '<button class="btn" id="filter-uncoded" style="background:none;color:var(--ink-2);border:1px solid var(--line)">Uncoded only</button>'
        + '<button class="btn" id="go-codebook" style="background:none;color:var(--ink-2);border:1px solid var(--line)">Manage codebook</button>'
        + '</div>'
        + '<div id="seg-counts" style="font-size:13px;color:var(--ink-3);margin-bottom:12px">Loading...</div>'
        + '<div id="seg-list"><div style="color:var(--ink-3);padding:24px;text-align:center">Loading segments...</div></div>';

      document.getElementById('seg-search').addEventListener('input', function () { searchQuery = this.value; renderList(); });
      document.getElementById('filter-all').addEventListener('click', function () { uncodedOnly = false; this.style.background = 'var(--acc)'; this.style.color = '#fff'; document.getElementById('filter-uncoded').style.cssText = 'background:none;color:var(--ink-2);border:1px solid var(--line)'; load(); });
      document.getElementById('filter-uncoded').addEventListener('click', function () { uncodedOnly = true; this.style.background = 'var(--acc)'; this.style.color = '#fff'; document.getElementById('filter-all').style.cssText = 'background:none;color:var(--ink-2);border:1px solid var(--line)'; load(); });
      document.getElementById('go-codebook').addEventListener('click', function () { activateStep('codebook'); });

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
          document.querySelectorAll('[id^="picker-"]').forEach(function (p) { if (p.id !== 'picker-' + sid) p.style.display = 'none'; });
          var picker = document.getElementById('picker-' + sid);
          if (picker) picker.style.display = picker.style.display === 'none' ? 'block' : 'none';
          setTimeout(function () {
            document.addEventListener('click', function close(ev) {
              if (!ev.target.closest('#pw-' + sid)) { var p = document.getElementById('picker-' + sid); if (p) p.style.display = 'none'; document.removeEventListener('click', close); }
            });
          }, 10);
        }
        if (item) { var sid = item.dataset.seg, cid = item.dataset.code, cname = item.dataset.name; var picker = document.getElementById('picker-' + sid); if (picker) picker.style.display = 'none'; applyCode(+sid, +cid, cname); }
        if (newBtn) {
          var sid = newBtn.dataset.seg;
          var name = prompt('New code name:');
          if (!name || !name.trim()) return;
          api('/api/qual/save-code.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, name: name.trim() }) })
            .then(function (r) { state.codes.push({ id: r.code_id, name: name.trim() }); applyCode(+sid, r.code_id, name.trim()); })
            .catch(function (e) { alert('Error: ' + e.message); });
        }
        if (removeBtn) {
          var sid = +removeBtn.dataset.seg, cid = +removeBtn.dataset.code;
          api('/api/qual/remove-code.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, segment_id: sid, code_id: cid }) })
            .then(function () {
              var chip = removeBtn.closest('[style*="acc-soft"]'); if (chip) chip.remove();
              var seg = segments.find(function (s) { return s.id === sid; });
              if (seg) { seg.codes = seg.codes.filter(function (c) { return c.id !== cid; }); seg.code_count = seg.codes.length; }
            }).catch(function (e) { alert('Error: ' + e.message); });
        }
        if (sugBtn) {
          var sid = +sugBtn.dataset.seg;
          var panel = document.getElementById('aip-' + sid);
          if (!panel) return;
          if (panel.style.display !== 'none' && panel.innerHTML !== '') { panel.style.display = 'none'; return; }
          panel.style.display = 'block';
          panel.innerHTML = '<div style="font-size:12.5px;color:var(--ink-3);padding:8px 0">ReliCheck Intelligence is analyzing this segment&hellip;</div>';
          sugBtn.disabled = true;
          api('/api/qual/suggest-codes.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, segment_id: sid }) })
            .then(function (r) { sugBtn.disabled = false; renderSuggestions(panel, sid, r.suggestions || []); })
            .catch(function (ex) { sugBtn.disabled = false; panel.innerHTML = '<div style="font-size:12.5px;color:#c0392b;padding:8px 0">Could not get suggestions: ' + esc(ex.message) + '</div>'; });
        }
        if (applyAi) {
          var sid = +applyAi.dataset.seg, cname = applyAi.dataset.name;
          var existCode = state.codes.find(function (c) { return c.name.toLowerCase() === cname.toLowerCase(); });
          if (existCode) { applyCode(sid, existCode.id, existCode.name); applyAi.closest('.ai-sug-row').style.opacity = '.4'; }
          else { api('/api/qual/save-code.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, name: cname }) }).then(function (r) { state.codes.push({ id: r.code_id, name: cname }); applyCode(sid, r.code_id, cname); applyAi.closest('.ai-sug-row').style.opacity = '.4'; }).catch(function (ex) { alert('Error: ' + ex.message); }); }
        }
        if (dismissAi) { dismissAi.closest('.ai-sug-row').style.opacity = '.4'; }
      });

      function renderSuggestions(panel, sid, suggestions) {
        if (!suggestions.length) { panel.innerHTML = '<div style="padding:8px 0;font-size:13px;color:var(--ink-3)">No suggestions. Add more codes to the codebook first.</div>'; return; }
        var etColors = { lexical: '#085fcc', phrase: '#174d30', semantic: '#92400e', syntactic: '#6b21a8' };
        var confIcons = { high: '&#9679;&#9679;&#9679;', medium: '&#9679;&#9679;&#9675;', low: '&#9679;&#9675;&#9675;' };
        var rows = suggestions.map(function (s) {
          var etColor = etColors[s.evidence_type] || '#374151';
          var existBadge = s.is_existing
            ? '<span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:999px;background:var(--acc-soft);color:var(--acc-deep);margin-left:6px">in codebook</span>'
            : '<span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:999px;background:#f3f4f6;color:#6b7280;margin-left:6px">new</span>';
          return '<div class="ai-sug-row" style="margin-bottom:12px">'
            + '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:4px">'
            + '<span style="font-size:14px;font-weight:700">' + esc(s.name) + '</span>' + existBadge
            + '<span style="font-size:10.5px;padding:2px 7px;border-radius:999px;font-weight:700;color:' + etColor + ';background:' + etColor + '18">' + esc(s.evidence_type) + '</span>'
            + '<span style="font-size:11px;color:var(--ink-3)">' + (confIcons[s.confidence] || '') + '</span>'
            + '</div>'
            + '<div style="font-size:12.5px;color:var(--ink-2);margin-bottom:8px;line-height:1.45">' + esc(s.rationale) + '</div>'
            + '<div style="display:flex;gap:8px">'
            + '<button class="btn ai-apply-btn" style="font-size:12px;padding:5px 12px" data-seg="' + sid + '" data-name="' + esc(s.name) + '">Apply</button>'
            + '<button class="btn ai-dismiss-btn" style="font-size:12px;padding:5px 10px;background:none;color:var(--ink-2);border:1px solid var(--line)">Dismiss</button>'
            + '</div></div>';
        }).join('');
        panel.innerHTML = '<div style="padding:10px 0"><div style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:10px">&#9734; ReliCheck Intelligence suggestions</div>' + rows + '</div>';
      }

      function applyCode(sid, cid, cname) {
        api('/api/qual/apply-code.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, segment_id: sid, code_id: cid }) })
          .then(function () {
            var seg = segments.find(function (s) { return s.id === sid; });
            if (seg && !seg.codes.find(function (c) { return c.id === cid; })) { seg.codes.push({ id: cid, name: cname }); seg.code_count = seg.codes.length; }
            var chipsEl = document.getElementById('chips-' + sid);
            if (chipsEl && seg) {
              chipsEl.innerHTML = seg.codes.map(function (c) {
                return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;padding:2px 8px;border-radius:8px;background:var(--acc-soft);color:var(--acc-deep);font-weight:600">' + esc(c.name)
                  + '<button style="border:none;background:none;cursor:pointer;color:var(--acc-deep);font-size:13px;line-height:1;padding:0" class="chip-x" data-seg="' + sid + '" data-code="' + c.id + '">&times;</button></span>';
              }).join('');
            }
          }).catch(function (e) { alert('Could not apply code: ' + e.message); });
      }

      load();
    });
  }

  // ── Codebook Builder ──────────────────────────────────────────────────────
  function renderCodebook(host) {
    loadCodesIfNeeded().then(function () {
      function renderTable() {
        if (!state.codes.length) return '<div style="color:var(--ink-3);padding:24px;text-align:center">No codes yet. Add your first code below.</div>';
        return '<div style="max-height:360px;overflow-y:auto;border:1px solid var(--line);border-radius:10px"><table style="width:100%;border-collapse:collapse;font-size:13px">'
          + '<thead><tr style="background:var(--line-2);position:sticky;top:0"><th style="text-align:left;padding:9px 12px;font-weight:700">Code</th><th style="text-align:left;padding:9px 12px;font-weight:700">Definition</th><th style="text-align:right;padding:9px 12px;font-weight:700">Applied</th><th style="padding:9px 12px"></th></tr></thead><tbody>'
          + state.codes.map(function (c) {
              return '<tr style="border-top:1px solid var(--line-2)">'
                + '<td style="padding:9px 12px;font-weight:700">' + esc(c.name) + '</td>'
                + '<td style="padding:9px 12px;color:var(--ink-2);max-width:260px">' + (c.definition ? esc(c.definition) : '<span style="color:var(--ink-3);font-style:italic">No definition</span>') + '</td>'
                + '<td style="padding:9px 12px;text-align:right;color:var(--ink-3)">' + (c.application_count || 0) + '</td>'
                + '<td style="padding:9px 12px"><button class="btn" style="font-size:12px;padding:4px 10px" data-edit="' + c.id + '">Edit</button></td>'
                + '</tr>';
            }).join('')
          + '</tbody></table></div>';
      }

      host.innerHTML = '<div class="ws-header">'
        + '<div class="ws-eyebrow">Step 08</div>'
        + '<h1 class="ws-title">Codebook Builder</h1>'
        + '<p class="ws-lede">Define, refine, and manage the codes used in your analysis.</p>'
        + '</div>'
        + '<div id="cb-table">' + renderTable() + '</div>'
        + '<div class="ov-card" style="margin-top:20px" id="code-form-panel">'
        + '<div class="ov-card-h" id="cb-form-title">Add a new code</div>'
        + '<div style="margin-top:4px">'
        + '<div style="margin-bottom:12px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Code name <span style="color:#c0392b">*</span></label><input id="cb-name" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" placeholder="e.g. Lack of Communication"></div>'
        + '<div style="margin-bottom:12px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px">Definition <span style="display:block;font-weight:400;color:var(--ink-3)">What does this code capture? Be specific enough that two coders would agree.</span></label><textarea id="cb-def" style="width:100%;min-height:70px;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px;resize:vertical" placeholder="Apply when a respondent describes..."></textarea></div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">'
        + '<div><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Include when</label><textarea id="cb-include" style="width:100%;min-height:60px;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px;resize:vertical" placeholder="The response describes a gap..."></textarea></div>'
        + '<div><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Exclude when</label><textarea id="cb-exclude" style="width:100%;min-height:60px;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px;resize:vertical" placeholder="The response is about a different construct..."></textarea></div>'
        + '</div>'
        + '<div style="margin-bottom:12px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Example quote</label><input id="cb-quote" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" placeholder="&quot;I never know who to go to...&quot;"></div>'
        + ContextualLens.panel('code', null, 'cb_cl_')
        + '<input type="hidden" id="cb-editing-id">'
        + '<div id="cb-msg" style="display:none;font-size:13px;margin-bottom:8px;margin-top:12px"></div>'
        + '<div style="display:flex;gap:8px;margin-top:12px">'
        + '<button class="btn" id="cb-save">Add code</button>'
        + '<button class="btn" id="cb-cancel" style="display:none;background:none;color:var(--ink-2);border:1px solid var(--line)">Cancel</button>'
        + '</div></div></div>';

      function clearForm() {
        ['cb-name','cb-def','cb-include','cb-exclude','cb-quote'].forEach(function (id) { document.getElementById(id).value = ''; });
        document.getElementById('cb-editing-id').value = '';
        document.getElementById('cb-form-title').textContent = 'Add a new code';
        document.getElementById('cb-save').textContent = 'Add code';
        document.getElementById('cb-cancel').style.display = 'none';
        ContextualLens.populate('code', {}, 'cb_cl_');
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
        ContextualLens.populate('code', code, 'cb_cl_');
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
        if (!name) { msg.textContent = 'Code name is required.'; msg.style.cssText = 'display:block;color:#c0392b;'; return; }
        msg.textContent = 'Saving...'; msg.style.cssText = 'display:block;color:var(--ink-3);';
        var body = { project_id: BOOT.projectId, name: name,
          definition:    document.getElementById('cb-def').value.trim(),
          include_when:  document.getElementById('cb-include').value.trim(),
          exclude_when:  document.getElementById('cb-exclude').value.trim(),
          example_quote: document.getElementById('cb-quote').value.trim() };
        Object.assign(body, ContextualLens.gather('code', 'cb_cl_'));
        if (editId) body.id = editId;
        api('/api/qual/save-code.php', { method: 'POST', body: JSON.stringify(body) })
          .then(function () { return api('/api/qual/get-codes.php?project_id=' + BOOT.projectId); })
          .then(function (r) {
            state.codes = r.codes || [];
            document.getElementById('cb-table').innerHTML = renderTable();
            clearForm();
            msg.textContent = editId ? 'Code updated.' : 'Code added.';
            msg.style.cssText = 'display:block;color:var(--acc);';
            setTimeout(function () { msg.style.display = 'none'; }, 2500);
          }).catch(function (e) { msg.textContent = 'Error: ' + e.message; msg.style.cssText = 'display:block;color:#c0392b;'; });
      });
    });
  }

  // ── Dual Coder ────────────────────────────────────────────────────────────
  function renderDual(host) {
    var dcState = { tab: 'team', inviteData: null, disagData: null, reviewFilter: 'disagree' };

    function renderPage() {
      host.innerHTML = '<div class="ws-header">'
        + '<div class="ws-eyebrow">Step 09</div>'
        + '<h1 class="ws-title">Dual Coder</h1>'
        + '<p class="ws-lede">Invite a second person to independently code the same segments. Compare your decisions to measure reliability and strengthen your findings.</p>'
        + '</div>'
        + '<div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:22px">'
        + _dTab('team',   'Team',               dcState.tab)
        + _dTab('review', 'Disagreement Review', dcState.tab)
        + '</div><div id="dc-panel"></div>';
      host.querySelector('[data-tab]') && host.addEventListener('click', function (e) {
        var t = e.target.closest('.dc-tab');
        if (t) { dcState.tab = t.dataset.tab; renderPage(); }
      });
      if (dcState.tab === 'team') renderTeam(); else renderReview();
    }

    function _dTab(id, label, active) {
      return '<button class="dc-tab" data-tab="' + id + '" style="font-size:13.5px;font-weight:700;padding:9px 18px;border:none;background:none;cursor:pointer;font-family:inherit;border-bottom:2px solid ' + (active === id ? 'var(--acc)' : 'transparent') + ';color:' + (active === id ? 'var(--acc-deep)' : 'var(--ink-3)') + ';margin-bottom:-2px">' + label + '</button>';
    }

    function _progressBar(pct, color) {
      return '<div style="background:var(--bg);border-radius:999px;height:6px;overflow:hidden"><div style="height:6px;border-radius:999px;background:' + color + ';width:' + pct + '%"></div></div><div style="font-size:11.5px;color:var(--ink-3);margin-top:4px;text-align:right">' + pct + '%</div>';
    }

    function renderTeam() {
      var panel = document.getElementById('dc-panel');
      if (!panel) return;
      if (dcState.inviteData) { renderTeamContent(panel, dcState.inviteData); return; }
      panel.innerHTML = '<p style="color:var(--ink-3)">Loading team&hellip;</p>';
      api('/api/qual/get-invites.php?project_id=' + BOOT.projectId)
        .then(function (d) { dcState.inviteData = d; renderTeamContent(panel, d); })
        .catch(function (e) { panel.innerHTML = '<p style="color:#c0392b">Could not load: ' + esc(e.message) + '</p>'; });
    }

    function renderTeamContent(panel, d) {
      var total = d.total_segments || 0;
      var lead  = d.lead || {};
      var invites = d.invites || [];
      var activeInvite = invites.find(function (i) { return i.status === 'pending' || i.status === 'accepted'; });
      var leadPct = total > 0 ? Math.round((lead.coded || 0) / total * 100) : 0;

      var coderCards = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:22px">'
        + '<div class="ov-card"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--acc);margin-bottom:4px">Lead coder (you)</div>'
        + '<div style="font-size:15px;font-weight:700;margin-bottom:6px">' + esc(lead.name || 'You') + '</div>'
        + '<div style="font-size:13px;color:var(--ink-3);margin-bottom:8px">' + (lead.coded || 0) + ' of ' + total + ' segments coded</div>'
        + _progressBar(leadPct, 'var(--acc)') + '</div>';

      if (activeInvite && activeInvite.status === 'accepted') {
        var scPct = total > 0 ? Math.round((activeInvite.coded || 0) / total * 100) : 0;
        coderCards += '<div class="ov-card"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#0A6FE8;margin-bottom:4px">Second coder</div>'
          + '<div style="font-size:15px;font-weight:700;margin-bottom:6px">' + esc(activeInvite.coder_name || activeInvite.email) + '</div>'
          + '<div style="font-size:13px;color:var(--ink-3);margin-bottom:8px">' + (activeInvite.coded || 0) + ' of ' + total + ' segments coded</div>'
          + _progressBar(scPct, '#0A6FE8') + '</div>';
      } else {
        coderCards += '<div class="ov-card" style="border-style:dashed;text-align:center;color:var(--ink-3)">'
          + '<div style="font-size:28px;margin-bottom:8px">+</div>'
          + '<div style="font-size:14px;font-weight:600;margin-bottom:4px">Second coder</div>'
          + '<div style="font-size:13px">Not yet assigned</div></div>';
      }
      coderCards += '</div>';

      var inviteSection = '';
      if (activeInvite) {
        var badgeColor = activeInvite.status === 'accepted' ? '#1f9e44' : '#d97706';
        var badgeBg    = activeInvite.status === 'accepted' ? '#e9f7ee' : '#fff8ee';
        inviteSection = '<div class="ov-card" style="margin-bottom:18px">'
          + '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px">'
          + '<span style="font-weight:700">' + esc(activeInvite.email) + '</span>'
          + '<span style="font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:999px;background:' + badgeBg + ';color:' + badgeColor + '">' + esc(activeInvite.status) + '</span>'
          + '</div>';
        if (activeInvite.status === 'pending') {
          inviteSection += '<div style="font-size:13px;color:var(--ink-3);margin-bottom:10px">Share this link with the coder. They must log in to ReliCheck to accept it.</div>'
            + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
            + '<input id="inv-link" value="' + esc(activeInvite.invite_url) + '" readonly style="flex:1;padding:8px 12px;font-size:12.5px;border:1.5px solid var(--line);border-radius:8px;background:var(--bg);font-family:monospace;min-width:0">'
            + '<button class="btn" id="dc-copy-btn" data-url="' + esc(activeInvite.invite_url) + '">Copy link</button>'
            + '</div>';
        }
        inviteSection += '<div style="display:flex;gap:8px;margin-top:14px">'
          + '<button class="btn" id="dc-revoke-btn" style="background:none;color:var(--ink-2);border:1px solid var(--line)" data-invite="' + activeInvite.id + '">Revoke invite</button>'
          + (activeInvite.status === 'pending' ? '' : '<button class="btn" id="dc-review-btn">View disagreements &rarr;</button>')
          + '</div></div>';
      } else {
        inviteSection = '<div class="ov-card" style="margin-bottom:18px">'
          + '<div class="ov-card-h">Invite a second coder</div>'
          + '<p style="font-size:13px;color:var(--ink-2);margin:0 0 14px">The second coder will receive a link giving them access to code this project\'s segments using the same codebook.</p>'
          + '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">'
          + '<div style="flex:1;min-width:200px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Email address</label>'
          + '<input id="dc-email" type="email" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" placeholder="colleague@example.com"></div>'
          + '<button class="btn" id="dc-invite-btn">Generate invite link</button>'
          + '</div><div id="dc-invite-result" style="margin-top:14px"></div></div>';
      }

      panel.innerHTML = coderCards + inviteSection;

      var copyBtn = document.getElementById('dc-copy-btn');
      if (copyBtn) copyBtn.addEventListener('click', function () {
        navigator.clipboard.writeText(this.dataset.url).catch(function () {});
        copyBtn.textContent = 'Copied!'; setTimeout(function () { copyBtn.textContent = 'Copy link'; }, 2000);
      });

      var revokeBtn = document.getElementById('dc-revoke-btn');
      if (revokeBtn) revokeBtn.addEventListener('click', function () {
        if (!confirm('Revoke this invite? The second coder will lose access.')) return;
        revokeBtn.disabled = true;
        api('/api/qual/revoke-invite.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, invite_id: +this.dataset.invite }) })
          .then(function () { dcState.inviteData = null; renderTeam(); })
          .catch(function (e) { alert('Error: ' + e.message); revokeBtn.disabled = false; });
      });

      var reviewBtn = document.getElementById('dc-review-btn');
      if (reviewBtn) reviewBtn.addEventListener('click', function () { dcState.tab = 'review'; renderPage(); });

      var inviteBtn = document.getElementById('dc-invite-btn');
      if (inviteBtn) inviteBtn.addEventListener('click', function () {
        var email = (document.getElementById('dc-email').value || '').trim();
        if (!email) { alert('Enter an email address.'); return; }
        inviteBtn.disabled = true; inviteBtn.textContent = 'Generating...';
        var result = document.getElementById('dc-invite-result');
        api('/api/qual/invite-coder.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, email: email }) })
          .then(function (r) {
            if (result) result.innerHTML = '<div style="padding:12px 14px;background:var(--acc-soft);border-radius:10px">'
              + '<div style="font-weight:700;margin-bottom:6px">Invite link generated</div>'
              + '<div style="display:flex;gap:8px;align-items:center">'
              + '<input id="new-inv-link" value="' + esc(r.invite_url) + '" readonly style="flex:1;padding:8px 12px;font-size:12px;border:1.5px solid var(--acc);border-radius:8px;background:#fff;font-family:monospace;min-width:0">'
              + '<button class="btn" id="new-copy-btn" data-url="' + esc(r.invite_url) + '">Copy</button></div></div>';
            var cb = document.getElementById('new-copy-btn');
            if (cb) cb.addEventListener('click', function () { navigator.clipboard.writeText(this.dataset.url).catch(function(){}); cb.textContent='Copied!'; setTimeout(function(){cb.textContent='Copy';},2000); });
            inviteBtn.disabled = false; inviteBtn.textContent = 'Generate invite link';
            setTimeout(function () { dcState.inviteData = null; renderTeam(); }, 1200);
          }).catch(function (e) {
            if (result) result.innerHTML = '<p style="color:#c0392b;font-size:13px">' + esc(e.message) + '</p>';
            inviteBtn.disabled = false; inviteBtn.textContent = 'Generate invite link';
          });
      });
    }

    function renderReview() {
      var panel = document.getElementById('dc-panel');
      if (!panel) return;
      if (dcState.disagData) { renderReviewContent(panel, dcState.disagData); return; }
      panel.innerHTML = '<p style="color:var(--ink-3)">Comparing coder decisions&hellip;</p>';
      api('/api/qual/get-disagreements.php?project_id=' + BOOT.projectId)
        .then(function (d) { dcState.disagData = d; renderReviewContent(panel, d); })
        .catch(function (e) { panel.innerHTML = '<p style="color:#c0392b">Could not load: ' + esc(e.message) + '</p>'; });
    }

    function renderReviewContent(panel, d) {
      if (!d.ready) { panel.innerHTML = '<div style="text-align:center;padding:40px;color:var(--ink-3)"><strong>No second coder yet</strong><br><span style="font-size:13px">' + esc(d.reason || 'Invite a second coder in the Team tab.') + '</span></div>'; return; }
      var stats = d.stats || {}, coders = d.coders || {}, lead = coders.lead || {}, second = coders.second || {}, segs = d.segments || [];
      var pct = stats.agreement_pct, pctColor = pct === null ? 'var(--ink-3)' : pct >= 75 ? '#1f9e44' : pct >= 60 ? '#d97706' : '#c0392b';
      var html = '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px">'
        + _statCard(stats.both_coded || 0, 'Both coded') + _statCard(stats.agreements || 0, 'Agreements') + _statCard(stats.disagreements || 0, 'Disagreements')
        + '<div style="background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px 18px;min-width:110px;text-align:center"><div style="font-size:24px;font-weight:800;color:' + pctColor + '">' + (pct !== null ? pct + '%' : '—') + '</div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);margin-top:4px">Agreement rate</div></div>'
        + '</div>';
      if (!segs.length) { html += '<div style="color:var(--ink-3);padding:24px;text-align:center">No segments coded by both coders yet.</div>'; panel.innerHTML = html; return; }
      html += '<div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">'
        + ['disagree','agree','all'].map(function (f) { return '<button class="dc-rf-btn" data-filter="' + f + '" style="padding:6px 14px;border-radius:8px;border:1px solid var(--line);background:' + (dcState.reviewFilter===f?'var(--acc)':'var(--panel)') + ';color:' + (dcState.reviewFilter===f?'#fff':'var(--ink-2)') + ';font:inherit;font-size:12.5px;cursor:pointer">' + (f==='disagree'?'Disagreements ('+stats.disagreements+')':f==='agree'?'Agreements ('+stats.agreements+')':'All ('+segs.length+')') + '</button>'; }).join('')
        + '</div><div style="max-height:480px;overflow-y:auto">';
      var filtered = segs.filter(function(s){ return dcState.reviewFilter==='disagree'?!s.is_agreement:dcState.reviewFilter==='agree'?s.is_agreement:true; });
      html += filtered.map(function (s) {
        var meta = s.metadata_json || {}, metaItems = Object.keys(meta).slice(0,3).map(function(k){return '<span style="font-size:11px;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:1px 7px;color:var(--ink-3)">'+esc(k)+': '+esc(String(meta[k]))+'</span>';}).join('');
        var badge = s.is_agreement ? '<span style="font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;background:var(--green-soft);color:var(--green)">Agreement</span>' : '<span style="font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;background:#fff8ee;color:#b45309">Disagreement</span>';
        return '<div style="border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:10px">'
          + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">' + (s.participant_id?'<span style="font-size:11px;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:1px 7px;color:var(--ink-3)">ID: '+esc(s.participant_id)+'</span>':'') + metaItems + badge + '</div>'
          + '<div style="font-size:13.5px;line-height:1.6;margin-bottom:10px">' + esc(s.text) + '</div>'
          + '<div style="display:flex;flex-direction:column;gap:6px">'
          + '<div style="display:flex;gap:8px;flex-wrap:wrap"><span style="font-size:11.5px;font-weight:700;color:var(--ink-3);width:70px;flex-shrink:0">Lead</span><div>' + ((s.agreed||[]).concat(s.only_lead||[]).map(function(c){return '<span style="font-size:12px;padding:2px 8px;border-radius:8px;background:var(--acc-soft);color:var(--acc-deep);font-weight:600;margin-right:4px">'+esc(c.name)+'</span>';}).join('')||'<span style="font-size:12.5px;color:var(--ink-3);font-style:italic">No codes</span>') + '</div></div>'
          + '<div style="display:flex;gap:8px;flex-wrap:wrap"><span style="font-size:11.5px;font-weight:700;color:#085fcc;width:70px;flex-shrink:0">Second</span><div>' + ((s.agreed||[]).concat(s.only_second||[]).map(function(c){return '<span style="font-size:12px;padding:2px 8px;border-radius:8px;background:#eef3fa;color:#085fcc;font-weight:600;margin-right:4px">'+esc(c.name)+'</span>';}).join('')||'<span style="font-size:12.5px;color:var(--ink-3);font-style:italic">No codes</span>') + '</div></div>'
          + '</div></div>';
      }).join('') + '</div>';
      panel.innerHTML = html;
      panel.querySelectorAll('.dc-rf-btn').forEach(function (btn) { btn.addEventListener('click', function () { dcState.reviewFilter = btn.dataset.filter; renderReviewContent(panel, d); }); });
    }

    host.innerHTML = '<div style="color:var(--ink-3);padding:24px;text-align:center">Loading&hellip;</div>';
    renderPage();
  }

  // ── Category Builder ──────────────────────────────────────────────────────
  function renderCategories(host) {
    var catState = { categories: [], unassigned: [] };

    function load() {
      return api('/api/qual/get-categories.php?project_id=' + BOOT.projectId)
        .then(function (d) { catState.categories = d.categories || []; catState.unassigned = d.unassigned || []; renderPage(); });
    }

    function renderPage() {
      var hasCategories = catState.categories.length > 0;

      var unassignedHtml = '';
      if (catState.unassigned.length) {
        var items = catState.unassigned.map(function (c) {
          var catOpts = catState.categories.map(function (cat) { return '<option value="' + cat.id + '">' + esc(cat.name) + '</option>'; }).join('');
          return '<div style="display:flex;align-items:center;gap:8px;padding:7px 10px;border-bottom:1px solid var(--line-2)" id="ucode-' + c.id + '">'
            + '<span style="font-size:12.5px;padding:2px 10px;border-radius:8px;background:var(--bg);border:1px solid var(--line);color:var(--ink-2)">' + esc(c.name) + '</span>'
            + (c.application_count > 0 ? '<span style="font-size:11.5px;color:var(--ink-3)">' + c.application_count + ' applied</span>' : '')
            + (catState.categories.length
              ? '<select class="cat-assign-sel" data-code="' + c.id + '" style="font-size:12px;padding:4px 8px;border:1px solid var(--line);border-radius:8px;margin-left:auto"><option value="">Assign to...</option>' + catOpts + '</select>'
              : '<span style="font-size:12px;color:var(--ink-3);margin-left:auto">Create a category first</span>')
            + '</div>';
        }).join('');
        unassignedHtml = '<div class="ov-card" style="margin-bottom:20px"><div class="ov-card-h">Unassigned codes (' + catState.unassigned.length + ')</div>'
          + '<div style="max-height:280px;overflow-y:auto">' + items + '</div></div>';
      } else if (!hasCategories) {
        unassignedHtml = '<p style="color:var(--ink-3);margin-bottom:20px">No codes in the codebook yet. Build your codebook before grouping codes into categories.</p>';
      }

      var catsHtml = catState.categories.map(function (cat) {
        var codeChips = cat.codes.length
          ? cat.codes.map(function (c) { return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;padding:2px 8px;border-radius:8px;background:var(--acc-soft);color:var(--acc-deep);font-weight:600;margin-right:4px">' + esc(c.name) + '<button style="border:none;background:none;cursor:pointer;color:var(--acc-deep);font-size:13px;line-height:1;padding:0" class="cat-unassign" data-code="' + c.id + '">&times;</button></span>'; }).join('')
          : '<span style="font-size:12.5px;color:var(--ink-3);font-style:italic">No codes assigned</span>';
        return '<div class="ov-card" id="cat-' + cat.id + '" style="margin-bottom:12px">'
          + '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:10px">'
          + '<div><div style="font-size:14px;font-weight:700">' + esc(cat.name) + '</div>'
          + (cat.description ? '<div style="font-size:13px;color:var(--ink-3);margin-top:2px">' + esc(cat.description) + '</div>' : '') + '</div>'
          + '<button class="btn" style="font-size:12px;padding:4px 10px;flex-shrink:0" data-edit-cat="' + cat.id + '">Edit</button></div>'
          + '<div>' + codeChips + '</div></div>';
      }).join('');

      host.innerHTML = '<div class="ws-header"><div class="ws-eyebrow">Step 10</div><h1 class="ws-title">Category Builder</h1>'
        + '<p class="ws-lede">Group related codes into categories. Categories become the building blocks of themes.</p></div>'
        + unassignedHtml
        + '<div style="margin-bottom:20px"><div style="font-size:15px;font-weight:700;margin:0 0 12px">Categories</div>'
        + '<div id="cat-list">' + (hasCategories ? catsHtml : '') + '</div></div>'
        + '<div class="ov-card" id="cat-form-panel"><div class="ov-card-h" id="cat-form-title">Add a category</div>'
        + '<div style="margin-top:8px">'
        + '<div style="margin-bottom:12px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Category name <span style="color:#c0392b">*</span></label><input id="cat-name" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" placeholder="e.g. Communication Barriers"></div>'
        + '<div style="margin-bottom:12px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Description <span style="font-weight:400;color:var(--ink-3)">(optional)</span></label><input id="cat-desc" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" placeholder="What kind of codes belong here?"></div>'
        + '<input type="hidden" id="cat-editing-id">'
        + '<div id="cat-msg" style="display:none;font-size:13px;margin-bottom:8px"></div>'
        + '<div style="display:flex;gap:8px"><button class="btn" id="cat-save">Add category</button><button class="btn" id="cat-cancel" style="display:none;background:none;color:var(--ink-2);border:1px solid var(--line)">Cancel</button></div>'
        + '</div></div>'
        + '<div style="margin-top:12px"><button class="btn" id="cat-to-themes">Next: Build themes &rarr;</button></div>';

      function clearCatForm() {
        document.getElementById('cat-editing-id').value = '';
        document.getElementById('cat-name').value = '';
        document.getElementById('cat-desc').value = '';
        document.getElementById('cat-form-title').textContent = 'Add a category';
        document.getElementById('cat-save').textContent = 'Add category';
        document.getElementById('cat-cancel').style.display = 'none';
      }

      document.getElementById('cat-save').addEventListener('click', function () {
        var msg = document.getElementById('cat-msg');
        var name = (document.getElementById('cat-name').value || '').trim();
        var desc = (document.getElementById('cat-desc').value || '').trim();
        var editId = +(document.getElementById('cat-editing-id').value) || 0;
        if (!name) { msg.textContent = 'Name is required.'; msg.style.cssText = 'display:block;color:#c0392b;'; return; }
        msg.textContent = 'Saving...'; msg.style.cssText = 'display:block;color:var(--ink-3);';
        api('/api/qual/save-category.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, id: editId || undefined, name: name, description: desc }) })
          .then(function () { return load(); }).then(function () { clearCatForm(); })
          .catch(function (e) { msg.textContent = 'Error: ' + e.message; msg.style.cssText = 'display:block;color:#c0392b;'; });
      });
      document.getElementById('cat-cancel').addEventListener('click', clearCatForm);
      document.getElementById('cat-to-themes').addEventListener('click', function () { activateStep('themes'); });

      host.addEventListener('click', function (e) {
        var editBtn = e.target.closest('[data-edit-cat]');
        if (editBtn) {
          var cat = catState.categories.find(function (c) { return c.id == editBtn.dataset.editCat; });
          if (!cat) return;
          document.getElementById('cat-editing-id').value = cat.id;
          document.getElementById('cat-name').value = cat.name;
          document.getElementById('cat-desc').value = cat.description || '';
          document.getElementById('cat-form-title').textContent = 'Edit: ' + cat.name;
          document.getElementById('cat-save').textContent = 'Save changes';
          document.getElementById('cat-cancel').style.display = 'inline-flex';
          document.getElementById('cat-form-panel').scrollIntoView({ behavior: 'smooth' });
        }
        var unassignBtn = e.target.closest('.cat-unassign');
        if (unassignBtn) {
          api('/api/qual/assign-code-category.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, code_id: +unassignBtn.dataset.code, category_id: 0 }) })
            .then(load).catch(function (ex) { alert('Error: ' + ex.message); });
        }
      });
      host.addEventListener('change', function (e) {
        var sel = e.target.closest('.cat-assign-sel');
        if (!sel || !sel.value) return;
        sel.disabled = true;
        api('/api/qual/assign-code-category.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, code_id: +sel.dataset.code, category_id: +sel.value }) })
          .then(load).catch(function (ex) { sel.disabled = false; alert('Error: ' + ex.message); });
      });
    }

    host.innerHTML = '<p style="color:var(--ink-3);padding:24px;text-align:center">Loading categories...</p>';
    load();
  }

  // ── Theme Builder ─────────────────────────────────────────────────────────
  function renderThemes(host) {
    var tState = { themes: [], allCategories: [] };

    function load() {
      return api('/api/qual/get-themes.php?project_id=' + BOOT.projectId)
        .then(function (d) { tState.themes = d.themes || []; tState.allCategories = d.all_categories || []; renderPage(); });
    }

    function renderPage() {
      var noCategories = !tState.allCategories.length;
      var themeCards = tState.themes.map(function (t) {
        var catTags = t.categories.length
          ? t.categories.map(function (c) { return '<span style="font-size:12px;padding:2px 8px;border-radius:8px;background:var(--acc-soft);color:var(--acc-deep);font-weight:600;margin-right:4px">' + esc(c.name) + '</span>'; }).join('')
          : '<span style="font-size:12.5px;color:var(--ink-3);font-style:italic">No categories linked</span>';
        var availCats = tState.allCategories.map(function (cat) {
          var linked = t.categories.some(function (tc) { return tc.id == cat.id; });
          return '<label style="display:flex;align-items:center;gap:8px;font-size:13px;padding:4px 0;cursor:pointer">'
            + '<input type="checkbox" class="tc-check" data-theme="' + t.id + '" data-cat="' + cat.id + '"' + (linked ? ' checked' : '') + '> '
            + esc(cat.name) + '</label>';
        }).join('');
        return '<div class="ov-card" id="theme-' + t.id + '" style="margin-bottom:12px">'
          + '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:10px">'
          + '<div style="font-size:15px;font-weight:700">' + esc(t.name) + '</div>'
          + '<button class="btn" style="font-size:12px;padding:4px 10px;flex-shrink:0" data-edit-theme="' + t.id + '">Edit</button></div>'
          + '<div style="padding:12px 14px;background:var(--acc-soft);border-radius:10px;margin-bottom:10px">'
          + '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--acc-deep);margin-bottom:4px">Finding</div>'
          + '<div style="font-size:14px;color:var(--acc-deep);line-height:1.55;font-style:italic">&ldquo;' + esc(t.interpretive_claim) + '&rdquo;</div></div>'
          + '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:6px">Supporting categories</div>'
          + '<div style="margin-bottom:10px">' + catTags + '</div>'
          + (tState.allCategories.length
            ? '<details style="font-size:13px"><summary style="cursor:pointer;color:var(--acc);font-weight:600">Link categories&hellip;</summary><div style="margin-top:10px;display:flex;flex-direction:column;gap:4px">' + availCats + '</div></details>'
            : '') + '</div>';
      }).join('');

      host.innerHTML = '<div class="ws-header"><div class="ws-eyebrow">Step 11</div><h1 class="ws-title">Theme Builder</h1>'
        + '<p class="ws-lede">Themes are interpretive claims, not topic labels. Each theme answers a question about participants\' experience.</p></div>'
        + (noCategories ? '<div style="padding:12px 14px;background:#fff8ee;border-radius:10px;color:#92400e;font-size:13px;margin-bottom:18px">No categories yet. Go to <strong>Category Builder</strong> first.</div>' : '')
        + (tState.themes.length ? '<div id="theme-list" style="margin-bottom:20px">' + themeCards + '</div>' : '<div id="theme-list"></div>')
        + '<div class="ov-card" id="theme-form-panel"><div class="ov-card-h" id="theme-form-title">Add a theme</div>'
        + '<div style="margin-top:8px">'
        + '<div style="margin-bottom:12px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Theme name <span style="color:#c0392b">*</span></label><input id="th-name" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" placeholder="e.g. Systemic barriers to participation"></div>'
        + '<div style="margin-bottom:12px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px">Interpretive claim <span style="color:#c0392b">*</span><span style="display:block;font-weight:400;color:var(--ink-3)">State a finding, not a label.</span></label><textarea id="th-claim" style="width:100%;min-height:90px;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px;resize:vertical" placeholder="Participants described feeling excluded from decision-making processes..."></textarea></div>'
        + '<div style="margin-bottom:12px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px">Notes <span style="font-weight:400;color:var(--ink-3)">(optional)</span></label><textarea id="th-notes" style="width:100%;min-height:60px;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px;resize:vertical" placeholder="Consider whether this theme overlaps with..."></textarea></div>'
        + ContextualLens.panel('theme', null, 'th_cl_')
        + '<input type="hidden" id="th-editing-id">'
        + '<div id="th-msg" style="display:none;font-size:13px;margin-bottom:8px;margin-top:12px"></div>'
        + '<div style="display:flex;gap:8px;margin-top:12px"><button class="btn" id="th-save">Add theme</button><button class="btn" id="th-cancel" style="display:none;background:none;color:var(--ink-2);border:1px solid var(--line)">Cancel</button></div>'
        + '</div></div>';

      function clearThemeForm() {
        document.getElementById('th-editing-id').value = '';
        document.getElementById('th-name').value = '';
        document.getElementById('th-claim').value = '';
        document.getElementById('th-notes').value = '';
        ContextualLens.populate('theme', {}, 'th_cl_');
        document.getElementById('theme-form-title').textContent = 'Add a theme';
        document.getElementById('th-save').textContent = 'Add theme';
        document.getElementById('th-cancel').style.display = 'none';
      }

      document.getElementById('th-save').addEventListener('click', function () {
        var msg = document.getElementById('th-msg');
        var name = (document.getElementById('th-name').value || '').trim();
        var claim = (document.getElementById('th-claim').value || '').trim();
        var notes = (document.getElementById('th-notes').value || '').trim();
        var editId = +(document.getElementById('th-editing-id').value) || 0;
        if (!name)  { var nameEl = document.getElementById('th-name'); nameEl.style.borderColor = '#c0392b'; nameEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); nameEl.focus(); msg.textContent = ''; return; }
        document.getElementById('th-name').style.borderColor = '';
        if (!claim) { msg.textContent = 'An interpretive claim is required.'; msg.style.cssText = 'display:block;color:#c0392b;'; return; }
        msg.textContent = 'Saving...'; msg.style.cssText = 'display:block;color:var(--ink-3);';
        var themeBody = { project_id: BOOT.projectId, id: editId || undefined, name: name, interpretive_claim: claim, notes: notes };
        Object.assign(themeBody, ContextualLens.gather('theme', 'th_cl_'));
        api('/api/qual/save-theme.php', { method: 'POST', body: JSON.stringify(themeBody) })
          .then(function () { return load(); }).then(function () { clearThemeForm(); })
          .catch(function (e) { msg.textContent = 'Error: ' + e.message; msg.style.cssText = 'display:block;color:#c0392b;'; });
      });
      document.getElementById('th-cancel').addEventListener('click', clearThemeForm);

      host.addEventListener('click', function (e) {
        var editBtn = e.target.closest('[data-edit-theme]');
        if (editBtn) {
          var theme = tState.themes.find(function (t) { return t.id == editBtn.dataset.editTheme; });
          if (!theme) return;
          document.getElementById('th-editing-id').value = theme.id;
          document.getElementById('th-name').value  = theme.name;
          document.getElementById('th-claim').value = theme.interpretive_claim || '';
          document.getElementById('th-notes').value = theme.notes || '';
          ContextualLens.populate('theme', theme, 'th_cl_');
          document.getElementById('theme-form-title').textContent = 'Edit: ' + theme.name;
          document.getElementById('th-save').textContent = 'Save changes';
          document.getElementById('th-cancel').style.display = 'inline-flex';
          document.getElementById('theme-form-panel').scrollIntoView({ behavior: 'smooth' });
        }
      });
      host.addEventListener('change', function (e) {
        var cb = e.target.closest('.tc-check');
        if (!cb) return;
        cb.disabled = true;
        api('/api/qual/link-theme-category.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, theme_id: +cb.dataset.theme, category_id: +cb.dataset.cat, action: cb.checked ? 'add' : 'remove' }) })
          .then(load).catch(function (ex) { cb.disabled = false; alert('Error: ' + ex.message); });
      });
    }

    host.innerHTML = '<p style="color:var(--ink-3);padding:24px;text-align:center">Loading themes...</p>';
    load();
  }

  // ── Quote Finder ──────────────────────────────────────────────────────────
  function renderQuotes(host) {
    var qState = { themes: [], theme: null, segments: [], pinned: [], pinnedIds: [] };

    function load(themeId) {
      var qs = '/api/qual/get-quotes.php?project_id=' + BOOT.projectId;
      if (themeId) qs += '&theme_id=' + themeId;
      return api(qs).then(function (d) {
        qState.themes = d.themes || []; qState.theme = d.theme || null;
        qState.segments = d.segments || []; qState.pinned = d.pinned || []; qState.pinnedIds = d.pinned_ids || [];
        renderPage();
      });
    }

    function renderPage() {
      if (!qState.themes.length) {
        host.innerHTML = '<div class="ws-header"><div class="ws-eyebrow">Step 12</div><h1 class="ws-title">Quote Finder</h1></div>'
          + '<div style="padding:12px 14px;background:#fff8ee;border-radius:10px;color:#92400e;font-size:13px">No themes yet. Build themes in the <strong>Theme Builder</strong> step first.</div>';
        return;
      }

      var tabs = qState.themes.map(function (t) {
        var active = qState.theme && qState.theme.id == t.id;
        return '<button style="padding:8px 16px;border:none;border-bottom:2px solid ' + (active?'var(--acc)':'transparent') + ';background:none;cursor:pointer;font:inherit;font-size:13.5px;font-weight:700;color:' + (active?'var(--acc-deep)':'var(--ink-3)') + ';margin-bottom:-2px" class="qf-tab" data-tid="' + t.id + '">' + esc(t.name) + '</button>';
      }).join('');

      var bodyHtml = '';
      if (!qState.theme) {
        bodyHtml = '<div style="color:var(--ink-3);padding:24px;text-align:center">Select a theme above to find exemplar quotes.</div>';
      } else {
        var t = qState.theme;
        bodyHtml += '<div style="padding:14px 18px;background:var(--acc-soft);border-radius:12px;margin-bottom:20px">'
          + '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--acc-deep);margin-bottom:4px">Finding</div>'
          + '<div style="font-size:14.5px;color:var(--acc-deep);line-height:1.55;font-style:italic">&ldquo;' + esc(t.interpretive_claim || '') + '&rdquo;</div></div>';

        var pinnedSegs = qState.segments.filter(function (s) { return qState.pinnedIds.indexOf(+s.id) !== -1; });
        if (pinnedSegs.length) {
          bodyHtml += '<div style="margin-bottom:24px"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--acc-deep);margin-bottom:10px">&#9733; Exemplar quotes (' + pinnedSegs.length + ')</div>'
            + '<div style="display:flex;flex-direction:column;gap:10px">' + pinnedSegs.map(function (s) { return renderSegCard(s, true); }).join('') + '</div></div>';
        }
        var unpinned = qState.segments.filter(function (s) { return qState.pinnedIds.indexOf(+s.id) === -1; });
        if (!qState.segments.length) {
          bodyHtml += '<div style="padding:12px 14px;background:#fff8ee;border-radius:10px;color:#92400e;font-size:13px">No coded segments linked to this theme yet.</div>';
        } else if (unpinned.length) {
          bodyHtml += '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);margin-bottom:10px">Linked segments — ' + unpinned.length + ' remaining</div>'
            + '<div style="max-height:520px;overflow-y:auto;display:flex;flex-direction:column;gap:10px">' + unpinned.map(function (s) { return renderSegCard(s, false); }).join('') + '</div>';
        } else {
          bodyHtml += '<div style="font-size:13.5px;color:var(--ink-3)">All linked segments are pinned as exemplars.</div>';
        }
      }

      host.innerHTML = '<div class="ws-header"><div class="ws-eyebrow">Step 12</div><h1 class="ws-title">Quote Finder</h1>'
        + '<p class="ws-lede">Pin the segments that best evidence each theme.</p></div>'
        + '<div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:20px" id="qf-tabs">' + tabs + '</div>'
        + '<div id="qf-body">' + bodyHtml + '</div>';

      document.getElementById('qf-tabs').addEventListener('click', function (e) {
        var btn = e.target.closest('.qf-tab');
        if (!btn) return;
        host.innerHTML = '<p style="color:var(--ink-3);padding:24px;text-align:center">Loading...</p>';
        load(+btn.dataset.tid).catch(function (ex) {
          host.innerHTML = '<p style="color:#c0392b;padding:24px">Could not load quotes: ' + esc(ex.message) + '</p>';
        });
      });
    }

    host.addEventListener('click', function (e) {
      var pinBtn = e.target.closest('.qf-pin-btn');
      if (!pinBtn || !qState.theme) return;
      pinBtn.disabled = true;
      api('/api/qual/save-quote.php', { method: 'POST', body: JSON.stringify({ project_id: BOOT.projectId, theme_id: qState.theme.id, segment_id: +pinBtn.dataset.seg, action: pinBtn.dataset.action }) })
        .then(function () { return load(qState.theme.id); })
        .catch(function (ex) { pinBtn.disabled = false; alert('Error: ' + ex.message); });
    });

    function renderSegCard(seg, isPinned) {
      var meta = seg.metadata_json || {};
      if (typeof meta === 'string') { try { meta = JSON.parse(meta); } catch (_) { meta = {}; } }
      var metaItems = Object.keys(meta).slice(0, 3).map(function (k) { return '<span style="font-size:11px;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:1px 7px;color:var(--ink-3)">' + esc(k) + ': ' + esc(String(meta[k])) + '</span>'; }).join('');
      var codeTags = (seg.theme_codes || []).map(function (c) { return '<span style="font-size:11.5px;padding:2px 9px;border-radius:8px;background:var(--acc-soft);color:var(--acc-deep);font-weight:600">' + esc(c.code_name) + ' &rarr; ' + esc(c.cat_name) + '</span>'; }).join('');
      var pinBtn = isPinned
        ? '<button class="qf-pin-btn" style="font-size:12px;padding:4px 12px;border:1px solid var(--acc);border-radius:8px;background:var(--acc-soft);color:var(--acc-deep);cursor:pointer;font-family:inherit" data-seg="' + seg.id + '" data-action="unpin">&#9733; Pinned &mdash; remove</button>'
        : '<button class="qf-pin-btn" style="font-size:12px;padding:4px 12px;border:1px solid var(--line);border-radius:8px;background:var(--panel);color:var(--ink-2);cursor:pointer;font-family:inherit" data-seg="' + seg.id + '" data-action="pin">&#9734; Pin as exemplar</button>';
      return '<div style="border:1px solid var(--line);border-radius:10px;padding:12px 14px' + (isPinned ? ';border-color:var(--acc);background:var(--acc-soft)' : '') + '">'
        + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">' + (seg.participant_id ? '<span style="font-size:11px;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:1px 7px;color:var(--ink-3)">ID: ' + esc(seg.participant_id) + '</span>' : '') + metaItems + '</div>'
        + '<div style="font-size:13.5px;line-height:1.65;margin-bottom:8px">' + esc(seg.cleaned_text || seg.raw_text) + '</div>'
        + (codeTags ? '<div style="margin-bottom:8px">' + codeTags + '</div>' : '')
        + '<div>' + pinBtn + '</div></div>';
    }

    host.innerHTML = '<p style="color:var(--ink-3);padding:24px;text-align:center">Loading quotes...</p>';
    api('/api/qual/get-quotes.php?project_id=' + BOOT.projectId).then(function (d) {
      if (d.themes && d.themes.length === 1) { return load(d.themes[0].id); }
      else { qState.themes = d.themes || []; qState.theme = null; renderPage(); }
    }).catch(function (e) { host.innerHTML = '<p style="color:#c0392b;padding:24px">' + esc(e.message) + '</p>'; });
  }

  // ── Trustworthiness ───────────────────────────────────────────────────────
  function renderTrustworthiness(host) {
    function load() {
      return api('/api/qual/get-trustworthiness.php?project_id=' + BOOT.projectId)
        .then(function (d) { renderPage(d); })
        .catch(function (e) { host.innerHTML = '<p style="color:#c0392b">Could not load: ' + esc(e.message) + '</p>'; });
    }

    function renderPage(d) {
      var html = '<div class="ws-header"><div class="ws-eyebrow">Step 13</div><h1 class="ws-title">Trustworthiness</h1>'
        + '<p class="ws-lede">Three practices that strengthen the credibility and transferability of qualitative findings.</p></div>';

      // 1. Researcher reflexivity
      var r = d.reflexivity || {};
      var approachLabel = { thematic:'Thematic Analysis', content:'Content Analysis', framework:'Framework Analysis', open_ended_survey:'Open-Ended Survey Analysis', document:'Document Analysis' }[r.analysis_approach] || r.analysis_approach || '—';
      html += '<div class="ov-card" style="margin-bottom:20px"><div class="ov-card-h">1 — Researcher reflexivity</div>'
        + '<p style="font-size:13px;color:var(--ink-2);margin:0 0 14px">Your recorded stance and research question ground the analysis and are part of the audit trail.</p>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">'
        + '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:4px">Analysis approach</div><div style="font-size:14px">' + esc(approachLabel) + '</div></div>'
        + '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:4px">Research question</div><div style="font-size:14px">' + (r.research_question ? esc(r.research_question) : '<em style="color:var(--ink-3)">Not yet set</em>') + '</div></div>'
        + '</div>'
        + (r.stance_memo
          ? '<div style="background:var(--acc-soft);border-radius:10px;padding:14px 16px"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--acc-deep);margin-bottom:6px">Researcher stance memo</div><div style="font-size:13.5px;line-height:1.6;color:var(--acc-deep)">' + esc(r.stance_memo).replace(/\n/g,'<br>') + '</div></div>'
          : '<div style="padding:10px 12px;background:#fff8ee;border-radius:8px;font-size:13px;color:#92400e">No researcher stance memo recorded. Add one in <button style="border:none;background:none;color:var(--acc);font-weight:700;cursor:pointer;font:inherit;font-size:13px;padding:0" onclick="activateStep(\'setup\')">Project Setup</button>.</div>')
        + '</div>';

      // 2. Coding agreement
      var ag = d.agreement || {};
      html += '<div class="ov-card" style="margin-bottom:20px"><div class="ov-card-h">2 — Coding agreement</div>';
      if (!ag.computable) {
        html += '<p style="font-size:13px;color:var(--ink-2);margin:0 0 12px">' + esc(ag.note || '') + '</p>'
          + '<div style="background:var(--line-2);border-radius:10px;padding:14px 16px;font-size:13px;color:var(--ink-2)">Cohen\'s kappa compares two coders\' decisions. With a single coder there is no inter-rater agreement to compute. Single-coder analysis is valid, especially with member checking.</div>';
      } else {
        var kappaColor = ag.kappa === null ? 'var(--ink-3)' : ag.kappa >= 0.6 ? '#1f9e44' : ag.kappa >= 0.4 ? '#d97706' : '#c0392b';
        html += '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px">'
          + '<div style="background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px 18px;text-align:center;min-width:120px"><div style="font-size:24px;font-weight:800;color:' + kappaColor + '">' + (ag.kappa !== null ? ag.kappa.toFixed(3) : '—') + '</div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);margin-top:4px">Cohen\'s kappa</div><div style="font-size:12px;color:' + kappaColor + ';margin-top:4px;font-weight:600">' + esc(ag.interpretation || '') + '</div></div>'
          + '<div style="background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px 18px;text-align:center;min-width:120px"><div style="font-size:24px;font-weight:800">' + (ag.percent_agreement !== null ? ag.percent_agreement + '%' : '—') + '</div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);margin-top:4px">Percent agreement</div><div style="font-size:12px;color:var(--ink-3);margin-top:4px">' + ag.shared_segments + ' shared segments</div></div>'
          + '</div>';
      }
      html += '</div>';

      // 3. Member checking
      var checks = d.member_checks || [];
      html += '<div class="ov-card"><div class="ov-card-h">3 — Member checking</div>'
        + '<p style="font-size:13px;color:var(--ink-2);margin:0 0 16px">Record when you shared findings with participants or peers.</p>';
      if (checks.length) {
        html += '<div style="max-height:280px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;margin-bottom:20px">'
          + checks.map(function (c) {
              var oc = c.outcome === 'Confirmed' ? '#1f9e44' : c.outcome === 'Revised' ? '#d97706' : '#0A6FE8';
              return '<div style="border:1px solid var(--line);border-radius:10px;padding:12px 14px">'
                + '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">'
                + '<span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;background:' + oc + '22;color:' + oc + '">' + esc(c.outcome || '') + '</span>'
                + '<span style="font-size:12px;color:var(--ink-3)">' + esc(c.date || '') + (c.who ? ' · ' + esc(c.who) : '') + '</span></div>'
                + '<div style="font-size:13.5px;margin-bottom:' + (c.notes?'8':'0') + 'px">' + esc(c.finding) + '</div>'
                + (c.notes ? '<div style="font-size:12.5px;color:var(--ink-2)">' + esc(c.notes).replace(/\n/g,'<br>') + '</div>' : '') + '</div>';
            }).join('') + '</div>';
      }
      html += '<div style="border:1px solid var(--line);border-radius:12px;padding:16px" id="mc-form">'
        + '<div style="font-size:13px;font-weight:600;margin-bottom:14px">Record a member check</div>'
        + '<div style="margin-bottom:12px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Finding or claim you shared <span style="color:#c0392b">*</span></label><textarea id="mc-finding" style="width:100%;min-height:60px;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px;resize:vertical" rows="2" placeholder="e.g. Participants felt excluded from..."></textarea></div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">'
        + '<div><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Shared with</label><input id="mc-who" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px" placeholder="e.g. 3 participants, supervisor"></div>'
        + '<div><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Method</label><select id="mc-method" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px"><option value="">Select...</option>' + ['Email summary','Interview review','Focus group','Peer review','Other'].map(function(m){return '<option>'+m+'</option>';}).join('') + '</select></div></div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">'
        + '<div><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Date</label><input id="mc-date" type="date" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px"></div>'
        + '<div><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Outcome</label><select id="mc-outcome" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px">' + ['Confirmed','Revised','Mixed'].map(function(o){return '<option>'+o+'</option>';}).join('') + '</select></div></div>'
        + '<div style="margin-bottom:12px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px">Notes</label><textarea id="mc-notes" style="width:100%;min-height:60px;padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13px;resize:vertical" rows="2" placeholder="What did they confirm, challenge, or add?"></textarea></div>'
        + '<button class="btn" id="mc-save-btn">Save member check</button>'
        + '</div></div>';

      host.innerHTML = html;

      document.getElementById('mc-save-btn').addEventListener('click', function () {
        var finding = (document.getElementById('mc-finding').value || '').trim();
        if (!finding) { alert('Finding is required.'); return; }
        var btn = document.getElementById('mc-save-btn');
        btn.disabled = true; btn.textContent = 'Saving...';
        api('/api/qual/save-member-check.php', { method: 'POST', body: JSON.stringify({
          project_id: BOOT.projectId,
          check: {
            finding: finding,
            who:     (document.getElementById('mc-who').value || '').trim(),
            method:  (document.getElementById('mc-method').value || '').trim(),
            date:    (document.getElementById('mc-date').value || '').trim(),
            outcome: (document.getElementById('mc-outcome').value || 'Confirmed'),
            notes:   (document.getElementById('mc-notes').value || '').trim(),
          },
        }) }).then(function (r) {
          if (r.ok) { host.innerHTML = '<p style="color:var(--ink-3);padding:24px;text-align:center">Reloading...</p>'; return load(); }
          btn.disabled = false; btn.textContent = 'Save member check';
        }).catch(function (e) { btn.disabled = false; btn.textContent = 'Save member check'; alert('Could not save: ' + e.message); });
      });
    }

    host.innerHTML = '<p style="color:var(--ink-3);padding:24px;text-align:center">Loading...</p>';
    load();
  }

  // ── Report / Export ───────────────────────────────────────────────────────
  function renderReport(host) {
    host.innerHTML = '<div class="ws-header"><div class="ws-eyebrow">Step 14</div><h1 class="ws-title">Report / Export</h1>'
      + '<p class="ws-lede">A structured summary of your analysis, ready to share or print.</p></div>'
      + '<p style="color:var(--ink-3)">Loading report data&hellip;</p>';

    api('/api/qual/build-report.php?project_id=' + BOOT.projectId)
      .then(function (d) { renderReportContent(host, d); })
      .catch(function (e) { host.innerHTML = '<div class="ws-header"><div class="ws-eyebrow">Step 14</div><h1 class="ws-title">Report / Export</h1></div><p style="color:#c0392b">Could not load report data: ' + esc(e.message) + '</p>'; });
  }

  function renderReportContent(host, d) {
    var p = d.project || {}, stats = d.stats || {}, themes = d.themes || [], checks = d.member_checks || [];
    var approachLabels = { thematic:'Thematic Analysis', content:'Content Analysis', framework:'Framework Analysis', open_ended_survey:'Open-Ended Survey Analysis', document:'Document Analysis' };
    var approach = approachLabels[p.analysis_approach] || (p.analysis_approach || '');

    var topBar = '<div style="display:flex;gap:10px;margin-bottom:24px">'
      + '<button class="btn" id="rep-print">Print / Save as PDF</button>'
      + '<a class="btn" style="background:none;color:var(--ink-2);border:1px solid var(--line);text-decoration:none" href="/api/qual/export-coded.php?project_id=' + BOOT.projectId + '">Download coded segments (.csv)</a>'
      + '<button class="btn" id="rep-json" style="background:none;color:var(--ink-2);border:1px solid var(--line)">Download themes (.json)</button>'
      + '</div>';

    var header = '<div class="ov-card" id="rep-header">'
      + '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);margin-bottom:6px">Qualitative Analysis Report</div>'
      + '<h2 style="margin:0 0 6px;font-size:24px;font-weight:700">' + esc(p.title || 'Untitled Project') + '</h2>'
      + (approach ? '<div style="font-size:13.5px;color:var(--ink-2);margin-bottom:16px">' + esc(approach) + '</div>' : '')
      + (p.research_question ? '<div style="background:var(--acc-soft);border-radius:10px;padding:14px 16px;margin-bottom:16px"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--acc-deep);margin-bottom:4px">Research question</div><div style="font-size:14.5px;color:var(--acc-deep);line-height:1.55">' + esc(p.research_question) + '</div></div>' : '')
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
      + (p.participant_description ? '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:3px">Participants</div><div style="font-size:13.5px">' + esc(p.participant_description) + '</div></div>' : '')
      + (p.purpose ? '<div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:3px">Purpose</div><div style="font-size:13.5px">' + esc(p.purpose) + '</div></div>' : '')
      + '</div></div>';

    var summary = '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px">'
      + _statCard(stats.seg_count, 'Segments analyzed') + _statCard(stats.total_words, 'Total words')
      + _statCard(stats.code_count, 'Codes in codebook') + _statCard(stats.theme_count, 'Themes built')
      + '</div>';

    var themeSection = '<div style="margin-bottom:24px"><h3 style="font-size:18px;font-weight:700;margin:0 0 14px;border-bottom:2px solid var(--acc-soft);padding-bottom:10px">Themes</h3>';
    if (!themes.length) {
      themeSection += '<p style="color:var(--ink-3)">No themes built yet. Complete the Theme Builder step first.</p>';
    } else {
      themeSection += themes.map(function (t, i) {
        var catChips = t.categories && t.categories.length
          ? t.categories.map(function (c) { return '<span style="font-size:12px;padding:2px 10px;border-radius:8px;background:var(--bg);color:var(--ink-2);border:1px solid var(--line);margin-right:4px">' + esc(c) + '</span>'; }).join('')
          : '<span style="font-size:12.5px;color:var(--ink-3);font-style:italic">No categories linked</span>';
        var quotesHtml = '';
        if (t.quotes && t.quotes.length) {
          quotesHtml = '<div style="margin-top:14px"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);margin-bottom:10px">Exemplar quotes</div>'
            + t.quotes.map(function (q) {
                var text = q.cleaned_text || q.raw_text || '', attr = [];
                if (q.participant_id) attr.push('ID: ' + q.participant_id);
                if (q.question_ref)   attr.push(q.question_ref);
                return '<div style="border-left:3px solid var(--acc);padding:8px 14px;margin-bottom:10px;background:var(--bg);border-radius:0 8px 8px 0">'
                  + '<div style="font-size:14px;color:var(--ink);line-height:1.65;font-style:italic">&ldquo;' + esc(text) + '&rdquo;</div>'
                  + (attr.length ? '<div style="font-size:11.5px;color:var(--ink-3);margin-top:6px">' + esc(attr.join(' · ')) + '</div>' : '') + '</div>';
              }).join('') + '</div>';
        } else {
          quotesHtml = '<div style="font-size:12.5px;color:var(--ink-3);margin-top:10px;font-style:italic">No exemplar quotes pinned. Use Quote Finder to pin supporting evidence.</div>';
        }
        return '<div class="ov-card" style="margin-bottom:16px">'
          + '<div style="display:flex;align-items:baseline;gap:12px;margin-bottom:10px">'
          + '<span style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--acc);background:var(--acc-soft);padding:3px 9px;border-radius:999px">Theme ' + (i+1) + '</span>'
          + '<span style="font-size:17px;font-weight:700">' + esc(t.name) + '</span></div>'
          + '<div style="background:var(--acc-soft);border-radius:10px;padding:12px 16px;margin-bottom:12px"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--acc-deep);margin-bottom:4px">Finding</div>'
          + '<div style="font-size:14px;color:var(--acc-deep);line-height:1.6;font-style:italic">&ldquo;' + esc(t.interpretive_claim || '(no claim set)') + '&rdquo;</div></div>'
          + '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:8px">Supporting categories</div>'
          + '<div style="margin-bottom:8px">' + catChips + '</div>'
          + quotesHtml
          + (t.notes ? '<div style="margin-top:12px;font-size:13px;color:var(--ink-2);border-top:1px solid var(--line);padding-top:12px">' + esc(t.notes).replace(/\n/g,'<br>') + '</div>' : '')
          + '</div>';
      }).join('');
    }
    themeSection += '</div>';

    var stanceMemo = p.researcher_stance_memo || '';
    var trustSection = '<div style="margin-bottom:24px"><h3 style="font-size:18px;font-weight:700;margin:0 0 14px;border-bottom:2px solid var(--acc-soft);padding-bottom:10px">Trustworthiness</h3>'
      + '<div class="ov-card"><div style="margin-bottom:20px"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:8px">Researcher reflexivity</div>'
      + (stanceMemo ? '<div style="background:var(--bg);border-radius:8px;padding:12px 14px;font-size:13.5px;line-height:1.6;color:var(--ink-2)">' + esc(stanceMemo).replace(/\n/g,'<br>') + '</div>' : '<div style="font-size:13px;color:var(--ink-3);font-style:italic">No researcher stance memo recorded.</div>')
      + '</div>'
      + '<div><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);margin-bottom:8px">Member checking</div>'
      + (checks.length
        ? '<div style="max-height:260px;overflow-y:auto;display:flex;flex-direction:column;gap:8px">' + checks.map(function (c) { return '<div style="border:1px solid var(--line);border-radius:8px;padding:10px 12px;font-size:13px"><div style="font-weight:700;margin-bottom:3px">' + esc(c.finding || '') + '</div>' + (c.notes?'<div style="color:var(--ink-2);margin-top:4px">' + esc(c.notes).replace(/\n/g,'<br>') + '</div>':'') + '<div style="font-size:11.5px;color:var(--ink-3);margin-top:6px">' + (c.who?esc(c.who)+' · ':'') + esc(c.date||'') + (c.method?' · '+esc(c.method):'') + '</div></div>'; }).join('') + '</div>'
        : '<div style="font-size:13px;color:var(--ink-3);font-style:italic">No member checks recorded.</div>')
      + '</div></div>'
      + (d.audit_count ? '<div style="font-size:12.5px;color:var(--ink-3);margin-top:10px">' + d.audit_count + ' action' + (d.audit_count!==1?'s':'') + ' logged in the audit trail.</div>' : '')
      + '</div>';

    // Export cards
    var exportSection = '<div style="margin-bottom:24px"><h3 style="font-size:18px;font-weight:700;margin:0 0 14px;border-bottom:2px solid var(--acc-soft);padding-bottom:10px">Export</h3>'
      + '<div class="ov-card" style="margin-bottom:12px"><div class="ov-card-h">MM Studio — Joint display handoff</div><p style="font-size:13px;color:var(--ink-2);margin:0 0 10px">Download the coded segments CSV and upload it as the qualitative strand in MM Studio.</p><a class="btn" style="background:none;color:var(--ink-2);border:1px solid var(--line);text-decoration:none" href="/api/qual/export-coded.php?project_id=' + BOOT.projectId + '">Download coded segments (.csv)</a></div>'
      + '<div class="ov-card" style="margin-bottom:12px"><div class="ov-card-h">RSSI — Open-ended evidence</div><p style="font-size:13px;color:var(--ink-2);margin:0 0 10px">Reference your themes as qualitative evidence alongside the RSSI reliability score.</p><a class="btn" style="background:none;color:var(--ink-2);border:1px solid var(--line);text-decoration:none" href="/rssi-app.php" target="_blank">Open RSSI &rarr;</a></div>'
      + '<div class="ov-card"><div class="ov-card-h">SIRI — Open-ended quality flags</div><p style="font-size:13px;color:var(--ink-2);margin:0 0 10px">Bring qualitative findings back to SIRI to flag question quality issues before the next deployment.</p><a class="btn" style="background:none;color:var(--ink-2);border:1px solid var(--line);text-decoration:none" href="/siri-app.php" target="_blank">Open SIRI &rarr;</a></div>'
      + '</div>';

    host.innerHTML = '<div class="ws-header"><div class="ws-eyebrow">Step 14</div><h1 class="ws-title">Report / Export</h1>'
      + '<p class="ws-lede">A structured summary of your analysis, ready to share or print.</p></div>'
      + topBar
      + '<div id="rep-printable">' + header + summary + themeSection + trustSection + exportSection + '</div>';

    document.getElementById('rep-print').addEventListener('click', function () { window.print(); });
    document.getElementById('rep-json').addEventListener('click', function () {
      var btn = this;
      btn.disabled = true; btn.textContent = 'Loading...';
      api('/api/qual/get-themes.php?project_id=' + BOOT.projectId)
        .then(function (d) {
          return Promise.all((d.themes || []).map(function (t) {
            return api('/api/qual/get-quotes.php?project_id=' + BOOT.projectId + '&theme_id=' + t.id)
              .then(function (qd) {
                var pinnedIds = qd.pinned_ids || [];
                t.pinned_quotes = (qd.segments || []).filter(function (s) { return pinnedIds.indexOf(+s.id) !== -1; }).map(function (s) { return { text: s.cleaned_text || s.raw_text, participant_id: s.participant_id || null, question_ref: s.question_ref || null }; });
                return t;
              });
          }));
        })
        .then(function (themes) {
          var payload = { project: { title: p.title, research_question: p.research_question, analysis_approach: p.analysis_approach }, themes: themes };
          var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a'); a.href = url; a.download = 'qual-themes.json'; a.click(); URL.revokeObjectURL(url);
          btn.disabled = false; btn.textContent = 'Download themes (.json)';
        })
        .catch(function (e) { btn.disabled = false; btn.textContent = 'Download themes (.json)'; alert('Error: ' + e.message); });
    });
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

      case 'setup':
        renderSetup(el);
        break;

      case 'upload':
        renderUpload(el);
        break;

      case 'datamap':
        renderColumnSetup(el);
        break;

      case 'cleaning':
        renderDeident(el);
        break;

      case 'familiarization':
        renderFamiliarization(el);
        break;

      case 'coding':
        renderCoding(el);
        break;

      case 'codebook':
        renderCodebook(el);
        break;

      case 'dual':
        renderDual(el);
        break;

      case 'categories':
        renderCategories(el);
        break;

      case 'themes':
        renderThemes(el);
        break;

      case 'quotes':
        renderQuotes(el);
        break;

      case 'trustworthiness':
        renderTrustworthiness(el);
        break;

      case 'report':
        renderReport(el);
        break;

      default:
        el.innerHTML = '<p style="color:var(--ink-3);font-size:14px;">Step: ' + esc(state.step) + '</p>';
    }
  }

  // ── Init ─────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {

    // 1. Uniform header
    StudioHeader.init({
      logoSrc:      '/QA-Studio-long.png',
      logoAlt:      'Qual Studio',
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

    // Save button + status indicator at rail bottom when in a project
    if (BOOT.projectId > 0) {
      var foot = document.getElementById('railFoot');
      if (foot) {
        foot.innerHTML = '<button class="rail-save-btn" id="railSaveBtn">Save project</button>'
          + '<div class="rail-save-status" id="railSaveStatus">'
          + '<span class="rail-save-dot"></span>Changes saved automatically</div>';

        document.getElementById('railSaveBtn').addEventListener('click', function () {
          var btn = this;
          btn.disabled = true;
          btn.textContent = 'Saving…';
          fetch('/api/qual/save-project.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ project_id: BOOT.projectId, title: BOOT.projectLabel || 'Untitled' }),
          })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              btn.disabled = false;
              if (d.ok) {
                btn.textContent = 'Saved ✓';
                btn.classList.add('saved');
                var status = document.getElementById('railSaveStatus');
                if (status) status.innerHTML = '<span class="rail-save-dot"></span>Just saved';
                setTimeout(function () {
                  btn.textContent = 'Save project';
                  btn.classList.remove('saved');
                  if (status) status.innerHTML = '<span class="rail-save-dot"></span>Changes saved automatically';
                }, 2000);
              } else {
                btn.textContent = 'Save project';
              }
            })
            .catch(function () {
              btn.disabled = false;
              btn.textContent = 'Save project';
            });
        });
      }
    }

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
