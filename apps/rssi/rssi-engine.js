/* ════════════════════════════════════════════════════════════════════════
   RSSI v1 — Survey Strength Index scoring engine (deterministic, post-data)
   ────────────────────────────────────────────────────────────────────────
   RSSI = ReliCheck Survey Strength Index. It runs AFTER responses are
   collected and answers "did the collected data perform reliably and support
   interpretation?" Reliability is one core domain, not the whole.

   This engine is PURE and DETERMINISTIC: given a Phase 4A dataset object
   (the JSON emitted by api/dev/rssi-dataset.php), it returns structured
   domain scores, construct evidence, item warnings, fence notes, and a
   summary. It computes NO factor analysis, runs NO AI, and renders NO UI.

   LOCKED v1 SPINE (point budgets sum to 100)
       Internal Consistency        35   (the core; Cronbach alpha per
                                          construct, rolled up. Structure
                                          folded in as sub-evidence.)
       Item Performance             25   (corrected item-total r, difficulty,
                                          dead/redundant items)
       Response Quality             20   (completion, missingness,
                                          straight-lining)
       Score Interpretability       20   (distribution health, floor/ceiling,
                                          usable variability)

   N ADEQUACY is a CROSS-CUTTING FENCE, not a scored domain. When evidence is
   too thin the engine WITHHOLDS the score and reports "Insufficient data to
   judge" rather than forcing a reliability claim, at both construct and
   survey level. Internal Consistency is the core: if it cannot be computed at
   all, the whole index is withheld.

   REPORTING is CONSTRUCT-FIRST, then rolled up.

   BANDS (interpret-centered)
       85–100      Reliable enough to interpret with confidence
       70–84.9     Reliable with minor cautions
       55–69.9     Interpret with caution
       below 55    Not yet reliable
       fence       Insufficient data to judge   (overrides the number)
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  var WEIGHTS = {
    internal_consistency: 35,
    item_performance: 25,
    response_quality: 20,
    score_interpretability: 20
  };

  // Minimum analyzable responses before ANY reliability claim. Defaults to the
  // dataset's own min_n (30) but is read from the dataset so the two stay in
  // step.
  var DEFAULT_MIN_N = 30;
  // A single construct needs this many complete-case respondents before its
  // alpha is trustworthy enough to score (looser than the survey fence; the
  // survey fence already guarantees >= MIN_N overall).
  var CONSTRUCT_MIN_N = 10;
  // Below this analyzable N we are critically thin: withhold even descriptive
  // reliability evidence and return counts only.
  var CRITICAL_N = 10;
  // A construct needs at least this many scorable items for internal
  // consistency to mean anything.
  var MIN_ITEMS_PER_CONSTRUCT = 3;
  // Inter-item correlation above this within a construct flags redundancy.
  var REDUNDANCY_R = 0.85;

  // ── small numeric helpers ────────────────────────────────────────────────
  function toNum(raw) {
    if (raw === null || raw === undefined) return null;
    var n = parseFloat(String(raw).trim());
    return isFinite(n) ? n : null;
  }
  function mean(a) {
    if (!a.length) return 0;
    var s = 0; for (var i = 0; i < a.length; i++) s += a[i];
    return s / a.length;
  }
  // sample variance (n-1); returns 0 for n<2
  function variance(a) {
    if (a.length < 2) return 0;
    var m = mean(a), s = 0;
    for (var i = 0; i < a.length; i++) { var d = a[i] - m; s += d * d; }
    return s / (a.length - 1);
  }
  function sd(a) { return Math.sqrt(variance(a)); }
  function pearson(x, y) {
    var n = x.length;
    if (n < 2) return 0;
    var mx = mean(x), my = mean(y), num = 0, dx = 0, dy = 0;
    for (var i = 0; i < n; i++) {
      var a = x[i] - mx, b = y[i] - my;
      num += a * b; dx += a * a; dy += b * b;
    }
    if (dx === 0 || dy === 0) return 0;
    return num / Math.sqrt(dx * dy);
  }
  function round1(n) { return Math.round(n * 10) / 10; }
  function round3(n) { return Math.round(n * 1000) / 1000; }
  function clamp(n, lo, hi) { return Math.max(lo, Math.min(hi, n)); }

  // Piecewise-linear interpolation across a table of [x, y] knots (x ascending).
  function interp(x, knots) {
    if (x <= knots[0][0]) return knots[0][1];
    var last = knots[knots.length - 1];
    if (x >= last[0]) return last[1];
    for (var i = 1; i < knots.length; i++) {
      if (x <= knots[i][0]) {
        var a = knots[i - 1], b = knots[i];
        var t = (x - a[0]) / (b[0] - a[0]);
        return a[1] + t * (b[1] - a[1]);
      }
    }
    return last[1];
  }

  // Cronbach's alpha quality → fraction of the points it earns. Calibrated so
  // .70 (the conventional "acceptable" line) lands at 0.75 of the budget and
  // .80+ approaches full credit. Negative/zero alpha earns nothing.
  var ALPHA_TO_FRACTION = [
    [-1.0, 0], [0.0, 0], [0.50, 0.35], [0.60, 0.55],
    [0.70, 0.75], [0.80, 0.90], [0.90, 1.0], [1.0, 1.0]
  ];
  // Corrected item-total correlation → per-item quality fraction.
  var ITR_TO_FRACTION = [
    [-1.0, 0], [0.0, 0], [0.10, 0.30], [0.20, 0.60],
    [0.30, 0.85], [0.40, 1.0], [1.0, 1.0]
  ];

  function alphaBand(alpha) {
    if (alpha >= 0.90) return 'excellent';
    if (alpha >= 0.80) return 'good';
    if (alpha >= 0.70) return 'acceptable';
    if (alpha >= 0.60) return 'questionable';
    if (alpha >= 0.50) return 'poor';
    return 'unacceptable';
  }

  // Cronbach's alpha over a complete-case matrix (rows = respondents, cols =
  // items). Returns null when it cannot be computed (k<2, n<2, zero total
  // variance).
  function cronbachAlpha(matrix, k) {
    var n = matrix.length;
    if (k < 2 || n < 2) return null;
    var cols = [];
    for (var j = 0; j < k; j++) cols.push([]);
    var totals = [];
    for (var r = 0; r < n; r++) {
      var rowSum = 0;
      for (var c = 0; c < k; c++) { cols[c].push(matrix[r][c]); rowSum += matrix[r][c]; }
      totals.push(rowSum);
    }
    var sumItemVar = 0;
    for (var c2 = 0; c2 < k; c2++) sumItemVar += variance(cols[c2]);
    var totalVar = variance(totals);
    if (totalVar === 0) return null;
    return (k / (k - 1)) * (1 - sumItemVar / totalVar);
  }

  // ── dataset projection ────────────────────────────────────────────────────
  // Build quick lookups from the 4A dataset object.
  function indexItems(dataset) {
    var byId = {};
    (dataset.items || []).forEach(function (it) { byId[it.id] = it; });
    return byId;
  }
  // sessionId → numeric value, for one scorable item.
  function valueMap(item) {
    var m = {};
    (item.values || []).forEach(function (v) {
      var n = toNum(v.raw);
      if (n !== null) m[v.sessionId] = n;
    });
    return m;
  }
  // Infer [min,max] scale range for an item, preferring declared scale.
  function scaleRange(item) {
    var lo = null, hi = null;
    if (item.scale) {
      if (item.scale.min !== null && item.scale.min !== undefined) lo = toNum(item.scale.min);
      if (item.scale.max !== null && item.scale.max !== undefined) hi = toNum(item.scale.max);
      if (lo === null && item.scale.points) { lo = 1; hi = toNum(item.scale.points); }
    }
    if (lo === null || hi === null) {
      var vals = (item.values || []).map(function (v) { return toNum(v.raw); }).filter(function (n) { return n !== null; });
      if (vals.length) {
        if (lo === null) lo = Math.min.apply(null, vals);
        if (hi === null) hi = Math.max.apply(null, vals);
      }
    }
    if (lo === null) lo = 1;
    if (hi === null) hi = lo + 1;
    if (hi <= lo) hi = lo + 1;
    return { min: lo, max: hi, range: hi - lo };
  }

  // For a construct, assemble its scorable items, per-item value maps, the set
  // of complete-case sessions (answered every scorable item), and the matrix.
  function constructMatrix(construct, itemsById) {
    var scorableItems = (construct.itemIds || [])
      .map(function (id) { return itemsById[id]; })
      .filter(function (it) { return it && it.scorable; });
    var maps = scorableItems.map(valueMap);
    // sessions answering ALL scorable items
    var sessionSets = maps.map(function (m) { return Object.keys(m); });
    var complete = [];
    if (sessionSets.length) {
      sessionSets[0].forEach(function (sid) {
        for (var j = 1; j < maps.length; j++) { if (!(sid in maps[j])) return; }
        complete.push(sid);
      });
    }
    complete.sort(function (a, b) { return (+a) - (+b); });
    var matrix = complete.map(function (sid) {
      return maps.map(function (m) { return m[sid]; });
    });
    return { items: scorableItems, maps: maps, sessions: complete, matrix: matrix };
  }

  // ════════════════════════════════════════════════════════════════════════
  // MAIN
  // ════════════════════════════════════════════════════════════════════════
  function score(dataset) {
    if (!dataset || !dataset.responses) {
      throw new Error('rssi-engine: a Phase 4A dataset object is required');
    }
    var itemsById = indexItems(dataset);
    var minN = (typeof dataset.responses.min_n === 'number') ? dataset.responses.min_n : DEFAULT_MIN_N;
    var analyzableN = dataset.responses.analyzable_n || 0;
    var totalN = dataset.responses.total_n || 0;
    var tooFew = (typeof dataset.responses.too_few_responses === 'boolean')
      ? dataset.responses.too_few_responses
      : (analyzableN < minN);

    var fenceNotes = (dataset.responses.fence_notes || []).slice();

    // Items that exist but are never reliability-scored (open text, choice,
    // etc.) — labeled, NOT treated as broken.
    var excludedItems = [];
    (dataset.items || []).forEach(function (it) {
      if (it.structural) return; // structural items are not survey content here
      if (!it.scorable) {
        excludedItems.push({
          itemId: it.id, label: it.label, fieldType: it.fieldType,
          reason: 'Not reliability-scored. ' + reasonForExclusion(it.fieldType)
        });
      }
    });

    var fenceLevel = tooFew ? (analyzableN < CRITICAL_N ? 'critical' : 'thin') : 'ok';

    // ── HARD FENCE: too few responses → withhold the number entirely ────────
    if (tooFew) {
      fenceNotes.push('RSSI withholds a reliability score: ' + analyzableN +
        ' analyzable response' + (analyzableN === 1 ? '' : 's') +
        ' is below the minimum of ' + minN + '. The verdict is "Insufficient data to judge" rather than a forced score.');
      return withheldResult(dataset, {
        analyzableN: analyzableN, totalN: totalN, minN: minN, tooFew: true, level: fenceLevel,
        notes: fenceNotes
      }, excludedItems,
      'Not enough data to judge reliability yet. ' +
        'Collect at least ' + minN + ' analyzable responses (currently ' + analyzableN + '), then re-run RSSI.');
    }

    // ── Per-construct internal-consistency evidence (construct-first) ───────
    var constructEvidence = [];
    var icScored = [];      // {fraction, weight} for rolled-up internal consistency
    var itemWarnings = [];
    var ipItemFractions = [];   // per scorable item, for Item Performance
    var siConstructScores = []; // per construct, distribution health for Score Interpretability

    (dataset.constructs || []).forEach(function (con) {
      var cm = constructMatrix(con, itemsById);
      var scorableCount = cm.items.length;
      var enoughItems = scorableCount >= MIN_ITEMS_PER_CONSTRUCT;
      var n = cm.matrix.length;

      var ev = {
        id: con.id, name: con.name,
        scorableCount: scorableCount, itemCount: con.itemCount,
        enoughItems: enoughItems, n: n,
        alpha: null, alphaBand: null, scored: false, note: ''
      };

      if (!enoughItems) {
        ev.note = 'Not enough scorable items (' + scorableCount + ' of ' + MIN_ITEMS_PER_CONSTRUCT +
          ' needed). Reported as not enough evidence rather than forcing a reliability number.';
        constructEvidence.push(ev);
        return;
      }
      if (n < CONSTRUCT_MIN_N) {
        ev.note = 'Only ' + n + ' complete-case responses for this construct (need ' + CONSTRUCT_MIN_N +
          '). Internal consistency is withheld for this construct; not enough evidence.';
        constructEvidence.push(ev);
        return;
      }

      var alpha = cronbachAlpha(cm.matrix, scorableCount);
      if (alpha === null) {
        ev.note = 'Internal consistency could not be computed (no usable variation in the responses).';
        constructEvidence.push(ev);
        return;
      }

      ev.alpha = round3(alpha);
      ev.alphaBand = alphaBand(alpha);
      ev.scored = true;
      var aFrac = interp(alpha, ALPHA_TO_FRACTION);
      icScored.push({ fraction: aFrac, weight: scorableCount, name: con.name });

      // structure sub-evidence: any negative inter-item correlation is a
      // dimensionality smell folded into internal consistency.
      var negPairs = countNegativeInterItem(cm);
      if (negPairs > 0) {
        ev.note = negPairs + ' item pair' + (negPairs === 1 ? '' : 's') +
          ' correlate negatively, a sign the construct may not be one-dimensional. Reliability is reported with that caution.';
      }

      // ── Item Performance evidence for this construct's items ──────────────
      perItemPerformance(cm, con, itemWarnings, ipItemFractions);

      // ── Score Interpretability evidence (construct mean scores) ───────────
      siConstructScores.push(constructDistribution(cm, con));

      constructEvidence.push(ev);
    });

    // ── Internal Consistency domain (the core) ──────────────────────────────
    var icDomain = rollUpInternalConsistency(icScored, dataset, itemsById, fenceNotes);

    // If the core cannot be computed at all, withhold the whole index.
    if (icDomain.fraction === null) {
      fenceNotes.push('RSSI withholds a reliability score: internal consistency, the core domain, could not be computed for any construct or the survey as a whole.');
      return withheldResult(dataset, {
        analyzableN: analyzableN, totalN: totalN, minN: minN, tooFew: false, level: 'no_structure',
        notes: fenceNotes
      }, excludedItems,
      'There are enough responses, but not enough scorable structure to judge reliability. ' +
        'Add scale items grouped into constructs, collect responses, then re-run RSSI.');
    }

    // ── Item Performance domain ─────────────────────────────────────────────
    var ipFraction = ipItemFractions.length ? mean(ipItemFractions) : icDomain.fraction;
    var ipDomain = domainOut('item_performance', 'Item Performance', ipFraction, [
      ipItemFractions.length + ' scorable item' + (ipItemFractions.length === 1 ? '' : 's') + ' evaluated for discrimination, difficulty, and redundancy.',
      itemWarnings.length + ' item warning' + (itemWarnings.length === 1 ? '' : 's') + ' raised.'
    ]);

    // ── Response Quality domain ─────────────────────────────────────────────
    var rqDomain = responseQuality(dataset, itemsById);

    // ── Score Interpretability domain ────────────────────────────────────────
    var siDomain = scoreInterpretability(siConstructScores);

    var domains = [icDomain.domain, ipDomain, rqDomain, siDomain];
    var total = 0;
    domains.forEach(function (d) { total += d.points; });
    total = round1(total);
    var band = bandFor(total);

    return {
      ok: true,
      version: 'rssi-v1',
      projectId: dataset.projectId,
      projectName: dataset.projectName,
      fence: {
        analyzableN: analyzableN, totalN: totalN, minN: minN,
        tooFew: false, withheld: false, level: 'ok', notes: fenceNotes
      },
      score: total,
      max: 100,
      pct: total,
      band: band.label,
      bandKey: band.key,
      verdict: band.label,
      domains: domains,
      constructs: constructEvidence,
      itemWarnings: itemWarnings,
      excludedItems: excludedItems,
      fenceNotes: fenceNotes,
      summary: buildSummary(total, band, constructEvidence, itemWarnings, analyzableN)
    };
  }

  // ── internal consistency roll-up + whole-survey fallback ───────────────────
  function rollUpInternalConsistency(icScored, dataset, itemsById, fenceNotes) {
    if (icScored.length) {
      var wsum = 0, fsum = 0;
      icScored.forEach(function (s) { wsum += s.weight; fsum += s.fraction * s.weight; });
      var frac = wsum > 0 ? fsum / wsum : null;
      return {
        fraction: frac,
        domain: domainOut('internal_consistency', 'Internal Consistency', frac, [
          icScored.length + ' construct' + (icScored.length === 1 ? '' : 's') + ' scored for internal consistency, item-weighted.',
          'Structure (negative inter-item correlations) folded in as sub-evidence.'
        ])
      };
    }
    // Fallback: no construct could be scored, try whole-survey alpha across all
    // scorable items (state the limitation).
    var scorable = (dataset.items || []).filter(function (it) { return it.scorable; });
    if (scorable.length >= MIN_ITEMS_PER_CONSTRUCT) {
      var maps = scorable.map(valueMap);
      var sets = maps.map(function (m) { return Object.keys(m); });
      var complete = [];
      if (sets.length) {
        sets[0].forEach(function (sid) {
          for (var j = 1; j < maps.length; j++) { if (!(sid in maps[j])) return; }
          complete.push(sid);
        });
      }
      if (complete.length >= CONSTRUCT_MIN_N) {
        var matrix = complete.map(function (sid) { return maps.map(function (m) { return m[sid]; }); });
        var alpha = cronbachAlpha(matrix, scorable.length);
        if (alpha !== null) {
          fenceNotes.push('No construct could be scored individually, so internal consistency falls back to a whole-survey alpha (' + round3(alpha) + '). Treat construct-level reliability as not yet established.');
          var f = interp(alpha, ALPHA_TO_FRACTION);
          return {
            fraction: f,
            domain: domainOut('internal_consistency', 'Internal Consistency', f, [
              'Whole-survey fallback alpha = ' + round3(alpha) + ' (' + alphaBand(alpha) + ').',
              'Construct-level reliability not established; reported as a survey-wide estimate only.'
            ])
          };
        }
      }
    }
    return { fraction: null, domain: domainOut('internal_consistency', 'Internal Consistency', null, ['Could not be computed.']) };
  }

  // corrected item-total r + difficulty + dead/redundant flags for one construct
  function perItemPerformance(cm, con, itemWarnings, ipItemFractions) {
    var k = cm.items.length;
    // column vectors over complete cases
    var cols = [];
    for (var j = 0; j < k; j++) cols.push(cm.matrix.map(function (row) { return row[j]; }));
    // total scores per respondent
    var totals = cm.matrix.map(function (row) {
      var s = 0; for (var c = 0; c < row.length; c++) s += row[c]; return s;
    });

    for (var i = 0; i < k; i++) {
      var item = cm.items[i];
      var col = cols[i];
      var rest = totals.map(function (t, idx) { return t - col[idx]; }); // corrected
      var itr = pearson(col, rest);
      var v = variance(col);
      var rng = scaleRange(item);
      var m = mean(col);
      var diff = clamp((m - rng.min) / rng.range, 0, 1); // 0=floor, 1=ceiling

      // per-item quality fraction (dead item earns nothing)
      var frac = (v === 0) ? 0 : interp(itr, ITR_TO_FRACTION);
      ipItemFractions.push(frac);

      if (v === 0) {
        itemWarnings.push(warn(item, con, 'dead_item', 'err',
          'Every respondent gave the same answer, so this item adds no information.'));
      } else if (itr < 0) {
        itemWarnings.push(warn(item, con, 'negative_discrimination', 'err',
          'Corrected item-total correlation is negative (' + round3(itr) + '); the item may be reverse-keyed or measuring something else.'));
      } else if (itr < 0.20) {
        itemWarnings.push(warn(item, con, 'low_discrimination', 'warn',
          'Weak corrected item-total correlation (' + round3(itr) + '); the item barely tracks its construct.'));
      }
      if (v > 0 && diff <= 0.15) {
        itemWarnings.push(warn(item, con, 'floor', 'info',
          'Responses cluster near the bottom of the scale (mean ' + round1(m) + ').'));
      } else if (v > 0 && diff >= 0.85) {
        itemWarnings.push(warn(item, con, 'ceiling', 'info',
          'Responses cluster near the top of the scale (mean ' + round1(m) + ').'));
      }
    }

    // redundancy: high inter-item correlation
    for (var a = 0; a < k; a++) {
      for (var b = a + 1; b < k; b++) {
        var r = pearson(cols[a], cols[b]);
        if (r > REDUNDANCY_R) {
          itemWarnings.push(warn(cm.items[a], con, 'redundant', 'info',
            'Very highly correlated (r=' + round3(r) + ') with "' + cm.items[b].label + '"; the two may be redundant.'));
        }
      }
    }
  }

  function countNegativeInterItem(cm) {
    var k = cm.items.length, neg = 0;
    var cols = [];
    for (var j = 0; j < k; j++) cols.push(cm.matrix.map(function (row) { return row[j]; }));
    for (var a = 0; a < k; a++) {
      for (var b = a + 1; b < k; b++) {
        if (pearson(cols[a], cols[b]) < 0) neg++;
      }
    }
    return neg;
  }

  // distribution health of a construct's mean scores (for Score Interpretability)
  function constructDistribution(cm, con) {
    var rng = scaleRange(cm.items[0]);
    var scores = cm.matrix.map(function (row) {
      var s = 0; for (var c = 0; c < row.length; c++) s += row[c]; return s / row.length;
    });
    var s = sd(scores);
    var floor = scores.filter(function (x) { return x <= rng.min + 0.001; }).length / scores.length;
    var ceil = scores.filter(function (x) { return x >= rng.max - 0.001; }).length / scores.length;
    // usable variability: ideal SD ~ range/4; credit climbs to 1 there, then
    // we do not punish a bit more spread.
    var idealSd = rng.range / 4;
    var varScore = idealSd > 0 ? clamp(s / idealSd, 0, 1) : 0;
    // distribution health: start from variability, dock floor/ceiling piling.
    var health = varScore * (1 - 0.5 * Math.max(floor, ceil));
    return {
      name: con.name, sd: round3(s), floorRate: round3(floor), ceilingRate: round3(ceil),
      health: clamp(health, 0, 1)
    };
  }

  function scoreInterpretability(siConstructScores) {
    if (!siConstructScores.length) {
      return domainOut('score_interpretability', 'Score Interpretability', 0, ['No scored construct to evaluate distribution health.']);
    }
    var frac = mean(siConstructScores.map(function (s) { return s.health; }));
    var hiFloor = siConstructScores.filter(function (s) { return s.floorRate >= 0.2 || s.ceilingRate >= 0.2; }).length;
    return domainOut('score_interpretability', 'Score Interpretability', frac, [
      'Distribution health across ' + siConstructScores.length + ' construct' + (siConstructScores.length === 1 ? '' : 's') + '.',
      hiFloor + ' construct' + (hiFloor === 1 ? '' : 's') + ' show notable floor/ceiling piling.'
    ]);
  }

  function responseQuality(dataset, itemsById) {
    var inputItems = (dataset.items || []).filter(function (it) { return !it.structural; });
    var scorableItems = inputItems.filter(function (it) { return it.scorable; });
    var sessions = (dataset.sessions || []).map(function (s) { return s.id; });
    var totalInput = inputItems.length;

    // answered-count per session (across input items)
    var answeredCount = {};
    inputItems.forEach(function (it) {
      (it.values || []).forEach(function (v) {
        answeredCount[v.sessionId] = (answeredCount[v.sessionId] || 0) + 1;
      });
    });
    var completions = sessions.map(function (sid) {
      return totalInput ? (answeredCount[sid] || 0) / totalInput : 0;
    });
    var meanCompletion = mean(completions);
    var answeredTotal = 0; sessions.forEach(function (sid) { answeredTotal += (answeredCount[sid] || 0); });
    var missingRate = (sessions.length && totalInput)
      ? 1 - answeredTotal / (sessions.length * totalInput) : 0;

    // straight-lining: per session, identical value across all scorable items
    // it answered (only eligible if it answered >= 3 scorable items).
    var perSessionScorable = {}; // sid -> [values]
    scorableItems.forEach(function (it) {
      (it.values || []).forEach(function (v) {
        var n = toNum(v.raw); if (n === null) return;
        (perSessionScorable[v.sessionId] = perSessionScorable[v.sessionId] || []).push(n);
      });
    });
    var eligible = 0, straight = 0;
    Object.keys(perSessionScorable).forEach(function (sid) {
      var arr = perSessionScorable[sid];
      if (arr.length < 3) return;
      eligible++;
      var allSame = arr.every(function (x) { return x === arr[0]; });
      if (allSame) straight++;
    });
    var straightRate = eligible ? straight / eligible : 0;

    // fraction: completion is the base, straight-lining docks up to 50%.
    var frac = meanCompletion * (1 - Math.min(straightRate, 0.5));
    return domainOut('response_quality', 'Response Quality', frac, [
      'Mean completion ' + Math.round(meanCompletion * 100) + '%, missingness ' + Math.round(missingRate * 100) + '%.',
      straight + ' of ' + eligible + ' eligible response' + (eligible === 1 ? '' : 's') + ' straight-lined the scale items.'
    ]);
  }

  // ── result builders ────────────────────────────────────────────────────────
  function domainOut(key, label, fraction, evidence) {
    var max = WEIGHTS[key];
    var pts = (fraction === null) ? 0 : round1(clamp(fraction, 0, 1) * max);
    return {
      key: key, label: label, max: max,
      points: pts,
      fraction: (fraction === null) ? null : round3(clamp(fraction, 0, 1)),
      withheld: fraction === null,
      evidence: evidence || []
    };
  }

  function withheldResult(dataset, fence, excludedItems, summary) {
    fence.withheld = true;
    var constructEvidence = (dataset.constructs || []).map(function (con) {
      return {
        id: con.id, name: con.name,
        scorableCount: con.scorableCount, itemCount: con.itemCount,
        enoughItems: con.enoughItems, n: null,
        alpha: null, alphaBand: null, scored: false,
        note: 'Reliability withheld: insufficient data to judge.'
      };
    });
    var domains = Object.keys(WEIGHTS).map(function (k) {
      return {
        key: k, label: labelFor(k), max: WEIGHTS[k],
        points: null, fraction: null, withheld: true,
        evidence: ['Withheld: insufficient data to judge.']
      };
    });
    return {
      ok: true,
      version: 'rssi-v1',
      projectId: dataset.projectId,
      projectName: dataset.projectName,
      fence: fence,
      score: null,
      max: 100,
      pct: null,
      band: 'Insufficient data to judge',
      bandKey: 'insufficient',
      verdict: 'Insufficient data to judge',
      domains: domains,
      constructs: constructEvidence,
      itemWarnings: [],
      excludedItems: excludedItems,
      fenceNotes: fence.notes,
      summary: summary
    };
  }

  function warn(item, con, type, severity, detail) {
    return {
      itemId: item.id, label: item.label,
      construct: con ? con.name : null,
      type: type, severity: severity, detail: detail
    };
  }

  function reasonForExclusion(fieldType) {
    if (fieldType === 'open_text') return 'Open-text answers are summarized elsewhere, not scored for reliability.';
    if (fieldType === 'categorical') return 'Unordered choice answers do not support an internal-consistency claim.';
    return 'This field type is not part of the reliability calculation.';
  }

  function labelFor(key) {
    return {
      internal_consistency: 'Internal Consistency',
      item_performance: 'Item Performance',
      response_quality: 'Response Quality',
      score_interpretability: 'Score Interpretability'
    }[key] || key;
  }

  function bandFor(total) {
    if (total >= 85) return { key: 'confident', label: 'Reliable enough to interpret with confidence' };
    if (total >= 70) return { key: 'minor', label: 'Reliable with minor cautions' };
    if (total >= 55) return { key: 'caution', label: 'Interpret with caution' };
    return { key: 'not_yet', label: 'Not yet reliable' };
  }

  function buildSummary(total, band, constructEvidence, itemWarnings, analyzableN) {
    var scored = constructEvidence.filter(function (c) { return c.scored; });
    var parts = [];
    parts.push('RSSI ' + total + ' of 100: ' + band.label + '.');
    parts.push('Based on ' + analyzableN + ' analyzable responses across ' + scored.length +
      ' scored construct' + (scored.length === 1 ? '' : 's') + '.');
    if (scored.length) {
      parts.push('Construct reliability: ' + scored.map(function (c) {
        return c.name + ' alpha ' + c.alpha + ' (' + c.alphaBand + ')';
      }).join('; ') + '.');
    }
    var errs = itemWarnings.filter(function (w) { return w.severity === 'err'; }).length;
    if (errs) parts.push(errs + ' item' + (errs === 1 ? '' : 's') + ' need attention before relying on the scores.');
    return parts.join(' ');
  }

  // ── export (UMD) ────────────────────────────────────────────────────────
  root.RSSIEngine = {
    score: score,
    WEIGHTS: WEIGHTS,
    cronbachAlpha: cronbachAlpha,
    bandFor: bandFor,
    CONSTRUCT_MIN_N: CONSTRUCT_MIN_N,
    MIN_ITEMS_PER_CONSTRUCT: MIN_ITEMS_PER_CONSTRUCT
  };
})(typeof window !== 'undefined' ? window : this);

if (typeof module !== 'undefined' && module.exports) {
  module.exports = (typeof window !== 'undefined' ? window : this).RSSIEngine;
}
