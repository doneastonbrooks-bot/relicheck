// ReliCheck Reporting Suite
// -------------------------------------------------------------------
// One engine, eight lenses, all consuming the saved-blocks corpus at
//   localStorage['relicheck.report.<project_id>.default']
// Lenses:
//   report_builder     interactive: title, include/exclude, reorder, notes
//   executive_summary  auto-drafted plain-language summary
//   methodology        auto-drafted methodology section
//   findings           polished, report-ready per-block findings
//   tables_figures     numbered tables drawn from blocks
//   recommendations    formal recommendation list
//   export             PDF (print) / HTML / Markdown / JSON / CSV / clipboard
//   appendix           per-block full payload
//
// Persistent state we maintain (in localStorage on the same project):
//   relicheck.report.<pid>.default.meta = { title, includeMap, blockOrder }

(function () {
  'use strict';

  const projectId = (window.RELICHECK_PROJECT_ID && String(window.RELICHECK_PROJECT_ID)) || 'untitled-project';
  const reportId  = 'default';
  const storageKey = 'relicheck.report.' + projectId + '.' + reportId;
  const metaKey    = storageKey + '.meta';

  let report = readReport();
  let meta   = readMeta();

  let dataset = window.REPORTING_DATASET;
  try {
    const ds = window.localStorage.getItem('relicheck.dataset.' + projectId);
    if (ds) {
      const parsed = JSON.parse(ds);
      if (parsed && parsed.payload && parsed.payload.dataset) dataset = parsed.payload.dataset;
    }
  } catch (e) { /* noop */ }

  let purpose = '';
  try { purpose = window.localStorage.getItem('relicheck.purpose.' + projectId) || ''; } catch (e) {}

  const lens = window.REPORTING_LENS || 'report_builder';
  const blocks = (report && report.blocks) || [];

  // Header ribbon
  wireRibbon();

  if (blocks.length === 0 && lens !== 'report_builder') {
    document.getElementById('repEmpty').hidden = false;
    exposeAppState({ lens: lens, empty: true });
    return;
  }
  if (blocks.length === 0 && lens === 'report_builder') {
    // Builder still renders so the user sees the title + empty list with the explanation.
    document.getElementById('repEmpty').hidden = false;
  }

  // Show the right lens section
  document.querySelectorAll('.rep-lens').forEach(el => {
    el.hidden = el.getAttribute('data-lens') !== lens;
  });

  switch (lens) {
    case 'report_builder':     renderBuilder(); break;
    case 'executive_summary':  renderExecutive(); break;
    case 'methodology':        renderMethodology(); break;
    case 'findings':           renderFindings(); break;
    case 'tables_figures':     renderTables(); break;
    case 'recommendations':    renderRecommendations(); break;
    case 'export':             renderExport(); break;
    case 'appendix':           renderAppendix(); break;
  }

  // Wire copy buttons (used by several lenses)
  document.querySelectorAll('[data-copy-target]').forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.getAttribute('data-copy-target');
      const el = document.getElementById(targetId);
      if (!el) return;
      const text = el.innerText || el.textContent || '';
      copyText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = 'Copied ✓';
        setTimeout(() => { btn.textContent = orig; }, 1400);
      });
    });
  });

  // ==================================================================
  // Read / write helpers
  // ==================================================================
  function readReport() {
    try {
      const raw = window.localStorage.getItem(storageKey);
      if (raw) {
        const parsed = JSON.parse(raw);
        if (parsed && Array.isArray(parsed.blocks)) return parsed;
      }
    } catch (e) {}
    return { project: 'Untitled project', projectId: projectId, reportId: reportId, blocks: [] };
  }
  function writeReport() {
    try { window.localStorage.setItem(storageKey, JSON.stringify(report)); } catch (e) {}
  }
  function readMeta() {
    try {
      const raw = window.localStorage.getItem(metaKey);
      if (raw) {
        const parsed = JSON.parse(raw);
        if (parsed) return Object.assign({ title: '', includeMap: {}, notes: [] }, parsed);
      }
    } catch (e) {}
    return { title: '', includeMap: {}, notes: [] };
  }
  function writeMeta() {
    try { window.localStorage.setItem(metaKey, JSON.stringify(meta)); } catch (e) {}
  }
  function isIncluded(blockId) {
    return meta.includeMap[blockId] !== false; // default true
  }
  function ribbon(level, label, meta_)  {
    const r = document.getElementById('repSource');
    if (!r) return;
    r.setAttribute('data-source', level);
    document.getElementById('repSourceLabel').textContent = label;
    document.getElementById('repSourceMeta').textContent  = meta_ || '';
  }
  function wireRibbon() {
    if (!blocks.length) {
      ribbon('empty', 'No saved results yet', 'Save an analysis to start a report.');
    } else {
      const includedCount = blocks.filter(b => isIncluded(b.id)).length;
      ribbon('ready',
        blocks.length + ' saved ' + (blocks.length === 1 ? 'analysis' : 'analyses'),
        includedCount + ' included in this report'
      );
      const title = meta.title || (report.project || 'Untitled report');
      const echo = document.getElementById('repTitleEcho');
      if (echo) echo.textContent = title;
    }
  }

  // ==================================================================
  // LENS: report_builder
  // ==================================================================
  function renderBuilder() {
    const titleInput = document.getElementById('repTitleInput');
    const blocksHost = document.getElementById('repBlocks');
    const builderMeta = document.getElementById('repBuilderMeta');
    titleInput.value = meta.title || report.project || '';
    titleInput.addEventListener('input', () => {
      meta.title = titleInput.value;
      writeMeta();
      wireRibbon();
    });
    document.getElementById('repAddNote').addEventListener('click', () => {
      const noteText = window.prompt('Note text (a paragraph between findings):', '');
      if (!noteText) return;
      const noteBlock = {
        id:      'note_' + Date.now(),
        addedAt: new Date().toISOString(),
        studio:  blocks[0] ? blocks[0].studio : 'survey',
        project: report.project,
        app:     'note',
        appName: 'Note',
        summary: noteText,
        payload: { kind: 'note', text: noteText },
      };
      report.blocks.push(noteBlock);
      writeReport();
      paint();
    });
    document.getElementById('repSelectAll').addEventListener('click', () => {
      report.blocks.forEach(b => { meta.includeMap[b.id] = true; });
      writeMeta(); paint();
    });
    document.getElementById('repClearAll').addEventListener('click', () => {
      report.blocks.forEach(b => { meta.includeMap[b.id] = false; });
      writeMeta(); paint();
    });
    document.getElementById('repClearReport').addEventListener('click', () => {
      if (!window.confirm('This removes every saved block from the report. The raw data stays. Continue?')) return;
      report.blocks = [];
      writeReport();
      meta.includeMap = {};
      writeMeta();
      paint();
    });

    paint();
    function paint() {
      blocksHost.innerHTML = '';
      if (!report.blocks.length) {
        blocksHost.innerHTML = '<p class="rep-flat">No blocks yet. Run an analysis, click Save to Report, then come back.</p>';
        builderMeta.textContent = '0 blocks';
        wireRibbon();
        return;
      }
      builderMeta.textContent = report.blocks.length + ' block' + (report.blocks.length === 1 ? '' : 's') + '  ·  ' + report.blocks.filter(b => isIncluded(b.id)).length + ' included';
      report.blocks.forEach((b, i) => {
        const row = document.createElement('div');
        row.className = 'rep-block-row';
        row.setAttribute('data-block-id', b.id);
        row.setAttribute('data-included', isIncluded(b.id) ? 'true' : 'false');
        row.innerHTML =
          '<div class="rep-block-controls">' +
            '<input type="checkbox" class="rep-block-include" ' + (isIncluded(b.id) ? 'checked' : '') + ' aria-label="Include in report"/>' +
            '<button class="rep-block-btn" data-act="up"     ' + (i === 0 ? 'disabled' : '') + ' aria-label="Move up">↑</button>' +
            '<button class="rep-block-btn" data-act="down"   ' + (i === report.blocks.length - 1 ? 'disabled' : '') + ' aria-label="Move down">↓</button>' +
            '<button class="rep-block-btn" data-act="remove" aria-label="Remove">×</button>' +
          '</div>' +
          '<div class="rep-block-info">' +
            '<div class="rep-block-eyebrow">' + esc(b.appName || b.app) + '  ·  saved ' + fmtTime(b.addedAt) + '</div>' +
            '<div class="rep-block-summary">' + esc(b.summary || '—') + '</div>' +
          '</div>';
        blocksHost.appendChild(row);

        row.querySelector('.rep-block-include').addEventListener('change', (e) => {
          meta.includeMap[b.id] = e.target.checked;
          writeMeta();
          row.setAttribute('data-included', e.target.checked ? 'true' : 'false');
          wireRibbon();
          builderMeta.textContent = report.blocks.length + ' block' + (report.blocks.length === 1 ? '' : 's') + '  ·  ' + report.blocks.filter(b => isIncluded(b.id)).length + ' included';
        });
        row.querySelectorAll('.rep-block-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            const act = btn.getAttribute('data-act');
            if (act === 'up' && i > 0) {
              const t = report.blocks[i]; report.blocks[i] = report.blocks[i - 1]; report.blocks[i - 1] = t;
            } else if (act === 'down' && i < report.blocks.length - 1) {
              const t = report.blocks[i]; report.blocks[i] = report.blocks[i + 1]; report.blocks[i + 1] = t;
            } else if (act === 'remove') {
              if (!window.confirm('Remove this block from the report? (The original analysis is not deleted.)')) return;
              report.blocks.splice(i, 1);
              delete meta.includeMap[b.id];
              writeMeta();
            }
            writeReport();
            paint();
            wireRibbon();
          });
        });
      });
    }
    exposeAppState({ lens: lens, blockCount: report.blocks.length, includedCount: report.blocks.filter(b => isIncluded(b.id)).length });
  }

  // ==================================================================
  // LENS: executive_summary
  // ==================================================================
  function renderExecutive() {
    const included = blocks.filter(b => isIncluded(b.id) && b.app !== 'note');
    const title = meta.title || report.project || 'Untitled report';
    document.getElementById('repExecTitle').textContent = title;
    const ranked = included.map(rankFinding).filter(f => f.score > 0).sort((a, b) => b.score - a.score).slice(0, 3);
    const decision = decisionVerdict(included, dataset);

    const paras = [];
    if (purpose) {
      paras.push('This analysis set out to ' + purpose.replace(/^\s*to\s+/i, '').replace(/\.$/, '') + '.');
    } else {
      paras.push('This report summarizes ' + included.length + ' ' + (included.length === 1 ? 'analysis' : 'analyses') + ' carried out in ReliCheck.');
    }
    if (ranked.length) {
      paras.push('The headline findings are: ' + ranked.map(f => f.line).join('; ') + '.');
    } else if (included.length === 0) {
      paras.push('No analyses have been included in this report yet.');
    } else {
      paras.push('No single result dominates; the analyses report a series of modest patterns rather than a standout finding.');
    }
    paras.push('Decision readiness: ' + decision.band.toLowerCase() + '. ' + decision.headline);
    if (decision.watch && decision.watch.length) {
      paras.push('Caveats include: ' + decision.watch.join('; ') + '.');
    }

    document.getElementById('repExecBody').innerHTML = paras.map(p => '<p>' + esc(p) + '</p>').join('');
    exposeAppState({ lens: lens, paragraphs: paras.length, ranked: ranked.length });
  }

  // ==================================================================
  // LENS: methodology
  // ==================================================================
  function renderMethodology() {
    const included = blocks.filter(b => isIncluded(b.id) && b.app !== 'note');
    const paras = [];

    // Sample
    if (dataset) {
      paras.push(
        '<strong>Sample.</strong> Data are drawn from ' + esc(dataset.source || 'an unnamed source') +
        ' (n = ' + (dataset.rowCount || 0) + ').'
      );
    } else {
      paras.push('<strong>Sample.</strong> No dataset metadata is attached to this report.');
    }

    // Variables
    if (dataset && Array.isArray(dataset.variables)) {
      const byType = {};
      dataset.variables.forEach(v => {
        (v.types || ['other']).forEach(t => { byType[t] = (byType[t] || 0) + 1; });
      });
      const typeBits = Object.keys(byType).map(t => byType[t] + ' ' + t).join(', ');
      paras.push(
        '<strong>Variables.</strong> ' + dataset.variables.length + ' variables in total (' + typeBits + ').'
      );
    }

    // Analyses
    if (included.length === 0) {
      paras.push('<strong>Analyses.</strong> No analyses included in this report.');
    } else {
      const counts = {};
      included.forEach(b => { counts[b.appName || b.app] = (counts[b.appName || b.app] || 0) + 1; });
      const bits = Object.keys(counts).map(k => counts[k] === 1 ? k : k + ' (×' + counts[k] + ')');
      paras.push(
        '<strong>Analyses.</strong> ' + bits.join('; ') + '.'
      );
    }

    // Statistical detail
    const tests = included.filter(b => b.app === 'inferential');
    if (tests.length) {
      const testNames = tests.map(b => (b.payload && b.payload.result && b.payload.result.test) || 'a test').join(', ');
      paras.push(
        '<strong>Inferential tests.</strong> ' + testNames + '. p-values were computed from the regularized incomplete beta (t and F distributions) and incomplete gamma (chi-square distribution) via continued-fraction expansions. Welch\'s correction is on by default for two-group comparisons; group variances are not assumed equal.'
      );
    }

    const strength = included.filter(b => b.app === 'strength_index');
    if (strength.length) {
      paras.push(
        '<strong>Instrument quality.</strong> The ReliCheck Strength Index is a 0-100 composite of six weighted domains: Internal Consistency &amp; Scale Reliability (25 pts, Cronbach\'s α and McDonald\'s ω), Factor Structure (20, KMO + loading-pattern penalties), Item Quality (20), Response Quality (15), Open-Ended Alignment (10), and Actionability (10).'
      );
    }

    const qual = included.filter(b => b.app === 'open_ended_summary');
    if (qual.length) {
      paras.push(
        '<strong>Open-ended responses.</strong> Open-ended text was summarized via response rate, word-count statistics, length distribution, top stopword-filtered unigrams and bigrams, and three representative sample quotes per question.'
      );
    }

    document.getElementById('repMethodologyBody').innerHTML = paras.map(p => '<p>' + p + '</p>').join('');
    exposeAppState({ lens: lens, sections: paras.length });
  }

  // ==================================================================
  // LENS: findings
  // ==================================================================
  function renderFindings() {
    const included = blocks.filter(b => isIncluded(b.id));
    const title = meta.title || report.project || 'Untitled report';
    document.getElementById('repFindingsTitle').textContent = title;
    document.getElementById('repFindingsSub').textContent =
      included.length + ' ' + (included.length === 1 ? 'block' : 'blocks') + ' included' +
      (purpose ? '  ·  Purpose: ' + purpose : '');
    const body = document.getElementById('repFindingsBody');
    body.innerHTML = '';
    if (!included.length) {
      body.innerHTML = '<p class="rep-flat">No included blocks. Visit Report Builder to include blocks you have saved.</p>';
      return;
    }
    included.forEach((b, i) => {
      if (b.app === 'note') {
        const note = document.createElement('div');
        note.className = 'rep-finding-note';
        note.innerHTML = '<p>' + esc(b.summary || b.payload && b.payload.text || '') + '</p>';
        body.appendChild(note);
        return;
      }
      const card = document.createElement('article');
      card.className = 'rep-finding';
      card.innerHTML =
        '<div class="rep-finding-eyebrow">Finding ' + (i + 1) + '  ·  ' + esc(b.appName || b.app) + '</div>' +
        '<h4 class="rep-finding-headline">' + esc(findingHeadline(b)) + '</h4>' +
        '<p class="rep-finding-context">' + esc(findingContext(b)) + '</p>' +
        '<p class="rep-finding-interp">' + esc(findingInterp(b)) + '</p>';
      body.appendChild(card);
    });
    exposeAppState({ lens: lens, included: included.length });
  }

  // ==================================================================
  // LENS: tables_figures
  // ==================================================================
  function renderTables() {
    const included = blocks.filter(b => isIncluded(b.id) && b.app !== 'note');
    const host = document.getElementById('repTablesBody');
    host.innerHTML = '';
    if (!included.length) {
      host.innerHTML = '<p class="rep-flat">No included blocks have tables to render.</p>';
      return;
    }
    let tableNum = 0;
    included.forEach(b => {
      const tables = blockTables(b);
      tables.forEach(t => {
        tableNum++;
        const wrap = document.createElement('div');
        wrap.className = 'rep-table-wrap';
        wrap.innerHTML =
          '<div class="rep-table-caption">Table ' + tableNum + '. ' + esc(t.caption) + '</div>' +
          renderTableHtml(t);
        host.appendChild(wrap);
      });
    });
    exposeAppState({ lens: lens, tables: tableNum });
  }

  function blockTables(b) {
    const p = b.payload || {};
    const out = [];
    if (b.app === 'strength_index' && p.components) {
      const rows = [];
      const labelMap = { reliability:'Internal Consistency & Scale Reliability', factor_structure:'Factor Structure', item_quality:'Item Quality', response_quality:'Response Quality', open_ended:'Open-Ended Alignment', actionability:'Actionability' };
      Object.keys(p.components).forEach(k => {
        const c = p.components[k] || {};
        rows.push([labelMap[k] || k, c.raw != null ? c.raw : '—', c.max != null ? c.max : '—', c.score != null ? c.score : '—']);
      });
      out.push({ caption: 'Strength Index by domain', columns: ['Domain', 'Raw', 'Max', 'Score (0-100)'], rows: rows });
    }
    if (b.app === 'inferential' && p.result && p.result.detail) {
      const d = p.result.detail;
      if (Array.isArray(d.groups)) {
        out.push({
          caption: p.result.test + ': group descriptives',
          columns: ['Group', 'n', 'Mean', 'SD'],
          rows: d.groups.map(g => [g.name, g.n, fmt(g.mean, 2), fmt(g.sd, 2)]),
        });
      }
      if (d.observed && d.rowLevels && d.colLevels) {
        const cols = ['', ...d.colLevels];
        const rows = d.rowLevels.map((r, i) => [r, ...d.observed[i].map(v => String(v))]);
        out.push({ caption: p.result.test + ': observed counts', columns: cols, rows: rows });
      }
    }
    if (b.app === 'effect_size' && p.result && p.result.detail) {
      const d = p.result.detail;
      if (d.m1 != null) {
        out.push({
          caption: 'Effect Size: group descriptives',
          columns: ['Group', 'n', 'Mean', 'SD'],
          rows: [
            [d.levels ? d.levels[0] : 'Group 1', d.n1, fmt(d.m1, 2), fmt(d.sd1, 2)],
            [d.levels ? d.levels[1] : 'Group 2', d.n2, fmt(d.m2, 2), fmt(d.sd2, 2)],
          ],
        });
      }
    }
    if (b.app === 'open_ended_summary' && Array.isArray(p.perQuestion)) {
      out.push({
        caption: 'Open-ended summary by question',
        columns: ['Question', 'Answered', 'Response rate', 'Mean words', 'Substantive (≥10 chars)'],
        rows: p.perQuestion.map(q => [
          q.label || q.name,
          q.answered,
          Math.round((q.responseRate || 0) * 100) + '%',
          (q.meanWords || 0).toFixed(1),
          q.substantive,
        ]),
      });
    }
    return out;
  }
  function renderTableHtml(t) {
    return '<table class="rep-table">' +
      '<thead><tr>' + t.columns.map(c => '<th>' + esc(c) + '</th>').join('') + '</tr></thead>' +
      '<tbody>' + t.rows.map(r => '<tr>' + r.map((v, i) =>
        '<' + (i === 0 ? 'th class="rowhead"' : 'td') + '>' + esc(v == null ? '—' : v) + '</' + (i === 0 ? 'th' : 'td') + '>'
      ).join('') + '</tr>').join('') + '</tbody>' +
    '</table>';
  }

  // ==================================================================
  // LENS: recommendations
  // ==================================================================
  function renderRecommendations() {
    const included = blocks.filter(b => isIncluded(b.id) && b.app !== 'note');
    const actions = [];
    included.forEach(b => {
      const p = b.payload || {};
      if (b.app === 'inferential' && p.result && p.result.p < 0.05) {
        if (p.result.test === 'One-way ANOVA') actions.push('Conduct a post-hoc comparison on the significant ANOVA to identify the pairs of groups that differ.');
        else if (p.result.test.indexOf('t-test') !== -1) actions.push('Report the group means with 95% confidence intervals alongside the t-test.');
        else if (p.result.test.indexOf('Chi-square') !== -1) actions.push('Inspect the standardized residuals on the chi-square to identify the cells that drive the association.');
        else if (p.result.test === 'Pearson correlation') actions.push('Confirm linearity on a scatterplot before reporting the correlation as causal-adjacent.');
      }
      if (b.app === 'strength_index' && p.components) {
        let lowest = null;
        Object.keys(p.components).forEach(k => {
          const c = p.components[k];
          if (c && c.score != null) {
            if (!lowest || c.score < lowest.score) lowest = { key: k, score: c.score };
          }
        });
        if (lowest && lowest.score < 80) {
          const map = { reliability:'Internal Consistency', factor_structure:'Factor Structure', item_quality:'Item Quality', response_quality:'Response Quality', open_ended:'Open-Ended Alignment', actionability:'Actionability' };
          actions.push('Strengthen the ' + (map[lowest.key] || lowest.key) + ' domain of the Strength Index (currently ' + Math.round(lowest.score) + '/100) before publication.');
        }
      }
      if (b.app === 'open_ended_summary' && p.aggregate && p.aggregate.answers >= 10) {
        actions.push('Move the open-ended responses into Theme Analysis for formal coding; the volume supports it.');
      }
    });
    const seen = new Set();
    const uniq = actions.filter(a => { if (seen.has(a)) return false; seen.add(a); return true; });
    const host = document.getElementById('repRecommendationsBody');
    if (!uniq.length) {
      host.innerHTML = '<li>No recommendations generated from the included blocks. Save more analyses or refine the existing ones.</li>';
    } else {
      host.innerHTML = uniq.map(a => '<li>' + esc(a) + '</li>').join('');
    }
    exposeAppState({ lens: lens, recommendations: uniq.length });
  }

  // ==================================================================
  // LENS: export
  // ==================================================================
  function renderExport() {
    const grid = document.getElementById('repExportGrid');
    grid.querySelectorAll('button[data-fmt]').forEach(btn => {
      btn.addEventListener('click', () => {
        const fmt = btn.getAttribute('data-fmt');
        switch (fmt) {
          case 'pdf':       exportPdf(); break;
          case 'html':      downloadFile('relicheck-report.html', buildHtml(),     'text/html'); break;
          case 'markdown':  downloadFile('relicheck-report.md',   buildMarkdown(), 'text/markdown'); break;
          case 'json':      downloadFile('relicheck-report.json', JSON.stringify({ report: report, meta: meta }, null, 2), 'application/json'); break;
          case 'csv':       downloadFile('relicheck-report.csv',  buildCsv(),      'text/csv'); break;
          case 'clipboard': copyText(buildMarkdown()).then(() => {
            const note = document.getElementById('repExportFoot');
            const orig = note.textContent;
            note.textContent = 'Full report copied to clipboard.';
            setTimeout(() => { note.textContent = orig; }, 1800);
          }); break;
        }
      });
    });
    exposeAppState({ lens: lens, blockCount: blocks.length });
  }
  function exportPdf() {
    // Switch user over to the Findings layout and trigger print.
    // (window.print() prints whatever's currently on screen — Findings has
    // print rules that strip chrome.)
    if (window.location.pathname.indexOf('/findings.php') !== -1) {
      window.print();
      return;
    }
    const studio = new URL(window.location.href).searchParams.get('studio') || 'survey';
    window.open('/findings.php?studio=' + encodeURIComponent(studio) + '#print', '_blank');
  }
  function buildHtml() {
    const included = blocks.filter(b => isIncluded(b.id));
    const title = meta.title || report.project || 'ReliCheck Report';
    let body = '';
    included.forEach((b, i) => {
      if (b.app === 'note') {
        body += '<p>' + esc(b.summary || '') + '</p>';
        return;
      }
      body += '<section><h3>Finding ' + (i + 1) + ': ' + esc(b.appName || b.app) + '</h3>';
      body += '<p><strong>' + esc(findingHeadline(b)) + '</strong></p>';
      body += '<p><code>' + esc(findingContext(b)) + '</code></p>';
      body += '<p>' + esc(findingInterp(b)) + '</p>';
      body += '</section>';
    });
    return '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title>' +
           '<style>body{font:14px/1.55 -apple-system,Helvetica,sans-serif;max-width:760px;margin:40px auto;padding:0 20px;color:#1c2238}h1{font-size:24px}h3{font-size:17px;margin-top:28px}code{background:#f3f5f8;padding:2px 6px;border-radius:4px;font-size:12.5px}</style>' +
           '</head><body><h1>' + esc(title) + '</h1>' +
           (purpose ? '<p><em>Purpose:</em> ' + esc(purpose) + '</p>' : '') +
           body + '</body></html>';
  }
  function buildMarkdown() {
    const included = blocks.filter(b => isIncluded(b.id));
    const title = meta.title || report.project || 'ReliCheck Report';
    let md = '# ' + title + '\n\n';
    if (purpose) md += '_Purpose: ' + purpose + '_\n\n';
    included.forEach((b, i) => {
      if (b.app === 'note') {
        md += b.summary + '\n\n';
        return;
      }
      md += '## Finding ' + (i + 1) + ': ' + (b.appName || b.app) + '\n\n';
      md += '**' + findingHeadline(b) + '**\n\n';
      md += '`' + findingContext(b) + '`\n\n';
      md += findingInterp(b) + '\n\n';
    });
    return md;
  }
  function buildCsv() {
    const included = blocks.filter(b => isIncluded(b.id));
    const rows = [['id', 'addedAt', 'app', 'appName', 'summary']];
    included.forEach(b => rows.push([b.id, b.addedAt, b.app, b.appName, b.summary]));
    return rows.map(r => r.map(csvCell).join(',')).join('\n');
  }
  function csvCell(s) {
    const str = String(s == null ? '' : s);
    if (/[",\n]/.test(str)) return '"' + str.replace(/"/g, '""') + '"';
    return str;
  }

  // ==================================================================
  // LENS: appendix
  // ==================================================================
  function renderAppendix() {
    const included = blocks.filter(b => isIncluded(b.id) && b.app !== 'note');
    const host = document.getElementById('repAppendixBody');
    host.innerHTML = '';
    if (!included.length) {
      host.innerHTML = '<p class="rep-flat">No included blocks.</p>';
      return;
    }
    included.forEach((b, i) => {
      const wrap = document.createElement('details');
      wrap.className = 'rep-appendix-row';
      wrap.innerHTML =
        '<summary>' +
          '<span class="rep-appendix-num">A' + (i + 1) + '</span>' +
          '<span class="rep-appendix-name">' + esc(b.appName || b.app) + '</span>' +
          '<span class="rep-appendix-summary">' + esc(b.summary || '') + '</span>' +
        '</summary>' +
        '<pre class="rep-appendix-pre">' + esc(JSON.stringify(b.payload, null, 2)) + '</pre>';
      host.appendChild(wrap);
    });
    exposeAppState({ lens: lens, blocks: included.length });
  }

  // ==================================================================
  // Shared helpers (block readers)
  // ==================================================================
  function rankFinding(b) {
    const p = b.payload || {};
    if (b.app === 'strength_index' && p.strength != null) {
      const dist = Math.abs(p.strength - 75);
      return { score: dist, line: 'a Strength Index of ' + p.strength + ' (' + p.verdict + ')' };
    }
    if (b.app === 'inferential' && p.result) {
      const r = p.result;
      const sig = r.p < 0.05 ? 1 : 0;
      const big = (r.effect.tone === 'strong' ? 2 : r.effect.tone === 'ok' ? 1 : 0);
      const score = sig * 2 + big * 2;
      if (!score) return { score: 0 };
      return { score: score, line: 'a ' + (sig ? 'significant ' : '') + r.test.toLowerCase() + ' on ' + (p.variables ? p.variables.join(' and ') : 'two variables') };
    }
    if (b.app === 'effect_size' && p.result) {
      const t = p.result.tone;
      const score = t === 'strong' ? 4 : t === 'ok' ? 3 : t === 'warn' ? 1 : 0;
      if (!score) return { score: 0 };
      return { score: score, line: 'an effect size of ' + (p.result.name || '') + ' = ' + (p.result.value != null ? p.result.value.toFixed(2) : '—') + ' (' + p.result.band + ')' };
    }
    if (b.app === 'open_ended_summary' && p.aggregate) {
      const rr = p.aggregate.responseRate || 0;
      const score = rr >= 0.70 ? 3 : rr >= 0.40 ? 2 : 0;
      if (!score) return { score: 0 };
      return { score: score, line: Math.round(rr * 100) + '% of respondents wrote substantive open-ended answers' };
    }
    return { score: 0 };
  }
  function decisionVerdict(included, dataset) {
    let evidence = 0; let watch = [];
    let inferentialPositive = 0;
    included.forEach(b => {
      const p = b.payload || {};
      if (b.app === 'strength_index' && p.strength != null) {
        if (p.strength >= 80) evidence += 3;
        else if (p.strength >= 70) evidence += 1.5;
        else evidence -= 1;
      }
      if (b.app === 'inferential' && p.result) {
        if (p.result.p < 0.05 && (p.result.effect.tone === 'ok' || p.result.effect.tone === 'strong')) { evidence += 2; inferentialPositive++; }
        else if (p.result.p < 0.05) { evidence += 0.5; inferentialPositive++; }
      }
      if (b.app === 'effect_size' && p.result && (p.result.tone === 'ok' || p.result.tone === 'strong')) evidence += 1;
      if (b.app === 'open_ended_summary' && p.aggregate && p.aggregate.responseRate >= 0.50) evidence += 1;
    });
    const rowCount = (dataset && dataset.rowCount) || 0;
    if (rowCount && rowCount < 30) { evidence -= 1; watch.push('n = ' + rowCount); }
    let band, headline;
    if (evidence >= 5)      { band = 'High-stakes ready'; headline = 'The evidence is strong enough to support consequential decisions.'; }
    else if (evidence >= 3) { band = 'Operational';        headline = 'Evidence is good enough for operational decisions with caveats.'; }
    else if (evidence >= 1) { band = 'Exploratory';        headline = 'Treat the evidence as exploratory; not yet decision-ready.'; }
    else                    { band = 'Insufficient';       headline = 'Not enough evidence to support a defensible decision.'; }
    return { evidence: evidence, band: band, headline: headline, watch: watch };
  }
  function findingHeadline(b) {
    const p = b.payload || {};
    if (b.app === 'strength_index')    return 'Instrument scores ' + p.strength + ' out of 100 (' + p.verdict + ').';
    if (b.app === 'inferential' && p.result) {
      const d = p.result.detail;
      if (d && d.groups && d.groups.length === 2)      return d.groups[0].name + ' (M = ' + fmt(d.groups[0].mean, 2) + ') vs ' + d.groups[1].name + ' (M = ' + fmt(d.groups[1].mean, 2) + ')';
      return p.result.test + ' on ' + (p.variables ? p.variables.join(' and ') : 'two variables');
    }
    if (b.app === 'effect_size' && p.result) return p.result.name + ' = ' + fmt(p.result.value, 3) + ' (' + p.result.band + ')';
    if (b.app === 'open_ended_summary' && p.aggregate) return Math.round((p.aggregate.responseRate || 0) * 100) + '% of respondents wrote ' + (p.aggregate.answers || 0) + ' open-ended ' + (p.aggregate.answers === 1 ? 'response' : 'responses');
    return b.summary || (b.appName || b.app);
  }
  function findingContext(b) {
    const p = b.payload || {};
    if (b.app === 'inferential' && p.result) {
      const r = p.result;
      if (r.test.indexOf('t-test') !== -1)        return 't(' + fmt(r.df, 1) + ') = ' + fmt(r.statistic, 2) + ', ' + fmtP(r.p) + ', d = ' + fmt(r.effect.value, 2);
      if (r.test === 'One-way ANOVA')             return 'F(' + r.df + ') = ' + fmt(r.statistic, 2) + ', ' + fmtP(r.p) + ', η² = ' + fmt(r.effect.value, 3);
      if (r.test.indexOf('Chi-square') !== -1)    return 'χ²(' + r.df + ', N = ' + (r.detail && r.detail.N) + ') = ' + fmt(r.statistic, 2) + ', ' + fmtP(r.p) + ", V = " + fmt(r.effect.value, 2);
      if (r.test === 'Pearson correlation')       return 'r(' + r.df + ') = ' + fmt(r.statistic, 2) + ', ' + fmtP(r.p) + ', r² = ' + fmt(r.effect.value, 3);
    }
    if (b.app === 'strength_index' && p.strength != null) return 'Strength = ' + p.strength + '/100 · verdict: ' + p.verdict;
    if (b.app === 'effect_size' && p.result) return p.result.name + ' = ' + fmt(p.result.value, 3) + (p.result.ci95 ? ', 95% CI [' + fmt(p.result.ci95[0], 2) + ', ' + fmt(p.result.ci95[1], 2) + ']' : '');
    if (b.app === 'open_ended_summary' && p.aggregate) return 'n = ' + p.aggregate.answers + ' answers, avg ' + (p.aggregate.avgWords || 0).toFixed(1) + ' words, ' + Math.round((p.aggregate.responseRate || 0) * 100) + '% response rate';
    return b.summary || '';
  }
  function findingInterp(b) {
    const p = b.payload || {};
    if (b.app === 'inferential' && p.result) {
      const sig = p.result.p < 0.05;
      const big = p.result.effect.tone === 'ok' || p.result.effect.tone === 'strong';
      if (sig && big)  return 'The difference is statistically significant and meaningfully large.';
      if (sig && !big) return 'Statistically significant but the effect size is modest.';
      if (!sig && big) return 'The effect size is substantial but the test does not clear .05; may be underpowered.';
      return 'No reliable difference at the 0.05 level.';
    }
    if (b.app === 'strength_index' && p.strength != null) {
      if (p.strength >= 80) return 'The instrument is strong enough to publish findings drawn from it.';
      if (p.strength >= 70) return 'Usable; address the weakest domain before strong claims.';
      return 'Needs strengthening before findings can stand as conclusions.';
    }
    if (b.app === 'effect_size' && p.result) {
      const r = p.result;
      if (r.band === 'large')  return 'A large effect — visible without statistical tools.';
      if (r.band === 'medium') return 'A medium effect — practically meaningful.';
      if (r.band === 'small')  return 'A small effect — real but modest.';
      return 'A negligible effect.';
    }
    if (b.app === 'open_ended_summary' && p.verdict) return p.verdict + '.';
    return '';
  }

  // ==================================================================
  // Tiny helpers
  // ==================================================================
  function exposeAppState(payload) {
    window.RELICHECK_APP_STATE = Object.assign({
      app_key:   'reporting',
      app_name:  'Reporting (' + lens + ')',
      summary:   summarize(payload),
      computed_at: new Date().toISOString(),
    }, payload);
  }
  function summarize(p) {
    if (p.empty) return 'Reporting (' + lens + '): no blocks saved yet.';
    if (lens === 'report_builder') return (p.blockCount || 0) + ' blocks, ' + (p.includedCount || 0) + ' included.';
    if (lens === 'executive_summary') return (p.paragraphs || 0) + '-paragraph executive summary.';
    if (lens === 'tables_figures')    return (p.tables || 0) + ' tables.';
    if (lens === 'recommendations')   return (p.recommendations || 0) + ' recommendations.';
    return lens;
  }
  function fmt(x, d) { if (x == null || !isFinite(x)) return '—'; return Number(x).toFixed(d == null ? 2 : d); }
  function fmtP(p) {
    if (p == null) return 'p = —';
    if (p < 0.001) return 'p < .001';
    if (p < 0.01)  return 'p = ' + p.toFixed(3).replace(/^0/, '');
    return 'p = ' + p.toFixed(2).replace(/^0/, '');
  }
  function fmtTime(iso) {
    if (!iso) return '—';
    try {
      const d = new Date(iso);
      return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch (e) { return iso; }
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(text);
    return new Promise((resolve) => {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta);
      resolve();
    });
  }
  function downloadFile(name, content, mime) {
    const blob = new Blob([content], { type: mime + ';charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = name; a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 100);
  }
})();
