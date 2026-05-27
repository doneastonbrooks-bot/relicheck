// ReliCheck Inferential Analysis
// -------------------------------------------------------------------
// Four tests under one engine:
//   - Welch's t-test (default) or Student's t-test
//   - One-way ANOVA (with η² and ω²)
//   - Chi-square test of independence (with Cramer's V)
//   - Pearson correlation (with r² and 95% CI via Fisher z)
//
// Real p-values, not z approximations:
//   - t and F p-values from the regularized incomplete beta function
//   - chi-square p-values from the regularized incomplete gamma function
// Both implemented with the standard continued-fraction recipes used in
// Numerical Recipes (Lentz's method), accurate to ~1e-8 across the
// usable range.
//
// Dataset shape: same as strength-index / open-ended-summary.
//   { source, variables: [{name, types, values}], rowCount }
//
// Mount-page contract:
//   window.INFERENTIAL_DATASET  (required)
//   window.INFERENTIAL_TEST     (optional; locks the picker to one test
//                               on a dedicated route like /t-test.php)
//   window.INFERENTIAL_CONFIG   (optional; per-studio language overrides)
//
// Save contract: window.RELICHECK_APP_STATE set after each successful run.

(function () {
  'use strict';

  // ====================================================================
  // Dataset & config resolution
  // ====================================================================
  let dataset = window.INFERENTIAL_DATASET;
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
  } catch (e) {
    console.warn('Inferential: localStorage read failed:', e);
  }
  window.INFERENTIAL_DATASET_RESOLVED = dataset;
  window.INFERENTIAL_DATASET_SOURCE   = datasetSource;

  if (!dataset || !Array.isArray(dataset.variables)) {
    console.warn('Inferential: no dataset available');
    setStatus('No dataset available. Run Evidence Intake first.');
    return;
  }

  const CONFIG = Object.assign({
    // Per-studio overrides land here. Generic defaults work everywhere.
  }, window.INFERENTIAL_CONFIG || {});

  const lockedTest = window.INFERENTIAL_TEST || null;
  const allVars    = dataset.variables;
  const rowCount   = dataset.rowCount || (allVars[0] ? allVars[0].values.length : 0);

  // ====================================================================
  // Numerical helpers
  // ====================================================================
  function num(v) { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function mean(arr) { return arr.reduce((s, v) => s + v, 0) / arr.length; }
  function variance(arr) {
    if (arr.length < 2) return 0;
    const m = mean(arr);
    return arr.reduce((s, v) => s + (v - m) * (v - m), 0) / (arr.length - 1);
  }
  function sd(arr) { return Math.sqrt(variance(arr)); }
  function isMissing(v) { return v === '' || v == null; }

  // log-gamma via Lanczos approximation, accurate to ~1e-12.
  function logGamma(x) {
    const c = [
      76.18009172947146, -86.50532032941677, 24.01409824083091,
      -1.231739572450155, 0.001208650973866179, -0.000005395239384953
    ];
    let y = x;
    let tmp = x + 5.5;
    tmp -= (x + 0.5) * Math.log(tmp);
    let ser = 1.000000000190015;
    for (let j = 0; j < 6; j++) {
      y += 1;
      ser += c[j] / y;
    }
    return -tmp + Math.log(2.5066282746310005 * ser / x);
  }

  // Regularized lower incomplete gamma P(a, x) via series (small x)
  // and continued fraction (large x). Accurate to ~1e-8.
  function regGammaP(a, x) {
    if (x < 0 || a <= 0) return 0;
    if (x === 0) return 0;
    if (x < a + 1) {
      // Series expansion
      let ap = a;
      let sum = 1 / a;
      let del = sum;
      for (let n = 1; n < 200; n++) {
        ap += 1;
        del *= x / ap;
        sum += del;
        if (Math.abs(del) < Math.abs(sum) * 1e-12) break;
      }
      return sum * Math.exp(-x + a * Math.log(x) - logGamma(a));
    } else {
      // Continued fraction (Lentz). Returns Q = 1 - P then we flip.
      const FPMIN = 1e-300;
      let b = x + 1 - a;
      let c = 1 / FPMIN;
      let d = 1 / b;
      let h = d;
      for (let i = 1; i < 200; i++) {
        const an = -i * (i - a);
        b += 2;
        d = an * d + b;
        if (Math.abs(d) < FPMIN) d = FPMIN;
        c = b + an / c;
        if (Math.abs(c) < FPMIN) c = FPMIN;
        d = 1 / d;
        const del = d * c;
        h *= del;
        if (Math.abs(del - 1) < 1e-12) break;
      }
      const Q = Math.exp(-x + a * Math.log(x) - logGamma(a)) * h;
      return 1 - Q;
    }
  }

  // Regularized incomplete beta I_x(a, b) via continued fraction
  // (Numerical Recipes section 6.4). Accurate to ~1e-10.
  function regBetaI(x, a, b) {
    if (x <= 0) return 0;
    if (x >= 1) return 1;
    const bt = Math.exp(
      logGamma(a + b) - logGamma(a) - logGamma(b) +
      a * Math.log(x) + b * Math.log(1 - x)
    );
    if (x < (a + 1) / (a + b + 2)) {
      return bt * betacf(x, a, b) / a;
    }
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
      d = 1 + aa * d;
      if (Math.abs(d) < FPMIN) d = FPMIN;
      c = 1 + aa / c;
      if (Math.abs(c) < FPMIN) c = FPMIN;
      d = 1 / d;
      h *= d * c;
      aa = -(a + m) * (qab + m) * x / ((a + m2) * (qap + m2));
      d = 1 + aa * d;
      if (Math.abs(d) < FPMIN) d = FPMIN;
      c = 1 + aa / c;
      if (Math.abs(c) < FPMIN) c = FPMIN;
      d = 1 / d;
      const del = d * c;
      h *= del;
      if (Math.abs(del - 1) < 1e-12) break;
    }
    return h;
  }

  // Two-tailed p from Student's t with df degrees of freedom.
  // p = I_{df/(df + t²)}(df/2, 1/2)
  function tPValue(t, df) {
    if (!isFinite(t) || df <= 0) return 1;
    const x = df / (df + t * t);
    return regBetaI(x, df / 2, 0.5);
  }
  // Upper-tail p from F(df1, df2): p = I_{df2/(df2 + df1*F)}(df2/2, df1/2)
  function fPValue(F, df1, df2) {
    if (!isFinite(F) || F <= 0 || df1 <= 0 || df2 <= 0) return 1;
    const x = df2 / (df2 + df1 * F);
    return regBetaI(x, df2 / 2, df1 / 2);
  }
  // Upper-tail p from chi-square: p = 1 - P(df/2, x/2)
  function chiPValue(x, df) {
    if (!isFinite(x) || x < 0 || df <= 0) return 1;
    return 1 - regGammaP(df / 2, x / 2);
  }

  // Inverse normal CDF via Beasley-Springer-Moro. Used only for Fisher-z CI.
  function invNormalCDF(p) {
    if (p <= 0) return -Infinity;
    if (p >= 1) return Infinity;
    const a = [-39.69683028665376, 220.9460984245205, -275.9285104469687,
                138.357751867269, -30.66479806614716, 2.506628277459239];
    const b = [-54.47609879822406, 161.5858368580409, -155.6989798598866,
                66.80131188771972, -13.28068155288572];
    const c = [-0.007784894002430293, -0.3223964580411365, -2.400758277161838,
                -2.549732539343734, 4.374664141464968, 2.938163982698783];
    const d = [0.007784695709041462, 0.3224671290700398, 2.445134137142996,
               3.754408661907416];
    const plow = 0.02425, phigh = 1 - plow;
    let q, r;
    if (p < plow) {
      q = Math.sqrt(-2 * Math.log(p));
      return (((((c[0]*q+c[1])*q+c[2])*q+c[3])*q+c[4])*q+c[5]) /
             ((((d[0]*q+d[1])*q+d[2])*q+d[3])*q+1);
    }
    if (p <= phigh) {
      q = p - 0.5;
      r = q * q;
      return (((((a[0]*r+a[1])*r+a[2])*r+a[3])*r+a[4])*r+a[5]) * q /
             (((((b[0]*r+b[1])*r+b[2])*r+b[3])*r+b[4])*r+1);
    }
    q = Math.sqrt(-2 * Math.log(1 - p));
    return -(((((c[0]*q+c[1])*q+c[2])*q+c[3])*q+c[4])*q+c[5]) /
            ((((d[0]*q+d[1])*q+d[2])*q+d[3])*q+1);
  }

  // ====================================================================
  // Variable inventory helpers
  // ====================================================================
  function hasType(v, t)        { return v.types && v.types.indexOf(t) !== -1; }
  function isNumericish(v)      { return hasType(v, 'numeric') || hasType(v, 'likert'); }
  function isCategorical(v)     { return hasType(v, 'categorical'); }
  function numericVars()        { return allVars.filter(isNumericish); }
  function categoricalVars()    { return allVars.filter(isCategorical); }
  function pairedValid(v1, v2) {
    const out = { v1: [], v2: [] };
    for (let i = 0; i < rowCount; i++) {
      const a = v1.values[i], b = v2.values[i];
      if (isMissing(a) || isMissing(b)) continue;
      out.v1.push(a); out.v2.push(b);
    }
    return out;
  }
  function groupLevels(v) {
    const seen = new Map();
    v.values.forEach(val => {
      if (isMissing(val)) return;
      const key = String(val);
      seen.set(key, (seen.get(key) || 0) + 1);
    });
    return Array.from(seen.entries()).map(([level, count]) => ({ level, count }));
  }

  // ====================================================================
  // Test implementations
  // ====================================================================

  // ---- T-TEST (Welch's by default; Student's optional) ----
  function runTTest(outcomeVar, groupVar, opts) {
    const useWelch = opts && opts.welch !== false;
    const pair = pairedValid(outcomeVar, groupVar);
    const groups = new Map();
    for (let i = 0; i < pair.v1.length; i++) {
      const y = num(pair.v1[i]);
      if (y == null) continue;
      const g = String(pair.v2[i]);
      if (!groups.has(g)) groups.set(g, []);
      groups.get(g).push(y);
    }
    const levels = Array.from(groups.keys());
    if (levels.length !== 2) {
      return error('A t-test requires exactly two groups. The grouping variable has ' + levels.length + ' levels. Use ANOVA for 3+ groups.');
    }
    const a = groups.get(levels[0]), b = groups.get(levels[1]);
    if (a.length < 2 || b.length < 2) return error('Each group needs at least 2 observations.');
    const m1 = mean(a), m2 = mean(b);
    const v1 = variance(a), v2 = variance(b);
    const n1 = a.length, n2 = b.length;
    const s1 = Math.sqrt(v1), s2 = Math.sqrt(v2);

    let t, df;
    if (useWelch) {
      const se = Math.sqrt(v1 / n1 + v2 / n2);
      t = (m1 - m2) / (se || 1e-12);
      // Welch-Satterthwaite df
      const num1 = (v1 / n1 + v2 / n2);
      df = (num1 * num1) /
           ((v1 * v1) / (n1 * n1 * (n1 - 1)) + (v2 * v2) / (n2 * n2 * (n2 - 1)));
    } else {
      const pooled = ((n1 - 1) * v1 + (n2 - 1) * v2) / (n1 + n2 - 2);
      const se = Math.sqrt(pooled * (1 / n1 + 1 / n2));
      t = (m1 - m2) / (se || 1e-12);
      df = n1 + n2 - 2;
    }
    const p = tPValue(t, df);

    // Cohen's d (pooled SD)
    const pooledSd = Math.sqrt(((n1 - 1) * v1 + (n2 - 1) * v2) / (n1 + n2 - 2));
    const d = (m1 - m2) / (pooledSd || 1e-12);

    // 95% CI of the mean difference (Welch SE if Welch, otherwise pooled)
    const seCI = useWelch
      ? Math.sqrt(v1 / n1 + v2 / n2)
      : Math.sqrt(((n1 - 1) * v1 + (n2 - 1) * v2) / (n1 + n2 - 2) * (1 / n1 + 1 / n2));
    // 95% t critical for two-tailed: solve I_x(df/2, 1/2) = 0.05 → too involved.
    // Use a normal approximation when df > 30, exact look-up for small df via
    // bisection on tPValue.
    const tCrit = tCriticalTwoTailed(0.05, df);
    const diff = m1 - m2;
    const ciLow  = diff - tCrit * seCI;
    const ciHigh = diff + tCrit * seCI;

    const effectBand = bandCohenD(d);
    return {
      ok:        true,
      test:      useWelch ? "Welch's t-test" : "Student's t-test",
      statName:  't',
      statistic: t,
      df:        df,
      p:         p,
      effect:    { name: "Cohen's d", value: d, band: effectBand.label, tone: effectBand.tone },
      detail:    {
        groups: [
          { name: levels[0], n: n1, mean: m1, sd: s1 },
          { name: levels[1], n: n2, mean: m2, sd: s2 },
        ],
        difference: diff,
        ci95:       [ciLow, ciHigh],
      },
      assumptions: [
        levels.length === 2 ? { ok: true, msg: 'Two groups present ✓' }
                            : { ok: false, msg: 'Groups must be exactly two' },
        (n1 >= 30 && n2 >= 30) ? { ok: true, msg: 'Each group has n ≥ 30 (CLT applies) ✓' } :
        (n1 >= 15 && n2 >= 15) ? { ok: 'warn', msg: 'Small groups (n < 30). The Welch correction handles unequal variances; assume approximate normality of group means.' } :
                                  { ok: 'warn', msg: 'Very small groups. Treat the p-value as approximate.' },
        useWelch ? { ok: true, msg: "Welch's correction is on, so unequal variances are handled ✓" } :
                   { ok: 'warn', msg: 'Equal-variance assumption: Student\'s t-test assumes the groups have similar variance.' },
      ],
    };
  }

  // ---- ANOVA (one-way) ----
  function runAnova(outcomeVar, groupVar) {
    const pair = pairedValid(outcomeVar, groupVar);
    const groups = new Map();
    for (let i = 0; i < pair.v1.length; i++) {
      const y = num(pair.v1[i]);
      if (y == null) continue;
      const g = String(pair.v2[i]);
      if (!groups.has(g)) groups.set(g, []);
      groups.get(g).push(y);
    }
    const levels = Array.from(groups.keys());
    if (levels.length < 2) return error('ANOVA requires at least 2 groups.');
    if (levels.length === 2) {
      // ANOVA on 2 groups is mathematically a t-test; we still run it but
      // surface a note.
    }
    const groupData = levels.map(l => groups.get(l));
    const ns        = groupData.map(arr => arr.length);
    if (ns.some(n => n < 2)) return error('Each group needs at least 2 observations.');

    const N      = ns.reduce((s, n) => s + n, 0);
    const k      = levels.length;
    const grand  = mean(groupData.flat());
    const ssB    = groupData.reduce((s, g) => s + g.length * Math.pow(mean(g) - grand, 2), 0);
    const ssW    = groupData.reduce((s, g) => {
      const gm = mean(g);
      return s + g.reduce((acc, x) => acc + (x - gm) * (x - gm), 0);
    }, 0);
    const dfB = k - 1;
    const dfW = N - k;
    const msB = ssB / dfB;
    const msW = ssW / dfW;
    const F   = msB / (msW || 1e-12);
    const p   = fPValue(F, dfB, dfW);

    // η² = SS_between / SS_total
    const ssT = ssB + ssW;
    const eta2 = ssT ? ssB / ssT : 0;
    // ω² = (SS_B - df_B * MS_W) / (SS_T + MS_W)
    const omega2 = (ssT + msW) ? (ssB - dfB * msW) / (ssT + msW) : 0;

    const effectBand = bandEtaSquared(eta2);

    return {
      ok:        true,
      test:      'One-way ANOVA',
      statName:  'F',
      statistic: F,
      df:        dfB + ', ' + dfW,
      p:         p,
      effect:    { name: 'η²', value: eta2, band: effectBand.label, tone: effectBand.tone, omega2: omega2 },
      detail:    {
        groups: groupData.map((g, i) => ({
          name: levels[i], n: g.length, mean: mean(g), sd: sd(g),
        })),
        grandMean: grand,
        ssB: ssB, ssW: ssW, ssT: ssT, msB: msB, msW: msW,
      },
      assumptions: [
        { ok: true, msg: levels.length + ' groups with n = ' + ns.join(', ') + ' ✓' },
        ns.every(n => n >= 30) ? { ok: true, msg: 'Each group has n ≥ 30 (CLT applies) ✓' } :
        ns.every(n => n >= 10) ? { ok: 'warn', msg: 'Modest group sizes. Standard ANOVA is fairly robust; consider Welch ANOVA if variances differ.' } :
                                  { ok: 'warn', msg: 'Small groups. Treat results as exploratory.' },
        (Math.max.apply(null, groupData.map(variance)) / Math.min.apply(null, groupData.map(variance))) <= 4 ?
          { ok: true, msg: 'Group variances are within 4× of each other ✓' } :
          { ok: 'warn', msg: 'Group variances differ more than 4×. Welch ANOVA (not yet implemented in this app) would be more defensible.' },
      ],
    };
  }

  // ---- Chi-square test of independence ----
  function runChiSquare(rowVar, colVar) {
    const pair = pairedValid(rowVar, colVar);
    const rowLevels = Array.from(new Set(pair.v1.map(String))).sort();
    const colLevels = Array.from(new Set(pair.v2.map(String))).sort();
    if (rowLevels.length < 2 || colLevels.length < 2) {
      return error('Both variables need at least 2 levels for chi-square.');
    }
    const r = rowLevels.length, c = colLevels.length;
    const rowIdx = new Map(rowLevels.map((l, i) => [l, i]));
    const colIdx = new Map(colLevels.map((l, i) => [l, i]));
    const observed = Array.from({ length: r }, () => new Array(c).fill(0));
    for (let i = 0; i < pair.v1.length; i++) {
      observed[rowIdx.get(String(pair.v1[i]))][colIdx.get(String(pair.v2[i]))]++;
    }
    const rowTotals = observed.map(row => row.reduce((s, v) => s + v, 0));
    const colTotals = new Array(c).fill(0);
    observed.forEach(row => row.forEach((v, j) => { colTotals[j] += v; }));
    const N = rowTotals.reduce((s, v) => s + v, 0);
    if (N === 0) return error('No paired observations.');

    let chi = 0;
    const expected = Array.from({ length: r }, () => new Array(c).fill(0));
    let lowExpected = 0;
    for (let i = 0; i < r; i++) {
      for (let j = 0; j < c; j++) {
        const E = (rowTotals[i] * colTotals[j]) / N;
        expected[i][j] = E;
        if (E < 5) lowExpected++;
        if (E > 0) chi += (observed[i][j] - E) * (observed[i][j] - E) / E;
      }
    }
    const df = (r - 1) * (c - 1);
    const p  = chiPValue(chi, df);
    // Cramer's V
    const v  = Math.sqrt(chi / (N * Math.min(r - 1, c - 1)));
    const effectBand = bandCramerV(v, Math.min(r - 1, c - 1));

    return {
      ok:        true,
      test:      'Chi-square test of independence',
      statName:  'χ²',
      statistic: chi,
      df:        df,
      p:         p,
      effect:    { name: "Cramer's V", value: v, band: effectBand.label, tone: effectBand.tone },
      detail:    {
        rowLevels: rowLevels, colLevels: colLevels,
        observed:  observed,  expected:  expected,
        N:         N,         lowExpected: lowExpected,
      },
      assumptions: [
        { ok: true, msg: 'Cross-tab is ' + r + ' × ' + c + ' (df = ' + df + ') ✓' },
        N >= 30 ? { ok: true, msg: 'Sample size ' + N + ' ≥ 30 ✓' } :
                  { ok: 'warn', msg: 'Small sample (' + N + '). Consider Fisher\'s exact test for sparse cells.' },
        lowExpected === 0 ?
          { ok: true, msg: 'All expected counts ≥ 5 ✓' } :
          { ok: 'warn', msg: lowExpected + ' cell(s) have expected count < 5. Chi-square approximation may be inaccurate; Fisher\'s exact recommended.' },
      ],
    };
  }

  // ---- Pearson correlation ----
  function runCorrelation(xVar, yVar) {
    const pair = pairedValid(xVar, yVar);
    const xs = pair.v1.map(num).filter(x => x != null);
    const ys = pair.v2.map(num).filter(y => y != null);
    // Re-pair on numeric coercion (drop a row if either is non-numeric)
    const pairs = [];
    for (let i = 0; i < pair.v1.length; i++) {
      const x = num(pair.v1[i]), y = num(pair.v2[i]);
      if (x == null || y == null) continue;
      pairs.push([x, y]);
    }
    const n = pairs.length;
    if (n < 3) return error('Correlation needs at least 3 paired numeric observations.');

    const mx = pairs.reduce((s, p) => s + p[0], 0) / n;
    const my = pairs.reduce((s, p) => s + p[1], 0) / n;
    let cov = 0, vx = 0, vy = 0;
    pairs.forEach(p => {
      const dx = p[0] - mx, dy = p[1] - my;
      cov += dx * dy; vx += dx * dx; vy += dy * dy;
    });
    const r = cov / (Math.sqrt(vx * vy) || 1e-12);
    const df = n - 2;
    // Significance: t = r * sqrt(df) / sqrt(1 - r²)
    const safe = Math.max(1 - r * r, 1e-12);
    const t = r * Math.sqrt(df) / Math.sqrt(safe);
    const p = tPValue(t, df);
    // Fisher z for 95% CI
    const zr = 0.5 * Math.log((1 + r) / (1 - r));
    const seZ = 1 / Math.sqrt(n - 3);
    const zCrit = invNormalCDF(0.975);
    const ciZLow  = zr - zCrit * seZ;
    const ciZHigh = zr + zCrit * seZ;
    const ciRLow  = (Math.exp(2 * ciZLow)  - 1) / (Math.exp(2 * ciZLow)  + 1);
    const ciRHigh = (Math.exp(2 * ciZHigh) - 1) / (Math.exp(2 * ciZHigh) + 1);
    const r2 = r * r;
    const effectBand = bandR(r);

    return {
      ok:        true,
      test:      'Pearson correlation',
      statName:  'r',
      statistic: r,
      df:        df,
      p:         p,
      effect:    { name: 'r²', value: r2, band: effectBand.label, tone: effectBand.tone },
      detail:    {
        n: n, meanX: mx, meanY: my,
        ci95: [ciRLow, ciRHigh],
        direction: r > 0 ? 'positive' : r < 0 ? 'negative' : 'flat',
      },
      assumptions: [
        { ok: true, msg: n + ' paired observations ✓' },
        n >= 30 ? { ok: true, msg: 'n ≥ 30; t-test on r is well-calibrated ✓' } :
                  { ok: 'warn', msg: 'Small n (' + n + '). The p-value is approximate; the CI is wider than at large n.' },
        { ok: 'warn', msg: 'Pearson assumes a linear relationship. Inspect a scatterplot before drawing conclusions.' },
      ],
    };
  }

  // ====================================================================
  // Bands (interpretation)
  // ====================================================================
  function bandCohenD(d) {
    const a = Math.abs(d);
    if (a < 0.20) return { label: 'negligible', tone: 'muted' };
    if (a < 0.50) return { label: 'small',      tone: 'warn'  };
    if (a < 0.80) return { label: 'medium',     tone: 'ok'    };
    return            { label: 'large',         tone: 'strong'};
  }
  function bandEtaSquared(e) {
    if (e < 0.01) return { label: 'negligible', tone: 'muted' };
    if (e < 0.06) return { label: 'small',      tone: 'warn'  };
    if (e < 0.14) return { label: 'medium',     tone: 'ok'    };
    return             { label: 'large',         tone: 'strong'};
  }
  function bandCramerV(v, dfMin) {
    // Cohen's effect-size table for chi-square depends on df.
    const small  = [0.10, 0.07, 0.06][Math.min(dfMin - 1, 2)] || 0.05;
    const medium = [0.30, 0.21, 0.17][Math.min(dfMin - 1, 2)] || 0.15;
    const large  = [0.50, 0.35, 0.29][Math.min(dfMin - 1, 2)] || 0.25;
    if (v < small)  return { label: 'negligible', tone: 'muted' };
    if (v < medium) return { label: 'small',      tone: 'warn'  };
    if (v < large)  return { label: 'medium',     tone: 'ok'    };
    return              { label: 'large',         tone: 'strong'};
  }
  function bandR(r) {
    const a = Math.abs(r);
    if (a < 0.10) return { label: 'negligible', tone: 'muted' };
    if (a < 0.30) return { label: 'small',      tone: 'warn'  };
    if (a < 0.50) return { label: 'medium',     tone: 'ok'    };
    return              { label: 'large',         tone: 'strong'};
  }

  // ====================================================================
  // UI wiring
  // ====================================================================
  const els = {
    picker:     document.getElementById('infPicker'),
    var1:       document.getElementById('infVar1'),
    var2:       document.getElementById('infVar2'),
    var1Label:  document.getElementById('infVar1Label'),
    var2Label:  document.getElementById('infVar2Label'),
    var1Hint:   document.getElementById('infVar1Hint'),
    var2Hint:   document.getElementById('infVar2Hint'),
    options:    document.getElementById('infOptions'),
    welch:      document.getElementById('infWelch'),
    run:        document.getElementById('infRun'),
    status:     document.getElementById('infStatus'),
    results:    document.getElementById('infResults'),
    empty:      document.getElementById('infEmpty'),
    assumptions:document.getElementById('infAssumptions'),
    assumptionsList: document.getElementById('infAssumptionList'),
    testLabel:  document.getElementById('infTestLabel'),
    headline:   document.getElementById('infHeadline'),
    context:    document.getElementById('infContext'),
    statName:   document.getElementById('infStatNameLabel'),
    statistic:  document.getElementById('infStatistic'),
    df:         document.getElementById('infDf'),
    p:          document.getElementById('infP'),
    pPill:      document.getElementById('infPPill'),
    effectLabel:document.getElementById('infEffectLabel'),
    effect:     document.getElementById('infEffect'),
    effectBand: document.getElementById('infEffectBand'),
    detail:     document.getElementById('infDetail'),
    interp:     document.getElementById('infInterp'),
    next:       document.getElementById('infNext'),
  };

  // ---- Hide picker if test is locked by mount page ----
  let currentTest = lockedTest || 't_test';
  if (lockedTest) {
    els.picker.hidden = true;
  } else {
    els.picker.querySelectorAll('.inf-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        currentTest = btn.getAttribute('data-test');
        syncPicker();
        updateFieldShape();
      });
    });
  }

  function syncPicker() {
    els.picker.querySelectorAll('.inf-tab').forEach(btn => {
      const isActive = btn.getAttribute('data-test') === currentTest;
      btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
      btn.classList.toggle('is-active', isActive);
    });
  }

  // ---- Field shape depends on which test is selected ----
  function updateFieldShape() {
    const shapes = {
      t_test: {
        l1: 'Outcome variable',         h1: 'Numeric or Likert',
        l2: 'Grouping variable',        h2: 'Categorical with exactly 2 levels',
        pop1: numericVars(),            pop2: categoricalVars().filter(v => groupLevels(v).length === 2),
        options: 'welch',
      },
      anova: {
        l1: 'Outcome variable',         h1: 'Numeric or Likert',
        l2: 'Grouping variable',        h2: 'Categorical with 3 or more levels',
        pop1: numericVars(),            pop2: categoricalVars().filter(v => groupLevels(v).length >= 3),
        options: 'none',
      },
      chi_square: {
        l1: 'Row variable',             h1: 'Categorical',
        l2: 'Column variable',          h2: 'Categorical',
        pop1: categoricalVars(),        pop2: categoricalVars(),
        options: 'none',
      },
      correlation: {
        l1: 'Variable X',               h1: 'Numeric or Likert',
        l2: 'Variable Y',               h2: 'Numeric or Likert',
        pop1: numericVars(),            pop2: numericVars(),
        options: 'none',
      },
    };
    const s = shapes[currentTest];
    els.var1Label.textContent = s.l1;
    els.var2Label.textContent = s.l2;
    els.var1Hint.textContent  = s.h1;
    els.var2Hint.textContent  = s.h2;
    fillSelect(els.var1, s.pop1);
    fillSelect(els.var2, s.pop2.filter(v => v.name !== els.var1.value));
    // Options strip visibility
    if (s.options === 'welch') {
      els.options.hidden = false;
    } else {
      els.options.hidden = true;
    }
  }
  function fillSelect(sel, vars) {
    const prev = sel.value;
    sel.innerHTML = '';
    if (!vars.length) {
      const opt = document.createElement('option');
      opt.value = ''; opt.textContent = '— no matching variables —';
      sel.appendChild(opt);
      sel.disabled = true;
      return;
    }
    sel.disabled = false;
    vars.forEach(v => {
      const opt = document.createElement('option');
      opt.value = v.name;
      opt.textContent = v.name;
      sel.appendChild(opt);
    });
    if (vars.some(v => v.name === prev)) sel.value = prev;
  }

  // ---- Run handler ----
  els.run.addEventListener('click', () => {
    const v1 = allVars.find(v => v.name === els.var1.value);
    const v2 = allVars.find(v => v.name === els.var2.value);
    if (!v1 || !v2) { setStatus('Pick two variables first.'); return; }
    setStatus('');
    let result;
    switch (currentTest) {
      case 't_test':      result = runTTest(v1, v2, { welch: els.welch.checked }); break;
      case 'anova':       result = runAnova(v1, v2); break;
      case 'chi_square':  result = runChiSquare(v1, v2); break;
      case 'correlation': result = runCorrelation(v1, v2); break;
    }
    if (!result || !result.ok) {
      setStatus(result ? result.msg : 'Unable to run the analysis.');
      return;
    }
    renderResult(result, v1, v2);
    exposeAppState(result, v1, v2);
  });

  // ---- Result rendering ----
  function renderResult(r, v1, v2) {
    els.empty.hidden = true;
    els.results.hidden = false;
    els.assumptions.hidden = false;

    els.testLabel.textContent = r.test;
    els.headline.textContent  = headlineFor(r, v1, v2);
    els.context.textContent   = contextFor(r, v1, v2);

    els.statName.textContent  = r.statName;
    els.statistic.textContent = fmt(r.statistic, 3);
    els.df.textContent        = String(r.df);
    els.p.textContent         = fmtP(r.p);
    setPPill(r.p);
    els.effectLabel.textContent = r.effect.name;
    els.effect.textContent      = fmt(r.effect.value, 3);
    els.effectBand.textContent  = r.effect.band;
    els.effectBand.setAttribute('data-tone', r.effect.tone);

    renderDetail(r);
    renderAssumptions(r.assumptions);

    els.interp.textContent = interpretationFor(r, v1, v2);
    els.next.textContent   = nextStepFor(r, v1, v2);
  }
  function setPPill(p) {
    let label, tone;
    if (p < 0.001)      { label = 'p < .001'; tone = 'strong'; }
    else if (p < 0.01)  { label = 'highly significant'; tone = 'strong'; }
    else if (p < 0.05)  { label = 'significant'; tone = 'ok'; }
    else if (p < 0.10)  { label = 'marginal'; tone = 'warn'; }
    else                { label = 'not significant'; tone = 'muted'; }
    els.pPill.textContent = label;
    els.pPill.setAttribute('data-tone', tone);
  }

  function renderDetail(r) {
    els.detail.innerHTML = '';
    if (r.test.indexOf('t-test') !== -1) {
      const tbl = document.createElement('table');
      tbl.className = 'inf-table';
      tbl.innerHTML =
        '<thead><tr><th>Group</th><th>n</th><th>Mean</th><th>SD</th></tr></thead>' +
        '<tbody>' +
        r.detail.groups.map(g =>
          '<tr><td>' + esc(g.name) + '</td><td>' + g.n + '</td><td>' + fmt(g.mean, 2) + '</td><td>' + fmt(g.sd, 2) + '</td></tr>'
        ).join('') +
        '</tbody>';
      els.detail.appendChild(tbl);
      const ci = document.createElement('p');
      ci.className = 'inf-detail-note';
      ci.innerHTML = 'Mean difference: <strong>' + fmt(r.detail.difference, 2) + '</strong>  ·  95% CI [' + fmt(r.detail.ci95[0], 2) + ', ' + fmt(r.detail.ci95[1], 2) + ']';
      els.detail.appendChild(ci);
    } else if (r.test === 'One-way ANOVA') {
      const tbl = document.createElement('table');
      tbl.className = 'inf-table';
      tbl.innerHTML =
        '<thead><tr><th>Group</th><th>n</th><th>Mean</th><th>SD</th></tr></thead>' +
        '<tbody>' +
        r.detail.groups.map(g =>
          '<tr><td>' + esc(g.name) + '</td><td>' + g.n + '</td><td>' + fmt(g.mean, 2) + '</td><td>' + fmt(g.sd, 2) + '</td></tr>'
        ).join('') +
        '</tbody>';
      els.detail.appendChild(tbl);
      const note = document.createElement('p');
      note.className = 'inf-detail-note';
      note.innerHTML = 'ω² = <strong>' + fmt(r.effect.omega2, 3) + '</strong>  ·  Grand mean = ' + fmt(r.detail.grandMean, 2);
      els.detail.appendChild(note);
    } else if (r.test.indexOf('Chi-square') !== -1) {
      // Render observed × expected contingency table
      const { rowLevels, colLevels, observed, expected, N } = r.detail;
      const tbl = document.createElement('table');
      tbl.className = 'inf-table inf-table-contingency';
      let html = '<thead><tr><th></th>';
      colLevels.forEach(c => html += '<th>' + esc(c) + '</th>');
      html += '</tr></thead><tbody>';
      rowLevels.forEach((row, i) => {
        html += '<tr><th class="rowhead">' + esc(row) + '</th>';
        colLevels.forEach((col, j) => {
          html += '<td><div class="obs">' + observed[i][j] + '</div><div class="exp">exp ' + fmt(expected[i][j], 1) + '</div></td>';
        });
        html += '</tr>';
      });
      html += '</tbody>';
      tbl.innerHTML = html;
      els.detail.appendChild(tbl);
      const note = document.createElement('p');
      note.className = 'inf-detail-note';
      note.textContent = 'N = ' + N + ' paired observations.';
      els.detail.appendChild(note);
    } else if (r.test === 'Pearson correlation') {
      const note = document.createElement('p');
      note.className = 'inf-detail-note';
      note.innerHTML =
        '<strong>' + r.detail.n + '</strong> paired observations  ·  ' +
        '95% CI for r [' + fmt(r.detail.ci95[0], 2) + ', ' + fmt(r.detail.ci95[1], 2) + ']  ·  ' +
        '<strong>' + fmt(r.effect.value * 100, 1) + '%</strong> of variance in Y is shared with X (r²)';
      els.detail.appendChild(note);
    }
  }
  function renderAssumptions(list) {
    els.assumptionsList.innerHTML = '';
    list.forEach(a => {
      const li = document.createElement('li');
      const tone = a.ok === true ? 'ok' : a.ok === 'warn' ? 'warn' : 'alert';
      li.setAttribute('data-tone', tone);
      const pip = document.createElement('span');
      pip.className = 'inf-assumption-pip';
      pip.setAttribute('aria-hidden', 'true');
      li.appendChild(pip);
      const txt = document.createElement('span');
      txt.textContent = a.msg;
      li.appendChild(txt);
      els.assumptionsList.appendChild(li);
    });
  }

  // ---- Headlines, context, interpretation, next-step ----
  function headlineFor(r, v1, v2) {
    if (r.test.indexOf('t-test') !== -1) {
      const a = r.detail.groups[0], b = r.detail.groups[1];
      const dir = a.mean > b.mean ? 'higher' : 'lower';
      return esc(a.name) + ' scored ' + dir + ' than ' + esc(b.name) + ' on ' + esc(v1.name);
    }
    if (r.test === 'One-way ANOVA') {
      return 'Group means on ' + esc(v1.name) + ' differ across levels of ' + esc(v2.name);
    }
    if (r.test.indexOf('Chi-square') !== -1) {
      return 'Association between ' + esc(v1.name) + ' and ' + esc(v2.name);
    }
    if (r.test === 'Pearson correlation') {
      const dir = r.statistic > 0 ? 'positive' : r.statistic < 0 ? 'negative' : 'no';
      return dir.charAt(0).toUpperCase() + dir.slice(1) + ' relationship between ' + esc(v1.name) + ' and ' + esc(v2.name);
    }
    return r.test;
  }
  function contextFor(r, v1, v2) {
    if (r.test.indexOf('t-test') !== -1) {
      return r.test + ', t(' + fmt(r.df, 1) + ') = ' + fmt(r.statistic, 2) + ', ' + fmtP(r.p) + ', d = ' + fmt(r.effect.value, 2);
    }
    if (r.test === 'One-way ANOVA') {
      return 'F(' + r.df + ') = ' + fmt(r.statistic, 2) + ', ' + fmtP(r.p) + ', η² = ' + fmt(r.effect.value, 3);
    }
    if (r.test.indexOf('Chi-square') !== -1) {
      return 'χ²(' + r.df + ', N = ' + r.detail.N + ') = ' + fmt(r.statistic, 2) + ', ' + fmtP(r.p) + ", V = " + fmt(r.effect.value, 2);
    }
    if (r.test === 'Pearson correlation') {
      return 'r(' + r.df + ') = ' + fmt(r.statistic, 2) + ', ' + fmtP(r.p) + ', r² = ' + fmt(r.effect.value, 3);
    }
    return '';
  }
  function interpretationFor(r, v1, v2) {
    const sig = r.p < 0.05;
    const big = r.effect.tone === 'ok' || r.effect.tone === 'strong';
    if (r.test.indexOf('t-test') !== -1) {
      const a = r.detail.groups[0], b = r.detail.groups[1];
      if (sig && big)  return 'A meaningful difference: ' + a.name + ' (M = ' + fmt(a.mean, 2) + ') vs ' + b.name + ' (M = ' + fmt(b.mean, 2) + '). The effect is ' + r.effect.band + ' (Cohen\'s d = ' + fmt(r.effect.value, 2) + '), and the p-value confirms the gap is unlikely to be chance.';
      if (sig && !big) return 'The difference is statistically significant but the effect size (Cohen\'s d = ' + fmt(r.effect.value, 2) + ', ' + r.effect.band + ') is modest. With a large enough sample, even small differences become detectable; weigh the size before reporting.';
      if (!sig && big) return 'A noticeable gap in means (Cohen\'s d = ' + fmt(r.effect.value, 2) + ', ' + r.effect.band + '), but with this sample the p-value (' + fmtP(r.p) + ') does not clear .05. The pattern may be real and underpowered.';
      return 'No reliable difference between groups (' + fmtP(r.p) + ', d = ' + fmt(r.effect.value, 2) + '). The data are consistent with no effect.';
    }
    if (r.test === 'One-way ANOVA') {
      if (sig && big)  return 'At least one group differs meaningfully on ' + v1.name + ' (η² = ' + fmt(r.effect.value, 3) + ', ' + r.effect.band + '). Run a post-hoc comparison to see which pairs drive the effect.';
      if (sig && !big) return 'Statistically significant overall, but η² (' + fmt(r.effect.value, 3) + ', ' + r.effect.band + ') is modest. With many groups or large n, even small differences reach significance.';
      if (!sig && big) return 'η² is ' + r.effect.band + ' (' + fmt(r.effect.value, 3) + '), but the F-test does not clear .05. May be underpowered.';
      return 'Group means do not differ reliably (' + fmtP(r.p) + ', η² = ' + fmt(r.effect.value, 3) + ').';
    }
    if (r.test.indexOf('Chi-square') !== -1) {
      if (sig && big)  return v1.name + ' and ' + v2.name + ' are not independent. Cramer\'s V (' + fmt(r.effect.value, 2) + ', ' + r.effect.band + ') indicates a real association.';
      if (sig && !big) return 'Statistically significant, but Cramer\'s V (' + fmt(r.effect.value, 2) + ', ' + r.effect.band + ') is modest. The pattern is detectable but practically small.';
      if (!sig && big) return 'Cramer\'s V is ' + r.effect.band + ' (' + fmt(r.effect.value, 2) + '), yet the chi-square test does not clear .05. The cell counts may be too sparse.';
      return v1.name + ' and ' + v2.name + ' appear independent (' + fmtP(r.p) + ', V = ' + fmt(r.effect.value, 2) + ').';
    }
    if (r.test === 'Pearson correlation') {
      const dir = r.statistic > 0 ? 'positive' : 'negative';
      if (sig && big)  return 'A ' + r.effect.band + ' ' + dir + ' linear relationship: r = ' + fmt(r.statistic, 2) + ', explaining ' + fmt(r.effect.value * 100, 1) + '% of the variance.';
      if (sig && !big) return 'Statistically significant but modest correlation (r = ' + fmt(r.statistic, 2) + ', r² = ' + fmt(r.effect.value, 3) + '). With large n, weak correlations become significant; weigh practical size.';
      if (!sig && big) return 'r = ' + fmt(r.statistic, 2) + ' looks ' + r.effect.band + ' but the p-value (' + fmtP(r.p) + ') does not clear .05. May be underpowered.';
      return 'No reliable linear relationship (r = ' + fmt(r.statistic, 2) + ', ' + fmtP(r.p) + ').';
    }
    return '';
  }
  function nextStepFor(r, v1, v2) {
    if (r.p < 0.05) {
      if (r.test === 'One-way ANOVA')        return 'Next: run a post-hoc comparison (Tukey HSD or Games-Howell) to find which groups differ.';
      if (r.test.indexOf('t-test') !== -1)   return 'Next: visualize the group means with error bars and add this finding to your report.';
      if (r.test.indexOf('Chi-square') !== -1) return 'Next: inspect the standardized residuals to identify which cells drive the association.';
      if (r.test === 'Pearson correlation')  return 'Next: inspect a scatterplot to confirm linearity, then consider whether to report the regression line.';
    }
    return 'Next: report this as a null finding only after confirming sample size is adequate. Consider whether the question is best tested another way.';
  }

  function exposeAppState(r, v1, v2) {
    window.RELICHECK_APP_STATE = {
      app_key:  'inferential',
      app_name: 'Inferential Analysis',
      summary:  contextFor(r, v1, v2),
      dataset:  {
        source:     dataset.source || '',
        rowCount:   rowCount,
        fromUpload: window.INFERENTIAL_DATASET_SOURCE === 'uploaded',
      },
      test:       currentTest,
      variables:  [v1.name, v2.name],
      result:     r,
      computed_at: new Date().toISOString(),
    };
  }

  // ====================================================================
  // Helpers (small)
  // ====================================================================
  function setStatus(msg) {
    if (els.status) els.status.textContent = msg || '';
  }
  function error(msg) { return { ok: false, msg: msg }; }
  function fmt(x, dig) {
    if (x == null || !isFinite(x)) return '—';
    if (typeof x !== 'number') return String(x);
    return x.toFixed(dig == null ? 2 : dig);
  }
  function fmtP(p) {
    if (p == null) return 'p = —';
    if (p < 0.001) return 'p < .001';
    if (p < 0.01)  return 'p = ' + p.toFixed(3).replace(/^0/, '');
    return 'p = ' + p.toFixed(2).replace(/^0/, '');
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  // Approximate the two-tailed t critical value at α=0.05 by bisection
  // on tPValue. Cheap; runs once per t-test.
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

  // ====================================================================
  // Boot
  // ====================================================================
  syncPicker();
  updateFieldShape();
  els.var1.addEventListener('change', () => {
    // Refilter var2 to exclude var1's value
    updateFieldShape();
  });
})();
