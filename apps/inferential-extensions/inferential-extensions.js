// ReliCheck Inferential Extensions
// -------------------------------------------------------------------
// Six lenses sharing one engine. Math helpers (incomplete beta and
// gamma, t/F/chi-square p-values, matrix inverse) are inlined so the
// app is self-contained and doesn't depend on the inferential app's JS.
//
// Lenses:
//   paired_t              paired t-test (within-subject)
//   welch_anova           ANOVA with unequal variances
//   post_hoc              pairwise Welch t-tests with Bonferroni-Holm
//   regression            linear regression (1 or 2 predictors)
//   confidence_interval   CI for mean / proportion / mean diff / r
//   assumption_check      normality + homogeneity of variance

(function () {
  'use strict';

  // ==================================================================
  // Dataset resolution
  // ==================================================================
  let dataset = window.INFEXT_DATASET;
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

  if (!dataset || !Array.isArray(dataset.variables)) {
    document.getElementById('ixEmpty').hidden = false;
    return;
  }
  const allVars  = dataset.variables;
  const rowCount = dataset.rowCount || (allVars[0] ? allVars[0].values.length : 0);
  const lens = window.INFEXT_LENS || 'paired_t';

  // ==================================================================
  // Math helpers
  // ==================================================================
  function logGamma(x) {
    const c = [76.18009172947146, -86.50532032941677, 24.01409824083091,
               -1.231739572450155, 0.001208650973866179, -0.000005395239384953];
    let y = x;
    let tmp = x + 5.5;
    tmp -= (x + 0.5) * Math.log(tmp);
    let ser = 1.000000000190015;
    for (let j = 0; j < 6; j++) { y += 1; ser += c[j] / y; }
    return -tmp + Math.log(2.5066282746310005 * ser / x);
  }
  function regGammaP(a, x) {
    if (x < 0 || a <= 0) return 0;
    if (x === 0) return 0;
    if (x < a + 1) {
      let ap = a, sum = 1 / a, del = sum;
      for (let n = 1; n < 200; n++) {
        ap += 1; del *= x / ap; sum += del;
        if (Math.abs(del) < Math.abs(sum) * 1e-12) break;
      }
      return sum * Math.exp(-x + a * Math.log(x) - logGamma(a));
    }
    const FPMIN = 1e-300;
    let b = x + 1 - a, c = 1 / FPMIN, d = 1 / b, h = d;
    for (let i = 1; i < 200; i++) {
      const an = -i * (i - a);
      b += 2;
      d = an * d + b; if (Math.abs(d) < FPMIN) d = FPMIN;
      c = b + an / c; if (Math.abs(c) < FPMIN) c = FPMIN;
      d = 1 / d;
      const del = d * c; h *= del;
      if (Math.abs(del - 1) < 1e-12) break;
    }
    return 1 - Math.exp(-x + a * Math.log(x) - logGamma(a)) * h;
  }
  function regBetaI(x, a, b) {
    if (x <= 0) return 0;
    if (x >= 1) return 1;
    const bt = Math.exp(
      logGamma(a + b) - logGamma(a) - logGamma(b) +
      a * Math.log(x) + b * Math.log(1 - x)
    );
    if (x < (a + 1) / (a + b + 2)) return bt * betacf(x, a, b) / a;
    return 1 - bt * betacf(1 - x, b, a) / b;
  }
  function betacf(x, a, b) {
    const FPMIN = 1e-300;
    const qab = a + b, qap = a + 1, qam = a - 1;
    let c = 1, d = 1 - qab * x / qap;
    if (Math.abs(d) < FPMIN) d = FPMIN;
    d = 1 / d;
    let h = d;
    for (let m = 1; m <= 200; m++) {
      const m2 = 2 * m;
      let aa = m * (b - m) * x / ((qam + m2) * (a + m2));
      d = 1 + aa * d; if (Math.abs(d) < FPMIN) d = FPMIN;
      c = 1 + aa / c; if (Math.abs(c) < FPMIN) c = FPMIN;
      d = 1 / d; h *= d * c;
      aa = -(a + m) * (qab + m) * x / ((a + m2) * (qap + m2));
      d = 1 + aa * d; if (Math.abs(d) < FPMIN) d = FPMIN;
      c = 1 + aa / c; if (Math.abs(c) < FPMIN) c = FPMIN;
      d = 1 / d;
      const del = d * c; h *= del;
      if (Math.abs(del - 1) < 1e-12) break;
    }
    return h;
  }
  function tPValue(t, df) {
    if (!isFinite(t) || df <= 0) return 1;
    const x = df / (df + t * t);
    return regBetaI(x, df / 2, 0.5);
  }
  function fPValue(F, df1, df2) {
    if (!isFinite(F) || F <= 0 || df1 <= 0 || df2 <= 0) return 1;
    const x = df2 / (df2 + df1 * F);
    return regBetaI(x, df2 / 2, df1 / 2);
  }
  function chiPValue(x, df) {
    if (!isFinite(x) || x < 0 || df <= 0) return 1;
    return 1 - regGammaP(df / 2, x / 2);
  }
  function invNormalCDF(p) {
    if (p <= 0) return -Infinity;
    if (p >= 1) return Infinity;
    const a = [-39.69683028665376, 220.9460984245205, -275.9285104469687, 138.357751867269, -30.66479806614716, 2.506628277459239];
    const b = [-54.47609879822406, 161.5858368580409, -155.6989798598866, 66.80131188771972, -13.28068155288572];
    const c = [-0.007784894002430293, -0.3223964580411365, -2.400758277161838, -2.549732539343734, 4.374664141464968, 2.938163982698783];
    const d = [0.007784695709041462, 0.3224671290700398, 2.445134137142996, 3.754408661907416];
    const plow = 0.02425, phigh = 1 - plow;
    let q, r;
    if (p < plow) {
      q = Math.sqrt(-2 * Math.log(p));
      return (((((c[0]*q+c[1])*q+c[2])*q+c[3])*q+c[4])*q+c[5]) / ((((d[0]*q+d[1])*q+d[2])*q+d[3])*q+1);
    }
    if (p <= phigh) {
      q = p - 0.5; r = q * q;
      return (((((a[0]*r+a[1])*r+a[2])*r+a[3])*r+a[4])*r+a[5]) * q / (((((b[0]*r+b[1])*r+b[2])*r+b[3])*r+b[4])*r+1);
    }
    q = Math.sqrt(-2 * Math.log(1 - p));
    return -(((((c[0]*q+c[1])*q+c[2])*q+c[3])*q+c[4])*q+c[5]) / ((((d[0]*q+d[1])*q+d[2])*q+d[3])*q+1);
  }
  function matrixInverse(M) {
    const n = M.length;
    const A = M.map((row, i) => {
      const r = row.slice();
      for (let j = 0; j < n; j++) r.push(i === j ? 1 : 0);
      return r;
    });
    for (let i = 0; i < n; i++) {
      let pivot = i;
      for (let k = i + 1; k < n; k++) if (Math.abs(A[k][i]) > Math.abs(A[pivot][i])) pivot = k;
      if (pivot !== i) { const t = A[i]; A[i] = A[pivot]; A[pivot] = t; }
      if (Math.abs(A[i][i]) < 1e-12) return null;
      for (let k = 0; k < n; k++) {
        if (k === i) continue;
        const f = A[k][i] / A[i][i];
        for (let j = 0; j < 2 * n; j++) A[k][j] -= f * A[i][j];
      }
    }
    return A.map((row, i) => row.slice(n).map(v => v / A[i][i]));
  }
  function tCriticalTwoTailed(alpha, df) {
    let lo = 0, hi = 50;
    for (let i = 0; i < 60; i++) {
      const mid = (lo + hi) / 2;
      const p = tPValue(mid, df);
      if (p < alpha) hi = mid;
      else           lo = mid;
    }
    return (lo + hi) / 2;
  }

  // ==================================================================
  // Tiny utilities
  // ==================================================================
  function num(v) { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function isMissing(v) { return v === '' || v == null; }
  function mean(a)  { return a.length ? a.reduce((s, v) => s + v, 0) / a.length : 0; }
  function variance(a) {
    if (a.length < 2) return 0;
    const m = mean(a);
    return a.reduce((s, v) => s + (v - m) * (v - m), 0) / (a.length - 1);
  }
  function sd(a) { return Math.sqrt(variance(a)); }
  function median(a) {
    if (!a.length) return 0;
    const s = a.slice().sort((x, y) => x - y);
    const mid = Math.floor(s.length / 2);
    return s.length % 2 ? s[mid] : (s[mid - 1] + s[mid]) / 2;
  }
  function skewness(a) {
    if (a.length < 3) return 0;
    const m = mean(a), v = variance(a);
    if (v === 0) return 0;
    const s = Math.sqrt(v);
    return a.reduce((acc, x) => acc + Math.pow((x - m) / s, 3), 0) / a.length;
  }
  function kurtosis(a) {
    if (a.length < 4) return 0;
    const m = mean(a), v = variance(a);
    if (v === 0) return 0;
    const s = Math.sqrt(v);
    return a.reduce((acc, x) => acc + Math.pow((x - m) / s, 4), 0) / a.length - 3;
  }
  function hasType(v, t) { return v.types && v.types.indexOf(t) !== -1; }
  function isNumericish(v) { return hasType(v, 'numeric') || hasType(v, 'likert'); }
  function isCategorical(v) { return hasType(v, 'categorical'); }
  function groupLevels(v) {
    const seen = new Set();
    v.values.forEach(val => { if (!isMissing(val)) seen.add(String(val)); });
    return Array.from(seen);
  }
  function fmt(x, d) { if (x == null || !isFinite(x)) return '—'; return Number(x).toFixed(d == null ? 2 : d); }
  function fmtP(p) {
    if (p == null) return 'p = —';
    if (p < 0.001) return 'p < .001';
    if (p < 0.01)  return 'p = ' + p.toFixed(3).replace(/^0/, '');
    return 'p = ' + p.toFixed(2).replace(/^0/, '');
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  // ==================================================================
  // Source ribbon + lens visibility
  // ==================================================================
  const ribbon = document.getElementById('ixSource');
  if (ribbon) {
    ribbon.setAttribute('data-source', datasetSource);
    document.getElementById('ixSourceLabel').textContent = datasetSource === 'uploaded' ? 'Uploaded data' : 'Sample data';
    document.getElementById('ixSourceMeta').textContent  = (dataset.source || 'Dataset') + '  ·  ' + rowCount + ' rows';
  }
  document.querySelectorAll('.ix-lens-setup').forEach(el => {
    el.hidden = el.getAttribute('data-lens') !== lens;
  });
  const lensName = { paired_t:'Paired t-test', welch_anova:'Welch ANOVA', post_hoc:'Post-hoc comparison', regression:'Linear regression', confidence_interval:'Confidence interval', assumption_check:'Assumption check' }[lens] || 'Result';

  // ==================================================================
  // Variable population
  // ==================================================================
  function fillSel(sel, vars, allowBlank) {
    if (!sel) return;
    sel.innerHTML = '';
    if (allowBlank) {
      const o = document.createElement('option'); o.value = ''; o.textContent = '— none —'; sel.appendChild(o);
    }
    if (!vars.length) {
      const o = document.createElement('option'); o.value = ''; o.textContent = '— no matching variables —';
      sel.appendChild(o); sel.disabled = true; return;
    }
    sel.disabled = false;
    vars.forEach(v => {
      const o = document.createElement('option'); o.value = v.name; o.textContent = v.name; sel.appendChild(o);
    });
  }

  switch (lens) {
    case 'paired_t':
      fillSel(document.getElementById('ixPairA'), allVars.filter(isNumericish));
      fillSel(document.getElementById('ixPairB'), allVars.filter(isNumericish));
      break;
    case 'welch_anova':
      fillSel(document.getElementById('ixWaOutcome'), allVars.filter(isNumericish));
      fillSel(document.getElementById('ixWaGroup'),   allVars.filter(v => isCategorical(v) && groupLevels(v).length >= 3));
      break;
    case 'post_hoc':
      fillSel(document.getElementById('ixPhOutcome'), allVars.filter(isNumericish));
      fillSel(document.getElementById('ixPhGroup'),   allVars.filter(v => isCategorical(v) && groupLevels(v).length >= 3));
      break;
    case 'regression':
      fillSel(document.getElementById('ixRegY'),  allVars.filter(isNumericish));
      fillSel(document.getElementById('ixRegX1'), allVars.filter(isNumericish));
      fillSel(document.getElementById('ixRegX2'), allVars.filter(isNumericish), true);
      break;
    case 'confidence_interval':
      fillSel(document.getElementById('ixCiMeanVar'), allVars.filter(isNumericish));
      fillSel(document.getElementById('ixCiPropVar'), allVars.filter(isCategorical));
      fillSel(document.getElementById('ixCiDiffOut'), allVars.filter(isNumericish));
      fillSel(document.getElementById('ixCiDiffGrp'), allVars.filter(v => isCategorical(v) && groupLevels(v).length === 2));
      fillSel(document.getElementById('ixCiPrX'),     allVars.filter(isNumericish));
      fillSel(document.getElementById('ixCiPrY'),     allVars.filter(isNumericish));
      // Wire the CI chip switcher
      const ciChips = document.querySelectorAll('.ix-chip[data-ci]');
      let currentCi = 'mean';
      ciChips.forEach(chip => {
        chip.addEventListener('click', () => {
          currentCi = chip.getAttribute('data-ci');
          ciChips.forEach(c => c.classList.toggle('is-active', c === chip));
          document.querySelectorAll('.ix-ciset').forEach(s => s.hidden = s.getAttribute('data-ci') !== currentCi);
          // Repopulate level dropdown on proportion variable change
          if (currentCi === 'prop') refreshPropLevels();
        });
      });
      const propVarSel = document.getElementById('ixCiPropVar');
      if (propVarSel) propVarSel.addEventListener('change', refreshPropLevels);
      refreshPropLevels();
      function refreshPropLevels() {
        const v = allVars.find(x => x.name === propVarSel.value);
        const lvlSel = document.getElementById('ixCiPropLvl');
        lvlSel.innerHTML = '';
        if (!v) return;
        groupLevels(v).sort().forEach(l => {
          const o = document.createElement('option'); o.value = l; o.textContent = l; lvlSel.appendChild(o);
        });
      }
      document.getElementById('ixCiRun').addEventListener('click', () => runCi(currentCi));
      break;
    case 'assumption_check':
      fillSel(document.getElementById('ixAcOutcome'), allVars.filter(isNumericish));
      fillSel(document.getElementById('ixAcGroup'),   allVars.filter(isCategorical), true);
      break;
  }

  // ==================================================================
  // Run-button wiring
  // ==================================================================
  if (lens === 'paired_t')       document.getElementById('ixPairRun').addEventListener('click', runPaired);
  if (lens === 'welch_anova')    document.getElementById('ixWaRun').addEventListener('click', runWelchAnova);
  if (lens === 'post_hoc')       document.getElementById('ixPhRun').addEventListener('click', runPostHoc);
  if (lens === 'regression')     document.getElementById('ixRegRun').addEventListener('click', runRegression);
  if (lens === 'assumption_check') document.getElementById('ixAcRun').addEventListener('click', runAssumption);

  function show(headline, context, stats, body, interp, next) {
    document.getElementById('ixResults').hidden = false;
    document.getElementById('ixLensName').textContent = lensName;
    document.getElementById('ixHeadline').textContent = headline;
    document.getElementById('ixContext').textContent  = context || '';
    document.getElementById('ixStatStrip').innerHTML  = stats || '';
    document.getElementById('ixBody').innerHTML       = body || '';
    document.getElementById('ixInterp').textContent   = interp || '';
    document.getElementById('ixNext').textContent     = next || '';
  }
  function setStatus(m) { document.getElementById('ixStatus').textContent = m || ''; }
  function statBox(label, value, pillText, pillTone) {
    return '<div class="ix-stat">' +
      '<label>' + esc(label) + '</label>' +
      '<span class="v">' + esc(value) + '</span>' +
      (pillText ? '<span class="ix-pip-pill" data-tone="' + esc(pillTone || 'muted') + '">' + esc(pillText) + '</span>' : '') +
    '</div>';
  }
  function pTone(p) {
    if (p < 0.001) return { label: 'p < .001', tone: 'strong' };
    if (p < 0.01)  return { label: 'highly significant', tone: 'strong' };
    if (p < 0.05)  return { label: 'significant', tone: 'ok' };
    if (p < 0.10)  return { label: 'marginal', tone: 'warn' };
    return { label: 'not significant', tone: 'muted' };
  }

  // ==================================================================
  // LENS: paired_t
  // ==================================================================
  function runPaired() {
    const a = allVars.find(v => v.name === document.getElementById('ixPairA').value);
    const b = allVars.find(v => v.name === document.getElementById('ixPairB').value);
    if (!a || !b || a.name === b.name) return setStatus('Pick two different numeric variables.');
    setStatus('');
    const diffs = [];
    for (let i = 0; i < rowCount; i++) {
      const x = num(a.values[i]), y = num(b.values[i]);
      if (x == null || y == null) continue;
      diffs.push(x - y);
    }
    if (diffs.length < 2) return setStatus('Need at least 2 complete pairs.');
    const n = diffs.length;
    const md = mean(diffs), sdd = sd(diffs);
    const t = md / (sdd / Math.sqrt(n) || 1e-12);
    const df = n - 1;
    const p = tPValue(t, df);
    const dz = md / (sdd || 1e-12);  // Cohen's d_z
    const tCrit = tCriticalTwoTailed(0.05, df);
    const seMd = sdd / Math.sqrt(n);
    const ciLow = md - tCrit * seMd;
    const ciHi  = md + tCrit * seMd;

    const pt = pTone(p);
    const dzBand = (() => {
      const x = Math.abs(dz);
      if (x < 0.20) return { label: 'negligible', tone: 'muted' };
      if (x < 0.50) return { label: 'small',      tone: 'warn' };
      if (x < 0.80) return { label: 'medium',     tone: 'ok' };
      return            { label: 'large',         tone: 'strong' };
    })();

    const stats =
      statBox('t', fmt(t, 2)) +
      statBox('df', String(df)) +
      statBox('p-value', fmtP(p).replace(/^p\s*=?\s*/, ''), pt.label, pt.tone) +
      statBox("Cohen's d_z", fmt(dz, 2), dzBand.label, dzBand.tone);
    const body =
      '<table class="ix-table">' +
        '<thead><tr><th>Stat</th><th class="ix-num">Value</th></tr></thead><tbody>' +
        '<tr><td>n (paired)</td><td class="ix-num">' + n + '</td></tr>' +
        '<tr><td>Mean of differences</td><td class="ix-num">' + fmt(md, 2) + '</td></tr>' +
        '<tr><td>SD of differences</td><td class="ix-num">' + fmt(sdd, 2) + '</td></tr>' +
        '<tr><td>SE</td><td class="ix-num">' + fmt(seMd, 2) + '</td></tr>' +
        '<tr><td>95% CI of difference</td><td class="ix-num">[' + fmt(ciLow, 2) + ', ' + fmt(ciHi, 2) + ']</td></tr>' +
      '</tbody></table>';

    const dir = md > 0 ? a.name + ' is higher than ' + b.name : b.name + ' is higher than ' + a.name;
    const interp = (p < 0.05)
      ? 'The within-respondent change is statistically reliable (' + fmtP(p) + '). ' + dir + ' by ' + fmt(Math.abs(md), 2) + ' on average. The effect size (d_z = ' + fmt(dz, 2) + ', ' + dzBand.label + ') describes the magnitude relative to the variability in differences.'
      : 'No reliable within-respondent change (' + fmtP(p) + ', d_z = ' + fmt(dz, 2) + ').';
    const next = (p < 0.05)
      ? 'Report the mean difference with the 95% CI; consider visualizing pre-post pairs.'
      : 'Confirm sample size; the data are consistent with no within-respondent change.';
    show('Paired t-test: ' + a.name + ' vs ' + b.name, 't(' + df + ') = ' + fmt(t, 2) + ', ' + fmtP(p) + ', d_z = ' + fmt(dz, 2), stats, body, interp, next);
    expose({ test: 'paired_t', variables: [a.name, b.name], t: t, df: df, p: p, mean_diff: md, sd_diff: sdd, dz: dz, ci95: [ciLow, ciHi], n: n });
  }

  // ==================================================================
  // LENS: welch_anova
  // ==================================================================
  function runWelchAnova() {
    const out = allVars.find(v => v.name === document.getElementById('ixWaOutcome').value);
    const grp = allVars.find(v => v.name === document.getElementById('ixWaGroup').value);
    if (!out || !grp) return setStatus('Pick an outcome and a grouping variable.');
    setStatus('');
    const groups = new Map();
    for (let i = 0; i < rowCount; i++) {
      const y = num(out.values[i]);
      const g = grp.values[i];
      if (y == null || isMissing(g)) continue;
      const key = String(g);
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(y);
    }
    const levels = Array.from(groups.keys());
    if (levels.length < 2) return setStatus('Need at least 2 groups.');
    const groupData = levels.map(l => groups.get(l));
    const ns = groupData.map(a => a.length);
    if (ns.some(n => n < 2)) return setStatus('Each group needs at least 2 observations.');

    const k = levels.length;
    const means = groupData.map(mean);
    const vars_  = groupData.map(variance);
    const w = ns.map((n, i) => n / (vars_[i] || 1e-12));
    const W = w.reduce((s, v) => s + v, 0);
    const M = w.reduce((s, wi, i) => s + wi * means[i], 0) / W;
    let numer = 0;
    for (let i = 0; i < k; i++) numer += w[i] * Math.pow(means[i] - M, 2);
    numer /= (k - 1);
    let denomSum = 0;
    for (let i = 0; i < k; i++) {
      const term = Math.pow(1 - w[i] / W, 2) / (ns[i] - 1);
      denomSum += term;
    }
    const denom = 1 + (2 * (k - 2) / (k * k - 1)) * denomSum;
    const F = numer / denom;
    const df1 = k - 1;
    const df2 = (k * k - 1) / (3 * denomSum);
    const p = fPValue(F, df1, df2);
    // η² approximation from standard sums of squares
    const all = groupData.flat();
    const grand = mean(all);
    const ssB = groupData.reduce((s, g) => s + g.length * Math.pow(mean(g) - grand, 2), 0);
    const ssW = groupData.reduce((s, g) => {
      const gm = mean(g);
      return s + g.reduce((acc, x) => acc + (x - gm) * (x - gm), 0);
    }, 0);
    const eta2 = (ssB + ssW) ? ssB / (ssB + ssW) : 0;

    const pt = pTone(p);
    const eta2Band = (() => {
      if (eta2 < 0.01) return { label: 'negligible', tone: 'muted' };
      if (eta2 < 0.06) return { label: 'small',      tone: 'warn' };
      if (eta2 < 0.14) return { label: 'medium',     tone: 'ok' };
      return               { label: 'large',         tone: 'strong' };
    })();

    const stats =
      statBox('F', fmt(F, 2)) +
      statBox('df', df1 + ', ' + fmt(df2, 1)) +
      statBox('p-value', fmtP(p).replace(/^p\s*=?\s*/, ''), pt.label, pt.tone) +
      statBox('η²', fmt(eta2, 3), eta2Band.label, eta2Band.tone);
    const body =
      '<table class="ix-table">' +
        '<thead><tr><th>Group</th><th class="ix-num">n</th><th class="ix-num">Mean</th><th class="ix-num">SD</th></tr></thead><tbody>' +
        levels.map((l, i) => '<tr><td>' + esc(l) + '</td><td class="ix-num">' + ns[i] + '</td><td class="ix-num">' + fmt(means[i], 2) + '</td><td class="ix-num">' + fmt(Math.sqrt(vars_[i]), 2) + '</td></tr>').join('') +
      '</tbody></table>' +
      '<p class="ix-detail-note">Variance ratio (max / min): ' + fmt(Math.max.apply(null, vars_) / (Math.min.apply(null, vars_) || 1), 2) + '. Welch ANOVA does not assume equal variances; the F-statistic and df are adjusted accordingly.</p>';
    const interp = (p < 0.05)
      ? 'At least one group mean differs from the others. η² (' + fmt(eta2, 3) + ', ' + eta2Band.label + ') indicates the magnitude of the omnibus effect.'
      : 'Group means do not differ reliably (' + fmtP(p) + ').';
    const next = (p < 0.05)
      ? 'Run Post-Hoc Comparison to find which specific pairs of groups differ.'
      : 'Confirm sample size is adequate; consider whether the question is better tested with a different grouping.';
    show('Welch ANOVA: ' + out.name + ' by ' + grp.name, 'F(' + df1 + ', ' + fmt(df2, 1) + ') = ' + fmt(F, 2) + ', ' + fmtP(p) + ', η² = ' + fmt(eta2, 3), stats, body, interp, next);
    expose({ test: 'welch_anova', variables: [out.name, grp.name], F: F, df1: df1, df2: df2, p: p, eta2: eta2, groups: levels.map((l, i) => ({ name: l, n: ns[i], mean: means[i], sd: Math.sqrt(vars_[i]) })) });
  }

  // ==================================================================
  // LENS: post_hoc (Games-Howell-style with Bonferroni-Holm)
  // ==================================================================
  function runPostHoc() {
    const out = allVars.find(v => v.name === document.getElementById('ixPhOutcome').value);
    const grp = allVars.find(v => v.name === document.getElementById('ixPhGroup').value);
    if (!out || !grp) return setStatus('Pick an outcome and a grouping variable.');
    setStatus('');
    const groups = new Map();
    for (let i = 0; i < rowCount; i++) {
      const y = num(out.values[i]);
      const g = grp.values[i];
      if (y == null || isMissing(g)) continue;
      const key = String(g);
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(y);
    }
    const levels = Array.from(groups.keys()).sort();
    if (levels.length < 3) return setStatus('Need at least 3 groups for post-hoc comparisons.');
    const dataByLvl = levels.map(l => groups.get(l));
    const means = dataByLvl.map(mean);
    const vars_  = dataByLvl.map(variance);
    const ns    = dataByLvl.map(a => a.length);

    // Pairwise Welch t-tests
    const pairs = [];
    for (let i = 0; i < levels.length; i++) {
      for (let j = i + 1; j < levels.length; j++) {
        const m1 = means[i], m2 = means[j];
        const v1 = vars_[i], v2 = vars_[j];
        const n1 = ns[i],   n2 = ns[j];
        const se = Math.sqrt(v1 / n1 + v2 / n2);
        const t  = (m1 - m2) / (se || 1e-12);
        const dfNum = Math.pow(v1 / n1 + v2 / n2, 2);
        const dfDen = (v1 * v1) / (n1 * n1 * (n1 - 1)) + (v2 * v2) / (n2 * n2 * (n2 - 1));
        const df = dfDen ? dfNum / dfDen : (n1 + n2 - 2);
        const p  = tPValue(t, df);
        const diff = m1 - m2;
        const tCrit = tCriticalTwoTailed(0.05, df);
        const ciLow = diff - tCrit * se;
        const ciHi  = diff + tCrit * se;
        const pooledSd = Math.sqrt(((n1 - 1) * v1 + (n2 - 1) * v2) / (n1 + n2 - 2));
        const d = (m1 - m2) / (pooledSd || 1e-12);
        pairs.push({ a: levels[i], b: levels[j], t: t, df: df, p_raw: p, diff: diff, ciLow: ciLow, ciHi: ciHi, d: d });
      }
    }
    // Bonferroni-Holm correction
    const sorted = pairs.slice().sort((x, y) => x.p_raw - y.p_raw);
    const m = sorted.length;
    let lastAdj = 0;
    sorted.forEach((p, k) => {
      const adj = Math.min(1, p.p_raw * (m - k));
      // Step-up: each adjusted p ≥ previous
      p.p_adj = Math.max(adj, lastAdj);
      lastAdj = p.p_adj;
    });

    const headline = 'Pairwise comparisons on ' + out.name + ' by ' + grp.name;
    const stats =
      statBox('Comparisons', String(pairs.length)) +
      statBox('Method', 'Welch + Holm') +
      statBox('Significant (α=.05)', String(pairs.filter(p => p.p_adj < 0.05).length));
    const sig = pairs.filter(p => p.p_adj < 0.05).length;
    const sortedDisplay = pairs.slice().sort((x, y) => x.p_adj - y.p_adj);
    const tableHtml =
      '<table class="ix-table">' +
        '<thead><tr><th>Comparison</th><th class="ix-num">Mean diff</th><th class="ix-num">95% CI</th><th class="ix-num">t</th><th class="ix-num">df</th><th class="ix-num">p (raw)</th><th class="ix-num">p (Holm)</th><th class="ix-num">d</th></tr></thead>' +
        '<tbody>' +
          sortedDisplay.map(p =>
            '<tr data-sig="' + (p.p_adj < 0.05 ? '1' : '0') + '">' +
              '<td>' + esc(p.a) + ' − ' + esc(p.b) + '</td>' +
              '<td class="ix-num">' + fmt(p.diff, 2) + '</td>' +
              '<td class="ix-num">[' + fmt(p.ciLow, 2) + ', ' + fmt(p.ciHi, 2) + ']</td>' +
              '<td class="ix-num">' + fmt(p.t, 2) + '</td>' +
              '<td class="ix-num">' + fmt(p.df, 1) + '</td>' +
              '<td class="ix-num">' + fmtP(p.p_raw).replace(/^p\s*=?\s*/, '') + '</td>' +
              '<td class="ix-num">' + fmtP(p.p_adj).replace(/^p\s*=?\s*/, '') + '</td>' +
              '<td class="ix-num">' + fmt(p.d, 2) + '</td>' +
            '</tr>'
          ).join('') +
        '</tbody>' +
      '</table>' +
      '<p class="ix-detail-note">Pairwise Welch t-tests do not assume equal variances. The Bonferroni-Holm correction is uniformly more powerful than plain Bonferroni at the same family-wise error rate; rows are sorted by adjusted p.</p>';
    const interp = sig
      ? sig + ' of ' + pairs.length + ' pairwise comparison' + (pairs.length === 1 ? '' : 's') + ' remain significant after Holm correction. See the table for which pairs differ.'
      : 'No pairs survive the Bonferroni-Holm correction. The omnibus ANOVA may have been driven by a near-significant pattern that loses power once you correct for multiple comparisons.';
    const next = sig ? 'Visualize the significant pairs with group-mean error bars and report the adjusted p-values in your write-up.'
                     : 'Re-examine the omnibus finding; consider whether the comparison was underpowered, or whether the effect is too subtle to isolate to one pair.';
    show(headline, levels.length + ' groups, ' + pairs.length + ' pairwise comparisons', stats, tableHtml, interp, next);
    expose({ test: 'post_hoc', variables: [out.name, grp.name], pairs: pairs });
  }

  // ==================================================================
  // LENS: regression (linear, 1-2 predictors)
  // ==================================================================
  function runRegression() {
    const yVar = allVars.find(v => v.name === document.getElementById('ixRegY').value);
    const x1Var = allVars.find(v => v.name === document.getElementById('ixRegX1').value);
    const x2Name = document.getElementById('ixRegX2').value;
    const x2Var = x2Name ? allVars.find(v => v.name === x2Name) : null;
    if (!yVar || !x1Var) return setStatus('Pick an outcome and at least one predictor.');
    if (x2Var && (x2Var.name === x1Var.name || x2Var.name === yVar.name)) return setStatus('Predictors must be distinct from each other and from Y.');
    setStatus('');

    // Build X and y vectors over complete rows
    const X = [], y = [];
    for (let i = 0; i < rowCount; i++) {
      const yi = num(yVar.values[i]);
      const x1 = num(x1Var.values[i]);
      const x2 = x2Var ? num(x2Var.values[i]) : null;
      if (yi == null || x1 == null) continue;
      if (x2Var && x2 == null) continue;
      y.push(yi);
      X.push(x2Var ? [1, x1, x2] : [1, x1]);
    }
    const n = y.length;
    if (n < (x2Var ? 4 : 3)) return setStatus('Not enough complete rows for regression.');
    const p = X[0].length;

    // β = (XᵀX)⁻¹ Xᵀy
    const XtX = matrixMultiply(transpose(X), X);
    const inv = matrixInverse(XtX);
    if (!inv) return setStatus('Singular matrix; predictors may be perfectly collinear.');
    const Xty = matrixMultiply(transpose(X), y.map(v => [v])).map(r => r[0]);
    const beta = matrixMultiply(inv, Xty.map(v => [v])).map(r => r[0]);

    // Predictions, residuals, R²
    const yhat = X.map(row => row.reduce((s, v, i) => s + v * beta[i], 0));
    const resid = y.map((v, i) => v - yhat[i]);
    const meanY = mean(y);
    const ssRes = resid.reduce((s, r) => s + r * r, 0);
    const ssTot = y.reduce((s, v) => s + (v - meanY) * (v - meanY), 0);
    const r2 = ssTot ? 1 - ssRes / ssTot : 0;
    const dfRes = n - p;
    const dfReg = p - 1;
    const sigma2 = ssRes / (dfRes || 1);
    const sigma  = Math.sqrt(sigma2);
    const r2adj  = 1 - (1 - r2) * (n - 1) / (dfRes || 1);
    const F = (dfReg && sigma2) ? ((ssTot - ssRes) / dfReg) / sigma2 : 0;
    const fP = fPValue(F, dfReg, dfRes);

    // SE per coefficient = sqrt(σ² * diag((XᵀX)⁻¹))
    const seCoef = beta.map((_, i) => Math.sqrt(sigma2 * inv[i][i]));
    const tCoef  = beta.map((b, i) => b / (seCoef[i] || 1e-12));
    const pCoef  = tCoef.map(t => tPValue(t, dfRes));
    const tCrit  = tCriticalTwoTailed(0.05, dfRes);
    const ciCoef = beta.map((b, i) => [b - tCrit * seCoef[i], b + tCrit * seCoef[i]]);

    const labels = x2Var ? ['(Intercept)', x1Var.name, x2Var.name] : ['(Intercept)', x1Var.name];
    const fPT = pTone(fP);

    const stats =
      statBox('R²', fmt(r2, 3)) +
      statBox('Adj. R²', fmt(r2adj, 3)) +
      statBox('F', fmt(F, 2) + ' (' + dfReg + ', ' + dfRes + ')', fPT.label, fPT.tone) +
      statBox('Residual SD', fmt(sigma, 2));

    const body =
      '<table class="ix-table ix-table-reg">' +
        '<thead><tr><th>Term</th><th class="ix-num">β</th><th class="ix-num">SE</th><th class="ix-num">t</th><th class="ix-num">95% CI</th><th class="ix-num">p</th></tr></thead>' +
        '<tbody>' +
          labels.map((lab, i) => {
            const pt = pTone(pCoef[i]);
            return '<tr>' +
              '<td>' + esc(lab) + '</td>' +
              '<td class="ix-num"><strong>' + fmt(beta[i], 3) + '</strong></td>' +
              '<td class="ix-num">' + fmt(seCoef[i], 3) + '</td>' +
              '<td class="ix-num">' + fmt(tCoef[i], 2) + '</td>' +
              '<td class="ix-num">[' + fmt(ciCoef[i][0], 2) + ', ' + fmt(ciCoef[i][1], 2) + ']</td>' +
              '<td class="ix-num"><span class="ix-pip-pill" data-tone="' + pt.tone + '">' + fmtP(pCoef[i]).replace(/^p\s*=?\s*/, '') + '</span></td>' +
            '</tr>';
          }).join('') +
        '</tbody>' +
      '</table>' +
      '<p class="ix-detail-note">n = ' + n + ' complete rows. Residual SD = ' + fmt(sigma, 2) + ' (in outcome units). R² = ' + fmt(r2, 3) + ' (' + (r2 * 100).toFixed(1) + '% of variance in ' + yVar.name + ' explained by ' + (x2Var ? 'both predictors' : x1Var.name) + ').</p>';

    const interp = fP < 0.05
      ? 'The model is statistically significant overall (F(' + dfReg + ', ' + dfRes + ') = ' + fmt(F, 2) + ', ' + fmtP(fP) + ') and explains ' + (r2 * 100).toFixed(1) + '% of the variance. Individual coefficients with p < .05 are reliable predictors of ' + yVar.name + ' net of the others.'
      : 'The overall model is not significant (' + fmtP(fP) + '). R² = ' + fmt(r2, 3) + ' is low; the predictors do not collectively explain meaningful variance in ' + yVar.name + '.';
    const next = 'Inspect residuals visually before drawing strong causal-style conclusions. Linearity and constant-variance assumptions are not auto-checked here.';
    show('Regression: ' + yVar.name + ' ~ ' + (x2Var ? x1Var.name + ' + ' + x2Var.name : x1Var.name), 'F(' + dfReg + ', ' + dfRes + ') = ' + fmt(F, 2) + ', ' + fmtP(fP) + ', R² = ' + fmt(r2, 3), stats, body, interp, next);
    expose({ test: 'regression', outcome: yVar.name, predictors: x2Var ? [x1Var.name, x2Var.name] : [x1Var.name], coefficients: labels.map((lab, i) => ({ name: lab, beta: beta[i], se: seCoef[i], t: tCoef[i], p: pCoef[i], ci95: ciCoef[i] })), r2: r2, r2adj: r2adj, F: F, df: [dfReg, dfRes], p: fP, n: n });
  }
  function transpose(M) {
    if (!M.length) return [];
    const rows = M.length, cols = M[0].length;
    const T = Array.from({ length: cols }, () => new Array(rows));
    for (let i = 0; i < rows; i++) for (let j = 0; j < cols; j++) T[j][i] = M[i][j];
    return T;
  }
  function matrixMultiply(A, B) {
    const rA = A.length, cA = A[0].length, cB = B[0].length;
    const C = Array.from({ length: rA }, () => new Array(cB).fill(0));
    for (let i = 0; i < rA; i++) {
      for (let k = 0; k < cA; k++) {
        const aik = A[i][k];
        for (let j = 0; j < cB; j++) C[i][j] += aik * B[k][j];
      }
    }
    return C;
  }

  // ==================================================================
  // LENS: confidence_interval
  // ==================================================================
  function runCi(kind) {
    setStatus('');
    const level = parseFloat(document.getElementById('ixCiLevel').value);
    const alpha = 1 - level;
    const zCrit = invNormalCDF(1 - alpha / 2);
    let result;

    if (kind === 'mean') {
      const v = allVars.find(x => x.name === document.getElementById('ixCiMeanVar').value);
      if (!v) return setStatus('Pick a variable.');
      const vals = v.values.map(num).filter(x => x != null);
      if (vals.length < 2) return setStatus('Need at least 2 numeric values.');
      const m = mean(vals), s = sd(vals), n = vals.length, df = n - 1;
      const tCrit = tCriticalTwoTailed(alpha, df);
      const se = s / Math.sqrt(n);
      const ci = [m - tCrit * se, m + tCrit * se];
      result = {
        kind: 'mean', variable: v.name, n: n, point: m, se: se, df: df, level: level, ci: ci,
        headline: 'Mean of ' + v.name + ' = ' + fmt(m, 2),
        context: Math.round(level * 100) + '% CI [' + fmt(ci[0], 2) + ', ' + fmt(ci[1], 2) + ']  ·  n = ' + n + ', SE = ' + fmt(se, 2) + ', t(' + df + ', α) = ' + fmt(tCrit, 2),
      };
    }
    else if (kind === 'prop') {
      const v = allVars.find(x => x.name === document.getElementById('ixCiPropVar').value);
      const lvl = document.getElementById('ixCiPropLvl').value;
      if (!v || !lvl) return setStatus('Pick a variable and a level.');
      const total = v.values.filter(x => !isMissing(x)).length;
      const hits  = v.values.filter(x => String(x) === lvl).length;
      if (total < 2) return setStatus('Need at least 2 non-missing observations.');
      const p_hat = hits / total;
      // Wilson interval (better than Wald for small n)
      const z2 = zCrit * zCrit;
      const denom = 1 + z2 / total;
      const center = (p_hat + z2 / (2 * total)) / denom;
      const half   = (zCrit / denom) * Math.sqrt(p_hat * (1 - p_hat) / total + z2 / (4 * total * total));
      const ci = [Math.max(0, center - half), Math.min(1, center + half)];
      result = {
        kind: 'proportion', variable: v.name, level: lvl, n: total, point: p_hat, level_conf: level, ci: ci,
        headline: 'Proportion of "' + lvl + '" in ' + v.name + ' = ' + (p_hat * 100).toFixed(1) + '%',
        context: Math.round(level * 100) + '% Wilson CI [' + (ci[0] * 100).toFixed(1) + '%, ' + (ci[1] * 100).toFixed(1) + '%]  ·  ' + hits + ' / ' + total,
      };
    }
    else if (kind === 'meandiff') {
      const out = allVars.find(x => x.name === document.getElementById('ixCiDiffOut').value);
      const grp = allVars.find(x => x.name === document.getElementById('ixCiDiffGrp').value);
      if (!out || !grp) return setStatus('Pick an outcome and a 2-level grouping.');
      const groups = new Map();
      for (let i = 0; i < rowCount; i++) {
        const y = num(out.values[i]), g = grp.values[i];
        if (y == null || isMissing(g)) continue;
        const key = String(g);
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(y);
      }
      const levels = Array.from(groups.keys());
      if (levels.length !== 2) return setStatus('Grouping must have exactly 2 levels.');
      const a = groups.get(levels[0]), b = groups.get(levels[1]);
      const m1 = mean(a), m2 = mean(b), v1 = variance(a), v2 = variance(b);
      const n1 = a.length, n2 = b.length;
      const se = Math.sqrt(v1 / n1 + v2 / n2);
      const dfNum = Math.pow(v1 / n1 + v2 / n2, 2);
      const dfDen = (v1 * v1) / (n1 * n1 * (n1 - 1)) + (v2 * v2) / (n2 * n2 * (n2 - 1));
      const df = dfDen ? dfNum / dfDen : (n1 + n2 - 2);
      const tCrit = tCriticalTwoTailed(alpha, df);
      const diff = m1 - m2;
      const ci = [diff - tCrit * se, diff + tCrit * se];
      result = {
        kind: 'mean_difference', variables: [out.name, grp.name], groups: [{ name: levels[0], n: n1, mean: m1 }, { name: levels[1], n: n2, mean: m2 }],
        point: diff, se: se, df: df, level: level, ci: ci,
        headline: 'Mean difference (' + levels[0] + ' − ' + levels[1] + ') = ' + fmt(diff, 2),
        context: Math.round(level * 100) + '% Welch CI [' + fmt(ci[0], 2) + ', ' + fmt(ci[1], 2) + ']  ·  df = ' + fmt(df, 1) + ', SE = ' + fmt(se, 2),
      };
    }
    else if (kind === 'pearson_r') {
      const x = allVars.find(v => v.name === document.getElementById('ixCiPrX').value);
      const y = allVars.find(v => v.name === document.getElementById('ixCiPrY').value);
      if (!x || !y || x.name === y.name) return setStatus('Pick two different numeric variables.');
      const pairs = [];
      for (let i = 0; i < rowCount; i++) {
        const a = num(x.values[i]), b = num(y.values[i]);
        if (a == null || b == null) continue;
        pairs.push([a, b]);
      }
      if (pairs.length < 4) return setStatus('Need at least 4 paired observations for a CI on r.');
      const n = pairs.length;
      const mx = pairs.reduce((s, p) => s + p[0], 0) / n;
      const my = pairs.reduce((s, p) => s + p[1], 0) / n;
      let cov = 0, vx = 0, vy = 0;
      pairs.forEach(p => { const dx = p[0] - mx, dy = p[1] - my; cov += dx * dy; vx += dx * dx; vy += dy * dy; });
      const r = cov / (Math.sqrt(vx * vy) || 1e-12);
      const zr = 0.5 * Math.log((1 + r) / (1 - r));
      const se = 1 / Math.sqrt(n - 3);
      const ciZ = [zr - zCrit * se, zr + zCrit * se];
      const ciR = ciZ.map(z => (Math.exp(2 * z) - 1) / (Math.exp(2 * z) + 1));
      result = {
        kind: 'pearson_r', variables: [x.name, y.name], n: n, point: r, level: level, ci: ciR,
        headline: 'Pearson r (' + x.name + ', ' + y.name + ') = ' + fmt(r, 2),
        context: Math.round(level * 100) + '% Fisher-z CI [' + fmt(ciR[0], 2) + ', ' + fmt(ciR[1], 2) + ']  ·  n = ' + n,
      };
    }
    if (!result) return;

    const stats =
      statBox('Estimate',        fmt(result.point, 3)) +
      statBox('Lower bound',     fmt(result.ci[0], 3)) +
      statBox('Upper bound',     fmt(result.ci[1], 3)) +
      statBox('Confidence',      Math.round(result.level * 100) + '%');
    const body =
      '<p class="ix-detail-note"><strong>What the CI says</strong>: if we repeated this study many times under identical conditions, ' + Math.round(result.level * 100) + '% of CIs constructed this way would contain the true value. A wide CI signals high uncertainty; a narrow one signals precision.</p>';
    let interp;
    if (result.kind === 'mean')              interp = 'Sample mean = ' + fmt(result.point, 2) + '; we are ' + Math.round(result.level * 100) + '% confident the population mean falls between ' + fmt(result.ci[0], 2) + ' and ' + fmt(result.ci[1], 2) + '.';
    else if (result.kind === 'proportion')   interp = 'Sample proportion = ' + (result.point * 100).toFixed(1) + '%; ' + Math.round(result.level * 100) + '% Wilson CI is [' + (result.ci[0] * 100).toFixed(1) + '%, ' + (result.ci[1] * 100).toFixed(1) + '%]. Wilson is the recommended interval for small samples and rare events.';
    else if (result.kind === 'mean_difference') interp = (result.ci[0] > 0 || result.ci[1] < 0)
                                                ? 'The CI does not include zero, so the difference is statistically distinguishable from zero at α = ' + (1 - result.level).toFixed(2) + '.'
                                                : 'The CI includes zero, consistent with no real difference at α = ' + (1 - result.level).toFixed(2) + '.';
    else                                     interp = 'The CI on r ' + (result.ci[0] > 0 ? 'lies entirely above zero (a positive relationship is supported)' : result.ci[1] < 0 ? 'lies entirely below zero (a negative relationship is supported)' : 'spans zero (cannot rule out no linear relationship)') + ' at the ' + Math.round(result.level * 100) + '% confidence level.';
    show(result.headline, result.context, stats, body, interp, '');
    expose({ test: 'confidence_interval', kind: result.kind, payload: result });
  }

  // ==================================================================
  // LENS: assumption_check
  // ==================================================================
  function runAssumption() {
    const out = allVars.find(v => v.name === document.getElementById('ixAcOutcome').value);
    const grpName = document.getElementById('ixAcGroup').value;
    const grp = grpName ? allVars.find(v => v.name === grpName) : null;
    if (!out) return setStatus('Pick an outcome variable.');
    setStatus('');

    const values = out.values.map(num).filter(x => x != null);
    if (values.length < 3) return setStatus('Need at least 3 non-missing values.');
    const sk = skewness(values);
    const ku = kurtosis(values);
    const n = values.length;
    // K-S-style statistic against fitted normal: max distance between
    // empirical CDF and N(mean, sd) CDF.
    const m = mean(values), s = sd(values);
    const sorted = values.slice().sort((a, b) => a - b);
    let D = 0;
    sorted.forEach((x, i) => {
      const F = normalCDFApprox((x - m) / (s || 1e-12));
      const Fe = (i + 1) / n;
      const FeLow = i / n;
      D = Math.max(D, Math.abs(Fe - F), Math.abs(F - FeLow));
    });
    // Kolmogorov asymptotic critical value at α=0.05: 1.36 / sqrt(n)
    const Dcrit = 1.36 / Math.sqrt(n);
    const normalityPass = Math.abs(sk) < 2 && Math.abs(ku) < 7 && D < Dcrit;
    const normalityTone = normalityPass ? 'ok' : (Math.abs(sk) > 2 || D > Dcrit * 1.5 ? 'alert' : 'warn');

    // Levene's test (if grouping provided)
    let levene = null;
    if (grp) {
      const groups = new Map();
      for (let i = 0; i < rowCount; i++) {
        const y = num(out.values[i]); const g = grp.values[i];
        if (y == null || isMissing(g)) continue;
        const k = String(g);
        if (!groups.has(k)) groups.set(k, []);
        groups.get(k).push(y);
      }
      const levels = Array.from(groups.keys());
      if (levels.length >= 2 && Array.from(groups.values()).every(a => a.length >= 2)) {
        // |X - median(X)| per group, then one-way ANOVA on those values
        const Z = [];
        const groupZ = levels.map(l => {
          const arr = groups.get(l);
          const med = median(arr);
          const z = arr.map(x => Math.abs(x - med));
          z.forEach(v => Z.push({ g: l, z: v }));
          return z;
        });
        const N = Z.length;
        const k = levels.length;
        const grandMean = mean(Z.map(o => o.z));
        const ssB = groupZ.reduce((s, gz) => s + gz.length * Math.pow(mean(gz) - grandMean, 2), 0);
        const ssW = groupZ.reduce((s, gz) => { const gm = mean(gz); return s + gz.reduce((a, x) => a + (x - gm) * (x - gm), 0); }, 0);
        const dfB = k - 1, dfW = N - k;
        const F = dfB && dfW ? (ssB / dfB) / (ssW / dfW || 1e-12) : 0;
        const p = fPValue(F, dfB, dfW);
        levene = { F: F, dfB: dfB, dfW: dfW, p: p, levels: levels, pass: p > 0.05 };
      }
    }

    const stats =
      statBox('Skewness', fmt(sk, 2), Math.abs(sk) < 0.5 ? 'symmetric' : Math.abs(sk) < 2 ? 'moderate skew' : 'severe skew', Math.abs(sk) < 0.5 ? 'ok' : Math.abs(sk) < 2 ? 'warn' : 'alert') +
      statBox('Kurtosis', fmt(ku, 2), Math.abs(ku) < 1 ? 'near normal' : Math.abs(ku) < 7 ? 'mild' : 'extreme', Math.abs(ku) < 1 ? 'ok' : Math.abs(ku) < 7 ? 'warn' : 'alert') +
      statBox('K-S D', fmt(D, 3), D < Dcrit ? 'fits normal' : 'departs from normal', D < Dcrit ? 'ok' : 'warn') +
      (levene ? statBox('Levene F', fmt(levene.F, 2) + ' (' + levene.dfB + ', ' + levene.dfW + ')', levene.pass ? 'variances equal' : 'unequal variances', levene.pass ? 'ok' : 'warn') : '');

    let body = '<h4 class="ix-block-h">Normality</h4>' +
      '<p class="ix-detail-note">' +
        'Variable <strong>' + esc(out.name) + '</strong>, n = ' + n + ', mean = ' + fmt(m, 2) + ', SD = ' + fmt(s, 2) + '. ' +
        'Skewness ' + fmt(sk, 2) + ' (target: |sk| &lt; 0.5 for symmetric; up to 2 for moderate skew). ' +
        'Excess kurtosis ' + fmt(ku, 2) + ' (target: |ku| &lt; 1 for near-normal). ' +
        'Kolmogorov-Smirnov-style statistic D = ' + fmt(D, 3) + '; critical value at α=.05 is ' + fmt(Dcrit, 3) + '.' +
      '</p>';
    if (levene) {
      body += '<h4 class="ix-block-h">Homogeneity of variance (Levene\'s test)</h4>' +
        '<p class="ix-detail-note">F(' + levene.dfB + ', ' + levene.dfW + ') = ' + fmt(levene.F, 2) + ', ' + fmtP(levene.p) +
        '. ' + (levene.pass ? 'Variances do not differ reliably across groups; standard ANOVA / Student\'s t-test are appropriate.' : 'Variances differ reliably across groups; use Welch ANOVA or Welch t-test instead.') + '</p>';
    }

    let interp = '';
    if (normalityPass && (!levene || levene.pass)) interp = 'Both assumptions hold. Standard parametric tests (Student\'s t, ANOVA, Pearson) are appropriate.';
    else if (!normalityPass && (!levene || levene.pass)) interp = 'Normality is questionable but variances are stable. Sample size determines whether to trust parametric tests; with n ≥ 30 per group, the CLT gives some cover. Otherwise consider a non-parametric alternative (Mann-Whitney, Kruskal-Wallis).';
    else if (normalityPass && levene && !levene.pass) interp = 'Normality holds but variances are unequal. Welch\'s t-test or Welch\'s ANOVA is the right choice.';
    else interp = 'Both assumptions fail. Consider a non-parametric test (Mann-Whitney, Kruskal-Wallis) or a transformation (log, square root) that stabilizes both normality and variance.';
    const next = (!normalityPass || (levene && !levene.pass))
      ? 'Re-run your hypothesis test using the recommended variant; the existing Inferential Suite includes Welch t-test and Welch ANOVA.'
      : 'Proceed with standard parametric tests.';

    show('Assumptions for ' + out.name + (grp ? ' across ' + grp.name : ''), normalityPass ? 'Normality appears to hold' : 'Normality questionable', stats, body, interp, next);
    expose({ test: 'assumption_check', variable: out.name, group: grp ? grp.name : null, skewness: sk, kurtosis: ku, ksD: D, ksDcrit: Dcrit, normalityPass: normalityPass, levene: levene });
  }
  function normalCDFApprox(z) {
    // Hastings (1955) approximation, ~1e-7 accurate.
    const t = 1 / (1 + 0.2316419 * Math.abs(z));
    const a = [0.319381530, -0.356563782, 1.781477937, -1.821255978, 1.330274429];
    let poly = 0;
    for (let i = a.length - 1; i >= 0; i--) poly = poly * t + a[i];
    poly *= t;
    const phi = Math.exp(-z * z / 2) / Math.sqrt(2 * Math.PI);
    const cdf = 1 - phi * poly;
    return z >= 0 ? cdf : 1 - cdf;
  }

  // ==================================================================
  // RELICHECK_APP_STATE
  // ==================================================================
  function expose(payload) {
    window.RELICHECK_APP_STATE = Object.assign({
      app_key:  'inferential_extensions',
      app_name: 'Inferential Extensions (' + lensName + ')',
      summary:  buildSummary(payload),
      lens:     lens,
      computed_at: new Date().toISOString(),
    }, payload);
  }
  function buildSummary(p) {
    if (p.test === 'paired_t')             return 'Paired t(' + p.df + ') = ' + fmt(p.t, 2) + ', ' + fmtP(p.p) + ', d_z = ' + fmt(p.dz, 2);
    if (p.test === 'welch_anova')          return 'Welch F(' + p.df1 + ', ' + fmt(p.df2, 1) + ') = ' + fmt(p.F, 2) + ', ' + fmtP(p.p) + ', η² = ' + fmt(p.eta2, 3);
    if (p.test === 'post_hoc')             return 'Post-hoc: ' + (p.pairs ? p.pairs.length : 0) + ' comparisons, ' + (p.pairs ? p.pairs.filter(x => x.p_adj < 0.05).length : 0) + ' significant (Holm).';
    if (p.test === 'regression')           return 'Regression: R² = ' + fmt(p.r2, 3) + ', F(' + p.df[0] + ', ' + p.df[1] + ') = ' + fmt(p.F, 2) + ', ' + fmtP(p.p);
    if (p.test === 'confidence_interval')  return 'CI (' + p.kind + ').';
    if (p.test === 'assumption_check')     return 'Assumption check: ' + (p.normalityPass ? 'normality OK' : 'normality questionable') + (p.levene ? '; Levene ' + (p.levene.pass ? 'OK' : 'fails') : '') + '.';
    return 'Inferential Extensions';
  }
})();
