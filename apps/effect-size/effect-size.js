// ReliCheck Effect Size
// -------------------------------------------------------------------
// Three modes:
//   - from_data     pick variables, compute one effect size only
//   - from_summary  enter means/SDs/ns or 2x2 counts, compute
//   - convert       enter one metric, get all related metrics
//
// Distinct from the Inferential app: this one leads with the SIZE of
// the effect (not the p-value), and adds two things the inferential
// app doesn't:
//   - compute from summary stats (no raw data required)
//   - convert between metrics (d ↔ r ↔ η² ↔ OR) for meta-analysis
//
// All conversions and bands come from Cohen (1988); odds-ratio bands
// follow Chen, Cohen & Chen (2010). Where formulas vary by context,
// the comment notes the assumption.

(function () {
  'use strict';

  // ====================================================================
  // Dataset (for from-data mode)
  // ====================================================================
  let dataset = window.EFFECT_SIZE_DATASET || window.INFERENTIAL_DATASET;
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
  window.EFFECT_SIZE_DATASET_RESOLVED = dataset;
  window.EFFECT_SIZE_DATASET_SOURCE   = datasetSource;

  const allVars  = (dataset && dataset.variables) || [];
  const rowCount = (dataset && dataset.rowCount) || (allVars[0] ? allVars[0].values.length : 0);
  function isMissing(v) { return v === '' || v == null; }
  function num(v)       { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function mean(arr)    { return arr.reduce((s, v) => s + v, 0) / arr.length; }
  function variance(arr){
    if (arr.length < 2) return 0;
    const m = mean(arr);
    return arr.reduce((s, v) => s + (v - m) * (v - m), 0) / (arr.length - 1);
  }
  function sd(arr) { return Math.sqrt(variance(arr)); }
  function hasType(v, t) { return v.types && v.types.indexOf(t) !== -1; }
  function isNumericish(v)  { return hasType(v, 'numeric') || hasType(v, 'likert'); }
  function isCategorical(v) { return hasType(v, 'categorical'); }
  function groupLevels(v) {
    const seen = new Set();
    v.values.forEach(val => { if (!isMissing(val)) seen.add(String(val)); });
    return Array.from(seen);
  }

  // ====================================================================
  // Effect-size bands
  // ====================================================================
  const BANDS = {
    d: function (d) {
      const a = Math.abs(d);
      if (a < 0.20) return { label: 'negligible', tone: 'muted' };
      if (a < 0.50) return { label: 'small',      tone: 'warn'  };
      if (a < 0.80) return { label: 'medium',     tone: 'ok'    };
      return            { label: 'large',         tone: 'strong'};
    },
    eta: function (e) {
      if (e < 0.01) return { label: 'negligible', tone: 'muted' };
      if (e < 0.06) return { label: 'small',      tone: 'warn'  };
      if (e < 0.14) return { label: 'medium',     tone: 'ok'    };
      return            { label: 'large',         tone: 'strong'};
    },
    v: function (v) {
      if (v < 0.10) return { label: 'negligible', tone: 'muted' };
      if (v < 0.30) return { label: 'small',      tone: 'warn'  };
      if (v < 0.50) return { label: 'medium',     tone: 'ok'    };
      return            { label: 'large',         tone: 'strong'};
    },
    r: function (r) {
      const a = Math.abs(r);
      if (a < 0.10) return { label: 'negligible', tone: 'muted' };
      if (a < 0.30) return { label: 'small',      tone: 'warn'  };
      if (a < 0.50) return { label: 'medium',     tone: 'ok'    };
      return            { label: 'large',         tone: 'strong'};
    },
    or: function (or) {
      // Chen, Cohen & Chen (2010) thresholds for OR. The "or its
      // reciprocal" symmetry means the band is on max(OR, 1/OR).
      const x = or >= 1 ? or : 1 / or;
      if (x < 1.21) return { label: 'negligible', tone: 'muted' };
      if (x < 1.86) return { label: 'small',      tone: 'warn'  };
      if (x < 3.00) return { label: 'medium',     tone: 'ok'    };
      return            { label: 'large',         tone: 'strong'};
    },
  };

  // ====================================================================
  // Computations
  // ====================================================================

  // --- Cohen's d from two-group raw data ---
  function dFromData(outcomeVar, groupVar) {
    const groups = new Map();
    for (let i = 0; i < rowCount; i++) {
      const y = num(outcomeVar.values[i]);
      const g = groupVar.values[i];
      if (y == null || isMissing(g)) continue;
      const k = String(g);
      if (!groups.has(k)) groups.set(k, []);
      groups.get(k).push(y);
    }
    const levels = Array.from(groups.keys());
    if (levels.length !== 2) return err('Need exactly 2 groups; this variable has ' + levels.length + ' levels.');
    const a = groups.get(levels[0]), b = groups.get(levels[1]);
    if (a.length < 2 || b.length < 2) return err('Each group needs at least 2 observations.');
    return dFromSummary(mean(a), sd(a), a.length, mean(b), sd(b), b.length, levels);
  }
  // --- Cohen's d from summary stats (uses pooled SD) ---
  function dFromSummary(m1, sd1, n1, m2, sd2, n2, levels) {
    if (n1 < 2 || n2 < 2) return err('Each group needs at least 2 observations.');
    const pooledSd = Math.sqrt(((n1 - 1) * sd1 * sd1 + (n2 - 1) * sd2 * sd2) / (n1 + n2 - 2));
    const d = (m1 - m2) / (pooledSd || 1e-12);
    // Hedges' g (small-sample correction)
    const J = 1 - 3 / (4 * (n1 + n2) - 9);
    const g = d * J;
    // 95% CI on d (approximate, Hedges & Olkin)
    const varD = (n1 + n2) / (n1 * n2) + (d * d) / (2 * (n1 + n2));
    const seD  = Math.sqrt(varD);
    const ciLow  = d - 1.96 * seD;
    const ciHigh = d + 1.96 * seD;
    const b = BANDS.d(d);
    return {
      ok: true,
      kind: 'd',
      name: "Cohen's d",
      value: d,
      band: b.label, tone: b.tone,
      ci95: [ciLow, ciHigh],
      detail: {
        m1: m1, m2: m2, sd1: sd1, sd2: sd2, n1: n1, n2: n2,
        pooledSd: pooledSd, hedgesG: g,
        levels: levels || ['Group 1', 'Group 2'],
      },
    };
  }
  // --- η² from raw data (one-way ANOVA) ---
  function etaFromData(outcomeVar, groupVar) {
    const groups = new Map();
    for (let i = 0; i < rowCount; i++) {
      const y = num(outcomeVar.values[i]);
      const g = groupVar.values[i];
      if (y == null || isMissing(g)) continue;
      const k = String(g);
      if (!groups.has(k)) groups.set(k, []);
      groups.get(k).push(y);
    }
    const levels = Array.from(groups.keys());
    if (levels.length < 2) return err('Need at least 2 groups.');
    const groupData = levels.map(l => groups.get(l));
    const ns = groupData.map(arr => arr.length);
    if (ns.some(n => n < 2)) return err('Each group needs at least 2 observations.');
    const all = groupData.flat();
    const grand = mean(all);
    const ssB = groupData.reduce((s, g) => s + g.length * Math.pow(mean(g) - grand, 2), 0);
    const ssW = groupData.reduce((s, g) => {
      const gm = mean(g);
      return s + g.reduce((acc, x) => acc + (x - gm) * (x - gm), 0);
    }, 0);
    return etaFromSummary(ssB, ssW, levels.length - 1, all.length - levels.length);
  }
  // --- η² from summary (SS_between, SS_within, df_between, df_within) ---
  function etaFromSummary(ssB, ssW, dfB, dfW) {
    if (ssB < 0 || ssW < 0) return err('Sums of squares must be non-negative.');
    const ssT = ssB + ssW;
    if (ssT === 0) return err('No variability in the data; effect size is undefined.');
    const eta2  = ssB / ssT;
    const msW   = ssW / (dfW || 1);
    const omega2 = (ssT + msW) ? (ssB - dfB * msW) / (ssT + msW) : 0;
    const b = BANDS.eta(eta2);
    return {
      ok: true,
      kind: 'eta',
      name: 'η²',
      value: eta2,
      band: b.label, tone: b.tone,
      ci95: null, // not implemented (requires non-central F)
      detail: { eta2: eta2, omega2: omega2, ssB: ssB, ssW: ssW, ssT: ssT, dfB: dfB, dfW: dfW },
    };
  }
  // --- Cramer's V from raw data (any r x c table) ---
  function vFromData(rowVar, colVar) {
    const rowLevels = [], colLevels = [];
    for (let i = 0; i < rowCount; i++) {
      const a = rowVar.values[i], b = colVar.values[i];
      if (isMissing(a) || isMissing(b)) continue;
      const ra = String(a), cb = String(b);
      if (rowLevels.indexOf(ra) === -1) rowLevels.push(ra);
      if (colLevels.indexOf(cb) === -1) colLevels.push(cb);
    }
    rowLevels.sort(); colLevels.sort();
    const r = rowLevels.length, c = colLevels.length;
    if (r < 2 || c < 2) return err('Each variable needs at least 2 levels.');
    const O = Array.from({ length: r }, () => new Array(c).fill(0));
    for (let i = 0; i < rowCount; i++) {
      const a = rowVar.values[i], b = colVar.values[i];
      if (isMissing(a) || isMissing(b)) continue;
      O[rowLevels.indexOf(String(a))][colLevels.indexOf(String(b))]++;
    }
    return vFrom2D(O, rowLevels, colLevels);
  }
  function vFrom2D(O, rowLevels, colLevels) {
    const r = O.length, c = O[0].length;
    const rowT = O.map(row => row.reduce((s, v) => s + v, 0));
    const colT = new Array(c).fill(0);
    O.forEach(row => row.forEach((v, j) => { colT[j] += v; }));
    const N = rowT.reduce((s, v) => s + v, 0);
    if (N === 0) return err('No observations.');
    let chi = 0;
    for (let i = 0; i < r; i++) {
      for (let j = 0; j < c; j++) {
        const E = (rowT[i] * colT[j]) / N;
        if (E > 0) chi += (O[i][j] - E) * (O[i][j] - E) / E;
      }
    }
    const dfMin = Math.min(r - 1, c - 1);
    const V = Math.sqrt(chi / (N * dfMin));
    const b = BANDS.v(V);
    return {
      ok: true,
      kind: 'v',
      name: "Cramer's V",
      value: V,
      band: b.label, tone: b.tone,
      ci95: null,
      detail: { chi: chi, N: N, r: r, c: c,
                rowLevels: rowLevels || rowT.map((_, i) => 'R' + (i + 1)),
                colLevels: colLevels || colT.map((_, j) => 'C' + (j + 1)),
                observed: O },
    };
  }
  // --- Pearson r from raw data ---
  function rFromData(xVar, yVar) {
    const pairs = [];
    for (let i = 0; i < rowCount; i++) {
      const x = num(xVar.values[i]), y = num(yVar.values[i]);
      if (x == null || y == null) continue;
      pairs.push([x, y]);
    }
    if (pairs.length < 3) return err('Need at least 3 paired numeric observations.');
    const n = pairs.length;
    const mx = pairs.reduce((s, p) => s + p[0], 0) / n;
    const my = pairs.reduce((s, p) => s + p[1], 0) / n;
    let cov = 0, vx = 0, vy = 0;
    pairs.forEach(p => {
      const dx = p[0] - mx, dy = p[1] - my;
      cov += dx * dy; vx += dx * dx; vy += dy * dy;
    });
    const r = cov / (Math.sqrt(vx * vy) || 1e-12);
    return rFromSummary(r, n);
  }
  function rFromSummary(r, n) {
    if (Math.abs(r) >= 1) return err('|r| must be less than 1.');
    if (n < 3) return err('Need at least 3 observations.');
    // Fisher-z 95% CI
    const zr = 0.5 * Math.log((1 + r) / (1 - r));
    const se = 1 / Math.sqrt(n - 3);
    const ciLZ = zr - 1.96 * se;
    const ciHZ = zr + 1.96 * se;
    const ciL  = (Math.exp(2 * ciLZ) - 1) / (Math.exp(2 * ciLZ) + 1);
    const ciH  = (Math.exp(2 * ciHZ) - 1) / (Math.exp(2 * ciHZ) + 1);
    const b = BANDS.r(r);
    return {
      ok: true,
      kind: 'r',
      name: 'Pearson r',
      value: r,
      band: b.label, tone: b.tone,
      ci95: [ciL, ciH],
      detail: { n: n, r2: r * r },
    };
  }
  // --- Odds ratio from a 2x2 table (Haldane 0.5 correction for sparse cells) ---
  function orFromCounts(a, b, c, d) {
    if ([a, b, c, d].some(x => x == null || x < 0)) return err('Counts must be non-negative.');
    let aa = a, bb = b, cc = c, dd = d;
    const sparse = (a === 0 || b === 0 || c === 0 || d === 0);
    if (sparse) { aa += 0.5; bb += 0.5; cc += 0.5; dd += 0.5; }
    const OR = (aa * dd) / (bb * cc);
    const logOR = Math.log(OR);
    const se = Math.sqrt(1 / aa + 1 / bb + 1 / cc + 1 / dd);
    const ciL = Math.exp(logOR - 1.96 * se);
    const ciH = Math.exp(logOR + 1.96 * se);
    const band = BANDS.or(OR);
    return {
      ok: true,
      kind: 'or',
      name: 'Odds ratio',
      value: OR,
      band: band.label, tone: band.tone,
      ci95: [ciL, ciH],
      detail: { a: a, b: b, c: c, d: d, sparseCorrected: sparse, logOR: logOR, seLogOR: se },
    };
  }
  // From raw data: pick two categorical 2x2 vars → OR
  function orFromData(rowVar, colVar) {
    const rLevels = groupLevels(rowVar);
    const cLevels = groupLevels(colVar);
    if (rLevels.length !== 2 || cLevels.length !== 2) {
      return err('Odds ratio needs exactly a 2 × 2 table; each variable must have 2 levels.');
    }
    rLevels.sort(); cLevels.sort();
    let a = 0, b = 0, c = 0, d = 0;
    for (let i = 0; i < rowCount; i++) {
      const rv = rowVar.values[i], cv = colVar.values[i];
      if (isMissing(rv) || isMissing(cv)) continue;
      const ri = rLevels.indexOf(String(rv)), ci = cLevels.indexOf(String(cv));
      if (ri === 0 && ci === 0) a++;
      else if (ri === 0 && ci === 1) b++;
      else if (ri === 1 && ci === 0) c++;
      else if (ri === 1 && ci === 1) d++;
    }
    const result = orFromCounts(a, b, c, d);
    if (result.ok) {
      result.detail.rowLabels = rLevels;
      result.detail.colLabels = cLevels;
    }
    return result;
  }

  // ====================================================================
  // Conversions (Convert mode)
  // ====================================================================
  // d → r: r = d / sqrt(d² + 4)  (assumes equal group sizes)
  // r → d: d = 2r / sqrt(1 - r²)
  // d → η²: η² = d² / (d² + 4)   (two-group ANOVA equivalence)
  // η² → d: d = 2 * sqrt(η² / (1 - η²))
  // r → η²: η² = r²
  // η² → r: r = sqrt(η²)
  // d → OR: log(OR) ≈ d * π / √3   (logistic approximation)
  // OR → d: d ≈ log(OR) * √3 / π
  // Conversions accumulate small errors; report to 3 decimals.
  function convertFrom(srcKind, x) {
    let d, r, eta, or;
    switch (srcKind) {
      case 'd':
        d   = x;
        r   = d / Math.sqrt(d * d + 4);
        eta = (d * d) / (d * d + 4);
        or  = Math.exp(d * Math.PI / Math.sqrt(3));
        break;
      case 'r':
        r   = x;
        if (Math.abs(r) >= 1) return err('|r| must be less than 1.');
        d   = 2 * r / Math.sqrt(1 - r * r);
        eta = r * r;
        or  = Math.exp(d * Math.PI / Math.sqrt(3));
        break;
      case 'eta':
        eta = x;
        if (eta < 0 || eta >= 1) return err('η² must be in [0, 1).');
        d   = 2 * Math.sqrt(eta / (1 - eta));
        r   = Math.sqrt(eta);
        or  = Math.exp(d * Math.PI / Math.sqrt(3));
        break;
      case 'or':
        or  = x;
        if (or <= 0) return err('Odds ratio must be positive.');
        d   = Math.log(or) * Math.sqrt(3) / Math.PI;
        r   = d / Math.sqrt(d * d + 4);
        eta = (d * d) / (d * d + 4);
        break;
      default: return err('Unknown source kind: ' + srcKind);
    }
    const bd = BANDS.d(d), br = BANDS.r(r), be = BANDS.eta(eta), bor = BANDS.or(or);
    return {
      ok: true,
      kind: 'convert',
      name: 'Conversion',
      value: x,
      band: BANDS[srcKind](x).label,
      tone: BANDS[srcKind](x).tone,
      detail: {
        srcKind: srcKind,
        d:   { value: d,   band: bd.label,  tone: bd.tone },
        r:   { value: r,   band: br.label,  tone: br.tone },
        eta: { value: eta, band: be.label,  tone: be.tone },
        or:  { value: or,  band: bor.label, tone: bor.tone },
      },
    };
  }

  // ====================================================================
  // UI wiring
  // ====================================================================
  const root = document.querySelector('.es-app');
  if (!root) return;
  const tabs = Array.from(root.querySelectorAll('.es-tab'));
  const panes = Array.from(root.querySelectorAll('.es-input-pane'));
  let currentMode = 'from_data';

  tabs.forEach(t => t.addEventListener('click', () => {
    currentMode = t.getAttribute('data-mode');
    tabs.forEach(tb => {
      const active = tb.getAttribute('data-mode') === currentMode;
      tb.classList.toggle('is-active', active);
      tb.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    panes.forEach(p => {
      p.hidden = p.getAttribute('data-pane') !== currentMode;
    });
  }));

  // Within from_data / from_summary, the "kind" chips switch which
  // sub-form / output is active.
  let currentDataKind = 'd';
  const dataPane = root.querySelector('[data-pane="from_data"]');
  dataPane.querySelectorAll('.es-chip[data-kind]').forEach(chip => {
    if (chip.getAttribute('data-kind') === 'd') chip.classList.add('is-active');
    chip.addEventListener('click', () => {
      currentDataKind = chip.getAttribute('data-kind');
      dataPane.querySelectorAll('.es-chip[data-kind]').forEach(c => {
        c.classList.toggle('is-active', c.getAttribute('data-kind') === currentDataKind);
      });
      updateDataFields();
    });
  });

  let currentSumKind = 'd';
  const sumPane = root.querySelector('[data-pane="from_summary"]');
  sumPane.querySelectorAll('.es-chip[data-kind]').forEach(chip => {
    if (chip.getAttribute('data-kind') === 'd') chip.classList.add('is-active');
    chip.addEventListener('click', () => {
      currentSumKind = chip.getAttribute('data-kind');
      sumPane.querySelectorAll('.es-chip[data-kind]').forEach(c => {
        c.classList.toggle('is-active', c.getAttribute('data-kind') === currentSumKind);
      });
      sumPane.querySelectorAll('.es-sumset').forEach(s => {
        s.hidden = s.getAttribute('data-kind') !== currentSumKind;
      });
    });
  });

  let currentConvSrc = 'd';
  const convPane = root.querySelector('[data-pane="convert"]');
  convPane.querySelectorAll('.es-chip[data-src]').forEach(chip => {
    if (chip.getAttribute('data-src') === 'd') chip.classList.add('is-active');
    chip.addEventListener('click', () => {
      currentConvSrc = chip.getAttribute('data-src');
      convPane.querySelectorAll('.es-chip[data-src]').forEach(c => {
        c.classList.toggle('is-active', c.getAttribute('data-src') === currentConvSrc);
      });
      document.getElementById('esConvSrcLabel').textContent = labelForKind(currentConvSrc);
    });
  });

  // Populate selects in from_data
  function updateDataFields() {
    const v1 = document.getElementById('esDataVar1');
    const v2 = document.getElementById('esDataVar2');
    const lab1 = document.getElementById('esDataVar1Label');
    const lab2 = document.getElementById('esDataVar2Label');
    let pop1, pop2;
    switch (currentDataKind) {
      case 'd':
        lab1.textContent = 'Outcome (numeric)';
        lab2.textContent = 'Grouping (2-level categorical)';
        pop1 = allVars.filter(isNumericish);
        pop2 = allVars.filter(v => isCategorical(v) && groupLevels(v).length === 2);
        break;
      case 'eta':
        lab1.textContent = 'Outcome (numeric)';
        lab2.textContent = 'Grouping (3+ level categorical)';
        pop1 = allVars.filter(isNumericish);
        pop2 = allVars.filter(v => isCategorical(v) && groupLevels(v).length >= 2);
        break;
      case 'v':
        lab1.textContent = 'Row variable (categorical)';
        lab2.textContent = 'Column variable (categorical)';
        pop1 = allVars.filter(isCategorical);
        pop2 = allVars.filter(isCategorical);
        break;
      case 'r':
        lab1.textContent = 'Variable X (numeric)';
        lab2.textContent = 'Variable Y (numeric)';
        pop1 = allVars.filter(isNumericish);
        pop2 = allVars.filter(isNumericish);
        break;
      case 'or':
        lab1.textContent = 'Row variable (2-level)';
        lab2.textContent = 'Column variable (2-level)';
        pop1 = allVars.filter(v => isCategorical(v) && groupLevels(v).length === 2);
        pop2 = allVars.filter(v => isCategorical(v) && groupLevels(v).length === 2);
        break;
    }
    fillSel(v1, pop1);
    fillSel(v2, pop2);
  }
  function fillSel(sel, vars) {
    sel.innerHTML = '';
    if (!vars.length) {
      const opt = document.createElement('option');
      opt.value = ''; opt.textContent = '— no matching variables —';
      sel.appendChild(opt); sel.disabled = true; return;
    }
    sel.disabled = false;
    vars.forEach(v => {
      const opt = document.createElement('option');
      opt.value = v.name; opt.textContent = v.name;
      sel.appendChild(opt);
    });
  }

  // ---- Run handlers ----
  document.getElementById('esDataRun').addEventListener('click', () => {
    if (!allVars.length) return showResult(err('No dataset. Switch to "From summary stats" or "Convert".'));
    const v1 = allVars.find(v => v.name === document.getElementById('esDataVar1').value);
    const v2 = allVars.find(v => v.name === document.getElementById('esDataVar2').value);
    if (!v1 || !v2) return showResult(err('Pick two variables first.'));
    let r;
    switch (currentDataKind) {
      case 'd':   r = dFromData(v1, v2); break;
      case 'eta': r = etaFromData(v1, v2); break;
      case 'v':   r = vFromData(v1, v2); break;
      case 'r':   r = rFromData(v1, v2); break;
      case 'or':  r = orFromData(v1, v2); break;
    }
    showResult(r);
  });

  document.getElementById('esSumRun').addEventListener('click', () => {
    let r;
    switch (currentSumKind) {
      case 'd':
        r = dFromSummary(
          num(document.getElementById('esSumM1').value),
          num(document.getElementById('esSumSd1').value),
          parseInt(document.getElementById('esSumN1').value, 10),
          num(document.getElementById('esSumM2').value),
          num(document.getElementById('esSumSd2').value),
          parseInt(document.getElementById('esSumN2').value, 10)
        );
        if (r.ok && (r.detail.m1 == null || r.detail.m2 == null || r.detail.sd1 == null || r.detail.sd2 == null)) {
          r = err('Fill in all four summary stats and both group sizes.');
        }
        break;
      case 'eta':
        r = etaFromSummary(
          num(document.getElementById('esEtaSsB').value),
          num(document.getElementById('esEtaSsW').value),
          parseInt(document.getElementById('esEtaDfB').value, 10),
          parseInt(document.getElementById('esEtaDfW').value, 10)
        );
        break;
      case 'v': {
        const a = parseInt(document.getElementById('esVa').value, 10);
        const b = parseInt(document.getElementById('esVb').value, 10);
        const c = parseInt(document.getElementById('esVc').value, 10);
        const d = parseInt(document.getElementById('esVd').value, 10);
        if ([a, b, c, d].some(x => isNaN(x) || x < 0)) { r = err('Enter four non-negative counts.'); break; }
        r = vFrom2D([[a, b], [c, d]]);
        break;
      }
      case 'r':
        r = rFromSummary(
          num(document.getElementById('esSumR').value),
          parseInt(document.getElementById('esSumNr').value, 10)
        );
        break;
      case 'or': {
        const a = parseInt(document.getElementById('esORa').value, 10);
        const b = parseInt(document.getElementById('esORb').value, 10);
        const c = parseInt(document.getElementById('esORc').value, 10);
        const d = parseInt(document.getElementById('esORd').value, 10);
        if ([a, b, c, d].some(x => isNaN(x) || x < 0)) { r = err('Enter four non-negative counts.'); break; }
        r = orFromCounts(a, b, c, d);
        break;
      }
    }
    showResult(r);
  });

  document.getElementById('esConvRun').addEventListener('click', () => {
    const x = num(document.getElementById('esConvValue').value);
    if (x == null) return showResult(err('Enter a numeric value to convert.'));
    showResult(convertFrom(currentConvSrc, x));
  });

  // ---- Result rendering ----
  function showResult(r) {
    const empty  = document.getElementById('esResultsEmpty');
    const shown  = document.getElementById('esResultsShown');
    if (!r) return;
    if (!r.ok) {
      // Show error in status; keep results card empty
      const status = document.querySelectorAll('.es-status');
      status.forEach(s => { if (!s.closest('[hidden]')) s.textContent = r.msg; });
      return;
    }
    empty.hidden = true; shown.hidden = false;
    document.querySelectorAll('.es-status').forEach(s => { s.textContent = ''; });

    document.getElementById('esResultsKindLabel').textContent = r.name;
    if (r.kind === 'convert') {
      // Custom render for the convert mode: show all four metrics.
      document.getElementById('esSizeName').textContent  = labelForKind(r.detail.srcKind);
      document.getElementById('esSizeValue').textContent = fmt(r.value, 3);
      document.getElementById('esBand').textContent = r.band;
      document.getElementById('esBand').setAttribute('data-tone', r.tone);
      document.getElementById('esCi').textContent = '';
      renderConvertDetail(r);
      document.getElementById('esInterp').textContent = interpretConvert(r);
    } else {
      document.getElementById('esSizeName').textContent  = nameForKind(r.kind);
      document.getElementById('esSizeValue').textContent = fmt(r.value, 3);
      document.getElementById('esBand').textContent = r.band;
      document.getElementById('esBand').setAttribute('data-tone', r.tone);
      if (r.ci95) {
        document.getElementById('esCi').textContent = '95% CI [' + fmt(r.ci95[0], 2) + ', ' + fmt(r.ci95[1], 2) + ']';
      } else {
        document.getElementById('esCi').textContent = '';
      }
      renderDetail(r);
      document.getElementById('esInterp').textContent = interpretFor(r);
    }
    exposeAppState(r);
  }

  function renderDetail(r) {
    const host = document.getElementById('esDetail');
    host.innerHTML = '';
    if (r.kind === 'd') {
      const d = r.detail;
      host.innerHTML =
        '<table class="es-table">' +
          '<thead><tr><th>Group</th><th>n</th><th>Mean</th><th>SD</th></tr></thead>' +
          '<tbody>' +
            '<tr><td>' + esc(d.levels[0]) + '</td><td>' + d.n1 + '</td><td>' + fmt(d.m1, 2) + '</td><td>' + fmt(d.sd1, 2) + '</td></tr>' +
            '<tr><td>' + esc(d.levels[1]) + '</td><td>' + d.n2 + '</td><td>' + fmt(d.m2, 2) + '</td><td>' + fmt(d.sd2, 2) + '</td></tr>' +
          '</tbody>' +
        '</table>' +
        '<p class="es-detail-note">Pooled SD = <strong>' + fmt(d.pooledSd, 2) + '</strong>  ·  Hedges\' g (small-sample correction) = <strong>' + fmt(d.hedgesG, 3) + '</strong></p>';
    } else if (r.kind === 'eta') {
      const d = r.detail;
      host.innerHTML =
        '<p class="es-detail-note">η² = ' + fmt(d.eta2, 3) + '  ·  ω² = <strong>' + fmt(d.omega2, 3) + '</strong> (less biased than η²)</p>' +
        '<p class="es-detail-note">SS<sub>between</sub> = ' + fmt(d.ssB, 2) + '  ·  SS<sub>within</sub> = ' + fmt(d.ssW, 2) + '  ·  SS<sub>total</sub> = ' + fmt(d.ssT, 2) + '</p>';
    } else if (r.kind === 'v') {
      const d = r.detail;
      let html = '<table class="es-table es-table-contingency"><thead><tr><th></th>';
      d.colLevels.forEach(c => html += '<th>' + esc(c) + '</th>');
      html += '</tr></thead><tbody>';
      d.rowLevels.forEach((row, i) => {
        html += '<tr><th class="rowhead">' + esc(row) + '</th>';
        d.colLevels.forEach((col, j) => {
          html += '<td>' + d.observed[i][j] + '</td>';
        });
        html += '</tr>';
      });
      html += '</tbody></table>';
      html += '<p class="es-detail-note">χ² = ' + fmt(d.chi, 2) + '  ·  N = ' + d.N + '  ·  ' + d.r + ' × ' + d.c + ' table</p>';
      host.innerHTML = html;
    } else if (r.kind === 'r') {
      const d = r.detail;
      host.innerHTML =
        '<p class="es-detail-note"><strong>' + d.n + '</strong> paired observations  ·  r² = <strong>' + fmt(d.r2, 3) + '</strong> (' + fmt(d.r2 * 100, 1) + '% shared variance)</p>';
    } else if (r.kind === 'or') {
      const d = r.detail;
      let html = '<table class="es-table es-table-contingency"><thead><tr><th></th><th>Outcome +</th><th>Outcome −</th></tr></thead><tbody>';
      const rowL = d.rowLabels || ['Exposed', 'Not exposed'];
      html += '<tr><th class="rowhead">' + esc(rowL[0]) + '</th><td>' + d.a + '</td><td>' + d.b + '</td></tr>';
      html += '<tr><th class="rowhead">' + esc(rowL[1]) + '</th><td>' + d.c + '</td><td>' + d.d + '</td></tr>';
      html += '</tbody></table>';
      html += '<p class="es-detail-note">log(OR) = ' + fmt(d.logOR, 3) + '  ·  SE(log OR) = ' + fmt(d.seLogOR, 3);
      if (d.sparseCorrected) html += '  ·  <strong>Haldane 0.5 correction applied</strong> (sparse cell)';
      html += '</p>';
      host.innerHTML = html;
    }
  }
  function renderConvertDetail(r) {
    const host = document.getElementById('esDetail');
    const d = r.detail;
    host.innerHTML =
      '<table class="es-table es-table-convert">' +
        '<thead><tr><th>Metric</th><th>Value</th><th>Band</th></tr></thead>' +
        '<tbody>' +
          metricRow('Cohen\'s d', d.d) +
          metricRow('Pearson r',  d.r) +
          metricRow('η²',         d.eta) +
          metricRow('Odds ratio', d.or) +
        '</tbody>' +
      '</table>' +
      '<p class="es-detail-note">Conversions use Cohen (1988) and Chen, Cohen &amp; Chen (2010). The d↔r↔η² relationships assume equal group sizes and a normal outcome; d↔OR uses the logistic approximation (log OR ≈ d·π/√3).</p>';
  }
  function metricRow(label, m) {
    return '<tr><td>' + esc(label) + '</td><td><strong>' + fmt(m.value, 3) + '</strong></td><td><span class="es-band-small" data-tone="' + esc(m.tone) + '">' + esc(m.band) + '</span></td></tr>';
  }

  function interpretFor(r) {
    switch (r.kind) {
      case 'd':
        if (r.band === 'negligible') return 'The two groups are essentially the same on this outcome.';
        if (r.band === 'small')      return 'A small but real difference between the groups. Visible only with adequate sample size.';
        if (r.band === 'medium')     return 'A moderate difference between the groups. Visible without statistical tools.';
        return 'A large difference between the groups. Practically meaningful in almost any context.';
      case 'eta':
        if (r.band === 'negligible') return 'Group membership explains almost none of the variance in the outcome.';
        if (r.band === 'small')      return 'Group membership explains ' + fmt(r.value * 100, 1) + '% of the variance. Real but modest.';
        if (r.band === 'medium')     return 'Group membership explains ' + fmt(r.value * 100, 1) + '% of the variance. Meaningful association.';
        return 'Group membership explains ' + fmt(r.value * 100, 1) + '% of the variance. A strong association.';
      case 'v':
        if (r.band === 'negligible') return 'The two categorical variables are effectively independent.';
        if (r.band === 'small')      return 'A weak but real association. Detectable in a chi-square test with adequate sample size.';
        if (r.band === 'medium')     return 'A moderate association between the variables.';
        return 'A strong association between the categorical variables.';
      case 'r':
        if (r.band === 'negligible') return 'No meaningful linear relationship.';
        if (r.band === 'small')      return 'A weak linear relationship: ' + fmt(r.detail.r2 * 100, 1) + '% shared variance.';
        if (r.band === 'medium')     return 'A moderate linear relationship: ' + fmt(r.detail.r2 * 100, 1) + '% shared variance.';
        return 'A strong linear relationship: ' + fmt(r.detail.r2 * 100, 1) + '% shared variance.';
      case 'or':
        const direction = r.value >= 1 ? 'more' : 'less';
        const x = r.value >= 1 ? r.value : 1 / r.value;
        if (r.band === 'negligible') return 'Odds in the two groups are essentially equal (OR ≈ 1).';
        return 'The exposed group has ' + fmt(x, 2) + '× the odds of the outcome ' + (r.value >= 1 ? '' : '(inverse direction)') + '. A ' + r.band + ' association.';
    }
    return '';
  }
  function interpretConvert(r) {
    const lookup = { d: "Cohen's d", r: "Pearson r", eta: "η²", or: "odds ratio" };
    return 'You entered a ' + r.band + ' ' + lookup[r.detail.srcKind] + ' of ' + fmt(r.value, 3) + '. The equivalents in the other three metrics are above.';
  }

  // ====================================================================
  // App state
  // ====================================================================
  function exposeAppState(r) {
    window.RELICHECK_APP_STATE = {
      app_key:  'effect_size',
      app_name: 'Effect Size',
      summary:  r.name + ' = ' + fmt(r.value, 3) + (r.band ? ' (' + r.band + ')' : ''),
      mode:     currentMode,
      result:   r,
      computed_at: new Date().toISOString(),
    };
  }

  // ====================================================================
  // Helpers
  // ====================================================================
  function err(msg) { return { ok: false, msg: msg }; }
  function nameForKind(k) {
    return { d: "Cohen's d", eta: 'η²', v: "Cramer's V", r: 'r', or: 'OR' }[k] || k;
  }
  function labelForKind(k) {
    return { d: "Cohen's d", eta: 'η²', v: "Cramer's V", r: 'Pearson r', or: 'Odds ratio' }[k] || k;
  }
  function fmt(x, dig) {
    if (x == null || !isFinite(x)) return '—';
    return Number(x).toFixed(dig == null ? 2 : dig);
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  // ====================================================================
  // Boot
  // ====================================================================
  updateDataFields();
  document.getElementById('esConvSrcLabel').textContent = labelForKind('d');
})();
