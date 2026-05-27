// ReliCheck TIA Analysis Suite
// -------------------------------------------------------------------
// Five lenses on student-by-item response data with an answer key:
//   item_difficulty       p-value (% correct) per item
//   item_discrimination   point-biserial + upper-lower index
//   distractor_analysis   for MC items, which distractors function
//   answer_key_validation flags miskeyed items
//   dif                   per-item gap across a chosen demographic
//
// Dataset shape expected:
//   { source, variables: [...], rowCount, answer_key: [
//       { item: 'q1', correct: 'A', max: 1, type: 'MC' }, ...
//   ] }
// Variables tagged 'item_response' are scored against the answer key.

(function () {
  'use strict';

  // ==================================================================
  // Dataset
  // ==================================================================
  let dataset = window.TIA_DATASET;
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
  } catch (e) {}

  if (!dataset || !Array.isArray(dataset.variables) || !Array.isArray(dataset.answer_key)) {
    document.getElementById('tiaEmpty').hidden = false;
    return;
  }

  const allVars   = dataset.variables;
  const answerKey = dataset.answer_key;
  const rowCount  = dataset.rowCount || (allVars[0] ? allVars[0].values.length : 0);
  const lens      = window.TIA_LENS || 'item_difficulty';

  // ==================================================================
  // Helpers
  // ==================================================================
  function isMissing(v) { return v === '' || v == null; }
  function hasType(v, t) { return v.types && v.types.indexOf(t) !== -1; }
  function isItemResponse(v) { return hasType(v, 'item_response'); }
  function isCategorical(v) { return hasType(v, 'categorical'); }
  function mean(a) { return a.length ? a.reduce((s, v) => s + v, 0) / a.length : 0; }
  function variance(a) {
    if (a.length < 2) return 0;
    const m = mean(a);
    return a.reduce((s, v) => s + (v - m) * (v - m), 0) / (a.length - 1);
  }
  function sd(a) { return Math.sqrt(variance(a)); }
  function pearson(a, b) {
    if (a.length !== b.length || a.length < 2) return 0;
    const ma = mean(a), mb = mean(b);
    let cov = 0, va = 0, vb = 0;
    for (let i = 0; i < a.length; i++) {
      cov += (a[i] - ma) * (b[i] - mb);
      va  += (a[i] - ma) * (a[i] - ma);
      vb  += (b[i] - mb) * (b[i] - mb);
    }
    const denom = Math.sqrt(va * vb);
    return denom === 0 ? 0 : cov / denom;
  }
  function fmt(x, d) { if (x == null || !isFinite(x)) return '—'; return Number(x).toFixed(d == null ? 2 : d); }
  function pct(x) { return x == null ? '—' : Math.round(x * 100) + '%'; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  // ==================================================================
  // Score per student per item, plus total scores per student
  // ==================================================================
  const itemVars = allVars.filter(isItemResponse);
  const keyByItem = {};
  answerKey.forEach(k => { keyByItem[k.item] = k; });

  // Per-row score matrix: scores[row][itemIdx] = 1 (correct) | 0 (wrong) | null (missing)
  const scores = [];
  for (let i = 0; i < rowCount; i++) {
    const row = itemVars.map(v => {
      const ans = v.values[i];
      const key = keyByItem[v.name];
      if (!key || isMissing(ans)) return null;
      return String(ans).trim().toLowerCase() === String(key.correct).trim().toLowerCase() ? 1 : 0;
    });
    scores.push(row);
  }
  // Total score per student (sum of non-null item scores)
  const totals = scores.map(row => row.reduce((s, v) => s + (v == null ? 0 : v), 0));
  const meanTotal = mean(totals);

  // Source ribbon
  document.getElementById('tiaSource').setAttribute('data-source', datasetSource);
  document.getElementById('tiaSourceLabel').textContent = datasetSource === 'uploaded' ? 'Uploaded test data' : 'Sample test data';
  document.getElementById('tiaSourceMeta').textContent  = (dataset.source || 'Test') + '  ·  ' + rowCount + ' students · ' + itemVars.length + ' items';

  if (lens === 'dif') {
    document.getElementById('tiaSetup').hidden = false;
    document.querySelectorAll('.tia-lens-setup').forEach(el => {
      el.hidden = el.getAttribute('data-lens') !== lens;
    });
    const catSel = document.getElementById('tiaDifGroup');
    const cats = allVars.filter(isCategorical);
    catSel.innerHTML = '';
    if (!cats.length) {
      const o = document.createElement('option'); o.value = ''; o.textContent = '— no categorical variables —';
      catSel.appendChild(o); catSel.disabled = true;
    } else {
      cats.forEach(v => { const o = document.createElement('option'); o.value = v.name; o.textContent = v.name; catSel.appendChild(o); });
    }
    document.getElementById('tiaDifRun').addEventListener('click', runDif);
  } else {
    document.getElementById('tiaSetup').hidden = true;
  }

  switch (lens) {
    case 'item_difficulty':       renderDifficulty(); break;
    case 'item_discrimination':   renderDiscrimination(); break;
    case 'distractor_analysis':   renderDistractor(); break;
    case 'answer_key_validation': renderAnswerKey(); break;
    case 'dif':                   /* run on click */ break;
  }

  // ==================================================================
  // LENS: item_difficulty
  // ==================================================================
  function renderDifficulty() {
    const rows = itemVars.map((v, j) => {
      const responses = scores.map(r => r[j]).filter(x => x != null);
      const correct = responses.filter(x => x === 1).length;
      const total = responses.length;
      const p = total ? correct / total : 0;
      let band, tone;
      if      (p >= 0.90) { band = 'Very easy';  tone = 'warn';  }
      else if (p >= 0.70) { band = 'Easy';        tone = 'ok';    }
      else if (p >= 0.40) { band = 'Moderate';    tone = 'strong';}
      else if (p >= 0.20) { band = 'Hard';        tone = 'ok';    }
      else                { band = 'Very hard';   tone = 'warn';  }
      return { name: v.name, correct: correct, total: total, p: p, band: band, tone: tone };
    }).sort((a, b) => b.p - a.p);

    setHeader('Item Difficulty', 'p-values (% correct) per item', rows.length + ' items, n = ' + rowCount + ' students');
    const stats =
      statBox('Items', String(rows.length)) +
      statBox('Mean p', fmt(rows.reduce((s, r) => s + r.p, 0) / Math.max(rows.length, 1), 2)) +
      statBox('Very easy (p ≥ 0.90)', String(rows.filter(r => r.p >= 0.90).length)) +
      statBox('Very hard (p < 0.20)', String(rows.filter(r => r.p < 0.20).length));
    setStats(stats);

    setBody(
      '<table class="tia-table">' +
        '<thead><tr><th>Item</th><th class="tia-num">Correct</th><th class="tia-num">n</th><th class="tia-num">p</th><th></th><th>Band</th></tr></thead>' +
        '<tbody>' +
          rows.map(r => '<tr data-tone="' + r.tone + '">' +
            '<td>' + esc(r.name) + '</td>' +
            '<td class="tia-num">' + r.correct + '</td>' +
            '<td class="tia-num">' + r.total + '</td>' +
            '<td class="tia-num"><strong>' + fmt(r.p, 2) + '</strong></td>' +
            '<td><div class="tia-bar"><span style="width:' + Math.round(r.p * 100) + '%"></span></div></td>' +
            '<td><span class="tia-pip-pill" data-tone="' + r.tone + '">' + r.band + '</span></td>' +
          '</tr>').join('') +
        '</tbody>' +
      '</table>'
    );
    const veryEasy = rows.filter(r => r.p >= 0.90).length;
    const veryHard = rows.filter(r => r.p < 0.20).length;
    setInterp(
      veryEasy + veryHard === 0 ? 'No items are extreme. Difficulty spread looks healthy for a test.' :
      veryEasy + veryHard < rows.length / 4 ? veryEasy + ' very easy and ' + veryHard + ' very hard item(s). The test has range; review the extremes only if they\'re critical to your scoring.' :
      'A meaningful portion of items are at the extremes (' + veryEasy + ' very easy, ' + veryHard + ' very hard). The test may not discriminate well across the ability range.'
    );
    expose({ lens: 'item_difficulty', items: rows });
  }

  // ==================================================================
  // LENS: item_discrimination
  // ==================================================================
  function renderDiscrimination() {
    const cutoff = Math.max(1, Math.round(rowCount * 0.27));
    const sortedIdx = totals.map((t, i) => ({ t, i })).sort((a, b) => b.t - a.t);
    const upperIdx = new Set(sortedIdx.slice(0, cutoff).map(o => o.i));
    const lowerIdx = new Set(sortedIdx.slice(-cutoff).map(o => o.i));

    const rows = itemVars.map((v, j) => {
      const itemScores = scores.map(r => r[j]);
      // Rest score = total minus this item (avoid self-correlation)
      const validIdx = [], item = [], rest = [];
      for (let i = 0; i < rowCount; i++) {
        if (itemScores[i] == null) continue;
        validIdx.push(i);
        item.push(itemScores[i]);
        rest.push(totals[i] - itemScores[i]);
      }
      const r = pearson(item, rest);
      // Upper-lower index: p_upper - p_lower
      let upN = 0, upC = 0, loN = 0, loC = 0;
      validIdx.forEach((rowI, k) => {
        if (upperIdx.has(rowI)) { upN++; if (item[k] === 1) upC++; }
        if (lowerIdx.has(rowI)) { loN++; if (item[k] === 1) loC++; }
      });
      const pUp = upN ? upC / upN : 0;
      const pLo = loN ? loC / loN : 0;
      const D = pUp - pLo;
      let band, tone;
      if      (D >= 0.40) { band = 'Excellent'; tone = 'strong'; }
      else if (D >= 0.30) { band = 'Good';      tone = 'ok'; }
      else if (D >= 0.20) { band = 'Adequate';  tone = 'ok'; }
      else if (D >= 0.00) { band = 'Weak';      tone = 'warn'; }
      else                { band = 'Reverse';   tone = 'alert'; }
      return { name: v.name, r: r, D: D, pUp: pUp, pLo: pLo, band: band, tone: tone };
    }).sort((a, b) => b.D - a.D);

    setHeader('Item Discrimination', 'Point-biserial r + upper-lower index (D)', rows.length + ' items · top/bottom ' + cutoff + ' students (27% groups)');
    const stats =
      statBox('Items', String(rows.length)) +
      statBox('Mean D', fmt(rows.reduce((s, r) => s + r.D, 0) / Math.max(rows.length, 1), 2)) +
      statBox('Excellent (D ≥ 0.40)', String(rows.filter(r => r.D >= 0.40).length)) +
      statBox('Reverse (D < 0)', String(rows.filter(r => r.D < 0).length));
    setStats(stats);
    setBody(
      '<table class="tia-table">' +
        '<thead><tr><th>Item</th><th class="tia-num">Point-biserial r</th><th class="tia-num">p (upper)</th><th class="tia-num">p (lower)</th><th class="tia-num">D</th><th>Band</th></tr></thead>' +
        '<tbody>' +
          rows.map(r => '<tr data-tone="' + r.tone + '">' +
            '<td>' + esc(r.name) + '</td>' +
            '<td class="tia-num">' + fmt(r.r, 2) + '</td>' +
            '<td class="tia-num">' + fmt(r.pUp, 2) + '</td>' +
            '<td class="tia-num">' + fmt(r.pLo, 2) + '</td>' +
            '<td class="tia-num"><strong>' + fmt(r.D, 2) + '</strong></td>' +
            '<td><span class="tia-pip-pill" data-tone="' + r.tone + '">' + r.band + '</span></td>' +
          '</tr>').join('') +
        '</tbody>' +
      '</table>' +
      '<p class="tia-detail-note">D = p<sub>upper</sub> − p<sub>lower</sub> on the top and bottom 27% of students by total score (Kelley\'s rule). D ≥ 0.40 is excellent; D &lt; 0 means the item is harder for higher-scoring students — almost always a sign of a miskeyed item.</p>'
    );
    const reverses = rows.filter(r => r.D < 0).length;
    setInterp(
      reverses ? reverses + ' item(s) discriminate IN REVERSE (D < 0) — almost certainly miskeyed. Check the Answer Key Validation lens.' :
      rows.filter(r => r.D < 0.20).length ? rows.filter(r => r.D < 0.20).length + ' item(s) discriminate weakly. Inspect content.' :
      'All items discriminate at least adequately (D ≥ 0.20).'
    );
    expose({ lens: 'item_discrimination', items: rows });
  }

  // ==================================================================
  // LENS: distractor_analysis
  // ==================================================================
  function renderDistractor() {
    const cutoff = Math.max(1, Math.round(rowCount * 0.27));
    const sortedIdx = totals.map((t, i) => ({ t, i })).sort((a, b) => b.t - a.t);
    const upperIdx = new Set(sortedIdx.slice(0, cutoff).map(o => o.i));
    const lowerIdx = new Set(sortedIdx.slice(-cutoff).map(o => o.i));

    const mcItems = itemVars.filter(v => (keyByItem[v.name] || {}).type !== 'Constructed' && (keyByItem[v.name] || {}).type !== 'Rubric');
    if (!mcItems.length) {
      setHeader('Distractor Analysis', 'No MC/T-F items found', 'Distractor analysis applies to multiple-choice items.');
      setBody('<p class="tia-flat">No MC items in this test. Distractor analysis requires items with a finite set of choice labels (A/B/C/D, T/F).</p>');
      setInterp('Tag items as MC or T/F in Evidence Intake to enable this lens.');
      expose({ lens: 'distractor_analysis', items: [] });
      return;
    }
    const rows = mcItems.map(v => {
      const key = keyByItem[v.name].correct;
      const allChoices = new Map();
      v.values.forEach(val => { if (isMissing(val)) return; const k = String(val); allChoices.set(k, (allChoices.get(k) || 0) + 1); });
      const total = Array.from(allChoices.values()).reduce((s, x) => s + x, 0);
      const choices = Array.from(allChoices.entries()).sort((a, b) => a[0].localeCompare(b[0]));
      // For each choice, count upper vs lower
      const breakdown = choices.map(([choice, count]) => {
        let upN = 0, loN = 0;
        for (let i = 0; i < rowCount; i++) {
          if (isMissing(v.values[i])) continue;
          if (String(v.values[i]) !== choice) continue;
          if (upperIdx.has(i)) upN++;
          if (lowerIdx.has(i)) loN++;
        }
        const isKey = String(choice) === String(key);
        const isFunctioning = !isKey && (loN > upN || count >= 2); // distractor functions if it draws some students, especially low scorers
        const flag = isKey ? 'key' :
                     count === 0 ? 'unused' :
                     loN > upN ? 'functioning' :
                     upN > loN ? 'attracting top scorers' :
                     'functioning';
        let tone;
        if (isKey)                                     tone = 'strong';
        else if (flag === 'unused')                    tone = 'warn';
        else if (flag === 'attracting top scorers')    tone = 'alert';
        else                                           tone = 'ok';
        return { choice: choice, count: count, pct: total ? count / total : 0, upper: upN, lower: loN, flag: flag, tone: tone };
      });
      return { name: v.name, key: key, breakdown: breakdown };
    });
    setHeader('Distractor Analysis', 'Which distractors function on each MC item', mcItems.length + ' MC items reviewed');
    const totalDistractors = rows.reduce((s, r) => s + (r.breakdown.length - 1), 0);
    const unused = rows.reduce((s, r) => s + r.breakdown.filter(b => b.flag === 'unused').length, 0);
    const dangerous = rows.reduce((s, r) => s + r.breakdown.filter(b => b.flag === 'attracting top scorers').length, 0);
    setStats(
      statBox('MC items', String(mcItems.length)) +
      statBox('Distractors total', String(totalDistractors)) +
      statBox('Unused', String(unused), 'review', unused ? 'warn' : 'muted') +
      statBox('Attracts top scorers', String(dangerous), 'inspect', dangerous ? 'alert' : 'muted')
    );
    setBody(
      rows.map(r =>
        '<div class="tia-da-card">' +
          '<div class="tia-da-head"><h4>' + esc(r.name) + '</h4><span class="tia-da-key">key: <strong>' + esc(r.key) + '</strong></span></div>' +
          '<table class="tia-table tia-table-da">' +
            '<thead><tr><th>Choice</th><th class="tia-num">Total</th><th class="tia-num">%</th><th class="tia-num">Upper</th><th class="tia-num">Lower</th><th>Flag</th></tr></thead>' +
            '<tbody>' +
              r.breakdown.map(b => '<tr data-tone="' + b.tone + '">' +
                '<td>' + esc(b.choice) + (b.flag === 'key' ? ' <span class="tia-key-mark">✓</span>' : '') + '</td>' +
                '<td class="tia-num">' + b.count + '</td>' +
                '<td class="tia-num">' + Math.round(b.pct * 100) + '%</td>' +
                '<td class="tia-num">' + b.upper + '</td>' +
                '<td class="tia-num">' + b.lower + '</td>' +
                '<td><span class="tia-pip-pill" data-tone="' + b.tone + '">' + esc(b.flag) + '</span></td>' +
              '</tr>').join('') +
            '</tbody>' +
          '</table>' +
        '</div>'
      ).join('')
    );
    setInterp(
      dangerous ? dangerous + ' distractor(s) attract more top-scoring students than low-scoring ones. These are pulling strong students away from the correct answer — likely poorly worded or genuinely ambiguous.' :
      unused ? unused + ' distractor(s) were never chosen. These are dead weight; replacing them would make the item harder and more discriminating.' :
              'All distractors function properly: they draw students, more from the lower scorers than the upper scorers.'
    );
    expose({ lens: 'distractor_analysis', items: rows });
  }

  // ==================================================================
  // LENS: answer_key_validation
  // ==================================================================
  function renderAnswerKey() {
    const cutoff = Math.max(1, Math.round(rowCount * 0.27));
    const sortedIdx = totals.map((t, i) => ({ t, i })).sort((a, b) => b.t - a.t);
    const upperIdx = new Set(sortedIdx.slice(0, cutoff).map(o => o.i));

    const rows = itemVars.map(v => {
      const key = (keyByItem[v.name] || {}).correct;
      if (key == null) return { name: v.name, key: null, flag: 'no key', tone: 'muted', detail: 'No answer key entry for this item.' };
      // Count which answer the upper group most often chose
      const upperCounts = new Map();
      for (let i = 0; i < rowCount; i++) {
        if (!upperIdx.has(i) || isMissing(v.values[i])) continue;
        const k = String(v.values[i]);
        upperCounts.set(k, (upperCounts.get(k) || 0) + 1);
      }
      let topAnswer = null, topCount = 0;
      upperCounts.forEach((c, a) => { if (c > topCount) { topCount = c; topAnswer = a; } });
      const flag = (topAnswer != null && String(topAnswer).trim().toLowerCase() === String(key).trim().toLowerCase())
        ? 'keyed correctly'
        : 'POSSIBLE MISKEY';
      const tone = flag === 'keyed correctly' ? 'ok' : 'alert';
      const detail = topAnswer == null
        ? 'Upper group had no responses on this item.'
        : 'Upper group most often chose "' + topAnswer + '"' + (flag === 'keyed correctly' ? '; matches the key.' : '; key says "' + key + '". Inspect.');
      return { name: v.name, key: key, topAnswer: topAnswer, flag: flag, tone: tone, detail: detail };
    });
    const miskeyed = rows.filter(r => r.flag === 'POSSIBLE MISKEY').length;
    setHeader('Answer Key Validation', 'Flags items where the keyed answer disagrees with the upper group\'s most-common choice', itemVars.length + ' items checked · upper group: top ' + cutoff + ' students');
    setStats(
      statBox('Items checked', String(rows.length)) +
      statBox('Possible miskeys', String(miskeyed), miskeyed ? 'inspect' : 'all clean', miskeyed ? 'alert' : 'strong') +
      statBox('Upper group size', String(cutoff))
    );
    setBody(
      '<table class="tia-table">' +
        '<thead><tr><th>Item</th><th>Keyed answer</th><th>Upper group\'s top choice</th><th>Flag</th><th>Detail</th></tr></thead>' +
        '<tbody>' +
          rows.map(r => '<tr data-tone="' + r.tone + '">' +
            '<td>' + esc(r.name) + '</td>' +
            '<td>' + esc(r.key || '—') + '</td>' +
            '<td>' + esc(r.topAnswer || '—') + '</td>' +
            '<td><span class="tia-pip-pill" data-tone="' + r.tone + '">' + esc(r.flag) + '</span></td>' +
            '<td>' + esc(r.detail) + '</td>' +
          '</tr>').join('') +
        '</tbody>' +
      '</table>'
    );
    setInterp(
      miskeyed ? miskeyed + ' item(s) flagged as possibly miskeyed. Confirm the answer-key entries before scoring; a single miskey can move every student\'s total score.' :
                 'No miskey flags. The keyed answer matches the upper group\'s most common choice for every item.'
    );
    expose({ lens: 'answer_key_validation', items: rows, miskeyed: miskeyed });
  }

  // ==================================================================
  // LENS: dif (Differential Item Functioning)
  // ==================================================================
  function runDif() {
    const grpName = document.getElementById('tiaDifGroup').value;
    const grp = allVars.find(v => v.name === grpName);
    if (!grp) return;
    const groupLevels = new Map();
    for (let i = 0; i < rowCount; i++) {
      const g = grp.values[i];
      if (isMissing(g)) continue;
      const k = String(g);
      if (!groupLevels.has(k)) groupLevels.set(k, []);
      groupLevels.get(k).push(i);
    }
    const levels = Array.from(groupLevels.keys());
    if (levels.length !== 2) {
      setHeader('Differential Item Functioning', 'Need a 2-level grouping', 'DIF compares item p-values between two demographic groups.');
      setBody('<p class="tia-flat">The chosen variable has ' + levels.length + ' levels. Pick a 2-level categorical (e.g., gender as M/F).</p>');
      setInterp('');
      return;
    }
    const a = groupLevels.get(levels[0]), b = groupLevels.get(levels[1]);
    const rows = itemVars.map((v, j) => {
      const pA = a.filter(i => scores[i][j] === 1).length / Math.max(a.length, 1);
      const pB = b.filter(i => scores[i][j] === 1).length / Math.max(b.length, 1);
      const gap = pA - pB;
      let band, tone;
      if      (Math.abs(gap) < 0.05) { band = 'No DIF';    tone = 'ok'; }
      else if (Math.abs(gap) < 0.15) { band = 'Mild DIF';  tone = 'warn'; }
      else                            { band = 'Strong DIF';tone = 'alert'; }
      return { name: v.name, pA: pA, pB: pB, gap: gap, band: band, tone: tone };
    }).sort((a, b) => Math.abs(b.gap) - Math.abs(a.gap));

    setHeader('Differential Item Functioning', grp.name + ': ' + levels[0] + ' (n=' + a.length + ') vs ' + levels[1] + ' (n=' + b.length + ')', itemVars.length + ' items');
    const strongDif = rows.filter(r => r.band === 'Strong DIF').length;
    const mildDif   = rows.filter(r => r.band === 'Mild DIF').length;
    setStats(
      statBox('Items', String(rows.length)) +
      statBox('Strong DIF (|p₁ − p₂| ≥ 0.15)', String(strongDif), strongDif ? 'inspect' : 'none', strongDif ? 'alert' : 'strong') +
      statBox('Mild DIF (0.05 ≤ gap < 0.15)', String(mildDif), mildDif ? 'watch' : 'none', mildDif ? 'warn' : 'strong') +
      statBox('Clean', String(rows.length - strongDif - mildDif))
    );
    setBody(
      '<table class="tia-table">' +
        '<thead><tr><th>Item</th><th class="tia-num">p — ' + esc(levels[0]) + '</th><th class="tia-num">p — ' + esc(levels[1]) + '</th><th class="tia-num">Gap</th><th>Band</th></tr></thead>' +
        '<tbody>' +
          rows.map(r => '<tr data-tone="' + r.tone + '">' +
            '<td>' + esc(r.name) + '</td>' +
            '<td class="tia-num">' + fmt(r.pA, 2) + '</td>' +
            '<td class="tia-num">' + fmt(r.pB, 2) + '</td>' +
            '<td class="tia-num"><strong>' + (r.gap >= 0 ? '+' : '') + fmt(r.gap, 2) + '</strong></td>' +
            '<td><span class="tia-pip-pill" data-tone="' + r.tone + '">' + r.band + '</span></td>' +
          '</tr>').join('') +
        '</tbody>' +
      '</table>' +
      '<p class="tia-detail-note">This is a simple gap-based DIF screen, not a full Mantel-Haenszel chi-square. Items with strong DIF should be checked for bias in wording, cultural assumptions, or content relevance to one group.</p>'
    );
    setInterp(
      strongDif ? strongDif + ' item(s) show strong DIF across ' + grp.name + '. Inspect these for bias before publishing or grading.' :
      mildDif ? mildDif + ' item(s) show mild DIF; worth inspecting if the test is being used in high-stakes contexts.' :
                'No items show meaningful DIF across ' + grp.name + '.'
    );
    expose({ lens: 'dif', group: grp.name, levels: levels, items: rows });
  }

  // ==================================================================
  // Helpers
  // ==================================================================
  function statBox(label, value, pillText, pillTone) {
    return '<div class="tia-stat">' +
      '<label>' + esc(label) + '</label>' +
      '<span class="v">' + esc(value) + '</span>' +
      (pillText ? '<span class="tia-pip-pill" data-tone="' + esc(pillTone || 'muted') + '">' + esc(pillText) + '</span>' : '') +
    '</div>';
  }
  function setHeader(name, headline, sub) {
    document.getElementById('tiaLensName').textContent = name;
    document.getElementById('tiaHeadline').textContent = headline;
    document.getElementById('tiaSub').textContent      = sub;
  }
  function setStats(html) { document.getElementById('tiaStatStrip').innerHTML = html; }
  function setBody(html)  { document.getElementById('tiaBody').innerHTML = html; }
  function setInterp(t)   { document.getElementById('tiaInterp').textContent = t; }
  function expose(payload) {
    window.RELICHECK_APP_STATE = Object.assign({
      app_key:  'tia_analysis',
      app_name: 'TIA (' + (lens || '—') + ')',
      summary:  summarize(payload),
      lens:     lens,
      computed_at: new Date().toISOString(),
    }, payload);
  }
  function summarize(p) {
    if (p.lens === 'item_difficulty')       return (p.items ? p.items.length : 0) + ' items by difficulty.';
    if (p.lens === 'item_discrimination')   return (p.items ? p.items.length : 0) + ' items by discrimination.';
    if (p.lens === 'distractor_analysis')   return (p.items ? p.items.length : 0) + ' MC items analyzed.';
    if (p.lens === 'answer_key_validation') return (p.miskeyed || 0) + ' possible miskeys.';
    if (p.lens === 'dif')                   return 'DIF across ' + (p.group || '—');
    return 'TIA analysis';
  }
})();
