// ReliCheck MM (Mixed-Methods) Analysis Suite
// -------------------------------------------------------------------
// Seven lenses on qualitative + quantitative data.
//   codebook_builder      interactive UI to define themes
//   theme_analysis        auto-tag open-ended responses against codebook
//   quote_extractor       representative quotes per theme
//   theme_by_group        cross-tab theme × demographic
//   joint_display         quant findings + qual themes side-by-side
//   integration_quality   rule-based corroboration score
//   qual_to_quant         convert codebook → 0/1 variables per respondent
//
// Codebook is project-scoped state in localStorage:
//   relicheck.codebook.<project_id> = [
//     { id, name, description, keywords: [string, ...] }, ...
//   ]

(function () {
  'use strict';

  // ==================================================================
  // State + dataset
  // ==================================================================
  let dataset = window.MM_DATASET;
  let datasetSource = 'sample';
  const projectId = (window.RELICHECK_PROJECT_ID && String(window.RELICHECK_PROJECT_ID)) || 'untitled-project';
  try {
    const stored = window.localStorage.getItem('relicheck.dataset.' + projectId);
    if (stored) {
      const parsed = JSON.parse(stored);
      if (parsed && parsed.payload && parsed.payload.dataset) { dataset = parsed.payload.dataset; datasetSource = 'uploaded'; }
    }
  } catch (e) {}

  if (!dataset || !Array.isArray(dataset.variables)) {
    document.getElementById('mmEmpty').hidden = false;
    return;
  }

  const allVars  = dataset.variables;
  const rowCount = dataset.rowCount || (allVars[0] ? allVars[0].values.length : 0);
  const lens     = window.MM_LENS || 'codebook_builder';

  // Codebook (state)
  const codebookKey = 'relicheck.codebook.' + projectId;
  let codebook = [];
  try {
    const raw = window.localStorage.getItem(codebookKey);
    if (raw) codebook = JSON.parse(raw) || [];
  } catch (e) {}
  function saveCodebook() { try { window.localStorage.setItem(codebookKey, JSON.stringify(codebook)); } catch (e) {} updateCodebookBadge(); }
  function updateCodebookBadge() {
    const el = document.getElementById('mmCodebookBadge');
    if (el) el.textContent = codebook.length + ' code' + (codebook.length === 1 ? '' : 's');
  }
  updateCodebookBadge();

  // ==================================================================
  // Helpers
  // ==================================================================
  function isMissing(v) { return v === '' || v == null; }
  function hasType(v, t) { return v.types && v.types.indexOf(t) !== -1; }
  function isOpen(v) { return hasType(v, 'open'); }
  function isCategorical(v) { return hasType(v, 'categorical'); }
  function fmt(x, d) { if (x == null || !isFinite(x)) return '—'; return Number(x).toFixed(d == null ? 2 : d); }
  function pct(x) { return x == null ? '—' : Math.round(x * 100) + '%'; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
  const STOP = new Set([
    'a','an','and','or','but','if','then','the','this','that','these','those',
    'is','are','was','were','be','been','being','am',
    'i','you','he','she','it','we','they','me','him','her','us','them',
    'of','in','on','at','to','for','from','by','with','about','as','into','through',
    'do','does','did','done','doing','have','has','had','having',
    'will','would','could','should','can','not','no','too','very','just','also','only','really','quite',
    'so','than','because','when','where','what','who','there','here','out','up','more','most','some','any','all','both','each','other','such',
  ]);
  function tokenize(text) {
    if (text == null) return [];
    return String(text).toLowerCase().replace(/[^a-z0-9\s\-]/g, ' ').replace(/\s+/g, ' ').trim().split(' ').filter(w => w.length > 1 && !STOP.has(w));
  }
  function tokenizeWithStops(text) {
    if (text == null) return [];
    return String(text).toLowerCase().replace(/[^a-z0-9\s\-]/g, ' ').replace(/\s+/g, ' ').trim().split(' ').filter(w => w.length > 0);
  }
  function matchesTheme(text, theme) {
    if (!theme || !theme.keywords || !theme.keywords.length) return false;
    const lower = String(text || '').toLowerCase();
    return theme.keywords.some(kw => kw && lower.indexOf(String(kw).toLowerCase()) !== -1);
  }

  // ==================================================================
  // Get the open-ended values: combine all open variables into one
  // [{ row, text, var, open }] list for analysis.
  // ==================================================================
  const openVars = allVars.filter(isOpen);
  const responses = [];
  for (let i = 0; i < rowCount; i++) {
    openVars.forEach(v => {
      const t = v.values[i];
      if (isMissing(t)) return;
      const text = String(t).trim();
      if (!text.length) return;
      responses.push({ row: i, text: text, varName: v.name });
    });
  }

  // Source ribbon
  document.getElementById('mmSource').setAttribute('data-source', datasetSource);
  document.getElementById('mmSourceLabel').textContent = datasetSource === 'uploaded' ? 'Uploaded data' : 'Sample data';
  document.getElementById('mmSourceMeta').textContent  = (dataset.source || 'Dataset') + '  ·  ' + rowCount + ' rows · ' + responses.length + ' qual responses';
  document.querySelectorAll('.mm-lens').forEach(el => {
    el.hidden = el.getAttribute('data-lens') !== lens;
  });

  switch (lens) {
    case 'codebook_builder':   renderCodebookBuilder(); break;
    case 'theme_analysis':     renderThemeAnalysis(); break;
    case 'quote_extractor':    renderQuoteExtractor(); break;
    case 'theme_by_group':     setupThemeByGroup(); break;
    case 'joint_display':      renderJointDisplay(); break;
    case 'integration_quality':renderIntegrationQuality(); break;
    case 'qual_to_quant':      renderQualToQuant(); break;
  }

  // ==================================================================
  // Unified template helpers (per [[relicheck-iq-design-template]])
  // -------------------------------------------------------------------
  // Wrap a lens's body content with the standard tinted summary card
  // (judgment + plain + research paragraphs) and accent-soft closing
  // card. Fills the per-lens mm-foot interp slot with a J/E/M/A footer.
  // Codebook Builder is the only lens that does NOT use this — it is an
  // interactive editor, not a report page.
  // ==================================================================
  function mmUnifyBody(o) {
    return '<div class="mm-rel-summary">' +
             '<h4 class="mm-block-h">' + esc(o.summaryTitle) + '</h4>' +
             '<p class="mm-rel-summary-line"><strong>' + esc(o.judgment) + '</strong></p>' +
             '<div class="mm-rel-paragraphs">' +
               '<div><span class="mm-rel-label">What this shows.</span> ' + esc(o.plainPara) + '</div>' +
               '<div><span class="mm-rel-label">Research interpretation.</span> ' + esc(o.researchPara) + '</div>' +
             '</div>' +
           '</div>' +
           (o.body || '') +
           '<div class="mm-rel-closing">' +
             '<h4 class="mm-block-h">Interpretation</h4>' +
             '<p>' + esc(o.closingPara) + '</p>' +
           '</div>';
  }
  function mmUnifyInterp(o) {
    return '<div class="mm-jema">' +
             '<div class="mm-jema-row"><strong>What this shows.</strong> ' + esc(o.plainPara) + '</div>' +
             '<div class="mm-jema-row"><strong>What stands out.</strong> ' + esc(o.judgment) + '</div>' +
             '<div class="mm-jema-row"><strong>What to check next.</strong> ' + esc(o.closingPara) + '</div>' +
           '</div>';
  }

  // ==================================================================
  // LENS: codebook_builder
  // ==================================================================
  function renderCodebookBuilder() {
    const host = document.getElementById('mmCbThemes');
    paint();
    document.getElementById('mmCbAdd').addEventListener('click', () => {
      const id = 't_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6);
      codebook.push({ id: id, name: '', description: '', keywords: [] });
      saveCodebook(); paint();
    });
    document.getElementById('mmCbClear').addEventListener('click', () => {
      if (!codebook.length) return;
      if (!window.confirm('Clear the entire codebook for this project?')) return;
      codebook = []; saveCodebook(); paint();
    });
    document.getElementById('mmCbSuggest').addEventListener('click', () => suggestThemes());

    function paint() {
      if (!codebook.length) {
        host.innerHTML = '<div class="mm-flat">No themes yet. Click <strong>+ Add theme</strong> to define one, or <strong>Suggest from data</strong> to auto-discover candidate themes from the open-ended responses.</div>';
        updateBadge();
        return;
      }
      host.innerHTML = codebook.map((t, i) =>
        '<div class="mm-cb-card" data-id="' + esc(t.id) + '">' +
          '<div class="mm-cb-row">' +
            '<label class="mm-cb-row-label">Theme name</label>' +
            '<input class="mm-cb-input" type="text" data-field="name" value="' + esc(t.name) + '" placeholder="e.g., Workload concerns" />' +
            '<button class="mm-cb-remove" type="button" aria-label="Remove">×</button>' +
          '</div>' +
          '<div class="mm-cb-row">' +
            '<label class="mm-cb-row-label">Description</label>' +
            '<input class="mm-cb-input" type="text" data-field="description" value="' + esc(t.description) + '" placeholder="What this theme means" />' +
          '</div>' +
          '<div class="mm-cb-row">' +
            '<label class="mm-cb-row-label">Keywords</label>' +
            '<input class="mm-cb-input" type="text" data-field="keywords" value="' + esc((t.keywords || []).join(', ')) + '" placeholder="comma-separated; e.g., workload, hours, overwhelm" />' +
          '</div>' +
          '<div class="mm-cb-stat">' +
            '<span>Matches: <strong>' + countMatches(t) + '</strong> of ' + responses.length + ' responses</span>' +
          '</div>' +
        '</div>'
      ).join('');

      host.querySelectorAll('.mm-cb-card').forEach(card => {
        const id = card.getAttribute('data-id');
        const t = codebook.find(x => x.id === id);
        if (!t) return;
        card.querySelectorAll('.mm-cb-input').forEach(input => {
          input.addEventListener('input', () => {
            const field = input.getAttribute('data-field');
            if (field === 'keywords') t.keywords = input.value.split(',').map(s => s.trim()).filter(s => s);
            else t[field] = input.value;
            saveCodebook();
            // Update match count inline without full repaint
            card.querySelector('.mm-cb-stat strong').textContent = countMatches(t);
          });
        });
        card.querySelector('.mm-cb-remove').addEventListener('click', () => {
          codebook = codebook.filter(x => x.id !== id);
          saveCodebook(); paint();
        });
      });
      updateBadge();
    }
    function updateBadge() {
      document.getElementById('mmCbStatus').textContent = codebook.length + ' theme' + (codebook.length === 1 ? '' : 's') + ' defined';
    }

    function suggestThemes() {
      if (!responses.length) return;
      // Find top bigrams across all open responses, then propose 5
      // themes from the most-frequent phrases.
      const bigramCounts = new Map();
      responses.forEach(r => {
        const toks = tokenizeWithStops(r.text);
        for (let i = 0; i < toks.length - 1; i++) {
          const a = toks[i], b = toks[i + 1];
          if (STOP.has(a) && STOP.has(b)) continue;
          const phrase = a + ' ' + b;
          bigramCounts.set(phrase, (bigramCounts.get(phrase) || 0) + 1);
        }
      });
      const topBigrams = Array.from(bigramCounts.entries())
        .filter(([, c]) => c >= 2)
        .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
        .slice(0, 5);
      if (!topBigrams.length) {
        document.getElementById('mmCbStatus').textContent = 'Not enough repeated phrases to suggest themes.';
        setTimeout(() => updateBadge(), 1800);
        return;
      }
      topBigrams.forEach(([phrase, count]) => {
        const id = 't_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6);
        codebook.push({
          id: id,
          name: phrase.replace(/\b([a-z])/g, m => m.toUpperCase()),
          description: 'Auto-suggested from ' + count + ' co-occurrences',
          keywords: phrase.split(' '),
        });
      });
      saveCodebook(); paint();
    }
    function countMatches(theme) {
      return responses.filter(r => matchesTheme(r.text, theme)).length;
    }
    expose({ lens: 'codebook_builder', themes: codebook.length });
  }

  // ==================================================================
  // LENS: theme_analysis
  // ==================================================================
  function renderThemeAnalysis() {
    if (!responses.length) { empty('Theme Analysis', 'No open-ended responses to analyze.'); return; }
    if (!codebook.length) {
      // Auto-discover themes
      const bigramCounts = new Map();
      responses.forEach(r => {
        const toks = tokenizeWithStops(r.text);
        for (let i = 0; i < toks.length - 1; i++) {
          const a = toks[i], b = toks[i + 1];
          if (STOP.has(a) && STOP.has(b)) continue;
          bigramCounts.set(a + ' ' + b, (bigramCounts.get(a + ' ' + b) || 0) + 1);
        }
      });
      const topBigrams = Array.from(bigramCounts.entries()).filter(([, c]) => c >= 2).sort((a, b) => b[1] - a[1]).slice(0, 6);
      document.getElementById('mmTaTitle').textContent = 'Auto-discovered themes (codebook empty)';
      document.getElementById('mmTaSub').textContent   = 'Define a codebook in Codebook Builder to apply your own themes.';
      const taAutoTable = topBigrams.length
        ? '<table class="mm-table"><thead><tr><th>Candidate theme</th><th class="mm-num">Co-occurrences</th></tr></thead><tbody>' +
          topBigrams.map(([p, c]) => '<tr><td>' + esc(p) + '</td><td class="mm-num">' + c + '</td></tr>').join('') +
          '</tbody></table>'
        : '<p class="mm-flat">No repeated phrases found in the open-ended responses.</p>';
      const taAutoJ = topBigrams.length
        ? topBigrams.length + ' candidate themes surfaced from word pairs that co-occur across responses.'
        : 'No repeated phrases turned up in the open-ended responses.';
      const taAutoPlain   = 'When no codebook exists yet, we scan the qualitative responses for pairs of words that appear together repeatedly. Those pairs are often the seed of a real theme.';
      const taAutoResearch= 'Co-occurrence is counted over a tokenized, stop-word-filtered version of each response. The list is descriptive, not validated — it tells you what is being talked about, not what it means.';
      const taAutoClosing = topBigrams.length
        ? 'Open Codebook Builder, turn the candidates that match your research question into real themes (name, description, keywords), then return here to see the themed table.'
        : 'Open Codebook Builder and define themes manually. Your responses may be too short or too varied for word-pair signals to surface.';
      document.getElementById('mmTaBody').innerHTML   = mmUnifyBody({ summaryTitle: 'Auto-discovered themes', judgment: taAutoJ, plainPara: taAutoPlain, researchPara: taAutoResearch, body: taAutoTable, closingPara: taAutoClosing });
      document.getElementById('mmTaInterp').innerHTML = mmUnifyInterp({ judgment: taAutoJ, plainPara: taAutoPlain, closingPara: taAutoClosing });
      expose({ lens: 'theme_analysis', autoSuggested: topBigrams.length });
      return;
    }
    const themed = codebook.map(t => {
      const matches = responses.filter(r => matchesTheme(r.text, t));
      return { theme: t, n: matches.length, rate: responses.length ? matches.length / responses.length : 0, matches: matches };
    }).sort((a, b) => b.n - a.n);
    const untagged = responses.filter(r => !codebook.some(t => matchesTheme(r.text, t))).length;

    document.getElementById('mmTaTitle').textContent = codebook.length + ' theme' + (codebook.length === 1 ? '' : 's') + ' applied';
    document.getElementById('mmTaSub').textContent   = responses.length + ' responses · ' + (responses.length - untagged) + ' tagged · ' + untagged + ' untagged';
    const maxN = themed[0] ? themed[0].n : 1;
    const taTable =
      '<table class="mm-table">' +
        '<thead><tr><th>Theme</th><th>Description</th><th class="mm-num">Responses</th><th class="mm-num">% of total</th><th></th></tr></thead>' +
        '<tbody>' +
          themed.map(t => '<tr>' +
            '<td><strong>' + esc(t.theme.name || 'Untitled') + '</strong></td>' +
            '<td class="mm-dim">' + esc(t.theme.description || '') + '</td>' +
            '<td class="mm-num">' + t.n + '</td>' +
            '<td class="mm-num">' + Math.round(t.rate * 100) + '%</td>' +
            '<td><div class="mm-bar"><span style="width:' + Math.round((t.n / maxN) * 100) + '%"></span></div></td>' +
          '</tr>').join('') +
        '</tbody>' +
      '</table>';
    const top = themed[0];
    const taJudgment = top && top.n
      ? '"' + (top.theme.name || 'untitled') + '" is the most prevalent theme (' + top.n + ' responses, ' + Math.round(top.rate * 100) + '% of all open-ended answers).'
      : 'No responses match any defined theme.';
    const taPlain = 'Each row is one theme from your codebook with how many qualitative responses mentioned its keywords. The bar shows the relative prevalence across themes.';
    const taResearch = 'A response is tagged with a theme if any of the theme\'s keywords appear in its text (case- and stem-insensitive). A response can be tagged with more than one theme, so the % columns may sum to more than 100%.';
    const taClosing = !top || !top.n
      ? 'No responses match any defined theme. Refine the keywords on each theme, or add new themes that capture what respondents actually said.'
      : untagged
        ? untagged + ' response' + (untagged === 1 ? '' : 's') + ' did not match any theme. Consider expanding the codebook (extra keywords, an additional theme) or accepting that some responses are off-topic.'
        : 'Every response matched at least one theme. The codebook is doing a good job covering the qualitative content.';
    document.getElementById('mmTaBody').innerHTML   = mmUnifyBody({ summaryTitle: 'Theme prevalence summary', judgment: taJudgment, plainPara: taPlain, researchPara: taResearch, body: taTable, closingPara: taClosing });
    document.getElementById('mmTaInterp').innerHTML = mmUnifyInterp({ judgment: taJudgment, plainPara: taPlain, closingPara: taClosing });
    expose({ lens: 'theme_analysis', themes: themed.map(t => ({ name: t.theme.name, n: t.n, rate: t.rate })), untagged: untagged });
  }

  // ==================================================================
  // LENS: quote_extractor
  // ==================================================================
  function renderQuoteExtractor() {
    if (!responses.length) { empty('Quote Extractor', 'No open-ended responses to extract from.'); return; }
    if (!codebook.length)  { empty('Quote Extractor', 'No codebook yet. Define themes in Codebook Builder first.'); return; }

    const perTheme = codebook.map(t => {
      const matches = responses.filter(r => matchesTheme(r.text, t));
      // Score each match by keyword density × length
      const scored = matches.map(r => {
        const toks = tokenize(r.text);
        let hits = 0;
        toks.forEach(w => { if (t.keywords.some(kw => w.indexOf(String(kw).toLowerCase()) !== -1)) hits++; });
        return { row: r.row, varName: r.varName, text: r.text, score: hits + Math.log(Math.max(toks.length, 1)) };
      }).sort((a, b) => b.score - a.score);
      return { theme: t, quotes: scored.slice(0, 5) };
    });

    document.getElementById('mmQeTitle').textContent = 'Representative quotes per theme';
    document.getElementById('mmQeSub').textContent   = perTheme.length + ' themes · up to 5 quotes per theme, ranked by keyword density × length';
    const qeBody =
      perTheme.map(b =>
        '<div class="mm-qe-card">' +
          '<h4>' + esc(b.theme.name || 'Untitled') + '</h4>' +
          (b.theme.description ? '<p class="mm-qe-desc">' + esc(b.theme.description) + '</p>' : '') +
          (b.quotes.length
            ? '<ul class="mm-qe-quotes">' +
                b.quotes.map(q => '<li class="mm-qe-quote"><span class="mm-qe-mark" aria-hidden="true">"</span><span class="mm-qe-text">' + esc(q.text) + '</span><span class="mm-qe-cite">— ' + esc(q.varName) + ', row ' + (q.row + 1) + '</span></li>').join('') +
              '</ul>'
            : '<p class="mm-qe-empty">No responses match this theme.</p>') +
        '</div>'
      ).join('');
    const totalQuotes = perTheme.reduce((s, b) => s + b.quotes.length, 0);
    const emptyThemes = perTheme.filter(b => !b.quotes.length).length;
    const qeJudgment  = totalQuotes + ' representative quote' + (totalQuotes === 1 ? '' : 's') + ' selected across ' + perTheme.length + ' theme' + (perTheme.length === 1 ? '' : 's') + '.';
    const qePlain     = 'For each theme in your codebook, the engine surfaces up to five responses that best illustrate the theme — ranked by how many keywords they hit and how rich the response was.';
    const qeResearch  = 'Quotes are scored as (keyword hit count) + log(token length). The log term gives weight to longer, more developed responses without letting one very long answer dominate. The top 5 by score are shown per theme; ties break by appearance order in the dataset.';
    const qeClosing   = emptyThemes
      ? emptyThemes + ' theme' + (emptyThemes === 1 ? '' : 's') + ' produced no matching quotes. Tighten or expand the keywords on those themes, or accept that no respondent spoke to them.'
      : 'Pull these into the Findings section of the report so each theme is grounded in respondents\' own words rather than only a count.';
    document.getElementById('mmQeBody').innerHTML   = mmUnifyBody({ summaryTitle: 'Quote selection summary', judgment: qeJudgment, plainPara: qePlain, researchPara: qeResearch, body: qeBody, closingPara: qeClosing });
    document.getElementById('mmQeInterp').innerHTML = mmUnifyInterp({ judgment: qeJudgment, plainPara: qePlain, closingPara: qeClosing });
    expose({ lens: 'quote_extractor', byTheme: perTheme.map(b => ({ name: b.theme.name, quotes: b.quotes.length })) });
  }

  // ==================================================================
  // LENS: theme_by_group
  // ==================================================================
  function setupThemeByGroup() {
    const sel = document.getElementById('mmTgGroup');
    const cats = allVars.filter(isCategorical);
    sel.innerHTML = '';
    if (!cats.length) {
      const o = document.createElement('option'); o.value = ''; o.textContent = '— no categorical variables —';
      sel.appendChild(o); sel.disabled = true;
    } else cats.forEach(v => { const o = document.createElement('option'); o.value = v.name; o.textContent = v.name; sel.appendChild(o); });
    document.getElementById('mmTgRun').addEventListener('click', renderThemeByGroup);
  }
  function renderThemeByGroup() {
    if (!codebook.length) { empty('Theme by Group', 'Define themes in Codebook Builder first.'); return; }
    const grp = allVars.find(v => v.name === document.getElementById('mmTgGroup').value);
    if (!grp) return;
    // For each level × theme, count matches
    const levels = Array.from(new Set(grp.values.filter(v => !isMissing(v)).map(String))).sort();
    if (levels.length < 2) { empty('Theme by Group', 'Pick a categorical with at least 2 levels.'); return; }
    // Build per-respondent theme tags (any open response on that row matching any theme keyword)
    const matrix = levels.map(level => {
      const counts = codebook.map(theme => {
        const rowsInLevel = [];
        for (let i = 0; i < rowCount; i++) if (String(grp.values[i]) === level) rowsInLevel.push(i);
        const matched = rowsInLevel.filter(i => responses.some(r => r.row === i && matchesTheme(r.text, theme)));
        return matched.length;
      });
      return { level: level, counts: counts, n: grp.values.filter(v => String(v) === level).length };
    });

    document.getElementById('mmTgTitle').textContent = 'Theme prevalence by ' + grp.name;
    document.getElementById('mmTgSub').textContent   = codebook.length + ' themes × ' + levels.length + ' levels';
    const maxCount = matrix.reduce((m, row) => Math.max(m, Math.max.apply(null, row.counts)), 1);
    const tgTable =
      '<table class="mm-table mm-table-grid">' +
        '<thead><tr><th>Theme</th>' + matrix.map(r => '<th>' + esc(r.level) + '<span class="mm-th-sub">(n=' + r.n + ')</span></th>').join('') + '</tr></thead>' +
        '<tbody>' +
          codebook.map((t, ti) => '<tr>' +
            '<td><strong>' + esc(t.name || 'Untitled') + '</strong></td>' +
            matrix.map(r => {
              const c = r.counts[ti];
              const intensity = maxCount ? c / maxCount : 0;
              return '<td class="mm-cell" style="--mm-intensity:' + intensity.toFixed(2) + '">' +
                '<div class="mm-cell-num">' + c + '</div>' +
                '<div class="mm-cell-pct">' + (r.n ? Math.round(c / r.n * 100) : 0) + '%</div>' +
              '</td>';
            }).join('') +
          '</tr>').join('') +
        '</tbody>' +
      '</table>';
    // Find the theme with the largest cross-group spread to seed the judgment.
    let widestThemeIdx = 0, widestSpread = -1;
    codebook.forEach((_, ti) => {
      const rates = matrix.map(r => r.n ? r.counts[ti] / r.n : 0);
      const sp = Math.max.apply(null, rates) - Math.min.apply(null, rates);
      if (sp > widestSpread) { widestSpread = sp; widestThemeIdx = ti; }
    });
    const widestTheme = codebook[widestThemeIdx];
    const tgJudgment = widestSpread > 0
      ? '"' + esc(widestTheme.name || 'untitled') + '" shows the widest spread across ' + grp.name + ' levels (' + Math.round(widestSpread * 100) + 'pp gap top-to-bottom).'
      : 'No theme shows meaningful variation across ' + grp.name + ' levels.';
    const tgPlain    = 'Each cell counts how many respondents in that group level mention that theme, with the within-group percentage below. Heavier tint signals a higher count.';
    const tgResearch = 'Counts are computed only from rows where the group variable is non-missing. Percentages use the group n (not the total) as the denominator, so they read as "share of this group that mentioned this theme".';
    const tgClosing  = widestSpread > 0.10
      ? 'A theme that is very prevalent in one group but rare in another is the kind of pattern worth investigating. Pair this view with the Joint Display lens to align it with quantitative findings.'
      : 'Themes look fairly even across groups. Differences in qualitative experience by ' + grp.name + ' appear to be modest in this dataset.';
    document.getElementById('mmTgBody').innerHTML   = mmUnifyBody({ summaryTitle: 'Theme-by-group summary', judgment: tgJudgment, plainPara: tgPlain, researchPara: tgResearch, body: tgTable, closingPara: tgClosing });
    document.getElementById('mmTgInterp').innerHTML = mmUnifyInterp({ judgment: tgJudgment, plainPara: tgPlain, closingPara: tgClosing });
    expose({ lens: 'theme_by_group', group: grp.name, matrix: matrix });
  }

  // ==================================================================
  // LENS: joint_display
  // ==================================================================
  function renderJointDisplay() {
    // Read saved blocks from localStorage
    let blocks = [];
    try {
      const raw = window.localStorage.getItem('relicheck.report.' + projectId + '.default');
      if (raw) {
        const parsed = JSON.parse(raw);
        if (parsed && Array.isArray(parsed.blocks)) blocks = parsed.blocks;
      }
    } catch (e) {}
    const quantBlocks = blocks.filter(b => b.app !== 'note' && b.app !== 'open_ended_summary');
    const themeRows = codebook.map(t => {
      const n = responses.filter(r => matchesTheme(r.text, t)).length;
      return { name: t.name || 'Untitled', n: n, rate: responses.length ? n / responses.length : 0 };
    }).sort((a, b) => b.n - a.n);

    document.getElementById('mmJdSub').textContent = quantBlocks.length + ' quant findings · ' + themeRows.length + ' qual themes';
    const jdGrid =
      '<div class="mm-jd-grid">' +
        '<div class="mm-jd-col">' +
          '<h4 class="mm-block-h">Quantitative findings (saved blocks)</h4>' +
          (quantBlocks.length
            ? '<ul class="mm-jd-list">' + quantBlocks.map(b =>
                '<li><div class="mm-jd-eyebrow">' + esc(b.appName || b.app) + '</div><div class="mm-jd-line">' + esc(b.summary || '—') + '</div></li>'
              ).join('') + '</ul>'
            : '<p class="mm-flat">No quant findings saved yet. Run any quantitative analysis app and click Save to Report.</p>') +
        '</div>' +
        '<div class="mm-jd-col">' +
          '<h4 class="mm-block-h">Qualitative themes</h4>' +
          (themeRows.length
            ? '<ul class="mm-jd-list">' + themeRows.map(t =>
                '<li><div class="mm-jd-eyebrow">' + t.n + ' responses · ' + Math.round(t.rate * 100) + '%</div><div class="mm-jd-line">' + esc(t.name) + '</div></li>'
              ).join('') + '</ul>'
            : '<p class="mm-flat">No themes yet. Define a codebook in Codebook Builder.</p>') +
        '</div>' +
      '</div>';
    const jdReady    = quantBlocks.length && themeRows.length;
    const jdJudgment = jdReady
      ? quantBlocks.length + ' quantitative finding' + (quantBlocks.length === 1 ? '' : 's') + ' lined up next to ' + themeRows.length + ' qualitative theme' + (themeRows.length === 1 ? '' : 's') + '.'
      : 'Joint display is not ready yet — need both saved quant findings and a populated codebook.';
    const jdPlain    = 'A joint display puts your numerical findings and your themes side-by-side so the integration becomes visible. The left column reads as "what the numbers say"; the right column reads as "what people said".';
    const jdResearch = 'Quant findings are read from the local saved-blocks store (the report\'s default report). Qualitative themes are pulled from the project codebook with the share of qualitative responses that mention each theme.';
    const jdClosing  = jdReady
      ? 'Move to Integration Quality to assess whether the qual themes corroborate, complicate, or contradict the quant findings. Use the Reporting section to compose the joint narrative.'
      : !quantBlocks.length
        ? 'Run a quantitative analysis (Strength Index, T-Test, ANOVA, etc.) and click Save to Report so a finding appears here.'
        : 'Open Codebook Builder and define at least one theme so the qualitative side has content.';
    document.getElementById('mmJdBody').innerHTML   = mmUnifyBody({ summaryTitle: 'Joint display summary', judgment: jdJudgment, plainPara: jdPlain, researchPara: jdResearch, body: jdGrid, closingPara: jdClosing });
    document.getElementById('mmJdInterp').innerHTML = mmUnifyInterp({ judgment: jdJudgment, plainPara: jdPlain, closingPara: jdClosing });
    expose({ lens: 'joint_display', quantBlocks: quantBlocks.length, themes: themeRows.length });
  }

  // ==================================================================
  // LENS: integration_quality
  // ==================================================================
  function renderIntegrationQuality() {
    let blocks = [];
    try {
      const raw = window.localStorage.getItem('relicheck.report.' + projectId + '.default');
      if (raw) { const parsed = JSON.parse(raw); if (parsed && Array.isArray(parsed.blocks)) blocks = parsed.blocks; }
    } catch (e) {}
    const quantBlocks = blocks.filter(b => b.app !== 'note' && b.app !== 'open_ended_summary');
    const sigCount = quantBlocks.filter(b => {
      const p = b.payload || {};
      return (p.result && p.result.p != null && p.result.p < 0.05) ||
             (p.strength != null && p.strength >= 80) ||
             (p.result && p.result.tone === 'strong');
    }).length;
    const themeCount = codebook.length;
    const themedRespRate = responses.length
      ? responses.filter(r => codebook.some(t => matchesTheme(r.text, t))).length / responses.length
      : 0;

    // Score
    let score = 0;
    if (quantBlocks.length >= 2)  score += 25;
    else if (quantBlocks.length === 1) score += 12;
    if (themeCount >= 3)          score += 25;
    else if (themeCount >= 1)     score += 12;
    if (themedRespRate >= 0.50)   score += 25;
    else if (themedRespRate >= 0.20) score += 12;
    if (sigCount && themeCount)   score += 25;
    score = Math.min(100, score);

    let band, tone;
    if      (score >= 80) { band = 'Strong integration';      tone = 'strong'; }
    else if (score >= 60) { band = 'Workable integration';    tone = 'ok'; }
    else if (score >= 30) { band = 'Partial integration';     tone = 'warn'; }
    else                  { band = 'Weak integration';        tone = 'alert'; }

    document.getElementById('mmIqTitle').textContent = 'Integration quality: ' + band;
    document.getElementById('mmIqSub').textContent   = score + '/100 · ' + quantBlocks.length + ' quant blocks · ' + themeCount + ' themes · ' + Math.round(themedRespRate * 100) + '% of qual responses tagged';
    document.getElementById('mmIqScorecard').innerHTML =
      '<div class="mm-iq-score" data-tone="' + tone + '">' +
        '<div class="mm-iq-score-num">' + score + '</div>' +
        '<div class="mm-iq-score-meta"><div class="mm-iq-score-band">' + band + '</div><div class="mm-iq-score-sub">' + quantBlocks.length + ' quant · ' + themeCount + ' qual themes</div></div>' +
      '</div>';
    const iqComponents =
      '<h4 class="mm-block-h">Components</h4>' +
      '<ul class="mm-iq-list">' +
        '<li data-tone="' + (quantBlocks.length >= 2 ? 'ok' : quantBlocks.length === 1 ? 'warn' : 'alert') + '"><span class="mm-iq-pip"></span>Quant breadth: ' + quantBlocks.length + ' saved finding' + (quantBlocks.length === 1 ? '' : 's') + ' (target ≥ 2)</li>' +
        '<li data-tone="' + (themeCount >= 3 ? 'ok' : themeCount >= 1 ? 'warn' : 'alert') + '"><span class="mm-iq-pip"></span>Qual breadth: ' + themeCount + ' theme' + (themeCount === 1 ? '' : 's') + ' (target ≥ 3)</li>' +
        '<li data-tone="' + (themedRespRate >= 0.50 ? 'ok' : themedRespRate >= 0.20 ? 'warn' : 'alert') + '"><span class="mm-iq-pip"></span>Qual coverage: ' + Math.round(themedRespRate * 100) + '% of responses tagged by some theme (target ≥ 50%)</li>' +
        '<li data-tone="' + (sigCount && themeCount ? 'ok' : 'warn') + '"><span class="mm-iq-pip"></span>Quant × Qual presence: ' + (sigCount && themeCount ? 'both present' : 'one side missing') + ' (target: both)</li>' +
      '</ul>';
    const iqJudgment = band + ' (' + score + '/100). ' + quantBlocks.length + ' quantitative block' + (quantBlocks.length === 1 ? '' : 's') + ', ' + themeCount + ' qualitative theme' + (themeCount === 1 ? '' : 's') + ', ' + Math.round(themedRespRate * 100) + '% qual coverage.';
    const iqPlain    = 'Integration quality scores how well your quantitative and qualitative work add up to a defensible mixed-methods study. The components above show what is holding the score up or pulling it down.';
    const iqResearch = 'The composite awards up to 25 points each on quant breadth (saved findings), qual breadth (codebook themes), qual coverage (share of qualitative responses that match some theme), and joint presence (at least one strong quant finding plus an active codebook). The bands are 80+ Strong, 60–79 Workable, 30–59 Partial, < 30 Weak.';
    const iqClosing  =
      score >= 80 ? 'Strong integration. Quant and qual breadth are both adequate; you can confidently triangulate findings across methods. Move on to Reporting with the integration story in front.' :
      score >= 60 ? 'Workable integration. Some triangulation is possible; expand whichever side is thinner before publishing strong mixed-methods claims.' :
      score >= 30 ? 'Partial integration. One side of the methods is doing most of the work; the other side is too thin to claim mixed-methods rigor. Strengthen the thin side before reporting.' :
                    'Weak integration. Either run more analyses or develop a fuller codebook before describing the work as mixed-methods.';
    document.getElementById('mmIqBody').innerHTML   = mmUnifyBody({ summaryTitle: 'Integration quality summary', judgment: iqJudgment, plainPara: iqPlain, researchPara: iqResearch, body: iqComponents, closingPara: iqClosing });
    document.getElementById('mmIqInterp').innerHTML = mmUnifyInterp({ judgment: iqJudgment, plainPara: iqPlain, closingPara: iqClosing });
    expose({ lens: 'integration_quality', score: score, band: band, quantBlocks: quantBlocks.length, themeCount: themeCount, themedRespRate: themedRespRate });
  }

  // ==================================================================
  // LENS: qual_to_quant
  // ==================================================================
  function renderQualToQuant() {
    if (!codebook.length) { empty('Qual → Quant', 'No codebook yet. Define themes in Codebook Builder first.'); return; }
    if (!responses.length) { empty('Qual → Quant', 'No open-ended responses to convert.'); return; }
    // Build a per-row matrix of theme indicators (any response on that row matching the theme)
    const idVar = allVars.find(v => hasType(v, 'id'));
    const ids = [];
    for (let i = 0; i < rowCount; i++) ids.push(idVar ? idVar.values[i] : ('row_' + (i + 1)));
    const matrix = ids.map((_, i) => codebook.map(t => responses.some(r => r.row === i && matchesTheme(r.text, t)) ? 1 : 0));
    const themeNames = codebook.map(t => t.name || 'Untitled');
    const counts = themeNames.map((_, j) => matrix.reduce((s, row) => s + row[j], 0));

    document.getElementById('mmQqTitle').textContent = 'Codebook → indicator variables';
    document.getElementById('mmQqSub').textContent   = codebook.length + ' themes × ' + rowCount + ' respondents';
    const qqTable =
      '<table class="mm-table mm-table-qq">' +
        '<thead><tr><th>Respondent</th>' + themeNames.map(n => '<th>' + esc(n) + '</th>').join('') + '</tr></thead>' +
        '<tbody>' +
          matrix.slice(0, 25).map((row, i) => '<tr>' +
            '<td>' + esc(String(ids[i])) + '</td>' +
            row.map(v => '<td class="mm-num">' + v + '</td>').join('') +
          '</tr>').join('') +
          (matrix.length > 25 ? '<tr><td colspan="' + (themeNames.length + 1) + '" class="mm-table-overflow">… and ' + (matrix.length - 25) + ' more rows. Download the CSV to see all.</td></tr>' : '') +
        '</tbody>' +
        '<tfoot><tr><th>Total (1s)</th>' + counts.map(c => '<td class="mm-num"><strong>' + c + '</strong></td>').join('') + '</tr></tfoot>' +
      '</table>';
    const totalOnes = counts.reduce((s, c) => s + c, 0);
    const respondentsTagged = matrix.filter(row => row.some(v => v === 1)).length;
    const taggedRate = rowCount ? respondentsTagged / rowCount : 0;
    const qqJudgment = themeNames.length + ' indicator variable' + (themeNames.length === 1 ? '' : 's') + ' built across ' + rowCount + ' respondents. ' + Math.round(taggedRate * 100) + '% of respondents matched at least one theme.';
    const qqPlain    = 'This converts each codebook theme into a 0/1 column per respondent — a "did this person\'s open-ended answer touch this theme" indicator. The table previews the first 25 respondents; the CSV exports all rows.';
    const qqResearch = 'A cell is 1 if any of the respondent\'s open-ended answers contain at least one keyword from the theme (stem- and case-insensitive). Use these indicators as predictors or outcomes in regression, t-tests, or chi-square in the Inferential section.';
    const qqClosing  = respondentsTagged === 0
      ? 'No respondents matched any theme. The codebook may need broader keywords, or the qualitative content may not align with the themes you defined.'
      : 'Download the CSV and load it through Evidence Intake as an additional dataset, or paste the indicator columns into your quantitative dataset to use these themes alongside the survey items.';
    document.getElementById('mmQqBody').innerHTML   = mmUnifyBody({ summaryTitle: 'Qual → Quant summary', judgment: qqJudgment, plainPara: qqPlain, researchPara: qqResearch, body: qqTable, closingPara: qqClosing });

    function buildCsv() {
      const header = ['respondent_id', ...themeNames.map(safeColName)].join(',');
      const rows = matrix.map((row, i) => [csvCell(ids[i]), ...row].join(','));
      return [header, ...rows].join('\n');
    }
    function safeColName(s) { return 'theme_' + String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'theme'; }
    function csvCell(s) {
      const str = String(s == null ? '' : s);
      if (/[",\n]/.test(str)) return '"' + str.replace(/"/g, '""') + '"';
      return str;
    }
    document.getElementById('mmQqExport').addEventListener('click', () => {
      const blob = new Blob([buildCsv()], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a'); a.href = url; a.download = 'qual-to-quant.csv'; a.style.display = 'none';
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      setTimeout(() => URL.revokeObjectURL(url), 100);
    });
    document.getElementById('mmQqCopy').addEventListener('click', () => {
      const text = buildCsv();
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text);
    });
    document.getElementById('mmQqInterp').innerHTML = mmUnifyInterp({ judgment: qqJudgment, plainPara: qqPlain, closingPara: qqClosing });
    expose({ lens: 'qual_to_quant', respondents: rowCount, themes: themeNames, counts: counts });
  }

  // ==================================================================
  // Helpers
  // ==================================================================
  function empty(title, msg) {
    const lensEl = document.querySelector('.mm-lens[data-lens="' + lens + '"]');
    if (!lensEl) return;
    const titleEl = lensEl.querySelector('h3');
    const subEl   = lensEl.querySelector('.mm-sub');
    const body    = lensEl.querySelector('.mm-body');
    if (titleEl) titleEl.textContent = title;
    if (subEl)   subEl.textContent   = msg;
    if (body)    body.innerHTML      = '<p class="mm-flat">' + esc(msg) + '</p>';
    const interp = lensEl.querySelector('.mm-interp');
    if (interp) interp.textContent = '';
    expose({ lens: lens, empty: true });
  }
  function expose(payload) {
    window.RELICHECK_APP_STATE = Object.assign({
      app_key:  'mm_analysis',
      app_name: 'MM Analysis (' + lens + ')',
      summary:  summarize(payload),
      lens:     lens,
      computed_at: new Date().toISOString(),
    }, payload);
  }
  function summarize(p) {
    if (p.lens === 'codebook_builder')      return (p.themes || 0) + ' themes in codebook.';
    if (p.lens === 'theme_analysis')        return (p.themes ? p.themes.length : (p.autoSuggested || 0)) + ' themes; ' + (p.untagged != null ? p.untagged + ' untagged' : 'auto-suggested');
    if (p.lens === 'quote_extractor')       return 'Quotes extracted across ' + (p.byTheme ? p.byTheme.length : 0) + ' themes.';
    if (p.lens === 'theme_by_group')        return 'Theme × ' + (p.group || '—') + ' cross-tab.';
    if (p.lens === 'joint_display')         return (p.quantBlocks || 0) + ' quant blocks + ' + (p.themes || 0) + ' themes.';
    if (p.lens === 'integration_quality')   return 'Integration ' + (p.score || 0) + '/100 (' + (p.band || '—') + ')';
    if (p.lens === 'qual_to_quant')         return 'CSV: ' + (p.respondents || 0) + ' rows × ' + ((p.themes || []).length) + ' theme columns.';
    return 'MM analysis';
  }
})();
