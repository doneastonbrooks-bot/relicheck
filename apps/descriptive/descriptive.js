// ReliCheck Descriptive Suite
// -------------------------------------------------------------------
// One engine, six lenses on the same dataset.
//   frequencies        count + cumulative % for a categorical/Likert var
//   cross_tabs         2D contingency with row/col/total % + heat tint
//   distributions      histogram + n/mean/median/SD/min/max/skew/kurt + quantiles
//   group_summaries    per-group means with deviation bars from grand mean
//   top_bottom_items   rank all Likert items, flag low-variance / ceiling
//   scale_scores       composite from picked items + α + distribution
//
// Mount-page contract:
//   window.DESCRIPTIVE_DATASET   (required)
//   window.DESCRIPTIVE_LENS      (required)

(function () {
  'use strict';

  // ==================================================================
  // Dataset resolution
  // ==================================================================
  let dataset = window.DESCRIPTIVE_DATASET;
  let datasetSource = 'sample';
  const projectId = (window.RELICHECK_PROJECT_ID && String(window.RELICHECK_PROJECT_ID)) || 'untitled-project';
  try {
    const stored = window.localStorage.getItem('relicheck.dataset.' + projectId);
    if (stored) {
      const parsed = JSON.parse(stored);
      if (parsed && parsed.payload && parsed.payload.dataset) {
        dataset = parsed.payload.dataset;
        datasetSource = 'uploaded';
      }
    }
  } catch (e) { /* noop */ }

  const lens = window.DESCRIPTIVE_LENS || 'frequencies';

  if (!dataset || !Array.isArray(dataset.variables)) {
    document.getElementById('dxEmpty').hidden = false;
    return;
  }

  const allVars  = dataset.variables;
  const rowCount = dataset.rowCount || (allVars[0] ? allVars[0].values.length : 0);

  // ==================================================================
  // Helpers
  // ==================================================================
  function num(v) { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function isMissing(v) { return v === '' || v == null; }
  function mean(arr) { return arr.length ? arr.reduce((s, v) => s + v, 0) / arr.length : 0; }
  function variance(arr) {
    if (arr.length < 2) return 0;
    const m = mean(arr);
    return arr.reduce((s, v) => s + (v - m) * (v - m), 0) / (arr.length - 1);
  }
  function sd(arr) { return Math.sqrt(variance(arr)); }
  function median(arr) {
    if (!arr.length) return 0;
    const s = arr.slice().sort((a, b) => a - b);
    const mid = Math.floor(s.length / 2);
    return s.length % 2 ? s[mid] : (s[mid - 1] + s[mid]) / 2;
  }
  function quantile(arr, q) {
    if (!arr.length) return null;
    const s = arr.slice().sort((a, b) => a - b);
    const pos = (s.length - 1) * q;
    const base = Math.floor(pos);
    const rest = pos - base;
    return s[base + 1] != null ? s[base] + rest * (s[base + 1] - s[base]) : s[base];
  }
  function mode(arr) {
    const counts = new Map();
    arr.forEach(v => counts.set(v, (counts.get(v) || 0) + 1));
    let best = null, bestCount = 0;
    counts.forEach((c, v) => { if (c > bestCount) { bestCount = c; best = v; } });
    return best;
  }
  function skewness(arr) {
    if (arr.length < 3) return 0;
    const m = mean(arr);
    const v = variance(arr);
    if (v === 0) return 0;
    const s = Math.sqrt(v);
    const sum3 = arr.reduce((a, x) => a + Math.pow((x - m) / s, 3), 0);
    return sum3 / arr.length;
  }
  function kurtosis(arr) {
    if (arr.length < 4) return 0;
    const m = mean(arr);
    const v = variance(arr);
    if (v === 0) return 0;
    const s = Math.sqrt(v);
    const sum4 = arr.reduce((a, x) => a + Math.pow((x - m) / s, 4), 0);
    return (sum4 / arr.length) - 3;
  }
  function hasType(v, t) { return v.types && v.types.indexOf(t) !== -1; }
  function isNumericish(v)  { return hasType(v, 'numeric') || hasType(v, 'likert'); }
  function isLikertOnly(v)  { return hasType(v, 'likert'); }
  function isCategorical(v) { return hasType(v, 'categorical'); }
  function fmt(x, d) { if (x == null || !isFinite(x)) return '—'; return Number(x).toFixed(d == null ? 2 : d); }
  function pct(x) { return (x == null) ? '—' : Math.round(x * 100) + '%'; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  // ==================================================================
  // Source ribbon + lens-setup show/hide
  // ==================================================================
  const sourceRibbon = document.getElementById('dxSource');
  if (sourceRibbon) {
    sourceRibbon.setAttribute('data-source', datasetSource);
    document.getElementById('dxSourceLabel').textContent = datasetSource === 'uploaded' ? 'Uploaded data' : 'Sample data';
    document.getElementById('dxSourceMeta').textContent  = (dataset.source || 'Dataset') + '  ·  ' + rowCount + ' rows';
  }
  document.querySelectorAll('.dx-lens-setup').forEach(el => {
    el.hidden = el.getAttribute('data-lens') !== lens;
  });

  // ==================================================================
  // Variable population per lens
  // ==================================================================
  function fillSel(sel, vars, prevValue) {
    sel.innerHTML = '';
    if (!vars.length) {
      const o = document.createElement('option'); o.textContent = '— no matching variables —'; o.value = '';
      sel.appendChild(o); sel.disabled = true; return;
    }
    sel.disabled = false;
    vars.forEach(v => {
      const o = document.createElement('option'); o.value = v.name; o.textContent = v.name;
      sel.appendChild(o);
    });
    if (prevValue && vars.some(v => v.name === prevValue)) sel.value = prevValue;
  }

  switch (lens) {
    case 'frequencies':
      fillSel(document.getElementById('dxFreqVar'), allVars.filter(v => isCategorical(v) || isLikertOnly(v)));
      break;
    case 'cross_tabs':
      fillSel(document.getElementById('dxCtRow'), allVars.filter(isCategorical));
      fillSel(document.getElementById('dxCtCol'), allVars.filter(isCategorical));
      break;
    case 'distributions':
      fillSel(document.getElementById('dxDistVar'), allVars.filter(isNumericish));
      break;
    case 'group_summaries':
      fillSel(document.getElementById('dxGsOutcome'), allVars.filter(isNumericish));
      fillSel(document.getElementById('dxGsGroup'),   allVars.filter(isCategorical));
      break;
    case 'top_bottom_items':
      // no inputs
      break;
    case 'scale_scores':
      buildScaleChecklist();
      break;
  }
  function buildScaleChecklist() {
    const host = document.getElementById('dxScaleItems');
    if (!host) return;
    const items = allVars.filter(isLikertOnly);
    host.innerHTML = '';
    if (!items.length) {
      host.innerHTML = '<p class="dx-flat">No Likert items in this dataset.</p>';
      return;
    }
    items.forEach(v => {
      const id = 'dxScaleItem_' + v.name.replace(/[^a-z0-9]/gi, '_');
      const row = document.createElement('label');
      row.className = 'dx-checklist-row';
      row.setAttribute('for', id);
      row.innerHTML =
        '<input type="checkbox" id="' + id + '" value="' + esc(v.name) + '" />' +
        '<span class="dx-checklist-name">' + esc(v.name) + '</span>';
      host.appendChild(row);
    });
  }

  // ==================================================================
  // Lens dispatchers — every lens AUTO-COMPUTES on load. Run buttons
  // still work (re-run after the user changes a picker) but the user
  // never has to click to see the first result. Per UX feedback:
  // "users shouldn't have to ask for frequencies / means / distributions
  // — that should happen automatically."
  // ==================================================================
  if (lens === 'frequencies')      { const b = document.getElementById('dxFreqRun');  if (b) b.addEventListener('click', runFreq);  setTimeout(runFreq, 50); }
  if (lens === 'cross_tabs')       { const b = document.getElementById('dxCtRun');    if (b) b.addEventListener('click', runCt);    setTimeout(runCt, 50); }
  if (lens === 'distributions')    { const b = document.getElementById('dxDistRun');  if (b) b.addEventListener('click', runDist);  setTimeout(runDist, 50); }
  if (lens === 'group_summaries')  { const b = document.getElementById('dxGsRun');    if (b) b.addEventListener('click', runGs);    setTimeout(runGs, 50); }
  if (lens === 'top_bottom_items') { const b = document.getElementById('dxTbRun');    if (b) b.addEventListener('click', runTb);    setTimeout(runTb, 50); }
  if (lens === 'scale_scores')     { const b = document.getElementById('dxScaleRun'); if (b) b.addEventListener('click', runScale); setTimeout(runScale, 50); }
  if (lens === 'missing_data')     { setTimeout(runMissing, 50); }

  function show(headline, sub, bodyHtml, interp) {
    document.getElementById('dxResults').hidden = false;
    document.getElementById('dxLensName').textContent  = nameForLens(lens);
    document.getElementById('dxResultsHeadline').textContent = headline;
    document.getElementById('dxResultsSub').textContent      = sub || '';
    document.getElementById('dxResultsBody').innerHTML       = bodyHtml || '';
    // interp accepts HTML (J/E/M/A footer is HTML); falls back to plain text
    const el = document.getElementById('dxInterp');
    if (el) {
      if (typeof interp === 'string' && interp.indexOf('<') !== -1) el.innerHTML = interp;
      else                                                          el.textContent = interp || '';
    }
  }
  function setStatus(msg) { document.getElementById('dxStatus').textContent = msg || ''; }
  function nameForLens(l) {
    return { frequencies:'Frequencies', cross_tabs:'Cross-Tabs', distributions:'Means &amp; Distributions', group_summaries:'Group Summaries', top_bottom_items:'Top &amp; Bottom Items', scale_scores:'Scale Scores', missing_data:'Missing Data' }[l] || 'Descriptive';
  }

  // ==================================================================
  // Unified template helpers (per [[relicheck-iq-design-template]])
  // -------------------------------------------------------------------
  // Every Descriptive lens renders into the same shape:
  //   tinted .dx-rel-summary  → judgment + plain + research paragraphs
  //   <inner body>            → graph / table(s) the lens computed
  //   accent-soft .dx-rel-closing → single Interpretation paragraph
  // and fills #dxInterp with a J/E/M/A footer:
  //   What this shows · What stands out · What to check next.
  //
  // Lenses pass in the four interpretation strings; the helpers wrap the
  // existing body markup with the standard chrome. This is the cheap
  // refactor: keep the math + table + chart, just standardize the frame.
  // ==================================================================
  function unifyBody(o) {
    return '<div class="dx-rel-summary">' +
             '<h4 class="dx-block-h">' + esc(o.summaryTitle) + '</h4>' +
             '<p class="dx-rel-summary-line"><strong>' + esc(o.judgment) + '</strong></p>' +
             '<div class="dx-rel-paragraphs">' +
               '<div><span class="dx-rel-label">What this table shows.</span> ' + esc(o.plainPara) + '</div>' +
               '<div><span class="dx-rel-label">Research interpretation.</span> ' + esc(o.researchPara) + '</div>' +
             '</div>' +
           '</div>' +
           (o.body || '') +
           '<div class="dx-rel-closing">' +
             '<h4 class="dx-block-h">Interpretation</h4>' +
             '<p>' + esc(o.closingPara) + '</p>' +
           '</div>';
  }
  function unifyInterp(o) {
    return '<div class="dx-jema">' +
             '<div class="dx-jema-row"><strong>What this table shows.</strong> ' + esc(o.plainPara) + '</div>' +
             '<div class="dx-jema-row"><strong>What stands out.</strong> ' + esc(o.judgment) + '</div>' +
             '<div class="dx-jema-row"><strong>What to check next.</strong> ' + esc(o.closingPara) + '</div>' +
           '</div>';
  }

  // ==================================================================
  // svgBarChart — quick SVG bar chart so every lens shows BOTH a graph
  // and a table (researchers want both). Per UX feedback. Accepts:
  //   rows: [{ label, value, accent? }, ...]
  //   opts: { height, valueFmt, horizontal, title }
  // Defaults to horizontal bars (room for long category labels).
  // ==================================================================
  function svgBarChart(rows, opts) {
    opts = opts || {};
    if (!rows || !rows.length) return '';
    const horizontal = opts.horizontal !== false;
    const title  = opts.title || '';
    const valueFmt = opts.valueFmt || (v => (v != null ? String(v) : ''));
    const maxVal = Math.max.apply(null, rows.map(r => Math.abs(r.value || 0))) || 1;
    const accent = 'var(--accent, #e85d3a)';
    if (horizontal) {
      const rowH = 28, gap = 6, labelW = 160, valueW = 60;
      const innerW = 380; // bar area width
      const h = rows.length * (rowH + gap) + 14;
      const w = labelW + innerW + valueW + 16;
      let svg = '<svg class="dx-svg-chart" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="' + esc(title || 'chart') + '" preserveAspectRatio="xMinYMin meet">';
      rows.forEach((r, i) => {
        const y = 10 + i * (rowH + gap);
        const v = r.value || 0;
        const bw = Math.max(2, (Math.abs(v) / maxVal) * innerW);
        svg += '<text x="' + (labelW - 8) + '" y="' + (y + rowH / 2 + 4) + '" text-anchor="end" font-size="12" fill="currentColor" style="opacity:0.78;">' + esc(r.label) + '</text>';
        svg += '<rect x="' + labelW + '" y="' + y + '" width="' + bw.toFixed(1) + '" height="' + rowH + '" rx="3" fill="' + (r.accent || accent) + '" opacity="0.85"/>';
        svg += '<text x="' + (labelW + bw + 6) + '" y="' + (y + rowH / 2 + 4) + '" font-size="12" font-weight="600" fill="currentColor">' + esc(valueFmt(v)) + '</text>';
      });
      svg += '</svg>';
      return svg;
    } else {
      // Vertical bars
      const barW = 32, gap = 8;
      const innerH = 180;
      const w = rows.length * (barW + gap) + 20;
      const h = innerH + 40;
      let svg = '<svg class="dx-svg-chart" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="' + esc(title || 'chart') + '">';
      rows.forEach((r, i) => {
        const x = 10 + i * (barW + gap);
        const v = r.value || 0;
        const bh = Math.max(2, (Math.abs(v) / maxVal) * innerH);
        const y = 10 + innerH - bh;
        svg += '<rect x="' + x + '" y="' + y + '" width="' + barW + '" height="' + bh + '" rx="3" fill="' + (r.accent || accent) + '" opacity="0.85"/>';
        svg += '<text x="' + (x + barW / 2) + '" y="' + (y - 4) + '" text-anchor="middle" font-size="11" font-weight="600" fill="currentColor">' + esc(valueFmt(v)) + '</text>';
        svg += '<text x="' + (x + barW / 2) + '" y="' + (innerH + 26) + '" text-anchor="middle" font-size="11" fill="currentColor" style="opacity:0.78;">' + esc(r.label) + '</text>';
      });
      svg += '</svg>';
      return svg;
    }
  }

  // ==================================================================
  // LENS: frequencies
  // ==================================================================
  function runFreq() {
    const v = allVars.find(x => x.name === document.getElementById('dxFreqVar').value);
    if (!v) return setStatus('Pick a variable.');
    setStatus('');
    const counts = new Map();
    let missing = 0;
    v.values.forEach(val => {
      if (isMissing(val)) { missing++; return; }
      const k = String(val);
      counts.set(k, (counts.get(k) || 0) + 1);
    });
    const total = v.values.length - missing;
    if (total === 0) return setStatus('No non-missing values.');
    const rows = Array.from(counts.entries())
      .map(([level, c]) => ({ level: level, count: c, pct: c / total }))
      .sort((a, b) => b.count - a.count);
    let cum = 0;
    rows.forEach(r => { cum += r.pct; r.cum = cum; });

    const maxCount = rows[0].count || 1;
    const rowHtml = rows.map(r =>
      '<tr>' +
        '<td class="dx-freq-level">' + esc(r.level) + '</td>' +
        '<td class="dx-freq-bar">' +
          '<div class="dx-freq-bar-row">' +
            '<span class="dx-freq-bar-fill" style="width:' + Math.round((r.count / maxCount) * 100) + '%"></span>' +
          '</div>' +
        '</td>' +
        '<td class="dx-num">' + r.count + '</td>' +
        '<td class="dx-num">' + Math.round(r.pct * 100) + '%</td>' +
        '<td class="dx-num">' + Math.round(r.cum * 100) + '%</td>' +
      '</tr>'
    ).join('');

    // Graph above the table — researchers want both views.
    const chart = svgBarChart(
      rows.map(r => ({ label: r.level, value: r.count })),
      { title: 'Frequency of ' + v.name, valueFmt: function (n) { return n + ' (' + Math.round((n / total) * 100) + '%)'; } }
    );
    const body =
      '<div class="dx-graph-wrap"><h4 class="dx-block-h">Graph</h4>' + chart + '</div>' +
      '<div class="dx-table-wrap"><h4 class="dx-block-h">Table</h4>' +
        '<table class="dx-table dx-table-freq">' +
          '<thead><tr><th>Level</th><th></th><th class="dx-num">Count</th><th class="dx-num">%</th><th class="dx-num">Cumulative %</th></tr></thead>' +
          '<tbody>' + rowHtml + '</tbody>' +
        '</table>' +
      '</div>' +
      '<p class="dx-detail-note">N = <strong>' + total + '</strong> non-missing observations  ·  ' +
        missing + ' missing (' + Math.round((missing / v.values.length) * 100) + '% of column)  ·  ' +
        rows.length + ' unique levels</p>';

    const topLevel = rows[0];
    const top3Pct  = Math.round(rows.slice(0, 3).reduce((s, r) => s + r.pct, 0) * 100);
    const judgment = topLevel
      ? '"' + topLevel.level + '" is the most common value (' + Math.round(topLevel.pct * 100) + '% of ' + total + ' non-missing responses).'
      : 'No values to summarize.';
    const plainPara = 'Each row counts how many respondents picked that level for ' + v.name + ', plus what share of the total it represents and where the cumulative share lands.';
    const researchPara = 'Counts and percentages are computed over non-missing observations only (N = ' + total + ', ' + missing + ' missing). Rows are sorted from the most common level downward, so the cumulative column reads as a Pareto.';
    const closingPara = !topLevel
      ? 'No non-missing observations were available for this variable.'
      : top3Pct >= 80
        ? 'The top three levels cover ' + top3Pct + '% of responses. Responses are concentrated; the rest of the table is a long tail.'
        : top3Pct >= 50
          ? 'The top three levels cover ' + top3Pct + '% of responses. The distribution has a clear shape but no single value dominates.'
          : 'No single level dominates (top three cover ' + top3Pct + '% of responses). Responses are spread broadly across ' + rows.length + ' levels.';
    show(
      'Frequencies for ' + v.name,
      total + ' non-missing observations across ' + rows.length + ' levels',
      unifyBody({ summaryTitle: 'Frequencies summary', judgment: judgment, plainPara: plainPara, researchPara: researchPara, body: body, closingPara: closingPara }),
      unifyInterp({ judgment: judgment, plainPara: plainPara, closingPara: closingPara })
    );
    exposeAppState({ kind: 'frequencies', variable: v.name, total: total, missing: missing, rows: rows });
  }

  // ==================================================================
  // LENS: cross_tabs
  // ==================================================================
  function runCt() {
    const r = allVars.find(x => x.name === document.getElementById('dxCtRow').value);
    const c = allVars.find(x => x.name === document.getElementById('dxCtCol').value);
    if (!r || !c) return setStatus('Pick two categorical variables.');
    if (r.name === c.name) return setStatus('Pick two different variables.');
    setStatus('');
    const rowLevels = [], colLevels = [];
    for (let i = 0; i < rowCount; i++) {
      if (isMissing(r.values[i]) || isMissing(c.values[i])) continue;
      const a = String(r.values[i]), b = String(c.values[i]);
      if (rowLevels.indexOf(a) === -1) rowLevels.push(a);
      if (colLevels.indexOf(b) === -1) colLevels.push(b);
    }
    rowLevels.sort(); colLevels.sort();
    const R = rowLevels.length, C = colLevels.length;
    if (R < 2 || C < 2) return setStatus('Need at least 2 levels in each variable.');
    const observed = Array.from({ length: R }, () => new Array(C).fill(0));
    for (let i = 0; i < rowCount; i++) {
      if (isMissing(r.values[i]) || isMissing(c.values[i])) continue;
      observed[rowLevels.indexOf(String(r.values[i]))][colLevels.indexOf(String(c.values[i]))]++;
    }
    const rowTot = observed.map(row => row.reduce((s, v) => s + v, 0));
    const colTot = new Array(C).fill(0);
    observed.forEach(row => row.forEach((v, j) => { colTot[j] += v; }));
    const N = rowTot.reduce((s, v) => s + v, 0);

    const mode = document.querySelector('input[name="dxCtPct"]:checked').value;

    let maxCell = 0;
    observed.forEach(row => row.forEach(v => { if (v > maxCell) maxCell = v; }));

    let html = '<table class="dx-table dx-table-ct">' +
                 '<thead><tr><th></th>';
    colLevels.forEach(l => html += '<th>' + esc(l) + '</th>');
    html += '<th class="dx-num">Total</th></tr></thead><tbody>';
    rowLevels.forEach((rl, i) => {
      html += '<tr><th class="dx-rowhead">' + esc(rl) + '</th>';
      colLevels.forEach((cl, j) => {
        const o = observed[i][j];
        let pcts = '';
        if (mode === 'row') pcts = rowTot[i] ? Math.round((o / rowTot[i]) * 100) + '%' : '';
        if (mode === 'col') pcts = colTot[j] ? Math.round((o / colTot[j]) * 100) + '%' : '';
        if (mode === 'total') pcts = N ? Math.round((o / N) * 100) + '%' : '';
        const intensity = maxCell ? (o / maxCell) : 0;
        html += '<td class="dx-ct-cell" data-intensity="' + intensity.toFixed(2) + '" style="--dx-intensity:' + intensity.toFixed(2) + ';">' +
                  '<div class="dx-ct-count">' + o + '</div>' +
                  '<div class="dx-ct-pct">' + pcts + '</div>' +
                '</td>';
      });
      html += '<td class="dx-num dx-ct-margin">' + rowTot[i] + '</td></tr>';
    });
    html += '<tr><th class="dx-rowhead dx-rowhead-total">Total</th>';
    colLevels.forEach((_, j) => html += '<td class="dx-num dx-ct-margin">' + colTot[j] + '</td>');
    html += '<td class="dx-num dx-ct-margin">' + N + '</td></tr>';
    html += '</tbody></table>';
    html += '<p class="dx-detail-note">' + R + ' × ' + C + ' contingency  ·  N = ' + N + ' paired observations.</p>';

    // Lightweight chi-square-free interpretation: largest cell
    let bestI = 0, bestJ = 0, bestV = -1;
    observed.forEach((row, i) => row.forEach((v, j) => { if (v > bestV) { bestV = v; bestI = i; bestJ = j; } }));
    // Concentration check: how much of N sits in the diagonal vs the largest cell
    const bestPct = N ? Math.round((bestV / N) * 100) : 0;
    const modeLabel = mode === 'row' ? 'row' : mode === 'col' ? 'column' : 'total';
    const judgment = 'The most populated combination is ' + rowLevels[bestI] + ' × ' + colLevels[bestJ] + ' (' + bestV + ' observations, ' + bestPct + '% of N).';
    const plainPara = 'Each cell counts how many respondents fall into a specific combination of ' + r.name + ' and ' + c.name + '. The percentage on each cell uses the ' + modeLabel + ' as the denominator, so it reads as "out of this ' + modeLabel + '".';
    const researchPara = 'This is an R × C contingency over N = ' + N + ' paired observations (' + R + ' rows × ' + C + ' columns). The shaded background intensity is proportional to the cell count. Margins (row totals, column totals, grand total) are shown but no formal test of association is computed here.';
    const closingPara = bestPct >= 40
      ? 'A large share of cases concentrates in one cell, which often hints at an association between ' + r.name + ' and ' + c.name + '. Run Chi-Square in the Inferential section for a formal test.'
      : 'No single cell dominates; cases spread across the table. Run Chi-Square in the Inferential section to test whether the variables are independent.';
    show(
      'Cross-tab: ' + r.name + ' × ' + c.name,
      R + ' × ' + C + ' contingency, ' + mode + ' percentages',
      unifyBody({ summaryTitle: 'Cross-tabs summary', judgment: judgment, plainPara: plainPara, researchPara: researchPara, body: html, closingPara: closingPara }),
      unifyInterp({ judgment: judgment, plainPara: plainPara, closingPara: closingPara })
    );
    exposeAppState({ kind: 'cross_tabs', row: r.name, col: c.name, mode: mode, N: N, observed: observed, rowLevels: rowLevels, colLevels: colLevels });
  }

  // ==================================================================
  // LENS: distributions
  // ==================================================================
  // Means & Distributions — table-first across ALL numeric/Likert variables.
  // One row per variable, with N · Mean · SD · Min · Max · Skew · Kurt ·
  // Floor% · Ceiling% · Flag · inline mini-histogram. No picker — the table
  // is the default view.
  function runDist() {
    const items = allVars.filter(v => isNumericish(v) || isLikertOnly(v));
    if (!items.length) {
      show('Means & Distributions', 'No numeric or Likert variables to summarize', '<p class="dx-flat">This dataset has no numeric or Likert variables. Distributions need numeric values.</p>', '');
      return;
    }

    const rows = items.map(v => {
      const values = v.values.map(num).filter(x => x != null);
      const n = values.length;
      const m = n ? mean(values) : null;
      const s = n > 1 ? sd(values) : null;
      const lo = n ? Math.min.apply(null, values) : null;
      const hi = n ? Math.max.apply(null, values) : null;
      const range = hi != null && lo != null ? hi - lo : 0;
      const ceil = n && hi != null ? values.filter(x => x === hi).length / n : 0;
      const floor = n && lo != null ? values.filter(x => x === lo).length / n : 0;
      const sk = n >= 3 ? skewness(values) : 0;
      const ku = n >= 4 ? kurtosis(values) : 0;
      // Mini histogram (8 bins)
      const nBins = 8;
      const binSize = (hi - lo) / nBins || 1;
      const bins = new Array(nBins).fill(0);
      values.forEach(x => {
        let bi = Math.floor((x - lo) / binSize);
        if (bi >= nBins) bi = nBins - 1;
        if (bi < 0) bi = 0;
        bins[bi]++;
      });
      const flags = [];
      if (ceil  >= 0.50) flags.push('Ceiling');
      if (floor >= 0.50) flags.push('Floor');
      if (Math.abs(sk) > 2) flags.push('Extreme skew');
      if (Math.abs(ku) > 5) flags.push('Heavy tails');
      let tone = 'ok', flagLabel = 'Clean';
      if (flags.length >= 2) { tone = 'alert'; flagLabel = flags.join(' · '); }
      else if (flags.length) { tone = 'warn';  flagLabel = flags[0]; }
      return { name: v.name, n, m, s, lo, hi, sk, ku, ceil, floor, bins, tone, flagLabel };
    });

    // Mini histogram bars
    function miniHist(bins) {
      const max = Math.max.apply(null, bins) || 1;
      return '<span class="dx-mini-hist">' +
        bins.map(c => '<span style="height:' + Math.max(2, Math.round((c / max) * 22)) + 'px"></span>').join('') +
      '</span>';
    }

    // Counts for summary line
    const clean   = rows.filter(r => r.tone === 'ok').length;
    const watch   = rows.filter(r => r.tone === 'warn').length;
    const problem = rows.filter(r => r.tone === 'alert').length;

    const judgment = problem
      ? problem + ' variable' + (problem === 1 ? '' : 's') + ' show multiple distributional issues; ' + watch + ' need a closer look.'
      : watch
        ? watch + ' variable' + (watch === 1 ? '' : 's') + ' show one distributional pattern worth a second look.'
        : 'All ' + items.length + ' numeric / Likert variable' + (items.length === 1 ? '' : 's') + ' have clean distributions.';

    const plainPara = problem || watch
      ? 'Some variables are clustered at the top or bottom of their scale, or have a lopsided shape. These items still produce a mean and SD, but they may not discriminate well across respondents — most people gave the same answer.'
      : 'Every variable spreads its values in a healthy mix. Means and SDs are based on a wide range of responses, not on respondents bunching at one end of the scale.';

    const researchPara = 'Per-variable distributional screens: ceiling (≥50% at top endpoint), floor (≥50% at bottom), extreme skew (|skew| > 2), heavy tails (|excess kurtosis| > 5). Two or more screens = Problem; one = Watch. Skew and excess kurtosis use the standard moment-based estimators.';

    const closingPara = problem
      ? 'Address the Problem variables before reporting means as group differences — ceiling and floor effects shrink the apparent gap between groups.'
      : watch
        ? 'Inspect the Watch variables; most are usable but may benefit from a response-scale revision.'
        : 'Distributions are healthy. Means and standard deviations can be reported confidently.';

    const tbody = rows.map(r => (
      '<tr data-tone="' + r.tone + '">' +
        '<td class="dx-item">' + esc(r.name) + '</td>' +
        '<td class="dx-num">' + r.n + '</td>' +
        '<td class="dx-num">' + fmt(r.m, 2) + '</td>' +
        '<td class="dx-num">' + fmt(r.s, 2) + '</td>' +
        '<td class="dx-num">' + fmt(r.lo, 2) + '</td>' +
        '<td class="dx-num">' + fmt(r.hi, 2) + '</td>' +
        '<td class="dx-num">' + fmt(r.sk, 2) + '</td>' +
        '<td class="dx-num">' + fmt(r.ku, 2) + '</td>' +
        '<td class="dx-num">' + Math.round(r.floor * 100) + '%</td>' +
        '<td class="dx-num">' + Math.round(r.ceil * 100) + '%</td>' +
        '<td><span class="dx-flag" data-tone="' + r.tone + '">' + esc(r.flagLabel) + '</span></td>' +
        '<td>' + miniHist(r.bins) + '</td>' +
      '</tr>'
    )).join('');

    const body =
      '<div class="dx-rel-summary">' +
        '<h4 class="dx-block-h">Means &amp; distributions summary</h4>' +
        '<p class="dx-rel-summary-line"><strong>' + esc(judgment) + '</strong></p>' +
        '<div class="dx-rel-paragraphs">' +
          '<div><span class="dx-rel-label">What this table shows.</span> ' + esc(plainPara) + '</div>' +
          '<div><span class="dx-rel-label">Research interpretation.</span> ' + esc(researchPara) + '</div>' +
        '</div>' +
      '</div>' +
      '<h4 class="dx-block-h">All numeric / Likert variables</h4>' +
      '<div class="dx-rel-table-wrap">' +
        '<table class="dx-table-rel">' +
          '<thead><tr>' +
            '<th>Variable</th><th class="dx-num">N</th><th class="dx-num">Mean</th><th class="dx-num">SD</th>' +
            '<th class="dx-num">Min</th><th class="dx-num">Max</th>' +
            '<th class="dx-num">Skew</th><th class="dx-num">Kurt</th>' +
            '<th class="dx-num">Floor %</th><th class="dx-num">Ceiling %</th>' +
            '<th>Flag</th><th>Distribution</th>' +
          '</tr></thead>' +
          '<tbody>' + tbody + '</tbody>' +
        '</table>' +
      '</div>' +
      '<div class="dx-rel-closing">' +
        '<h4 class="dx-block-h">Interpretation</h4>' +
        '<p>' + esc(closingPara) + '</p>' +
      '</div>';

    const interp =
      '<div class="dx-jema">' +
        '<div class="dx-jema-row"><strong>What this table shows.</strong> ' + esc(plainPara) + '</div>' +
        '<div class="dx-jema-row"><strong>What stands out.</strong> ' + esc(judgment) + '</div>' +
        '<div class="dx-jema-row"><strong>What to check next.</strong> ' + esc(closingPara) + '</div>' +
      '</div>';

    show('Means & Distributions', items.length + ' numeric / Likert variable' + (items.length === 1 ? '' : 's') + ' · ' + clean + ' clean · ' + watch + ' watch · ' + problem + ' problem', body, interp);
    exposeAppState({ kind: 'distributions', summary: { clean, watch, problem }, rows: rows.map(r => ({ name: r.name, n: r.n, mean: r.m, sd: r.s, flag: r.flagLabel })) });
  }

  function statBox(label, value) {
    return '<div class="dx-stat">' +
             '<label>' + esc(label) + '</label>' +
             '<span class="v">' + esc(value) + '</span>' +
           '</div>';
  }

  // ==================================================================
  // LENS: missing_data — per-variable + per-row missingness with a
  // pattern hint. Table-first. No pickers needed.
  // ==================================================================
  function runMissing() {
    if (!allVars || !allVars.length) {
      show('Missing Data', 'No data loaded', '<p class="dx-flat">No dataset is loaded yet.</p>', '');
      return;
    }
    const n = rowCount;
    function pct(x) { return n ? (x / n) : 0; }

    // Per-variable missingness
    const varRows = allVars.map(v => {
      const vals = (v.values || []).slice(0, n);
      const miss = vals.filter(isMissing).length;
      const type = (v.types && v.types[0]) || '—';
      const sev = miss === 0 ? 'Clean'
                : miss / n < 0.05 ? 'Clean'
                : miss / n < 0.25 ? 'Watch'
                                  : 'Critical';
      const tone = sev === 'Clean' ? 'strong' : sev === 'Watch' ? 'warn' : 'alert';
      // Pattern: missing-at-end vs. scattered
      let firstMissing = -1, lastNonMissing = -1;
      for (let i = 0; i < vals.length; i++) {
        if (isMissing(vals[i])) { if (firstMissing < 0) firstMissing = i; }
        else lastNonMissing = i;
      }
      const pattern = miss === 0 ? '—'
        : (firstMissing > 0 && lastNonMissing < firstMissing) ? 'Tail (last responses incomplete)'
        : 'Scattered';
      return { name: v.name, type, miss, pct: pct(miss), pattern, sev, tone };
    });

    // Per-row missingness
    let complete = 0, miss12 = 0, miss3plus = 0;
    for (let i = 0; i < n; i++) {
      let rowMiss = 0;
      for (let j = 0; j < allVars.length; j++) {
        if (isMissing((allVars[j].values || [])[i])) rowMiss++;
      }
      if (rowMiss === 0) complete++;
      else if (rowMiss <= 2) miss12++;
      else miss3plus++;
    }

    const totalCells = n * allVars.length;
    const totalMissing = varRows.reduce((s, r) => s + r.miss, 0);
    const overallPct = totalCells ? (totalMissing / totalCells * 100) : 0;

    // Pattern overall: concentrated vs spread
    const sortedVar = varRows.slice().sort((a, b) => b.miss - a.miss);
    const top3Share = totalMissing ? (sortedVar.slice(0, 3).reduce((s, r) => s + r.miss, 0) / totalMissing) : 0;

    const overallSev = overallPct === 0 ? 'Clean' : overallPct < 5 ? 'Clean' : overallPct < 15 ? 'Watch' : 'Critical';
    const judgment = overallSev === 'Clean'
      ? 'Missing data is below 5%. The dataset is ready for analysis with no special handling.'
      : overallSev === 'Watch'
        ? 'Missing data is between 5–15%. Listwise deletion will start to lose meaningful sample size; consider pairwise procedures or imputation.'
        : 'Missing data exceeds 15%. Listwise deletion will discard a lot of rows; imputation or re-collection should be considered.';

    const plainPara = totalMissing === 0
      ? 'Every cell in your dataset has a value. Nothing is missing.'
      : top3Share > 0.8
        ? 'Most of the missing values are in just a few columns. That usually means those columns were optional, conditional, or had a skip pattern.'
        : 'Missing values are spread across many columns and many rows. That usually means people dropped off at different points or skipped different questions.';

    const researchPara = 'Per-variable severity: Clean (< 5% missing), Watch (5–25%), Critical (≥ 25%). Per-row buckets: Complete (0 missing), Light (1–2 missing), Heavy (3+ missing). Pattern is Tail when all missing values come at the end of the response sequence (suggests dropoff), Scattered otherwise.';

    const closingPara = overallSev === 'Clean'
      ? 'No action needed. Run analyses with confidence.'
      : top3Share > 0.8
        ? 'Investigate the top-missing columns first — they account for ' + Math.round(top3Share * 100) + '% of all missing values. If they\'re conditional, exclude them from full-sample analyses.'
        : 'Consider pairwise rather than listwise deletion for most inferential tests. For composite scores, imputation may be safer than dropping rows.';

    // Build per-variable rows (sorted by missing count desc)
    const varTbody = sortedVar.map(r => (
      '<tr data-tone="' + r.tone + '">' +
        '<td class="dx-item">' + esc(r.name) + '</td>' +
        '<td>' + esc(r.type) + '</td>' +
        '<td class="dx-num">' + r.miss + '</td>' +
        '<td class="dx-num">' + (r.pct * 100).toFixed(1) + '%</td>' +
        '<td>' + esc(r.pattern) + '</td>' +
        '<td><span class="dx-flag" data-tone="' + r.tone + '">' + r.sev + '</span></td>' +
      '</tr>'
    )).join('');

    // Per-row summary
    const rowSummary =
      '<table class="dx-table-rel" style="margin-bottom:14px;">' +
        '<thead><tr><th>Row pattern</th><th class="dx-num">Count</th><th class="dx-num">Share</th></tr></thead>' +
        '<tbody>' +
          '<tr data-tone="strong"><td>Complete rows (0 missing)</td><td class="dx-num">' + complete + '</td><td class="dx-num">' + (n ? Math.round(complete / n * 100) : 0) + '%</td></tr>' +
          '<tr data-tone="warn"><td>Light missingness (1–2 items)</td><td class="dx-num">' + miss12 + '</td><td class="dx-num">' + (n ? Math.round(miss12 / n * 100) : 0) + '%</td></tr>' +
          '<tr data-tone="alert"><td>Heavy missingness (3+ items)</td><td class="dx-num">' + miss3plus + '</td><td class="dx-num">' + (n ? Math.round(miss3plus / n * 100) : 0) + '%</td></tr>' +
        '</tbody>' +
      '</table>';

    const body =
      '<div class="dx-rel-summary">' +
        '<h4 class="dx-block-h">Missing data summary</h4>' +
        '<p class="dx-rel-summary-line"><strong>' + esc(judgment) + '</strong></p>' +
        '<div class="dx-rel-paragraphs">' +
          '<div><span class="dx-rel-label">What this table shows.</span> ' + esc(plainPara) + '</div>' +
          '<div><span class="dx-rel-label">Research interpretation.</span> ' + esc(researchPara) + '</div>' +
        '</div>' +
      '</div>' +
      '<h4 class="dx-block-h">Row-level pattern</h4>' +
      '<div class="dx-rel-table-wrap">' + rowSummary + '</div>' +
      '<h4 class="dx-block-h">Per-variable missingness</h4>' +
      '<div class="dx-rel-table-wrap">' +
        '<table class="dx-table-rel">' +
          '<thead><tr>' +
            '<th>Variable</th><th>Type</th>' +
            '<th class="dx-num">Missing count</th><th class="dx-num">Missing %</th>' +
            '<th>Pattern</th><th>Severity</th>' +
          '</tr></thead>' +
          '<tbody>' + varTbody + '</tbody>' +
        '</table>' +
      '</div>' +
      '<div class="dx-rel-closing">' +
        '<h4 class="dx-block-h">Interpretation</h4>' +
        '<p>' + esc(closingPara) + '</p>' +
      '</div>';

    const interp =
      '<div class="dx-jema">' +
        '<div class="dx-jema-row"><strong>What this table shows.</strong> ' + esc(plainPara) + '</div>' +
        '<div class="dx-jema-row"><strong>What stands out.</strong> ' + overallPct.toFixed(1) + '% missing overall · ' + complete + '/' + n + ' complete rows · ' + sortedVar.filter(r => r.sev === 'Critical').length + ' critical variables.</div>' +
        '<div class="dx-jema-row"><strong>What to check next.</strong> ' + esc(closingPara) + '</div>' +
      '</div>';

    show('Missing Data', overallPct.toFixed(1) + '% overall missingness · ' + complete + '/' + n + ' complete rows', body, interp);
    exposeAppState({ kind: 'missing_data', overallPct, completeRows: complete, vars: sortedVar });
  }

  // ==================================================================
  // LENS: group_summaries
  // ==================================================================
  function runGs() {
    const out = allVars.find(x => x.name === document.getElementById('dxGsOutcome').value);
    const grp = allVars.find(x => x.name === document.getElementById('dxGsGroup').value);
    if (!out || !grp) return setStatus('Pick an outcome and a grouping variable.');
    setStatus('');
    const groups = new Map();
    for (let i = 0; i < rowCount; i++) {
      const y = num(out.values[i]);
      const g = grp.values[i];
      if (y == null || isMissing(g)) continue;
      const k = String(g);
      if (!groups.has(k)) groups.set(k, []);
      groups.get(k).push(y);
    }
    const all = [];
    groups.forEach(arr => arr.forEach(v => all.push(v)));
    if (!all.length) return setStatus('No paired observations.');
    const grand = mean(all);

    const rows = [];
    groups.forEach((arr, lvl) => {
      rows.push({
        level: lvl,
        n: arr.length,
        mean: mean(arr),
        sd: sd(arr),
        min: Math.min.apply(null, arr),
        max: Math.max.apply(null, arr),
        diff: mean(arr) - grand,
      });
    });
    rows.sort((a, b) => b.mean - a.mean);

    const maxAbsDiff = Math.max.apply(null, rows.map(r => Math.abs(r.diff))) || 1;

    // Graph: bar chart of each group's mean (sorted high-to-low for clarity)
    const chart = svgBarChart(
      rows.map(r => ({ label: r.level, value: r.mean })),
      { title: 'Group means', valueFmt: function (n) { return fmt(n, 2); } }
    );
    let html = '<div class="dx-graph-wrap"><h4 class="dx-block-h">Graph (group means)</h4>' + chart + '</div>' +
               '<div class="dx-table-wrap"><h4 class="dx-block-h">Table</h4>' +
               '<table class="dx-table dx-table-gs">' +
                 '<thead><tr><th>Group</th><th class="dx-num">n</th><th class="dx-num">Mean</th><th class="dx-num">SD</th><th class="dx-num">Min</th><th class="dx-num">Max</th><th>Δ from grand mean (' + fmt(grand, 2) + ')</th></tr></thead><tbody>';
    rows.forEach(r => {
      const side = r.diff >= 0 ? 'pos' : 'neg';
      const w = Math.round((Math.abs(r.diff) / maxAbsDiff) * 50);
      const bar =
        '<div class="dx-gs-bar">' +
          '<div class="dx-gs-bar-mid" aria-hidden="true"></div>' +
          (r.diff >= 0
            ? '<div class="dx-gs-bar-fill dx-gs-bar-pos" style="width:' + w + '%; left:50%"></div>'
            : '<div class="dx-gs-bar-fill dx-gs-bar-neg" style="width:' + w + '%; right:50%"></div>') +
          '<span class="dx-gs-bar-label">' + (r.diff >= 0 ? '+' : '') + fmt(r.diff, 2) + '</span>' +
        '</div>';
      html += '<tr>' +
        '<td>' + esc(r.level) + '</td>' +
        '<td class="dx-num">' + r.n + '</td>' +
        '<td class="dx-num">' + fmt(r.mean, 2) + '</td>' +
        '<td class="dx-num">' + fmt(r.sd, 2) + '</td>' +
        '<td class="dx-num">' + fmt(r.min, 2) + '</td>' +
        '<td class="dx-num">' + fmt(r.max, 2) + '</td>' +
        '<td>' + bar + '</td>' +
      '</tr>';
    });
    html += '</tbody></table></div>';
    html += '<p class="dx-detail-note">Grand mean across all groups: <strong>' + fmt(grand, 2) + '</strong>  ·  N = ' + all.length + ' paired observations.</p>';

    const top = rows[0], bot = rows[rows.length - 1];
    const range = top.mean - bot.mean;
    const overallSd = sd(all);
    const wideGap = range > overallSd;
    const judgment = top.level + ' has the highest mean (' + fmt(top.mean, 2) + '); ' + bot.level + ' the lowest (' + fmt(bot.mean, 2) + '). Range across groups: ' + fmt(range, 2) + '.';
    const plainPara = 'Each row shows one level of ' + grp.name + ' with its average ' + out.name + ' score, how many respondents are in that group, and how the group mean compares to the grand mean across everyone (' + fmt(grand, 2) + ').';
    const researchPara = 'Means and SDs are computed within each group using only paired observations on both variables (N = ' + all.length + '). The Δ bar shows each group\'s deviation from the grand mean, scaled to the largest absolute deviation in the table for visual comparability.';
    const closingPara = wideGap
      ? 'The spread between the highest and lowest group means (' + fmt(range, 2) + ') exceeds the overall SD (' + fmt(overallSd, 2) + '). The gap is large enough to warrant a formal test of group differences (t-test for two groups, ANOVA or Welch ANOVA for more).'
      : 'The spread between groups (' + fmt(range, 2) + ') is within one SD of the overall variation (' + fmt(overallSd, 2) + '). Group differences look modest; a formal test will tell you whether the gap is reliable.';
    show(
      'Group summaries: ' + out.name + ' by ' + grp.name,
      rows.length + ' groups, grand mean = ' + fmt(grand, 2),
      unifyBody({ summaryTitle: 'Group summaries', judgment: judgment, plainPara: plainPara, researchPara: researchPara, body: html, closingPara: closingPara }),
      unifyInterp({ judgment: judgment, plainPara: plainPara, closingPara: closingPara })
    );
    exposeAppState({ kind: 'group_summaries', outcome: out.name, group: grp.name, grandMean: grand, groups: rows });
  }

  // ==================================================================
  // LENS: top_bottom_items
  // ==================================================================
  function runTb() {
    const items = allVars.filter(isLikertOnly);
    if (items.length < 2) {
      show('Top / Bottom Items', '—', '<p class="dx-flat">Need at least 2 Likert items.</p>', '');
      return;
    }
    const rows = items.map(v => {
      const vals = v.values.map(num).filter(x => x != null);
      if (!vals.length) return null;
      const m = mean(vals), s = sd(vals);
      const lo = Math.min.apply(null, vals), hi = Math.max.apply(null, vals);
      const range = hi - lo || 1;
      // Ceiling/floor: ≥70% at extreme
      const ceilN = vals.filter(x => x === hi).length;
      const floorN = vals.filter(x => x === lo).length;
      const ceil = ceilN / vals.length;
      const floor = floorN / vals.length;
      const lowVar = s < 0.15 * range;
      return { name: v.name, n: vals.length, mean: m, sd: s, lowVar: lowVar, ceil: ceil, floor: floor };
    }).filter(r => r);

    const sortedByMean = rows.slice().sort((a, b) => b.mean - a.mean);
    const sortedBySd   = rows.slice().sort((a, b) => b.sd - a.sd);
    const maxMean = Math.max.apply(null, rows.map(r => r.mean)) || 1;
    const top3 = sortedByMean.slice(0, 3);
    const bot3 = sortedByMean.slice(-3).reverse();

    function row(r, rank, tag) {
      const flags = [];
      if (r.lowVar) flags.push('low variance');
      if (r.ceil >= 0.70) flags.push('ceiling');
      if (r.floor >= 0.70) flags.push('floor');
      return '<tr data-tag="' + (tag || '') + '">' +
        '<td class="dx-rank">' + (rank != null ? rank : '') + '</td>' +
        '<td>' + esc(r.name) + '</td>' +
        '<td class="dx-num">' + fmt(r.mean, 2) + '</td>' +
        '<td><div class="dx-mean-bar"><span style="width:' + Math.round((r.mean / maxMean) * 100) + '%"></span></div></td>' +
        '<td class="dx-num">' + fmt(r.sd, 2) + '</td>' +
        '<td class="dx-num">' + r.n + '</td>' +
        '<td>' + (flags.length ? '<span class="dx-flag">' + esc(flags.join(', ')) + '</span>' : '') + '</td>' +
      '</tr>';
    }

    // Graph: each Likert item's mean as a horizontal bar (sorted)
    const tbChart = svgBarChart(
      sortedByMean.map(r => ({ label: r.name, value: r.mean })),
      { title: 'Item means', valueFmt: function (n) { return fmt(n, 2); } }
    );
    let html = '<div class="dx-graph-wrap"><h4 class="dx-block-h">Graph (item means)</h4>' + tbChart + '</div>' +
               '<h4 class="dx-block-h">All items, ranked by mean</h4>' +
               '<table class="dx-table dx-table-tb">' +
                 '<thead><tr><th>#</th><th>Item</th><th class="dx-num">Mean</th><th></th><th class="dx-num">SD</th><th class="dx-num">n</th><th>Flags</th></tr></thead>' +
                 '<tbody>' +
                   sortedByMean.map((r, i) => row(r, i + 1, i < 3 ? 'top' : i >= sortedByMean.length - 3 ? 'bot' : '')).join('') +
                 '</tbody>' +
               '</table>';
    html += '<div class="dx-tb-callouts">' +
              '<div class="dx-tb-call"><h5>Top 3 items</h5><ul>' +
                top3.map(r => '<li><strong>' + esc(r.name) + '</strong> · mean ' + fmt(r.mean, 2) + ' · SD ' + fmt(r.sd, 2) + '</li>').join('') +
              '</ul></div>' +
              '<div class="dx-tb-call"><h5>Bottom 3 items</h5><ul>' +
                bot3.map(r => '<li><strong>' + esc(r.name) + '</strong> · mean ' + fmt(r.mean, 2) + ' · SD ' + fmt(r.sd, 2) + '</li>').join('') +
              '</ul></div>' +
              '<div class="dx-tb-call"><h5>Highest variance (most discriminating)</h5><ul>' +
                sortedBySd.slice(0, 3).map(r => '<li><strong>' + esc(r.name) + '</strong> · SD ' + fmt(r.sd, 2) + '</li>').join('') +
              '</ul></div>' +
            '</div>';
    const flagged = rows.filter(r => r.lowVar || r.ceil >= 0.70 || r.floor >= 0.70);
    const judgment =
      (top3[0] ? top3[0].name + ' is the strongest item (mean ' + fmt(top3[0].mean, 2) + '). ' : '') +
      (bot3[0] ? bot3[0].name + ' is the weakest (mean ' + fmt(bot3[0].mean, 2) + '). ' : '') +
      (flagged.length
        ? flagged.length + ' item' + (flagged.length === 1 ? ' is' : 's are') + ' flagged for low variance or ceiling/floor patterns.'
        : 'No items flagged for low variance or ceiling/floor patterns.');
    const plainPara = 'Each row is one Likert item, ranked from highest average score to lowest. Items with low variance or where most respondents picked the top (or bottom) of the scale are flagged because they may not distinguish people well.';
    const researchPara = 'Means and SDs are computed across non-missing observations for ' + rows.length + ' Likert items. Items with SD below 15% of the response range are flagged as low-variance. Ceiling and floor flags trigger when ≥ 70% of respondents pick the highest (or lowest) point on the scale.';
    const closingPara = flagged.length
      ? flagged.length + ' item' + (flagged.length === 1 ? '' : 's') + ' may need to be revised or dropped before the instrument is used in a high-stakes setting. Items that everyone answers the same way add no discriminating information to scale scores.'
      : 'All items appear to discriminate; none are pegged at the top or bottom of the scale. The instrument is positioned to capture differences between respondents.';
    show(
      'Top & Bottom Items',
      sortedByMean.length + ' Likert items ranked by mean',
      unifyBody({ summaryTitle: 'Top & Bottom items summary', judgment: judgment, plainPara: plainPara, researchPara: researchPara, body: html, closingPara: closingPara }),
      unifyInterp({ judgment: judgment, plainPara: plainPara, closingPara: closingPara })
    );
    exposeAppState({ kind: 'top_bottom_items', items: sortedByMean });
  }

  // ==================================================================
  // LENS: scale_scores
  // ==================================================================
  function runScale() {
    const checked = Array.from(document.querySelectorAll('#dxScaleItems input[type="checkbox"]:checked'))
                         .map(cb => cb.value);
    if (checked.length < 2) return setStatus('Pick at least 2 items.');
    setStatus('');
    const items = checked.map(n => allVars.find(v => v.name === n)).filter(v => v);
    const cols = items.map(v => v.values.map(num).map(x => x == null ? 0 : x));
    const validRows = [];
    for (let i = 0; i < rowCount; i++) {
      if (!items.some(v => isMissing(v.values[i]))) validRows.push(i);
    }
    if (validRows.length < 3) return setStatus('Need at least 3 rows with all items answered.');
    const validCols = cols.map(col => validRows.map(i => col[i]));

    const mode = document.querySelector('input[name="dxScaleMode"]:checked').value;
    const scores = validRows.map((_, idx) => {
      const sum = validCols.reduce((s, col) => s + col[idx], 0);
      return mode === 'sum' ? sum : sum / items.length;
    });
    const mScale = mean(scores), sScale = sd(scores);

    // Cronbach's α
    const k = items.length;
    const itemVar = validCols.reduce((s, col) => s + variance(col), 0);
    const totals  = validRows.map((_, idx) => validCols.reduce((s, col) => s + col[idx], 0));
    const totalVar = variance(totals);
    const alpha = totalVar ? (k / (k - 1)) * (1 - itemVar / totalVar) : 0;
    let alphaRating;
    if      (alpha >= 0.90) alphaRating = 'Excellent';
    else if (alpha >= 0.80) alphaRating = 'Strong';
    else if (alpha >= 0.70) alphaRating = 'Acceptable';
    else if (alpha >= 0.60) alphaRating = 'Needs strengthening';
    else                    alphaRating = 'Weak';

    // Compact histogram of scale scores
    const nBins = 8;
    const lo = Math.min.apply(null, scores), hi = Math.max.apply(null, scores);
    const binSize = (hi - lo) / nBins || 1;
    const bins = new Array(nBins).fill(0);
    scores.forEach(x => {
      let bi = Math.floor((x - lo) / binSize);
      if (bi >= nBins) bi = nBins - 1;
      if (bi < 0) bi = 0;
      bins[bi]++;
    });
    const maxBin = Math.max.apply(null, bins) || 1;
    const histHtml =
      '<div class="dx-hist">' +
        bins.map((c, i) => {
          const lo2 = lo + i * binSize;
          return '<div class="dx-hist-col">' +
                   '<div class="dx-hist-bar" style="height:' + Math.round((c / maxBin) * 100) + '%"></div>' +
                   '<div class="dx-hist-label">' + fmt(lo2, 1) + '</div>' +
                 '</div>';
        }).join('') +
      '</div>';

    const stats =
      '<div class="dx-stat-grid">' +
        statBox('Scale items',     items.length) +
        statBox('Valid rows',      validRows.length) +
        statBox('Composite type',  mode === 'sum' ? 'sum' : 'item-mean') +
        statBox('Mean score',      fmt(mScale, 2)) +
        statBox('SD',              fmt(sScale, 2)) +
        statBox('Min',             fmt(lo, 2)) +
        statBox('Max',             fmt(hi, 2)) +
        statBox("Cronbach's α",    fmt(alpha, 2)) +
        statBox('Reliability',     alphaRating) +
      '</div>';
    const body = stats + '<h4 class="dx-block-h">Composite distribution</h4>' + histHtml;
    const judgment = items.length + ' items combined into a ' + (mode === 'sum' ? 'sum' : 'mean') + ' composite, Cronbach\'s α = ' + fmt(alpha, 2) + ' (' + alphaRating.toLowerCase() + ').';
    const plainPara = 'A scale score is a single number that combines several items into one composite. The histogram shows how the composite is distributed across respondents; α tells you whether the items hang together as a single underlying scale.';
    const researchPara = 'The composite is a ' + (mode === 'sum' ? 'simple sum' : 'mean across items') + ' for the ' + validRows.length + ' respondents who answered every selected item. Cronbach\'s α is computed from the item variances and the total-score variance; it ranges from 0 (no internal consistency) to 1 (perfect consistency), with the conventional cut at 0.70 for use in research and 0.80 for high-stakes decisions.';
    const closingPara = alpha >= 0.80
      ? 'The selected items hang together strongly. The composite is a defensible single-score summary of this construct, suitable for reporting and for use as an outcome in inferential tests.'
      : alpha >= 0.70
        ? 'The selected items hang together well enough to summarize as a single score for most research uses. For high-stakes decisions, run McDonald\'s ω in the Instrument Quality section to confirm.'
        : 'The selected items do not cohere strongly. Review whether they really measure the same construct, or whether one or two items should be dropped before treating this composite as a scale.';
    show(
      'Scale: ' + items.map(v => v.name).join(' + '),
      validRows.length + ' valid rows · α = ' + fmt(alpha, 2) + ' (' + alphaRating.toLowerCase() + ')',
      unifyBody({ summaryTitle: 'Scale composite summary', judgment: judgment, plainPara: plainPara, researchPara: researchPara, body: body, closingPara: closingPara }),
      unifyInterp({ judgment: judgment, plainPara: plainPara, closingPara: closingPara })
    );
    exposeAppState({ kind: 'scale_scores', items: items.map(v => v.name), mode: mode, alpha: alpha, meanScale: mScale, sdScale: sScale });
  }

  // ==================================================================
  // App state
  // ==================================================================
  function exposeAppState(payload) {
    window.RELICHECK_APP_STATE = Object.assign({
      app_key:   'descriptive',
      app_name:  'Descriptive (' + nameForLens(lens) + ')',
      summary:   buildSummary(payload),
      lens:      lens,
      dataset:   { source: dataset.source || '', rowCount: rowCount, fromUpload: datasetSource === 'uploaded' },
      computed_at: new Date().toISOString(),
    }, payload);
  }
  function buildSummary(p) {
    if (p.kind === 'frequencies')      return p.variable + ': ' + p.total + ' observations across ' + (p.rows ? p.rows.length : 0) + ' levels.';
    if (p.kind === 'cross_tabs')       return p.row + ' × ' + p.col + ', N = ' + p.N + '.';
    if (p.kind === 'distributions')    return p.variable + ': n = ' + p.n + ', mean = ' + fmt(p.mean, 2) + ', SD = ' + fmt(p.sd, 2) + '.';
    if (p.kind === 'group_summaries')  return p.outcome + ' by ' + p.group + ': grand mean = ' + fmt(p.grandMean, 2) + '.';
    if (p.kind === 'top_bottom_items') return (p.items ? p.items.length : 0) + ' Likert items ranked.';
    if (p.kind === 'scale_scores')     return p.items.length + '-item ' + p.mode + ' composite, α = ' + fmt(p.alpha, 2) + '.';
    return 'Descriptive output';
  }
})();
