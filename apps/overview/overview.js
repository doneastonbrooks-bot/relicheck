// ReliCheck Overview Suite
// -------------------------------------------------------------------
// Three lenses sharing one engine:
//   project_snapshot   meta-summary of the dataset and project
//   sample_profile     stacked frequency tables for categoricals
//   data_quality       duplicates, straight-lining, outliers, invalid values,
//                      low-effort opens, item-level missingness

(function () {
  'use strict';

  // ==================================================================
  // Dataset + project meta
  // ==================================================================
  let dataset = window.OVERVIEW_DATASET;
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

  if (!dataset || !Array.isArray(dataset.variables)) {
    document.getElementById('ovEmpty').hidden = false;
    return;
  }
  const allVars  = dataset.variables;
  const rowCount = dataset.rowCount || (allVars[0] ? allVars[0].values.length : 0);
  const lens     = window.OVERVIEW_LENS || 'project_snapshot';

  // Project purpose (from Evidence Alignment lens)
  let purpose = '';
  try { purpose = window.localStorage.getItem('relicheck.purpose.' + projectId) || ''; } catch (e) {}

  // ==================================================================
  // Helpers
  // ==================================================================
  function num(v) { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function isMissing(v) { return v === '' || v == null; }
  function hasType(v, t) { return v.types && v.types.indexOf(t) !== -1; }
  function isCategorical(v) { return hasType(v, 'categorical'); }
  function isLikert(v)      { return hasType(v, 'likert'); }
  function isNumeric(v)     { return hasType(v, 'numeric'); }
  function isOpen(v)        { return hasType(v, 'open'); }
  function isDate(v)        { return hasType(v, 'date'); }
  function mean(a) { return a.length ? a.reduce((s, v) => s + v, 0) / a.length : 0; }
  function variance(a) {
    if (a.length < 2) return 0;
    const m = mean(a);
    return a.reduce((s, v) => s + (v - m) * (v - m), 0) / (a.length - 1);
  }
  function median(a) {
    if (!a.length) return 0;
    const s = a.slice().sort((x, y) => x - y);
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
  function fmt(x, d) { if (x == null || !isFinite(x)) return '—'; return Number(x).toFixed(d == null ? 2 : d); }
  function pct(x) { return x == null ? '—' : Math.round(x * 100) + '%'; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  // Source ribbon
  document.getElementById('ovSource').setAttribute('data-source', datasetSource);
  document.getElementById('ovSourceLabel').textContent = datasetSource === 'uploaded' ? 'Uploaded data' : 'Sample data';
  document.getElementById('ovSourceMeta').textContent  = (dataset.source || 'Dataset') + '  ·  ' + rowCount + ' rows';

  document.querySelectorAll('.ov-lens').forEach(el => {
    el.hidden = el.getAttribute('data-lens') !== lens;
  });

  switch (lens) {
    case 'project_snapshot': renderSnapshot(); break;
    case 'sample_profile':   renderSampleProfile(); break;
    case 'data_quality':     renderDataQuality(); break;
  }

  // ==================================================================
  // LENS: project_snapshot
  // ==================================================================
  function renderSnapshot() {
    const byType = { id: 0, likert: 0, numeric: 0, categorical: 0, open: 0, date: 0, other: 0 };
    allVars.forEach(v => {
      if (!v.types || !v.types.length) { byType.other++; return; }
      v.types.forEach(t => {
        if (byType[t] != null) byType[t]++;
        else byType.other++;
      });
    });

    // Completion: rows complete across non-open variables
    const required = allVars.filter(v => !isOpen(v));
    let complete = 0;
    for (let i = 0; i < rowCount; i++) {
      if (required.every(v => !isMissing(v.values[i]))) complete++;
    }
    const completeRate = rowCount ? complete / rowCount : 0;

    // Date range (any date variable)
    let earliest = null, latest = null;
    const dateVars = allVars.filter(isDate);
    dateVars.forEach(v => {
      v.values.forEach(val => {
        if (isMissing(val)) return;
        const d = new Date(val);
        if (isNaN(d.getTime())) return;
        if (!earliest || d < earliest) earliest = d;
        if (!latest   || d > latest)   latest = d;
      });
    });

    // Headline + sub
    document.getElementById('ovSnapTitle').textContent = (dataset.source || 'Project') + ' — snapshot';
    document.getElementById('ovSnapSub').textContent  = rowCount + ' respondents · ' + allVars.length + ' variables · completion ' + Math.round(completeRate * 100) + '%';

    const cards = [
      { label: 'Respondents',  value: rowCount,            sub: 'rows of data' },
      { label: 'Variables',    value: allVars.length,      sub: byType.likert + ' Likert · ' + byType.numeric + ' numeric · ' + byType.categorical + ' categorical' },
      { label: 'Completion',   value: Math.round(completeRate * 100) + '%', sub: complete + ' of ' + rowCount + ' rows complete' },
      { label: 'Open-ended',   value: byType.open,         sub: byType.open ? 'free-text fields' : 'no free-text fields' },
      { label: 'ID columns',   value: byType.id,           sub: byType.id ? 'respondent identifier(s)' : 'no identifier column' },
      { label: 'Date columns', value: byType.date,         sub: earliest && latest ? earliest.toLocaleDateString() + ' → ' + latest.toLocaleDateString() : 'no dates parsed' },
    ];
    const grid = document.getElementById('ovSnapGrid');
    grid.innerHTML = cards.map(c =>
      '<div class="ov-snap-card">' +
        '<div class="ov-snap-label">' + esc(c.label) + '</div>' +
        '<div class="ov-snap-value">' + esc(String(c.value)) + '</div>' +
        '<div class="ov-snap-sub">' + esc(c.sub) + '</div>' +
      '</div>'
    ).join('');

    // Extras: purpose statement if set
    const extras = document.getElementById('ovSnapExtras');
    let extrasHtml = '';
    if (purpose) {
      extrasHtml += '<div class="ov-snap-purpose">' +
        '<div class="ov-block-h">Project purpose</div>' +
        '<p>' + esc(purpose) + '</p>' +
      '</div>';
    } else {
      extrasHtml += '<div class="ov-snap-purpose ov-snap-purpose-empty">' +
        '<div class="ov-block-h">Project purpose</div>' +
        '<p>No purpose statement set. Add one on the <a href="/evidence-alignment.php">Evidence Alignment</a> page so executive-summary and methodology drafts have a stated goal to reference.</p>' +
      '</div>';
    }

    // Variable list as a compact table
    extrasHtml += '<div class="ov-var-list">' +
      '<div class="ov-block-h">Variables</div>' +
      '<table class="ov-table">' +
        '<thead><tr><th>Name</th><th>Type(s)</th><th class="ov-num">Missing</th></tr></thead>' +
        '<tbody>' +
          allVars.map(v => {
            const missing = v.values.filter(isMissing).length;
            return '<tr>' +
              '<td><strong>' + esc(v.name) + '</strong></td>' +
              '<td>' + (v.types || []).map(t => '<span class="ov-type-pill">' + esc(t) + '</span>').join(' ') + '</td>' +
              '<td class="ov-num">' + missing + ' (' + Math.round((missing / v.values.length) * 100) + '%)</td>' +
            '</tr>';
          }).join('') +
        '</tbody>' +
      '</table>' +
    '</div>';
    extras.innerHTML = extrasHtml;

    exposeAppState({
      lens: 'project_snapshot',
      n: rowCount,
      variables: allVars.length,
      byType: byType,
      completion: completeRate,
      dateRange: earliest && latest ? [earliest.toISOString(), latest.toISOString()] : null,
      purpose: purpose,
    });
  }

  // ==================================================================
  // LENS: sample_profile
  // ==================================================================
  function renderSampleProfile() {
    const cats = allVars.filter(isCategorical);
    document.getElementById('ovSpSub').textContent = cats.length
      ? cats.length + ' categorical variable' + (cats.length === 1 ? '' : 's') + ' · n = ' + rowCount
      : 'No categorical variables to profile.';
    const grid = document.getElementById('ovSpGrid');
    grid.innerHTML = '';
    if (!cats.length) {
      grid.innerHTML = '<p class="ov-flat">No categorical variables in this dataset.</p>';
      exposeAppState({ lens: 'sample_profile', categoricalVars: 0 });
      return;
    }

    cats.forEach(v => {
      const counts = new Map();
      let missing = 0;
      v.values.forEach(val => {
        if (isMissing(val)) { missing++; return; }
        const k = String(val);
        counts.set(k, (counts.get(k) || 0) + 1);
      });
      const total = rowCount - missing;
      const rows = Array.from(counts.entries())
        .map(([level, c]) => ({ level, count: c, pct: total ? c / total : 0 }))
        .sort((a, b) => b.count - a.count);
      const maxCount = rows[0] ? rows[0].count : 1;

      const card = document.createElement('article');
      card.className = 'ov-sp-card';
      card.innerHTML =
        '<h4 class="ov-sp-card-h">' + esc(v.name) + '</h4>' +
        '<p class="ov-sp-card-sub">' + rows.length + ' levels · ' + total + ' non-missing' + (missing ? ' · ' + missing + ' missing' : '') + '</p>' +
        '<div class="ov-sp-rows">' +
          rows.map(r =>
            '<div class="ov-sp-row">' +
              '<div class="ov-sp-row-level">' + esc(r.level) + '</div>' +
              '<div class="ov-sp-row-bar"><span style="width:' + Math.round((r.count / maxCount) * 100) + '%"></span></div>' +
              '<div class="ov-sp-row-meta">' + r.count + '<span class="ov-sp-row-pct">' + Math.round(r.pct * 100) + '%</span></div>' +
            '</div>'
          ).join('') +
        '</div>';
      grid.appendChild(card);
    });

    document.getElementById('ovSpFoot').textContent = 'Each card breaks down respondents by the levels of one categorical variable. Use this to confirm sample composition matches what you expected.';

    exposeAppState({
      lens: 'sample_profile',
      categoricalVars: cats.length,
      profile: cats.map(v => {
        const counts = new Map();
        v.values.forEach(val => { if (!isMissing(val)) counts.set(String(val), (counts.get(String(val)) || 0) + 1); });
        return { name: v.name, levels: Array.from(counts.entries()).map(([k, c]) => ({ level: k, count: c })) };
      }),
    });
  }

  // ==================================================================
  // LENS: data_quality
  // ==================================================================
  function renderDataQuality() {
    const checks = [];

    // 1. Duplicate full rows
    const seenFull = new Map();
    let dupFull = 0;
    const dupFullExamples = [];
    for (let i = 0; i < rowCount; i++) {
      const key = allVars.map(v => String(v.values[i] || '')).join('\x1f');
      if (seenFull.has(key)) { dupFull++; if (dupFullExamples.length < 3) dupFullExamples.push(i); }
      else seenFull.set(key, i);
    }

    // 2. Duplicate IDs
    const idVar = allVars.find(v => hasType(v, 'id'));
    let dupId = 0;
    const dupIdExamples = [];
    if (idVar) {
      const seenId = new Set();
      for (let i = 0; i < rowCount; i++) {
        const k = String(idVar.values[i] || '');
        if (!k) continue;
        if (seenId.has(k)) { dupId++; if (dupIdExamples.length < 3) dupIdExamples.push(k); }
        else seenId.add(k);
      }
    }

    // 3. Straight-lining (all Likert values identical for a row)
    const likertVars = allVars.filter(isLikert);
    let straight = 0;
    if (likertVars.length >= 3) {
      for (let i = 0; i < rowCount; i++) {
        const vals = likertVars.map(v => v.values[i]);
        if (vals.some(isMissing)) continue;
        const first = num(vals[0]);
        if (vals.every(v => num(v) === first)) straight++;
      }
    }

    // 4. Numeric outliers (Tukey IQR fences on every numeric/Likert variable)
    let outlierCount = 0;
    const outlierBreakdown = [];
    allVars.filter(v => isNumeric(v) || isLikert(v)).forEach(v => {
      const nums = v.values.map(num).filter(x => x != null);
      if (nums.length < 4) return;
      const q1 = quantile(nums, 0.25), q3 = quantile(nums, 0.75);
      const iqr = q3 - q1;
      const lo = q1 - 1.5 * iqr, hi = q3 + 1.5 * iqr;
      const out = nums.filter(x => x < lo || x > hi).length;
      if (out > 0) { outlierCount += out; outlierBreakdown.push({ name: v.name, n: out }); }
    });

    // 5. Invalid values (non-numeric in numeric/Likert columns)
    let invalid = 0;
    const invalidBreakdown = [];
    allVars.filter(v => isNumeric(v) || isLikert(v)).forEach(v => {
      let inv = 0;
      v.values.forEach(val => {
        if (isMissing(val)) return;
        if (num(val) == null) inv++;
      });
      if (inv) { invalid += inv; invalidBreakdown.push({ name: v.name, n: inv }); }
    });

    // 6. Low-effort opens (< 5 chars among non-missing)
    let lowOpen = 0;
    const openVars = allVars.filter(isOpen);
    let totalOpen = 0;
    openVars.forEach(v => {
      v.values.forEach(val => {
        if (isMissing(val)) return;
        totalOpen++;
        if (String(val).trim().length < 5) lowOpen++;
      });
    });

    // 7. Item-level missingness (any variable > 20% missing)
    const highMiss = [];
    allVars.forEach(v => {
      const miss = v.values.filter(isMissing).length;
      const rate = miss / v.values.length;
      if (rate > 0.20) highMiss.push({ name: v.name, rate: rate });
    });

    // Build check rows
    function check(name, finding, tone, detail) {
      return { name: name, finding: finding, tone: tone, detail: detail };
    }
    checks.push(check('Duplicate full rows',
      dupFull === 0 ? 'None' : dupFull + ' row' + (dupFull === 1 ? '' : 's') + ' identical across all columns',
      dupFull === 0 ? 'ok' : 'alert',
      dupFull ? 'Example row indices: ' + dupFullExamples.map(i => '#' + (i + 1)).join(', ') : 'No identical rows.'));

    checks.push(check('Duplicate IDs',
      idVar ? (dupId === 0 ? 'None (' + idVar.name + ')' : dupId + ' duplicate id' + (dupId === 1 ? '' : 's') + ' in ' + idVar.name) : 'No ID column',
      idVar ? (dupId === 0 ? 'ok' : 'alert') : 'muted',
      idVar ? (dupId ? 'Example ids: ' + dupIdExamples.map(esc).join(', ') : 'All ids unique.') : 'Tag a column as "ID" in Evidence Intake to enable this check.'));

    checks.push(check('Straight-lining on Likert items',
      likertVars.length < 3 ? 'Not assessed (need ≥ 3 Likert items)' : (straight === 0 ? 'None' : straight + ' respondent' + (straight === 1 ? '' : 's') + ' answered every Likert item identically'),
      likertVars.length < 3 ? 'muted' : (straight === 0 ? 'ok' : straight / rowCount > 0.05 ? 'alert' : 'warn'),
      likertVars.length < 3 ? '' : (rowCount ? 'Straight-lining rate: ' + Math.round(straight / rowCount * 100) + '%.' : '')));

    checks.push(check('Numeric outliers (Tukey IQR)',
      outlierCount === 0 ? 'None' : outlierCount + ' value' + (outlierCount === 1 ? '' : 's') + ' outside 1.5×IQR fences',
      outlierCount === 0 ? 'ok' : outlierCount > rowCount * 0.1 ? 'warn' : 'ok',
      outlierBreakdown.length ? 'By variable: ' + outlierBreakdown.map(o => o.name + ' (' + o.n + ')').join(', ') : 'No outliers.'));

    checks.push(check('Invalid numeric values',
      invalid === 0 ? 'None' : invalid + ' non-numeric value' + (invalid === 1 ? '' : 's') + ' in numeric/Likert columns',
      invalid === 0 ? 'ok' : 'alert',
      invalidBreakdown.length ? 'By variable: ' + invalidBreakdown.map(o => o.name + ' (' + o.n + ')').join(', ') : 'All numeric values parse.'));

    checks.push(check('Low-effort open-ends',
      !openVars.length ? 'No open-ended fields' : (lowOpen === 0 ? 'None' : lowOpen + ' answer' + (lowOpen === 1 ? '' : 's') + ' under 5 characters'),
      !openVars.length ? 'muted' : (lowOpen === 0 ? 'ok' : lowOpen / Math.max(totalOpen, 1) > 0.2 ? 'warn' : 'ok'),
      !openVars.length ? '' : 'Of ' + totalOpen + ' total open-ended answers.'));

    checks.push(check('High item-level missingness',
      highMiss.length === 0 ? 'No variable above 20% missing' : highMiss.length + ' variable' + (highMiss.length === 1 ? '' : 's') + ' above 20% missing',
      highMiss.length === 0 ? 'ok' : 'warn',
      highMiss.length ? 'Affected: ' + highMiss.map(o => o.name + ' (' + Math.round(o.rate * 100) + '%)').join(', ') : ''));

    // Score: 100 minus 15 per alert, 5 per warn
    const alerts = checks.filter(c => c.tone === 'alert').length;
    const warns  = checks.filter(c => c.tone === 'warn').length;
    const score = Math.max(0, 100 - 15 * alerts - 5 * warns);
    let band, scoreTone;
    if      (score >= 95) { band = 'Clean'; scoreTone = 'strong'; }
    else if (score >= 85) { band = 'Mostly clean'; scoreTone = 'ok'; }
    else if (score >= 70) { band = 'Needs attention'; scoreTone = 'warn'; }
    else                  { band = 'Significant issues'; scoreTone = 'alert'; }

    // Scorecard
    document.getElementById('ovDqTitle').textContent = 'Data quality: ' + band;
    document.getElementById('ovDqSub').textContent   = score + '/100 · ' + alerts + ' alert' + (alerts === 1 ? '' : 's') + ' · ' + warns + ' warning' + (warns === 1 ? '' : 's');
    document.getElementById('ovDqScorecard').innerHTML =
      '<div class="ov-dq-score" data-tone="' + scoreTone + '">' +
        '<div class="ov-dq-score-num">' + score + '</div>' +
        '<div class="ov-dq-score-meta">' +
          '<div class="ov-dq-score-band">' + esc(band) + '</div>' +
          '<div class="ov-dq-score-sub">' + checks.length + ' checks · ' + alerts + ' alert / ' + warns + ' warn / ' + (checks.length - alerts - warns) + ' clean</div>' +
        '</div>' +
      '</div>';

    // Detail rows
    document.getElementById('ovDqDetail').innerHTML =
      '<ul class="ov-dq-list">' +
        checks.map(c =>
          '<li class="ov-dq-row" data-tone="' + c.tone + '">' +
            '<span class="ov-dq-pip" aria-hidden="true"></span>' +
            '<div class="ov-dq-row-body">' +
              '<div class="ov-dq-row-name">' + esc(c.name) + '</div>' +
              '<div class="ov-dq-row-finding">' + esc(c.finding) + '</div>' +
              (c.detail ? '<div class="ov-dq-row-detail">' + esc(c.detail) + '</div>' : '') +
            '</div>' +
          '</li>'
        ).join('') +
      '</ul>';

    document.getElementById('ovDqInterp').textContent =
      score >= 95   ? 'The dataset is clean. Proceed to analysis with confidence.' :
      score >= 85   ? 'Mostly clean. Address the warnings before publishing strong claims.' :
      score >= 70   ? 'Several issues need attention. Resolve the alerts first; the warnings should be inspected before drawing conclusions.' :
                      'Significant data-quality issues. Resolve the alerts before any analysis is defensible. The current results may be driven by duplicates, invalid values, or straight-lining.';

    exposeAppState({ lens: 'data_quality', score: score, band: band, alerts: alerts, warns: warns, checks: checks });
  }

  // ==================================================================
  // App state
  // ==================================================================
  function exposeAppState(payload) {
    window.RELICHECK_APP_STATE = Object.assign({
      app_key:    'overview',
      app_name:   'Overview (' + (lens === 'project_snapshot' ? 'Project Snapshot' : lens === 'sample_profile' ? 'Sample Profile' : 'Data Quality') + ')',
      summary:    summarize(payload),
      lens:       lens,
      dataset:    { source: dataset.source || '', rowCount: rowCount, fromUpload: datasetSource === 'uploaded' },
      computed_at: new Date().toISOString(),
    }, payload);
  }
  function summarize(p) {
    if (p.lens === 'project_snapshot') return rowCount + ' respondents, ' + allVars.length + ' variables, ' + Math.round((p.completion || 0) * 100) + '% complete.';
    if (p.lens === 'sample_profile')   return 'Sample profiled across ' + (p.categoricalVars || 0) + ' categorical variable(s).';
    if (p.lens === 'data_quality')     return 'Data quality ' + p.score + '/100 (' + p.band + ')';
    return 'Overview';
  }
})();
