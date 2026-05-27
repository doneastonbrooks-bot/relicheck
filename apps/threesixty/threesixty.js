// ReliCheck 360 Studio Analysis Suite
// -------------------------------------------------------------------
// Seven lenses on rater × ratee × competency-score data.
//   rater_group_comparison    per-competency means by rater group
//   self_other_gap            self minus mean(others), banded
//   competency_profile        per-competency aggregate + top/bottom
//   confidentiality_threshold flags rater groups with n < k
//   comment_theme             theme analysis on comments by rater group
//   development_plan          auto-drafted focus-areas list per ratee
//   cohort_summary            aggregate across all ratees
//
// Dataset shape (variables in dataset.variables):
//   ratee_id     types: ['categorical'] (or ['id', 'categorical'])
//   rater_role   types: ['categorical']   values: Self / Peer / Manager / Direct Report
//   rater_id     types: ['id'] (optional)
//   <comp>       types: ['likert'] for each competency
//   <comment>    types: ['open']   for comment fields (optional)

(function () {
  'use strict';

  let dataset = window.T6_DATASET;
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
    document.getElementById('t6Empty').hidden = false;
    return;
  }

  const allVars  = dataset.variables;
  const rowCount = dataset.rowCount || (allVars[0] ? allVars[0].values.length : 0);
  const lens     = window.T6_LENS || 'rater_group_comparison';

  // ==================================================================
  // Identify key columns
  // ==================================================================
  function isMissing(v) { return v === '' || v == null; }
  function hasType(v, t) { return v.types && v.types.indexOf(t) !== -1; }
  function isLikert(v)  { return hasType(v, 'likert'); }
  function isOpen(v)    { return hasType(v, 'open'); }
  function num(v) { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function mean(a) { return a.length ? a.reduce((s, v) => s + v, 0) / a.length : 0; }
  function sd(a) {
    if (a.length < 2) return 0;
    const m = mean(a);
    return Math.sqrt(a.reduce((s, v) => s + (v - m) * (v - m), 0) / (a.length - 1));
  }
  function fmt(x, d) { if (x == null || !isFinite(x)) return '—'; return Number(x).toFixed(d == null ? 2 : d); }
  function pct(x) { return x == null ? '—' : Math.round(x * 100) + '%'; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  const rateeVar = allVars.find(v => /^ratee[_-]?id$/i.test(v.name)) || allVars.find(v => v.name.toLowerCase().indexOf('ratee') !== -1);
  const roleVar  = allVars.find(v => /^rater[_-]?role$/i.test(v.name)) || allVars.find(v => v.name.toLowerCase().indexOf('rater_role') !== -1 || v.name.toLowerCase().indexOf('rater role') !== -1);
  const competencyVars = allVars.filter(v => isLikert(v) && v.name.toLowerCase() !== 'rater_role' && v.name.toLowerCase() !== 'ratee_id');
  const commentVars = allVars.filter(isOpen);

  if (!rateeVar || !roleVar || competencyVars.length < 1) {
    document.getElementById('t6Empty').hidden = false;
    return;
  }

  const ratees = Array.from(new Set(rateeVar.values.filter(v => !isMissing(v)).map(String))).sort();
  const roles  = Array.from(new Set(roleVar.values.filter(v => !isMissing(v)).map(String)));
  // Normalize role ordering: Self first, then Peer / Manager / Direct Report / Other
  const ROLE_ORDER = ['Self', 'Peer', 'Manager', 'Direct Report', 'Direct report', 'Other'];
  roles.sort((a, b) => {
    const ai = ROLE_ORDER.findIndex(r => r.toLowerCase() === a.toLowerCase());
    const bi = ROLE_ORDER.findIndex(r => r.toLowerCase() === b.toLowerCase());
    return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
  });

  // Source ribbon
  document.getElementById('t6Source').setAttribute('data-source', datasetSource);
  document.getElementById('t6SourceLabel').textContent = datasetSource === 'uploaded' ? 'Uploaded 360 data' : 'Sample 360 data';
  document.getElementById('t6SourceMeta').textContent  = (dataset.source || '360 dataset') + '  ·  ' + ratees.length + ' ratees · ' + roles.length + ' rater roles · ' + competencyVars.length + ' competencies';

  // Ratee picker visibility
  const RATEE_LENSES = ['rater_group_comparison', 'self_other_gap', 'competency_profile', 'confidentiality_threshold', 'comment_theme', 'development_plan'];
  if (RATEE_LENSES.indexOf(lens) !== -1) {
    document.getElementById('t6RateePicker').hidden = false;
    const sel = document.getElementById('t6Ratee');
    ratees.forEach(r => { const o = document.createElement('option'); o.value = r; o.textContent = r; sel.appendChild(o); });
    document.getElementById('t6Run').addEventListener('click', () => render());
  }

  render();

  function render() {
    switch (lens) {
      case 'rater_group_comparison':    renderRGC(); break;
      case 'self_other_gap':             renderSOG(); break;
      case 'competency_profile':         renderCP(); break;
      case 'confidentiality_threshold':  renderConfidentiality(); break;
      case 'comment_theme':              renderCommentTheme(); break;
      case 'development_plan':           renderDevelopmentPlan(); break;
      case 'cohort_summary':             renderCohort(); break;
    }
  }

  // ==================================================================
  // Data helpers
  // ==================================================================
  function ratingsForRatee(rateeId) {
    // Returns rows where ratee matches, indexed by role
    const byRole = {};
    roles.forEach(r => { byRole[r] = []; });
    for (let i = 0; i < rowCount; i++) {
      if (String(rateeVar.values[i]) !== String(rateeId)) continue;
      const role = String(roleVar.values[i]);
      if (!byRole[role]) byRole[role] = [];
      byRole[role].push(i);
    }
    return byRole;
  }
  function meanByRole(rateeId, compIdx) {
    const byRole = ratingsForRatee(rateeId);
    const out = {};
    Object.keys(byRole).forEach(role => {
      const vals = byRole[role].map(i => num(competencyVars[compIdx].values[i])).filter(x => x != null);
      out[role] = { mean: vals.length ? mean(vals) : null, n: vals.length };
    });
    return out;
  }
  function statBox(label, value, pillText, pillTone) {
    return '<div class="t6-stat">' +
      '<label>' + esc(label) + '</label>' +
      '<span class="v">' + esc(value) + '</span>' +
      (pillText ? '<span class="t6-pip-pill" data-tone="' + esc(pillTone || 'muted') + '">' + esc(pillText) + '</span>' : '') +
    '</div>';
  }
  function setHeader(name, title, sub) {
    document.getElementById('t6LensName').textContent = name;
    document.getElementById('t6Title').textContent    = title;
    document.getElementById('t6Sub').textContent      = sub || '';
  }
  function setStats(html) { document.getElementById('t6Stats').innerHTML = html || ''; }
  function setBody(html)  { document.getElementById('t6Body').innerHTML  = html; }
  function setInterp(t)   { document.getElementById('t6Interp').textContent = t || ''; }
  function getRateeId() { return document.getElementById('t6Ratee').value || ratees[0]; }

  // ==================================================================
  // LENS: rater_group_comparison
  // ==================================================================
  function renderRGC() {
    const rateeId = getRateeId();
    const table = competencyVars.map((cv, ci) => {
      const r = meanByRole(rateeId, ci);
      return { name: cv.name, byRole: r };
    });
    setHeader('Rater Group Comparison', 'Ratings of ' + rateeId + ' by rater group', roles.join(' · '));
    setStats(
      statBox('Competencies', String(competencyVars.length)) +
      statBox('Rater groups', String(roles.length)) +
      statBox('Total raters', String(Object.values(ratingsForRatee(rateeId)).reduce((s, a) => s + a.length, 0)))
    );
    const allMeans = [];
    table.forEach(r => Object.values(r.byRole).forEach(c => { if (c.mean != null) allMeans.push(c.mean); }));
    const maxScale = allMeans.length ? Math.max.apply(null, allMeans) : 5;
    setBody(
      '<table class="t6-table">' +
        '<thead><tr><th>Competency</th>' +
          roles.map(r => '<th class="t6-num">' + esc(r) + '</th>').join('') +
          '<th class="t6-num">All others</th>' +
        '</tr></thead><tbody>' +
        table.map(row => {
          const selfMean = row.byRole['Self'] ? row.byRole['Self'].mean : null;
          const others = Object.entries(row.byRole).filter(([role]) => role.toLowerCase() !== 'self').map(([, c]) => c.mean).filter(x => x != null);
          const otherMean = others.length ? mean(others) : null;
          return '<tr>' +
            '<td><strong>' + esc(row.name) + '</strong></td>' +
            roles.map(r => {
              const c = row.byRole[r];
              return '<td class="t6-num">' + fmt(c ? c.mean : null, 2) + '<span class="t6-cell-n"> (n=' + (c ? c.n : 0) + ')</span></td>';
            }).join('') +
            '<td class="t6-num"><strong>' + fmt(otherMean, 2) + '</strong></td>' +
          '</tr>';
        }).join('') +
        '</tbody></table>'
    );
    // Find biggest discrepancy
    let biggestGap = null;
    table.forEach(row => {
      const s = row.byRole['Self'] ? row.byRole['Self'].mean : null;
      const others = Object.entries(row.byRole).filter(([role]) => role.toLowerCase() !== 'self').map(([, c]) => c.mean).filter(x => x != null);
      const oM = others.length ? mean(others) : null;
      if (s != null && oM != null) {
        const gap = s - oM;
        if (!biggestGap || Math.abs(gap) > Math.abs(biggestGap.gap)) biggestGap = { comp: row.name, gap: gap, self: s, other: oM };
      }
    });
    setInterp(biggestGap
      ? 'Biggest self/others discrepancy is on <strong>' + biggestGap.comp + '</strong>: ' + rateeId + ' self-rates ' + fmt(biggestGap.self, 2) + ' but others rate ' + fmt(biggestGap.other, 2) + ' (' + (biggestGap.gap >= 0 ? '+' : '') + fmt(biggestGap.gap, 2) + '). The Self-Other Gap lens drills into this view across all competencies.'
      : 'No Self rating available; can\'t compute self/others gaps.');
    expose({ lens: 'rater_group_comparison', ratee: rateeId, table: table });
  }

  // ==================================================================
  // LENS: self_other_gap
  // ==================================================================
  function renderSOG() {
    const rateeId = getRateeId();
    const rows = competencyVars.map((cv, ci) => {
      const r = meanByRole(rateeId, ci);
      const selfMean = r['Self'] ? r['Self'].mean : null;
      const others = Object.entries(r).filter(([role]) => role.toLowerCase() !== 'self').map(([, c]) => c.mean).filter(x => x != null);
      const otherMean = others.length ? mean(others) : null;
      const gap = (selfMean != null && otherMean != null) ? selfMean - otherMean : null;
      let band, tone;
      if (gap == null)            { band = 'no data';     tone = 'muted'; }
      else if (Math.abs(gap) < 0.25) { band = 'aligned'; tone = 'strong'; }
      else if (Math.abs(gap) < 0.75) { band = 'moderate'; tone = 'ok'; }
      else if (gap > 0)           { band = 'over-rates'; tone = 'warn'; }
      else                        { band = 'under-rates'; tone = 'warn'; }
      return { name: cv.name, selfMean: selfMean, otherMean: otherMean, gap: gap, band: band, tone: tone };
    });
    setHeader('Self/Other Gap', rateeId + ': self ratings vs others', competencyVars.length + ' competencies');
    const overs = rows.filter(r => r.gap != null && r.gap > 0.5).length;
    const unders = rows.filter(r => r.gap != null && r.gap < -0.5).length;
    setStats(
      statBox('Competencies', String(competencyVars.length)) +
      statBox('Over-rates', String(overs), overs ? 'blind spots' : 'none', overs ? 'warn' : 'strong') +
      statBox('Under-rates', String(unders), unders ? 'hidden strengths' : 'none', unders ? 'ok' : 'strong')
    );
    const maxAbsGap = Math.max.apply(null, rows.map(r => r.gap != null ? Math.abs(r.gap) : 0)) || 1;
    setBody(
      '<table class="t6-table">' +
        '<thead><tr><th>Competency</th><th class="t6-num">Self</th><th class="t6-num">Others (mean)</th><th class="t6-num">Gap</th><th>Bar (Self vs Others)</th><th>Band</th></tr></thead>' +
        '<tbody>' +
          rows.map(r => {
            const sign = r.gap == null ? '' : (r.gap >= 0 ? '+' : '');
            const w = r.gap != null ? Math.round(Math.abs(r.gap) / maxAbsGap * 50) : 0;
            const bar = r.gap == null ? '' :
              '<div class="t6-sog-bar">' +
                '<div class="t6-sog-mid"></div>' +
                (r.gap >= 0
                  ? '<div class="t6-sog-fill t6-sog-pos" style="width:' + w + '%; left:50%"></div>'
                  : '<div class="t6-sog-fill t6-sog-neg" style="width:' + w + '%; right:50%"></div>') +
              '</div>';
            return '<tr data-tone="' + r.tone + '">' +
              '<td>' + esc(r.name) + '</td>' +
              '<td class="t6-num">' + fmt(r.selfMean, 2) + '</td>' +
              '<td class="t6-num">' + fmt(r.otherMean, 2) + '</td>' +
              '<td class="t6-num"><strong>' + sign + fmt(r.gap, 2) + '</strong></td>' +
              '<td>' + bar + '</td>' +
              '<td><span class="t6-pip-pill" data-tone="' + r.tone + '">' + r.band + '</span></td>' +
            '</tr>';
          }).join('') +
        '</tbody>' +
      '</table>'
    );
    setInterp(
      overs && unders ? 'Mixed self-awareness: ' + rateeId + ' over-rates on ' + overs + ' competency(ies) and under-rates on ' + unders + '. The over-rated areas are common starting points for development conversations.' :
      overs ? rateeId + ' over-rates self on ' + overs + ' competency(ies) — typically these are the most useful focus areas for development.' :
      unders ? rateeId + ' under-rates self on ' + unders + ' competency(ies) — hidden strengths the ratee can lean into.' :
      'Self ratings are well-aligned with others\' ratings across competencies. ' + rateeId + ' has accurate self-perception.'
    );
    expose({ lens: 'self_other_gap', ratee: rateeId, rows: rows });
  }

  // ==================================================================
  // LENS: competency_profile
  // ==================================================================
  function renderCP() {
    const rateeId = getRateeId();
    const byRole = ratingsForRatee(rateeId);
    const rows = competencyVars.map((cv, ci) => {
      // Mean across all raters (not just self)
      const allVals = [];
      Object.values(byRole).forEach(rowIdxs => {
        rowIdxs.forEach(i => { const v = num(cv.values[i]); if (v != null) allVals.push(v); });
      });
      // Mean of others only (exclude self)
      const otherVals = [];
      Object.entries(byRole).forEach(([role, idxs]) => {
        if (role.toLowerCase() === 'self') return;
        idxs.forEach(i => { const v = num(cv.values[i]); if (v != null) otherVals.push(v); });
      });
      return {
        name: cv.name,
        allMean: allVals.length ? mean(allVals) : null,
        otherMean: otherVals.length ? mean(otherVals) : null,
        sd: otherVals.length > 1 ? sd(otherVals) : null,
        n: otherVals.length,
      };
    }).sort((a, b) => (b.otherMean || 0) - (a.otherMean || 0));

    setHeader('Competency Profile', 'Profile of ' + rateeId, competencyVars.length + ' competencies, ranked by mean of others');
    const top = rows.slice(0, 2);
    const bot = rows.slice(-2);
    setStats(
      statBox('Competencies', String(competencyVars.length)) +
      statBox('Top strength', top[0] ? top[0].name : '—', top[0] ? fmt(top[0].otherMean, 2) : '—', 'strong') +
      statBox('Biggest gap', bot[0] ? bot[bot.length - 1].name : '—', bot[bot.length - 1] ? fmt(bot[bot.length - 1].otherMean, 2) : '—', 'warn')
    );
    const maxM = Math.max.apply(null, rows.map(r => r.otherMean || 0)) || 5;
    setBody(
      '<table class="t6-table">' +
        '<thead><tr><th>Competency</th><th class="t6-num">Mean (others)</th><th class="t6-num">SD</th><th class="t6-num">n</th><th>Bar</th></tr></thead>' +
        '<tbody>' +
          rows.map((r, i) => '<tr data-tone="' + (i < 2 ? 'strong' : i >= rows.length - 2 ? 'warn' : 'ok') + '">' +
            '<td><strong>' + esc(r.name) + '</strong></td>' +
            '<td class="t6-num">' + fmt(r.otherMean, 2) + '</td>' +
            '<td class="t6-num">' + fmt(r.sd, 2) + '</td>' +
            '<td class="t6-num">' + r.n + '</td>' +
            '<td><div class="t6-bar"><span style="width:' + Math.round((r.otherMean || 0) / maxM * 100) + '%"></span></div></td>' +
          '</tr>').join('') +
        '</tbody>' +
      '</table>'
    );
    setInterp(
      top.length && bot.length
        ? rateeId + '\'s strengths are <strong>' + top.map(t => t.name).join(' and ') + '</strong>; gaps are <strong>' + bot.map(b => b.name).join(' and ') + '</strong>. Move to Development Plan for a focus-areas synthesis.'
        : 'Not enough rater data to profile.'
    );
    expose({ lens: 'competency_profile', ratee: rateeId, profile: rows });
  }

  // ==================================================================
  // LENS: confidentiality_threshold
  // ==================================================================
  function renderConfidentiality() {
    const rateeId = getRateeId();
    const k = 3; // standard threshold
    const byRole = ratingsForRatee(rateeId);
    const rows = roles.map(role => {
      const idxs = byRole[role] || [];
      const safe = idxs.length >= k;
      const tone = safe ? 'strong' : idxs.length === 0 ? 'muted' : 'alert';
      return { role: role, n: idxs.length, safe: safe, tone: tone };
    });
    const totalSafe = rows.filter(r => r.safe).length;
    const violations = rows.filter(r => !r.safe && r.n > 0).length;

    setHeader('Confidentiality Threshold', rateeId + ': rater-group sizes vs k = ' + k, 'k-anonymity for raters; below ' + k + ' raters in a group means individual responses could be identifiable');
    setStats(
      statBox('Threshold (k)', String(k)) +
      statBox('Safe groups', String(totalSafe)) +
      statBox('Violations', String(violations), violations ? 'suppress' : 'none', violations ? 'alert' : 'strong')
    );
    setBody(
      '<table class="t6-table">' +
        '<thead><tr><th>Rater group</th><th class="t6-num">n</th><th>Status</th><th>Recommended action</th></tr></thead>' +
        '<tbody>' +
          rows.map(r => '<tr data-tone="' + r.tone + '">' +
            '<td>' + esc(r.role) + '</td>' +
            '<td class="t6-num">' + r.n + '</td>' +
            '<td><span class="t6-pip-pill" data-tone="' + r.tone + '">' + (r.safe ? 'safe (n ≥ ' + k + ')' : r.n === 0 ? 'no raters' : 'below k') + '</span></td>' +
            '<td>' + (r.safe ? 'Report group means normally.' : r.n === 0 ? 'Nothing to report for this group.' : 'Suppress this group\'s ratings, or pool with another group, before publishing the report.') + '</td>' +
          '</tr>').join('') +
        '</tbody>' +
      '</table>'
    );
    setInterp(
      violations
        ? violations + ' rater group(s) for ' + rateeId + ' are below the k = ' + k + ' confidentiality threshold. Either suppress these groups\' ratings from the published report, or pool them with adjacent groups (e.g., combine "Peer" and "Direct Report" into "Other") before sharing back with the ratee.'
        : 'All rater groups for ' + rateeId + ' meet the k = ' + k + ' threshold. The report can publish group-level means without risk of individual identification.'
    );
    expose({ lens: 'confidentiality_threshold', ratee: rateeId, k: k, groups: rows, violations: violations });
  }

  // ==================================================================
  // LENS: comment_theme
  // ==================================================================
  function renderCommentTheme() {
    const rateeId = getRateeId();
    if (!commentVars.length) {
      setHeader('Comment Theme', 'No comment fields', 'This 360 instrument has no open-ended comment columns.');
      setBody('<p class="t6-flat">Tag at least one column as "Open-ended" in Evidence Intake to enable comment-theme analysis.</p>');
      setInterp('');
      expose({ lens: 'comment_theme', ratee: rateeId, empty: true });
      return;
    }
    // Read codebook (shared with MM)
    let codebook = [];
    try { codebook = JSON.parse(window.localStorage.getItem('relicheck.codebook.' + projectId) || '[]') || []; } catch (e) {}

    // Build per-role comment lists
    const byRole = ratingsForRatee(rateeId);
    const commentsByRole = {};
    Object.entries(byRole).forEach(([role, idxs]) => {
      commentsByRole[role] = [];
      idxs.forEach(i => {
        commentVars.forEach(cv => {
          const text = cv.values[i];
          if (text && String(text).trim().length) commentsByRole[role].push(String(text).trim());
        });
      });
    });
    const totalComments = Object.values(commentsByRole).reduce((s, a) => s + a.length, 0);

    setHeader('Comment Theme', rateeId + ': comments by rater group', totalComments + ' total comment(s) across ' + roles.length + ' groups');

    if (!codebook.length) {
      // Show raw comments per role
      setStats(
        statBox('Total comments', String(totalComments)) +
        statBox('Themes defined', '0', 'use Codebook Builder', 'warn')
      );
      setBody(
        '<p class="t6-detail-note">No codebook defined yet. Visit <a href="/codebook-builder.php?studio=mm">Codebook Builder</a> to define themes; this lens will then auto-tag comments by theme.</p>' +
        '<div class="t6-comment-grid">' +
          roles.map(r => '<div class="t6-comment-col">' +
            '<h4>' + esc(r) + ' <span class="t6-comment-count">(' + (commentsByRole[r] || []).length + ')</span></h4>' +
            ((commentsByRole[r] || []).length
              ? '<ul class="t6-comment-list">' + commentsByRole[r].map(c => '<li><span class="t6-comment-mark">"</span>' + esc(c) + '</li>').join('') + '</ul>'
              : '<p class="t6-flat-small">No comments.</p>') +
          '</div>').join('') +
        '</div>'
      );
      setInterp('Showing raw comments. Define a codebook to see theme-level aggregation across rater groups.');
      expose({ lens: 'comment_theme', ratee: rateeId, raw: true, commentsByRole: commentsByRole });
      return;
    }

    // Theme tagging
    function matches(text, theme) {
      if (!theme.keywords || !theme.keywords.length) return false;
      const lower = String(text || '').toLowerCase();
      return theme.keywords.some(kw => kw && lower.indexOf(String(kw).toLowerCase()) !== -1);
    }
    const themeRows = codebook.map(t => {
      const counts = {};
      roles.forEach(r => { counts[r] = (commentsByRole[r] || []).filter(c => matches(c, t)).length; });
      return { theme: t, counts: counts, total: Object.values(counts).reduce((s, v) => s + v, 0) };
    }).sort((a, b) => b.total - a.total);
    setStats(
      statBox('Themes', String(codebook.length)) +
      statBox('Total comments', String(totalComments)) +
      statBox('Tagged', String(themeRows.reduce((s, t) => s + t.total, 0)))
    );
    const maxCount = Math.max.apply(null, themeRows.map(t => Math.max.apply(null, Object.values(t.counts)))) || 1;
    setBody(
      '<table class="t6-table t6-table-grid">' +
        '<thead><tr><th>Theme</th>' + roles.map(r => '<th>' + esc(r) + '</th>').join('') + '</tr></thead>' +
        '<tbody>' +
          themeRows.map(t => '<tr>' +
            '<td><strong>' + esc(t.theme.name || 'Untitled') + '</strong></td>' +
            roles.map(r => {
              const c = t.counts[r];
              const intensity = maxCount ? c / maxCount : 0;
              return '<td class="t6-cell" style="--t6-intensity:' + intensity.toFixed(2) + '">' + c + '</td>';
            }).join('') +
          '</tr>').join('') +
        '</tbody>' +
      '</table>'
    );
    const top = themeRows[0];
    setInterp(
      top && top.total
        ? '"' + (top.theme.name || 'Untitled') + '" appears most often in comments for ' + rateeId + ' (' + top.total + ' mentions). Where it concentrates by rater group is the interesting signal — e.g., a theme only the Self mentions can indicate a blind spot from others, while one only Direct Reports mention can indicate a leadership-style issue.'
        : 'No themes matched the comments. Refine codebook keywords.'
    );
    expose({ lens: 'comment_theme', ratee: rateeId, themeRows: themeRows });
  }

  // ==================================================================
  // LENS: development_plan
  // ==================================================================
  function renderDevelopmentPlan() {
    const rateeId = getRateeId();
    // Profile data + self-other gaps
    const profile = competencyVars.map((cv, ci) => {
      const r = meanByRole(rateeId, ci);
      const others = Object.entries(r).filter(([role]) => role.toLowerCase() !== 'self').map(([, c]) => c.mean).filter(x => x != null);
      const oM = others.length ? mean(others) : null;
      const s = r['Self'] ? r['Self'].mean : null;
      return { name: cv.name, otherMean: oM, selfMean: s, gap: s != null && oM != null ? s - oM : null };
    });
    const sortedByLow = profile.slice().sort((a, b) => (a.otherMean || 0) - (b.otherMean || 0));
    const sortedByGap = profile.slice().sort((a, b) => Math.abs(b.gap || 0) - Math.abs(a.gap || 0));
    const lowestN = sortedByLow.slice(0, 2);
    const biggestGap = sortedByGap.filter(p => Math.abs(p.gap || 0) > 0.5).slice(0, 1);

    setHeader('Development Plan', 'Auto-drafted focus areas for ' + rateeId, 'From lowest competencies + biggest self/others gaps');
    setStats(
      statBox('Lowest competencies', lowestN.map(p => p.name).join(', ') || '—') +
      statBox('Biggest blind spot', biggestGap[0] ? biggestGap[0].name : 'none')
    );
    const sections = [];
    sections.push({
      h: 'Focus area 1: ' + (lowestN[0] ? lowestN[0].name : '—'),
      lines: lowestN[0] ? [
        'Mean rating from others: ' + fmt(lowestN[0].otherMean, 2) + '.',
        lowestN[0].gap != null ? 'Self-rating: ' + fmt(lowestN[0].selfMean, 2) + ' (gap of ' + fmt(lowestN[0].gap, 2) + ').' : '',
        'Why this is a focus area: this is the lowest-rated competency in ' + rateeId + '\'s profile. Even small improvements here will be noticed by raters.',
      ].filter(s => s) : [],
    });
    if (lowestN[1]) {
      sections.push({
        h: 'Focus area 2: ' + lowestN[1].name,
        lines: [
          'Mean rating from others: ' + fmt(lowestN[1].otherMean, 2) + '.',
          lowestN[1].gap != null ? 'Self-rating: ' + fmt(lowestN[1].selfMean, 2) + ' (gap of ' + fmt(lowestN[1].gap, 2) + ').' : '',
        ].filter(s => s),
      });
    }
    if (biggestGap[0] && !lowestN.some(p => p.name === biggestGap[0].name)) {
      sections.push({
        h: 'Self-awareness focus: ' + biggestGap[0].name,
        lines: [
          'Self-rating: ' + fmt(biggestGap[0].selfMean, 2) + '; others\' mean: ' + fmt(biggestGap[0].otherMean, 2) + '.',
          biggestGap[0].gap > 0
            ? rateeId + ' rates themselves higher than others do on this competency. The gap is a calibration opportunity rather than a skill gap — seek specific feedback before assuming the rating is "right."'
            : rateeId + ' rates themselves lower than others do. This may be a hidden strength to lean into; check what others are seeing that ' + rateeId + ' is not.',
        ],
      });
    }
    setBody(
      '<div class="t6-dev-plan">' +
        sections.map(s =>
          '<section class="t6-dev-section">' +
            '<h4>' + esc(s.h) + '</h4>' +
            s.lines.map(line => '<p>' + esc(line) + '</p>').join('') +
          '</section>'
        ).join('') +
        '<section class="t6-dev-section">' +
          '<h4>Strengths to keep using</h4>' +
          (() => {
            const strengths = profile.slice().sort((a, b) => (b.otherMean || 0) - (a.otherMean || 0)).slice(0, 2);
            return '<p>' + esc(rateeId) + ' is rated highest on <strong>' + strengths.map(s => s.name).join('</strong> and <strong>') + '</strong>. Building development around these existing strengths often outpaces fixing weaknesses.</p>';
          })() +
        '</section>' +
      '</div>'
    );
    setInterp('A starting point, not a finished plan. Use the focus areas as a conversation prompt with ' + rateeId + ' and a manager; the actual development plan should be co-created.');
    expose({ lens: 'development_plan', ratee: rateeId, focusAreas: sections.map(s => s.h) });
  }

  // ==================================================================
  // LENS: cohort_summary
  // ==================================================================
  function renderCohort() {
    document.getElementById('t6RateePicker').hidden = true;

    // Aggregate per competency: cohort mean (all ratees, others-only), cohort SD, n_ratees rated
    const stats = competencyVars.map((cv, ci) => {
      const perRatee = ratees.map(rid => {
        const r = meanByRole(rid, ci);
        const others = Object.entries(r).filter(([role]) => role.toLowerCase() !== 'self').map(([, c]) => c.mean).filter(x => x != null);
        return others.length ? mean(others) : null;
      }).filter(x => x != null);
      return { name: cv.name, mean: perRatee.length ? mean(perRatee) : null, sd: perRatee.length > 1 ? sd(perRatee) : null, n: perRatee.length };
    }).sort((a, b) => (b.mean || 0) - (a.mean || 0));

    setHeader('Cohort Summary', 'Aggregate across ' + ratees.length + ' ratees', competencyVars.length + ' competencies, others\' ratings only');
    const top = stats[0], bot = stats[stats.length - 1];
    setStats(
      statBox('Ratees', String(ratees.length)) +
      statBox('Cohort top', top ? top.name : '—', top ? fmt(top.mean, 2) : '—', 'strong') +
      statBox('Cohort gap', bot ? bot.name : '—', bot ? fmt(bot.mean, 2) : '—', 'warn')
    );
    const maxM = Math.max.apply(null, stats.map(s => s.mean || 0)) || 5;
    setBody(
      '<table class="t6-table">' +
        '<thead><tr><th>Competency</th><th class="t6-num">Cohort mean</th><th class="t6-num">SD across ratees</th><th class="t6-num">n ratees</th><th>Bar</th></tr></thead>' +
        '<tbody>' +
          stats.map((s, i) => '<tr data-tone="' + (i < 2 ? 'strong' : i >= stats.length - 2 ? 'warn' : 'ok') + '">' +
            '<td><strong>' + esc(s.name) + '</strong></td>' +
            '<td class="t6-num">' + fmt(s.mean, 2) + '</td>' +
            '<td class="t6-num">' + fmt(s.sd, 2) + '</td>' +
            '<td class="t6-num">' + s.n + '</td>' +
            '<td><div class="t6-bar"><span style="width:' + Math.round((s.mean || 0) / maxM * 100) + '%"></span></div></td>' +
          '</tr>').join('') +
        '</tbody>' +
      '</table>'
    );
    setInterp(
      stats.length
        ? 'Across the cohort of ' + ratees.length + ' ratees, the strongest competency is <strong>' + top.name + '</strong> (mean ' + fmt(top.mean, 2) + ') and the weakest is <strong>' + bot.name + '</strong> (mean ' + fmt(bot.mean, 2) + '). Cohort-wide gaps point to organizational development needs — consider group training on the weakest competencies.'
        : 'No ratees with adequate data to summarize.'
    );
    expose({ lens: 'cohort_summary', ratees: ratees, competencyStats: stats });
  }

  // ==================================================================
  // Helpers
  // ==================================================================
  function expose(payload) {
    window.RELICHECK_APP_STATE = Object.assign({
      app_key:  'threesixty_analysis',
      app_name: '360 (' + lens + ')',
      summary:  summarize(payload),
      lens:     lens,
      computed_at: new Date().toISOString(),
    }, payload);
  }
  function summarize(p) {
    if (p.lens === 'rater_group_comparison')   return 'Ratings of ' + (p.ratee || '—') + ' across ' + roles.length + ' rater groups.';
    if (p.lens === 'self_other_gap')           return 'Self/other gap for ' + (p.ratee || '—') + '.';
    if (p.lens === 'competency_profile')       return 'Profile of ' + (p.ratee || '—') + ' (' + competencyVars.length + ' competencies).';
    if (p.lens === 'confidentiality_threshold')return 'Confidentiality: ' + (p.violations || 0) + ' violations at k=' + (p.k || 3) + '.';
    if (p.lens === 'comment_theme')            return 'Comment themes for ' + (p.ratee || '—') + '.';
    if (p.lens === 'development_plan')         return 'Dev plan for ' + (p.ratee || '—') + ': ' + ((p.focusAreas || []).length) + ' focus areas.';
    if (p.lens === 'cohort_summary')           return 'Cohort across ' + (p.ratees || []).length + ' ratees.';
    return '360 analysis';
  }
})();
