/* ════════════════════════════════════════════════════════════════════════
   ReliCheck Survey Checker Index (RSCI) — deterministic engine
   ────────────────────────────────────────────────────────────────────────
   The pre-deployment mirror of RSSI. RSSI scores an instrument AFTER data
   (observed); RSCI scores the SAME instrument BEFORE data (predicted), using
   deterministic linters only. No network, no API key.

   Input  : array of builder questions
            { id, text, type, opts:{points,minLabel,maxLabel,stars}, choices:[],
              construct? }      // construct is optional; see graceful degrade
            types: likert | open | multiple | rating | slider | matrix | ranking | priority
   Output : RSCIEngine.assess(questions) -> result object (see bottom)

   FOUR check groups, each 0–100, rolled up TWO-AND-TWO into two dimensions:

       VALIDITY    = mean(Question quality, Construct coverage)
       RELIABILITY = mean(Scale strength,   Survey flow)

   No weights. A check group feeds exactly one dimension, fixed by GROUP_DIM.

   THE ALPHA FENCE — internal consistency and items-per-construct are signals
   that sit *near* construct coverage by topic, but they predict RELIABILITY,
   not validity. They are emitted only into the `scaleStrength` group, so by
   construction they can never move the validity score. This is enforced
   structurally (every flag carries a group; validity is computed solely from
   the two validity groups' flags) and asserted at the end of assess().
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  /* ── severity → penalty points deducted from a 100 group score ── */
  var SEV = { critical: 34, major: 18, minor: 8, info: 0 };

  /* ── Pass-lines for PREDICTED validity and reliability ──────────────────
     RSCI scores the instrument against these fixed design thresholds. It does
     NOT predict, calibrate to, or grade itself against the observed RSSI
     score — the two read the same target from different information.

     The predicted pass-line deliberately sits ABOVE the observed pass-line as
     a safety margin: administration (weak sample, low motivation, careless
     responding) only erodes quality from the design ceiling, so a design must
     clear a higher bar to still land acceptable in the field.

     Starting values — tune later; do not bury new numbers in the logic. ── */
  var THRESHOLDS = {
    VALIDITY_READY:    80,  // predicted validity ≥ this  → design-ready
    RELIABILITY_READY: 80,  // predicted reliability ≥ this → design-ready
    FLAG_FLOOR:        65   // [FLAG_FLOOR, READY) → flag, do not block; below FLAG_FLOOR → not design-ready
  };

  /* Which dimension each check group rolls up into. The fence lives here:
     no reliability-only code may be assigned a validity group. */
  var GROUP_DIM = {
    questionQuality:   'validity',
    constructCoverage: 'validity',
    scaleStrength:     'reliability',
    surveyFlow:        'reliability'
  };

  /* Codes that are internal-consistency / items-per-construct signals. They
     MUST land in a reliability group. Used by the end-of-assess fence check. */
  var FENCE_CODES = { thin_scale: 1, no_reverse: 1, mixed_scales: 1 };

  var SCALE_TYPES = { likert: 1, rating: 1, slider: 1 };

  /* Average adult silent reading ≈ 240 wpm; add a fixed per-item answer cost. */
  var WORDS_PER_MIN = 240;
  var ANSWER_SECONDS = { likert: 6, rating: 6, slider: 8, multiple: 8, matrix: 14, ranking: 16, priority: 14, open: 40 };

  /* Demographic stems we can detect from text, for order checks. */
  var DEMOGRAPHIC_RE = /\b(age|gender|sex|race|ethnicity|income|salary|education|marital|religion|zip\s?code|nationality|household)\b/;

  function words(s) { return String(s || '').trim().split(/\s+/).filter(Boolean); }
  function lc(s) { return String(s || '').toLowerCase(); }
  function has(re, s) { return re.test(s); }

  /* ────────────────────────────────────────────────────────────────────
     Per-item wording linters → Question quality (validity)
     Each flag: { code, severity, label, fix, group:'questionQuality' }
     ──────────────────────────────────────────────────────────────────── */
  function lintWording(qtext, type) {
    var flags = [];
    var t = String(qtext || '').trim();
    var l = lc(t);
    var w = words(t);
    var isOpen = type === 'open';

    function push(code, severity, label, fix) {
      flags.push({ code: code, severity: severity, label: label, fix: fix, group: 'questionQuality' });
    }

    if (!t) {
      push('empty', 'critical', 'Item has no text.',
        'Write the question or statement respondents will read.');
      return flags;
    }

    if (has(/\b(don'?t|do not|wouldn'?t|would not)\s+you\s+(agree|think|feel)\b/, l) ||
        has(/\bisn'?t it\b|\bdon'?t you think\b|\bas you (?:know|are aware)\b/, l) ||
        has(/\b(obviously|clearly|surely|undoubtedly|of course)\b/, l)) {
      push('leading', 'major', 'Leading or loaded wording nudges the respondent.',
        'Rephrase neutrally — remove agreement-prompts and value-laden words.');
    }

    if (!isOpen) {
      if (has(/\b(always|never|all|none|every|everyone|everybody|nobody|no one)\b/, l)) {
        push('absolute', 'minor', 'Absolute term ("always", "never", "all"…) is hard to endorse.',
          'Soften to a frequency or degree the respondent can judge.');
      }
      if (has(/\b(?:and|or)\b/, l) && w.length >= 6 && hasTwoClauses(l)) {
        push('double_barreled', 'major', 'Possible double-barreled item — asks about two things at once.',
          'Split into two separate items so each measures one idea.');
      }
      if (has(/\b(often|sometimes|regularly|frequently|occasionally|usually|rarely|seldom)\b/, l)) {
        push('vague_quantifier', 'minor', 'Vague frequency word means different things to different people.',
          'Anchor it ("at least once a week") or move it into a labeled scale.');
      }
    }

    if (countNegations(l) >= 2) {
      push('double_negative', 'major', 'Double negative makes the item hard to parse.',
        'Rewrite positively so agreement maps to a clear meaning.');
    }

    if (!isOpen && w.length > 25) {
      push('too_long', 'minor', 'Long stem (' + w.length + ' words) increases dropout and misreads.',
        'Trim to a single, concrete idea — aim for under 20 words.');
    }

    if (w.some(function (x) { return x.replace(/[^a-z]/gi, '').length >= 14; })) {
      push('jargon', 'minor', 'Contains long/technical word(s) that may not be widely understood.',
        'Swap for plain language your respondents use.');
    }

    if (SCALE_TYPES[type] && w.length < 3) {
      push('too_short', 'major', 'Stem is too short to be a clear statement.',
        'State a complete idea respondents rate (e.g. "My workload is manageable").');
    }

    return flags;
  }

  function hasTwoClauses(l) {
    var parts = l.split(/\b(?:and|or)\b/);
    if (parts.length < 2) return false;
    var verbish = /\b(is|are|was|were|do|does|did|have|has|feel|think|like|use|find|get|make|want|need|enjoy|trust|support|provide|help|understand)\b|\b\w+(?:ed|ing|s)\b/;
    return verbish.test(parts[0]) && verbish.test(parts.slice(1).join(' '));
  }

  function countNegations(l) {
    var m = l.match(/\b(not|no|never|cannot|can'?t|won'?t|don'?t|doesn'?t|didn'?t|isn'?t|aren'?t|wasn'?t|weren'?t|none|neither|nor)\b/g);
    return m ? m.length : 0;
  }

  /* ────────────────────────────────────────────────────────────────────
     Per-item scale checks → Scale strength (reliability)
     ──────────────────────────────────────────────────────────────────── */
  function lintScale(q) {
    var flags = [];
    var opts = q.opts || {};
    if (!SCALE_TYPES[q.type]) return flags;

    function push(code, severity, label, fix) {
      flags.push({ code: code, severity: severity, label: label, fix: fix, group: 'scaleStrength' });
    }

    if (q.type === 'likert') {
      var pts = +opts.points || 0;
      if (pts && pts < 4) {
        push('few_points', 'minor', pts + '-point scale limits how finely respondents can answer.',
          'Use 5–7 points for more reliable variance.');
      }
      if (pts > 7) {
        push('many_points', 'minor', pts + ' points is more than most respondents distinguish.',
          'Reduce to 5–7 labeled points.');
      }
      if (!String(opts.minLabel || '').trim() || !String(opts.maxLabel || '').trim()) {
        push('unlabeled', 'minor', 'Scale endpoints are not both labeled.',
          'Label both ends (e.g. "Strongly disagree" / "Strongly agree").');
      }
    }
    return flags;
  }

  /* ────────────────────────────────────────────────────────────────────
     Survey-level checks. Each flag is tagged with the group it feeds, so
     the rollup partitions cleanly and the fence holds.
     ──────────────────────────────────────────────────────────────────── */
  function lintSurvey(questions) {
    var flags = [];
    var n = questions.length;

    function push(group, code, severity, label, fix) {
      flags.push({ code: code, severity: severity, label: label, fix: fix, group: group });
    }

    if (n === 0) {
      // No items: nothing to validate and nothing to deploy. Flag on flow
      // (a survey-level structural fault); the severity cap handles the rest.
      push('surveyFlow', 'no_items', 'critical', 'Survey has no items.',
        'Add at least a few questions to assess.');
      return flags;
    }

    var likert = questions.filter(function (q) { return q.type === 'likert'; });
    var openN  = questions.filter(function (q) { return q.type === 'open'; }).length;

    /* ── Reliability signals (the alpha fence): items-per-construct and
          internal-consistency proxies all feed scaleStrength only. ── */
    if (likert.length > 0 && likert.length < 3) {
      push('scaleStrength', 'thin_scale', 'major',
        'Only ' + likert.length + ' rating item(s) — too few to estimate internal consistency.',
        'Use at least 3–4 items per construct so reliability can be measured.');
    }
    var ptsSet = {};
    likert.forEach(function (q) { var p = +(q.opts && q.opts.points) || 0; if (p) ptsSet[p] = 1; });
    if (Object.keys(ptsSet).length > 1) {
      push('scaleStrength', 'mixed_scales', 'minor',
        'Rating items use different point counts (' + Object.keys(ptsSet).join(', ') + ').',
        'Standardize on one scale length so responses are comparable.');
    }
    if (likert.length >= 4) {
      var anyNeg = likert.some(function (q) { return countNegations(lc(q.text)) >= 1; });
      if (!anyNeg) {
        push('scaleStrength', 'no_reverse', 'minor',
          'No reverse-worded items — respondents may straight-line.',
          'Add one or two carefully reverse-keyed items to catch inattentive answering.');
      }
    }

    /* ── Construct coverage (validity), graceful degrade ── */
    var constructs = collectConstructs(questions);
    if (!constructs.defined) {
      // No construct field yet. Score what's inferable; surface guidance as
      // an info flag (zero penalty) rather than fabricating a coverage number.
      push('constructCoverage', 'no_constructs_defined', 'info',
        'No constructs are defined, so purpose-to-item coverage can only be estimated.',
        'Tag each item with the construct it measures to get a real coverage score.');
      if (n < 3) {
        push('constructCoverage', 'coverage_thin', 'minor',
          'With ' + n + ' item(s) the survey is unlikely to cover a construct adequately.',
          'Add items so each thing you intend to measure has enough coverage.');
      }
    } else {
      // Construct field present: real coverage checks.
      if (constructs.unmapped > 0) {
        push('constructCoverage', 'unmapped_items', 'major',
          constructs.unmapped + ' item(s) are not mapped to any construct.',
          'Assign every item to the construct it measures, or remove it.');
      }
      constructs.thin.forEach(function (name) {
        push('constructCoverage', 'construct_thin', 'minor',
          'Construct "' + name + '" has only 1 item — thin coverage.',
          'Add items so the construct is represented by several questions.');
      });
    }

    /* ── Survey flow (reliability) ── */
    if (n >= 4 && openN / n > 0.4) {
      push('surveyFlow', 'open_heavy', 'minor',
        Math.round(openN / n * 100) + '% of items are open-ended — high effort to answer.',
        'Keep open-ended items focused; convert some to closed scales.');
    }
    // Demographic placement: demographic items asked before substantive ones
    // prime responses and raise early dropout.
    var firstNonDemo = -1, firstDemo = -1;
    questions.forEach(function (q, i) {
      var isDemo = DEMOGRAPHIC_RE.test(lc(q.text));
      if (isDemo && firstDemo < 0) firstDemo = i;
      if (!isDemo && firstNonDemo < 0) firstNonDemo = i;
    });
    if (firstDemo >= 0 && firstNonDemo >= 0 && firstDemo < firstNonDemo) {
      push('surveyFlow', 'demographics_early', 'minor',
        'Demographic questions appear before the substantive items.',
        'Move demographics to the end so they do not prime or fatigue respondents up front.');
    }

    return flags;
  }

  /* Inspect optional per-item construct tags. Returns whether constructs are
     defined at all, count of unmapped items, and names with only one item. */
  function collectConstructs(questions) {
    var defined = questions.some(function (q) {
      return q.construct != null && String(q.construct).trim() !== '';
    });
    if (!defined) return { defined: false, unmapped: 0, thin: [] };

    var counts = {}, unmapped = 0;
    questions.forEach(function (q) {
      var c = q.construct != null ? String(q.construct).trim() : '';
      if (!c) { unmapped++; return; }
      counts[c] = (counts[c] || 0) + 1;
    });
    var thin = Object.keys(counts).filter(function (k) { return counts[k] < 2; });
    return { defined: true, unmapped: unmapped, thin: thin };
  }

  /* ── estimated completion time (minutes) ── */
  function estMinutes(questions) {
    var sec = 0;
    questions.forEach(function (q) {
      var read = words(q.text).length / WORDS_PER_MIN * 60;
      sec += read + (ANSWER_SECONDS[q.type] || 8);
    });
    return Math.max(0.1, sec / 60);
  }

  /* ── one flag list → a single 0–100 score ── */
  function scoreOne(list) {
    var penalty = 0;
    list.forEach(function (f) { penalty += SEV[f.severity] || 0; });
    return Math.max(0, Math.min(100, Math.round(100 - penalty)));
  }

  /* ── mean of per-item scores (fairer than summing all penalties) ── */
  function meanScore(flagLists) {
    if (!flagLists.length) return 100;
    var sum = 0;
    flagLists.forEach(function (list) { sum += scoreOne(list); });
    return Math.round(sum / flagLists.length);
  }

  /* ── combine a per-item base with survey-level penalties for one group ── */
  function combineScore(base, surveyFlags) {
    var penalty = 0;
    surveyFlags.forEach(function (f) { penalty += SEV[f.severity] || 0; });
    return Math.max(0, Math.min(100, Math.round(base - penalty)));
  }

  /* Classify a dimension score against its pass-line. `readyAt` is the
     dimension's design-ready threshold (validity and reliability are tuned
     independently, even if they currently share a value). */
  function classify(score, readyAt) {
    if (score >= readyAt)              return { key: 'ready',     label: 'Design-ready',           pass: true };
    if (score >= THRESHOLDS.FLAG_FLOOR) return { key: 'flag',      label: 'Review before deploying', pass: false };
    return { key: 'not_ready', label: 'Not design-ready', pass: false };
  }

  /* Averaging keeps a dimension interpretable but can mask one serious defect,
     so cap a dimension by the worst severity present in its own groups. */
  function capFor(counts) {
    if (counts.critical > 0)    return 50;
    if (counts.major >= 3)      return 68;
    if (counts.major >= 1)      return 82;
    return 100;
  }

  /* ════════════════════════════════════════════════════════════════════
     Public: assess(questions) -> result
     ════════════════════════════════════════════════════════════════════ */
  function assess(questions) {
    questions = Array.isArray(questions) ? questions : [];

    var items = questions.map(function (q, i) {
      var flags = lintWording(q.text, q.type).concat(lintScale(q));
      return {
        id: q.id != null ? q.id : i + 1,
        index: i,
        text: String(q.text || ''),
        type: q.type || 'likert',
        flags: flags,
        score: scoreOne(flags)
      };
    });

    var surveyFlags = lintSurvey(questions);

    // ── Partition every flag by its group. ──
    var byGroup = { questionQuality: [], constructCoverage: [], scaleStrength: [], surveyFlow: [] };
    surveyFlags.forEach(function (f) { (byGroup[f.group] || byGroup.surveyFlow).push(f); });

    // Per-item lists per group (only the two item-level groups have these).
    var qualityItemLists = items.map(function (it) {
      return it.flags.filter(function (f) { return f.group === 'questionQuality'; });
    });
    var scaleItemLists = items
      .filter(function (it) { return SCALE_TYPES[it.type]; })
      .map(function (it) { return it.flags.filter(function (f) { return f.group === 'scaleStrength'; }); });

    // Flow penalizes long surveys (fatigue) deterministically from est. time.
    var minutes = estMinutes(questions);
    if (minutes > 12) {
      byGroup.surveyFlow.push({ code: 'too_long_survey', severity: 'major', group: 'surveyFlow',
        label: 'Estimated ' + minutes.toFixed(0) + ' min to complete — fatigue risk.',
        fix: 'Trim or split the survey; aim for under 10 minutes.' });
    } else if (minutes > 8) {
      byGroup.surveyFlow.push({ code: 'longish_survey', severity: 'minor', group: 'surveyFlow',
        label: 'Estimated ' + minutes.toFixed(0) + ' min to complete.',
        fix: 'Consider trimming lower-priority items to reduce dropout.' });
    }

    // ── Four group scores (no weights). ──
    var questionQuality   = meanScore(qualityItemLists);
    var constructCoverage = scoreOne(byGroup.constructCoverage);
    var scaleBase         = scaleItemLists.length ? meanScore(scaleItemLists) : 100;
    var scaleStrength     = combineScore(scaleBase, byGroup.scaleStrength);
    var surveyFlow        = scoreOne(byGroup.surveyFlow);

    // ── Two-and-two rollup. Validity is computed SOLELY from the two
    //    validity groups — the fence is structural, not cosmetic. ──
    var validityRaw    = Math.round((questionQuality + constructCoverage) / 2);
    var reliabilityRaw = Math.round((scaleStrength + surveyFlow) / 2);

    // Per-dimension severity caps from each dimension's own flags.
    var vCounts = countSev(qualityItemLists, byGroup.constructCoverage);
    var rCounts = countSev(scaleItemLists, byGroup.scaleStrength.concat(byGroup.surveyFlow));
    var validity    = Math.min(validityRaw, capFor(vCounts));
    var reliability = Math.min(reliabilityRaw, capFor(rCounts));

    // An instrument with no items cannot be scored as design-ready on either
    // dimension — there is nothing yet to be valid or reliable. Force both
    // below the pass-line rather than letting an empty group default to 100.
    if (questions.length === 0) {
      validity = 0;
      reliability = 0;
    }

    // Overall flag tally for the headline (excludes zero-penalty info flags).
    var allCounts = { critical: 0, major: 0, minor: 0 };
    [vCounts, rCounts].forEach(function (c) {
      allCounts.critical += c.critical; allCounts.major += c.major; allCounts.minor += c.minor;
    });

    var result = {
      validity:    { score: validity,    tier: classify(validity, THRESHOLDS.VALIDITY_READY) },
      reliability: { score: reliability, tier: classify(reliability, THRESHOLDS.RELIABILITY_READY) },
      groups: {
        questionQuality:   { score: questionQuality,   dimension: 'validity',    label: 'Question quality' },
        constructCoverage: { score: constructCoverage, dimension: 'validity',    label: 'Construct coverage' },
        scaleStrength:     { score: scaleStrength,     dimension: 'reliability', label: 'Scale strength' },
        surveyFlow:        { score: surveyFlow,        dimension: 'reliability', label: 'Survey flow' }
      },
      items: items,
      surveyFlags: byGroup.constructCoverage
        .concat(byGroup.scaleStrength, byGroup.surveyFlow),
      reliabilityDetail: {
        itemsPerConstruct: questions.filter(function (q) { return SCALE_TYPES[q.type]; }).length,
        fenced: true
      },
      summary: {
        itemCount: questions.length,
        likertCount: questions.filter(function (q) { return q.type === 'likert'; }).length,
        openCount: questions.filter(function (q) { return q.type === 'open'; }).length,
        estMinutes: minutes,
        flagCounts: allCounts
      }
    };

    assertFence(result);
    return result;
  }

  function countSev(itemLists, surveyFlags) {
    var c = { critical: 0, major: 0, minor: 0 };
    itemLists.forEach(function (list) { list.forEach(function (f) { if (c[f.severity] != null) c[f.severity]++; }); });
    surveyFlags.forEach(function (f) { if (c[f.severity] != null) c[f.severity]++; });
    return c;
  }

  /* The fence, asserted: no internal-consistency / items-per-construct signal
     may have been routed to a validity group. If this ever throws, the rollup
     partitioning regressed and validity could be inflated by reliability. */
  function assertFence(result) {
    var leaked = result.surveyFlags.filter(function (f) {
      return FENCE_CODES[f.code] && GROUP_DIM[f.group] !== 'reliability';
    });
    if (leaked.length) {
      throw new Error('RSCI alpha-fence violation: ' +
        leaked.map(function (f) { return f.code + '→' + f.group; }).join(', '));
    }
  }

  root.RSCIEngine = { assess: assess, classify: classify, THRESHOLDS: THRESHOLDS, GROUP_DIM: GROUP_DIM };
})(typeof window !== 'undefined' ? window : this);
