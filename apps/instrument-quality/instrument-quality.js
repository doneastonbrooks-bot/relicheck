// ReliCheck Instrument Quality Detail Suite
// -------------------------------------------------------------------
// Six lenses on the instrument's quality:
//   validity         factor structure + α/ω per inferred construct
//   item_quality     detailed per-item table with all diagnostic flags
//   scale_structure  cluster Likert items by inter-item correlation
//   factor_readiness KMO + per-item MSA + Bartlett's test of sphericity
//   bias_clarity     rule-based variable-name analysis + AI hook
//   response_scale   per-item response-option use, midpoint, balance

(function () {
  'use strict';

  /* ────────────────────────────────────────────────────────────
   *  PUBLIC API — registered FIRST, before any DOM access, so
   *  it's always available even when this script loads on a
   *  page that doesn't have the engine's full DOM (e.g. RSSI).
   *  Pure compute helpers — no DOM, no closures over engine state.
   * ──────────────────────────────────────────────────────────── */
  (function registerPublicAPI() {
    function _completeCases(itemArrays) {
      if (!itemArrays.length) return { validCols: [], validRows: [] };
      const n = itemArrays[0].length;
      const validRows = [];
      for (let i = 0; i < n; i++) {
        let ok = true;
        for (let j = 0; j < itemArrays.length; j++) {
          if (itemArrays[j][i] == null || isNaN(itemArrays[j][i])) { ok = false; break; }
        }
        if (ok) validRows.push(i);
      }
      const validCols = itemArrays.map(function (col) { return validRows.map(function (idx) { return col[idx]; }); });
      return { validCols: validCols, validRows: validRows };
    }
    function _pearson(a, b) {
      const n = Math.min(a.length, b.length);
      if (n < 2) return 0;
      let ma = 0, mb = 0;
      for (let i = 0; i < n; i++) { ma += a[i]; mb += b[i]; }
      ma /= n; mb /= n;
      let cov = 0, va = 0, vb = 0;
      for (let i = 0; i < n; i++) {
        cov += (a[i] - ma) * (b[i] - mb);
        va  += (a[i] - ma) * (a[i] - ma);
        vb  += (b[i] - mb) * (b[i] - mb);
      }
      const denom = Math.sqrt(va * vb);
      return denom === 0 ? 0 : cov / denom;
    }
    function _variance(arr) {
      if (arr.length < 2) return 0;
      const m = arr.reduce(function (s, v) { return s + v; }, 0) / arr.length;
      return arr.reduce(function (s, v) { return s + (v - m) * (v - m); }, 0) / (arr.length - 1);
    }
    /* FULL Construct Alignment narrative — mirrors what renderConstructAlignment
       in the studio engine produces. Returns every block the studio template
       displays: status, summary, plain + research paragraphs, candidate
       clusters with member items, the 6-row evidence table, closing
       interpretation, J/E/M/A footer, and the recommended-actions list. */
    function _constructAlignmentNarrative(likertItems) {
      if (!Array.isArray(likertItems) || likertItems.length < 2) {
        return { error: 'Need at least 2 Likert items.' };
      }
      const itemArrays = likertItems.map(function (it) {
        return (it.values || []).map(function (v) {
          const x = parseFloat(v); return isNaN(x) ? null : x;
        });
      });
      const cc = _completeCases(itemArrays);
      if (cc.validRows.length < 3) return { error: 'Need 3+ complete responses across all items.' };
      const validCols = cc.validCols;
      const validRows = cc.validRows;
      const k = likertItems.length;

      const scaleLabel = {
        SA: 'Self-Awareness', SM: 'Self-Management', SO: 'Social Awareness', RM: 'Relationship Management',
        EI: 'Emotional Intelligence', ER: 'Emotional Regulation', EE: 'Engagement',
      };
      function inferScale(name) {
        const base = String(name || '').replace(/_R$/i, '');
        const m = base.match(/^([A-Za-z]+)/);
        if (!m) return null;
        const pref = m[1].toUpperCase();
        return scaleLabel[pref] || pref;
      }
      function isReverse(name) { return /_R$/i.test(String(name || '')); }

      // Item-rest correlations against the full pooled total
      const itemRest = likertItems.map(function (_, i) {
        const item = validCols[i];
        const rest = validRows.map(function (_, idx) {
          let s = 0;
          for (let j = 0; j < validCols.length; j++) if (j !== i) s += validCols[j][idx];
          return s;
        });
        return _pearson(item, rest);
      });

      // Two-cluster split: positively vs negatively correlated with total
      const cluster1Idx = [], cluster2Idx = [];
      likertItems.forEach(function (_, i) {
        if (itemRest[i] < 0) cluster2Idx.push(i);
        else cluster1Idx.push(i);
      });

      // α and avg r per cluster
      function clusterStats(idxList) {
        if (idxList.length < 2) return { alpha: null, avgR: null, k: idxList.length };
        const cols = idxList.map(function (i) { return validCols[i]; });
        const km = cols.length;
        const itemVar = cols.reduce(function (s, c) { return s + _variance(c); }, 0);
        const totals  = validRows.map(function (_, ri) { return cols.reduce(function (s, c) { return s + c[ri]; }, 0); });
        const totalVar = _variance(totals);
        const alpha = totalVar ? (km / (km - 1)) * (1 - itemVar / totalVar) : 0;
        let aR = 0, p = 0;
        for (let i = 0; i < km; i++) for (let j = i + 1; j < km; j++) { aR += _pearson(cols[i], cols[j]); p++; }
        aR /= p || 1;
        return { alpha: alpha, avgR: aR, k: km };
      }
      const c1Stats = clusterStats(cluster1Idx);
      const c2Stats = clusterStats(cluster2Idx);

      const intendedConstructs = Array.from(new Set(likertItems.map(function (v) { return inferScale(v.name); }).filter(Boolean)));
      const c2Names      = cluster2Idx.map(function (i) { return likertItems[i].name; });
      const c2Constructs = Array.from(new Set(c2Names.map(inferScale).filter(Boolean)));
      const c2RevShare   = c2Names.length ? (c2Names.filter(isReverse).length / c2Names.length) : 0;
      const methodEffect = cluster2Idx.length >= 2 && (c2RevShare >= 0.8 || c2Constructs.length >= 2);
      const c1Names      = cluster1Idx.map(function (i) { return likertItems[i].name; });
      const c1Constructs = Array.from(new Set(c1Names.map(inferScale).filter(Boolean)));
      const c1Broad      = cluster1Idx.length >= 2 && c1Constructs.length >= 2;
      const detectedClusters = (cluster1Idx.length >= 2 ? 1 : 0) + (cluster2Idx.length >= 2 ? 1 : 0);

      let status, statusTone;
      if (methodEffect)                                                                  { status = 'Method-effect warning'; statusTone = 'alert'; }
      else if (detectedClusters === intendedConstructs.length && c1Constructs.length === 1) { status = 'Strong alignment';     statusTone = 'strong'; }
      else if (cluster2Idx.length === 0 && intendedConstructs.length === 1)               { status = 'Mostly aligned';       statusTone = 'ok'; }
      else if (cluster2Idx.length === 0)                                                  { status = 'Mixed alignment';      statusTone = 'warn'; }
      else                                                                                { status = 'Not aligned';          statusTone = 'alert'; }

      const headlineInterp = methodEffect
        ? 'The detected clusters do not cleanly match the intended scale names. Items appear to separate by wording/scoring direction, especially the reverse-coded items, rather than by the ' +
          (intendedConstructs.length >= 2 ? intendedConstructs.length + ' intended ' + (intendedConstructs.length === 4 ? 'emotional-intelligence ' : '') + 'domains.' : 'intended construct structure.')
        : status === 'Strong alignment' ? 'Empirical clustering reproduces the intended construct structure.'
        : status === 'Mostly aligned'   ? 'Items group as expected, with minor cross-construct overlap.'
        :                                 'Items cluster in patterns that do not clearly match the named constructs.';

      const plain = methodEffect
        ? 'The survey appears to be grouping items by how they are worded rather than by what they are supposed to measure. Most regular items group together, while the reverse-coded items form their own group. This may mean the reverse-coded items were not scored correctly, or that respondents answered negatively worded items differently from positively worded ones.'
        : status === 'Strong alignment'
          ? 'Items group together along the lines you would expect from their scale names. The structure is consistent with the intended design.'
          : 'Items are not splitting cleanly by their named constructs. Some items from different constructs are clustering together; others appear to belong to a single broad group.';

      const research = methodEffect
        ? 'The detected two-cluster solution does not map cleanly onto the intended ' +
          intendedConstructs.length + '-domain structure. Candidate Scale 2 consists ' +
          (c2RevShare >= 0.8 ? 'entirely or nearly entirely ' : 'largely ') +
          'of reverse-coded items drawn from multiple constructs, suggesting a possible wording-method factor, reverse-scoring issue, or response-style artifact. Construct alignment should be evaluated after reverse-coded items are rescored and subscale-level factor / reliability checks are conducted.'
        : 'Empirical clustering ' +
          (status === 'Strong alignment' ? 'reproduces the intended structure.' : 'does not fully reproduce the named subdomain map. Cross-construct co-clustering may indicate a higher-order general factor, weak subdomain separation, or item-level construct misfit.');

      // Candidate clusters with items + spans
      function clusterCardData(idxList, n, badge, stats) {
        if (idxList.length < 1) return null;
        const names = idxList.map(function (i) { return likertItems[i].name; });
        const constructs = Array.from(new Set(names.map(inferScale).filter(Boolean)));
        return {
          number: n,
          badge:  badge,
          itemCount: idxList.length,
          alpha:  stats ? stats.alpha : null,
          avgR:   stats ? stats.avgR  : null,
          items:  names.map(function (name) { return { name: name, construct: inferScale(name) }; }),
          spans:  constructs,
        };
      }
      const candidateClusters = [];
      const c1Card = clusterCardData(cluster1Idx, 1,
        c1Broad ? { label: 'Broad general factor', tone: 'warn' } : { label: 'Construct-coherent', tone: 'ok' },
        c1Stats);
      if (c1Card) candidateClusters.push(c1Card);
      if (cluster2Idx.length) {
        const c2Card = clusterCardData(cluster2Idx, 2,
          methodEffect
            ? { label: 'Reverse-coded method cluster', tone: 'alert' }
            : { label: 'Negative-direction items',     tone: 'warn'  },
          c2Stats);
        if (c2Card) candidateClusters.push(c2Card);
      }

      // 6-row evidence table
      const c2List = c2Names.length ? c2Names.join(', ') : 'none detected';
      const evidenceRows = [
        {
          check: 'Number of detected clusters',
          evidence: detectedClusters + ' candidate ' + (detectedClusters === 1 ? 'cluster' : 'clusters') + ' detected',
          status: detectedClusters === intendedConstructs.length ? 'Strong' : 'Watch',
          tone:   detectedClusters === intendedConstructs.length ? 'strong' : 'warn',
          plain:  'The data naturally split into ' + detectedClusters + ' ' + (detectedClusters === 1 ? 'group' : 'groups') +
                  (intendedConstructs.length && detectedClusters !== intendedConstructs.length
                    ? ', but those groups do not match the expected ' + intendedConstructs.length + ' constructs.' : '.'),
          research: detectedClusters === intendedConstructs.length
            ? 'Empirical and theoretical structure agree on cluster count.'
            : 'The empirical clustering does not reproduce the intended ' + intendedConstructs.length + '-domain structure.',
          action: 'Compare detected clusters against the theoretical scale map.',
        },
        {
          check: 'Intended construct match',
          evidence: intendedConstructs.length
            ? 'Intended constructs appear to be ' + intendedConstructs.join(', ')
            : 'No construct names could be inferred from item naming.',
          status: c1Broad ? 'Watch' : 'Acceptable',
          tone:   c1Broad ? 'warn'  : 'ok',
          plain: c1Broad
            ? 'Items from different intended constructs are being grouped together.'
            : 'Items in each cluster appear to share a construct.',
          research: c1Broad
            ? 'Cross-construct clustering may indicate a higher-order factor, weak construct separation, or scoring-method artifact.'
            : 'Within-cluster construct homogeneity supports the intended structure.',
          action: 'Test reliability and factor structure separately for each intended subscale.',
        },
        {
          check: 'Reverse-coded item cluster',
          evidence: methodEffect
            ? 'Candidate Scale 2 contains ' + c2List
            : (cluster2Idx.length
                ? cluster2Idx.length + ' item(s) with negative item-rest: ' + c2List
                : 'No items grouping by reverse-coded direction.'),
          status: methodEffect ? 'Problem' : (cluster2Idx.length ? 'Watch' : 'Strong'),
          tone:   methodEffect ? 'alert'   : (cluster2Idx.length ? 'warn'  : 'strong'),
          plain: methodEffect
            ? 'The reverse-coded items are grouping together even though they belong to different constructs.'
            : (cluster2Idx.length
                ? 'A few items move opposite the rest but do not form a clean cluster.'
                : 'No reverse-direction clustering detected.'),
          research: methodEffect
            ? 'This pattern suggests a possible wording-method factor, reverse-scoring error, or response-style artifact.'
            : (cluster2Idx.length
                ? 'Isolated negative item-rest correlations without method clustering usually trace to individual item issues.'
                : 'No method-effect pattern detected.'),
          action: methodEffect
            ? 'Confirm reverse-scoring, rerun construct alignment, and inspect item wording.'
            : (cluster2Idx.length
                ? 'Inspect each negative-direction item individually.'
                : 'No action required.'),
        },
        {
          check: 'Candidate Scale 1 composition',
          evidence: cluster1Idx.length
            ? 'Candidate Scale 1 contains ' + cluster1Idx.length + ' item' + (cluster1Idx.length === 1 ? '' : 's') +
              (c1Constructs.length > 1 ? ' across ' + c1Constructs.join(', ') : c1Constructs.length === 1 ? ' from ' + c1Constructs[0] : '')
            : 'Cluster 1 is empty.',
          status: c1Broad ? 'Mixed' : 'Acceptable',
          tone:   c1Broad ? 'warn'  : 'ok',
          plain: c1Broad
            ? 'Most regular items are moving together, but they are not separating clearly by named construct.'
            : 'The items in Cluster 1 share a single named construct.',
          research: c1Broad
            ? 'The items may reflect a broad general factor rather than distinct subdomains.'
            : 'Cluster 1 is consistent with a single-construct interpretation.',
          action: c1Broad
            ? 'Decide whether the instrument should report a total score, subscale scores, or both.'
            : 'Continue with subscale-level reporting.',
        },
        {
          check: 'Candidate Scale 2 reliability',
          evidence: c2Stats.k >= 2
            ? 'Candidate Scale 2 has ' + c2Stats.k + ' items, α = ' + (c2Stats.alpha != null ? c2Stats.alpha.toFixed(2) : '—') + ', avg r = ' + (c2Stats.avgR != null ? c2Stats.avgR.toFixed(2) : '—')
            : 'Candidate Scale 2 has too few items to compute α.',
          status: c2Stats.k >= 2 ? 'Caution' : 'N/A',
          tone:   c2Stats.k >= 2 ? 'warn'    : 'ok',
          plain: c2Stats.k >= 2
            ? 'These items are consistent with each other, but that does not mean they form a meaningful construct.'
            : 'Not enough items in this cluster to evaluate.',
          research: c2Stats.k >= 2
            ? 'Internal consistency among reverse-coded items may reflect shared wording direction rather than substantive construct coherence.'
            : 'No statistical interpretation possible.',
          action: c2Stats.k >= 2
            ? 'Do not name this as a real scale until wording/scoring effects are ruled out.'
            : 'No action required.',
        },
        {
          check: 'Construct naming readiness',
          evidence: methodEffect || c1Broad
            ? 'Detected clusters do not correspond cleanly to named constructs.'
            : 'Detected clusters align with named constructs.',
          status: methodEffect ? 'Not ready' : (c1Broad ? 'Watch' : 'Ready'),
          tone:   methodEffect ? 'alert'      : (c1Broad ? 'warn'  : 'strong'),
          plain: methodEffect || c1Broad
            ? 'ReliCheck should not automatically name these as final scales.'
            : 'These clusters can be reported under their named scales.',
          research: 'Empirical clusters require theoretical confirmation before being interpreted as latent constructs.',
          action: methodEffect || c1Broad
            ? 'Label them as "candidate clusters," not confirmed scales.'
            : 'Naming as confirmed scales is supported by the structure.',
        },
      ];

      const closingPlain = methodEffect
        ? 'The instrument does show structure, but the structure is not yet conceptually clean. The strongest warning is that the reverse-coded items form their own cluster across multiple intended constructs. That usually means the cluster may be caused by scoring direction, item wording, or respondent confusion rather than a true second construct. Before reporting construct-level scores, confirm reverse-coding and rerun the alignment analysis.'
        : status === 'Strong alignment'
          ? 'Empirical structure agrees with the intended design. Items behave as their scale names suggest.'
          : 'The structure is partially aligned with the intended design. Treat the detected clusters as provisional.';

      const closingResearch = methodEffect
        ? 'The two-cluster solution may indicate a general factor plus a wording-method factor. Because Candidate Scale 2 consists ' +
          (c2RevShare >= 0.8 ? 'entirely ' : 'largely ') +
          'of reverse-coded items, it should not be interpreted as a substantive latent construct without additional evidence. Recommended next steps are reverse-scoring verification, subscale reliability, exploratory factor analysis, and confirmatory testing against the intended ' + intendedConstructs.length + '-factor model if sample size and design allow.'
        : 'Confirmatory factor analysis against the intended factor model is the appropriate next step, with subscale-level reliability and convergent / discriminant validity checks.';

      const recs = [];
      if (methodEffect) recs.push('Confirm all _R items were reverse-scored correctly.');
      if (methodEffect) recs.push('Rerun construct alignment after scoring corrections.');
      if (intendedConstructs.length > 1) recs.push('Test each intended construct separately: ' + intendedConstructs.join(', ') + '.');
      if (methodEffect) recs.push('Do not automatically treat Candidate Scale 2 as a real construct.');
      recs.push('Report the detected clusters as provisional until factor analysis confirms them.');
      recs.push('If item wording is available, inspect whether reverse-coded items are confusing, double-negative, or conceptually different.');
      if (c1Broad || methodEffect) recs.push('Decide whether the instrument supports one general score, subscale scores, or needs revision.');

      const jema = {
        judgment: status === 'Method-effect warning' ? headlineInterp : 'Alignment status: ' + status + '. ' + headlineInterp,
        evidence: detectedClusters + ' detected cluster(s) vs ' + intendedConstructs.length + ' intended construct(s)' +
          (cluster2Idx.length ? '; cluster 2 contains ' + cluster2Idx.length + ' item(s): ' + c2List : '') +
          (methodEffect ? '; ' + Math.round(c2RevShare * 100) + '% of cluster 2 items are reverse-coded' : '') + '.',
        meaning: closingResearch,
        action: recs[0] || 'No structural correction required.',
      };

      return {
        status: status, statusTone: statusTone,
        headlineInterp: headlineInterp,
        plain: plain,
        research: research,
        action: jema.action,
        detectedClusters: detectedClusters,
        intendedConstructs: intendedConstructs,
        intendedConstructsCount: intendedConstructs.length,
        methodEffect: methodEffect,
        cluster1Count: cluster1Idx.length,
        cluster2Count: cluster2Idx.length,
        itemCount: k,
        completeResponses: validRows.length,
        candidateClusters: candidateClusters,
        evidenceRows: evidenceRows,
        closingPlain: closingPlain,
        closingResearch: closingResearch,
        recommendedActions: recs,
        jema: jema,
      };
    }
    window.IQ_ENGINE = window.IQ_ENGINE || {};
    window.IQ_ENGINE.constructAlignmentNarrative = _constructAlignmentNarrative;
  })();

  // Stop here when running on a page that doesn't have the engine's DOM
  // (e.g. RSSI loads this file solely for window.IQ_ENGINE). The full
  // render pipeline below only makes sense when the studio mount provides
  // .iq-source / #iqEmpty / .iq-lens markup.
  if (!document.getElementById('iqEmpty')) return;

  // ── Dignity / Framing Readiness is a PRE-DATA validity subdomain: it
  //    reviews the instrument's item text against AI-proposed, human-settled
  //    flags and scores deterministically via window.DignityEngine. It never
  //    reads the response dataset, so short-circuit here, before the dataset
  //    guard, so it renders even when the survey has no responses yet. ──
  if ((window.IQ_LENS || '') === 'dignity_framing') {
    document.querySelectorAll('.iq-lens').forEach(function (el) {
      el.hidden = el.getAttribute('data-lens') !== 'dignity_framing';
    });
    var _dfSrc = document.getElementById('iqSource');
    if (_dfSrc) { _dfSrc.hidden = true; _dfSrc.style.display = 'none'; }
    renderDignityFraming();
    return;
  }

  // ── Access Readiness is the second PRE-DATA validity lens: same pattern as
  //    Dignity (AI-proposed, human-settled barriers scored by window.AccessEngine),
  //    so short-circuit it here too, before the dataset guard. ──
  if ((window.IQ_LENS || '') === 'access') {
    document.querySelectorAll('.iq-lens').forEach(function (el) {
      el.hidden = el.getAttribute('data-lens') !== 'access';
    });
    var _acSrc = document.getElementById('iqSource');
    if (_acSrc) { _acSrc.hidden = true; _acSrc.style.display = 'none'; }
    renderAccess();
    return;
  }

  // ── The five remaining Validity Readiness components are PRE-DATA factory
  //    lenses (construct_definition, purpose_alignment, dimension_coverage,
  //    item_construct_alignment, response_option_validity). They share one
  //    engine + one generic renderer; short-circuit here before the dataset
  //    guard, exactly like Dignity and Access. ──
  if (window.SDSI_VALIDITY_LENSES && window.SDSI_VALIDITY_LENSES[window.IQ_LENS || '']) {
    var _vc = window.IQ_LENS;
    document.querySelectorAll('.iq-lens').forEach(function (el) {
      el.hidden = el.getAttribute('data-lens') !== _vc;
    });
    var _vcSrc = document.getElementById('iqSource');
    if (_vcSrc) { _vcSrc.hidden = true; _vcSrc.style.display = 'none'; }
    renderValidityLens(_vc);
    return;
  }

  // ── The five Reliability Readiness components are PRE-DATA factory lenses
  //    (scale_structure_readiness, item_clarity, response_scale_consistency,
  //    redundancy_balance, administration_consistency). They share the same
  //    factory engine + a generic renderer; short-circuit here before the
  //    dataset guard, exactly like the validity lenses. ──
  if (window.SDSI_RELIABILITY_LENSES && window.SDSI_RELIABILITY_LENSES[window.IQ_LENS || '']) {
    var _rc = window.IQ_LENS;
    document.querySelectorAll('.iq-lens').forEach(function (el) {
      el.hidden = el.getAttribute('data-lens') !== _rc;
    });
    var _rcSrc = document.getElementById('iqSource');
    if (_rcSrc) { _rcSrc.hidden = true; _rcSrc.style.display = 'none'; }
    renderReliabilityLens(_rc);
    return;
  }

  // ── The five Administration Readiness components are PRE-LAUNCH factory
  //    lenses (respondent_instructions, consent_privacy, fielding_plan,
  //    sensitive_safety, completion_burden). They share the same factory engine
  //    + a generic renderer; short-circuit here before the dataset guard,
  //    exactly like the validity and reliability lenses. ──
  if (window.SDSI_ADMINISTRATION_LENSES && window.SDSI_ADMINISTRATION_LENSES[window.IQ_LENS || '']) {
    var _ac = window.IQ_LENS;
    document.querySelectorAll('.iq-lens').forEach(function (el) {
      el.hidden = el.getAttribute('data-lens') !== _ac;
    });
    var _acSrc = document.getElementById('iqSource');
    if (_acSrc) { _acSrc.hidden = true; _acSrc.style.display = 'none'; }
    renderAdministrationLens(_ac);
    return;
  }

  // ==================================================================
  // Dataset
  // ==================================================================
  let dataset = window.IQ_DATASET;
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
    document.getElementById('iqEmpty').hidden = false;
    return;
  }
  const allVars  = dataset.variables;
  const rowCount = dataset.rowCount || (allVars[0] ? allVars[0].values.length : 0);
  const lens     = window.IQ_LENS || 'validity';

  // ==================================================================
  // Math helpers
  // ==================================================================
  function num(v) { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function isMissing(v) { return v === '' || v == null; }
  function hasType(v, t) { return v.types && v.types.indexOf(t) !== -1; }
  // isLikert: explicitly tagged 'likert', OR numeric items that look
  // Likert (small integer range, ≤11 unique levels). The fallback catches
  // datasets where the detector landed on 'numeric' for what are really
  // 1-5 / 1-7 ratings — common with the MM Evidence Intake path.
  function isLikert(v) {
    if (hasType(v, 'likert')) return true;
    if (!hasType(v, 'numeric')) return false;
    const nums = (v.values || []).map(num).filter(x => x != null);
    if (nums.length < 3) return false;
    let allInts = true, lo = Infinity, hi = -Infinity;
    const seen = new Set();
    for (let i = 0; i < nums.length; i++) {
      const n = nums[i];
      if (!Number.isInteger(n)) { allInts = false; break; }
      if (n < lo) lo = n;
      if (n > hi) hi = n;
      seen.add(n);
    }
    return allInts && lo >= 0 && hi <= 10 && seen.size <= 11;
  }
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
      for (let n = 1; n < 200; n++) { ap += 1; del *= x / ap; sum += del; if (Math.abs(del) < Math.abs(sum) * 1e-12) break; }
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
  function chiPValue(x, df) {
    if (!isFinite(x) || x < 0 || df <= 0) return 1;
    return 1 - regGammaP(df / 2, x / 2);
  }

  // Matrix utilities (Gauss-Jordan inverse + determinant via row operations)
  function correlationMatrix(cols) {
    const k = cols.length;
    const R = [];
    for (let i = 0; i < k; i++) {
      R.push([]);
      for (let j = 0; j < k; j++) R[i].push(i === j ? 1 : pearson(cols[i], cols[j]));
    }
    return R;
  }
  function inverseAndDet(M) {
    const n = M.length;
    const A = M.map((row, i) => {
      const r = row.slice();
      for (let j = 0; j < n; j++) r.push(i === j ? 1 : 0);
      return r;
    });
    let det = 1, sign = 1;
    for (let i = 0; i < n; i++) {
      let pivot = i;
      for (let k = i + 1; k < n; k++) if (Math.abs(A[k][i]) > Math.abs(A[pivot][i])) pivot = k;
      if (pivot !== i) { const t = A[i]; A[i] = A[pivot]; A[pivot] = t; sign = -sign; }
      if (Math.abs(A[i][i]) < 1e-12) return { inv: null, det: 0 };
      det *= A[i][i];
      for (let k = 0; k < n; k++) {
        if (k === i) continue;
        const f = A[k][i] / A[i][i];
        for (let j = 0; j < 2 * n; j++) A[k][j] -= f * A[i][j];
      }
    }
    const inv = A.map((row, i) => row.slice(n).map(v => v / A[i][i]));
    return { inv: inv, det: sign * det };
  }

  function fmt(x, d) { if (x == null || !isFinite(x)) return '—'; return Number(x).toFixed(d == null ? 2 : d); }
  function pct(x) { return x == null ? '—' : Math.round(x * 100) + '%'; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
  function fmtP(p) {
    if (p == null) return 'p = —';
    if (p < 0.001) return 'p < .001';
    if (p < 0.01)  return 'p = ' + p.toFixed(3).replace(/^0/, '');
    return 'p = ' + p.toFixed(2).replace(/^0/, '');
  }

  // ==================================================================
  // Likert-item preparation (shared across most lenses)
  // ==================================================================
  function prepareLikert() {
    const items = allVars.filter(isLikert);
    if (items.length < 2) return null;
    const cols = items.map(v => v.values.map(num).map(x => x == null ? 0 : x));
    const validRows = [];
    for (let i = 0; i < rowCount; i++) {
      if (!items.some(v => isMissing(v.values[i]))) validRows.push(i);
    }
    if (validRows.length < 3) return { items: items, cols: cols, validCols: null, validRows: [] };
    const validCols = cols.map(col => validRows.map(i => col[i]));
    return { items: items, cols: cols, validCols: validCols, validRows: validRows };
  }

  // ==================================================================
  // Source ribbon + lens visibility
  // ==================================================================
  document.getElementById('iqSource').setAttribute('data-source', datasetSource);
  document.getElementById('iqSourceLabel').textContent = datasetSource === 'uploaded' ? 'Uploaded data' : 'Sample data';
  document.getElementById('iqSourceMeta').textContent  = (dataset.source || 'Dataset') + '  ·  ' + rowCount + ' rows';
  document.querySelectorAll('.iq-lens').forEach(el => {
    el.hidden = el.getAttribute('data-lens') !== lens;
  });

  // Wrap each lens render in try/catch so a runtime error surfaces as a
  // visible message instead of silent dashes. Each renderer writes to its
  // own DOM elements; if it throws we fall back to empty() with the error.
  function safeRender(fn, label) {
    try { fn(); }
    catch (err) {
      console.error('[IQ] ' + label + ' failed:', err);
      empty(label, 'Could not render ' + label.toLowerCase() + ': ' + (err && err.message ? err.message : String(err)));
    }
  }

  switch (lens) {
    case 'validity':            safeRender(renderValidity,           'Validity Review');     break;
    case 'reliability':         safeRender(renderReliability,        'Reliability');         break;
    case 'construct_alignment': safeRender(renderConstructAlignment, 'Construct Alignment'); break;
    case 'item_quality':        safeRender(renderItemQuality,        'Item Quality');        break;
    case 'scale_structure':     safeRender(renderScaleStructure,     'Scale Structure');     break;
    case 'factor_readiness':    safeRender(renderFactorReadiness,    'Factor Readiness');    break;
    case 'bias_clarity':        safeRender(renderBiasClarity,        'Bias & Clarity');      break;
    case 'response_scale':      safeRender(renderResponseScale,      'Response Scale');      break;
  }

  // ==================================================================
  // LENS: dignity_framing  (pre-data; dispatched by the short-circuit above)
  // AI proposes flags; the human keeps / dismisses / re-grades each; the
  // deterministic DignityEngine produces the score, SDSI points, evidence
  // ledger, and an ORTHOGONAL launch gate that never moves the number.
  // ==================================================================
  function dfDefaultProposal() {
    // Sample K-12 family survey — reproduces the agreed worked example
    // (−44 capped penalty, +6 credit → 62/100 → 5.0/8) and fires blockers
    // on the unprotected sensitive items so the gate is visible.
    return {
      title: 'Sample K-12 family survey',
      population: { minors: true, peopleFacing: true, communities: ['multilingual learners', 'special education'] },
      flags: [
        { check: 'deficit_framing', item_ref: 'q3',
          quote: 'How far behind are the at-risk students in your class?', severity: 'major',
          rationale: 'Frames students by deficiency before the respondent answers, pushing a deficit interpretation and distorting the construct.',
          suggested_revision: 'What academic supports would help your students make progress?' },
        { check: 'identity_erasure', item_ref: 'q8', topic: 'race',
          quote: 'Race: White / Black / Other', severity: 'major',
          rationale: 'Collapses distinct identities into "Other" and forces misclassification, biasing who gets counted.',
          suggested_revision: 'Offer multi-select with a write-in option matched to your community.',
          blocker_candidate: true, blocker_condition: 'forced_misclassification' },
        { check: 'extractive_disclosure', item_ref: 'q14', topic: 'legal_status', section: 'demographics',
          quote: 'Is anyone in your household undocumented?', severity: 'critical',
          rationale: 'Demands legal status from families with no stated purpose or decline path — real risk to respondents and a driver of nonresponse bias.',
          suggested_revision: 'Remove the item, or state a clear purpose and add a "Prefer not to answer" option on this item.',
          blocker_candidate: true, blocker_condition: 'unguarded_legal_status' },
        { check: 'judging_respondent', item_ref: 'q20',
          quote: 'How often do you neglect to read with your child?', severity: 'moderate',
          rationale: 'Moralizing wording shames the respondent before they answer, suppressing honest response.',
          suggested_revision: 'In a typical week, how often do you read with your child?' }
      ],
      // Mitigations sit on the "intro" section — they earn score credit (+6)
      // but are NOT attached to the sensitive demographics items, so they do
      // not clear those items' blockers. Move them onto an item/section to see
      // the gate clear.
      mitigations: [
        { type: 'clear_purpose',  item_ref: 'qIntro', section: 'intro',
          evidence: 'The survey intro states a general purpose.' },
        { type: 'decline_option', item_ref: 'qIntro', section: 'intro',
          evidence: 'A general "you may skip questions" note appears in the intro.' }
      ]
    };
  }

  function renderDignityFraming() {
    var Eng = window.DignityEngine;
    var titleEl  = document.getElementById('iqDfTitle');
    var subEl    = document.getElementById('iqDfSub');
    var bodyEl   = document.getElementById('iqDfBody');
    var interpEl = document.getElementById('iqDfInterp');
    if (!Eng) {
      titleEl.textContent = 'Dignity / Framing Readiness';
      subEl.textContent = 'engine unavailable';
      bodyEl.innerHTML = '<p class="iq-rel-plain">DignityEngine (apps/sdsi/dignity-engine.js) did not load.</p>';
      return;
    }

    var CHECKS = Eng.CHECKS;
    var SEVS   = ['minor', 'moderate', 'major', 'critical'];

    // Live survey when mounted under the survey studio with a project_id.
    // (For surveys, project_id IS the survey id.) Without one — e.g. the
    // standalone harness — we fall back to the worked-example sample and hide
    // the server-backed Run / Save actions.
    var studio   = window.RELICHECK_STUDIO || '';
    var surveyId = ((studio === 'survey' || studio === 'strength-survey') && window.RELICHECK_PROJECT_ID)
      ? Number(window.RELICHECK_PROJECT_ID) || 0 : 0;
    var live = surveyId > 0;

    // ── Mutable review state ──
    var population  = {};
    var flags       = [];
    var mitigations = [];
    var notes       = '';

    function seedFlags(arr) {
      flags = (arr || []).map(function (f, i) {
        return Object.assign({}, f, {
          flag_id: f.flag_id || ('f' + (i + 1)),
          decision: f.decision || 'accepted',
          _origSeverity: (f._origSeverity != null ? f._origSeverity : f.severity),
          blocker_reviewed: !!f.blocker_reviewed
        });
      });
    }
    function seedMitigations(arr) {
      mitigations = (arr || []).map(function (m, i) {
        return Object.assign({}, m, {
          mitigation_id: m.mitigation_id || ('m' + (i + 1)),
          decision: m.decision || 'accepted'
        });
      });
    }
    function seedFromProposal(p) {
      if (p.population) population = Object.assign({}, p.population);
      seedFlags(p.flags);
      seedMitigations(p.mitigations);
      notes = p.notes || '';
    }

    // Sample seed for the harness / no-survey case.
    if (!live) {
      seedFromProposal(dfDefaultProposal());
    } else {
      population = { minors: false, peopleFacing: true, communities: [] };
    }

    function findFlag(id) { for (var i = 0; i < flags.length; i++) if (flags[i].flag_id === id) return flags[i]; return null; }

    // ── Status line + button refs (set after setup HTML is rendered) ──
    var statusEl = null, runBtn = null, saveBtn = null, resultEl = null;
    function status(msg, isErr) {
      if (!statusEl) return;
      statusEl.textContent = msg || '';
      statusEl.setAttribute('data-tone', isErr ? 'err' : 'ok');
    }

    function commListToStr(c) { return Array.isArray(c) ? c.join(', ') : (c || ''); }
    function strToCommList(s) {
      return String(s || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 12);
    }

    function setupHtml() {
      return '' +
        '<div class="iq-df-setup">' +
          '<div class="iq-df-pop">' +
            '<span class="iq-df-pop-h">Population</span>' +
            '<label class="iq-df-pop-opt"><input type="checkbox" class="iq-df-pop-minors"' + (population.minors ? ' checked' : '') + '> Respondents include minors</label>' +
            '<label class="iq-df-pop-opt"><input type="checkbox" class="iq-df-pop-people"' + (population.peopleFacing !== false ? ' checked' : '') + '> People-facing (asked of people about themselves or their families)</label>' +
            '<label class="iq-df-pop-comm">Communities present ' +
              '<input type="text" class="iq-df-pop-communities" placeholder="e.g. multilingual learners, special education" value="' + esc(commListToStr(population.communities)) + '">' +
            '</label>' +
          '</div>' +
          '<div class="iq-df-actions">' +
            (live
              ? '<button type="button" class="iq-df-run">Run AI review</button>' +
                '<button type="button" class="iq-df-save">Save review</button>'
              : '<span class="iq-df-sample">Sample instrument — connect a survey to run the AI review and save.</span>') +
            '<span class="iq-df-status" data-tone="ok"></span>' +
          '</div>' +
        '</div>' +
        '<div class="iq-df-result"></div>';
    }

    function draw() {
      var res = Eng.assess({ flags: flags, mitigations: mitigations, population: population });
      var m = res.math;

      titleEl.textContent = 'Dignity / Framing Score: ' + res.score + '/100  ·  ' +
        res.sdsiPoints.toFixed(1) + '/' + res.sdsiWeight + ' validity points';
      subEl.textContent = res.band.label;

      var mathLine = 'Started at ' + m.startedAt + '   −' + Math.abs(m.cappedPenalty) +
        ' capped penalties   +' + m.credit + ' mitigation credit   =   ' + m.raw +
        (m.raw !== m.final ? '   (clamped to ' + m.final + ')' : '');

      var gateHtml;
      if (res.blockers.length === 0) {
        gateHtml = '<div class="iq-df-gate" data-state="clear">No launch blockers — the dignity/framing gate is clear.</div>';
      } else {
        gateHtml = '<div class="iq-df-gate" data-state="' + (res.launchReady ? 'reviewed' : 'blocked') + '">' +
          '<strong>' + (res.launchReady
            ? 'All blockers reviewed — gate cleared.'
            : 'Critical dignity/framing issue detected — review before launch.') + '</strong>' +
          '<span class="iq-df-gate-note">The launch gate is orthogonal: it does not change the ' + res.score + '/100 score.</span>' +
          '<ul class="iq-df-blockers">' + res.blockers.map(function (b) {
            return '<li>' +
              '<span class="iq-flag" data-tone="alert">Blocker</span> ' + esc(b.label) +
              ' <em>(' + esc(b.item_ref) + ')</em> ' +
              (b.reviewed
                ? '<span class="iq-df-reviewed">reviewed</span>'
                : '<button type="button" class="iq-df-btn" data-act="review" data-flag="' + esc(b.triggeredBy) + '">Mark reviewed</button>') +
            '</li>';
          }).join('') + '</ul>' +
        '</div>';
      }

      var cards = flags.map(function (f) {
        var counted = (f.decision === 'accepted' || f.decision === 'severity_overridden');
        var pen = counted ? Eng.SEVERITY_PENALTY[f.severity] : 0;
        var stateTxt = f.decision === 'dismissed' ? 'dismissed (0 pts · kept in ledger)'
                     : f.decision === 'severity_overridden' ? 'severity overridden'
                     : 'kept';
        return '<div class="iq-df-card" data-decision="' + esc(f.decision) + '">' +
          '<div class="iq-df-card-head">' +
            '<span class="iq-flag" data-tone="' + (counted ? 'alert' : 'ok') + '">' + esc(CHECKS[f.check] || f.check) + '</span>' +
            '<span class="iq-df-ref">' + esc(f.item_ref) + (f.topic ? ' · ' + esc(f.topic) : '') + '</span>' +
            '<span class="iq-df-pen">' + pen + ' pts</span>' +
          '</div>' +
          '<blockquote class="iq-df-quote">' + esc(f.quote) + '</blockquote>' +
          '<p class="iq-df-why"><strong>Why it matters.</strong> ' + esc(f.rationale) + '</p>' +
          (f.suggested_revision ? '<p class="iq-df-fix"><strong>Suggested revision.</strong> ' + esc(f.suggested_revision) + '</p>' : '') +
          '<div class="iq-df-controls">' +
            '<label class="iq-df-sevlabel">Severity ' +
              '<select class="iq-df-sev" data-flag="' + esc(f.flag_id) + '">' +
                SEVS.map(function (s) {
                  return '<option value="' + s + '"' + (s === f.severity ? ' selected' : '') + '>' +
                    s + ' (' + Eng.SEVERITY_PENALTY[s] + ')</option>';
                }).join('') +
              '</select>' +
            '</label>' +
            '<button type="button" class="iq-df-btn" data-act="accept" data-flag="' + esc(f.flag_id) + '"' + (f.decision !== 'dismissed' ? ' data-on="1"' : '') + '>Keep</button>' +
            '<button type="button" class="iq-df-btn" data-act="dismiss" data-flag="' + esc(f.flag_id) + '"' + (f.decision === 'dismissed' ? ' data-on="1"' : '') + '>Dismiss</button>' +
            '<span class="iq-df-state">' + esc(stateTxt) + '</span>' +
          '</div>' +
        '</div>';
      }).join('');

      var ledgerRows = res.ledger.map(function (l) {
        return '<tr data-counted="' + l.counted + '">' +
          '<td class="iq-rel-item">' + esc(l.item_ref) + '</td>' +
          '<td>' + esc(l.checkLabel) + '</td>' +
          '<td>' + esc(l.severity) + '</td>' +
          '<td>' + l.penalty + '</td>' +
          '<td>' + esc(l.decision) + '</td>' +
        '</tr>';
      }).join('');

      if (!resultEl) return;
      resultEl.innerHTML =
        '<div class="iq-rel-summary">' +
          '<p class="iq-rel-summary-line"><strong>' + esc(res.band.label) + '</strong></p>' +
          '<p class="iq-df-math">' + esc(mathLine) + '</p>' +
          '<p class="iq-rel-plain">Dignity / Framing is judged on <em>how</em> the instrument handles people, identity, risk, and difference — not on whether it asks about sensitive topics. The AI proposes the flags below with the exact wording that triggered each; you keep, dismiss, or re-grade them, and the score recomputes from your decisions.</p>' +
          (notes ? '<p class="iq-df-notes"><strong>AI notes.</strong> ' + esc(notes) + '</p>' : '') +
        '</div>' +
        gateHtml +
        '<h4 class="iq-block-h">Flags to review</h4>' +
        '<div class="iq-df-cards">' + (cards || '<p class="iq-rel-plain">No flags proposed — dignity/framing reads clean.</p>') + '</div>' +
        '<h4 class="iq-block-h">Evidence ledger</h4>' +
        '<div class="iq-rel-table-wrap"><table class="iq-table iq-table-rel">' +
          '<thead><tr><th>Item</th><th>Check</th><th>Severity</th><th>Penalty</th><th>Decision</th></tr></thead>' +
          '<tbody>' + ledgerRows + '</tbody>' +
        '</table></div>';

      interpEl.innerHTML =
        '<div class="iq-jema">' +
          '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(res.band.label) + ' — ' + res.sdsiPoints.toFixed(1) + ' of ' + res.sdsiWeight + ' validity points.</div>' +
          '<div class="iq-jema-row"><strong>Evidence.</strong> ' + res.ledger.filter(function (l) { return l.counted; }).length + ' counted flag(s); ' + res.blockers.length + ' launch blocker(s).</div>' +
          '<div class="iq-jema-row"><strong>Meaning.</strong> Framing problems are measurement error, not only ethics — deficit, othering, erasure, and shaming wording push respondents before they answer.</div>' +
          '<div class="iq-jema-row"><strong>Action.</strong> ' + (res.launchReady
            ? 'No unreviewed blockers. Address the remaining flags to raise the score before launch.'
            : 'Resolve or review the launch blocker(s) before this instrument is launch-ready — regardless of the score.') + '</div>' +
        '</div>';
    }

    // Render the setup region (population + actions) ONCE so its inputs keep
    // focus, then let draw() repaint only the .iq-df-result child below it.
    bodyEl.innerHTML = setupHtml();
    resultEl = bodyEl.querySelector('.iq-df-result');
    statusEl = bodyEl.querySelector('.iq-df-status');
    runBtn   = bodyEl.querySelector('.iq-df-run');
    saveBtn  = bodyEl.querySelector('.iq-df-save');

    // ── Population inputs → state ──
    var minorsEl = bodyEl.querySelector('.iq-df-pop-minors');
    var peopleEl = bodyEl.querySelector('.iq-df-pop-people');
    var commEl   = bodyEl.querySelector('.iq-df-pop-communities');
    if (minorsEl) minorsEl.addEventListener('change', function () { population.minors = minorsEl.checked; draw(); });
    if (peopleEl) peopleEl.addEventListener('change', function () { population.peopleFacing = peopleEl.checked; draw(); });
    if (commEl)   commEl.addEventListener('change', function () { population.communities = strToCommList(commEl.value); });

    // ── Run AI review (live only) ──
    if (runBtn) {
      runBtn.addEventListener('click', function () {
        population.communities = strToCommList(commEl ? commEl.value : '');
        runBtn.disabled = true;
        status('Running AI review of the instrument…');
        fetch('/api/sdsi/dignity-propose.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ survey_id: surveyId, population: population })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (o) {
            if (!o.ok || !o.j || !o.j.ok) throw new Error((o.j && o.j.message) || 'Proposal failed.');
            seedFromProposal(o.j);
            status((flags.length || 0) + ' flag(s) proposed' + (mitigations.length ? ', ' + mitigations.length + ' mitigation(s)' : '') + '. Review, then Save.');
            draw();
          })
          .catch(function (e) { status('AI review failed: ' + e.message, true); })
          .then(function () { runBtn.disabled = false; });
      });
    }

    // ── Save settled review (live only) ──
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        var res = Eng.assess({ flags: flags, mitigations: mitigations, population: population });
        saveBtn.disabled = true;
        status('Saving review…');
        fetch('/api/sdsi/dignity-save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            survey_id: surveyId,
            population: population,
            flags: flags,
            mitigations: mitigations,
            result: {
              score: res.score, sdsiPoints: res.sdsiPoints, band: res.band.key,
              launchReady: res.launchReady, blockerCount: res.blockers.length
            }
          })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (o) {
            if (!o.ok || !o.j || !o.j.ok) throw new Error((o.j && o.j.message) || 'Save failed.');
            status('Saved · ' + res.score + '/100 · ' + res.sdsiPoints.toFixed(1) + '/' + res.sdsiWeight + ' validity points.');
          })
          .catch(function (e) { status('Save failed: ' + e.message, true); })
          .then(function () { saveBtn.disabled = false; });
      });
    }

    // ── Flag card + severity delegation on the persistent result region ──
    resultEl.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.iq-df-btn') : null;
      if (!btn) return;
      var f = findFlag(btn.getAttribute('data-flag'));
      if (!f) return;
      var act = btn.getAttribute('data-act');
      if (act === 'accept')      f.decision = (f.severity !== f._origSeverity) ? 'severity_overridden' : 'accepted';
      else if (act === 'dismiss') f.decision = 'dismissed';
      else if (act === 'review')  f.blocker_reviewed = true;
      draw();
    });
    resultEl.addEventListener('change', function (e) {
      var sel = e.target.closest ? e.target.closest('.iq-df-sev') : null;
      if (!sel) return;
      var f = findFlag(sel.getAttribute('data-flag'));
      if (!f) return;
      f.severity = sel.value;
      if (f.decision !== 'dismissed') f.decision = (f.severity !== f._origSeverity) ? 'severity_overridden' : 'accepted';
      draw();
    });

    // ── Bootstrap: in live mode, load any saved review before first paint ──
    if (live) {
      status('Loading saved review…');
      fetch('/api/sdsi/dignity-get.php?survey_id=' + encodeURIComponent(surveyId), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok && j.exists && j.review) {
            seedFromProposal(j.review);
            if (minorsEl) minorsEl.checked = !!population.minors;
            if (peopleEl) peopleEl.checked = population.peopleFacing !== false;
            if (commEl)   commEl.value = commListToStr(population.communities);
            status('Loaded saved review (updated ' + (j.review.updatedAt || 'previously') + ').');
          } else {
            status('No saved review yet. Set the population, then Run AI review.');
          }
          draw();
        })
        .catch(function () { status('Could not load a saved review — start a new one.', true); draw(); });
    }

    draw();
  }

  // ==================================================================
  // LENS: access  (pre-data; dispatched by the short-circuit above)
  // Second validity lens. Same shape as dignity_framing: the AI proposes
  // access barriers; the human keeps / dismisses / re-grades each; the
  // deterministic AccessEngine produces the score, SDSI points, evidence
  // ledger, and an ORTHOGONAL launch gate that never moves the number.
  // Reuses the iq-df-* presentation classes (purely visual primitives).
  // ==================================================================
  function acDefaultProposal() {
    // Sample K-12 family survey — reproduces the agreed worked example
    // (−44 capped penalty, +6 credit → 62/100 → 5.0/8) and fires blockers
    // on the unprotected unreachable items so the gate is visible.
    return {
      title: 'Sample K-12 family survey',
      population: { minors: true, peopleFacing: true, communities: ['multilingual learners', 'special education'] },
      flags: [
        { check: 'reading_load', item_ref: 'q3',
          quote: 'To what extent do you perceive the pedagogical interventions to be efficacious?', severity: 'major',
          rationale: 'College-level vocabulary and abstract phrasing sit far above many family respondents, so answers reflect reading ability rather than the intended attitude.',
          suggested_revision: 'Do you think the extra help your child gets is working?' },
        { check: 'language_barrier', item_ref: 'q7', section: 'background',
          quote: 'Please describe your family’s experience with the enrollment process.', severity: 'major',
          rationale: 'Open English prose for a community that includes multilingual families excludes non-English readers and biases the sample toward English readers.',
          suggested_revision: 'Offer this item in the family’s home language plus a short plain-language version with checkbox options and an open field.',
          blocker_candidate: true, blocker_condition: 'language_excludes_population' },
        { check: 'format_inaccessibility', item_ref: 'q12', section: 'background',
          quote: 'Drag the slider to indicate how strongly you agree.', severity: 'critical',
          rationale: 'A drag-only slider assumes a pointer device and fine motor control; respondents on phones or with motor differences cannot answer, dropping them from the data.',
          suggested_revision: 'Offer labeled radio buttons (Strongly disagree … Strongly agree) as the primary control, with the slider optional.',
          blocker_candidate: true, blocker_condition: 'inaccessible_no_alt' },
        { check: 'response_burden', item_ref: 'q18',
          quote: 'List every school event you attended this year and what you learned at each.', severity: 'moderate',
          rationale: 'Long open recall over a full year is high-effort and drives fatigue and dropout, thinning later responses.',
          suggested_revision: 'About how many school events did you attend this year? (none / 1–2 / 3–5 / 6+) with an optional comment.' }
      ],
      // Mitigations sit on the "intro" section — they earn score credit (+6)
      // but are NOT attached to the unreachable background items, so they do
      // not clear those items' blockers. Move them onto an item/section to see
      // the gate clear.
      mitigations: [
        { type: 'plain_language_alt', item_ref: 'qIntro', section: 'intro',
          evidence: 'The intro offers a simpler-wording version of the opening items.' },
        { type: 'accommodation_path', item_ref: 'qIntro', section: 'intro',
          evidence: 'The intro notes families can request help completing the survey.' }
      ]
    };
  }

  function renderAccess() {
    var Eng = window.AccessEngine;
    var titleEl  = document.getElementById('iqAcTitle');
    var subEl    = document.getElementById('iqAcSub');
    var bodyEl   = document.getElementById('iqAcBody');
    var interpEl = document.getElementById('iqAcInterp');
    if (!Eng) {
      titleEl.textContent = 'Access Readiness';
      subEl.textContent = 'engine unavailable';
      bodyEl.innerHTML = '<p class="iq-rel-plain">AccessEngine (apps/sdsi/access-engine.js) did not load.</p>';
      return;
    }

    var CHECKS = Eng.CHECKS;
    var SEVS   = ['minor', 'moderate', 'major', 'critical'];

    var studio   = window.RELICHECK_STUDIO || '';
    var surveyId = ((studio === 'survey' || studio === 'strength-survey') && window.RELICHECK_PROJECT_ID)
      ? Number(window.RELICHECK_PROJECT_ID) || 0 : 0;
    var live = surveyId > 0;

    // ── Mutable review state ──
    var population  = {};
    var flags       = [];
    var mitigations = [];
    var notes       = '';

    function seedFlags(arr) {
      flags = (arr || []).map(function (f, i) {
        return Object.assign({}, f, {
          flag_id: f.flag_id || ('f' + (i + 1)),
          decision: f.decision || 'accepted',
          _origSeverity: (f._origSeverity != null ? f._origSeverity : f.severity),
          blocker_reviewed: !!f.blocker_reviewed
        });
      });
    }
    function seedMitigations(arr) {
      mitigations = (arr || []).map(function (m, i) {
        return Object.assign({}, m, {
          mitigation_id: m.mitigation_id || ('m' + (i + 1)),
          decision: m.decision || 'accepted'
        });
      });
    }
    function seedFromProposal(p) {
      if (p.population) population = Object.assign({}, p.population);
      seedFlags(p.flags);
      seedMitigations(p.mitigations);
      notes = p.notes || '';
    }

    if (!live) {
      seedFromProposal(acDefaultProposal());
    } else {
      population = { minors: false, peopleFacing: true, communities: [] };
    }

    function findFlag(id) { for (var i = 0; i < flags.length; i++) if (flags[i].flag_id === id) return flags[i]; return null; }

    var statusEl = null, runBtn = null, saveBtn = null, resultEl = null;
    function status(msg, isErr) {
      if (!statusEl) return;
      statusEl.textContent = msg || '';
      statusEl.setAttribute('data-tone', isErr ? 'err' : 'ok');
    }

    function commListToStr(c) { return Array.isArray(c) ? c.join(', ') : (c || ''); }
    function strToCommList(s) {
      return String(s || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 12);
    }

    function setupHtml() {
      return '' +
        '<div class="iq-df-setup">' +
          '<div class="iq-df-pop">' +
            '<span class="iq-df-pop-h">Population</span>' +
            '<label class="iq-df-pop-opt"><input type="checkbox" class="iq-df-pop-minors"' + (population.minors ? ' checked' : '') + '> Respondents include minors</label>' +
            '<label class="iq-df-pop-opt"><input type="checkbox" class="iq-df-pop-people"' + (population.peopleFacing !== false ? ' checked' : '') + '> People-facing (asked of people about themselves or their families)</label>' +
            '<label class="iq-df-pop-comm">Communities / languages present ' +
              '<input type="text" class="iq-df-pop-communities" placeholder="e.g. multilingual learners, Spanish, special education" value="' + esc(commListToStr(population.communities)) + '">' +
            '</label>' +
          '</div>' +
          '<div class="iq-df-actions">' +
            (live
              ? '<button type="button" class="iq-df-run">Run AI review</button>' +
                '<button type="button" class="iq-df-save">Save review</button>'
              : '<span class="iq-df-sample">Sample instrument — connect a survey to run the AI review and save.</span>') +
            '<span class="iq-df-status" data-tone="ok"></span>' +
          '</div>' +
        '</div>' +
        '<div class="iq-df-result"></div>';
    }

    function draw() {
      var res = Eng.assess({ flags: flags, mitigations: mitigations, population: population });
      var m = res.math;

      titleEl.textContent = 'Access Score: ' + res.score + '/100  ·  ' +
        res.sdsiPoints.toFixed(1) + '/' + res.sdsiWeight + ' validity points';
      subEl.textContent = res.band.label;

      var mathLine = 'Started at ' + m.startedAt + '   −' + Math.abs(m.cappedPenalty) +
        ' capped penalties   +' + m.credit + ' mitigation credit   =   ' + m.raw +
        (m.raw !== m.final ? '   (clamped to ' + m.final + ')' : '');

      var gateHtml;
      if (res.blockers.length === 0) {
        gateHtml = '<div class="iq-df-gate" data-state="clear">No launch blockers — the access gate is clear.</div>';
      } else {
        gateHtml = '<div class="iq-df-gate" data-state="' + (res.launchReady ? 'reviewed' : 'blocked') + '">' +
          '<strong>' + (res.launchReady
            ? 'All blockers reviewed — gate cleared.'
            : 'Critical access barrier detected — review before launch.') + '</strong>' +
          '<span class="iq-df-gate-note">The launch gate is orthogonal: it does not change the ' + res.score + '/100 score.</span>' +
          '<ul class="iq-df-blockers">' + res.blockers.map(function (b) {
            return '<li>' +
              '<span class="iq-flag" data-tone="alert">Blocker</span> ' + esc(b.label) +
              ' <em>(' + esc(b.item_ref) + ')</em> ' +
              (b.reviewed
                ? '<span class="iq-df-reviewed">reviewed</span>'
                : '<button type="button" class="iq-df-btn" data-act="review" data-flag="' + esc(b.triggeredBy) + '">Mark reviewed</button>') +
            '</li>';
          }).join('') + '</ul>' +
        '</div>';
      }

      var cards = flags.map(function (f) {
        var counted = (f.decision === 'accepted' || f.decision === 'severity_overridden');
        var pen = counted ? Eng.SEVERITY_PENALTY[f.severity] : 0;
        var stateTxt = f.decision === 'dismissed' ? 'dismissed (0 pts · kept in ledger)'
                     : f.decision === 'severity_overridden' ? 'severity overridden'
                     : 'kept';
        return '<div class="iq-df-card" data-decision="' + esc(f.decision) + '">' +
          '<div class="iq-df-card-head">' +
            '<span class="iq-flag" data-tone="' + (counted ? 'alert' : 'ok') + '">' + esc(CHECKS[f.check] || f.check) + '</span>' +
            '<span class="iq-df-ref">' + esc(f.item_ref) + (f.section ? ' · ' + esc(f.section) : '') + '</span>' +
            '<span class="iq-df-pen">' + pen + ' pts</span>' +
          '</div>' +
          '<blockquote class="iq-df-quote">' + esc(f.quote) + '</blockquote>' +
          '<p class="iq-df-why"><strong>Why it matters.</strong> ' + esc(f.rationale) + '</p>' +
          (f.suggested_revision ? '<p class="iq-df-fix"><strong>Suggested revision.</strong> ' + esc(f.suggested_revision) + '</p>' : '') +
          '<div class="iq-df-controls">' +
            '<label class="iq-df-sevlabel">Severity ' +
              '<select class="iq-df-sev" data-flag="' + esc(f.flag_id) + '">' +
                SEVS.map(function (s) {
                  return '<option value="' + s + '"' + (s === f.severity ? ' selected' : '') + '>' +
                    s + ' (' + Eng.SEVERITY_PENALTY[s] + ')</option>';
                }).join('') +
              '</select>' +
            '</label>' +
            '<button type="button" class="iq-df-btn" data-act="accept" data-flag="' + esc(f.flag_id) + '"' + (f.decision !== 'dismissed' ? ' data-on="1"' : '') + '>Keep</button>' +
            '<button type="button" class="iq-df-btn" data-act="dismiss" data-flag="' + esc(f.flag_id) + '"' + (f.decision === 'dismissed' ? ' data-on="1"' : '') + '>Dismiss</button>' +
            '<span class="iq-df-state">' + esc(stateTxt) + '</span>' +
          '</div>' +
        '</div>';
      }).join('');

      var ledgerRows = res.ledger.map(function (l) {
        return '<tr data-counted="' + l.counted + '">' +
          '<td class="iq-rel-item">' + esc(l.item_ref) + '</td>' +
          '<td>' + esc(l.checkLabel) + '</td>' +
          '<td>' + esc(l.severity) + '</td>' +
          '<td>' + l.penalty + '</td>' +
          '<td>' + esc(l.decision) + '</td>' +
        '</tr>';
      }).join('');

      if (!resultEl) return;
      resultEl.innerHTML =
        '<div class="iq-rel-summary">' +
          '<p class="iq-rel-summary-line"><strong>' + esc(res.band.label) + '</strong></p>' +
          '<p class="iq-df-math">' + esc(mathLine) + '</p>' +
          '<p class="iq-rel-plain">Access asks whether the intended respondents can actually <em>reach</em> the items — read them, understand them, answer them in their language, and finish without undue burden. A construct you cannot reach is a construct you cannot measure. The AI proposes the barriers below with the exact wording that triggered each; you keep, dismiss, or re-grade them, and the score recomputes from your decisions.</p>' +
          (notes ? '<p class="iq-df-notes"><strong>AI notes.</strong> ' + esc(notes) + '</p>' : '') +
        '</div>' +
        gateHtml +
        '<h4 class="iq-block-h">Barriers to review</h4>' +
        '<div class="iq-df-cards">' + (cards || '<p class="iq-rel-plain">No barriers proposed — access reads clean.</p>') + '</div>' +
        '<h4 class="iq-block-h">Evidence ledger</h4>' +
        '<div class="iq-rel-table-wrap"><table class="iq-table iq-table-rel">' +
          '<thead><tr><th>Item</th><th>Check</th><th>Severity</th><th>Penalty</th><th>Decision</th></tr></thead>' +
          '<tbody>' + ledgerRows + '</tbody>' +
        '</table></div>';

      interpEl.innerHTML =
        '<div class="iq-jema">' +
          '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(res.band.label) + ' — ' + res.sdsiPoints.toFixed(1) + ' of ' + res.sdsiWeight + ' validity points.</div>' +
          '<div class="iq-jema-row"><strong>Evidence.</strong> ' + res.ledger.filter(function (l) { return l.counted; }).length + ' counted barrier(s); ' + res.blockers.length + ' launch blocker(s).</div>' +
          '<div class="iq-jema-row"><strong>Meaning.</strong> Access barriers are measurement error, not only fairness — when reading load, language, burden, or format push respondents out, the missing answers bias the result.</div>' +
          '<div class="iq-jema-row"><strong>Action.</strong> ' + (res.launchReady
            ? 'No unreviewed blockers. Address the remaining barriers to raise the score before launch.'
            : 'Resolve or review the launch blocker(s) before this instrument is launch-ready — regardless of the score.') + '</div>' +
        '</div>';
    }

    bodyEl.innerHTML = setupHtml();
    resultEl = bodyEl.querySelector('.iq-df-result');
    statusEl = bodyEl.querySelector('.iq-df-status');
    runBtn   = bodyEl.querySelector('.iq-df-run');
    saveBtn  = bodyEl.querySelector('.iq-df-save');

    var minorsEl = bodyEl.querySelector('.iq-df-pop-minors');
    var peopleEl = bodyEl.querySelector('.iq-df-pop-people');
    var commEl   = bodyEl.querySelector('.iq-df-pop-communities');
    if (minorsEl) minorsEl.addEventListener('change', function () { population.minors = minorsEl.checked; draw(); });
    if (peopleEl) peopleEl.addEventListener('change', function () { population.peopleFacing = peopleEl.checked; draw(); });
    if (commEl)   commEl.addEventListener('change', function () { population.communities = strToCommList(commEl.value); });

    if (runBtn) {
      runBtn.addEventListener('click', function () {
        population.communities = strToCommList(commEl ? commEl.value : '');
        runBtn.disabled = true;
        status('Running AI review of the instrument…');
        fetch('/api/sdsi/access-propose.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ survey_id: surveyId, population: population })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (o) {
            if (!o.ok || !o.j || !o.j.ok) throw new Error((o.j && o.j.message) || 'Proposal failed.');
            seedFromProposal(o.j);
            status((flags.length || 0) + ' barrier(s) proposed' + (mitigations.length ? ', ' + mitigations.length + ' mitigation(s)' : '') + '. Review, then Save.');
            draw();
          })
          .catch(function (e) { status('AI review failed: ' + e.message, true); })
          .then(function () { runBtn.disabled = false; });
      });
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        var res = Eng.assess({ flags: flags, mitigations: mitigations, population: population });
        saveBtn.disabled = true;
        status('Saving review…');
        fetch('/api/sdsi/access-save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            survey_id: surveyId,
            population: population,
            flags: flags,
            mitigations: mitigations,
            result: {
              score: res.score, sdsiPoints: res.sdsiPoints, band: res.band.key,
              launchReady: res.launchReady, blockerCount: res.blockers.length
            }
          })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (o) {
            if (!o.ok || !o.j || !o.j.ok) throw new Error((o.j && o.j.message) || 'Save failed.');
            status('Saved · ' + res.score + '/100 · ' + res.sdsiPoints.toFixed(1) + '/' + res.sdsiWeight + ' validity points.');
          })
          .catch(function (e) { status('Save failed: ' + e.message, true); })
          .then(function () { saveBtn.disabled = false; });
      });
    }

    resultEl.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.iq-df-btn') : null;
      if (!btn) return;
      var f = findFlag(btn.getAttribute('data-flag'));
      if (!f) return;
      var act = btn.getAttribute('data-act');
      if (act === 'accept')      f.decision = (f.severity !== f._origSeverity) ? 'severity_overridden' : 'accepted';
      else if (act === 'dismiss') f.decision = 'dismissed';
      else if (act === 'review')  f.blocker_reviewed = true;
      draw();
    });
    resultEl.addEventListener('change', function (e) {
      var sel = e.target.closest ? e.target.closest('.iq-df-sev') : null;
      if (!sel) return;
      var f = findFlag(sel.getAttribute('data-flag'));
      if (!f) return;
      f.severity = sel.value;
      if (f.decision !== 'dismissed') f.decision = (f.severity !== f._origSeverity) ? 'severity_overridden' : 'accepted';
      draw();
    });

    if (live) {
      status('Loading saved review…');
      fetch('/api/sdsi/access-get.php?survey_id=' + encodeURIComponent(surveyId), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok && j.exists && j.review) {
            seedFromProposal(j.review);
            if (minorsEl) minorsEl.checked = !!population.minors;
            if (peopleEl) peopleEl.checked = population.peopleFacing !== false;
            if (commEl)   commEl.value = commListToStr(population.communities);
            status('Loaded saved review (updated ' + (j.review.updatedAt || 'previously') + ').');
          } else {
            status('No saved review yet. Set the population, then Run AI review.');
          }
          draw();
        })
        .catch(function () { status('Could not load a saved review — start a new one.', true); draw(); });
    }

    draw();
  }

  // ==================================================================
  // GENERIC PRE-DATA VALIDITY LENS (the five factory components).
  // One renderer for construct_definition, purpose_alignment,
  // dimension_coverage, item_construct_alignment, response_option_validity.
  // Mirrors renderAccess's settle-and-score loop, but: (a) it reads the spec
  // + engine from window.SDSI_VALIDITY_{SPECS,LENSES}; (b) the setup form is
  // the spec's reviewer-declared CONTEXT fields (construct/definition/purpose/
  // dimensions…), not population checkboxes; (c) these lenses define NO
  // mitigations, so there is no credit UI. Element IDs are derived per
  // component as iqV_<component>_{title,sub,body,interp}.
  // ==================================================================
  function renderValidityLens(component) {
    var spec = (window.SDSI_VALIDITY_SPECS || {})[component];
    var Eng  = (window.SDSI_VALIDITY_LENSES || {})[component];
    function vid(s) { return document.getElementById('iqV_' + component + '_' + s); }
    var titleEl = vid('title'), subEl = vid('sub'), bodyEl = vid('body'), interpEl = vid('interp');
    if (!spec || !Eng || !titleEl) {
      if (titleEl) titleEl.textContent = (spec ? spec.name : component);
      if (subEl) subEl.textContent = 'engine unavailable';
      if (bodyEl) bodyEl.innerHTML = '<p class="iq-rel-plain">The validity lens factory (apps/sdsi/validity-lens-engine.js + validity-specs.js) did not load.</p>';
      return;
    }

    var CHECKS = Eng.CHECKS;
    var SEVS   = ['minor', 'moderate', 'major', 'critical'];
    var fields = spec.contextFields || [];

    var studio   = window.RELICHECK_STUDIO || '';
    var surveyId = ((studio === 'survey' || studio === 'strength-survey') && window.RELICHECK_PROJECT_ID)
      ? Number(window.RELICHECK_PROJECT_ID) || 0 : 0;
    var live = surveyId > 0;

    // ── Mutable review state ──
    var context = {};
    var flags   = [];
    var notes   = '';

    function listToStr(v) { return Array.isArray(v) ? v.join(', ') : (v || ''); }
    function strToList(s) {
      return String(s || '').split(/[\r\n,]+/).map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 24);
    }

    function seedFlags(arr) {
      flags = (arr || []).map(function (f, i) {
        return Object.assign({}, f, {
          flag_id: f.flag_id || ('f' + (i + 1)),
          decision: f.decision || 'accepted',
          _origSeverity: (f._origSeverity != null ? f._origSeverity : f.severity),
          blocker_reviewed: !!f.blocker_reviewed
        });
      });
    }
    function seedFromProposal(p) {
      if (p.context) context = Object.assign({}, p.context);
      // Live denominator for report-level warnings (e.g. scale-interpretation
      // risk): the count of items the proposal actually reviewed. Not a reviewer
      // field, not persisted — recomputed each render from current data.
      if (Array.isArray(p.items)) context.item_count = p.items.length;
      seedFlags(p.flags);
      notes = p.notes || '';
    }

    if (!live && spec.sample) {
      seedFromProposal({ context: spec.sample.context, flags: spec.sample.flags });
    }

    function findFlag(id) { for (var i = 0; i < flags.length; i++) if (flags[i].flag_id === id) return flags[i]; return null; }

    var statusEl = null, runBtn = null, saveBtn = null, resultEl = null;
    function status(msg, isErr) {
      if (!statusEl) return;
      statusEl.textContent = msg || '';
      statusEl.setAttribute('data-tone', isErr ? 'err' : 'ok');
    }

    function fieldHtml(f) {
      var val = context[f.key];
      if (f.type === 'textarea') {
        return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
          '<textarea class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="textarea" rows="2">' + esc(val || '') + '</textarea></label>';
      }
      if (f.type === 'list') {
        return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
          '<input type="text" class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="list" value="' + esc(listToStr(val)) + '"></label>';
      }
      if (f.type === 'select') {
        var opts = (f.options || []).map(function (o) {
          return '<option value="' + esc(o.value) + '"' + (o.value === val ? ' selected' : '') + '>' + esc(o.label) + '</option>';
        }).join('');
        return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
          '<select class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="select">' + opts + '</select></label>';
      }
      return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
        '<input type="text" class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="text" value="' + esc(val || '') + '"></label>';
    }

    function setupHtml() {
      var fieldsHtml = fields.length
        ? fields.map(fieldHtml).join('')
        : '<span class="iq-df-sample">This component reads the items directly — no context to declare.</span>';
      return '' +
        '<div class="iq-df-setup">' +
          '<div class="iq-df-pop">' +
            '<span class="iq-df-pop-h">Declared context</span>' +
            (spec.reviewerPrompt ? '<p class="iq-vc-prompt">' + esc(spec.reviewerPrompt) + '</p>' : '') +
            fieldsHtml +
          '</div>' +
          '<div class="iq-df-actions">' +
            (live
              ? '<button type="button" class="iq-df-run">Run AI review</button>' +
                '<button type="button" class="iq-df-save">Save review</button>'
              : '<span class="iq-df-sample">Sample instrument — connect a survey to run the AI review and save.</span>') +
            '<span class="iq-df-status" data-tone="ok"></span>' +
          '</div>' +
        '</div>' +
        '<div class="iq-df-result"></div>';
    }

    function draw() {
      var res = Eng.assess({ flags: flags, mitigations: [], context: context });
      var m = res.math;

      titleEl.textContent = spec.name + ' Score: ' + res.score + '/100  ·  ' +
        res.sdsiPoints.toFixed(1) + '/' + res.sdsiWeight + ' validity points';
      subEl.textContent = res.band.label;

      var mathLine = 'Started at ' + m.startedAt + '   −' + Math.abs(m.cappedPenalty) +
        ' capped penalties   =   ' + (m.startedAt + m.cappedPenalty) +
        (m.raw !== m.final ? '   (clamped to ' + m.final + ')' : '');

      var gateHtml;
      if (res.blockers.length === 0) {
        gateHtml = '<div class="iq-df-gate" data-state="clear">No launch blockers — the validity gate is clear.</div>';
      } else {
        gateHtml = '<div class="iq-df-gate" data-state="' + (res.launchReady ? 'reviewed' : 'blocked') + '">' +
          '<strong>' + (res.launchReady
            ? 'All blockers reviewed — gate cleared.'
            : 'Validity blocker detected — review before launch.') + '</strong>' +
          '<span class="iq-df-gate-note">The launch gate is orthogonal: it does not change the ' + res.score + '/100 score.</span>' +
          '<ul class="iq-df-blockers">' + res.blockers.map(function (b) {
            return '<li>' +
              '<span class="iq-flag" data-tone="alert">Blocker</span> ' + esc(b.label) +
              ' <em>(' + esc(b.item_ref) + ')</em> ' +
              (b.reviewed
                ? '<span class="iq-df-reviewed">reviewed</span>'
                : '<button type="button" class="iq-df-btn" data-act="review" data-flag="' + esc(b.triggeredBy) + '">Mark reviewed</button>') +
            '</li>';
          }).join('') + '</ul>' +
        '</div>';
      }

      // Report-level cautions: advisory only — never change the score or block launch.
      var warnHtml = (res.warnings && res.warnings.length)
        ? '<div class="iq-df-gate" data-state="warn">' +
            '<strong>Report-level caution</strong>' +
            '<span class="iq-df-gate-note">Advisory only — does not change the ' + res.score + '/100 score or block launch.</span>' +
            '<ul class="iq-df-blockers">' + res.warnings.map(function (w) {
              return '<li><span class="iq-flag" data-tone="warn">Caution</span> ' + esc(w.label) + '</li>';
            }).join('') + '</ul>' +
          '</div>'
        : '';

      var cards = flags.map(function (f) {
        var counted = (f.decision === 'accepted' || f.decision === 'severity_overridden');
        var pen = counted ? Eng.SEVERITY_PENALTY[f.severity] : 0;
        var stateTxt = f.decision === 'dismissed' ? 'dismissed (0 pts · kept in ledger)'
                     : f.decision === 'severity_overridden' ? 'severity overridden'
                     : 'kept';
        return '<div class="iq-df-card" data-decision="' + esc(f.decision) + '">' +
          '<div class="iq-df-card-head">' +
            '<span class="iq-flag" data-tone="' + (counted ? 'alert' : 'ok') + '">' + esc(CHECKS[f.check] || f.check) + '</span>' +
            '<span class="iq-df-ref">' + esc(f.item_ref) + (f.section ? ' · ' + esc(f.section) : '') + '</span>' +
            '<span class="iq-df-pen">' + pen + ' pts</span>' +
          '</div>' +
          '<blockquote class="iq-df-quote">' + esc(f.quote) + '</blockquote>' +
          '<p class="iq-df-why"><strong>Why it matters.</strong> ' + esc(f.rationale) + '</p>' +
          (f.suggested_revision ? '<p class="iq-df-fix"><strong>Suggested revision.</strong> ' + esc(f.suggested_revision) + '</p>' : '') +
          '<div class="iq-df-controls">' +
            '<label class="iq-df-sevlabel">Severity ' +
              '<select class="iq-df-sev" data-flag="' + esc(f.flag_id) + '">' +
                SEVS.map(function (s) {
                  return '<option value="' + s + '"' + (s === f.severity ? ' selected' : '') + '>' +
                    s + ' (' + Eng.SEVERITY_PENALTY[s] + ')</option>';
                }).join('') +
              '</select>' +
            '</label>' +
            '<button type="button" class="iq-df-btn" data-act="accept" data-flag="' + esc(f.flag_id) + '"' + (f.decision !== 'dismissed' ? ' data-on="1"' : '') + '>Keep</button>' +
            '<button type="button" class="iq-df-btn" data-act="dismiss" data-flag="' + esc(f.flag_id) + '"' + (f.decision === 'dismissed' ? ' data-on="1"' : '') + '>Dismiss</button>' +
            '<span class="iq-df-state">' + esc(stateTxt) + '</span>' +
          '</div>' +
        '</div>';
      }).join('');

      var ledgerRows = res.ledger.map(function (l) {
        return '<tr data-counted="' + l.counted + '">' +
          '<td class="iq-rel-item">' + esc(l.item_ref) + '</td>' +
          '<td>' + esc(l.checkLabel) + '</td>' +
          '<td>' + esc(l.severity) + '</td>' +
          '<td>' + l.penalty + '</td>' +
          '<td>' + esc(l.decision) + '</td>' +
        '</tr>';
      }).join('');

      if (!resultEl) return;
      resultEl.innerHTML =
        '<div class="iq-rel-summary">' +
          '<p class="iq-rel-summary-line"><strong>' + esc(res.band.label) + '</strong></p>' +
          '<p class="iq-df-math">' + esc(mathLine) + '</p>' +
          '<p class="iq-rel-plain">' + esc(spec.intro || '') + ' The AI proposes the issues below with the exact wording that triggered each; you keep, dismiss, or re-grade them, and the score recomputes from your decisions.</p>' +
          (notes ? '<p class="iq-df-notes"><strong>AI notes.</strong> ' + esc(notes) + '</p>' : '') +
        '</div>' +
        gateHtml +
        warnHtml +
        '<h4 class="iq-block-h">Issues to review</h4>' +
        '<div class="iq-df-cards">' + (cards || '<p class="iq-rel-plain">No issues proposed — this component reads clean.</p>') + '</div>' +
        '<h4 class="iq-block-h">Evidence ledger</h4>' +
        '<div class="iq-rel-table-wrap"><table class="iq-table iq-table-rel">' +
          '<thead><tr><th>Item</th><th>Check</th><th>Severity</th><th>Penalty</th><th>Decision</th></tr></thead>' +
          '<tbody>' + ledgerRows + '</tbody>' +
        '</table></div>';

      interpEl.innerHTML =
        '<div class="iq-jema">' +
          '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(res.band.label) + ' — ' + res.sdsiPoints.toFixed(1) + ' of ' + res.sdsiWeight + ' validity points.</div>' +
          '<div class="iq-jema-row"><strong>Evidence.</strong> ' + res.ledger.filter(function (l) { return l.counted; }).length + ' counted issue(s); ' + res.blockers.length + ' launch blocker(s).</div>' +
          '<div class="iq-jema-row"><strong>Meaning.</strong> This is a validity-readiness check on the instrument before any data — it never uses reliability statistics (alpha, omega, item-total). It asks whether the construct-to-item chain holds.</div>' +
          '<div class="iq-jema-row"><strong>Action.</strong> ' + (res.launchReady
            ? 'No unreviewed blockers. Address the remaining issues to raise the score before launch.'
            : 'Resolve or review the launch blocker(s) before this component is launch-ready — regardless of the score.') + '</div>' +
        '</div>';
    }

    bodyEl.innerHTML = setupHtml();
    resultEl = bodyEl.querySelector('.iq-df-result');
    statusEl = bodyEl.querySelector('.iq-df-status');
    runBtn   = bodyEl.querySelector('.iq-df-run');
    saveBtn  = bodyEl.querySelector('.iq-df-save');

    function readContextFields() {
      bodyEl.querySelectorAll('.iq-vc-field').forEach(function (el) {
        var k = el.getAttribute('data-key');
        context[k] = el.getAttribute('data-type') === 'list' ? strToList(el.value) : el.value;
      });
    }
    bodyEl.querySelectorAll('.iq-vc-field').forEach(function (el) {
      el.addEventListener('change', function () {
        var k = el.getAttribute('data-key');
        context[k] = el.getAttribute('data-type') === 'list' ? strToList(el.value) : el.value;
      });
    });

    if (runBtn) {
      runBtn.addEventListener('click', function () {
        readContextFields();
        runBtn.disabled = true;
        status('Running AI review of the instrument…');
        fetch('/api/sdsi/validity-propose.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ survey_id: surveyId, component: component, context: context })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (o) {
            if (!o.ok || !o.j || !o.j.ok) throw new Error((o.j && o.j.message) || 'Proposal failed.');
            seedFromProposal(o.j);
            status((flags.length || 0) + ' issue(s) proposed. Review, then Save.');
            draw();
          })
          .catch(function (e) { status('AI review failed: ' + e.message, true); })
          .then(function () { runBtn.disabled = false; });
      });
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        readContextFields();
        var res = Eng.assess({ flags: flags, mitigations: [], context: context });
        saveBtn.disabled = true;
        status('Saving review…');
        fetch('/api/sdsi/validity-save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            survey_id: surveyId,
            component: component,
            context: context,
            flags: flags,
            result: {
              score: res.score, sdsiPoints: res.sdsiPoints, band: res.band.key,
              launchReady: res.launchReady, blockerCount: res.blockers.length
            }
          })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (o) {
            if (!o.ok || !o.j || !o.j.ok) throw new Error((o.j && o.j.message) || 'Save failed.');
            status('Saved · ' + res.score + '/100 · ' + res.sdsiPoints.toFixed(1) + '/' + res.sdsiWeight + ' validity points.');
          })
          .catch(function (e) { status('Save failed: ' + e.message, true); })
          .then(function () { saveBtn.disabled = false; });
      });
    }

    resultEl.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.iq-df-btn') : null;
      if (!btn) return;
      var f = findFlag(btn.getAttribute('data-flag'));
      if (!f) return;
      var act = btn.getAttribute('data-act');
      if (act === 'accept')      f.decision = (f.severity !== f._origSeverity) ? 'severity_overridden' : 'accepted';
      else if (act === 'dismiss') f.decision = 'dismissed';
      else if (act === 'review')  f.blocker_reviewed = true;
      draw();
    });
    resultEl.addEventListener('change', function (e) {
      var sel = e.target.closest ? e.target.closest('.iq-df-sev') : null;
      if (!sel) return;
      var f = findFlag(sel.getAttribute('data-flag'));
      if (!f) return;
      f.severity = sel.value;
      if (f.decision !== 'dismissed') f.decision = (f.severity !== f._origSeverity) ? 'severity_overridden' : 'accepted';
      draw();
    });

    if (live) {
      status('Loading saved review…');
      fetch('/api/sdsi/validity-get.php?survey_id=' + encodeURIComponent(surveyId) + '&component=' + encodeURIComponent(component), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok && j.exists && j.review) {
            seedFromProposal(j.review);
            bodyEl.querySelectorAll('.iq-vc-field').forEach(function (el) {
              var k = el.getAttribute('data-key');
              el.value = el.getAttribute('data-type') === 'list' ? listToStr(context[k]) : (context[k] || '');
            });
            status('Loaded saved review (updated ' + (j.review.updatedAt || 'previously') + ').');
          } else {
            status('No saved review yet. Declare the context, then Run AI review.');
          }
          draw();
        })
        .catch(function () { status('Could not load a saved review — start a new one.', true); draw(); });
    }

    draw();
  }

  // ==================================================================
  // GENERIC PRE-DATA RELIABILITY READINESS LENS (the five factory components).
  // One renderer for scale_structure_readiness, item_clarity,
  // response_scale_consistency, redundancy_balance, administration_consistency.
  // A near-twin of renderValidityLens, but: (a) it reads the spec + engine from
  // window.SDSI_RELIABILITY_{SPECS,LENSES}; (b) it hits the reliability-*
  // endpoints; (c) element IDs are iqR_<component>_{title,sub,body,interp};
  // (d) the language says "reliability points" and the Meaning row states the
  // ALPHA FENCE (pre-data design review — never alpha/omega/item-total/
  // inter-item/factor analysis). Some lenses carry an advisory warning and/or an
  // orthogonal launch blocker; both are surfaced exactly as the engine reports.
  // ==================================================================
  function renderReliabilityLens(component) {
    var spec = (window.SDSI_RELIABILITY_SPECS || {})[component];
    var Eng  = (window.SDSI_RELIABILITY_LENSES || {})[component];
    function vid(s) { return document.getElementById('iqR_' + component + '_' + s); }
    var titleEl = vid('title'), subEl = vid('sub'), bodyEl = vid('body'), interpEl = vid('interp');
    if (!spec || !Eng || !titleEl) {
      if (titleEl) titleEl.textContent = (spec ? spec.name : component);
      if (subEl) subEl.textContent = 'engine unavailable';
      if (bodyEl) bodyEl.innerHTML = '<p class="iq-rel-plain">The reliability lens factory (apps/sdsi/validity-lens-engine.js + reliability-specs.js) did not load.</p>';
      return;
    }

    var CHECKS = Eng.CHECKS;
    var SEVS   = ['minor', 'moderate', 'major', 'critical'];
    var fields = spec.contextFields || [];

    var studio   = window.RELICHECK_STUDIO || '';
    var surveyId = ((studio === 'survey' || studio === 'strength-survey') && window.RELICHECK_PROJECT_ID)
      ? Number(window.RELICHECK_PROJECT_ID) || 0 : 0;
    var live = surveyId > 0;

    // ── Mutable review state ──
    var context = {};
    var flags   = [];
    var notes   = '';

    function listToStr(v) { return Array.isArray(v) ? v.join(', ') : (v || ''); }
    function strToList(s) {
      return String(s || '').split(/[\r\n,]+/).map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 24);
    }

    function seedFlags(arr) {
      flags = (arr || []).map(function (f, i) {
        return Object.assign({}, f, {
          flag_id: f.flag_id || ('f' + (i + 1)),
          decision: f.decision || 'accepted',
          _origSeverity: (f._origSeverity != null ? f._origSeverity : f.severity),
          blocker_reviewed: !!f.blocker_reviewed
        });
      });
    }
    function seedFromProposal(p) {
      if (p.context) context = Object.assign({}, p.context);
      // Live denominator for the widespread_*_risk advisory warnings: the count
      // of items the proposal actually reviewed. Not a reviewer field; recomputed
      // each render. The reviewer may also type item_count directly.
      if (Array.isArray(p.items) && p.items.length) context.item_count = p.items.length;
      seedFlags(p.flags);
      notes = p.notes || '';
    }

    if (!live && spec.sample) {
      seedFromProposal({ context: spec.sample.context, flags: spec.sample.flags });
    }

    function findFlag(id) { for (var i = 0; i < flags.length; i++) if (flags[i].flag_id === id) return flags[i]; return null; }

    var statusEl = null, runBtn = null, saveBtn = null, resultEl = null;
    function status(msg, isErr) {
      if (!statusEl) return;
      statusEl.textContent = msg || '';
      statusEl.setAttribute('data-tone', isErr ? 'err' : 'ok');
    }

    function fieldHtml(f) {
      var val = context[f.key];
      if (f.type === 'textarea') {
        return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
          '<textarea class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="textarea" rows="2">' + esc(val || '') + '</textarea></label>';
      }
      if (f.type === 'list') {
        return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
          '<input type="text" class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="list" value="' + esc(listToStr(val)) + '"></label>';
      }
      if (f.type === 'number') {
        return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
          '<input type="number" min="0" class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="number" value="' + esc(val == null ? '' : val) + '"></label>';
      }
      if (f.type === 'select') {
        var opts = (f.options || []).map(function (o) {
          return '<option value="' + esc(o.value) + '"' + (o.value === val ? ' selected' : '') + '>' + esc(o.label) + '</option>';
        }).join('');
        return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
          '<select class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="select"><option value="">—</option>' + opts + '</select></label>';
      }
      return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
        '<input type="text" class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="text" value="' + esc(val || '') + '"></label>';
    }

    function setupHtml() {
      var fieldsHtml = fields.length
        ? fields.map(fieldHtml).join('')
        : '<span class="iq-df-sample">This component reads the items directly — no context to declare.</span>';
      return '' +
        '<div class="iq-df-setup">' +
          '<div class="iq-df-pop">' +
            '<span class="iq-df-pop-h">Declared context</span>' +
            (spec.reviewerPrompt ? '<p class="iq-vc-prompt">' + esc(spec.reviewerPrompt) + '</p>' : '') +
            fieldsHtml +
          '</div>' +
          '<div class="iq-df-actions">' +
            (live
              ? '<button type="button" class="iq-df-run">Run AI review</button>' +
                '<button type="button" class="iq-df-save">Save review</button>'
              : '<span class="iq-df-sample">Sample instrument — connect a survey to run the AI review and save.</span>') +
            '<span class="iq-df-status" data-tone="ok"></span>' +
          '</div>' +
        '</div>' +
        '<div class="iq-df-result"></div>';
    }

    function draw() {
      var res = Eng.assess({ flags: flags, mitigations: [], context: context });
      var m = res.math;

      titleEl.textContent = spec.name + ' Score: ' + res.score + '/100  ·  ' +
        res.sdsiPoints.toFixed(1) + '/' + res.sdsiWeight + ' reliability points';
      subEl.textContent = res.band.label;

      var mathLine = 'Started at ' + m.startedAt + '   −' + Math.abs(m.cappedPenalty) +
        ' capped penalties   =   ' + (m.startedAt + m.cappedPenalty) +
        (m.raw !== m.final ? '   (clamped to ' + m.final + ')' : '');

      var gateHtml;
      if (res.blockers.length === 0) {
        gateHtml = '<div class="iq-df-gate" data-state="clear">No launch blockers — the reliability gate is clear.</div>';
      } else {
        gateHtml = '<div class="iq-df-gate" data-state="' + (res.launchReady ? 'reviewed' : 'blocked') + '">' +
          '<strong>' + (res.launchReady
            ? 'All blockers reviewed — gate cleared.'
            : 'Reliability blocker detected — review before launch.') + '</strong>' +
          '<span class="iq-df-gate-note">The launch gate is orthogonal: it does not change the ' + res.score + '/100 score.</span>' +
          '<ul class="iq-df-blockers">' + res.blockers.map(function (b) {
            return '<li>' +
              '<span class="iq-flag" data-tone="alert">Blocker</span> ' + esc(b.label) +
              ' <em>(' + esc(b.item_ref) + ')</em> ' +
              (b.reviewed
                ? '<span class="iq-df-reviewed">reviewed</span>'
                : '<button type="button" class="iq-df-btn" data-act="review" data-flag="' + esc(b.triggeredBy) + '">Mark reviewed</button>') +
            '</li>';
          }).join('') + '</ul>' +
        '</div>';
      }

      // Report-level cautions: advisory only — never change the score or block launch.
      var warnHtml = (res.warnings && res.warnings.length)
        ? '<div class="iq-df-gate" data-state="warn">' +
            '<strong>Report-level caution</strong>' +
            '<span class="iq-df-gate-note">Advisory only — does not change the ' + res.score + '/100 score or block launch.</span>' +
            '<ul class="iq-df-blockers">' + res.warnings.map(function (w) {
              return '<li><span class="iq-flag" data-tone="warn">Caution</span> ' + esc(w.label) + '</li>';
            }).join('') + '</ul>' +
          '</div>'
        : '';

      var cards = flags.map(function (f) {
        var counted = (f.decision === 'accepted' || f.decision === 'severity_overridden');
        var pen = counted ? Eng.SEVERITY_PENALTY[f.severity] : 0;
        var stateTxt = f.decision === 'dismissed' ? 'dismissed (0 pts · kept in ledger)'
                     : f.decision === 'severity_overridden' ? 'severity overridden'
                     : 'kept';
        return '<div class="iq-df-card" data-decision="' + esc(f.decision) + '">' +
          '<div class="iq-df-card-head">' +
            '<span class="iq-flag" data-tone="' + (counted ? 'alert' : 'ok') + '">' + esc(CHECKS[f.check] || f.check) + '</span>' +
            '<span class="iq-df-ref">' + esc(f.item_ref) + (f.section ? ' · ' + esc(f.section) : '') + '</span>' +
            '<span class="iq-df-pen">' + pen + ' pts</span>' +
          '</div>' +
          '<blockquote class="iq-df-quote">' + esc(f.quote) + '</blockquote>' +
          '<p class="iq-df-why"><strong>Why it matters.</strong> ' + esc(f.rationale) + '</p>' +
          (f.suggested_revision ? '<p class="iq-df-fix"><strong>Suggested revision.</strong> ' + esc(f.suggested_revision) + '</p>' : '') +
          '<div class="iq-df-controls">' +
            '<label class="iq-df-sevlabel">Severity ' +
              '<select class="iq-df-sev" data-flag="' + esc(f.flag_id) + '">' +
                SEVS.map(function (s) {
                  return '<option value="' + s + '"' + (s === f.severity ? ' selected' : '') + '>' +
                    s + ' (' + Eng.SEVERITY_PENALTY[s] + ')</option>';
                }).join('') +
              '</select>' +
            '</label>' +
            '<button type="button" class="iq-df-btn" data-act="accept" data-flag="' + esc(f.flag_id) + '"' + (f.decision !== 'dismissed' ? ' data-on="1"' : '') + '>Keep</button>' +
            '<button type="button" class="iq-df-btn" data-act="dismiss" data-flag="' + esc(f.flag_id) + '"' + (f.decision === 'dismissed' ? ' data-on="1"' : '') + '>Dismiss</button>' +
            '<span class="iq-df-state">' + esc(stateTxt) + '</span>' +
          '</div>' +
        '</div>';
      }).join('');

      var ledgerRows = res.ledger.map(function (l) {
        return '<tr data-counted="' + l.counted + '">' +
          '<td class="iq-rel-item">' + esc(l.item_ref) + '</td>' +
          '<td>' + esc(l.checkLabel) + '</td>' +
          '<td>' + esc(l.severity) + '</td>' +
          '<td>' + l.penalty + '</td>' +
          '<td>' + esc(l.decision) + '</td>' +
        '</tr>';
      }).join('');

      if (!resultEl) return;
      resultEl.innerHTML =
        '<div class="iq-rel-summary">' +
          '<p class="iq-rel-summary-line"><strong>' + esc(res.band.label) + '</strong></p>' +
          '<p class="iq-df-math">' + esc(mathLine) + '</p>' +
          '<p class="iq-rel-plain">' + esc(spec.intro || '') + ' The AI proposes the issues below with the exact wording that triggered each; you keep, dismiss, or re-grade them, and the score recomputes from your decisions.</p>' +
          (notes ? '<p class="iq-df-notes"><strong>AI notes.</strong> ' + esc(notes) + '</p>' : '') +
        '</div>' +
        gateHtml +
        warnHtml +
        '<h4 class="iq-block-h">Issues to review</h4>' +
        '<div class="iq-df-cards">' + (cards || '<p class="iq-rel-plain">No issues proposed — this component reads clean.</p>') + '</div>' +
        '<h4 class="iq-block-h">Evidence ledger</h4>' +
        '<div class="iq-rel-table-wrap"><table class="iq-table iq-table-rel">' +
          '<thead><tr><th>Item</th><th>Check</th><th>Severity</th><th>Penalty</th><th>Decision</th></tr></thead>' +
          '<tbody>' + ledgerRows + '</tbody>' +
        '</table></div>';

      interpEl.innerHTML =
        '<div class="iq-jema">' +
          '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(res.band.label) + ' — ' + res.sdsiPoints.toFixed(1) + ' of ' + res.sdsiWeight + ' reliability points.</div>' +
          '<div class="iq-jema-row"><strong>Evidence.</strong> ' + res.ledger.filter(function (l) { return l.counted; }).length + ' counted issue(s); ' + res.blockers.length + ' launch blocker(s); ' + (res.warnings ? res.warnings.length : 0) + ' advisory caution(s).</div>' +
          '<div class="iq-jema-row"><strong>Meaning.</strong> This is a PRE-DATA reliability-readiness check on the instrument design — it never uses Cronbach’s alpha, omega, item-total or inter-item correlations, or factor analysis (those belong to RSSI after data collection). It asks whether the design supports consistent, stable, interpretable responses.</div>' +
          '<div class="iq-jema-row"><strong>Action.</strong> ' + (res.launchReady
            ? 'No unreviewed blockers. Address the remaining issues to raise the score before launch.'
            : 'Resolve or review the launch blocker(s) before this component is launch-ready — regardless of the score.') + '</div>' +
        '</div>';
    }

    bodyEl.innerHTML = setupHtml();
    resultEl = bodyEl.querySelector('.iq-df-result');
    statusEl = bodyEl.querySelector('.iq-df-status');
    runBtn   = bodyEl.querySelector('.iq-df-run');
    saveBtn  = bodyEl.querySelector('.iq-df-save');

    function readContextFields() {
      bodyEl.querySelectorAll('.iq-vc-field').forEach(function (el) {
        var k = el.getAttribute('data-key');
        var t = el.getAttribute('data-type');
        context[k] = t === 'list' ? strToList(el.value)
                   : t === 'number' ? (el.value === '' ? '' : Math.max(0, parseInt(el.value, 10) || 0))
                   : el.value;
      });
    }
    bodyEl.querySelectorAll('.iq-vc-field').forEach(function (el) {
      el.addEventListener('change', function () {
        var k = el.getAttribute('data-key');
        var t = el.getAttribute('data-type');
        context[k] = t === 'list' ? strToList(el.value)
                   : t === 'number' ? (el.value === '' ? '' : Math.max(0, parseInt(el.value, 10) || 0))
                   : el.value;
        if (k === 'item_count') draw();
      });
    });

    if (runBtn) {
      runBtn.addEventListener('click', function () {
        readContextFields();
        runBtn.disabled = true;
        status('Running AI review of the instrument…');
        fetch('/api/sdsi/reliability-propose.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ survey_id: surveyId, component: component, context: context })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (o) {
            if (!o.ok || !o.j || !o.j.ok) throw new Error((o.j && o.j.message) || 'Proposal failed.');
            seedFromProposal(o.j);
            status((flags.length || 0) + ' issue(s) proposed. Review, then Save.');
            draw();
          })
          .catch(function (e) { status('AI review failed: ' + e.message, true); })
          .then(function () { runBtn.disabled = false; });
      });
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        readContextFields();
        var res = Eng.assess({ flags: flags, mitigations: [], context: context });
        saveBtn.disabled = true;
        status('Saving review…');
        fetch('/api/sdsi/reliability-save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            survey_id: surveyId,
            component: component,
            context: context,
            flags: flags,
            result: {
              score: res.score, sdsiPoints: res.sdsiPoints, band: res.band.key,
              launchReady: res.launchReady, blockerCount: res.blockers.length
            }
          })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (o) {
            if (!o.ok || !o.j || !o.j.ok) throw new Error((o.j && o.j.message) || 'Save failed.');
            status('Saved · ' + res.score + '/100 · ' + res.sdsiPoints.toFixed(1) + '/' + res.sdsiWeight + ' reliability points.');
          })
          .catch(function (e) { status('Save failed: ' + e.message, true); })
          .then(function () { saveBtn.disabled = false; });
      });
    }

    resultEl.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.iq-df-btn') : null;
      if (!btn) return;
      var f = findFlag(btn.getAttribute('data-flag'));
      if (!f) return;
      var act = btn.getAttribute('data-act');
      if (act === 'accept')      f.decision = (f.severity !== f._origSeverity) ? 'severity_overridden' : 'accepted';
      else if (act === 'dismiss') f.decision = 'dismissed';
      else if (act === 'review')  f.blocker_reviewed = true;
      draw();
    });
    resultEl.addEventListener('change', function (e) {
      var sel = e.target.closest ? e.target.closest('.iq-df-sev') : null;
      if (!sel) return;
      var f = findFlag(sel.getAttribute('data-flag'));
      if (!f) return;
      f.severity = sel.value;
      if (f.decision !== 'dismissed') f.decision = (f.severity !== f._origSeverity) ? 'severity_overridden' : 'accepted';
      draw();
    });

    if (live) {
      status('Loading saved review…');
      fetch('/api/sdsi/reliability-get.php?survey_id=' + encodeURIComponent(surveyId) + '&component=' + encodeURIComponent(component), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok && j.exists && j.review) {
            seedFromProposal(j.review);
            bodyEl.querySelectorAll('.iq-vc-field').forEach(function (el) {
              var k = el.getAttribute('data-key');
              var t = el.getAttribute('data-type');
              el.value = t === 'list' ? listToStr(context[k]) : (context[k] == null ? '' : context[k]);
            });
            status('Loaded saved review (updated ' + (j.review.updatedAt || 'previously') + ').');
          } else {
            status('No saved review yet. Declare the context, then Run AI review.');
          }
          draw();
        })
        .catch(function () { status('Could not load a saved review — start a new one.', true); draw(); });
    }

    draw();
  }

  // ==================================================================
  // Administration Readiness — generic renderer for the five PRE-LAUNCH
  // factory lenses. A structural twin of renderReliabilityLens: same factory
  // engine, same settled-flag workflow, same orthogonal launch gate. Reads
  // window.SDSI_ADMINISTRATION_{SPECS,LENSES}; element IDs iqA_<component>_*;
  // hits /api/sdsi/administration-{propose,save,get}.php. PRE-LAUNCH SCOPE:
  // never reviews survey results or post-administration data quality (RSSI).
  // ==================================================================
  function renderAdministrationLens(component) {
    var spec = (window.SDSI_ADMINISTRATION_SPECS || {})[component];
    var Eng  = (window.SDSI_ADMINISTRATION_LENSES || {})[component];
    function vid(s) { return document.getElementById('iqA_' + component + '_' + s); }
    var titleEl = vid('title'), subEl = vid('sub'), bodyEl = vid('body'), interpEl = vid('interp');
    if (!spec || !Eng || !titleEl) {
      if (titleEl) titleEl.textContent = (spec ? spec.name : component);
      if (subEl) subEl.textContent = 'engine unavailable';
      if (bodyEl) bodyEl.innerHTML = '<p class="iq-rel-plain">The administration lens factory (apps/sdsi/validity-lens-engine.js + administration-specs.js) did not load.</p>';
      return;
    }

    var CHECKS = Eng.CHECKS;
    var SEVS   = ['minor', 'moderate', 'major', 'critical'];
    var fields = spec.contextFields || [];

    var studio   = window.RELICHECK_STUDIO || '';
    var surveyId = ((studio === 'survey' || studio === 'strength-survey') && window.RELICHECK_PROJECT_ID)
      ? Number(window.RELICHECK_PROJECT_ID) || 0 : 0;
    var live = surveyId > 0;

    // ── Mutable review state ──
    var context = {};
    var flags   = [];
    var notes   = '';

    function listToStr(v) { return Array.isArray(v) ? v.join(', ') : (v || ''); }
    function strToList(s) {
      return String(s || '').split(/[\r\n,]+/).map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 24);
    }

    function seedFlags(arr) {
      flags = (arr || []).map(function (f, i) {
        return Object.assign({}, f, {
          flag_id: f.flag_id || ('f' + (i + 1)),
          decision: f.decision || 'accepted',
          _origSeverity: (f._origSeverity != null ? f._origSeverity : f.severity),
          blocker_reviewed: !!f.blocker_reviewed
        });
      });
    }
    function seedFromProposal(p) {
      if (p.context) context = Object.assign({}, p.context);
      // Live denominator for the widespread_*_risk advisory warnings: the count
      // of items the proposal actually reviewed. Not a reviewer field; recomputed
      // each render. The reviewer may also type item_count directly.
      if (Array.isArray(p.items) && p.items.length) context.item_count = p.items.length;
      seedFlags(p.flags);
      notes = p.notes || '';
    }

    if (!live && spec.sample) {
      seedFromProposal({ context: spec.sample.context, flags: spec.sample.flags });
    }

    function findFlag(id) { for (var i = 0; i < flags.length; i++) if (flags[i].flag_id === id) return flags[i]; return null; }

    var statusEl = null, runBtn = null, saveBtn = null, resultEl = null;
    function status(msg, isErr) {
      if (!statusEl) return;
      statusEl.textContent = msg || '';
      statusEl.setAttribute('data-tone', isErr ? 'err' : 'ok');
    }

    function fieldHtml(f) {
      var val = context[f.key];
      if (f.type === 'textarea') {
        return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
          '<textarea class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="textarea" rows="2">' + esc(val || '') + '</textarea></label>';
      }
      if (f.type === 'list') {
        return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
          '<input type="text" class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="list" value="' + esc(listToStr(val)) + '"></label>';
      }
      if (f.type === 'number') {
        return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
          '<input type="number" min="0" class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="number" value="' + esc(val == null ? '' : val) + '"></label>';
      }
      if (f.type === 'select') {
        var opts = (f.options || []).map(function (o) {
          return '<option value="' + esc(o.value) + '"' + (o.value === val ? ' selected' : '') + '>' + esc(o.label) + '</option>';
        }).join('');
        return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
          '<select class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="select"><option value="">—</option>' + opts + '</select></label>';
      }
      return '<label class="iq-df-pop-comm iq-vc-field-wrap">' + esc(f.label) +
        '<input type="text" class="iq-vc-field" data-key="' + esc(f.key) + '" data-type="text" value="' + esc(val || '') + '"></label>';
    }

    function setupHtml() {
      var fieldsHtml = fields.length
        ? fields.map(fieldHtml).join('')
        : '<span class="iq-df-sample">This component reads the items directly — no context to declare.</span>';
      return '' +
        '<div class="iq-df-setup">' +
          '<div class="iq-df-pop">' +
            '<span class="iq-df-pop-h">Declared context</span>' +
            (spec.reviewerPrompt ? '<p class="iq-vc-prompt">' + esc(spec.reviewerPrompt) + '</p>' : '') +
            fieldsHtml +
          '</div>' +
          '<div class="iq-df-actions">' +
            (live
              ? '<button type="button" class="iq-df-run">Run AI review</button>' +
                '<button type="button" class="iq-df-save">Save review</button>'
              : '<span class="iq-df-sample">Sample instrument — connect a survey to run the AI review and save.</span>') +
            '<span class="iq-df-status" data-tone="ok"></span>' +
          '</div>' +
        '</div>' +
        '<div class="iq-df-result"></div>';
    }

    function draw() {
      var res = Eng.assess({ flags: flags, mitigations: [], context: context });
      var m = res.math;

      titleEl.textContent = spec.name + ' Score: ' + res.score + '/100  ·  ' +
        res.sdsiPoints.toFixed(1) + '/' + res.sdsiWeight + ' administration points';
      subEl.textContent = res.band.label;

      var mathLine = 'Started at ' + m.startedAt + '   −' + Math.abs(m.cappedPenalty) +
        ' capped penalties   =   ' + (m.startedAt + m.cappedPenalty) +
        (m.raw !== m.final ? '   (clamped to ' + m.final + ')' : '');

      var gateHtml;
      if (res.blockers.length === 0) {
        gateHtml = '<div class="iq-df-gate" data-state="clear">No launch blockers — the administration gate is clear.</div>';
      } else {
        gateHtml = '<div class="iq-df-gate" data-state="' + (res.launchReady ? 'reviewed' : 'blocked') + '">' +
          '<strong>' + (res.launchReady
            ? 'All blockers reviewed — gate cleared.'
            : 'Administration blocker detected — review before launch.') + '</strong>' +
          '<span class="iq-df-gate-note">The launch gate is orthogonal: it does not change the ' + res.score + '/100 score.</span>' +
          '<ul class="iq-df-blockers">' + res.blockers.map(function (b) {
            return '<li>' +
              '<span class="iq-flag" data-tone="alert">Blocker</span> ' + esc(b.label) +
              ' <em>(' + esc(b.item_ref) + ')</em> ' +
              (b.reviewed
                ? '<span class="iq-df-reviewed">reviewed</span>'
                : '<button type="button" class="iq-df-btn" data-act="review" data-flag="' + esc(b.triggeredBy) + '">Mark reviewed</button>') +
            '</li>';
          }).join('') + '</ul>' +
        '</div>';
      }

      // Report-level cautions: advisory only — never change the score or block launch.
      var warnHtml = (res.warnings && res.warnings.length)
        ? '<div class="iq-df-gate" data-state="warn">' +
            '<strong>Report-level caution</strong>' +
            '<span class="iq-df-gate-note">Advisory only — does not change the ' + res.score + '/100 score or block launch.</span>' +
            '<ul class="iq-df-blockers">' + res.warnings.map(function (w) {
              return '<li><span class="iq-flag" data-tone="warn">Caution</span> ' + esc(w.label) + '</li>';
            }).join('') + '</ul>' +
          '</div>'
        : '';

      var cards = flags.map(function (f) {
        var counted = (f.decision === 'accepted' || f.decision === 'severity_overridden');
        var pen = counted ? Eng.SEVERITY_PENALTY[f.severity] : 0;
        var stateTxt = f.decision === 'dismissed' ? 'dismissed (0 pts · kept in ledger)'
                     : f.decision === 'severity_overridden' ? 'severity overridden'
                     : 'kept';
        return '<div class="iq-df-card" data-decision="' + esc(f.decision) + '">' +
          '<div class="iq-df-card-head">' +
            '<span class="iq-flag" data-tone="' + (counted ? 'alert' : 'ok') + '">' + esc(CHECKS[f.check] || f.check) + '</span>' +
            '<span class="iq-df-ref">' + esc(f.item_ref) + (f.section ? ' · ' + esc(f.section) : '') + '</span>' +
            '<span class="iq-df-pen">' + pen + ' pts</span>' +
          '</div>' +
          '<blockquote class="iq-df-quote">' + esc(f.quote) + '</blockquote>' +
          '<p class="iq-df-why"><strong>Why it matters.</strong> ' + esc(f.rationale) + '</p>' +
          (f.suggested_revision ? '<p class="iq-df-fix"><strong>Suggested revision.</strong> ' + esc(f.suggested_revision) + '</p>' : '') +
          '<div class="iq-df-controls">' +
            '<label class="iq-df-sevlabel">Severity ' +
              '<select class="iq-df-sev" data-flag="' + esc(f.flag_id) + '">' +
                SEVS.map(function (s) {
                  return '<option value="' + s + '"' + (s === f.severity ? ' selected' : '') + '>' +
                    s + ' (' + Eng.SEVERITY_PENALTY[s] + ')</option>';
                }).join('') +
              '</select>' +
            '</label>' +
            '<button type="button" class="iq-df-btn" data-act="accept" data-flag="' + esc(f.flag_id) + '"' + (f.decision !== 'dismissed' ? ' data-on="1"' : '') + '>Keep</button>' +
            '<button type="button" class="iq-df-btn" data-act="dismiss" data-flag="' + esc(f.flag_id) + '"' + (f.decision === 'dismissed' ? ' data-on="1"' : '') + '>Dismiss</button>' +
            '<span class="iq-df-state">' + esc(stateTxt) + '</span>' +
          '</div>' +
        '</div>';
      }).join('');

      var ledgerRows = res.ledger.map(function (l) {
        return '<tr data-counted="' + l.counted + '">' +
          '<td class="iq-rel-item">' + esc(l.item_ref) + '</td>' +
          '<td>' + esc(l.checkLabel) + '</td>' +
          '<td>' + esc(l.severity) + '</td>' +
          '<td>' + l.penalty + '</td>' +
          '<td>' + esc(l.decision) + '</td>' +
        '</tr>';
      }).join('');

      if (!resultEl) return;
      resultEl.innerHTML =
        '<div class="iq-rel-summary">' +
          '<p class="iq-rel-summary-line"><strong>' + esc(res.band.label) + '</strong></p>' +
          '<p class="iq-df-math">' + esc(mathLine) + '</p>' +
          '<p class="iq-rel-plain">' + esc(spec.intro || '') + ' The AI proposes the issues below with the exact wording that triggered each; you keep, dismiss, or re-grade them, and the score recomputes from your decisions.</p>' +
          (notes ? '<p class="iq-df-notes"><strong>AI notes.</strong> ' + esc(notes) + '</p>' : '') +
        '</div>' +
        gateHtml +
        warnHtml +
        '<h4 class="iq-block-h">Issues to review</h4>' +
        '<div class="iq-df-cards">' + (cards || '<p class="iq-rel-plain">No issues proposed — this component reads clean.</p>') + '</div>' +
        '<h4 class="iq-block-h">Evidence ledger</h4>' +
        '<div class="iq-rel-table-wrap"><table class="iq-table iq-table-rel">' +
          '<thead><tr><th>Item</th><th>Check</th><th>Severity</th><th>Penalty</th><th>Decision</th></tr></thead>' +
          '<tbody>' + ledgerRows + '</tbody>' +
        '</table></div>';

      interpEl.innerHTML =
        '<div class="iq-jema">' +
          '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(res.band.label) + ' — ' + res.sdsiPoints.toFixed(1) + ' of ' + res.sdsiWeight + ' administration points.</div>' +
          '<div class="iq-jema-row"><strong>Evidence.</strong> ' + res.ledger.filter(function (l) { return l.counted; }).length + ' counted issue(s); ' + res.blockers.length + ' launch blocker(s); ' + (res.warnings ? res.warnings.length : 0) + ' advisory caution(s).</div>' +
          '<div class="iq-jema-row"><strong>Meaning.</strong> This is a PRE-LAUNCH administration-readiness check on whether the survey is ready to be fielded responsibly, clearly, safely, and practically. It never evaluates survey results or post-administration data quality — that belongs to RSSI, after data collection. SIRI asks whether the survey is ready to collect interpretable data before launch.</div>' +
          '<div class="iq-jema-row"><strong>Action.</strong> ' + (res.launchReady
            ? 'No unreviewed blockers. Address the remaining issues to raise the score before launch.'
            : 'Resolve or review the launch blocker(s) before this component is launch-ready — regardless of the score.') + '</div>' +
        '</div>';
    }

    bodyEl.innerHTML = setupHtml();
    resultEl = bodyEl.querySelector('.iq-df-result');
    statusEl = bodyEl.querySelector('.iq-df-status');
    runBtn   = bodyEl.querySelector('.iq-df-run');
    saveBtn  = bodyEl.querySelector('.iq-df-save');

    function readContextFields() {
      bodyEl.querySelectorAll('.iq-vc-field').forEach(function (el) {
        var k = el.getAttribute('data-key');
        var t = el.getAttribute('data-type');
        context[k] = t === 'list' ? strToList(el.value)
                   : t === 'number' ? (el.value === '' ? '' : Math.max(0, parseInt(el.value, 10) || 0))
                   : el.value;
      });
    }
    bodyEl.querySelectorAll('.iq-vc-field').forEach(function (el) {
      el.addEventListener('change', function () {
        var k = el.getAttribute('data-key');
        var t = el.getAttribute('data-type');
        context[k] = t === 'list' ? strToList(el.value)
                   : t === 'number' ? (el.value === '' ? '' : Math.max(0, parseInt(el.value, 10) || 0))
                   : el.value;
        if (k === 'item_count' || k === 'denominator_reviewed' || k === 'page_count') draw();
      });
    });

    if (runBtn) {
      runBtn.addEventListener('click', function () {
        readContextFields();
        runBtn.disabled = true;
        status('Running AI review of the instrument…');
        fetch('/api/sdsi/administration-propose.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ survey_id: surveyId, component: component, context: context })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (o) {
            if (!o.ok || !o.j || !o.j.ok) throw new Error((o.j && o.j.message) || 'Proposal failed.');
            seedFromProposal(o.j);
            status((flags.length || 0) + ' issue(s) proposed. Review, then Save.');
            draw();
          })
          .catch(function (e) { status('AI review failed: ' + e.message, true); })
          .then(function () { runBtn.disabled = false; });
      });
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        readContextFields();
        var res = Eng.assess({ flags: flags, mitigations: [], context: context });
        saveBtn.disabled = true;
        status('Saving review…');
        fetch('/api/sdsi/administration-save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            survey_id: surveyId,
            component: component,
            context: context,
            flags: flags,
            result: {
              score: res.score, sdsiPoints: res.sdsiPoints, band: res.band.key,
              launchReady: res.launchReady, blockerCount: res.blockers.length
            }
          })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (o) {
            if (!o.ok || !o.j || !o.j.ok) throw new Error((o.j && o.j.message) || 'Save failed.');
            status('Saved · ' + res.score + '/100 · ' + res.sdsiPoints.toFixed(1) + '/' + res.sdsiWeight + ' administration points.');
          })
          .catch(function (e) { status('Save failed: ' + e.message, true); })
          .then(function () { saveBtn.disabled = false; });
      });
    }

    resultEl.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.iq-df-btn') : null;
      if (!btn) return;
      var f = findFlag(btn.getAttribute('data-flag'));
      if (!f) return;
      var act = btn.getAttribute('data-act');
      if (act === 'accept')      f.decision = (f.severity !== f._origSeverity) ? 'severity_overridden' : 'accepted';
      else if (act === 'dismiss') f.decision = 'dismissed';
      else if (act === 'review')  f.blocker_reviewed = true;
      draw();
    });
    resultEl.addEventListener('change', function (e) {
      var sel = e.target.closest ? e.target.closest('.iq-df-sev') : null;
      if (!sel) return;
      var f = findFlag(sel.getAttribute('data-flag'));
      if (!f) return;
      f.severity = sel.value;
      if (f.decision !== 'dismissed') f.decision = (f.severity !== f._origSeverity) ? 'severity_overridden' : 'accepted';
      draw();
    });

    if (live) {
      status('Loading saved review…');
      fetch('/api/sdsi/administration-get.php?survey_id=' + encodeURIComponent(surveyId) + '&component=' + encodeURIComponent(component), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok && j.exists && j.review) {
            seedFromProposal(j.review);
            bodyEl.querySelectorAll('.iq-vc-field').forEach(function (el) {
              var k = el.getAttribute('data-key');
              var t = el.getAttribute('data-type');
              el.value = t === 'list' ? listToStr(context[k]) : (context[k] == null ? '' : context[k]);
            });
            status('Loaded saved review (updated ' + (j.review.updatedAt || 'previously') + ').');
          } else {
            status('No saved review yet. Declare the context, then Run AI review.');
          }
          draw();
        })
        .catch(function () { status('Could not load a saved review — start a new one.', true); draw(); });
    }

    draw();
  }

  // ==================================================================
  // First-eigenvalue + first-factor loadings via power iteration.
  // Used by Validity for variance-explained and primary loadings. Same
  // technique as in strength-index/firstEigen — kept local to keep
  // instrument-quality engine self-contained (single source of truth).
  // ==================================================================
  function firstEigen(R) {
    const n = R.length;
    if (!n) return { eigen: 0, vec: [] };
    let v = new Array(n).fill(1 / Math.sqrt(n));
    let eigen = 0;
    for (let iter = 0; iter < 80; iter++) {
      const w = new Array(n).fill(0);
      for (let i = 0; i < n; i++) for (let j = 0; j < n; j++) w[i] += R[i][j] * v[j];
      let norm = 0;
      for (let i = 0; i < n; i++) norm += w[i] * w[i];
      norm = Math.sqrt(norm) || 1;
      const next = w.map(x => x / norm);
      eigen = norm;
      let delta = 0;
      for (let i = 0; i < n; i++) delta += Math.abs(next[i] - v[i]);
      v = next;
      if (delta < 1e-7) break;
    }
    return { eigen: eigen, vec: v };
  }

  // ==================================================================
  // LENS: validity — a validity evidence review, NOT a duplicate of
  // Reliability. Answers four questions per spec:
  //   1. Is this instrument measuring what it claims to measure?
  //   2. What validity evidence supports that claim?
  //   3. What validity evidence weakens that claim?
  //   4. What needs to be fixed before the results can be trusted?
  // Two-layer interpretation: plain-language + research, every row.
  // ==================================================================
  function renderValidity() {
    const prep = prepareLikert();
    if (!prep || !prep.validCols) {
      empty('Validity Review', 'Need at least 2 Likert items with 3+ complete rows.');
      return;
    }
    const { items, validCols, validRows } = prep;
    const k = items.length;
    const R = correlationMatrix(validCols);

    // ---- Diagnostics (computed once, reused across rows) ----
    const itemVar = validCols.reduce((s, c) => s + variance(c), 0);
    const totals  = validRows.map((_, idx) => validCols.reduce((s, c) => s + c[idx], 0));
    const totalVar = variance(totals);
    const alpha = totalVar ? (k / (k - 1)) * (1 - itemVar / totalVar) : 0;
    let avgR = 0, pairs = 0;
    for (let i = 0; i < k; i++) for (let j = i + 1; j < k; j++) { avgR += R[i][j]; pairs++; }
    avgR /= pairs || 1;
    const itemRest = items.map((_, i) => {
      const item = validCols[i];
      const rest = validRows.map((_, idx) => validCols.reduce((s, c, j) => s + (j === i ? 0 : c[idx]), 0));
      return pearson(item, rest);
    });
    const negItems  = items.map((v, i) => ({ name: v.name, r: itemRest[i] })).filter(x => x.r < 0);
    const weakItems = items.map((v, i) => ({ name: v.name, r: itemRest[i] })).filter(x => x.r >= 0 && x.r < 0.30);

    // Scale assignment by name prefix
    const scaleLabel = {
      SA: 'Self-Awareness', SM: 'Self-Management', SO: 'Social Awareness', RM: 'Relationship Management',
      EI: 'Emotional Intelligence', ER: 'Emotional Regulation', EE: 'Engagement',
    };
    function inferScale(name) {
      const base = String(name || '').replace(/_R$/i, '');
      const m = base.match(/^([A-Za-z]+)/);
      if (!m) return null;
      const pref = m[1].toUpperCase();
      return scaleLabel[pref] || pref;
    }
    const inferredScales = Array.from(new Set(items.map(v => inferScale(v.name)).filter(Boolean)));

    // Item wording presence — currently we only have names. A future
    // Survey Builder hookup would attach .prompt; until then content
    // validity reports as Incomplete.
    const hasItemWording = items.some(v => v.prompt && String(v.prompt).trim().length > 6);

    // ---- Validity status — one of 4 levels ----
    let status, statusTone, judgment, decisionGuide, nextStep;
    if (negItems.length >= 3 || alpha < 0.60) {
      status = 'Not Ready'; statusTone = 'alert';
      judgment = 'Significant validity concerns. The instrument is not yet ready to support strong claims, publication, or high-stakes decisions.';
      decisionGuide = 'Do not use for decisions yet. The instrument needs scoring corrections and item review before it can be trusted.';
      nextStep = 'Confirm reverse-coding on every _R item, rerun reliability, and review whether the instrument is one scale or several subscales.';
    } else if (negItems.length || weakItems.length >= Math.ceil(k * 0.2) || avgR < 0.20 || alpha < 0.70) {
      status = 'Use with Caution'; statusTone = 'caution';
      judgment = 'Mixed Evidence. The instrument has acceptable internal consistency, but item-level evidence suggests construct alignment problems.' +
        (negItems.length ? ' ' + negItems.length + ' reverse-coded item' + (negItems.length === 1 ? '' : 's') + ' ' + (negItems.length === 1 ? 'is' : 'are') + ' moving opposite the total scale,' : '') +
        ' and the average inter-item relationship is ' + (avgR < 0.20 ? 'weaker than expected' : 'on the lower edge of acceptable') + '.';
      decisionGuide = 'Use with caution. The instrument may be appropriate for exploratory analysis or internal review, but it should not be used for strong claims, publication, or high-stakes decisions until the flagged items and scale structure are reviewed.';
      nextStep = 'Confirm whether all _R items were reverse-scored. Then rerun reliability and factor structure checks. Also evaluate whether the instrument should be analyzed as separate subscales rather than one total score.';
    } else if (avgR < 0.30 || weakItems.length) {
      status = 'Mixed Evidence'; statusTone = 'warn';
      judgment = 'Validity evidence is mixed. The instrument is usable for exploratory analysis but should be strengthened before publication.';
      decisionGuide = 'Acceptable for internal use and exploratory analysis. Resolve the flagged items before publication or external reporting.';
      nextStep = 'Inspect the weak-item-rest items, consider revising or dropping them, and report by subscale where possible.';
    } else {
      status = 'Strong'; statusTone = 'strong';
      judgment = 'Validity evidence is strong. Items converge on the intended construct and the scale supports confident reporting.';
      decisionGuide = 'Ready to use. Standard caveats around content validity (expert wording review) still apply.';
      nextStep = 'No statistical correction needed. Confirm content and face validity with a subject-matter expert before publication.';
    }

    // ---- Header ----
    document.getElementById('iqValTitle').textContent = 'Validity Review: ' + status;
    document.getElementById('iqValSub').textContent = judgment;

    // ---- Status pill + 4 metric cards ----
    function statusOf(value, kind) {
      // kind: 'alpha' | 'avgR' | 'flags' | 'n'
      if (kind === 'alpha') {
        if (value >= 0.80) return { label: 'Strong',     tone: 'strong' };
        if (value >= 0.70) return { label: 'Acceptable', tone: 'ok'     };
        if (value >= 0.60) return { label: 'Watch',      tone: 'warn'   };
        return                    { label: 'Problem',    tone: 'alert'  };
      }
      if (kind === 'avgR') {
        if (value >= 0.30 && value <= 0.50) return { label: 'Strong',  tone: 'strong' };
        if (value >= 0.20 || value > 0.50)  return { label: 'Watch',   tone: 'warn'   };
        return                                     { label: 'Problem', tone: 'alert'  };
      }
      if (kind === 'flags') {
        if (value === 0) return { label: 'None',    tone: 'strong' };
        if (value <= 2)  return { label: 'Watch',   tone: 'warn'   };
        return                  { label: 'Problem', tone: 'alert'  };
      }
      if (kind === 'n') {
        if (value >= 200) return { label: 'Strong',     tone: 'strong' };
        if (value >= 100) return { label: 'Acceptable', tone: 'ok'     };
        if (value >=  30) return { label: 'Watch',      tone: 'warn'   };
        return                   { label: 'Problem',    tone: 'alert'  };
      }
      return { label: '—', tone: 'ok' };
    }
    const aSt = statusOf(alpha, 'alpha');
    const rSt = statusOf(avgR, 'avgR');
    const fSt = statusOf(negItems.length + weakItems.length, 'flags');
    const nSt = statusOf(validRows.length, 'n');

    // ---- Validity Evidence Table (7 rows per spec) ----
    const negNames = negItems.map(x => x.name).join(', ');
    const evidenceRows = [
      {
        check: 'Overall internal consistency',
        evidence: "Cronbach's α = " + fmt(alpha, 2),
        status: alpha >= 0.80 ? 'Strong' : alpha >= 0.70 ? 'Acceptable' : alpha >= 0.60 ? 'Watch' : 'Problem',
        tone: alpha >= 0.80 ? 'strong' : alpha >= 0.70 ? 'ok' : alpha >= 0.60 ? 'warn' : 'alert',
        plain: alpha >= 0.70 ? 'The items mostly work together as a set.' :
               alpha >= 0.60 ? 'The items partially work together but reliability is weak.' :
                               'The items are not working together well enough to be treated as one scale.',
        research: alpha >= 0.70
          ? 'Alpha exceeds the conventional .70 research threshold and suggests acceptable internal consistency.'
          : 'Alpha is below the conventional .70 research threshold; internal consistency is questionable as-is.',
        action: 'Keep, but do not rely on alpha alone. Review item-level evidence.',
      },
      {
        check: 'Item convergence',
        evidence: 'Avg inter-item r = ' + fmt(avgR, 2),
        status: (avgR >= 0.30 && avgR <= 0.50) ? 'Acceptable' : (avgR >= 0.20 || avgR > 0.50) ? 'Watch' : 'Problem',
        tone: (avgR >= 0.30 && avgR <= 0.50) ? 'ok' : (avgR >= 0.20 || avgR > 0.50) ? 'warn' : 'alert',
        plain: avgR < 0.20
          ? 'The items may not be closely related enough to represent one clean idea.'
          : avgR > 0.50
            ? 'The items are very tightly clustered; some may be redundant.'
            : 'The items are reasonably related to each other.',
        research: avgR < 0.30
          ? 'Average inter-item correlation is below the commonly preferred range for a coherent unidimensional scale. This may suggest multidimensionality or weak construct convergence.'
          : avgR > 0.50
            ? 'Average inter-item correlation is above 0.50; some items may be near-duplicates and inflate α artificially.'
            : 'Average inter-item correlation falls in the conventional range for a coherent unidimensional scale.',
        action: avgR < 0.30
          ? 'Review whether the instrument should be scored as subscales rather than one total score.'
          : 'No change required.',
      },
      {
        check: 'Reverse-coded item behavior',
        evidence: negItems.length
          ? negItems.length + ' negative item-rest correlations: ' + negNames
          : 'No negative item-rest correlations.',
        status: negItems.length ? 'Problem' : 'Strong',
        tone: negItems.length ? 'alert' : 'strong',
        plain: negItems.length
          ? 'Several items are moving in the opposite direction from the rest of the survey.'
          : 'All items move in the same direction as the total score.',
        research: negItems.length
          ? 'Negative item-rest correlations suggest reverse-scoring error, wording-method effect, or construct misfit.'
          : 'No reverse-direction items detected; scoring direction appears consistent.',
        action: negItems.length
          ? 'Confirm reverse-coding and rerun reliability. If still negative, revise or remove those items.'
          : 'No action required.',
      },
      {
        check: 'Construct alignment',
        evidence: inferredScales.length > 1
          ? 'Items come from multiple named areas: ' + inferredScales.join(', ')
          : (inferredScales.length === 1 ? 'Items appear to come from one named area: ' + inferredScales[0] : 'Scale assignment could not be inferred from item names.'),
        status: inferredScales.length > 1 ? 'Watch' : 'Acceptable',
        tone:   inferredScales.length > 1 ? 'warn'  : 'ok',
        plain: inferredScales.length > 1
          ? 'The survey may be measuring several related skills, not one single construct.'
          : 'The items appear to target a single construct.',
        research: inferredScales.length > 1
          ? 'Combining multiple theoretical subdomains into one total score may obscure dimensionality and weaken construct validity evidence.'
          : 'Items appear to belong to a single theoretical domain; construct alignment evidence is consistent.',
        action: inferredScales.length > 1
          ? 'Report validity evidence by intended subscale.'
          : 'No change required.',
      },
      {
        check: 'Score interpretability',
        evidence: (avgR < 0.30 || negItems.length)
          ? 'Full-scale score affected by ' + (avgR < 0.30 ? 'low average inter-item correlation' : '') + (avgR < 0.30 && negItems.length ? ' and ' : '') + (negItems.length ? 'flagged items' : '')
          : 'Full-scale composite is straightforward to interpret with current evidence.',
        status: (avgR < 0.30 || negItems.length) ? 'Watch' : 'Strong',
        tone:   (avgR < 0.30 || negItems.length) ? 'warn'  : 'strong',
        plain: (avgR < 0.30 || negItems.length)
          ? 'The total score may be hard to interpret until item issues are fixed.'
          : 'The total score is interpretable as-is.',
        research: (avgR < 0.30 || negItems.length)
          ? 'A full-scale composite may not be defensible if item direction and dimensionality are unresolved.'
          : 'Composite scoring is supported by current diagnostics.',
        action: (avgR < 0.30 || negItems.length)
          ? 'Use subscale scores or rerun analysis after scoring corrections.'
          : 'Composite score is appropriate.',
      },
      {
        check: 'Content / face validity',
        evidence: hasItemWording ? 'Item wording present; awaiting expert review.' : 'Actual item wording not shown in the dataset.',
        status: 'Incomplete',
        tone: 'warn',
        plain: hasItemWording
          ? 'ReliCheck has the wording — but content validity always requires expert judgment, not just numbers.'
          : 'ReliCheck cannot fully judge whether the questions are clear or aligned without the actual wording.',
        research: 'Content and face validity require item prompt text, not variable names alone.',
        action: hasItemWording
          ? 'Have a subject-matter expert review item wording for clarity and construct fit.'
          : 'Connect item prompts from Survey Builder or upload the item text.',
      },
      {
        check: 'Decision readiness',
        evidence: status === 'Strong'
          ? 'Reliability, convergence, and construct alignment all clear.'
          : status === 'Mixed Evidence'
            ? 'Reliability acceptable; convergence or alignment concerns remain.'
            : status === 'Use with Caution'
              ? 'Reliability acceptable, but item and construct concerns remain.'
              : 'Reliability or item integrity below acceptable thresholds.',
        status: status === 'Strong' ? 'Ready' : status === 'Mixed Evidence' ? 'Exploratory only' : status === 'Use with Caution' ? 'Use with caution' : 'Not ready',
        tone: statusTone,
        plain: status === 'Strong'
          ? 'The instrument can be used for confident reporting.'
          : status === 'Not Ready'
            ? 'The instrument should not yet drive any decisions.'
            : 'The instrument can be explored, but should not yet drive strong decisions.',
        research: status === 'Strong'
          ? 'Evidence supports publication and external reporting.'
          : 'Evidence supports preliminary use, but construct validity is not fully established.',
        action: status === 'Strong'
          ? 'Proceed to reporting.'
          : 'Resolve flagged items before publication, external reporting, or high-stakes use.',
      },
    ];

    const tbody = evidenceRows.map(r => (
      '<tr data-tone="' + r.tone + '">' +
        '<td class="iq-vt-check">' + esc(r.check) + '</td>' +
        '<td class="iq-vt-evidence">' + esc(r.evidence) + '</td>' +
        '<td><span class="iq-flag" data-tone="' + r.tone + '">' + esc(r.status) + '</span></td>' +
        '<td class="iq-vt-plain">' + esc(r.plain) + '</td>' +
        '<td class="iq-vt-research">' + esc(r.research) + '</td>' +
        '<td class="iq-vt-action">' + esc(r.action) + '</td>' +
      '</tr>'
    )).join('');

    // ---- Parametric plain-language + researcher paragraphs below table ----
    const negList = negItems.slice(0, 5).map(x => x.name).join(', ');
    const plainPara =
      'The instrument shows ' + (alpha >= 0.70 ? 'acceptable' : 'questionable') + ' overall reliability, but the validity evidence is ' +
      (status === 'Strong' ? 'strong overall.' : 'mixed.') +
      " Cronbach's α = " + fmt(alpha, 2) + ' ' +
      (alpha >= 0.70 ? 'suggests the items have usable internal consistency, ' : 'suggests internal consistency is below the conventional threshold, ') +
      (avgR < 0.30 || negItems.length
        ? 'while the ' + (avgR < 0.30 ? 'low average inter-item correlation' : '') +
          (avgR < 0.30 && negItems.length ? ' and ' : '') +
          (negItems.length ? negItems.length + ' negative item-rest correlation' + (negItems.length === 1 ? '' : 's') : '') +
          ' indicate that some items may not align with the intended construct.'
        : 'and item-level diagnostics also support that read.') +
      (negItems.length
        ? ' The most likely issue is reverse-coding or item-direction mismatch. Confirm scoring, review the flagged items, and rerun validity checks before using the results for strong claims.'
        : '');

    const researchPara =
      'Although internal consistency is ' + (alpha >= 0.70 ? 'acceptable' : 'below threshold') + ', construct validity is not fully supported by alpha alone. ' +
      (negItems.length || avgR < 0.30
        ? 'The ' + (avgR < 0.30 ? 'low average inter-item correlation' : 'inter-item structure') +
          (negItems.length ? ' and negative item-rest correlations' : '') +
          ' suggest possible reverse-scoring errors, multidimensionality, wording-method effects, or item-level construct misfit. '
        : 'No statistical patterns point to multidimensionality or reverse-scoring issues. ') +
      'Validity evidence should be ' + (negItems.length ? 'recalculated after reverse-coded items are confirmed and ' : '') + 'subscale-level structure ' + (inferredScales.length > 1 ? 'tested.' : 'verified.');

    // ---- Recommended actions list (parametric) ----
    const recommendedActions = [];
    if (negItems.length) recommendedActions.push('Confirm scoring direction for all _R items.');
    if (negItems.length || alpha < 0.70) recommendedActions.push('Rerun reliability after reverse-coding is verified.');
    if (inferredScales.length > 1) recommendedActions.push('Test subscale-level reliability for ' + inferredScales.join(', ') + '.');
    recommendedActions.push('Run factor readiness and factor structure checks.');
    if (!hasItemWording) recommendedActions.push('Upload or connect actual item wording so ReliCheck can assess content and face validity.');
    if (status !== 'Strong') recommendedActions.push('Avoid strong claims until flagged items are resolved.');

    // ---- Closing interpretation (parametric, parallel to Reliability) ----
    const closingPara = negItems.length
      ? 'The instrument shows usable reliability, but the validity evidence is mixed. The strongest warning is that reverse-coded items (' + negList + ') are moving against the rest of the scale. The next step is to confirm scoring direction, rerun validity checks, and decide whether to report subscale scores rather than one total score.'
      : status === 'Strong'
        ? 'Items converge on the intended construct cleanly. The instrument supports confident reporting. Standard content-validity review by a subject-matter expert is still warranted before publication.'
        : 'The instrument shows usable reliability but mixed validity evidence. Items flagged in the table need review before this instrument supports strong claims.';

    // ---- Compose body — SAME structure as Reliability ----
    // Layout:
    //   1. iq-rel-summary (tinted box) — bold summary line + labeled
    //      plain-language and research paragraphs
    //   2. h4 + table (iq-table-rel for identical chrome)
    //   3. iq-rel-closing (accent-soft box) — single closing paragraph
    //   4. J/E/M/A footer (iq-foot)
    document.getElementById('iqValBody').innerHTML =
      '<div class="iq-rel-summary">' +
        '<h4 class="iq-block-h">Validity summary</h4>' +
        '<p class="iq-rel-summary-line"><strong>' + esc(judgment) + '</strong></p>' +
        '<div class="iq-rel-paragraphs">' +
          '<div><span class="iq-rel-label">Plain-language explanation.</span> ' + esc(plainPara) + '</div>' +
          '<div><span class="iq-rel-label">Research interpretation.</span> ' + esc(researchPara) + '</div>' +
        '</div>' +
      '</div>' +
      '<h4 class="iq-block-h">Validity evidence</h4>' +
      '<div class="iq-rel-table-wrap">' +
        '<table class="iq-table iq-table-rel">' +
          '<thead><tr>' +
            '<th>Validity check</th>' +
            '<th>Evidence found</th>' +
            '<th>Status</th>' +
            '<th>Plain-language meaning</th>' +
            '<th>Research interpretation</th>' +
            '<th>Recommended action</th>' +
          '</tr></thead>' +
          '<tbody>' + tbody + '</tbody>' +
        '</table>' +
      '</div>' +
      '<div class="iq-rel-closing"><h4 class="iq-block-h">Interpretation</h4>' +
        '<p>' + esc(closingPara) + '</p>' +
      '</div>';

    // J/E/M/A interpretation footer mirrors Reliability for consistency
    document.getElementById('iqValInterp').innerHTML =
      '<div class="iq-jema">' +
        '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(judgment) + '</div>' +
        '<div class="iq-jema-row"><strong>Evidence.</strong> α = ' + fmt(alpha, 2) +
          '; avg inter-item r = ' + fmt(avgR, 2) +
          (negItems.length ? '; ' + negItems.length + ' negative item-rest correlation(s): ' + negList : '') +
          (inferredScales.length > 1 ? '; ' + inferredScales.length + ' inferred subscales (' + inferredScales.join(', ') + ')' : '') +
          '.</div>' +
        '<div class="iq-jema-row"><strong>Meaning.</strong> ' + esc(researchPara) + '</div>' +
        '<div class="iq-jema-row"><strong>Action.</strong> ' + esc(nextStep) + '</div>' +
      '</div>';

    exposeAppState({
      lens: 'validity', status: status, alpha: alpha, avgR: avgR,
      negItems: negItems.map(x => x.name), weakItems: weakItems.map(x => x.name),
      inferredScales: inferredScales, evidenceRows: evidenceRows.map(r => ({ check: r.check, status: r.status })),
      recommendedActions: recommendedActions,
    });
  }

  // ==================================================================
  // LENS: reliability — interpretive item-level diagnostics table.
  // Every row carries a Flag + plain-language meaning + research
  // interpretation + recommended action. Per spec: the table never
  // leaves the user with only numbers.
  // ==================================================================
  function renderReliability() {
    const prep = prepareLikert();
    if (!prep || !prep.validCols) {
      empty('Reliability', 'Need at least 2 Likert items with 3+ complete rows.');
      return;
    }
    const { items, validCols, validRows } = prep;
    const k = items.length;

    // Overall α
    const itemVar = validCols.reduce((s, c) => s + variance(c), 0);
    const totals  = validRows.map((_, idx) => validCols.reduce((s, c) => s + c[idx], 0));
    const totalVar = variance(totals);
    const alpha = totalVar ? (k / (k - 1)) * (1 - itemVar / totalVar) : 0;

    // Inter-item r (avg)
    const R = correlationMatrix(validCols);
    let avgR = 0, pairs = 0;
    for (let i = 0; i < k; i++) for (let j = i + 1; j < k; j++) { avgR += R[i][j]; pairs++; }
    avgR /= pairs || 1;

    // Per-item: variance, item-rest correlation, α-if-deleted, scale
    function alphaIfDeleted(idx) {
      const cols2 = validCols.filter((_, i) => i !== idx);
      const k2 = cols2.length;
      if (k2 < 2) return null;
      const itemVar2 = cols2.reduce((s, c) => s + variance(c), 0);
      const totals2  = validRows.map((_, ri) => cols2.reduce((s, c) => s + c[ri], 0));
      const totalVar2 = variance(totals2);
      return totalVar2 ? (k2 / (k2 - 1)) * (1 - itemVar2 / totalVar2) : 0;
    }

    // Detect scale assignment from item name. Prefix before _ or digits is
    // the construct (e.g., "SA1" → "Self-Awareness" if mapped, else "SA").
    // Reverse-coded heuristic: name ends in _R.
    const scaleLabel = {
      SA: 'Self-Awareness', SM: 'Self-Management', SO: 'Social Awareness', RM: 'Relationship Management',
      EI: 'Emotional Intelligence', ER: 'Emotional Regulation', EE: 'Engagement',
    };
    function inferScale(name) {
      const base = String(name || '').replace(/_R$/i, '');
      const m = base.match(/^([A-Za-z]+)/);
      if (!m) return '—';
      const pref = m[1].toUpperCase();
      return scaleLabel[pref] || pref;
    }
    function isReverse(name) { return /_R$/i.test(String(name || '')); }

    const rows = items.map((v, i) => {
      const col = validCols[i];
      const itVar = variance(col);
      const rest = validRows.map((_, idx) => validCols.reduce((s, c, j) => s + (j === i ? 0 : c[idx]), 0));
      const ir = pearson(col, rest);
      const aId = alphaIfDeleted(i);
      const reverse = isReverse(v.name);

      // Flag rules per spec
      let flag, flagTone, plainMeaning, researchInterp, action;
      if (ir < 0) {
        flag = 'Problem'; flagTone = 'alert';
        plainMeaning = 'This item is moving in the opposite direction from the rest of the scale.';
        researchInterp = 'Negative corrected item-rest correlation suggests reverse-coding error, scoring-direction issue, or construct misfit.';
        action = reverse
          ? 'Check reverse-coding immediately. If already rescored, review wording or remove from scale.'
          : 'Confirm scoring direction or whether this item belongs to the scale.';
      } else if (ir < 0.10) {
        flag = 'Problem'; flagTone = 'alert';
        plainMeaning = 'This item barely relates to the rest of the scale.';
        researchInterp = 'Item-rest correlation below 0.10 — this item contributes essentially no shared variance to the scale.';
        action = 'Remove or rewrite this item before reporting reliability.';
      } else if (ir < 0.30) {
        flag = 'Watch'; flagTone = 'warn';
        plainMeaning = 'This item only weakly fits with the rest of the scale.';
        researchInterp = 'Corrected item-rest correlation between 0.10 and 0.30 — the item adds little to internal consistency.';
        action = 'Inspect wording. If "alpha if deleted" exceeds the overall α, consider revising or removing.';
      } else if (ir < 0.60) {
        flag = 'Good'; flagTone = 'ok';
        plainMeaning = 'This item moves well with the rest of the scale.';
        researchInterp = 'Corrected item-rest correlation is above 0.30 and supports internal consistency.';
        action = 'Keep item.';
      } else {
        flag = 'Strong'; flagTone = 'strong';
        plainMeaning = 'This item strongly fits with the rest of the scale.';
        researchInterp = 'Corrected item-rest correlation is strong and contributes positively to scale reliability.';
        action = 'Keep item.';
      }

      return {
        name: v.name, scale: inferScale(v.name), variance: itVar, itemRest: ir, alphaIfDeleted: aId,
        reverse: reverse, flag: flag, flagTone: flagTone,
        plainMeaning: plainMeaning, researchInterp: researchInterp, action: action,
      };
    });

    // Counts by flag
    const counts = { Strong: 0, Good: 0, Watch: 0, Problem: 0 };
    rows.forEach(r => counts[r.flag]++);
    const reverseProblems = rows.filter(r => r.flag === 'Problem' && (r.reverse || r.itemRest < 0));

    // ---- Render the page ----
    document.getElementById('iqRelTitle').textContent = 'Reliability: ' + k + ' Likert items across ' + new Set(rows.map(r => r.scale)).size + ' scale(s)';
    document.getElementById('iqRelSub').textContent =
      'Cronbach\'s α = ' + fmt(alpha, 3) +
      '  ·  avg inter-item r = ' + fmt(avgR, 2) +
      '  ·  ' + counts.Strong + ' strong · ' + counts.Good + ' good · ' + counts.Watch + ' watch · ' + counts.Problem + ' problem' +
      '  ·  ' + validRows.length + ' complete rows';

    // Reliability Summary (the paragraph above the table)
    let summary;
    if (counts.Problem === 0 && counts.Watch <= 1 && alpha >= 0.80) {
      summary = 'The scale is reliable and item-level diagnostics support strong inferences.';
    } else if (counts.Problem === 0 && alpha >= 0.70) {
      summary = 'The scale is acceptable overall; a few items are worth a second look but nothing blocks reporting.';
    } else if (alpha >= 0.70) {
      summary = 'The scale is acceptable overall, but item-level diagnostics show several items need review before this scale should be used for strong conclusions.';
    } else {
      summary = 'Reliability is below the conventional acceptability threshold. Address the flagged items before reporting any scale-based finding.';
    }

    // Plain-language explanation paragraph
    let plain;
    const reverseStr = reverseProblems.length
      ? 'However, the items marked with _R are moving in the opposite direction from the rest of the scale. That usually means they may need to be reverse-scored, or they may be asking something different from the other items.'
      : (counts.Problem
          ? 'A small number of items are not pulling with the rest of the scale and should be revisited before this scale is used for strong inferences.'
          : 'All items are pulling in the same direction, which means the scale has a usable level of consistency.');
    plain = (counts.Strong + counts.Good >= Math.ceil(k * 0.6)
              ? 'Most items are working together, which means the scale has a usable level of consistency. '
              : 'A meaningful share of items are not working together with the rest of the scale. ')
            + reverseStr;

    // Research interpretation paragraph
    const research = "Cronbach's α = " + fmt(alpha, 3) + ' ' +
      (alpha >= 0.80 ? 'meets the conventional threshold for confirmatory research.' :
       alpha >= 0.70 ? 'meets the conventional minimum threshold for exploratory research.' :
                       'is below the conventional 0.70 acceptability threshold.') +
      (reverseProblems.length
        ? ' However, negative item-rest correlations for reverse-coded items suggest possible scoring-direction problems, method effects, or construct misfit. Reliability should be recalculated after reverse-coded items are confirmed.'
        : (counts.Problem
            ? ' Item-level diagnostics flag ' + counts.Problem + ' item(s) that should be reviewed before reliability is interpreted as final.'
            : ' Item-level diagnostics show no items working against the scale.'));

    // Closing interpretation paragraph
    const closing =
      (counts.Strong + counts.Good > 0
        ? 'Most visible items show good to strong item-rest correlations, meaning they are contributing to the scale. '
        : '') +
      (reverseProblems.length
        ? 'The major concern is the reverse-coded items. ' +
          reverseProblems.slice(0, 4).map(r => r.name).join(' and ') +
          ' have ' + (reverseProblems.length > 1 ? '' : 'a ') + 'large negative item-rest correlation' +
          (reverseProblems.length > 1 ? 's' : '') + ', which strongly suggests a reverse-scoring issue or item misalignment. '
        : (counts.Problem
            ? 'Items flagged as Problem are not contributing to the scale and should be addressed first. '
            : '')) +
      'The next step is to verify scoring direction, rerun reliability, and then decide whether the flagged items should be retained, revised, or removed.';

    // ---- Build the table ----
    const tbody = rows.map(r => (
      '<tr data-tone="' + r.flagTone + '">' +
        '<td class="iq-rel-item">' + esc(r.name) + (r.reverse ? ' <span class="iq-rel-tag">reverse-coded</span>' : '') + '</td>' +
        '<td>' + esc(r.scale) + '</td>' +
        '<td class="iq-num">' + fmt(r.variance, 3) + '</td>' +
        '<td class="iq-num">' + fmt(r.itemRest, 3) + '</td>' +
        '<td class="iq-num">' + fmt(r.alphaIfDeleted, 3) + '</td>' +
        '<td><span class="iq-flag" data-tone="' + r.flagTone + '">' + r.flag + '</span></td>' +
        '<td class="iq-rel-plain">' + esc(r.plainMeaning) + '</td>' +
        '<td class="iq-rel-research">' + esc(r.researchInterp) + '</td>' +
        '<td class="iq-rel-action">' + esc(r.action) + '</td>' +
      '</tr>'
    )).join('');

    document.getElementById('iqRelBody').innerHTML =
      '<div class="iq-rel-summary">' +
        '<h4 class="iq-block-h">Reliability summary</h4>' +
        '<p class="iq-rel-summary-line"><strong>' + esc(summary) + '</strong></p>' +
        '<div class="iq-rel-paragraphs">' +
          '<div><span class="iq-rel-label">Plain-language explanation.</span> ' + esc(plain) + '</div>' +
          '<div><span class="iq-rel-label">Research interpretation.</span> ' + esc(research) + '</div>' +
        '</div>' +
      '</div>' +
      '<h4 class="iq-block-h">Item-level diagnostics</h4>' +
      '<div class="iq-rel-table-wrap">' +
        '<table class="iq-table iq-table-rel">' +
          '<thead><tr>' +
            '<th>Item</th><th>Scale / construct</th>' +
            '<th class="iq-num">Variance</th>' +
            '<th class="iq-num">Item-rest r</th>' +
            '<th class="iq-num">α if deleted</th>' +
            '<th>Flag</th>' +
            '<th>Plain-language meaning</th>' +
            '<th>Research interpretation</th>' +
            '<th>Recommended action</th>' +
          '</tr></thead>' +
          '<tbody>' + tbody + '</tbody>' +
        '</table>' +
      '</div>' +
      '<div class="iq-rel-closing"><h4 class="iq-block-h">Interpretation</h4>' +
        '<p>' + esc(closing) + '</p>' +
      '</div>';

    // J/E/M/A footer (mirrors what Validity uses)
    document.getElementById('iqRelInterp').innerHTML =
      '<div class="iq-jema">' +
        '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(summary) + '</div>' +
        '<div class="iq-jema-row"><strong>Evidence.</strong> α = ' + fmt(alpha, 3) +
          '; avg inter-item r = ' + fmt(avgR, 2) +
          '; ' + counts.Strong + ' strong, ' + counts.Good + ' good, ' + counts.Watch + ' watch, ' + counts.Problem + ' problem items' +
          (reverseProblems.length ? '; reverse-coded items with negative item-rest: ' + reverseProblems.map(r => r.name).join(', ') : '') + '.</div>' +
        '<div class="iq-jema-row"><strong>Meaning.</strong> ' + esc(research) + '</div>' +
        '<div class="iq-jema-row"><strong>Action.</strong> ' + esc(closing) + '</div>' +
      '</div>';

    exposeAppState({
      lens: 'reliability', alpha: alpha, avgR: avgR, counts: counts,
      items: rows.map(r => ({ name: r.name, scale: r.scale, itemRest: r.itemRest, alphaIfDeleted: r.alphaIfDeleted, flag: r.flag, action: r.action })),
      reverseProblems: reverseProblems.map(r => r.name),
    });
  }

  // ==================================================================
  // LENS: construct_alignment — does the empirical clustering match
  // the named scale structure, or is it driven by wording/scoring?
  // ==================================================================
  function renderConstructAlignment() {
    const prep = prepareLikert();
    if (!prep || !prep.validCols) {
      empty('Construct Alignment', 'Need at least 2 Likert items with 3+ complete rows.');
      return;
    }
    const { items, validCols, validRows } = prep;
    const k = items.length;

    // --- Helpers ---
    const scaleLabel = {
      SA: 'Self-Awareness', SM: 'Self-Management', SO: 'Social Awareness', RM: 'Relationship Management',
      EI: 'Emotional Intelligence', ER: 'Emotional Regulation', EE: 'Engagement',
    };
    function inferScale(name) {
      const base = String(name || '').replace(/_R$/i, '');
      const m = base.match(/^([A-Za-z]+)/);
      if (!m) return null;
      const pref = m[1].toUpperCase();
      return scaleLabel[pref] || pref;
    }
    function isReverse(name) { return /_R$/i.test(String(name || '')); }

    // --- Item-rest correlations against the full pooled total ---
    const itemRest = items.map((_, i) => {
      const item = validCols[i];
      const rest = validRows.map((_, idx) => validCols.reduce((s, c, j) => s + (j === i ? 0 : c[idx]), 0));
      return pearson(item, rest);
    });

    // --- Two-cluster split: positively correlated with total vs negatively ---
    // Items whose item-rest correlation is ≥ 0 join Cluster 1 (general factor).
    // Items with negative item-rest correlation join Cluster 2 (wording-method).
    // Items with extremely weak item-rest (|r| < 0.05) are listed as Cluster 1
    // with a Watch note (they're not contributing to either direction).
    const cluster1Idx = [], cluster2Idx = [];
    items.forEach((_, i) => {
      if (itemRest[i] < 0) cluster2Idx.push(i);
      else cluster1Idx.push(i);
    });

    // --- α and avg r per cluster ---
    function clusterStats(idxList) {
      if (idxList.length < 2) return { alpha: null, avgR: null, k: idxList.length };
      const cols = idxList.map(i => validCols[i]);
      const km = cols.length;
      const itemVar = cols.reduce((s, c) => s + variance(c), 0);
      const totals  = validRows.map((_, ri) => cols.reduce((s, c) => s + c[ri], 0));
      const totalVar = variance(totals);
      const alpha = totalVar ? (km / (km - 1)) * (1 - itemVar / totalVar) : 0;
      const Rsub = correlationMatrix(cols);
      let aR = 0, p = 0;
      for (let i = 0; i < km; i++) for (let j = i + 1; j < km; j++) { aR += Rsub[i][j]; p++; }
      aR /= p || 1;
      return { alpha: alpha, avgR: aR, k: km };
    }
    const c1Stats = clusterStats(cluster1Idx);
    const c2Stats = clusterStats(cluster2Idx);

    // --- Intended constructs detected from item names ---
    const intendedConstructs = Array.from(new Set(items.map(v => inferScale(v.name)).filter(Boolean)));

    // --- Method-effect detection ---
    // Cluster 2 is a "wording-method" cluster iff:
    //   - it has ≥ 2 items
    //   - at least 80% of cluster 2 items are _R OR all came from ≥2 different intended constructs
    const c2Names = cluster2Idx.map(i => items[i].name);
    const c2Constructs = Array.from(new Set(c2Names.map(inferScale).filter(Boolean)));
    const c2RevShare = c2Names.length ? (c2Names.filter(isReverse).length / c2Names.length) : 0;
    const methodEffect = cluster2Idx.length >= 2 && (c2RevShare >= 0.8 || c2Constructs.length >= 2);

    // --- Cluster 1 is "broad general" if it spans ≥2 intended constructs ---
    const c1Names = cluster1Idx.map(i => items[i].name);
    const c1Constructs = Array.from(new Set(c1Names.map(inferScale).filter(Boolean)));
    const c1Broad = cluster1Idx.length >= 2 && c1Constructs.length >= 2;

    const detectedClusters = (cluster1Idx.length >= 2 ? 1 : 0) + (cluster2Idx.length >= 2 ? 1 : 0);

    // --- Status ---
    let status, statusTone;
    if (methodEffect)                                                                  { status = 'Method-effect warning'; statusTone = 'alert'; }
    else if (detectedClusters === intendedConstructs.length && c1Constructs.length === 1) { status = 'Strong alignment';     statusTone = 'strong'; }
    else if (cluster2Idx.length === 0 && intendedConstructs.length === 1)               { status = 'Mostly aligned';       statusTone = 'ok'; }
    else if (cluster2Idx.length === 0)                                                  { status = 'Mixed alignment';      statusTone = 'warn'; }
    else                                                                                { status = 'Not aligned';          statusTone = 'alert'; }

    // --- Header ---
    document.getElementById('iqCaTitle').textContent = 'Construct Alignment Review: ' + status;

    const headlineInterp = methodEffect
      ? 'The detected clusters do not cleanly match the intended scale names. Items appear to separate by wording/scoring direction, especially the reverse-coded items, rather than by the ' +
        (intendedConstructs.length >= 2 ? intendedConstructs.length + ' intended ' + (intendedConstructs.length === 4 ? 'emotional-intelligence ' : '') + 'domains.' : 'intended construct structure.')
      : status === 'Strong alignment'
        ? 'Empirical clustering reproduces the intended construct structure.'
        : status === 'Mostly aligned'
          ? 'Items group as expected, with minor cross-construct overlap.'
          : 'Items cluster in patterns that do not clearly match the named constructs.';

    document.getElementById('iqCaSub').textContent = headlineInterp;

    // --- Summary card strip ---
    function summaryCard(label, value, tone) {
      return '<div class="iq-ca-sum-card" data-tone="' + tone + '">' +
        '<div class="iq-ca-sum-label">' + label + '</div>' +
        '<div class="iq-ca-sum-value">' + value + '</div>' +
      '</div>';
    }
    const sumTone = statusTone;
    const summaryStrip =
      '<div class="iq-ca-summary">' +
        summaryCard('Detected clusters',  String(detectedClusters), detectedClusters === intendedConstructs.length ? 'strong' : 'warn') +
        summaryCard('Intended constructs', String(intendedConstructs.length || '—'), intendedConstructs.length ? 'ok' : 'warn') +
        summaryCard('Reverse-coded cluster', methodEffect ? 'Yes' : (cluster2Idx.length ? 'Partial' : 'No'), methodEffect ? 'alert' : (cluster2Idx.length ? 'warn' : 'strong')) +
        summaryCard('Alignment status', status, sumTone) +
      '</div>' +
      '<div class="iq-ca-action">' +
        '<strong>Recommended action.</strong> ' +
        (methodEffect
          ? 'Confirm reverse-coding and test subscales separately before reporting any construct-level score.'
          : status === 'Strong alignment'
            ? 'No structural action required. Confirm content validity with a subject-matter expert.'
            : 'Test each intended construct as its own subscale; compare detected clusters to the theoretical map.') +
      '</div>';

    // --- Plain + research interpretation paragraphs ---
    const plain = methodEffect
      ? 'The survey appears to be grouping items by how they are worded rather than by what they are supposed to measure. Most regular items group together, while the reverse-coded items form their own group. This may mean the reverse-coded items were not scored correctly, or that respondents answered negatively worded items differently from positively worded ones.'
      : status === 'Strong alignment'
        ? 'Items group together along the lines you would expect from their scale names. The structure is consistent with the intended design.'
        : 'Items are not splitting cleanly by their named constructs. Some items from different constructs are clustering together; others appear to belong to a single broad group.';

    const research = methodEffect
      ? 'The detected two-cluster solution does not map cleanly onto the intended ' +
        intendedConstructs.length + '-domain structure. Candidate Scale 2 consists ' +
        (c2RevShare >= 0.8 ? 'entirely or nearly entirely ' : 'largely ') +
        'of reverse-coded items drawn from multiple constructs, suggesting a possible wording-method factor, reverse-scoring issue, or response-style artifact. Construct alignment should be evaluated after reverse-coded items are rescored and subscale-level factor / reliability checks are conducted.'
      : 'Empirical clustering ' +
        (status === 'Strong alignment' ? 'reproduces the intended structure.' : 'does not fully reproduce the named subdomain map. Cross-construct co-clustering may indicate a higher-order general factor, weak subdomain separation, or item-level construct misfit.');

    // --- Evidence table rows ---
    const c2List = c2Names.length ? c2Names.join(', ') : 'none detected';
    const evidenceRows = [
      {
        check: 'Number of detected clusters',
        evidence: detectedClusters + ' candidate ' + (detectedClusters === 1 ? 'cluster' : 'clusters') + ' detected',
        status: detectedClusters === intendedConstructs.length ? 'Strong' : 'Watch',
        tone:   detectedClusters === intendedConstructs.length ? 'strong' : 'warn',
        plain:  'The data naturally split into ' + detectedClusters + ' ' + (detectedClusters === 1 ? 'group' : 'groups') +
                (intendedConstructs.length && detectedClusters !== intendedConstructs.length
                  ? ', but those groups do not match the expected ' + intendedConstructs.length + ' constructs.'
                  : '.'),
        research: detectedClusters === intendedConstructs.length
          ? 'Empirical and theoretical structure agree on cluster count.'
          : 'The empirical clustering does not reproduce the intended ' + intendedConstructs.length + '-domain structure.',
        action: 'Compare detected clusters against the theoretical scale map.',
      },
      {
        check: 'Intended construct match',
        evidence: intendedConstructs.length
          ? 'Intended constructs appear to be ' + intendedConstructs.join(', ')
          : 'No construct names could be inferred from item naming.',
        status: c1Broad ? 'Watch' : 'Acceptable',
        tone:   c1Broad ? 'warn'  : 'ok',
        plain: c1Broad
          ? 'Items from different intended constructs are being grouped together.'
          : 'Items in each cluster appear to share a construct.',
        research: c1Broad
          ? 'Cross-construct clustering may indicate a higher-order factor, weak construct separation, or scoring-method artifact.'
          : 'Within-cluster construct homogeneity supports the intended structure.',
        action: 'Test reliability and factor structure separately for each intended subscale.',
      },
      {
        check: 'Reverse-coded item cluster',
        evidence: methodEffect
          ? 'Candidate Scale 2 contains ' + c2List
          : (cluster2Idx.length
              ? cluster2Idx.length + ' item(s) with negative item-rest: ' + c2List
              : 'No items grouping by reverse-coded direction.'),
        status: methodEffect ? 'Problem' : (cluster2Idx.length ? 'Watch' : 'Strong'),
        tone:   methodEffect ? 'alert'   : (cluster2Idx.length ? 'warn'  : 'strong'),
        plain: methodEffect
          ? 'The reverse-coded items are grouping together even though they belong to different constructs.'
          : (cluster2Idx.length
              ? 'A few items move opposite the rest but do not form a clean cluster.'
              : 'No reverse-direction clustering detected.'),
        research: methodEffect
          ? 'This pattern suggests a possible wording-method factor, reverse-scoring error, or response-style artifact.'
          : (cluster2Idx.length
              ? 'Isolated negative item-rest correlations without method clustering usually trace to individual item issues.'
              : 'No method-effect pattern detected.'),
        action: methodEffect
          ? 'Confirm reverse-scoring, rerun construct alignment, and inspect item wording.'
          : (cluster2Idx.length
              ? 'Inspect each negative-direction item individually.'
              : 'No action required.'),
      },
      {
        check: 'Candidate Scale 1 composition',
        evidence: cluster1Idx.length
          ? 'Candidate Scale 1 contains ' + cluster1Idx.length + ' item' + (cluster1Idx.length === 1 ? '' : 's') +
            (c1Constructs.length > 1 ? ' across ' + c1Constructs.join(', ') : c1Constructs.length === 1 ? ' from ' + c1Constructs[0] : '')
          : 'Cluster 1 is empty.',
        status: c1Broad ? 'Mixed' : 'Acceptable',
        tone:   c1Broad ? 'warn'  : 'ok',
        plain: c1Broad
          ? 'Most regular items are moving together, but they are not separating clearly by named construct.'
          : 'The items in Cluster 1 share a single named construct.',
        research: c1Broad
          ? 'The items may reflect a broad general factor rather than distinct subdomains.'
          : 'Cluster 1 is consistent with a single-construct interpretation.',
        action: c1Broad
          ? 'Decide whether the instrument should report a total score, subscale scores, or both.'
          : 'Continue with subscale-level reporting.',
      },
      {
        check: 'Candidate Scale 2 reliability',
        evidence: c2Stats.k >= 2
          ? 'Candidate Scale 2 has ' + c2Stats.k + ' items, α = ' + fmt(c2Stats.alpha, 2) + ', avg r = ' + fmt(c2Stats.avgR, 2)
          : 'Candidate Scale 2 has too few items to compute α.',
        status: c2Stats.k >= 2 ? 'Caution' : 'N/A',
        tone:   c2Stats.k >= 2 ? 'warn'    : 'ok',
        plain: c2Stats.k >= 2
          ? 'These items are consistent with each other, but that does not mean they form a meaningful construct.'
          : 'Not enough items in this cluster to evaluate.',
        research: c2Stats.k >= 2
          ? 'Internal consistency among reverse-coded items may reflect shared wording direction rather than substantive construct coherence.'
          : 'No statistical interpretation possible.',
        action: c2Stats.k >= 2
          ? 'Do not name this as a real scale until wording/scoring effects are ruled out.'
          : 'No action required.',
      },
      {
        check: 'Construct naming readiness',
        evidence: methodEffect || c1Broad
          ? 'Detected clusters do not correspond cleanly to named constructs.'
          : 'Detected clusters align with named constructs.',
        status: methodEffect ? 'Not ready' : (c1Broad ? 'Watch' : 'Ready'),
        tone:   methodEffect ? 'alert'      : (c1Broad ? 'warn'  : 'strong'),
        plain: methodEffect || c1Broad
          ? 'ReliCheck should not automatically name these as final scales.'
          : 'These clusters can be reported under their named scales.',
        research: 'Empirical clusters require theoretical confirmation before being interpreted as latent constructs.',
        action: methodEffect || c1Broad
          ? 'Label them as "candidate clusters," not confirmed scales.'
          : 'Naming as confirmed scales is supported by the structure.',
      },
    ];

    const tbody = evidenceRows.map(r => (
      '<tr data-tone="' + r.tone + '">' +
        '<td class="iq-vt-check">' + esc(r.check) + '</td>' +
        '<td class="iq-vt-evidence">' + esc(r.evidence) + '</td>' +
        '<td><span class="iq-flag" data-tone="' + r.tone + '">' + esc(r.status) + '</span></td>' +
        '<td class="iq-vt-plain">' + esc(r.plain) + '</td>' +
        '<td class="iq-vt-research">' + esc(r.research) + '</td>' +
        '<td class="iq-vt-action">' + esc(r.action) + '</td>' +
      '</tr>'
    )).join('');

    // --- Candidate scale cards (kept from earlier UX, now with badges) ---
    function cardForCluster(idxList, n, badge, stats) {
      if (idxList.length < 1) return '';
      const names = idxList.map(i => items[i].name);
      const constructs = Array.from(new Set(names.map(inferScale).filter(Boolean)));
      return '<div class="iq-ca-card" data-tone="' + badge.tone + '">' +
        '<div class="iq-ca-card-head">' +
          '<span class="iq-ca-card-num">Candidate Scale ' + n + '</span>' +
          '<span class="iq-ca-card-badge" data-tone="' + badge.tone + '">' + badge.label + '</span>' +
        '</div>' +
        '<div class="iq-ca-card-meta">' +
          idxList.length + ' item' + (idxList.length === 1 ? '' : 's') +
          (stats && stats.alpha != null ? '  ·  α = ' + fmt(stats.alpha, 2) + '  ·  avg r = ' + fmt(stats.avgR, 2) : '') +
        '</div>' +
        '<div class="iq-ca-card-items">' + esc(names.join(', ')) + '</div>' +
        (constructs.length > 0
          ? '<div class="iq-ca-card-constructs">' +
              'Spans: ' + esc(constructs.join(', ')) +
            '</div>'
          : '') +
      '</div>';
    }
    const cardsHtml =
      '<div class="iq-ca-cards">' +
        cardForCluster(
          cluster1Idx, 1,
          c1Broad
            ? { label: 'Broad general factor', tone: 'warn' }
            : { label: 'Construct-coherent',   tone: 'ok'   },
          c1Stats
        ) +
        (cluster2Idx.length
          ? cardForCluster(
              cluster2Idx, 2,
              methodEffect
                ? { label: 'Reverse-coded method cluster', tone: 'alert' }
                : { label: 'Negative-direction items',     tone: 'warn'  },
              c2Stats
            )
          : '') +
      '</div>';

    // --- Recommended actions list (parametric) ---
    const recs = [];
    if (methodEffect) recs.push('Confirm all _R items were reverse-scored correctly.');
    if (methodEffect) recs.push('Rerun construct alignment after scoring corrections.');
    if (intendedConstructs.length > 1) recs.push('Test each intended construct separately: ' + intendedConstructs.join(', ') + '.');
    if (methodEffect) recs.push('Do not automatically treat Candidate Scale 2 as a real construct.');
    recs.push('Report the detected clusters as provisional until factor analysis confirms them.');
    recs.push('If item wording is available, inspect whether reverse-coded items are confusing, double-negative, or conceptually different.');
    if (c1Broad || methodEffect) recs.push('Decide whether the instrument supports one general score, subscale scores, or needs revision.');

    // --- Closing prose blocks ---
    const closingPlain = methodEffect
      ? 'The instrument does show structure, but the structure is not yet conceptually clean. The strongest warning is that the reverse-coded items form their own cluster across multiple intended constructs. That usually means the cluster may be caused by scoring direction, item wording, or respondent confusion rather than a true second construct. Before reporting construct-level scores, confirm reverse-coding and rerun the alignment analysis.'
      : status === 'Strong alignment'
        ? 'Empirical structure agrees with the intended design. Items behave as their scale names suggest.'
        : 'The structure is partially aligned with the intended design. Treat the detected clusters as provisional.';

    const closingResearch = methodEffect
      ? 'The two-cluster solution may indicate a general factor plus a wording-method factor. Because Candidate Scale 2 consists ' +
        (c2RevShare >= 0.8 ? 'entirely ' : 'largely ') +
        'of reverse-coded items, it should not be interpreted as a substantive latent construct without additional evidence. Recommended next steps are reverse-scoring verification, subscale reliability, exploratory factor analysis, and confirmatory testing against the intended ' + intendedConstructs.length + '-factor model if sample size and design allow.'
      : 'Confirmatory factor analysis against the intended factor model is the appropriate next step, with subscale-level reliability and convergent / discriminant validity checks.';

    // --- Assemble body ---
    // Compose body — SAME structure as Reliability and Validity:
    //   1. iq-rel-summary (tinted box) — judgment line + plain + research labels
    //   2. h4 + table (iq-table-rel for identical chrome)
    //   3. iq-rel-closing (accent-soft box) — single closing paragraph
    //   4. Candidate cluster cards kept (lens-specific; sits between summary and table)
    //   5. J/E/M/A footer (iq-foot)
    document.getElementById('iqCaBody').innerHTML =
      '<div class="iq-rel-summary">' +
        '<h4 class="iq-block-h">Construct alignment summary</h4>' +
        '<p class="iq-rel-summary-line"><strong>' + esc(headlineInterp) + '</strong></p>' +
        '<div class="iq-rel-paragraphs">' +
          '<div><span class="iq-rel-label">Plain-language explanation.</span> ' + esc(plain) + '</div>' +
          '<div><span class="iq-rel-label">Research interpretation.</span> ' + esc(research) + '</div>' +
        '</div>' +
      '</div>' +
      '<h4 class="iq-block-h">Candidate clusters</h4>' +
      cardsHtml +
      '<h4 class="iq-block-h">Construct alignment evidence</h4>' +
      '<div class="iq-rel-table-wrap">' +
        '<table class="iq-table iq-table-rel">' +
          '<thead><tr>' +
            '<th>Alignment check</th>' +
            '<th>Evidence found</th>' +
            '<th>Status</th>' +
            '<th>Plain-language meaning</th>' +
            '<th>Research interpretation</th>' +
            '<th>Recommended action</th>' +
          '</tr></thead>' +
          '<tbody>' + tbody + '</tbody>' +
        '</table>' +
      '</div>' +
      '<div class="iq-rel-closing"><h4 class="iq-block-h">Interpretation</h4>' +
        '<p>' + esc(closingPlain + ' ' + closingResearch) + '</p>' +
      '</div>';

    // J/E/M/A footer for cross-page consistency
    document.getElementById('iqCaInterp').innerHTML =
      '<div class="iq-jema">' +
        '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(status === 'Method-effect warning' ? headlineInterp : 'Alignment status: ' + status + '. ' + headlineInterp) + '</div>' +
        '<div class="iq-jema-row"><strong>Evidence.</strong> ' +
          detectedClusters + ' detected cluster(s) vs ' + intendedConstructs.length + ' intended construct(s)' +
          (cluster2Idx.length ? '; cluster 2 contains ' + cluster2Idx.length + ' item(s): ' + c2List : '') +
          (methodEffect ? '; ' + Math.round(c2RevShare * 100) + '% of cluster 2 items are reverse-coded' : '') +
        '.</div>' +
        '<div class="iq-jema-row"><strong>Meaning.</strong> ' + esc(closingResearch) + '</div>' +
        '<div class="iq-jema-row"><strong>Action.</strong> ' + esc(recs[0] || 'No structural correction required.') + '</div>' +
      '</div>';

    exposeAppState({
      lens: 'construct_alignment', status: status,
      detectedClusters: detectedClusters, intendedConstructs: intendedConstructs,
      cluster1: { items: c1Names, alpha: c1Stats.alpha, avgR: c1Stats.avgR, constructs: c1Constructs, broad: c1Broad },
      cluster2: { items: c2Names, alpha: c2Stats.alpha, avgR: c2Stats.avgR, constructs: c2Constructs, methodEffect: methodEffect },
      recommendedActions: recs,
    });
  }

  // ==================================================================
  // LENS: item_quality
  // ==================================================================
  function renderItemQuality() {
    const items = allVars.filter(isLikert);
    if (items.length < 1) { empty('Item Quality', 'Need at least 1 Likert item.'); return; }
    const rows = items.map(v => {
      const all = v.values;
      const nums = all.map(num).filter(x => x != null);
      const missing = all.length - nums.length;
      const m = nums.length ? mean(nums) : null;
      const s = nums.length > 1 ? sd(nums) : null;
      const lo = nums.length ? Math.min.apply(null, nums) : null;
      const hi = nums.length ? Math.max.apply(null, nums) : null;
      const range = (hi != null && lo != null) ? hi - lo : 0;
      const ceil = (nums.length && hi != null) ? nums.filter(x => x === hi).length / nums.length : 0;
      const floor = (nums.length && lo != null) ? nums.filter(x => x === lo).length / nums.length : 0;
      const lowVar = (s != null && range > 0) ? (s < 0.15 * range) : false;
      const skew = nums.length >= 3 ? (() => {
        const sM = sd(nums); if (sM === 0) return 0;
        return nums.reduce((acc, x) => acc + Math.pow((x - m) / sM, 3), 0) / nums.length;
      })() : 0;
      const kurt = nums.length >= 4 ? (() => {
        const sM = sd(nums); if (sM === 0) return 0;
        return nums.reduce((acc, x) => acc + Math.pow((x - m) / sM, 4), 0) / nums.length - 3;
      })() : 0;
      const flags = [];
      if (ceil  >= 0.70) flags.push('ceiling');
      if (floor >= 0.70) flags.push('floor');
      if (lowVar)        flags.push('low variance');
      if (Math.abs(skew) > 2)  flags.push('extreme skew');
      if (Math.abs(kurt) > 5)  flags.push('extreme kurtosis');
      if (missing / all.length > 0.20) flags.push(Math.round(missing / all.length * 100) + '% missing');
      let tone = 'ok';
      if (flags.length >= 3) tone = 'alert';
      else if (flags.length) tone = 'warn';
      return { name: v.name, n: nums.length, mean: m, sd: s, missing: missing, missingRate: missing / all.length, ceil: ceil, floor: floor, skew: skew, kurt: kurt, flags: flags, tone: tone };
    });
    const ok    = rows.filter(r => r.tone === 'ok').length;
    const warn  = rows.filter(r => r.tone === 'warn').length;
    const alert = rows.filter(r => r.tone === 'alert').length;

    // Headline
    document.getElementById('iqIqTitle').textContent = 'Item Quality: ' + items.length + ' items';
    document.getElementById('iqIqSub').textContent   = ok + ' clean · ' + warn + ' watch · ' + alert + ' problem';

    // Summary line + parametric paragraphs
    const judgment = alert
      ? alert + ' item' + (alert === 1 ? '' : 's') + ' show multiple problems and should be revised or removed; ' + warn + ' need a closer look.'
      : warn
        ? warn + ' item' + (warn === 1 ? '' : 's') + ' show one issue each. Inspect; most are usable with minor adjustments.'
        : 'All items pass the item-quality screens.';
    const plainPara = alert || warn
      ? 'A handful of items are not pulling their weight on this instrument. Items flagged Ceiling or Floor are too easy or too hard to discriminate; items flagged Low variance behave the same way for almost every respondent; items flagged with extreme skew or kurtosis have a lopsided distribution.'
      : 'Each item behaves like a useful question. Means and spreads land in workable ranges, no item is stuck at the top or bottom of the scale, and missingness is low.';
    const researchPara = (alert || warn)
      ? 'Per-item screens for ceiling / floor effects (≥70% at endpoint), low variance (SD < 15% of range), and distributional extremes (|skew| > 2, |excess kurtosis| > 5). Items flagged on three or more screens are marked Problem; one screen flags Watch. Items flagged as Watch and Problem are candidates for revision or removal before publication.'
      : 'No items meet thresholds for ceiling (≥70% endpoint), floor (≥70% bottom), low variance (SD < 15% of range), extreme skew (|skew| > 2), extreme kurtosis (|kurt| > 5), or excessive missingness (>20%).';

    // Closing interpretation
    const closingPara = alert
      ? 'Address the ' + alert + ' Problem item' + (alert === 1 ? '' : 's') + ' first — revise wording or remove from the scale, then recompute α. The ' + warn + ' Watch item' + (warn === 1 ? '' : 's') + ' should be inspected next.'
      : warn
        ? 'Each Watch item shows a single distributional issue. Inspect wording, response options, or population fit. Most are usable as-is for exploratory work.'
        : 'No item-level corrections required. Item quality is consistent with confident reporting.';

    // Table — 6-column layout matching the rest of IQ
    const tbody = rows.map(r => {
      const status = r.tone === 'alert' ? 'Problem' : r.tone === 'warn' ? 'Watch' : 'Clean';
      const plain  = r.flags.length
        ? 'Behaves unusually on ' + r.flags.length + ' screen' + (r.flags.length === 1 ? '' : 's') + ': ' + r.flags.join(', ') + '.'
        : 'Behaves like a useful question — means, spread, and distribution are workable.';
      const research = r.flags.length
        ? 'Flagged: ' + r.flags.join('; ') + '. n = ' + r.n + ', mean = ' + fmt(r.mean, 2) + ', SD = ' + fmt(r.sd, 2) + ', ceiling = ' + pct(r.ceil) + ', floor = ' + pct(r.floor) + ', skew = ' + fmt(r.skew, 2) + ', kurt = ' + fmt(r.kurt, 2) + '.'
        : 'All distributional screens pass.';
      const action  = r.tone === 'alert' ? 'Revise or remove this item before reporting.'
                    : r.tone === 'warn'  ? 'Inspect wording; consider revising.'
                                          : 'Keep item.';
      return '<tr data-tone="' + r.tone + '">' +
        '<td class="iq-rel-item">' + esc(r.name) + '</td>' +
        '<td class="iq-num">' + r.n + '</td>' +
        '<td class="iq-num">' + fmt(r.mean, 2) + ' (SD ' + fmt(r.sd, 2) + ')</td>' +
        '<td><span class="iq-flag" data-tone="' + r.tone + '">' + status + '</span></td>' +
        '<td class="iq-rel-plain">' + esc(plain) + '</td>' +
        '<td class="iq-rel-research">' + esc(research) + '</td>' +
        '<td class="iq-rel-action">' + esc(action) + '</td>' +
      '</tr>';
    }).join('');

    document.getElementById('iqIqBody').innerHTML =
      '<div class="iq-rel-summary">' +
        '<h4 class="iq-block-h">Item quality summary</h4>' +
        '<p class="iq-rel-summary-line"><strong>' + esc(judgment) + '</strong></p>' +
        '<div class="iq-rel-paragraphs">' +
          '<div><span class="iq-rel-label">Plain-language explanation.</span> ' + esc(plainPara) + '</div>' +
          '<div><span class="iq-rel-label">Research interpretation.</span> ' + esc(researchPara) + '</div>' +
        '</div>' +
      '</div>' +
      '<h4 class="iq-block-h">Item-level diagnostics</h4>' +
      '<div class="iq-rel-table-wrap">' +
        '<table class="iq-table iq-table-rel">' +
          '<thead><tr>' +
            '<th>Item</th><th class="iq-num">n</th><th class="iq-num">Mean (SD)</th>' +
            '<th>Status</th>' +
            '<th>Plain-language meaning</th>' +
            '<th>Research interpretation</th>' +
            '<th>Recommended action</th>' +
          '</tr></thead>' +
          '<tbody>' + tbody + '</tbody>' +
        '</table>' +
      '</div>' +
      '<div class="iq-rel-closing"><h4 class="iq-block-h">Interpretation</h4>' +
        '<p>' + esc(closingPara) + '</p>' +
      '</div>';

    document.getElementById('iqIqInterp').innerHTML =
      '<div class="iq-jema">' +
        '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(judgment) + '</div>' +
        '<div class="iq-jema-row"><strong>Evidence.</strong> ' + ok + ' clean, ' + warn + ' watch, ' + alert + ' problem across ' + items.length + ' items.</div>' +
        '<div class="iq-jema-row"><strong>Meaning.</strong> ' + esc(researchPara) + '</div>' +
        '<div class="iq-jema-row"><strong>Action.</strong> ' + esc(closingPara) + '</div>' +
      '</div>';

    exposeAppState({ lens: 'item_quality', items: rows, summary: { ok, warn, alert } });
  }

  // ==================================================================
  // LENS: scale_structure
  // ==================================================================
  function renderScaleStructure() {
    const prep = prepareLikert();
    if (!prep || !prep.validCols) { empty('Scale Structure', 'Need at least 2 Likert items.'); return; }
    const { items, validCols } = prep;
    const k = items.length;
    const R = correlationMatrix(validCols);

    // Greedy clustering: start each item as own cluster; merge clusters
    // whose average pairwise r is above 0.4 (modest correlation threshold).
    // Result: candidate scales of correlated items.
    let clusters = items.map((v, i) => ({ name: 'C' + (i + 1), idx: [i] }));
    function clusterAvgR(c1, c2) {
      let sum = 0, n = 0;
      c1.idx.forEach(i => c2.idx.forEach(j => { sum += R[i][j]; n++; }));
      return n ? sum / n : 0;
    }
    let merged = true;
    while (merged && clusters.length > 1) {
      merged = false;
      let bestA = -1, bestB = -1, bestR = 0.40;
      for (let a = 0; a < clusters.length; a++) {
        for (let b = a + 1; b < clusters.length; b++) {
          const r = clusterAvgR(clusters[a], clusters[b]);
          if (r > bestR) { bestR = r; bestA = a; bestB = b; }
        }
      }
      if (bestA >= 0) {
        clusters[bestA] = { name: clusters[bestA].name + '+' + clusters[bestB].name.replace(/^C/, ''), idx: clusters[bestA].idx.concat(clusters[bestB].idx) };
        clusters.splice(bestB, 1);
        merged = true;
      }
    }

    // Stats per cluster (alpha + avg r)
    const clusterRows = clusters.map((c, ci) => {
      const itemNames = c.idx.map(i => items[i].name);
      let alpha = null;
      if (c.idx.length >= 2) {
        const cols = c.idx.map(i => validCols[i]);
        const totals = cols[0].map((_, idx) => cols.reduce((s, col) => s + col[idx], 0));
        const iv = cols.reduce((s, col) => s + variance(col), 0);
        const tv = variance(totals);
        const ck = cols.length;
        alpha = tv ? (ck / (ck - 1)) * (1 - iv / tv) : null;
      }
      let avg = 0, n = 0;
      for (let i = 0; i < c.idx.length; i++) for (let j = i + 1; j < c.idx.length; j++) { avg += R[c.idx[i]][c.idx[j]]; n++; }
      avg = n ? avg / n : null;
      const tone = c.idx.length === 1 ? 'warn' : (alpha != null && alpha >= 0.70 ? 'strong' : 'ok');
      const status = c.idx.length === 1 ? 'Singleton' : (alpha != null && alpha >= 0.70 ? 'Coherent' : 'Loose');
      const plain = c.idx.length === 1
        ? 'This item does not cluster with anything else at the threshold.'
        : alpha != null && alpha >= 0.70
          ? 'These items hang together reliably as a candidate scale.'
          : 'These items cluster together but only loosely.';
      const research = c.idx.length === 1
        ? 'Item-pair correlations with other items fall below the 0.40 merge threshold.'
        : 'α = ' + (alpha != null ? fmt(alpha, 2) : '—') + ', avg inter-item r = ' + (avg != null ? fmt(avg, 2) : '—') + '. ' +
          (alpha != null && alpha >= 0.70 ? 'Internal consistency meets the conventional threshold.' : 'Internal consistency falls below the conventional threshold.');
      const action = c.idx.length === 1
        ? 'Inspect wording; this item may belong elsewhere or measure something distinct.'
        : alpha != null && alpha >= 0.70
          ? 'Treat as a candidate scale pending theoretical confirmation.'
          : 'Review item content; one or more items may belong to a different cluster.';
      return { ci, itemNames, alpha, avg, tone, status, plain, research, action };
    });

    const singletons = clusters.filter(c => c.idx.length === 1).length;

    // Headline + summary copy
    document.getElementById('iqSsTitle').textContent = 'Scale Structure: ' + clusters.length + ' candidate cluster' + (clusters.length === 1 ? '' : 's');
    document.getElementById('iqSsSub').textContent = items.length + ' Likert items · correlation-merge threshold = 0.40';

    const judgment =
      clusters.length === 1 ? 'All items cluster into one candidate scale — consistent with a unidimensional instrument.' :
      singletons === clusters.length ? 'Every item is its own cluster. The items do not cohere into any defensible scale at this threshold.' :
      clusters.length + ' candidate clusters. ' + (singletons ? singletons + ' item' + (singletons === 1 ? '' : 's') + ' did not cluster with anything else.' : 'No singletons; structure is reasonably clean.');

    const plainPara = clusters.length === 1
      ? 'All your Likert items move together strongly enough to be treated as one scale.'
      : 'Items group together when they are correlated above the threshold. The groups below show which items appear to belong to the same underlying idea.';
    const researchPara = clusters.length === 1
      ? 'Single-cluster solution at the r ≥ 0.40 merge threshold. Consistent with a unidimensional measurement model, pending formal factor analysis.'
      : 'Hierarchical merge using average pairwise inter-item correlation ≥ 0.40. Singletons are items whose pair-level correlations all fall below threshold.';

    const closingPara =
      clusters.length === 1 ? 'Confirm unidimensionality with a formal factor analysis (Factor Readiness, Factor Structure) before publishing a single composite score.' :
      singletons === clusters.length ? 'The instrument lacks coherent structure at this threshold. Review item wording and consider whether the scale was designed to capture more than one construct.' :
      'Review the singletons first — they may belong elsewhere or measure something distinct. Then decide whether the candidate clusters match your intended subscales.';

    // Table — same chrome as the rest
    const tbody = clusterRows.map(r => (
      '<tr data-tone="' + r.tone + '">' +
        '<td class="iq-rel-item">Candidate cluster ' + (r.ci + 1) + '</td>' +
        '<td class="iq-num">' + r.itemNames.length + '</td>' +
        '<td class="iq-num">' + (r.alpha != null ? fmt(r.alpha, 2) : '—') + '</td>' +
        '<td class="iq-num">' + (r.avg != null ? fmt(r.avg, 2) : '—') + '</td>' +
        '<td><span class="iq-flag" data-tone="' + r.tone + '">' + r.status + '</span></td>' +
        '<td class="iq-rel-plain">' + esc(r.plain) + '<br><span style="color:var(--ink-5);font-size:12px;">' + esc(r.itemNames.join(', ')) + '</span></td>' +
        '<td class="iq-rel-research">' + esc(r.research) + '</td>' +
        '<td class="iq-rel-action">' + esc(r.action) + '</td>' +
      '</tr>'
    )).join('');

    document.getElementById('iqSsBody').innerHTML =
      '<div class="iq-rel-summary">' +
        '<h4 class="iq-block-h">Scale structure summary</h4>' +
        '<p class="iq-rel-summary-line"><strong>' + esc(judgment) + '</strong></p>' +
        '<div class="iq-rel-paragraphs">' +
          '<div><span class="iq-rel-label">Plain-language explanation.</span> ' + esc(plainPara) + '</div>' +
          '<div><span class="iq-rel-label">Research interpretation.</span> ' + esc(researchPara) + '</div>' +
        '</div>' +
      '</div>' +
      '<h4 class="iq-block-h">Candidate clusters</h4>' +
      '<div class="iq-rel-table-wrap">' +
        '<table class="iq-table iq-table-rel">' +
          '<thead><tr>' +
            '<th>Cluster</th>' +
            '<th class="iq-num">Items</th>' +
            '<th class="iq-num">α</th>' +
            '<th class="iq-num">Avg r</th>' +
            '<th>Status</th>' +
            '<th>Plain-language meaning &amp; items</th>' +
            '<th>Research interpretation</th>' +
            '<th>Recommended action</th>' +
          '</tr></thead>' +
          '<tbody>' + tbody + '</tbody>' +
        '</table>' +
      '</div>' +
      '<div class="iq-rel-closing"><h4 class="iq-block-h">Interpretation</h4>' +
        '<p>' + esc(closingPara) + '</p>' +
      '</div>';

    document.getElementById('iqSsInterp').innerHTML =
      '<div class="iq-jema">' +
        '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(judgment) + '</div>' +
        '<div class="iq-jema-row"><strong>Evidence.</strong> ' + clusters.length + ' cluster(s) across ' + items.length + ' items at the r ≥ 0.40 threshold; ' + singletons + ' singleton(s).</div>' +
        '<div class="iq-jema-row"><strong>Meaning.</strong> ' + esc(researchPara) + '</div>' +
        '<div class="iq-jema-row"><strong>Action.</strong> ' + esc(closingPara) + '</div>' +
      '</div>';

    exposeAppState({ lens: 'scale_structure', clusters: clusters.map(c => ({ items: c.idx.map(i => items[i].name) })) });
  }

  // ==================================================================
  // LENS: factor_readiness
  // ==================================================================
  function renderFactorReadiness() {
    const prep = prepareLikert();
    if (!prep || !prep.validCols) { empty('Factor Readiness', 'Need at least 2 Likert items.'); return; }
    const { items, validCols, validRows } = prep;
    const k = items.length;
    const R = correlationMatrix(validCols);
    const { inv, det } = inverseAndDet(R);
    // KMO + per-item MSA
    let kmoR2 = 0, kmoP2 = 0;
    const msaPerItem = new Array(k).fill(0);
    const msaR2 = new Array(k).fill(0);
    const msaP2 = new Array(k).fill(0);
    if (inv) {
      for (let i = 0; i < k; i++) {
        for (let j = i + 1; j < k; j++) {
          const r = R[i][j];
          const denom = Math.sqrt(Math.abs(inv[i][i] * inv[j][j]));
          const p = denom === 0 ? 0 : -inv[i][j] / denom;
          kmoR2 += r * r;
          kmoP2 += p * p;
          msaR2[i] += r * r; msaR2[j] += r * r;
          msaP2[i] += p * p; msaP2[j] += p * p;
        }
      }
    }
    const kmo = (kmoR2 + kmoP2) === 0 ? null : kmoR2 / (kmoR2 + kmoP2);
    const msa = msaR2.map((rr, i) => (rr + msaP2[i]) === 0 ? null : rr / (rr + msaP2[i]));

    // Bartlett's test of sphericity
    let bartChi = null, bartDf = null, bartP = null;
    if (det != null && det > 0) {
      const N = validRows.length;
      bartChi = -((N - 1) - (2 * k + 5) / 6) * Math.log(det);
      bartDf  = k * (k - 1) / 2;
      bartP   = chiPValue(bartChi, bartDf);
    }
    function kmoBand(x) {
      if (x == null) return { label: '—', tone: 'muted' };
      if (x >= 0.90) return { label: 'marvelous', tone: 'strong' };
      if (x >= 0.80) return { label: 'meritorious', tone: 'strong' };
      if (x >= 0.70) return { label: 'middling', tone: 'ok' };
      if (x >= 0.60) return { label: 'mediocre', tone: 'warn' };
      if (x >= 0.50) return { label: 'miserable', tone: 'warn' };
      return            { label: 'unacceptable', tone: 'alert' };
    }
    const band = kmoBand(kmo);

    // Headline
    document.getElementById('iqFrTitle').textContent = 'Factor Readiness: KMO = ' + fmt(kmo, 2) + ' (' + band.label + ')';
    document.getElementById('iqFrSub').textContent = items.length + ' Likert items · ' + validRows.length + ' complete rows · Bartlett ' + (bartP != null ? fmtP(bartP) : '—');

    // Parametric copy
    const judgment =
      kmo == null ? 'KMO could not be computed — the correlation matrix is likely singular.' :
      kmo >= 0.70 && bartP != null && bartP < 0.05 ? 'Both readiness checks pass. Factor analysis is appropriate on this data.' :
      kmo >= 0.60 && bartP != null && bartP < 0.05 ? 'KMO is marginal but Bartlett\'s test supports factor structure. Proceed with caution.' :
      'Factor analysis is not well supported on this set of items as currently structured.';

    const plainPara = kmo == null
      ? 'There isn\'t enough variation across items to test factor readiness. The math behind the test cannot run.'
      : kmo >= 0.70
        ? 'Your items share enough patterns for a factor analysis to actually find something. The data are ready.'
        : kmo >= 0.60
          ? 'Your items share some patterns, but not strongly. A factor analysis may run but the result will be noisy.'
          : 'Your items do not share enough patterns. A factor analysis on this data is unlikely to give a useful result.';

    const researchPara = "KMO bands (Kaiser 1974): marvelous ≥ 0.90, meritorious ≥ 0.80, middling ≥ 0.70, mediocre ≥ 0.60, miserable ≥ 0.50, unacceptable below. Bartlett's test rejects sphericity (p < 0.05) when the correlation matrix is different enough from the identity to make factor analysis worthwhile. Per-item MSA flags items whose individual sampling adequacy is below the overall threshold.";

    // Closing
    const lowMsaItems = items.map((v, i) => ({ name: v.name, msa: msa[i] })).filter(x => x.msa != null && x.msa < 0.60).map(x => x.name);
    const closingPara =
      kmo == null ? 'Restructure the data — verify reverse-coded items are rescored, then re-run readiness.' :
      kmo >= 0.70 ? 'Move on to Factor Structure (Scale Structure rail) to inspect the actual loadings.' :
      lowMsaItems.length
        ? 'Consider removing low-MSA items (' + lowMsaItems.slice(0, 5).join(', ') + (lowMsaItems.length > 5 ? ', …' : '') + ') and re-running readiness. Or expand the item pool.'
        : 'Consider expanding the item pool, or restructuring the scale around a clearer construct, before factor analysis.';

    // Table — overall + per-item MSA
    const overallTone = band.tone === 'strong' ? 'strong' : band.tone === 'ok' ? 'ok' : band.tone === 'warn' ? 'warn' : 'alert';
    const overallRow =
      '<tr data-tone="' + overallTone + '">' +
        '<td class="iq-rel-item">Overall KMO</td>' +
        '<td class="iq-num">' + fmt(kmo, 2) + '</td>' +
        '<td><span class="iq-flag" data-tone="' + overallTone + '">' + band.label + '</span></td>' +
        '<td class="iq-rel-plain">' + esc(kmo == null ? 'Cannot be computed on this data.' : kmo >= 0.70 ? 'Data are ready for factor analysis.' : kmo >= 0.60 ? 'Data are marginally ready.' : 'Data are not ready.') + '</td>' +
        '<td class="iq-rel-research">' + esc('KMO = ' + fmt(kmo, 2) + ', ' + band.label + ' band per Kaiser 1974.') + '</td>' +
        '<td class="iq-rel-action">' + esc(kmo == null ? 'Restructure data; check for singular correlation matrix.' : kmo >= 0.70 ? 'Proceed to factor analysis.' : 'Inspect per-item MSA below; consider dropping low-MSA items.') + '</td>' +
      '</tr>';
    const bartlettTone = bartP == null ? 'warn' : bartP < 0.05 ? 'strong' : 'alert';
    const bartlettRow =
      '<tr data-tone="' + bartlettTone + '">' +
        '<td class="iq-rel-item">Bartlett\'s test</td>' +
        '<td class="iq-num">χ² = ' + fmt(bartChi, 1) + (bartDf != null ? ', df = ' + bartDf : '') + '</td>' +
        '<td><span class="iq-flag" data-tone="' + bartlettTone + '">' + (bartP == null ? '—' : bartP < 0.05 ? 'Sphericity rejected' : 'Sphericity holds') + '</span></td>' +
        '<td class="iq-rel-plain">' + esc(bartP == null ? 'Could not run.' : bartP < 0.05 ? 'The items relate to each other strongly enough for factor structure to be meaningful.' : 'The items are too independent — factor structure is unlikely to emerge.') + '</td>' +
        '<td class="iq-rel-research">' + esc('p = ' + (bartP != null ? fmtP(bartP).replace(/^p\s*=?\s*/, '') : '—') + '. p < 0.05 supports running factor analysis.') + '</td>' +
        '<td class="iq-rel-action">' + esc(bartP == null ? 'Re-run after data correction.' : bartP < 0.05 ? 'Proceed with factor analysis.' : 'Do not attempt factor analysis on this data.') + '</td>' +
      '</tr>';
    const itemRows = items.map((v, i) => {
      const b = kmoBand(msa[i]);
      return '<tr data-tone="' + b.tone + '">' +
        '<td class="iq-rel-item">' + esc(v.name) + '</td>' +
        '<td class="iq-num">' + fmt(msa[i], 2) + '</td>' +
        '<td><span class="iq-flag" data-tone="' + b.tone + '">' + b.label + '</span></td>' +
        '<td class="iq-rel-plain">' + esc(msa[i] != null && msa[i] >= 0.70 ? 'This item fits well with the others.' : msa[i] != null && msa[i] >= 0.60 ? 'This item fits, but only marginally.' : 'This item is pulling away from the rest of the set.') + '</td>' +
        '<td class="iq-rel-research">' + esc('Per-item MSA = ' + fmt(msa[i], 2) + '.') + '</td>' +
        '<td class="iq-rel-action">' + esc(msa[i] != null && msa[i] >= 0.60 ? 'Keep.' : 'Consider revising or removing this item.') + '</td>' +
      '</tr>';
    }).join('');

    document.getElementById('iqFrBody').innerHTML =
      '<div class="iq-rel-summary">' +
        '<h4 class="iq-block-h">Factor readiness summary</h4>' +
        '<p class="iq-rel-summary-line"><strong>' + esc(judgment) + '</strong></p>' +
        '<div class="iq-rel-paragraphs">' +
          '<div><span class="iq-rel-label">Plain-language explanation.</span> ' + esc(plainPara) + '</div>' +
          '<div><span class="iq-rel-label">Research interpretation.</span> ' + esc(researchPara) + '</div>' +
        '</div>' +
      '</div>' +
      '<h4 class="iq-block-h">Readiness checks</h4>' +
      '<div class="iq-rel-table-wrap">' +
        '<table class="iq-table iq-table-rel">' +
          '<thead><tr>' +
            '<th>Check</th>' +
            '<th class="iq-num">Value</th>' +
            '<th>Status</th>' +
            '<th>Plain-language meaning</th>' +
            '<th>Research interpretation</th>' +
            '<th>Recommended action</th>' +
          '</tr></thead>' +
          '<tbody>' + overallRow + bartlettRow + itemRows + '</tbody>' +
        '</table>' +
      '</div>' +
      '<div class="iq-rel-closing"><h4 class="iq-block-h">Interpretation</h4>' +
        '<p>' + esc(closingPara) + '</p>' +
      '</div>';

    document.getElementById('iqFrInterp').innerHTML =
      '<div class="iq-jema">' +
        '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(judgment) + '</div>' +
        '<div class="iq-jema-row"><strong>Evidence.</strong> KMO = ' + fmt(kmo, 2) + ' (' + band.label + '); Bartlett p = ' + (bartP != null ? fmtP(bartP).replace(/^p\s*=?\s*/, '') : '—') + '; ' + lowMsaItems.length + ' item(s) below MSA 0.60.</div>' +
        '<div class="iq-jema-row"><strong>Meaning.</strong> ' + esc(researchPara) + '</div>' +
        '<div class="iq-jema-row"><strong>Action.</strong> ' + esc(closingPara) + '</div>' +
      '</div>';

    exposeAppState({ lens: 'factor_readiness', kmo: kmo, bartlettChi: bartChi, bartlettDf: bartDf, bartlettP: bartP, msa: items.map((v, i) => ({ name: v.name, msa: msa[i] })) });
  }

  // ==================================================================
  // LENS: bias_clarity
  // ==================================================================
  function renderBiasClarity() {
    const items = allVars.filter(isLikert);
    if (items.length < 1) { empty('Bias & Clarity Review', 'Need at least 1 Likert item.'); return; }
    const rows = items.map(v => {
      const name = v.name;
      const flags = [];
      if (name.length > 40) flags.push('very long variable name');
      if (/[A-Z]{4,}/.test(name)) flags.push('long uppercase run (possible acronym)');
      if (/_(and|or)_/i.test(name)) flags.push('contains "_and_" or "_or_" (possible double-barreled)');
      if (/_(not|never)_/i.test(name)) flags.push('contains negation (possible reverse-coded; verify)');
      if (/\d/.test(name) && !/^item\d|^q\d/i.test(name)) flags.push('embedded digit (check intent)');
      if (/[^a-z0-9_]/i.test(name)) flags.push('non-alphanumeric character');
      if (name === name.toUpperCase() && name.length > 4) flags.push('all-uppercase');
      let tone = 'ok';
      if (flags.length >= 2) tone = 'alert';
      else if (flags.length) tone = 'warn';
      return { name: name, flags: flags, tone: tone };
    });
    const ok    = rows.filter(r => r.tone === 'ok').length;
    const warn  = rows.filter(r => r.tone === 'warn').length;
    const alert = rows.filter(r => r.tone === 'alert').length;
    // Headline
    document.getElementById('iqBcTitle').textContent = 'Bias & Clarity: ' + items.length + ' Likert items inspected';
    document.getElementById('iqBcSub').textContent   = ok + ' clean · ' + warn + ' watch · ' + alert + ' possible issue';

    // Parametric copy
    const judgment = alert
      ? alert + ' item name' + (alert === 1 ? '' : 's') + ' show multiple wording flags. Inspect those items first.'
      : warn
        ? warn + ' name' + (warn === 1 ? '' : 's') + ' show one flag each. Check whether they apply to the actual prompt.'
        : 'No automatic name-level flags detected. Manual review of the actual item prompts is still required.';
    const plainPara = 'This page looks for wording patterns that often signal bias or unclear questions — things like double-barreled phrasing ("and"/"or"), negation, or unusually long names. It can only see the variable names right now; once the actual prompt text is connected, the same checks will run on full item wording.';
    const researchPara = 'Heuristic name-level pass: long uppercase runs (possible acronyms), "_and_" / "_or_" markers (possible double-barreled items), "_not_" / "_never_" markers (possible reverse-coded items requiring verification), embedded digits outside the standard item-numbering pattern, and non-alphanumeric characters. Two or more flags → Watch; three or more → Possible issue. Content-level bias and clarity require the actual item prompt, not the variable name.';
    const closingPara = alert || warn
      ? 'Review the flagged items first. Confirm whether the wording reflects what you intended to measure, and decide whether the item should be reworded, split, or removed.'
      : 'No name-level signals. The actual prompt text still warrants a human pass — names can hide problems that wording reveals.';

    // Table
    const tbody = rows.map(r => {
      const status = r.tone === 'alert' ? 'Possible issue' : r.tone === 'warn' ? 'Watch' : 'Clean';
      const plain  = r.flags.length
        ? 'Name patterns suggest this item may be ' + (r.flags.length > 1 ? 'unclear or double-barreled.' : 'worth a second look.')
        : 'No name-level signals.';
      const research = r.flags.length
        ? 'Flags: ' + r.flags.join('; ') + '.'
        : 'No name-level wording patterns flagged.';
      const action = r.tone === 'alert' ? 'Review the prompt; consider rewording or splitting.'
                   : r.tone === 'warn'  ? 'Check whether the flag applies to the actual prompt.'
                                         : 'No action required (still review prompt manually).';
      return '<tr data-tone="' + r.tone + '">' +
        '<td class="iq-rel-item">' + esc(r.name) + '</td>' +
        '<td><span class="iq-flag" data-tone="' + r.tone + '">' + status + '</span></td>' +
        '<td class="iq-rel-plain">' + esc(plain) + '</td>' +
        '<td class="iq-rel-research">' + esc(research) + '</td>' +
        '<td class="iq-rel-action">' + esc(action) + '</td>' +
      '</tr>';
    }).join('');

    document.getElementById('iqBcBody').innerHTML =
      '<div class="iq-rel-summary">' +
        '<h4 class="iq-block-h">Bias &amp; clarity summary</h4>' +
        '<p class="iq-rel-summary-line"><strong>' + esc(judgment) + '</strong></p>' +
        '<div class="iq-rel-paragraphs">' +
          '<div><span class="iq-rel-label">Plain-language explanation.</span> ' + esc(plainPara) + '</div>' +
          '<div><span class="iq-rel-label">Research interpretation.</span> ' + esc(researchPara) + '</div>' +
        '</div>' +
      '</div>' +
      '<h4 class="iq-block-h">Item-name diagnostics</h4>' +
      '<div class="iq-rel-table-wrap">' +
        '<table class="iq-table iq-table-rel">' +
          '<thead><tr>' +
            '<th>Item name</th>' +
            '<th>Status</th>' +
            '<th>Plain-language meaning</th>' +
            '<th>Research interpretation</th>' +
            '<th>Recommended action</th>' +
          '</tr></thead>' +
          '<tbody>' + tbody + '</tbody>' +
        '</table>' +
      '</div>' +
      '<div class="iq-rel-closing"><h4 class="iq-block-h">Interpretation</h4>' +
        '<p>' + esc(closingPara) + '</p>' +
      '</div>';

    document.getElementById('iqBcInterp').innerHTML =
      '<div class="iq-jema">' +
        '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(judgment) + '</div>' +
        '<div class="iq-jema-row"><strong>Evidence.</strong> ' + ok + ' clean, ' + warn + ' watch, ' + alert + ' possible issue across ' + items.length + ' items.</div>' +
        '<div class="iq-jema-row"><strong>Meaning.</strong> ' + esc(researchPara) + '</div>' +
        '<div class="iq-jema-row"><strong>Action.</strong> ' + esc(closingPara) + '</div>' +
      '</div>';

    exposeAppState({ lens: 'bias_clarity', items: rows, summary: { ok, warn, alert } });
  }

  // ==================================================================
  // LENS: response_scale
  // ==================================================================
  function renderResponseScale() {
    const items = allVars.filter(isLikert);
    if (items.length < 1) { empty('Response Scale Review', 'Need at least 1 Likert item.'); return; }
    const rows = items.map(v => {
      const nums = v.values.map(num).filter(x => x != null);
      if (!nums.length) return null;
      const lo = Math.min.apply(null, nums), hi = Math.max.apply(null, nums);
      const range = hi - lo;
      const dist = new Map();
      nums.forEach(x => dist.set(x, (dist.get(x) || 0) + 1));
      const points = Array.from(dist.entries()).sort((a, b) => a[0] - b[0]);
      const total = nums.length;
      const midpoint = (lo + hi) / 2;
      const midpointN = dist.get(midpoint) || 0;
      const ceil  = (dist.get(hi) || 0) / total;
      const floor = (dist.get(lo) || 0) / total;
      const midRate = midpointN / total;
      const flags = [];
      if (ceil  >= 0.50) flags.push(Math.round(ceil * 100) + '% at top');
      if (floor >= 0.50) flags.push(Math.round(floor * 100) + '% at bottom');
      if (midRate >= 0.40) flags.push(Math.round(midRate * 100) + '% on midpoint (possible neutral-overuse)');
      if (points.length < 4) flags.push('only ' + points.length + ' distinct value' + (points.length === 1 ? '' : 's') + ' used');
      return { name: v.name, n: total, range: range, points: points, ceil: ceil, floor: floor, midRate: midRate, flags: flags };
    }).filter(r => r);

    const flagged = rows.filter(r => r.flags.length).length;

    // Headline
    document.getElementById('iqRsTitle').textContent = 'Response Scale Review: ' + rows.length + ' items';
    document.getElementById('iqRsSub').textContent   = flagged + ' item' + (flagged === 1 ? '' : 's') + ' with response-pattern flags · ' + (rows.length - flagged) + ' balanced';

    // Parametric copy
    const judgment = flagged
      ? flagged + ' item' + (flagged === 1 ? '' : 's') + ' show ceiling, floor, midpoint-overuse, or range-restriction patterns and may not discriminate well in their current form.'
      : 'Response options are used in balanced ways across all items; the scale is doing the work it was designed to do.';
    const plainPara = flagged
      ? 'A scale that everyone answers the same way can\'t tell people apart. The flagged items show one of these patterns: most respondents picked the top, most picked the bottom, most parked on the middle, or only a few of the scale points were actually used.'
      : 'Every response option is being used in a healthy mix. Respondents are spreading their answers across the scale rather than parking on one or two points.';
    const researchPara = 'Per-item flags: ≥50% at the top endpoint (ceiling), ≥50% at the bottom endpoint (floor), ≥40% at the midpoint (possible neutral overuse), or fewer than four distinct values used (range restriction). Each pattern reduces the item\'s ability to discriminate among respondents.';
    const closingPara = flagged
      ? 'For each flagged item, consider whether the response scale should be revised (e.g., expand the high end), whether the wording invites a default answer, or whether the item belongs in this instrument at all.'
      : 'No corrective action required. The response scales are earning their points.';

    // Table — one row per item, with embedded mini distribution
    const tbody = rows.map(r => {
      const tone = r.flags.length >= 2 ? 'alert' : r.flags.length ? 'warn' : 'strong';
      const status = r.flags.length >= 2 ? 'Problem' : r.flags.length ? 'Watch' : 'Balanced';
      const maxPoint = r.points.reduce((m, p) => Math.max(m, p[1]), 1);
      const miniBars = r.points.map(([val, count]) => (
        '<span title="' + val + ': ' + count + '" style="display:inline-block;width:14px;height:' + Math.max(3, Math.round((count / maxPoint) * 22)) + 'px;background:var(--accent, #e85d3a);opacity:0.7;border-radius:2px;margin-right:2px;vertical-align:bottom;"></span>'
      )).join('');
      const plain = r.flags.length
        ? 'This item\'s answers are clustered: ' + r.flags.join(' · ') + '.'
        : 'Answers spread evenly across the response scale.';
      const research = r.flags.length
        ? 'Flags: ' + r.flags.join('; ') + '. n = ' + r.n + '; distinct values used: ' + r.points.length + '.'
        : 'No ceiling, floor, midpoint, or range-restriction flags. n = ' + r.n + '.';
      const action = tone === 'alert' ? 'Revise the response scale or item wording.'
                   : tone === 'warn'  ? 'Inspect; consider revising response options.'
                                       : 'Keep as-is.';
      return '<tr data-tone="' + tone + '">' +
        '<td class="iq-rel-item">' + esc(r.name) + '</td>' +
        '<td class="iq-num">' + r.n + '</td>' +
        '<td style="white-space:nowrap;">' + miniBars + '</td>' +
        '<td><span class="iq-flag" data-tone="' + tone + '">' + status + '</span></td>' +
        '<td class="iq-rel-plain">' + esc(plain) + '</td>' +
        '<td class="iq-rel-research">' + esc(research) + '</td>' +
        '<td class="iq-rel-action">' + esc(action) + '</td>' +
      '</tr>';
    }).join('');

    document.getElementById('iqRsBody').innerHTML =
      '<div class="iq-rel-summary">' +
        '<h4 class="iq-block-h">Response scale summary</h4>' +
        '<p class="iq-rel-summary-line"><strong>' + esc(judgment) + '</strong></p>' +
        '<div class="iq-rel-paragraphs">' +
          '<div><span class="iq-rel-label">Plain-language explanation.</span> ' + esc(plainPara) + '</div>' +
          '<div><span class="iq-rel-label">Research interpretation.</span> ' + esc(researchPara) + '</div>' +
        '</div>' +
      '</div>' +
      '<h4 class="iq-block-h">Per-item response distribution</h4>' +
      '<div class="iq-rel-table-wrap">' +
        '<table class="iq-table iq-table-rel">' +
          '<thead><tr>' +
            '<th>Item</th>' +
            '<th class="iq-num">n</th>' +
            '<th>Distribution</th>' +
            '<th>Status</th>' +
            '<th>Plain-language meaning</th>' +
            '<th>Research interpretation</th>' +
            '<th>Recommended action</th>' +
          '</tr></thead>' +
          '<tbody>' + tbody + '</tbody>' +
        '</table>' +
      '</div>' +
      '<div class="iq-rel-closing"><h4 class="iq-block-h">Interpretation</h4>' +
        '<p>' + esc(closingPara) + '</p>' +
      '</div>';

    document.getElementById('iqRsInterp').innerHTML =
      '<div class="iq-jema">' +
        '<div class="iq-jema-row"><strong>Judgment.</strong> ' + esc(judgment) + '</div>' +
        '<div class="iq-jema-row"><strong>Evidence.</strong> ' + flagged + ' of ' + rows.length + ' items with response-pattern flags.</div>' +
        '<div class="iq-jema-row"><strong>Meaning.</strong> ' + esc(researchPara) + '</div>' +
        '<div class="iq-jema-row"><strong>Action.</strong> ' + esc(closingPara) + '</div>' +
      '</div>';

    exposeAppState({ lens: 'response_scale', items: rows });
  }

  // ==================================================================
  // Shared helpers
  // ==================================================================
  function statBox(label, value, pillText, pillTone) {
    return '<div class="iq-stat">' +
      '<label>' + esc(label) + '</label>' +
      '<span class="v">' + esc(value) + '</span>' +
      (pillText ? '<span class="iq-pip-pill" data-tone="' + esc(pillTone || 'muted') + '">' + esc(pillText) + '</span>' : '') +
    '</div>';
  }
  function empty(title, msg) {
    const lensEl = document.querySelector('.iq-lens[data-lens="' + lens + '"]');
    if (!lensEl) return;
    const titleEl = lensEl.querySelector('h3');
    const subEl   = lensEl.querySelector('.iq-sub');
    const body    = lensEl.querySelector('.iq-body');
    if (titleEl) titleEl.textContent = title;
    if (subEl)   subEl.textContent   = msg;
    if (body)    body.innerHTML      = '<p class="iq-flat">' + esc(msg) + '</p>';
    exposeAppState({ lens: lens, empty: true });
  }
  function exposeAppState(payload) {
    window.RELICHECK_APP_STATE = Object.assign({
      app_key:  'instrument_quality',
      app_name: 'Instrument Quality (' + lens + ')',
      summary:  summarize(payload),
      lens:     lens,
      computed_at: new Date().toISOString(),
    }, payload);
  }
  function summarize(p) {
    if (p.lens === 'validity')         return 'Validity: α = ' + fmt(p.alpha, 2) + ', avg r = ' + fmt(p.avgR, 2) + '.';
    if (p.lens === 'item_quality' && p.summary) return p.summary.ok + ' clean / ' + p.summary.warn + ' warn / ' + p.summary.alert + ' alert.';
    if (p.lens === 'scale_structure' && p.clusters) return p.clusters.length + ' candidate scale(s).';
    if (p.lens === 'factor_readiness') return 'KMO = ' + fmt(p.kmo, 2) + (p.bartlettP != null ? ', Bartlett ' + fmtP(p.bartlettP) : '') + '.';
    if (p.lens === 'bias_clarity' && p.summary) return p.summary.ok + ' clean / ' + p.summary.warn + ' watch / ' + p.summary.alert + ' issue.';
    if (p.lens === 'response_scale' && p.items)  return p.items.length + ' items reviewed.';
    return 'Instrument quality detail.';
  }

  /* Legacy duplicate of the public API (kept for compatibility but
     superseded by the top-of-file registration). The block below was
     moved up so it runs even when the engine bails out on missing DOM.
     Leaving the function definition here is harmless dead code; it is
     never reached because the `if (!document.getElementById('iqEmpty'))
     return;` early-out above prevents the rest of the engine from
     executing when this file is loaded on /rssi-upload.php. */
  function _legacyConstructAlignmentNarrative_unused(likertItems) {
    if (!Array.isArray(likertItems) || likertItems.length < 2) {
      return { error: 'Need at least 2 Likert items.' };
    }
    const itemArrays = likertItems.map(function (it) {
      return (it.values || []).map(function (v) {
        const x = parseFloat(v); return isNaN(x) ? null : x;
      });
    });
    const cc = _completeCases(itemArrays);
    if (cc.validRows.length < 3) return { error: 'Need 3+ complete responses across all items.' };
    const validCols = cc.validCols;
    const validRows = cc.validRows;
    const k = likertItems.length;

    const scaleLabel = {
      SA: 'Self-Awareness', SM: 'Self-Management', SO: 'Social Awareness', RM: 'Relationship Management',
      EI: 'Emotional Intelligence', ER: 'Emotional Regulation', EE: 'Engagement',
    };
    function inferScale(name) {
      const base = String(name || '').replace(/_R$/i, '');
      const m = base.match(/^([A-Za-z]+)/);
      if (!m) return null;
      const pref = m[1].toUpperCase();
      return scaleLabel[pref] || pref;
    }
    function isReverse(name) { return /_R$/i.test(String(name || '')); }

    // Item-rest correlations against the full pooled total
    const itemRest = likertItems.map(function (_, i) {
      const item = validCols[i];
      const rest = validRows.map(function (_, idx) {
        let s = 0;
        for (let j = 0; j < validCols.length; j++) if (j !== i) s += validCols[j][idx];
        return s;
      });
      return _pearson(item, rest);
    });

    // Two-cluster split
    const cluster1Idx = [], cluster2Idx = [];
    likertItems.forEach(function (_, i) {
      if (itemRest[i] < 0) cluster2Idx.push(i);
      else cluster1Idx.push(i);
    });

    const intendedConstructs = Array.from(new Set(likertItems.map(function (v) { return inferScale(v.name); }).filter(Boolean)));
    const c2Names      = cluster2Idx.map(function (i) { return likertItems[i].name; });
    const c2Constructs = Array.from(new Set(c2Names.map(inferScale).filter(Boolean)));
    const c2RevShare   = c2Names.length ? (c2Names.filter(isReverse).length / c2Names.length) : 0;
    const methodEffect = cluster2Idx.length >= 2 && (c2RevShare >= 0.8 || c2Constructs.length >= 2);
    const c1Names      = cluster1Idx.map(function (i) { return likertItems[i].name; });
    const c1Constructs = Array.from(new Set(c1Names.map(inferScale).filter(Boolean)));
    const detectedClusters = (cluster1Idx.length >= 2 ? 1 : 0) + (cluster2Idx.length >= 2 ? 1 : 0);

    let status, statusTone;
    if (methodEffect)                                                                  { status = 'Method-effect warning'; statusTone = 'alert'; }
    else if (detectedClusters === intendedConstructs.length && c1Constructs.length === 1) { status = 'Strong alignment';     statusTone = 'strong'; }
    else if (cluster2Idx.length === 0 && intendedConstructs.length === 1)               { status = 'Mostly aligned';       statusTone = 'ok'; }
    else if (cluster2Idx.length === 0)                                                  { status = 'Mixed alignment';      statusTone = 'warn'; }
    else                                                                                { status = 'Not aligned';          statusTone = 'alert'; }

    const headlineInterp = methodEffect
      ? 'The detected clusters do not cleanly match the intended scale names. Items appear to separate by wording/scoring direction, especially the reverse-coded items.'
      : status === 'Strong alignment' ? 'Empirical clustering reproduces the intended construct structure.'
      : status === 'Mostly aligned'   ? 'Items group as expected, with minor cross-construct overlap.'
      :                                 'Items cluster in patterns that do not clearly match the named constructs.';

    const plain = methodEffect
      ? 'The survey appears to be grouping items by how they are worded rather than by what they are supposed to measure. Most regular items group together, while the reverse-coded items form their own group. This may mean the reverse-coded items were not scored correctly, or that respondents answered negatively worded items differently.'
      : status === 'Strong alignment'
        ? 'Items group together along the lines you would expect from their scale names. The structure is consistent with the intended design.'
        : 'Items are not splitting cleanly by their named constructs. Some items from different constructs are clustering together; others appear to belong to a single broad group.';

    const action = methodEffect
      ? 'Confirm reverse-coding and test subscales separately before reporting any construct-level score.'
      : status === 'Strong alignment'
        ? 'No structural action required. Confirm content validity with a subject-matter expert.'
        : 'Test each intended construct as its own subscale; compare detected clusters to the theoretical map.';

    return {
      status: status, statusTone: statusTone,
      headlineInterp: headlineInterp,
      plain: plain,
      action: action,
      detectedClusters: detectedClusters,
      intendedConstructs: intendedConstructs.length,
      methodEffect: methodEffect,
      cluster1Count: cluster1Idx.length,
      cluster2Count: cluster2Idx.length,
      itemCount: k,
      completeResponses: validRows.length,
    };
  }

  /* (Public API registration is now at the top of the file so it runs
     even when this engine bails out on missing DOM.) */
})();
