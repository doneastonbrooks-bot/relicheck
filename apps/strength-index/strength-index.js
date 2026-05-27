// ReliCheck Strength Index — composite instrument-quality score.
// ===================================================================
// CANONICAL PSYCHOMETRICS ENGINE — single source of truth.
//
// This file contains the canonical psychometrics engine for the entire
// ReliCheck product. The math exposed on window.RSSI_MATH is the single
// source of truth for α, item-rest, item-total, average inter-item r,
// α-if-deleted, and the reliability and item-quality narratives.
//
// Both the standalone RSSI analyzer at apps/rssi/ and the in-studio
// Strength Index mount consume from this engine. Any future surface
// that performs these calculations must also consume from here, not
// implement its own copy. Two surfaces producing different numbers for
// the same data is a product bug.
//
// The math is exposed unconditionally on script load. The dataset-
// presence gate runs after the math exposure, so this file behaves
// correctly on pages that include it for the math but do not render the
// strength-index UI (e.g., rssi-upload.php).
//
// Consumers should load this file with `defer` to ensure the global is
// populated before their own scripts run.
// ===================================================================
//
// Reads window.STRENGTH_DATASET (set inline by the mounting page) and
// computes five components:
//   - Reliability        Cronbach's α on Likert/Scale items
//   - Validity           Average inter-item correlation among Likert items
//   - Response quality   Inverse of straight-lining rate
//   - Completion         Share of rows with no missing values
//   - Coverage           Minimum-subgroup size against a k=10 anonymity floor
// Each component is normalized to 0-100. The headline Strength score is
// an equal-weight mean. The interpretation paragraph is generated from
// the lowest-scoring component (the one to look at next).
//
// Dataset shape this consumes:
//   {
//     source: 'Workplace Equity Survey, 2026',
//     variables: [
//       { name: 'respondent_id',    types: ['id'],         values: ['001', '002', ...] },
//       { name: 'role_score',       types: ['likert'],     values: [4, 3, 5, ...] },
//       { name: 'gender',           types: ['categorical'],values: ['Female', ...] },
//       ...
//     ],
//     rowCount: 8
//   }
// All values arrive as strings or numbers; numeric helpers coerce.

(function () {
  'use strict';

  // ---------- Resolve the dataset ----------
  // Priority: a dataset uploaded via Evidence Intake (stored in
  // localStorage by evidence-intake.js, scoped by project id) beats
  // the inline sample. Per [[relicheck-reports-model]] the storage
  // key is relicheck.dataset.<project_id>.
  let dataset = window.STRENGTH_DATASET;
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
    console.warn('Could not read dataset from localStorage:', e);
  }
  window.STRENGTH_DATASET_RESOLVED = dataset;
  window.STRENGTH_DATASET_SOURCE   = datasetSource;

  // Note: dataset-presence check and variable groups are deferred until
  // after window.RSSI_MATH is exposed, so the standalone RSSI
  // surface (which loads this file purely to consume the math) gets the
  // math even when no STRENGTH_DATASET is set on the page.

  // ---------- Helpers ----------
  function num(v) { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function isMissing(v) { return v === '' || v == null; }
  function mean(arr)  { return arr.reduce((s, v) => s + v, 0) / arr.length; }
  function variance(arr) {
    if (arr.length < 2) return 0;
    const m = mean(arr);
    return arr.reduce((s, v) => s + (v - m) * (v - m), 0) / (arr.length - 1);
  }
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
  function clamp(x, lo, hi) { return Math.max(lo, Math.min(hi, x)); }
  function round(x) { return Math.round(x); }

  // ---------- Factor-loading helpers (used by McDonald's omega) ----------
  // Build the k-by-k correlation matrix for k item columns.
  function correlationMatrix(cols) {
    const k = cols.length;
    const R = [];
    for (let i = 0; i < k; i++) {
      R.push([]);
      for (let j = 0; j < k; j++) {
        R[i].push(i === j ? 1 : pearson(cols[i], cols[j]));
      }
    }
    return R;
  }
  // Power iteration on a symmetric matrix. Returns the first eigenvector +
  // eigenvalue. Used as a proxy for single-factor loadings; this is the
  // common simplification when no factor-analysis library is available.
  // For a true psychometric report, swap to principal-axis factoring with
  // iterated communality estimates.
  function firstEigen(matrix) {
    const n = matrix.length;
    let v = new Array(n).fill(1 / Math.sqrt(n));
    for (let iter = 0; iter < 80; iter++) {
      const w = new Array(n).fill(0);
      for (let i = 0; i < n; i++) {
        for (let j = 0; j < n; j++) w[i] += matrix[i][j] * v[j];
      }
      const norm = Math.sqrt(w.reduce((s, x) => s + x * x, 0)) || 1;
      v = w.map(x => x / norm);
    }
    let lambda = 0;
    for (let i = 0; i < n; i++) {
      for (let j = 0; j < n; j++) lambda += v[i] * matrix[i][j] * v[j];
    }
    return { vector: v, value: lambda };
  }
  // McDonald's ω from the correlation matrix of standardized items.
  // Loadings are taken from the first principal component scaled by sqrt(λ).
  // ω = (Σλ)² / [(Σλ)² + Σ(1 - λ²)] for standardized items.
  function mcdonaldOmega(cols) {
    const k = cols.length;
    if (k < 2) return null;
    const R = correlationMatrix(cols);
    const { vector, value } = firstEigen(R);
    const sqrtL = Math.sqrt(Math.max(value, 0));
    const loadings = vector.map(v => Math.abs(v) * sqrtL);
    const sumL   = loadings.reduce((a, b) => a + b, 0);
    const sumLsq = loadings.reduce((a, b) => a + b * b, 0);
    const uniqueVar = k - sumLsq;  // standardized items have variance 1
    const denom = sumL * sumL + uniqueVar;
    return denom === 0 ? null : (sumL * sumL) / denom;
  }

  // ---------- Statistical moments (used by Item Quality) ----------
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
    return (sum4 / arr.length) - 3; // excess kurtosis
  }

  // ---------- Matrix inverse (Gauss-Jordan) + KMO ----------
  function matrixInverse(M) {
    const n = M.length;
    const A = M.map((row, i) => {
      const r = row.slice();
      for (let j = 0; j < n; j++) r.push(i === j ? 1 : 0);
      return r;
    });
    for (let i = 0; i < n; i++) {
      let pivot = i;
      for (let k = i + 1; k < n; k++) {
        if (Math.abs(A[k][i]) > Math.abs(A[pivot][i])) pivot = k;
      }
      if (pivot !== i) { const t = A[i]; A[i] = A[pivot]; A[pivot] = t; }
      if (Math.abs(A[i][i]) < 1e-10) return null;
      for (let k = 0; k < n; k++) {
        if (k === i) continue;
        const f = A[k][i] / A[i][i];
        for (let j = 0; j < 2 * n; j++) A[k][j] -= f * A[i][j];
      }
    }
    return A.map((row, i) => row.slice(n).map(v => v / A[i][i]));
  }

  // KMO from the correlation matrix. Returns null if the matrix is singular.
  // KMO = Σ r² / (Σ r² + Σ p²) over off-diagonal pairs, where p is the
  // partial correlation. Anti-image (partial) correlation is derived from
  // the inverse of the correlation matrix.
  function kmoIndex(cols) {
    const k = cols.length;
    if (k < 2) return null;
    const R = correlationMatrix(cols);
    const Ri = matrixInverse(R);
    if (!Ri) return null;
    let sumR2 = 0, sumP2 = 0;
    for (let i = 0; i < k; i++) {
      for (let j = i + 1; j < k; j++) {
        const r = R[i][j];
        const denom = Math.sqrt(Math.abs(Ri[i][i] * Ri[j][j]));
        const p = denom === 0 ? 0 : -Ri[i][j] / denom;
        sumR2 += r * r;
        sumP2 += p * p;
      }
    }
    return (sumR2 + sumP2) === 0 ? null : sumR2 / (sumR2 + sumP2);
  }

  // First-factor loadings from PC on the correlation matrix.
  function firstFactorLoadings(cols) {
    if (cols.length < 2) return null;
    const R = correlationMatrix(cols);
    const { vector, value } = firstEigen(R);
    const sqrtL = Math.sqrt(Math.max(value, 0));
    return vector.map(v => v * sqrtL); // signed loadings
  }

  // ====================================================================
  // CANONICAL MATH — exposed on window.RSSI_MATH (see top-of-file
  // header for the product-wide rule). Functions here are consumed by
  // computeReliability below in this same file and by the standalone
  // RSSI surface (apps/rssi/rssi-reliability.js, rssi-analyses.js).
  //
  // Surface:
  //   completeCases, cronbachAlpha, itemTotal, bandForAlpha,
  //   avgInterItemR, itemRestCorrelations,
  //   computeReliabilityStatusNarrative, computeItemQuality
  // ====================================================================

  // From rssi-reliability.js. Items arrive as arrays-of-numbers-or-null
  // (no upstream complete-case filtering); this returns the row-aligned
  // 2D matrix of complete rows.
  function absorbedCompleteCases(items) {
    if (!items.length) return [];
    const n = items[0].length;
    const matrix = [];
    for (let i = 0; i < n; i++) {
      let ok = true;
      for (let j = 0; j < items.length; j++) {
        if (items[j][i] == null || isNaN(items[j][i])) { ok = false; break; }
      }
      if (ok) {
        const row = new Array(items.length);
        for (let j = 0; j < items.length; j++) row[j] = items[j][i];
        matrix.push(row);
      }
    }
    return matrix;
  }

  // From rssi-reliability.js. Null-aware Cronbach α. Returns null when
  // k < 2 or fewer than 5 complete cases. computeReliability's outer
  // guard now matches the 5-row floor so the two paths agree.
  function absorbedCronbachAlpha(items) {
    const k = items.length;
    if (k < 2) return null;
    const matrix = absorbedCompleteCases(items);
    if (matrix.length < 5) return null;
    const itemVars = [];
    for (let j = 0; j < k; j++) {
      const col = matrix.map(function (r) { return r[j]; });
      itemVars.push(variance(col));
    }
    const sums = matrix.map(function (r) { return r.reduce(function (a, b) { return a + b; }, 0); });
    const totalVar = variance(sums);
    if (totalVar === 0) return 0;
    const itemVarSum = itemVars.reduce(function (a, b) { return a + b; }, 0);
    return (k / (k - 1)) * (1 - itemVarSum / totalVar);
  }

  // From rssi-reliability.js. Corrected item-rest correlation: item[idx]
  // against the sum of all OTHER items, on complete cases.
  function absorbedItemTotal(items, idx) {
    if (items.length < 2) return null;
    const matrix = absorbedCompleteCases(items);
    if (matrix.length < 5) return null;
    const itemVals = matrix.map(function (r) { return r[idx]; });
    const restSums = matrix.map(function (r) {
      let s = 0;
      for (let j = 0; j < r.length; j++) if (j !== idx) s += r[j];
      return s;
    });
    return pearson(itemVals, restSums);
  }

  // Canonical α band per spec §4.1. Returns the user-facing label
  // (consumed by the standalone analyzer banner) and the point
  // allocation (consumed by computeReliability scoring math). The
  // `class` field maps to the existing banner CSS (good/warn/bad/neutral).
  function bandForAlpha(a) {
    if (a == null)       return { label: '—',          class: 'neutral', points: 0 };
    if (a >= 0.90)       return { label: 'Excellent',  class: 'good',    points: 8 };
    if (a >= 0.80)       return { label: 'Strong',     class: 'good',    points: 7 };
    if (a >= 0.70)       return { label: 'Acceptable', class: 'warn',    points: 5 };
    if (a >= 0.60)       return { label: 'Marginal',   class: 'warn',    points: 3 };
    return                      { label: 'Inadequate', class: 'bad',     points: 0 };
  }

  // From rssi-analyses.js. Average inter-item correlation on complete
  // cases. Equivalent to the loop already in computeReliability.
  function absorbedAvgInterItemR(itemArrays) {
    const matrix = absorbedCompleteCases(itemArrays);
    const k = itemArrays.length;
    if (k < 2 || !matrix.length) return null;
    const validCols = itemArrays.map(function (_, j) {
      return matrix.map(function (r) { return r[j]; });
    });
    let sum = 0, pairs = 0;
    for (let i = 0; i < k; i++) {
      for (let j = i + 1; j < k; j++) {
        sum += pearson(validCols[i], validCols[j]);
        pairs++;
      }
    }
    return pairs ? sum / pairs : 0;
  }

  // From rssi-analyses.js. Item-rest correlations for every item.
  function absorbedItemRestCorrelations(itemArrays) {
    const matrix = absorbedCompleteCases(itemArrays);
    const k = itemArrays.length;
    if (k < 2 || matrix.length < 5) return itemArrays.map(function () { return null; });
    const validCols = itemArrays.map(function (_, j) {
      return matrix.map(function (r) { return r[j]; });
    });
    return validCols.map(function (item, i) {
      const rest = matrix.map(function (_, idx) {
        let s = 0;
        for (let j = 0; j < k; j++) if (j !== i) s += validCols[j][idx];
        return s;
      });
      return pearson(item, rest);
    });
  }

  // Narrative reliability-status heuristic over α + avg inter-item r +
  // item-rest correlations. Produces the Ready / Use-with-Caution /
  // Mixed / Strong status the standalone RSSI surface renders for the
  // reliability lens. NOTE: this is NOT the canonical §4A Validity
  // sub-scoring (which is new construction requiring HTMT + criterion
  // validity, out of scope for this conversation). The name was
  // updated from computeValidityNarrative to reflect what the heuristic
  // actually measures.
  function computeReliabilityStatusNarrative(likertItems) {
    if (likertItems.length < 2) {
      return { error: 'Need at least 2 Likert items.' };
    }
    const itemArrays = likertItems.map(function (it) { return it.values.map(num); });
    const matrix = absorbedCompleteCases(itemArrays);
    if (matrix.length < 5) {
      return { error: 'Need at least 5 complete responses across all items.' };
    }
    const alpha   = absorbedCronbachAlpha(itemArrays);
    const avgR    = absorbedAvgInterItemR(itemArrays);
    const itemRest = absorbedItemRestCorrelations(itemArrays);
    const k = likertItems.length;
    const n = matrix.length;
    const rows = likertItems.map(function (it, i) {
      const r = itemRest[i];
      let flag = 'ok';
      if (r != null && r < 0)         flag = 'neg';
      else if (r != null && r < 0.30) flag = 'weak';
      return {
        name:     it.name,
        label:    it.label || it.name,
        n:        n,
        itemRest: r,
        flag:     flag,
      };
    });
    const negCount  = rows.filter(function (r) { return r.flag === 'neg'; }).length;
    const weakCount = rows.filter(function (r) { return r.flag === 'weak'; }).length;
    let status, statusTone, judgment, decision, nextStep;
    if (negCount >= 3 || (alpha != null && alpha < 0.60)) {
      status = 'Not Ready'; statusTone = 'alert';
      judgment = 'Significant validity concerns. The instrument is not yet ready to support strong claims, publication, or high-stakes decisions.';
      decision = 'Do not use for decisions yet. The instrument needs scoring corrections and item review before it can be trusted.';
      nextStep = 'Confirm reverse-coding on every _R item, rerun reliability, and review whether the instrument is one scale or several subscales.';
    } else if (negCount > 0 || weakCount >= Math.ceil(k * 0.2) || (avgR != null && avgR < 0.20) || (alpha != null && alpha < 0.70)) {
      status = 'Use with Caution'; statusTone = 'caution';
      judgment = 'Mixed evidence. The instrument has acceptable internal consistency, but item-level evidence suggests construct-alignment problems.';
      decision = 'Appropriate for exploratory analysis or internal review. Do not use for strong claims, publication, or high-stakes decisions until flagged items are reviewed.';
      nextStep = 'Confirm whether all _R items were reverse-scored. Then rerun reliability and factor structure checks. Consider analyzing subscales separately rather than one total score.';
    } else if ((avgR != null && avgR < 0.30) || weakCount > 0) {
      status = 'Mixed Evidence'; statusTone = 'warn';
      judgment = 'Validity evidence is mixed. The instrument is usable for exploratory analysis but should be strengthened before publication.';
      decision = 'Acceptable for internal use and exploratory analysis. Resolve the flagged items before publication or external reporting.';
      nextStep = 'Inspect the weak-item-rest items, consider revising or dropping them, and report by subscale where possible.';
    } else {
      status = 'Strong'; statusTone = 'strong';
      judgment = 'Validity evidence is strong. Items converge on the intended construct and the scale supports confident reporting.';
      decision = 'Ready to use. Standard caveats around content validity (expert wording review) still apply.';
      nextStep = 'No statistical correction needed. Confirm content and face validity with a subject-matter expert before publication.';
    }
    return {
      alpha: alpha, avgR: avgR, n: n, k: k,
      negCount: negCount, weakCount: weakCount,
      rows: rows,
      status: status, statusTone: statusTone,
      judgment: judgment, decision: decision, nextStep: nextStep,
    };
  }

  // Canonical item-quality function. Merges the scoring math (points +
  // flags, formerly the IIFE-local computeItemQuality) with the
  // narrative (rows + ok/warn/alert/judgment/status, formerly
  // computeItemQualityNarrative) into a single source of truth.
  //
  // Per-item flag-threshold semantics adopt computeItemQuality across
  // three edge cases where the two old functions diverged:
  //   1) Constant item (range = 0): flag low variance (range coerced
  //      to 1 so the 15%-of-range test passes for sd = 0).
  //   2) Missing rate at exactly 20%: flag (`>=`, not `>`).
  //   3) Item with < 2 valid responses: short-circuit. The item bumps
  //      the highMiss penalty bucket but is not added to the flat
  //      flags list; the narrative row reports the missing rate so it
  //      still surfaces in the per-item table.
  //
  // Return shape:
  //   - points         : raw points out of 20 (scoring math consumer)
  //   - flags          : flat list of "<name> (<reason>)" strings —
  //                      matches old computeItemQuality.flaggedItems
  //                      exactly (scoring math + render note consumer)
  //   - narrative      : structured object — rows[] + ok/warn/alert
  //                      counts + judgment + status + statusTone
  //                      (standalone analyzer renderer)
  //   - narrative_text : one-line plain-English summary suitable for
  //                      the strength-index card and report copy
  function canonicalItemQuality(likertItems) {
    if (likertItems.length < 1) return { error: 'Need at least 1 Likert item.' };
    const rows = likertItems.map(function (v) {
      const all  = v.values;
      const nums = all.map(num).filter(function (x) { return x != null; });
      const missing = all.length - nums.length;
      const missRate = all.length ? missing / all.length : 0;

      // Short-circuit: < 2 valid responses. Item bumps highMiss penalty
      // but is excluded from the flat flags list (computeItemQuality
      // semantics).
      if (nums.length < 2) {
        return {
          name: v.name, label: v.label || v.name, n: nums.length,
          mean: null, sd: null, missing: missing,
          ceil: 0, floor: 0, skew: 0, kurt: 0,
          flags: [Math.round(missRate * 100) + '% missing'],
          tone: 'warn',
          _shortCircuit: true,
        };
      }

      const m   = nums.reduce(function (s, x) { return s + x; }, 0) / nums.length;
      const sdv = Math.sqrt(variance(nums));
      const lo  = Math.min.apply(null, nums);
      const hi  = Math.max.apply(null, nums);
      const range = (hi - lo) || 1; // coerce 0 → 1 so constant items flag low variance
      const ceil  = nums.filter(function (x) { return x === hi; }).length / nums.length;
      const floor = nums.filter(function (x) { return x === lo; }).length / nums.length;
      const lowVar = sdv < 0.15 * range;
      let skew = 0, kurt = 0;
      if (nums.length >= 3 && sdv > 0) {
        skew = nums.reduce(function (a, x) { return a + Math.pow((x - m) / sdv, 3); }, 0) / nums.length;
      }
      if (nums.length >= 4 && sdv > 0) {
        kurt = nums.reduce(function (a, x) { return a + Math.pow((x - m) / sdv, 4); }, 0) / nums.length - 3;
      }
      const itemFlags = [];
      if (ceil  >= 0.70)        itemFlags.push('ceiling');
      if (floor >= 0.70)        itemFlags.push('floor');
      if (lowVar)               itemFlags.push('low variance');
      if (Math.abs(skew) > 2)   itemFlags.push('extreme skew');
      if (Math.abs(kurt) > 5)   itemFlags.push('extreme kurtosis');
      if (missRate >= 0.20)     itemFlags.push(Math.round(missRate * 100) + '% missing');
      let tone = 'ok';
      if (itemFlags.length >= 3) tone = 'alert';
      else if (itemFlags.length) tone = 'warn';
      return {
        name: v.name, label: v.label || v.name, n: nums.length,
        mean: m, sd: sdv, missing: missing,
        ceil: ceil, floor: floor, skew: skew, kurt: kurt,
        flags: itemFlags, tone: tone,
      };
    });

    // Penalty buckets + flat flags. Mirror computeItemQuality exactly:
    // short-circuited rows bump highMiss but skip the flags push.
    let ceilingFloor = 0, lowVarN = 0, highSkew = 0, highKurt = 0, highMiss = 0;
    const flags = [];
    rows.forEach(function (r) {
      if (r._shortCircuit) { highMiss++; return; }
      if (r.flags.indexOf('ceiling') !== -1 || r.flags.indexOf('floor') !== -1) {
        ceilingFloor++; flags.push(r.name + ' (ceiling/floor)');
      }
      if (r.flags.indexOf('low variance')     !== -1) { lowVarN++;  flags.push(r.name + ' (low variance)'); }
      if (r.flags.indexOf('extreme skew')     !== -1) { highSkew++; flags.push(r.name + ' (skewed)'); }
      if (r.flags.indexOf('extreme kurtosis') !== -1) { highKurt++; flags.push(r.name + ' (kurtic)'); }
      const missF = r.flags.find(function (f) { return /% missing/.test(f); });
      if (missF) { highMiss++; flags.push(r.name + ' (' + missF + ')'); }
    });
    const pen = Math.min(ceilingFloor * 2, 6) + Math.min(lowVarN * 2, 6) +
                Math.min(highSkew * 1, 4) + Math.min(highKurt * 1, 4) +
                Math.min(highMiss * 2, 4);
    const points = Math.max(0, 20 - pen);

    // Strip private fields before exposing rows to the narrative.
    const narrativeRows = rows.map(function (r) {
      const c = {};
      Object.keys(r).forEach(function (k) { if (k.charAt(0) !== '_') c[k] = r[k]; });
      return c;
    });
    const ok    = narrativeRows.filter(function (r) { return r.tone === 'ok'; }).length;
    const warn  = narrativeRows.filter(function (r) { return r.tone === 'warn'; }).length;
    const alert = narrativeRows.filter(function (r) { return r.tone === 'alert'; }).length;
    const judgment = alert
      ? alert + ' item' + (alert === 1 ? '' : 's') + ' show multiple problems and should be revised or removed; ' + warn + ' need a closer look.'
      : warn
        ? warn + ' item' + (warn === 1 ? '' : 's') + ' show one issue each. Inspect; most are usable with minor adjustments.'
        : 'All items pass the item-quality screens.';
    const statusTone = alert ? 'alert' : warn ? 'warn' : 'strong';
    const status     = alert ? 'Action Required' : warn ? 'Inspect' : 'All Clear';

    const narrative_text = flags.length
      ? 'Items to review: ' + flags.slice(0, 4).join(', ') + (flags.length > 4 ? ', and more' : '') + '.'
      : 'No items show ceiling, floor, low-variance, skew, kurtosis, or high-missingness flags.';

    return {
      points: points,
      flags: flags,
      narrative: { rows: narrativeRows, ok: ok, warn: warn, alert: alert, judgment: judgment, status: status, statusTone: statusTone },
      narrative_text: narrative_text,
    };
  }

  // ====================================================================
  // THREE-LENS RSSI CONFIGURATION (Spec §3.2)
  // ====================================================================
  // Versioned weight vectors. Tagged so a saved version always traces
  // back to the exact scoring revision that produced it (Spec §9, §15).
  const RSSI_WEIGHTS_VERSION = 'v2.0';

  // Canonical 8-domain key set (Spec §2, §10). The single source of
  // truth for domain identifiers across the engine and both surfaces.
  // No alternate names appear in module output.
  const CANONICAL_DOMAINS = [
    'reliability', 'validity', 'construct_alignment', 'item_prompt_quality',
    'bias_clarity', 'scale_structure', 'factor_readiness', 'response_scale_review',
  ];

  // Spec §3.2 — three weight vectors over the 8 canonical domains.
  // Each lens column sums to 100. Edit only through a versioned bump.
  const LENS_WEIGHTS = {
    psychometric_core: {
      reliability: 22, validity: 22, construct_alignment: 14, item_prompt_quality: 12,
      bias_clarity: 8, scale_structure: 6, factor_readiness: 10, response_scale_review: 6,
    },
    respondent_centered: {
      reliability: 15, validity: 15, construct_alignment: 10, item_prompt_quality: 18,
      bias_clarity: 14, scale_structure: 8, factor_readiness: 8, response_scale_review: 12,
    },
    validity_forward: {
      reliability: 15, validity: 22, construct_alignment: 15, item_prompt_quality: 14,
      bias_clarity: 10, scale_structure: 6, factor_readiness: 10, response_scale_review: 8,
    },
  };
  const LENS_KEYS = ['psychometric_core', 'respondent_centered', 'validity_forward'];

  // Spec §3.4 — Respondent-Centered is the default headline for new
  // users; surfaces may override per-survey.
  const DEFAULT_HEADLINE_LENS = 'respondent_centered';

  // Spec §3.6 — Validity-Forward evidence cap.
  // When criterion data is absent, the Validity sub-score is capped at
  // 60 so the absence of criterion evidence cannot produce an
  // indistinguishable headline from a fully-evidenced instrument.
  // The cap modifies the underlying sub-score (which feeds all three
  // lenses), but the "limited evidence" indicator is surfaced only on
  // the Validity-Forward lens per §3.6.
  function applyValidityForwardCap(validitySubscore, criterionPresent) {
    if (validitySubscore == null) return { value: null, capped: false };
    if (criterionPresent) return { value: validitySubscore, capped: false };
    if (validitySubscore <= 60) return { value: validitySubscore, capped: true };
    return { value: 60, capped: true };
  }

  // Spec §3.5 — disagreement readout lookup. Returns a one-sentence
  // diagnostic when the spread between the highest and lowest lens
  // scores exceeds 10 points, else null. Suppressed when any of the 8
  // canonical domains is skipped — the three baseline sentences are
  // calibrated for the full 8-domain weight structure (Phase 3
  // decision: partial-evidence variants are not invented here).
  const DISAGREEMENT_SENTENCES = {
    // key: 'highest|lowest'
    'psychometric_core|respondent_centered':
      'Your scales are statistically sound, but the items may be hard for respondents to answer clearly. Review item wording before deployment.',
    'respondent_centered|psychometric_core':
      'Your items read well, but the underlying scales are not holding together statistically. Consider adding items or refining constructs.',
    // Spec §3.5 third pattern — V-F much lower than both others. The
    // lookup key is the lens that is *highest*; we resolve to this
    // sentence when V-F is the lowest AND the spread between V-F and
    // the next-lower lens exceeds 10 points (handled at call site).
    'validity_forward_low':
      'Your survey is internally consistent, but you may not yet have evidence it measures what you claim. Add criterion data or pilot against an established instrument.',
  };

  function computeDisagreementReadout(lensScores, skippedDomains) {
    if (Array.isArray(skippedDomains) && skippedDomains.length > 0) {
      return null;
    }
    const present = LENS_KEYS.filter(function (L) { return lensScores[L] != null; });
    if (present.length < 2) return null;
    const sorted = present.slice().sort(function (a, b) { return lensScores[b] - lensScores[a]; });
    const highest = sorted[0];
    const lowest  = sorted[sorted.length - 1];
    const spread  = lensScores[highest] - lensScores[lowest];
    if (spread <= 10) return null;
    // §3.5 V-F-low pattern takes precedence when V-F is the lowest by
    // more than 10 points below BOTH other lenses.
    if (lowest === 'validity_forward') {
      const nextLowest = sorted[sorted.length - 2];
      if (lensScores[nextLowest] - lensScores['validity_forward'] > 10) {
        return DISAGREEMENT_SENTENCES['validity_forward_low'];
      }
    }
    const key = highest + '|' + lowest;
    return DISAGREEMENT_SENTENCES[key] || null;
  }

  // Spec §3.3 — pure lens math from a {domain_key: 0–100 | null} map.
  // Null sub-scores (the four un-built §4A/4B/4D/4E domains in this
  // build, or any domain skipped by edge-case logic) are excluded from
  // the weighted sum and the weights rescale to the present subset.
  // Returns lens scores rounded to two decimals.
  function computeLenses(subscores) {
    const skipped = CANONICAL_DOMAINS.filter(function (d) { return subscores[d] == null; });
    const out = {
      psychometric_core: null,
      respondent_centered: null,
      validity_forward: null,
      headline_lens: DEFAULT_HEADLINE_LENS,
      skipped_domains: skipped,
    };
    LENS_KEYS.forEach(function (L) {
      const w = LENS_WEIGHTS[L];
      let weighted = 0, totalW = 0;
      CANONICAL_DOMAINS.forEach(function (d) {
        if (subscores[d] == null) return;
        weighted += subscores[d] * w[d];
        totalW   += w[d];
      });
      out[L] = totalW > 0 ? Math.round((weighted / totalW) * 100) / 100 : null;
    });
    return out;
  }

  // Active analysis context. The per-domain compute fns below read
  // from these bindings; `_resolveCtx(dataset)` repopulates them
  // before each call to `computeLensesFromDataset`. Declared `let` so
  // both the IIFE render path and the canonical entry can drive them.
  let likertVars, categoricalVars, allVars, rowCount;

  function _resolveCtx(ds) {
    const byType = function (t) {
      return ds.variables.filter(function (v) { return v.types && v.types.indexOf(t) !== -1; });
    };
    likertVars      = byType('likert');
    categoricalVars = byType('categorical');
    allVars         = ds.variables;
    rowCount        = ds.rowCount || (allVars[0] ? allVars[0].values.length : 0);
  }

  // ====================================================================
  // CANONICAL LENS ENTRY POINT (Spec §6 single-source-of-truth applied
  // to the composite path). Both the in-studio mount and the standalone
  // RSSI surface call this. Identical input → identical output.
  // ====================================================================
  function computeLensesFromDataset(ds, config) {
    config = config || {};
    const criterionPresent = !!config.criterion_column;
    const baseOut = {
      rssi: {
        psychometric_core: null, respondent_centered: null, validity_forward: null,
        headline_lens: DEFAULT_HEADLINE_LENS,
        disagreement_readout: null,
        validity_forward_capped: false,
      },
      domain_subscores: {},
      domain_details: {},
      skipped_domains: CANONICAL_DOMAINS.slice(),
      rssi_weights_version: RSSI_WEIGHTS_VERSION,
      computed_at: new Date().toISOString(),
    };
    if (!ds || !Array.isArray(ds.variables)) {
      baseOut.error = 'no_dataset';
      return baseOut;
    }
    _resolveCtx(ds);

    // Per-domain compute fns are closures over likertVars/allVars/
    // rowCount (set by _resolveCtx above). open_ended is folded into
    // item_prompt_quality per Spec §4C; actionability is removed per
    // Spec §2. Neither feeds the lens math.
    const rel = computeReliability();
    const fs  = computeFactorStructure();
    const iq  = computeItemQuality();
    const rq  = computeResponseQuality();
    const ss  = computeScaleStructure(config);

    // Map v1 compute output → canonical 8-domain sub-score map.
    // Three un-built domains (Spec §4A/4B/4D new construction) remain
    // null; computeLenses excludes them via skip-and-rescale. §4E is
    // built (computeScaleStructure) but stays null in production until
    // the platform-side data contract carries scale assignments etc.
    // (see KNOWN_ISSUES.md §4 for the four data-flow prerequisites).
    const subscores = {
      reliability:           rel.score,
      validity:              null,
      construct_alignment:   null,
      item_prompt_quality:   iq.score,
      bias_clarity:          null,
      scale_structure:       ss.score,
      factor_readiness:      fs.score,
      response_scale_review: rq.score,
    };

    // Spec §3.6 — apply Validity-Forward evidence cap before lens math.
    // The cap is dormant in this build (validity sub-score is null
    // until §4A ships); when validity becomes a real number, the cap
    // automatically engages if config.criterion_column is absent.
    const cap = applyValidityForwardCap(subscores.validity, criterionPresent);
    subscores.validity = cap.value;

    const lens = computeLenses(subscores);
    const readout = computeDisagreementReadout(lens, lens.skipped_domains);

    return {
      rssi: {
        psychometric_core:       lens.psychometric_core,
        respondent_centered:     lens.respondent_centered,
        validity_forward:        lens.validity_forward,
        headline_lens:           lens.headline_lens,
        disagreement_readout:    readout,
        validity_forward_capped: cap.capped,
      },
      domain_subscores: subscores,
      domain_details: {
        reliability:           rel,
        factor_readiness:      fs,
        item_prompt_quality:   iq,
        response_scale_review: rq,
        scale_structure:       ss,
      },
      skipped_domains: lens.skipped_domains,
      rssi_weights_version: RSSI_WEIGHTS_VERSION,
      computed_at: new Date().toISOString(),
    };
  }

  // Single entry point for retirement-phase work to migrate against.
  window.RSSI_MATH = {
    completeCases:          absorbedCompleteCases,
    cronbachAlpha:          absorbedCronbachAlpha,
    itemTotal:              absorbedItemTotal,
    bandForAlpha:           bandForAlpha,
    avgInterItemR:          absorbedAvgInterItemR,
    itemRestCorrelations:   absorbedItemRestCorrelations,
    computeReliabilityStatusNarrative,
    computeItemQuality:     canonicalItemQuality,
    // Three-lens RSSI (Spec §3.2–3.6, §6).
    RSSI_WEIGHTS_VERSION,
    CANONICAL_DOMAINS,
    LENS_WEIGHTS,
    computeLenses,
    computeLensesFromDataset,
    computeDisagreementReadout,
    applyValidityForwardCap,
  };

  // ---------- Dataset gate ----------
  // Math + lens entry exposed above; everything below is the
  // strength-index render path and requires a resolved dataset.
  if (!dataset || !Array.isArray(dataset.variables)) {
    console.warn('Strength Index: no dataset available');
    return;
  }
  // Variable groups (likertVars, allVars, rowCount, …) are populated
  // by _resolveCtx() — called inside computeLensesFromDataset below.
  // The render path runs that single canonical entry, then reads the
  // resulting domain_details + lens scores.

  // ---------- Components ----------
  function computeReliability() {
    // Domain: Internal Consistency & Scale Reliability (25 pts total)
    // Five sub-components per the canonical formula
    // (see relicheck_strength_index_formula.md memory):
    //   - Cronbach's α                       8 pts
    //   - McDonald's ω                      10 pts
    //   - α-ω agreement                      3 pts
    //   - Item-total / item-rest correlations 3 pts
    //   - Redundancy / overlap check         1 pt
    //
    // The component card shows the raw "X / 25" and a percent-of-max
    // bar; the headline Strength score still averages domains in v1
    // (full weighted composite lands when all six domains are aligned).

    if (likertVars.length < 2) {
      return {
        score: null, raw: null, max: 25,
        note: 'Needs at least 2 Likert items.',
        interp: 'A reliable scale needs at least two Likert items measuring the same construct.',
        tone: 'warn',
      };
    }
    const cols = likertVars.map(v => v.values.map(num).map(x => x == null ? 0 : x));
    const valid = [];
    for (let i = 0; i < rowCount; i++) {
      let any = false;
      for (let c = 0; c < cols.length; c++) {
        if (isMissing(likertVars[c].values[i])) { any = true; break; }
      }
      if (!any) valid.push(i);
    }
    if (valid.length < 5) {
      return {
        score: null, raw: null, max: 25,
        note: 'Not enough complete rows.',
        interp: 'Provisional reliability needs at least five respondents with answers across every scale item.',
        tone: 'warn',
      };
    }
    const k = cols.length;
    const validCols = cols.map(col => valid.map(i => col[i]));
    const rawArrays = likertVars.map(v => v.values.map(num));

    // ----- Cronbach's α (8 pts) -----
    const totals   = valid.map((_, idx) => validCols.reduce((s, col) => s + col[idx], 0));
    const alpha = absorbedCronbachAlpha(rawArrays);

    // Spec §4.1 bands; bandForAlpha is the single source for both the
    // user-facing label (banner) and the point allocation (scoring).
    const alphaBand = bandForAlpha(alpha);
    const alphaPts  = alphaBand.points;

    // ----- McDonald's ω (10 pts) -----
    // Requires ≥ 3 items and ≥ 3 respondents (already verified above) to
    // estimate single-factor loadings. When ω cannot be estimated, the
    // domain falls back to α + item-total correlations and surfaces a
    // warning in the interpretation.
    //
    // Bands per Spec §4.1 (canonical v2). v1's softer floor (<0.60 → 2)
    // and intermediate 5-pt step are superseded — an instrument that
    // fails the 0.60 reliability threshold cannot quietly contribute
    // points to the headline.
    const omega = (k >= 3) ? mcdonaldOmega(validCols) : null;
    let omegaPts, omegaText, omegaWarning = '';
    if (omega == null) {
      omegaPts = 0;
      omegaText = '—';
      omegaWarning = (k < 3)
        ? 'ω needs at least 3 items; the domain is using α + item-total correlations as a provisional estimate.'
        : 'ω could not be estimated; reading the provisional reliability from α + item-total correlations.';
    } else {
      omegaText = omega.toFixed(2);
      if      (omega >= 0.90) omegaPts = 10;
      else if (omega >= 0.80) omegaPts = 9;
      else if (omega >= 0.70) omegaPts = 7;
      else if (omega >= 0.60) omegaPts = 4;
      else                    omegaPts = 0;
    }

    // ----- α-ω agreement (3 pts) -----
    // Per Spec §4.1, the agreement point is awarded purely on the
    // absolute gap |α − ω|. v1's "both ≥ 0.80" gate is removed in v2:
    // the agreement measures whether the two coefficients tell the
    // same story, independent of magnitude.
    let agreementPts, agreementText;
    if (omega == null) {
      agreementPts = 0;
      agreementText = 'agreement not assessed (no ω)';
    } else {
      const gap = Math.abs(alpha - omega);
      if (gap <= 0.05) {
        agreementPts = 3;
        agreementText = 'α and ω agree (gap = ' + gap.toFixed(2) + ')';
      } else if (gap <= 0.10) {
        agreementPts = 1.5;
        agreementText = 'the 1-factor assumption is mildly strained (α-ω gap = ' + gap.toFixed(2) + ')';
      } else {
        agreementPts = 0;
        agreementText = 'items may not load equally on one factor (α-ω gap = ' + gap.toFixed(2) + ')';
      }
    }

    // ----- Item-total / item-rest correlations (3 pts) -----
    // Corrected item-total: each item correlated with the sum of the
    // OTHER items. Awards 3 pts when all items r ≥ 0.40. Deducts 1 pt
    // per item with r < 0.30; deducts 1.5 pts per item with r < 0.
    const itemRestRs = absorbedItemRestCorrelations(rawArrays);
    const itemTotals = likertVars.map((v, i) => ({ name: v.name, r: itemRestRs[i] }));
    let itemTotalPts = 3;
    let weakItems = 0, negItems = 0;
    const weakItemNames = [], negItemNames = [];
    itemTotals.forEach(it => {
      if (it.r < 0)        { negItems++;  negItemNames.push(it.name); }
      else if (it.r < 0.30){ weakItems++; weakItemNames.push(it.name); }
    });
    itemTotalPts -= 1.5 * negItems;
    itemTotalPts -= 1.0 * weakItems;
    itemTotalPts = clamp(itemTotalPts, 0, 3);

    // ----- Redundancy / overlap (1 pt) -----
    // Average inter-item correlation. Sweet spot 0.30-0.60 → 1 pt.
    // Above 0.80 = items redundant. Below 0.30 = items don't cohere.
    const avgR = absorbedAvgInterItemR(rawArrays) || 0;
    let redundancyPts, redundancyText;
    if (avgR >= 0.30 && avgR <= 0.60) {
      redundancyPts = 1;
      redundancyText = 'items cohere without being redundant (avg inter-item r = ' + avgR.toFixed(2) + ')';
    } else if (avgR > 0.80) {
      redundancyPts = 0;
      redundancyText = 'items may be redundant (avg inter-item r = ' + avgR.toFixed(2) + '); consider dropping one';
    } else if (avgR > 0.60) {
      redundancyPts = 0.5;
      redundancyText = 'items correlate strongly (avg inter-item r = ' + avgR.toFixed(2) + '); watch for redundancy';
    } else {
      redundancyPts = 0;
      redundancyText = 'items do not cohere well (avg inter-item r = ' + avgR.toFixed(2) + ')';
    }

    // ----- Per-item diagnostics (for the rich Reliability detail view) -----
    // Mean, SD, missing rate, α-if-dropped, ω-if-dropped, flag, plain-
    // language per-item interpretation. Built once here so the detail
    // section doesn't re-walk the data.
    const itemDiagnostics = likertVars.map((v, i) => {
      const allVals    = v.values;
      const numericAll = allVals.map(num).filter(x => x != null);
      const missingN   = allVals.length - numericAll.length;
      const missingPct = allVals.length ? missingN / allVals.length : 0;
      const itemMean   = numericAll.length ? mean(numericAll) : null;
      const itemSd     = numericAll.length > 1 ? Math.sqrt(variance(numericAll)) : null;
      const itemRest   = itemTotals[i] ? itemTotals[i].r : null;

      // α-if-dropped re-filters complete cases per reduced set, matching
      // the standalone analyzer's behavior. This differs from v1's inline
      // behavior, which used fixed full-scale complete cases across all
      // toggles. Re-filtering is the methodologically honest choice for
      // exploratory toggling because each reduced scale is treated as its
      // own analysis. The spec (§7.1, §7.2) is silent on the complete-
      // cases question; this is a deliberate choice for alignment with
      // the standalone surface, not a spec mandate.
      let alphaIfDropped = null;
      if (k > 2) {
        const reducedRaw = rawArrays.filter((_, j) => j !== i);
        alphaIfDropped = absorbedCronbachAlpha(reducedRaw);
      }

      // ω with this item removed (only meaningful with ≥ 3 items remaining)
      let omegaIfDropped = null;
      if (k > 3) {
        const reduced = validCols.filter((_, j) => j !== i);
        omegaIfDropped = mcdonaldOmega(reduced);
      }

      // Flag + plain-language interpretation
      let flagTone, flagLabel, interpText;
      if (itemRest != null && itemRest < 0) {
        flagTone = 'reverse';
        flagLabel = 'Reverse?';
        interpText = 'Negative item-rest correlation. The item may be reverse-coded or measuring something different from the rest of the scale.';
      } else if (itemRest != null && itemRest < 0.30) {
        flagTone = 'drop';
        flagLabel = 'Drop?';
        interpText = 'Weak relationship to the rest of the scale (r = ' + (itemRest != null ? itemRest.toFixed(2) : '—') + '). Consider rewording or dropping.';
      } else if (alphaIfDropped != null && alphaIfDropped > alpha + 0.02) {
        flagTone = 'watch';
        flagLabel = 'Watch';
        interpText = 'α improves from ' + alpha.toFixed(2) + ' to ' + alphaIfDropped.toFixed(2) + ' when this item is dropped. Inspect wording.';
      } else if (missingPct > 0.20) {
        flagTone = 'watch';
        flagLabel = 'Watch';
        interpText = Math.round(missingPct * 100) + '% of respondents skipped this item. Check wording or routing.';
      } else if (itemSd != null && itemSd < 0.5) {
        flagTone = 'watch';
        flagLabel = 'Watch';
        interpText = 'Very low variance (SD = ' + itemSd.toFixed(2) + '). Most respondents gave the same answer; the item may not discriminate.';
      } else {
        flagTone = 'ok';
        flagLabel = 'Good';
        interpText = 'Loads cleanly on the scale (item-rest r = ' + (itemRest != null ? itemRest.toFixed(2) : '—') + ').';
      }

      return {
        name:           v.name,
        text:           v.text || '—',  // populated when survey builder integrates
        mean:           itemMean,
        sd:             itemSd,
        missingRate:    missingPct,
        itemRest:       itemRest,
        alphaIfDropped: alphaIfDropped,
        omegaIfDropped: omegaIfDropped,
        flagTone:       flagTone,
        flagLabel:      flagLabel,
        interp:         interpText,
      };
    });

    // ----- Scale-level summary -----
    // Mean scale score = mean of per-row totals; SD = std dev of totals.
    const allRowTotals = totals; // per-row sums over the Likert items
    const meanScale = totals.length ? mean(totals) : null;
    const sdScale   = totals.length > 1 ? Math.sqrt(variance(totals)) : null;
    const totalCells   = k * rowCount;
    const missingCells = likertVars.reduce((s, v) =>
      s + v.values.filter(x => isMissing(x)).length, 0);
    const missingRate  = totalCells ? missingCells / totalCells : 0;

    const ratingPrimary = omega == null ? alpha : omega;
    let scaleRating, scaleRatingTone;
    if      (ratingPrimary >= 0.90) { scaleRating = 'Excellent';            scaleRatingTone = 'ok'; }
    else if (ratingPrimary >= 0.80) { scaleRating = 'Strong';               scaleRatingTone = 'ok'; }
    else if (ratingPrimary >= 0.70) { scaleRating = 'Acceptable';           scaleRatingTone = 'warn'; }
    else if (ratingPrimary >= 0.60) { scaleRating = 'Needs strengthening';  scaleRatingTone = 'warn'; }
    else                            { scaleRating = 'Weak';                 scaleRatingTone = 'alert'; }

    const scaleSummary = {
      // Without per-construct tags from the survey builder we treat all
      // Likert items as a single scale. When tags exist, this section
      // will iterate once per tagged construct.
      name:           'Composite Likert scale (all Likert items)',
      itemCount:      k,
      validResponses: valid.length,
      alpha:          alpha,
      omega:          omega,
      avgInterItem:   avgR,
      meanScale:      meanScale,
      sdScale:        sdScale,
      missingRate:    missingRate,
      rating:         scaleRating,
      ratingTone:     scaleRatingTone,
    };

    // ----- Contribution breakdown (25 pts) -----
    const contribBreakdown = [
      { key: 'alpha',      label: "Cronbach's α",         pts: alphaPts,      max: 8  },
      { key: 'omega',      label: "McDonald's ω total",   pts: omegaPts,      max: 10 },
      { key: 'agreement',  label: 'α-ω agreement',        pts: agreementPts,  max: 3  },
      { key: 'itemTotal',  label: 'Item-rest correlations', pts: itemTotalPts, max: 3 },
      { key: 'redundancy', label: 'Redundancy check',     pts: redundancyPts, max: 1  },
    ];

    // ----- Composite for this domain -----
    const raw = round((alphaPts + omegaPts + agreementPts + itemTotalPts + redundancyPts) * 10) / 10;
    const score = clamp(round((raw / 25) * 100), 0, 100);

    // ----- Full multi-point interpretation (for the rich detail view) -----
    const strongItems = itemDiagnostics.filter(d => d.flagTone === 'ok').map(d => d.name);
    const watchItems  = itemDiagnostics.filter(d => d.flagTone === 'watch' || d.flagTone === 'drop' || d.flagTone === 'reverse');
    let fullInterp = [];

    // Reliable?
    if (omega != null) {
      fullInterp.push(
        'The scale ' +
        (omega >= 0.80 ? '<strong>is internally consistent</strong>' :
         omega >= 0.70 ? '<strong>is usable</strong> but not strong' :
         '<strong>needs strengthening</strong>') +
        ' (ω = ' + omega.toFixed(2) + ', α = ' + alpha.toFixed(2) + ').'
      );
    } else {
      fullInterp.push(
        'ω could not be estimated; reading the scale on α + item-total correlations only. ' +
        'On α alone the scale ' +
        (alpha >= 0.80 ? 'appears internally consistent' :
         alpha >= 0.70 ? 'is usable' :
         'needs strengthening') +
        ' (α = ' + alpha.toFixed(2) + ').'
      );
    }

    // Agreement
    if (omega != null) {
      const gap = Math.abs(alpha - omega);
      if (gap <= 0.05) {
        fullInterp.push('<strong>α and ω agree</strong> (gap = ' + gap.toFixed(2) + '); the 1-factor assumption holds.');
      } else if (gap <= 0.10) {
        fullInterp.push('α and ω <strong>partially agree</strong> (gap = ' + gap.toFixed(2) + '); items contribute somewhat unequally.');
      } else {
        fullInterp.push('α and ω <strong>disagree</strong> (gap = ' + gap.toFixed(2) + '); items may not load equally on one factor and the scale may be multidimensional.');
      }
    }

    // Strong items
    if (strongItems.length) {
      fullInterp.push('<strong>Strong items:</strong> ' + strongItems.join(', ') + '. All have item-rest r ≥ 0.30 and load cleanly.');
    }

    // Items needing review
    if (watchItems.length) {
      const reasons = watchItems.map(w => w.name + ' (' + w.flagLabel.toLowerCase() + ')').join(', ');
      fullInterp.push('<strong>Items to review:</strong> ' + reasons + '. See the item diagnostics table for each item\'s specific issue.');
    }

    // Misalignment / reverse / redundancy / confusing
    const misalignedNotes = [];
    if (itemDiagnostics.some(d => d.flagTone === 'reverse')) {
      misalignedNotes.push('one or more items may be reverse-coded or misaligned');
    }
    if (avgR > 0.80) {
      misalignedNotes.push('items are highly correlated and may be redundant (avg inter-item r = ' + avgR.toFixed(2) + '); consider whether all items are necessary');
    } else if (avgR < 0.30) {
      misalignedNotes.push('items do not cohere strongly (avg inter-item r = ' + avgR.toFixed(2) + '); the scale may be measuring more than one construct');
    }
    if (itemDiagnostics.some(d => d.missingRate > 0.20)) {
      misalignedNotes.push('one or more items have high missingness and may be confusing or poorly placed');
    }
    if (misalignedNotes.length) {
      fullInterp.push('<strong>Watch:</strong> ' + misalignedNotes.join('; ') + '.');
    }

    // Ready for reporting?
    let readyForReport, readyTone;
    if (omega != null && omega >= 0.80 && !watchItems.some(w => w.flagTone === 'drop' || w.flagTone === 'reverse')) {
      readyForReport = 'The scale is <strong>ready for reporting</strong>.';
      readyTone = 'ok';
    } else if ((omega != null && omega >= 0.70) || (omega == null && alpha >= 0.70)) {
      readyForReport = 'The scale is <strong>usable with caveats</strong>; address the items flagged above before publishing.';
      readyTone = 'warn';
    } else {
      readyForReport = '<strong>Not ready for reporting yet.</strong> Strengthen the scale (re-word weak items, drop reverse-coded outliers, or split into multiple constructs) before drawing conclusions.';
      readyTone = 'alert';
    }
    fullInterp.push(readyForReport);

    const diagnostics = {
      scaleSummary,
      itemDiagnostics,
      contribBreakdown,
      fullInterp,
      readyTone,
    };

    // ----- Note (compact, for the card) -----
    let note = 'α = ' + alpha.toFixed(2) + ', ω = ' + omegaText + '  ·  ' + raw + ' / 25';

    // ----- Interpretation (richer, for the bottom panel) -----
    let interp;
    if (omega == null) {
      interp = omegaWarning + ' On α alone the scale is ' +
        (alpha >= 0.80 ? 'internally consistent' : alpha >= 0.70 ? 'usable' : 'weak') +
        ' (α = ' + alpha.toFixed(2) + '), and ' +
        (itemTotals.length - weakItems - negItems) + ' of ' + itemTotals.length + ' items have a corrected item-total r ≥ 0.30.';
    } else {
      interp =
        'α = ' + alpha.toFixed(2) + ' and ω = ' + omega.toFixed(2) + ': ' + agreementText + '. ' +
        'The scale ' +
        (omega >= 0.80 ? 'appears internally consistent' : omega >= 0.70 ? 'is usable but not strong' : 'needs strengthening') +
        ' by ω, the more defensible estimator when item loadings vary.';
    }
    if (weakItems || negItems) {
      const flags = [];
      if (negItems)  flags.push(negItemNames.length === 1
        ? 'item ' + negItemNames[0] + ' has a NEGATIVE item-rest correlation and is pulling the scale apart'
        : negItemNames.length + ' items have negative item-rest correlations');
      if (weakItems) flags.push(weakItemNames.length === 1
        ? 'item ' + weakItemNames[0] + ' has a weak item-rest correlation (r < 0.30)'
        : weakItemNames.length + ' items have weak item-rest correlations (r < 0.30)');
      interp += ' Item-level flag: ' + flags.join(' and ') + '.';
    } else {
      interp += ' All ' + itemTotals.length + ' items have item-rest correlations ≥ 0.30.';
    }
    interp += ' ' + redundancyText.charAt(0).toUpperCase() + redundancyText.slice(1) + '.';

    return {
      score, raw, max: 25,
      note,
      interp,
      tone: score >= 80 ? 'ok' : score >= 60 ? 'warn' : 'alert',
      breakdown: {
        alpha:        { value: alpha, pts: alphaPts,        max: 8  },
        omega:        { value: omega, pts: omegaPts,        max: 10 },
        agreement:    {              pts: agreementPts,    max: 3, text: agreementText },
        itemTotal:    {              pts: itemTotalPts,    max: 3, weak: weakItemNames, neg: negItemNames, all: itemTotals },
        redundancy:   { avgR,        pts: redundancyPts,   max: 1, text: redundancyText },
      },
      diagnostics: diagnostics,
    };
  }

  // ====================================================================
  // FACTOR STRUCTURE (20 pts)
  // KMO bands + loading-pattern penalties.
  // ====================================================================
  function computeFactorStructure() {
    const MAX = 20;
    if (likertVars.length < 2) {
      return blankDomain(MAX, 'Needs at least 2 Likert items.', 'warn');
    }
    const cols = likertVars.map(v => v.values.map(num).map(x => x == null ? 0 : x));
    const validRows = [];
    for (let i = 0; i < rowCount; i++) {
      if (!likertVars.some(v => isMissing(v.values[i]))) validRows.push(i);
    }
    if (validRows.length < 3) {
      return blankDomain(MAX, 'Not enough complete rows for KMO.', 'warn');
    }
    const validCols = cols.map(col => validRows.map(i => col[i]));

    const kmo = kmoIndex(validCols);
    let basePts;
    if (kmo == null) basePts = 5;
    else if (kmo >= 0.80) basePts = 18;
    else if (kmo >= 0.70) basePts = 15;
    else if (kmo >= 0.60) basePts = 11;
    else basePts = 5;

    // Loading-pattern penalties on first-factor loadings.
    const loadings = firstFactorLoadings(validCols) || [];
    const weakLoads = loadings.filter(l => Math.abs(l) < 0.40).length;
    const weakPen = Math.min(weakLoads * 2, 6);
    // Cross-loading: top two loadings within 0.20 and both >= 0.30. Single-
    // factor PC can't really show cross-loadings (only one factor); skip.
    const crossPen = 0;
    // Single-item factor: in a single-factor model, all items load on one
    // factor; this penalty applies in multi-factor solutions. Skip in v1.
    const singletonPen = 0;

    const raw = Math.max(0, basePts - weakPen - crossPen - singletonPen);
    const score = clamp(round((raw / MAX) * 100), 0, 100);
    const kmoText = kmo == null ? '—' : kmo.toFixed(2);
    return {
      score, raw, max: MAX,
      note: 'KMO = ' + kmoText + (weakLoads ? ', ' + weakLoads + ' weak loading' + (weakLoads === 1 ? '' : 's') : '') + '  ·  ' + raw + ' / ' + MAX,
      interp: 'Factor adequacy: KMO = ' + kmoText + '. ' +
              (weakLoads ? weakLoads + ' item(s) load weakly on the first factor.' : 'All items load adequately on the first factor.'),
      tone: score >= 80 ? 'ok' : score >= 60 ? 'warn' : 'alert',
    };
  }

  // ====================================================================
  // ITEM QUALITY (20 pts) — thin render adapter around the canonical
  // merged function (canonicalItemQuality / window.RSSI_MATH
  // .computeItemQuality). The canonical function returns points and
  // flags (consumed here for scoring + note) and narrative /
  // narrative_text (consumed by the standalone analyzer).
  // ====================================================================
  function computeItemQuality() {
    const MAX = 20;
    if (likertVars.length < 1) return blankDomain(MAX, 'No Likert items to evaluate.', 'warn');
    const c = canonicalItemQuality(likertVars);
    if (c.error) return blankDomain(MAX, c.error, 'warn');
    const raw   = c.points;
    const score = clamp(round((raw / MAX) * 100), 0, 100);
    const flagCount = c.flags.length;
    return {
      score, raw, max: MAX,
      note: (flagCount ? flagCount + ' item flag' + (flagCount === 1 ? '' : 's') : 'No item-level flags') + '  ·  ' + raw + ' / ' + MAX,
      interp: c.narrative_text,
      tone: score >= 85 ? 'ok' : score >= 65 ? 'warn' : 'alert',
    };
  }

  // ====================================================================
  // RESPONSE QUALITY (15 pts)
  // Sample-size brackets + completion + missingness + straight-lining.
  // ====================================================================
  function computeResponseQuality() {
    const MAX = 15;
    // Sample-size brackets
    let basePts;
    if (rowCount >= 100) basePts = 15;
    else if (rowCount >= 50) basePts = 13;
    else if (rowCount >= 30) basePts = 11;
    else if (rowCount >= 10) basePts = 8;
    else basePts = 5;

    // Completion: rows complete across non-open vars
    const required = allVars.filter(v => !(v.types || []).some(t => t === 'open'));
    let complete = 0;
    for (let i = 0; i < rowCount; i++) {
      if (required.every(v => !isMissing(v.values[i]))) complete++;
    }
    const completeRate = rowCount ? complete / rowCount : 0;
    let completionPen = 0;
    if (completeRate < 0.90) completionPen += 1;
    if (completeRate < 0.75) completionPen += 1;

    // Item-level missingness penalty
    let missCells = 0, totalCells = 0;
    likertVars.forEach(v => {
      v.values.forEach(x => { totalCells++; if (isMissing(x)) missCells++; });
    });
    const missRate = totalCells ? missCells / totalCells : 0;
    let missPen = 0;
    if (missRate > 0.10) missPen += 1;
    if (missRate > 0.20) missPen += 1;

    // Straight-lining
    let straight = 0, considered = 0;
    if (likertVars.length >= 3) {
      for (let i = 0; i < rowCount; i++) {
        const vals = likertVars.map(v => v.values[i]);
        if (vals.some(isMissing)) continue;
        considered++;
        if (vals.every(v => num(v) === num(vals[0]))) straight++;
      }
    }
    const straightRate = considered ? straight / considered : 0;
    let straightPen = 0;
    if (straightRate > 0.05) straightPen += 1;
    if (straightRate > 0.15) straightPen += 1;

    const raw = Math.max(0, basePts - completionPen - missPen - straightPen);
    const score = clamp(round((raw / MAX) * 100), 0, 100);
    return {
      score, raw, max: MAX,
      note: 'n=' + rowCount + ', completion ' + Math.round(completeRate * 100) + '%, straight-lining ' + (straightRate * 100).toFixed(1) + '%  ·  ' + raw + ' / ' + MAX,
      interp: 'Sample size n=' + rowCount + ' (' + (rowCount >= 50 ? 'adequate' : 'small') + '). Completion ' + Math.round(completeRate * 100) + '%; straight-lining ' + (straightRate * 100).toFixed(1) + '%; item missingness ' + (missRate * 100).toFixed(1) + '%.',
      tone: score >= 80 ? 'ok' : score >= 60 ? 'warn' : 'alert',
    };
  }

  // ====================================================================
  // SCALE STRUCTURE (Spec §4E) — 15 raw pts, rescaled to 0–100.
  //
  // Five sub-components:
  //   1. Item count per scale         (5 pts) — k=4–8 ideal; sharp penalties at extremes
  //   2. Reverse-coded balance        (3 pts) — at least one reverse item in k≥4 scales
  //   3. Response-format uniformity   (3 pts) — same likert range / anchor count per scale
  //   4. Scale-level missingness      (2 pts) — share of respondents with all items missing in a scale
  //   5. Survey-level item-count health (2 pts) — total Likert items in [5, 30]
  //
  // Per Phase 1 decisions:
  //   - No scale assignments on any Likert item → whole domain SKIPS
  //     (return score: null). Caller's lens math absorbs via §3.2
  //     skip-and-rescale.
  //   - Reverse-coded balance uses a tri-state contract: skip-and-
  //     rescale unless the survey-level config flag
  //     `reverse_coded_confirmed: true` is set. When the flag IS set,
  //     scales with k≥4 and zero reverse-coded items score 0 (the
  //     architectural finding the spec describes); k<4 scales auto-
  //     award 3. Without the confirmation flag, sub-component 2
  //     skips and the remaining 12 raw pts rescale to 15.
  //   - Format uniformity requires per-item likert_range or anchor_count.
  //     Absent on any item → sub-component 3 skips and rescales.
  //
  // Per-item fields read from each Likert variable:
  //   - v.scale  or  v.construct  (string)   — scale membership
  //   - v.reverse_coded           (boolean)  — defaults to false
  //   - v.likert_range            ([lo, hi]) — format key
  //   - v.anchor_count            (number)   — alt format key
  //
  // Config fields read:
  //   - config.reverse_coded_confirmed (boolean) — gates sub-component 2
  // ====================================================================
  function computeScaleStructure(config) {
    config = config || {};
    const MAX_RAW = 15;
    const skipResult = function (reason, note) {
      return {
        score: null, raw: null, max: MAX_RAW,
        note: note, interp: note, tone: 'neutral',
        skipped: true, skip_reason: reason,
      };
    };

    if (likertVars.length === 0) {
      return skipResult('no_likert_items', 'No Likert items to evaluate scale structure.');
    }

    // Group items by scale. Accept either `scale` or `construct` as the
    // membership key (platform conventions differ — column_meta.construct
    // in db; Setup Wizard may emit `scale`). Both are acceptable; the
    // engine treats either as the canonical membership field.
    const scaleMap = {};
    let allHaveScale = true;
    likertVars.forEach(function (v) {
      const s = (v.scale != null && v.scale !== '') ? String(v.scale)
              : (v.construct != null && v.construct !== '') ? String(v.construct)
              : null;
      if (s == null) { allHaveScale = false; return; }
      if (!scaleMap[s]) scaleMap[s] = [];
      scaleMap[s].push(v);
    });
    if (!allHaveScale || Object.keys(scaleMap).length === 0) {
      return skipResult('no_scale_assignments',
        'Scale Structure skipped: no per-item scale assignments. Setup Wizard must tag each Likert item with its scale before this domain scores.');
    }

    const scales = Object.keys(scaleMap).map(function (name) {
      return { name: name, items: scaleMap[name] };
    });

    // ---- Sub-component 1: item count per scale (5 pts) ----
    function itemCountPts(k) {
      if (k >= 4 && k <= 8) return 5;
      if (k === 3)          return 3;
      if (k >= 9 && k <= 15) return 2;
      if (k === 2)          return 1;
      return 0; // k=1 or k>15
    }
    const sub1Pts = mean(scales.map(function (s) { return itemCountPts(s.items.length); }));

    // ---- Sub-component 2: reverse-coded balance (3 pts) ----
    // Tri-state per Phase 1 Q2: absent confirmation flag → skip+rescale.
    let sub2Pts = null;
    let sub2Skipped = false;
    if (!config.reverse_coded_confirmed) {
      sub2Skipped = true;
    } else {
      sub2Pts = mean(scales.map(function (s) {
        const k = s.items.length;
        if (k < 4) return 3; // small-scale auto-award per spec
        const hasReverse = s.items.some(function (it) { return !!it.reverse_coded; });
        return hasReverse ? 3 : 0;
      }));
    }

    // ---- Sub-component 3: response-format uniformity (3 pts) ----
    // Skip when any item in any scale lacks both likert_range and anchor_count.
    function fmtKey(it) {
      if (Array.isArray(it.likert_range) && it.likert_range.length === 2 &&
          it.likert_range[0] != null && it.likert_range[1] != null) {
        return 'r:' + it.likert_range[0] + '-' + it.likert_range[1];
      }
      if (it.anchor_count != null) return 'a:' + it.anchor_count;
      return null;
    }
    let sub3Pts = null;
    let sub3Skipped = false;
    const fmtMissing = scales.some(function (s) {
      return s.items.some(function (it) { return fmtKey(it) == null; });
    });
    if (fmtMissing) {
      sub3Skipped = true;
    } else {
      sub3Pts = mean(scales.map(function (s) {
        const keys = s.items.map(fmtKey);
        const first = keys[0];
        const allSame = keys.every(function (k) { return k === first; });
        return allSame ? 3 : 0;
      }));
    }

    // ---- Sub-component 4: scale-level missingness pattern (2 pts) ----
    // For each scale: share of respondents who have EVERY item in the
    // scale missing. 0% → 2; (0, 5%] → 1; >5% → 0.
    const sub4Pts = mean(scales.map(function (s) {
      let allMissingCount = 0;
      for (let i = 0; i < rowCount; i++) {
        let allMissing = true;
        for (let j = 0; j < s.items.length; j++) {
          if (!isMissing(s.items[j].values[i])) { allMissing = false; break; }
        }
        if (allMissing) allMissingCount++;
      }
      const rate = rowCount ? allMissingCount / rowCount : 0;
      if (rate === 0)    return 2;
      if (rate <= 0.05)  return 1;
      return 0;
    }));

    // ---- Sub-component 5: survey-level item-count health (2 pts) ----
    const totalLikert = likertVars.length;
    let sub5Pts;
    if (totalLikert >= 5 && totalLikert <= 30)                  sub5Pts = 2;
    else if (totalLikert === 4 || (totalLikert >= 31 && totalLikert <= 40)) sub5Pts = 1;
    else                                                         sub5Pts = 0;

    // ---- Combine with skip-and-rescale ----
    const components = [
      { key: 'item_count_per_scale',        pts: sub1Pts, max: 5, skipped: false },
      { key: 'reverse_coded_balance',       pts: sub2Pts, max: 3, skipped: sub2Skipped },
      { key: 'response_format_uniformity',  pts: sub3Pts, max: 3, skipped: sub3Skipped },
      { key: 'scale_missingness',           pts: sub4Pts, max: 2, skipped: false },
      { key: 'survey_item_count_health',    pts: sub5Pts, max: 2, skipped: false },
    ];
    let raw = 0, maxAvailable = 0;
    components.forEach(function (c) {
      if (!c.skipped) { raw += c.pts; maxAvailable += c.max; }
    });
    const score = maxAvailable > 0
      ? clamp(round((raw / maxAvailable) * 100), 0, 100)
      : null;
    const skippedKeys = components.filter(function (c) { return c.skipped; }).map(function (c) { return c.key; });

    const tone = score == null ? 'neutral'
      : score >= 80 ? 'ok'
      : score >= 60 ? 'warn'
      : 'alert';

    const rawDisp = Math.round(raw * 10) / 10;
    const note = skippedKeys.length
      ? rawDisp + ' / ' + maxAvailable + ' (rescaled from ' + MAX_RAW + '; ' + skippedKeys.length + ' sub-component(s) skipped)'
      : rawDisp + ' / ' + MAX_RAW;

    const interp = scales.length === 1
      ? 'Single scale ("' + scales[0].name + '", k=' + scales[0].items.length + '). Architecture score: ' + score + '/100.'
      : scales.length + ' scales (k = ' + scales.map(function (s) { return s.items.length; }).join(', ') + '). Architecture score: ' + score + '/100.';

    return {
      score: score,
      raw: rawDisp,
      max: maxAvailable,
      max_full: MAX_RAW,
      note: note,
      interp: interp,
      tone: tone,
      skipped: false,
      skipped_subcomponents: skippedKeys,
      breakdown: {
        item_count_per_scale:        { pts: sub1Pts, max: 5, skipped: false },
        reverse_coded_balance:       { pts: sub2Pts, max: 3, skipped: sub2Skipped },
        response_format_uniformity:  { pts: sub3Pts, max: 3, skipped: sub3Skipped },
        scale_missingness:           { pts: sub4Pts, max: 2, skipped: false },
        survey_item_count_health:    { pts: sub5Pts, max: 2, skipped: false },
      },
      scales: scales.map(function (s) { return { name: s.name, k: s.items.length }; }),
    };
  }

  // ====================================================================
  // OPEN-ENDED ALIGNMENT (10 pts)
  // Neutral 7 if no open-ends; else 4 + response-rate + avg-words + volume.
  // ====================================================================
  function computeOpenEnded() {
    const MAX = 10;
    const openVars = allVars.filter(v => (v.types || []).indexOf('open') !== -1);
    if (!openVars.length) {
      return {
        score: 70, raw: 7, max: MAX,
        note: 'No open-ended items  ·  7 / 10 (neutral)',
        interp: 'Survey has no open-ended items; neutral 7 awarded.',
        tone: 'ok',
      };
    }
    // Aggregate across all open-ended fields
    let totalAnswers = 0, nonEmptyAnswers = 0, totalWords = 0;
    openVars.forEach(v => {
      v.values.forEach(val => {
        totalAnswers++;
        if (!isMissing(val) && String(val).trim().length > 0) {
          nonEmptyAnswers++;
          totalWords += String(val).trim().split(/\s+/).length;
        }
      });
    });
    const responseRate = totalAnswers ? nonEmptyAnswers / totalAnswers : 0;
    const avgWords = nonEmptyAnswers ? totalWords / nonEmptyAnswers : 0;
    let pts = 4;
    pts += responseRate >= 0.70 ? 2 : responseRate >= 0.40 ? 1 : 0;
    pts += avgWords >= 12 ? 2 : avgWords >= 5 ? 1 : 0;
    pts += nonEmptyAnswers >= 10 ? 2 : 0;
    const raw = clamp(pts, 0, MAX);
    const score = clamp(round((raw / MAX) * 100), 0, 100);
    return {
      score, raw, max: MAX,
      note: openVars.length + ' open field' + (openVars.length === 1 ? '' : 's') + ', ' + nonEmptyAnswers + ' answers, ~' + avgWords.toFixed(1) + ' words  ·  ' + raw + ' / ' + MAX,
      interp: 'Open-ended response rate ' + Math.round(responseRate * 100) + '%, average ' + avgWords.toFixed(1) + ' words; ' + nonEmptyAnswers + ' total answers.',
      tone: score >= 80 ? 'ok' : score >= 60 ? 'warn' : 'alert',
    };
  }

  // ====================================================================
  // ACTIONABILITY (10 pts)
  // Start at 10; subtract for survey-design issues.
  // ====================================================================
  function computeActionability() {
    const MAX = 10;
    let pen = 0;
    const flags = [];
    const k = likertVars.length;
    if (k < 5) { pen += 2; flags.push('fewer than 5 Likert items'); }
    if (k > 30) { pen += 2; flags.push('more than 30 Likert items'); }
    // Reverse-coded items with very weak item-total correlations:
    // we already detect negative item-rest in the Reliability domain.
    // Recompute here for actionability (cheap).
    if (k >= 2) {
      const cols = likertVars.map(v => v.values.map(num).map(x => x == null ? 0 : x));
      const validRows = [];
      for (let i = 0; i < rowCount; i++) {
        if (!likertVars.some(v => isMissing(v.values[i]))) validRows.push(i);
      }
      if (validRows.length >= 3) {
        const validCols = cols.map(col => validRows.map(i => col[i]));
        let negativeCount = 0;
        for (let i = 0; i < k; i++) {
          const item = validCols[i];
          const rest = validRows.map((_, idx) =>
            validCols.reduce((s, col, j) => s + (j === i ? 0 : col[idx]), 0)
          );
          if (pearson(item, rest) < 0) negativeCount++;
        }
        if (negativeCount > 0) { pen += 2; flags.push(negativeCount + ' negatively-correlated item(s)'); }
      }
    }
    // Single-factor solution across 8+ items, more than 5 factors, prompts
    // shorter than 12 chars: require data we do not have in v1 (factor
    // count from FA tool, prompt text from survey builder). Skip for now.

    const raw = Math.max(0, MAX - pen);
    const score = clamp(round((raw / MAX) * 100), 0, 100);
    return {
      score, raw, max: MAX,
      note: (flags.length ? flags.length + ' design flag' + (flags.length === 1 ? '' : 's') : 'No design flags') + '  ·  ' + raw + ' / ' + MAX,
      interp: flags.length
        ? 'Design flags: ' + flags.join('; ') + '.'
        : 'Survey design is in good shape: item count appropriate, no negative item-total signals.',
      tone: score >= 80 ? 'ok' : score >= 60 ? 'warn' : 'alert',
    };
  }

  // Helper for compute* functions to return a blank domain when input is insufficient.
  function blankDomain(max, note, tone) {
    return { score: null, raw: 0, max, note, interp: note, tone };
  }

  // ---------- Composite + interpretation ----------
  // Single canonical entry. computeLensesFromDataset populates the IIFE
  // closure vars (likertVars, allVars, rowCount) via _resolveCtx and runs
  // the v2-mapped per-domain compute fns. The render path below reads
  // from the returned object; the legacy `components` shape is preserved
  // so the existing 6 component cards keep rendering until the studio
  // template is migrated to the canonical 8-domain taxonomy (separate
  // conversation per Spec §2).
  const lensResult = computeLensesFromDataset(dataset);
  const components = {
    reliability:      lensResult.domain_details.reliability,
    factor_structure: lensResult.domain_details.factor_readiness,
    item_quality:     lensResult.domain_details.item_prompt_quality,
    response_quality: lensResult.domain_details.response_scale_review,
    // Retired from lens math but still rendered as cards until template
    // migration. Recomputed off the ctx that _resolveCtx already set.
    open_ended:       computeOpenEnded(),
    actionability:    computeActionability(),
  };

  // Headline becomes the Respondent-Centered lens (Spec §3.4 default).
  // The three lens scores live on lensResult.rssi for surfaces that
  // want to render all of them.
  const strength = lensResult.rssi.respondent_centered == null
    ? 0
    : clamp(round(lensResult.rssi.respondent_centered), 0, 100);

  let verdict, note, tone;
  if      (strength >= 90) { verdict = 'Excellent';            tone = 'strong';  note = 'Strong enough to publish and act on.'; }
  else if (strength >= 80) { verdict = 'Strong';               tone = 'strong';  note = 'Strong enough to share findings.'; }
  else if (strength >= 70) { verdict = 'Usable';               tone = 'adequate';note = 'Usable; address the lowest domain before publishing.'; }
  else if (strength >= 60) { verdict = 'Needs strengthening';  tone = 'adequate';note = 'Strengthen the weakest domain before drawing firm conclusions.'; }
  else                     { verdict = 'Weak';                 tone = 'weak';    note = 'Address multiple domains before this evidence can support a decision.'; }

  const labelMap = {
    reliability:      'Internal Consistency & Scale Reliability',
    factor_structure: 'Factor Structure',
    item_quality:     'Item Quality',
    response_quality: 'Response Quality',
    open_ended:       'Open-Ended Alignment',
    actionability:    'Actionability',
  };
  let lowest = null;
  let highest = null;
  Object.keys(components).forEach(k => {
    const s = components[k].score;
    if (s == null) return;
    if (!lowest  || s < lowest.score)  lowest  = { key: k, score: s };
    if (!highest || s > highest.score) highest = { key: k, score: s };
  });

  // ---------- Render ----------
  const elScore       = document.getElementById('strengthScore');
  const elVerdict     = document.getElementById('strengthVerdict');
  const elVerdictNote = document.getElementById('strengthVerdictNote');
  const elGaugeArc    = document.getElementById('gaugeArc');
  const elGaugeDot    = document.getElementById('gaugeDot');
  const elInterpLead  = document.getElementById('interpLead');
  const elInterpFocus = document.getElementById('interpFocus');
  const elInterpBtn   = document.getElementById('interpFocusBtn');

  if (elScore) elScore.textContent = strength;
  if (elVerdict) {
    elVerdict.setAttribute('data-tone', tone);
    elVerdict.querySelector('.verdict-label').textContent = verdict;
  }
  if (elVerdictNote) elVerdictNote.textContent = note;

  // Animate the gauge arc: full perimeter ≈ 251 (matches stroke-dasharray in render).
  const GAUGE_FULL = 251;
  const dashOffset = GAUGE_FULL - (GAUGE_FULL * (strength / 100));
  setTimeout(() => {
    if (elGaugeArc) elGaugeArc.style.strokeDashoffset = String(dashOffset);
    // Approximate dot position along the arc
    if (elGaugeDot) {
      const t = Math.PI * (1 - strength / 100);
      const cx = 100 + 80 * Math.cos(t);
      const cy = 100 - 80 * Math.sin(t);
      elGaugeDot.setAttribute('cx', cx.toFixed(1));
      elGaugeDot.setAttribute('cy', cy.toFixed(1));
    }
  }, 80);

  // Fill component cards
  Object.keys(components).forEach(key => {
    const card = document.querySelector('.component-card[data-component="' + key + '"]');
    if (!card) return;
    const c = components[key];
    const scoreEl = card.querySelector('[data-fill="score"]');
    const noteEl  = card.querySelector('[data-fill="note"]');
    const barEl   = card.querySelector('[data-fill="bar"]');
    // Card label reads "/ <max>" (raw points). Render raw, not the 0–100 sub-score,
    // so "X / 25" matches the composite math (composite = Σ raw across the 6 domains).
    if (scoreEl) scoreEl.textContent = c.raw == null ? '—' : c.raw;
    if (noteEl)  noteEl.textContent  = c.note;
    if (c.tone)  card.setAttribute('data-tone', c.tone);
    setTimeout(() => {
      if (barEl) barEl.style.width = (c.score == null ? 0 : c.score) + '%';
    }, 100);
    if (lowest && lowest.key === key && c.score != null && c.score < 85) {
      card.setAttribute('data-flag', 'focus');
    }
  });

  // Interpretation
  if (elInterpLead && highest) {
    elInterpLead.innerHTML =
      'Your survey scores <strong>' + strength + '</strong> on the Strength Index. ' +
      'Most of the lift comes from <strong>' + labelMap[highest.key] + '</strong> (' + highest.score + '). ' +
      note;
  }
  if (elInterpFocus && lowest && lowest.score < 90) {
    elInterpFocus.innerHTML =
      'The one to look at is <strong>' + labelMap[lowest.key] + '</strong> (' + lowest.score + '). ' +
      explainLow(lowest.key, components[lowest.key]);
    if (elInterpBtn) {
      elInterpBtn.hidden = false;
      elInterpBtn.textContent = 'Open ' + labelMap[lowest.key] + ' panel';
    }
  }

  // When Reliability is computed cleanly (not flagged as the lowest),
  // surface its richer interpretation as a second paragraph so the
  // user always sees the α-ω + item-level story.
  if (elInterpFocus && components.reliability && components.reliability.interp && (!lowest || lowest.key !== 'reliability')) {
    const extra = document.createElement('p');
    extra.innerHTML = '<strong>' + labelMap.reliability + ':</strong> ' + components.reliability.interp;
    elInterpFocus.parentNode.insertBefore(extra, elInterpFocus.nextSibling || null);
  }

  // ============================================================
  // Diagnostic panels (per Instrument Quality spec):
  //   - Readiness label (Publishable / Research-Ready / Use with
  //     Caution / Not Ready)
  //   - Report status pill (Ready to use / Revise before reporting /
  //     Do not use for decisions yet)
  //   - "What To Fix First" — top 1-3 priorities pulled from each
  //     domain's flags, sorted by severity. Every priority carries
  //     Judgment → Evidence → Meaning → Action.
  //
  // These are ADDITIONS — the canonical Strength Index formula above
  // is untouched. They read from the same `components` object the
  // composite is built from, so cross-panel contradictions cannot
  // happen: every panel reads the same source of truth.
  // ============================================================
  function buildDiagnosticPanels() {
    // -- Readiness label per spec --
    let readiness, readinessTone;
    if      (strength >= 85) { readiness = 'Publishable';        readinessTone = 'strong';   }
    else if (strength >= 70) { readiness = 'Research-Ready';     readinessTone = 'adequate'; }
    else if (strength >= 55) { readiness = 'Use with Caution';   readinessTone = 'caution';  }
    else                     { readiness = 'Not Ready';          readinessTone = 'weak';     }

    let reportStatus, reportTone;
    if      (strength >= 85) { reportStatus = 'Ready to use';                       reportTone = 'strong';   }
    else if (strength >= 70) { reportStatus = 'Revise before reporting';            reportTone = 'adequate'; }
    else                     { reportStatus = 'Do not use for decisions yet';       reportTone = 'weak';     }

    // -- Build priorities. Each priority: {issue, evidence, meaning, action, severity, domain} --
    const priorities = [];

    // Reliability flags
    const rel = components.reliability;
    if (rel && rel.diagnostics) {
      const d = rel.diagnostics;
      if (Array.isArray(d.negativeItems) && d.negativeItems.length) {
        priorities.push({
          domain: 'Internal Consistency',
          severity: 'critical',
          issue: 'Negative item-rest correlations',
          evidence: d.negativeItems.length + ' item(s) show negative item-rest correlation: ' + d.negativeItems.slice(0, 5).join(', ') + (d.negativeItems.length > 5 ? ', …' : ''),
          meaning: 'Those items run opposite to the rest of their scale. The most common cause is reverse-coded items that were not rescored before reliability was computed; the next most common cause is items that measure a different construct than the scale name implies.',
          action: 'Confirm scoring direction for each flagged item. If reverse-coded, recode and recompute α. If correctly scored, consider removing the item or assigning it to a different scale.',
        });
      }
      if (d.lowInterItem != null && d.lowInterItem === true) {
        priorities.push({
          domain: 'Internal Consistency',
          severity: 'critical',
          issue: 'Average inter-item correlation below 0.30',
          evidence: 'Mean inter-item r = ' + (d.meanInterItem != null ? d.meanInterItem.toFixed(2) : '—'),
          meaning: 'Items in this scale do not converge on a single construct. α may still look acceptable due to scale length, but the construct is not unified.',
          action: 'Inspect the scale\'s item content and factor structure. Consider splitting the scale into sub-scales or revising items that share weak content overlap with the rest.',
        });
      }
      if (Array.isArray(d.lowItemRest) && d.lowItemRest.length) {
        priorities.push({
          domain: 'Internal Consistency',
          severity: 'watch',
          issue: 'Weak item-rest correlations',
          evidence: d.lowItemRest.length + ' item(s) with item-rest r < 0.20: ' + d.lowItemRest.slice(0, 4).join(', ') + (d.lowItemRest.length > 4 ? ', …' : ''),
          meaning: 'These items add little to the scale\'s signal. They may be measuring something tangential to the construct, or they may be unclearly worded.',
          action: 'Review wording. Test "alpha if item deleted" — if α rises when the item is removed, revise or drop it.',
        });
      }
    }

    // Factor structure flags
    const fs = components.factor_structure;
    if (fs && fs.diagnostics) {
      const d = fs.diagnostics;
      if (d.kmo != null && d.kmo < 0.6) {
        priorities.push({
          domain: 'Factor Structure',
          severity: 'critical',
          issue: 'KMO sampling adequacy below 0.60',
          evidence: 'KMO = ' + d.kmo.toFixed(2),
          meaning: 'The data are not suitable for factor analysis. Items do not share enough variance for clean factors to emerge.',
          action: 'Increase sample size or revise items that load weakly with the rest. Do not run EFA on this data until KMO ≥ 0.60.',
        });
      }
      if (Array.isArray(d.crossLoadings) && d.crossLoadings.length) {
        priorities.push({
          domain: 'Factor Structure',
          severity: 'watch',
          issue: 'Items with cross-loadings',
          evidence: d.crossLoadings.length + ' item(s) load on more than one factor: ' + d.crossLoadings.slice(0, 4).join(', ') + (d.crossLoadings.length > 4 ? ', …' : ''),
          meaning: 'These items don\'t cleanly belong to one factor. Either the scale conflates two constructs, or the items need rewording.',
          action: 'Reword cross-loading items so each targets one construct. If wording is fine, consider whether the scale should be two sub-scales.',
        });
      }
    }

    // Item Quality flags
    const iq = components.item_quality;
    if (iq && iq.diagnostics) {
      const d = iq.diagnostics;
      if (Array.isArray(d.ceilingItems) && d.ceilingItems.length) {
        priorities.push({
          domain: 'Item Quality',
          severity: 'watch',
          issue: 'Ceiling effects',
          evidence: d.ceilingItems.length + ' item(s) with ≥70% at the top of the scale: ' + d.ceilingItems.slice(0, 4).join(', ') + (d.ceilingItems.length > 4 ? ', …' : ''),
          meaning: 'These items can\'t discriminate among high scorers — everyone agrees. The item is either too easy or worded so positively that disagreement is socially costly.',
          action: 'Raise the bar of the item or add a more demanding endpoint. Useful for highlighting variation, especially across groups.',
        });
      }
      if (Array.isArray(d.floorItems) && d.floorItems.length) {
        priorities.push({
          domain: 'Item Quality',
          severity: 'watch',
          issue: 'Floor effects',
          evidence: d.floorItems.length + ' item(s) with ≥70% at the bottom of the scale: ' + d.floorItems.slice(0, 4).join(', ') + (d.floorItems.length > 4 ? ', …' : ''),
          meaning: 'These items can\'t discriminate among low scorers. Either the item is too hard or so negative that respondents won\'t disagree.',
          action: 'Soften the wording or add a less extreme endpoint so the item separates low responders.',
        });
      }
    }

    // Response Quality flags
    const rq = components.response_quality;
    if (rq && rq.diagnostics) {
      const d = rq.diagnostics;
      if (d.completionPct != null && d.completionPct < 0.7) {
        priorities.push({
          domain: 'Response Quality',
          severity: 'critical',
          issue: 'Completion rate below 70%',
          evidence: 'Completion = ' + Math.round(d.completionPct * 100) + '%',
          meaning: 'A large share of respondents started but didn\'t finish. Anything you compute from this data is biased toward those who completed.',
          action: 'Investigate where respondents drop off (often a confusing item or a long open-ended). Shorten the survey or split into two waves.',
        });
      }
      if (d.straightLinerPct != null && d.straightLinerPct > 0.05) {
        priorities.push({
          domain: 'Response Quality',
          severity: 'watch',
          issue: 'Straight-lining detected',
          evidence: Math.round(d.straightLinerPct * 100) + '% of respondents straight-lined a scale',
          meaning: 'Respondents picked the same answer for every item in a block — engagement was low. These responses inflate α artificially and dilute group differences.',
          action: 'Filter out straight-liners before re-running reliability and group comparisons. Long-term, shorten the scale or vary item direction.',
        });
      }
    }

    // Sort: critical > watch > minor; then by domain importance
    const sevRank = { critical: 0, watch: 1, minor: 2 };
    priorities.sort((a, b) => (sevRank[a.severity] ?? 9) - (sevRank[b.severity] ?? 9));

    // -- Render Readiness + Report Status bar --
    const readyEl = document.getElementById('strengthReadiness');
    if (readyEl) {
      readyEl.removeAttribute('hidden');
      readyEl.setAttribute('data-tone', readinessTone);
      readyEl.querySelector('.readiness-label').textContent = readiness;
      readyEl.querySelector('.readiness-judgment').textContent = note;
    }
    const reportEl = document.getElementById('strengthReportStatus');
    if (reportEl) {
      reportEl.removeAttribute('hidden');
      reportEl.setAttribute('data-tone', reportTone);
      reportEl.querySelector('.report-status-label').textContent = reportStatus;
    }

    // -- Render What To Fix First (top 3) --
    const fixHost = document.getElementById('strengthFixFirst');
    if (fixHost) {
      const top = priorities.slice(0, 3);
      if (!top.length) {
        fixHost.innerHTML =
          '<div class="fix-empty">' +
            '<strong>No critical fixes identified.</strong> Every domain is at or above its acceptable threshold. ' +
            'Read the domain explainers below for context before publishing or sharing.' +
          '</div>';
      } else {
        const sevLabel = { critical: 'Critical', watch: 'Watch', minor: 'Minor' };
        fixHost.innerHTML =
          '<h3 class="fix-head">What to fix first</h3>' +
          '<p class="fix-sub">Top ' + top.length + ' issue' + (top.length === 1 ? '' : 's') + ' across all domains, sorted by severity. Each carries the evidence, what it means, and the recommended action.</p>' +
          '<div class="fix-list">' +
            top.map((p, i) => (
              '<article class="fix-row" data-sev="' + p.severity + '">' +
                '<div class="fix-rank">' + (i + 1) + '</div>' +
                '<div class="fix-body">' +
                  '<div class="fix-row-head">' +
                    '<span class="fix-sev">' + sevLabel[p.severity] + '</span>' +
                    '<span class="fix-domain">' + p.domain + '</span>' +
                  '</div>' +
                  '<h4 class="fix-issue">' + p.issue + '</h4>' +
                  '<div class="fix-jema">' +
                    '<div><strong>Evidence.</strong> ' + p.evidence + '</div>' +
                    '<div><strong>Meaning.</strong> ' + p.meaning + '</div>' +
                    '<div><strong>Action.</strong> ' + p.action + '</div>' +
                  '</div>' +
                '</div>' +
              '</article>'
            )).join('') +
          '</div>';
      }
    }

    // -- Expose to window so report builder / save-to-report can use --
    window.RELICHECK_INSTRUMENT_DIAGNOSTICS = {
      strength: strength,
      verdict: verdict,
      readiness: readiness,
      reportStatus: reportStatus,
      domains: components,
      priorities: priorities,
      // Three-lens RSSI output (Spec §3.2–3.3) — same numbers the
      // standalone surface produces from the same dataset.
      rssi: lensResult.rssi,
      domain_subscores: lensResult.domain_subscores,
      skipped_domains: lensResult.skipped_domains,
      rssi_weights_version: lensResult.rssi_weights_version,
    };
    if (window.RELICHECK_APP_STATE) {
      window.RELICHECK_APP_STATE.readiness = readiness;
      window.RELICHECK_APP_STATE.reportStatus = reportStatus;
      window.RELICHECK_APP_STATE.priorities = priorities;
    }
  }
  buildDiagnosticPanels();

  // ---------- Render the rich Reliability detail (4 brand-required parts) ----------
  function renderReliabilityDetail(reliability) {
    const host = document.getElementById('reliabilityDetailBody');
    if (!host) return;

    const d = reliability && reliability.diagnostics;
    if (!d) {
      host.innerHTML = '<div class="reliability-detail-loading">Reliability detail unavailable (no scale data).</div>';
      return;
    }

    const ss = d.scaleSummary;
    function fmt(x, dig) { return x == null ? '—' : Number(x).toFixed(dig != null ? dig : 2); }
    function pct(x)      { return x == null ? '—' : Math.round(x * 100) + '%'; }

    // Part 1: Scale-level summary
    const part1 =
      '<div class="rdt-section">' +
        '<div class="rdt-section-head">' +
          '<h4>Scale-level reliability summary</h4>' +
          '<span class="rdt-eyebrow">Part 1 of 4</span>' +
        '</div>' +
        '<div class="scale-summary">' +
          stat('Scale name', ss.name, 'stat-name', false) +
          stat('Items', ss.itemCount) +
          stat('Valid responses', ss.validResponses) +
          stat("Cronbach's α", fmt(ss.alpha)) +
          stat("McDonald's ω", fmt(ss.omega)) +
          stat('Avg inter-item r', fmt(ss.avgInterItem)) +
          stat('Mean scale score', fmt(ss.meanScale, 2)) +
          stat('Standard deviation', fmt(ss.sdScale, 2)) +
          stat('Missing data rate', pct(ss.missingRate)) +
          statRating('Reliability rating', ss.rating, ss.ratingTone) +
        '</div>' +
      '</div>';

    // Part 2: Item-level diagnostic table
    const itemRows = d.itemDiagnostics.map(it => itemRow(it)).join('');
    const part2 =
      '<div class="rdt-section">' +
        '<div class="rdt-section-head">' +
          '<h4>Item-level diagnostics</h4>' +
          '<span class="rdt-eyebrow">Part 2 of 4</span>' +
        '</div>' +
        '<div class="item-diag">' +
          '<div class="item-diag-row head">' +
            '<div class="c">Item ID</div>' +
            '<div class="c">Item text</div>' +
            '<div class="c num">Mean</div>' +
            '<div class="c num">SD</div>' +
            '<div class="c num">Missing</div>' +
            '<div class="c num">Item-rest r</div>' +
            '<div class="c num">α if dropped</div>' +
            '<div class="c num">ω impact</div>' +
            '<div class="c">Flag</div>' +
            '<div class="c">Interpretation</div>' +
          '</div>' +
          itemRows +
        '</div>' +
        '<p style="font-size:12px;color:var(--ink-5);margin:8px 0 0;">Item text comes from the Survey Builder when prompts are tagged on individual items; until then the variable name stands in.</p>' +
      '</div>';

    // Part 3: Score contribution out of 25
    const contribRows = d.contribBreakdown.map(c =>
      '<div class="lbl">' + escapeHtml(c.label) + '</div>' +
      '<div class="pts">' + c.pts + '</div>' +
      '<div class="sep">/</div>' +
      '<div class="max">' + c.max + '</div>'
    ).join('');
    const totalPts = d.contribBreakdown.reduce((s, c) => s + c.pts, 0);
    const part3 =
      '<div class="rdt-section">' +
        '<div class="rdt-section-head">' +
          '<h4>Reliability score contribution (out of 25)</h4>' +
          '<span class="rdt-eyebrow">Part 3 of 4</span>' +
        '</div>' +
        '<div class="contrib-list">' + contribRows +
          '<div class="row-total">' +
            '<div class="lbl">Domain total</div>' +
            '<div class="pts">' + (Math.round(totalPts * 10) / 10) + '</div>' +
            '<div class="sep">/</div>' +
            '<div class="max">25</div>' +
          '</div>' +
        '</div>' +
      '</div>';

    // Part 4: User-facing interpretation
    const interpParas = d.fullInterp.map(p => '<p>' + p + '</p>').join('');
    const part4 =
      '<div class="rdt-section">' +
        '<div class="rdt-section-head">' +
          '<h4>What this means</h4>' +
          '<span class="rdt-eyebrow">Part 4 of 4</span>' +
        '</div>' +
        '<div class="rdt-interp">' + interpParas + '</div>' +
      '</div>';

    host.innerHTML = part1 + part2 + part3 + part4;

    function stat(label, value, extraClass, monoNumeric) {
      return '<div class="stat ' + (extraClass || '') + '">' +
        '<label>' + escapeHtml(label) + '</label>' +
        '<span class="v">' + escapeHtml(String(value == null ? '—' : value)) + '</span>' +
      '</div>';
    }
    function statRating(label, value, tone) {
      return '<div class="stat">' +
        '<label>' + escapeHtml(label) + '</label>' +
        '<span class="v rating" data-tone="' + escapeHtml(tone || '') + '">' + escapeHtml(value) + '</span>' +
      '</div>';
    }
    function itemRow(it) {
      const meanS    = it.mean == null ? '—' : it.mean.toFixed(2);
      const sdS      = it.sd   == null ? '—' : it.sd.toFixed(2);
      const missS    = it.missingRate == null ? '—' : (Math.round(it.missingRate * 100) + '%');
      const restS    = it.itemRest == null ? '—' : it.itemRest.toFixed(2);
      const alphaS   = it.alphaIfDropped == null ? '—' : it.alphaIfDropped.toFixed(2);
      let omegaS;
      if (it.omegaIfDropped == null) {
        omegaS = '<span class="dim">—</span>';
      } else {
        omegaS = it.omegaIfDropped.toFixed(2);
      }
      return '<div class="item-diag-row">' +
        '<div class="c mono">' + escapeHtml(it.name) + '</div>' +
        '<div class="c dim">' + escapeHtml(it.text || '—') + '</div>' +
        '<div class="c num">' + meanS + '</div>' +
        '<div class="c num">' + sdS + '</div>' +
        '<div class="c num">' + missS + '</div>' +
        '<div class="c num">' + restS + '</div>' +
        '<div class="c num">' + alphaS + '</div>' +
        '<div class="c num">' + omegaS + '</div>' +
        '<div class="c"><span class="item-flag" data-tone="' + escapeHtml(it.flagTone) + '"><span class="pip" aria-hidden="true"></span>' + escapeHtml(it.flagLabel) + '</span></div>' +
        '<div class="c interp-cell">' + escapeHtml(it.interp) + '</div>' +
      '</div>';
    }
  }
  renderReliabilityDetail(components.reliability);

  // ---------- Expose state for Save-to-report ----------
  // Universal contract: every analysis app sets window.RELICHECK_APP_STATE
  // after its computation completes so the studio chrome's Save button
  // can snapshot what's currently on screen.
  window.RELICHECK_APP_STATE = {
    app_key:    'strength_index',
    app_name:   'ReliCheck Strength Index',
    summary:    'Strength score ' + strength + ' (' + verdict + '). ' + note,
    dataset:    {
      source:    dataset.source || '',
      rowCount:  rowCount,
      fromUpload: window.STRENGTH_DATASET_SOURCE === 'uploaded',
    },
    strength:   strength,
    verdict:    verdict,
    components: components,
    // Three-lens RSSI output (Spec §3.2–3.3, §6 single source of truth).
    rssi:                 lensResult.rssi,
    domain_subscores:     lensResult.domain_subscores,
    skipped_domains:      lensResult.skipped_domains,
    rssi_weights_version: lensResult.rssi_weights_version,
    computed_at: new Date().toISOString(),
  };

  function explainLow(key, c) {
    if (c && c.interp) return c.interp;
    switch (key) {
      case 'reliability':
        return 'Items are not hanging together as strongly as you would expect. Inspect inter-item correlations and consider dropping or rewording an outlier item.';
      case 'factor_structure':
        return 'The factor solution is weak. KMO suggests the items may not share enough variance for a clean single-factor model.';
      case 'item_quality':
        return 'One or more items show ceiling/floor, low variance, or high missingness. Inspect the item-quality flags.';
      case 'open_ended':
        return 'The open-ended items have low response volume or short responses. Consider whether prompts are clear.';
      case 'actionability':
        return 'The survey design has structural flags (item count, weak reverse items, or factor count). Review before publishing.';
      case 'validity':
        return 'Items correlate, but the factor structure may not be clean. Run the Validity panel to see which item is cross-loading.';
      case 'response_quality':
        return 'A meaningful share of respondents straight-lined the Likert items, or the sample is small. Consider attention checks for the next wave.';
      default:
        return 'Open the panel to see why.';
    }
  }
})();
