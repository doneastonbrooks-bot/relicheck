/* ════════════════════════════════════════════════════════════════════════
   SIRI — Launch Check engine (deterministic, calibrated 100-point readiness)
   ────────────────────────────────────────────────────────────────────────
   SIRI ("Survey Instrument Readiness Index") is the user-facing 100-point
   PRE-LAUNCH gate the Survey Development System shows once a survey is built:
   the "is this ready to send out" companion to the SDSI Build Check (the
   50-point "improve as you build" score). SDSI helps improve the survey while
   you build it; SIRI checks the completed survey before launch.

   This engine is SIRI v1: it scores the ACTUAL BUILT SURVEY deterministically
   from project data, the same no-AI way BuildCheck does. It is SEPARATE from
   the judgment-driven domain aggregators (validity/reliability/administration-
   readiness.js), which score saved per-lens flag reviews and DEFAULT MISSING
   REVIEWS TO FULL POINTS — that would inflate a Launch Check for a survey that
   has had no reviews. Here, MISSING LAUNCH-READINESS EVIDENCE LOSES POINTS and
   is reported as an advisory gap; it never earns silent full credit.

   THREE DOMAINS (point budgets sum to 100) — same weights as the real engine:

       VALIDITY (50)
         Construct Definition          8
         Purpose Alignment             7
         Dimension / Domain Coverage   8
         Item-to-Construct Alignment   7
         Response-Option Validity      4
         Dignity / Framing             8   (capped baseline — see below)
         Access                        8   (capped baseline — see below)
       RELIABILITY (35)
         Scale Structure Readiness     8
         Item Clarity / Wording        8
         Response Scale Consistency    7
         Redundancy Balance            6
         Administration Consistency    6
       ADMINISTRATION (15)
         Respondent Instructions       4
         Consent / Privacy             4
         Fielding Plan & Timing        3
         Sensitive-Topic & Safety      2
         Completion Burden & Logistics 2

   SDSI BOUNDARY: SIRI is NOT the SDSI score. It may reference SDSI STATUS as
   one readiness input (a `critical` Build Check flag holds the launch gate),
   but it never reuses the SDSI number and never mutates SDSI.

   CAPPED BASELINES: Dignity/Framing and Access cannot be fully verified before
   the dedicated review workflow ships, so they are capped below full credit and
   only adjusted downward by a deterministic wording / reading-load scan. They
   rise to a full rubric in a later sub-phase.

   ORTHOGONAL LAUNCH GATE: a blocker on any lens flips the verdict to
   "Blocked for review" WITHOUT changing the number. Scores are computed purely
   from evidence; blockers only set `launchReady:false`. Blockers v1: zero
   answerable items, no construct defined at all, a `critical` SDSI flag, and a
   detected sensitive topic with no decline path. (Missing consent is advisory
   in v1 — it loses points but does not block, because the builder offers no
   consent field to resolve it yet.)

   PRE-LAUNCH ONLY: SIRI evaluates whether the instrument is ready to collect
   interpretable data. It does not evaluate response data — that is RSSI, after
   data collection.
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  // Item-type vocabulary (the QTYPES keys the builder actually stores).
  var STRUCTURAL_TYPES = {
    'Section Text': true, 'Instructions': true, 'Consent': true,
    'Page Break': true, 'Thank-you Message': true
  };
  var SCALED_TYPES = { 'Likert Scale': true, 'Rating Scale': true, 'NPS': true, 'Slider': true };
  var CHOICE_TYPES = {
    'Multiple Choice': true, 'Checkboxes': true, 'Multiple Answers / Checkboxes': true,
    'Dropdown': true, 'Ranking': true, 'Yes/No': true, 'True/False': true, 'Demographic': true
  };

  var DEMERIT = { critical: 1.0, major: 0.85, moderate: 0.6, minor: 0.3 };

  function clamp(s) { return Math.min(100, Math.max(0, s)); }
  function round1(n) { return Math.round(n * 10) / 10; }
  function lc(s) { return String(s == null ? '' : s).toLowerCase(); }
  function words(s) { return lc(s).split(/[^a-z0-9']+/).filter(Boolean); }
  function worst(arr) { var d = 0; arr.forEach(function (v) { if (v > d) d = v; }); return d; }

  var LEADING_WORDS = ['obviously', 'clearly', 'surely', 'everyone knows', "don't you agree", "isn't it", "wouldn't you", 'as you know'];
  var ABSOLUTE_WORDS = ['always', 'never', 'every ', 'all of', 'none of', 'completely', 'totally', 'rarely'];
  var LOADED_WORDS = ['failure', 'foolish', 'irresponsible', 'lazy', 'stupid', 'crazy', 'suffer', 'unfortunately', 'at-risk', 'behind', 'neglect'];
  // Topics whose disclosure can expose or harm a respondent if asked without a
  // clear purpose and a decline path. Word-boundary regex (not loose substring,
  // so "orientation" in "program orientation" does not trip "sexual orientation").
  var SENSITIVE_RE = new RegExp('\\b(' + [
    'race', 'races', 'racial', 'ethnic', 'ethnicity', 'religion', 'religious',
    'income', 'salary', 'salaries', 'wage', 'wages', 'disability', 'disabilities',
    'health', 'medical', 'diagnosis', 'mental health', 'sexual orientation',
    'sexuality', 'gender identity', 'immigration', 'immigrant', 'immigrants',
    'undocumented', 'legal status', 'citizenship', 'criminal', 'arrest', 'arrested',
    'abuse', 'social security', 'ssn', 'pregnant', 'pregnancy'
  ].join('|') + ')\\b', 'i');
  var DECLINE_RE = /prefer not|decline to|rather not|no answer|not to say/i;

  // Generic 0–100 band shared by lenses and domains.
  function band(pct) {
    if (pct >= 90) return { key: 'strong', label: 'Strong' };
    if (pct >= 80) return { key: 'good', label: 'Good' };
    if (pct >= 70) return { key: 'moderate', label: 'Moderate' };
    if (pct >= 60) return { key: 'significant', label: 'Significant' };
    return { key: 'high', label: 'High risk' };
  }

  // ── Shared context ────────────────────────────────────────────────────────
  function buildContext(project, opts) {
    project = project || {};
    opts = opts || {};
    var items = project.items || [];
    var ans = items.filter(function (it) { return !STRUCTURAL_TYPES[it.type]; });
    var constructs = (project.constructs || []).filter(function (c) { return String(c.name || '').trim() !== ''; });
    var scaled = ans.filter(function (it) { return SCALED_TYPES[it.type]; });
    var scaleItems = ans.filter(function (it) { return SCALED_TYPES[it.type] || CHOICE_TYPES[it.type]; });

    var byConstruct = {};
    constructs.forEach(function (c) { byConstruct[c.name] = { all: 0, scaled: 0 }; });
    var unmapped = 0;
    ans.forEach(function (it) {
      var cn = String(it.construct || '').trim();
      if (cn && byConstruct[cn]) {
        byConstruct[cn].all += 1;
        if (SCALED_TYPES[it.type]) byConstruct[cn].scaled += 1;
      } else { unmapped += 1; }
    });

    // Mixed Likert lengths (a within-instrument consistency signal).
    var likertPoints = {};
    ans.forEach(function (it) {
      if (it.type === 'Likert Scale') { var p = parseInt((it.settings || {}).points, 10); if (p) likertPoints[p] = 1; }
    });

    // Structural evidence the builder can actually carry today.
    var hasConsent = items.some(function (it) { return it.type === 'Consent'; });
    var hasInstructions = items.some(function (it) {
      return it.type === 'Section Text' || it.type === 'Instructions' || it.type === 'Consent';
    });

    // Phase 2D — explicit launch-readiness fields the user documents (settings.launchReadiness).
    // Each lens prefers these; the item-based inference above stays as a fallback.
    var lr = project.launchReadiness || {};
    var lrC = lr.consent || {}, lrI = lr.instructions || {}, lrA = lr.access || {},
        lrF = lr.fielding || {}, lrD = lr.dignity || {}, lrS = lr.sensitive || {};
    var consentText = String(lrC.statement || '').trim();
    var consentDocumented = lrC.documented === true && consentText.length >= 20;
    var consentPartial = !consentDocumented && (lrC.documented === true || consentText.length >= 20);
    var instrText = String(lrI.text || '').trim();
    var instrProvided = lrI.provided === true && instrText.length >= 20;
    var accessReviewed = lrA.reviewed === true;
    var fWindow = String(lrF.window || '').trim();
    var fChannel = String(lrF.channel || '').trim();
    var fMin = parseInt(lrF.estMinutes, 10); fMin = (fMin > 0) ? fMin : 0;
    var fieldingParts = (fWindow ? 1 : 0) + (fChannel ? 1 : 0) + (fMin ? 1 : 0);
    var dignityReviewed = lrD.reviewed === true;
    var sensitiveDeclared = lrS.hasSensitive === true;
    var declineProvided = lrS.declineProvided === true;

    // Sensitive-topic detection + whether any decline path exists.
    var sensitiveItems = ans.filter(function (it) {
      return SENSITIVE_RE.test(String(it.prompt || ''));
    });
    var hasPreferNot = ans.some(function (it) {
      return (it.options || []).some(function (o) { return DECLINE_RE.test(String(o)); });
    });
    var declinePath = hasConsent || hasPreferNot || declineProvided;

    var sdsi = opts.sdsiResult || null;
    var sdsiCritical = !!(sdsi && (sdsi.flags || []).some(function (f) { return f.severity === 'critical'; }));

    return {
      project: project, items: items, ans: ans, N: ans.length,
      constructs: constructs, numC: constructs.length, hasConstructs: constructs.length > 0,
      scaled: scaled, scaleItems: scaleItems, byConstruct: byConstruct, unmapped: unmapped,
      mixedLikert: Object.keys(likertPoints).length > 1,
      sections: project.sections || [], purpose: String(project.purpose || '').trim(),
      population: String(project.population || '').trim(), mode: String(project.mode || '').trim(),
      hasConsent: hasConsent, hasInstructions: hasInstructions,
      sensitiveItems: sensitiveItems, declinePath: declinePath,
      // launch-readiness predicates
      lrAccess: lrA, lrFielding: lrF,
      consentDocumented: consentDocumented, consentPartial: consentPartial,
      instrProvided: instrProvided, accessReviewed: accessReviewed,
      fieldingParts: fieldingParts, dignityReviewed: dignityReviewed,
      sensitiveDeclared: sensitiveDeclared, declineProvided: declineProvided,
      sdsi: sdsi, sdsiCritical: sdsiCritical
    };
  }

  function note(sev, lens, msg) { return { sev: sev, lens: lens, msg: msg }; }

  /* ════════════════════════ VALIDITY (50) ════════════════════════ */

  function lensConstructDefinition(ctx, out) {
    if (ctx.N === 0) return 0;
    if (!ctx.hasConstructs) {
      out.notes.push(note('warn', 'Construct Definition', 'No construct is defined, so it is unclear what the survey measures. Define at least one construct.'));
      return 0;
    }
    var s = 100, undef = 0, thin = 0;
    ctx.constructs.forEach(function (c) {
      var def = String(c.definition || '').trim();
      if (def === '') undef += 1; else if (def.length < 20) thin += 1;
    });
    s -= Math.round(undef * (60 / ctx.numC));
    s -= Math.round(thin * (25 / ctx.numC));
    if (undef > 0) out.notes.push(note('warn', 'Construct Definition', undef + ' of ' + ctx.numC + ' construct' + (ctx.numC === 1 ? '' : 's') + ' have no definition. Define each in one plain sentence.'));
    return clamp(s);
  }

  function lensPurposeAlignment(ctx, out) {
    if (ctx.purpose === '') {
      out.notes.push(note('warn', 'Purpose Alignment', 'No purpose is recorded, so items cannot be checked against an intended use.'));
      return 0;
    }
    var s = 100;
    if (ctx.purpose.length < 25) s -= 35;
    if (!ctx.hasConstructs) s -= 25;
    if (ctx.N === 0) s -= 20;
    if (ctx.population === '') { s -= 15; out.notes.push(note('warn', 'Purpose Alignment', 'No target audience is recorded. Naming who answers helps fit wording and reading level.')); }
    return clamp(s);
  }

  function lensDimensionCoverage(ctx, out) {
    if (ctx.N === 0) return 0;
    if (!ctx.hasConstructs) return 30;
    var s = 100, thin = 0;
    ctx.constructs.forEach(function (c) {
      var n = ctx.byConstruct[c.name].all;
      if (n === 0) thin += (40 / ctx.numC);
      else if (n === 1) thin += (25 / ctx.numC);
      else if (n === 2) thin += (12 / ctx.numC);
    });
    s -= Math.round(thin);
    if (ctx.unmapped > 0) s -= Math.min(40, Math.round(40 * ctx.unmapped / ctx.N));
    return clamp(s);
  }

  function lensItemConstructAlignment(ctx, out) {
    if (ctx.N === 0) return 0;
    if (!ctx.hasConstructs) return 25;
    var s = 100;
    if (ctx.unmapped > 0) {
      s -= Math.round(70 * ctx.unmapped / ctx.N);
      out.notes.push(note('warn', 'Item-to-Construct Alignment', ctx.unmapped + ' of ' + ctx.N + ' items are not mapped to a construct. Map each item or remove it.'));
    }
    return clamp(s);
  }

  function lensResponseOptionValidity(ctx, out) {
    if (ctx.N === 0) return 0;
    if (ctx.scaleItems.length === 0) return 100; // pure open/numeric: nothing to assess
    var demerit = 0;
    ctx.scaleItems.forEach(function (it) {
      var f = [];
      if (CHOICE_TYPES[it.type] && it.type !== 'Yes/No' && it.type !== 'True/False' && (it.options || []).length < 2) f.push(DEMERIT.moderate);
      if ((it.type === 'Rating Scale' || it.type === 'Slider') && !(it.settings || {}).max) f.push(DEMERIT.minor);
      demerit += worst(f);
    });
    var s = 100 * (1 - demerit / ctx.scaleItems.length);
    if (ctx.mixedLikert) s -= 12;
    return clamp(s);
  }

  // Wording-scan demerit shared by Dignity (loaded/leading) and the capped
  // baselines. Returns demerit-per-item in [0..1].
  function wordingDemerit(ctx) {
    if (ctx.N === 0) return 0;
    var sum = 0;
    ctx.ans.forEach(function (it) {
      var low = lc(it.prompt), f = [];
      if (LOADED_WORDS.some(function (w) { return low.indexOf(w) !== -1; })) f.push(DEMERIT.moderate);
      if (LEADING_WORDS.some(function (w) { return low.indexOf(w) !== -1; })) f.push(DEMERIT.moderate);
      if (ABSOLUTE_WORDS.some(function (w) { return low.indexOf(w) !== -1; })) f.push(DEMERIT.minor);
      sum += worst(f);
    });
    return sum / ctx.N;
  }

  function lensDignityFraming(ctx, out) {
    var demerit = Math.round(45 * wordingDemerit(ctx));
    if (ctx.N === 0) return ctx.dignityReviewed ? 100 : 50;
    // Attesting a dignity/framing review lifts the cap; otherwise it stays capped.
    var s = (ctx.dignityReviewed ? 100 : 60) - demerit;
    if (!ctx.dignityReviewed) out.notes.push(note('info', 'Dignity / Framing', 'A dignity and framing review has not been recorded, so this lens is capped. Confirm it in Launch Readiness to lift the cap.'));
    if (demerit > 0) out.notes.push(note('warn', 'Dignity / Framing', 'Some items use loaded, leading, or absolute wording. Rephrase neutrally and respectfully.'));
    return clamp(s);
  }

  function lensAccess(ctx, out) {
    var penalty = 0, longCount = 0;
    ctx.ans.forEach(function (it) {
      if (String(it.prompt || '').length > 200) { penalty += 1; longCount += 1; }
      if (it.type === 'Slider') penalty += 0.5; // pointer/fine-motor dependence
    });
    var penaltyPct = ctx.N > 0 ? Math.round(25 * (penalty / ctx.N)) : 0;
    var s;
    if (ctx.accessReviewed) {
      // Reviewed: lift the cap. Baseline 75, plus credit for documented supports.
      var a = ctx.lrAccess || {}, credit = 0;
      var langs = String(a.languages || '');
      if (langs.indexOf(',') !== -1 || langs.trim().split(/\s+/).length >= 2) credit += 10;
      if (a.plainLanguageAlt === true) credit += 10;
      if (String(a.accommodationContact || '').trim() !== '') credit += 10;
      s = Math.min(100, 75 + credit) - penaltyPct;
    } else {
      // Not reviewed: stays capped at the pre-2D baseline.
      s = (ctx.N === 0 ? 50 : 55) - penaltyPct;
      out.notes.push(note('info', 'Access', 'An accessibility review has not been recorded, so this lens is capped. Document languages, a plain-language option, and an accommodation contact in Launch Readiness to lift the cap.'));
    }
    if (longCount > 0) out.notes.push(note('warn', 'Access', longCount + ' item' + (longCount === 1 ? '' : 's') + ' read long, which raises reading load. Tighten to one clear sentence.'));
    return clamp(s);
  }

  /* ════════════════════════ RELIABILITY (35) ════════════════════════ */

  function lensScaleStructure(ctx, out) {
    if (ctx.N === 0) return 0;
    if (ctx.scaled.length === 0) return 100; // format-neutral
    var s = 100;
    if (!ctx.hasConstructs) {
      out.notes.push(note('warn', 'Scale Structure Readiness', 'There are scaled items but no construct grouping them, so a reliable scale cannot be formed.'));
      return clamp(s - 50);
    }
    var thin = 0, anyMulti = false;
    ctx.constructs.forEach(function (c) {
      var n = ctx.byConstruct[c.name].scaled;
      if (n >= 3) anyMulti = true;
      else if (n === 2) thin += 10;
      else if (n === 1) thin += 18;
    });
    s -= Math.min(thin, 45);
    if (!anyMulti) s -= 20;
    return clamp(s);
  }

  function lensItemClarity(ctx, out) {
    if (ctx.N === 0) return 0;
    var demerit = 0, issues = 0;
    ctx.ans.forEach(function (it) {
      var prompt = String(it.prompt || '').trim(), f = [];
      if (prompt === '') { f.push(DEMERIT.major); }
      else {
        var low = lc(prompt), w = words(prompt);
        if (/\b(and|or)\b/.test(low) && !CHOICE_TYPES[it.type] && low.split(/\b(?:and|or)\b/).length === 2 && prompt.length > 30) f.push(DEMERIT.moderate);
        if (prompt.length > 200) f.push(DEMERIT.minor);
        if (w.length > 0 && w.length < 3) f.push(DEMERIT.minor);
      }
      var d = worst(f); if (d > 0) issues += 1;
      demerit += d;
    });
    if (issues > 0) out.notes.push(note('warn', 'Item Clarity / Wording', issues + ' item' + (issues === 1 ? '' : 's') + ' may be unclear (empty, double-barreled, very long, or fragmentary).'));
    return clamp(100 * (1 - demerit / ctx.N));
  }

  function lensResponseScaleConsistency(ctx, out) {
    if (ctx.N === 0) return 0;
    if (ctx.scaleItems.length === 0) return 100;
    var s = 100, miss = 0;
    ctx.scaleItems.forEach(function (it) {
      if ((it.type === 'Rating Scale' || it.type === 'Slider')) {
        var st = it.settings || {};
        if (!st.max || st.min == null) miss += 1;
      }
    });
    if (ctx.mixedLikert) { s -= 12; out.notes.push(note('warn', 'Response Scale Consistency', 'Likert questions use different scale lengths. Use one consistent length so responses compare cleanly.')); }
    if (miss > 0) s -= Math.min(30, Math.round(30 * miss / ctx.scaleItems.length));
    return clamp(s);
  }

  function lensRedundancyBalance(ctx, out) {
    if (ctx.N === 0) return 0;
    if (!ctx.hasConstructs) return 50;
    var s = 100, single = 0, heavy = false;
    ctx.constructs.forEach(function (c) {
      var n = ctx.byConstruct[c.name].all;
      if (n === 1) single += 1;
      if (n > 8) heavy = true;
    });
    s -= Math.min(45, single * 15);
    if (heavy) s -= 10;
    return clamp(s);
  }

  function lensAdminConsistency(ctx, out) {
    if (ctx.N === 0) return 0;
    if (ctx.scaled.length === 0) return 100;
    var s = 100;
    if (ctx.mixedLikert) s -= 20;
    if (ctx.mode === '') { s -= 15; out.notes.push(note('info', 'Administration Consistency for Reliability', 'No response mode is recorded, so consistent administration cannot be confirmed.')); }
    return clamp(s);
  }

  /* ════════════════════════ ADMINISTRATION (15) ════════════════════════ */

  function lensRespondentInstructions(ctx, out) {
    if (ctx.instrProvided || ctx.hasInstructions) return 100;
    out.notes.push(note('warn', 'Respondent Instructions & Guidance', 'No respondent instructions were found. Add them in Launch Readiness so respondents know what to do.'));
    if (ctx.purpose !== '' && ctx.population !== '') return 40;
    if (ctx.purpose !== '' || ctx.population !== '') return 20;
    return 0;
  }

  function lensConsentPrivacy(ctx, out) {
    if (ctx.consentDocumented || ctx.hasConsent) return 100;
    if (ctx.consentPartial) {
      out.notes.push(note('warn', 'Consent, Privacy & Use Transparency', 'Consent/privacy is started but not fully documented. Complete the statement and attestation in Launch Readiness.'));
      return 50;
    }
    out.notes.push(note('warn', 'Consent, Privacy & Use Transparency', 'Consent/privacy documentation is required before launch readiness can be granted. Complete the Consent & Privacy field in Launch Readiness.'));
    return 0;
  }

  function lensFieldingPlan(ctx, out) {
    if (ctx.fieldingParts === 3) return 100;
    if (ctx.fieldingParts === 2) return 70;
    if (ctx.fieldingParts === 1) return 50;
    out.notes.push(note('info', 'Fielding Plan & Timing', 'Fielding window, channel, and estimated time are not documented. Add them in Launch Readiness.'));
    if (ctx.mode !== '') return 40;
    return 0;
  }

  function lensSensitiveSafety(ctx, out) {
    var anySensitive = ctx.sensitiveItems.length > 0 || ctx.sensitiveDeclared;
    if (!anySensitive) return 100; // nothing to gate
    if (ctx.declinePath) return 100;
    out.notes.push(note('warn', 'Sensitive-Topic & Safety Readiness', 'This survey touches sensitive topics without a clear decline path. Provide a decline option (for example "Prefer not to answer") or confirm one in Launch Readiness.'));
    return 0;
  }

  function lensCompletionBurden(ctx, out) {
    var n = ctx.N;
    if (n === 0) return 0;
    var s;
    if (n >= 6 && n <= 30) s = 100;
    else if (n >= 4) s = 80;
    else if (n > 30 && n <= 40) s = 70;
    else if (n > 40) s = 40;
    else s = 50; // n < 4
    if (n > 15 && ctx.sections.length <= 1) s -= 10;
    return clamp(s);
  }

  // Lens registry per domain: [key, name, weight, scorer].
  var VALIDITY = [
    ['construct_definition', 'Construct Definition', 8, lensConstructDefinition],
    ['purpose_alignment', 'Purpose Alignment', 7, lensPurposeAlignment],
    ['dimension_coverage', 'Dimension / Domain Coverage', 8, lensDimensionCoverage],
    ['item_construct_alignment', 'Item-to-Construct Alignment', 7, lensItemConstructAlignment],
    ['response_option_validity', 'Response-Option Validity', 4, lensResponseOptionValidity],
    ['dignity_framing', 'Dignity / Framing', 8, lensDignityFraming],
    ['access', 'Access', 8, lensAccess]
  ];
  var RELIABILITY = [
    ['scale_structure_readiness', 'Scale Structure Readiness', 8, lensScaleStructure],
    ['item_clarity', 'Item Clarity / Wording Consistency', 8, lensItemClarity],
    ['response_scale_consistency', 'Response Scale Consistency', 7, lensResponseScaleConsistency],
    ['redundancy_balance', 'Redundancy Balance', 6, lensRedundancyBalance],
    ['administration_consistency', 'Administration Consistency for Reliability', 6, lensAdminConsistency]
  ];
  var ADMINISTRATION = [
    ['respondent_instructions', 'Respondent Instructions & Guidance', 4, lensRespondentInstructions],
    ['consent_privacy', 'Consent, Privacy & Use Transparency', 4, lensConsentPrivacy],
    ['fielding_plan', 'Fielding Plan & Timing', 3, lensFieldingPlan],
    ['sensitive_safety', 'Sensitive-Topic & Safety Readiness', 2, lensSensitiveSafety],
    ['completion_burden', 'Completion Burden & Launch Logistics', 2, lensCompletionBurden]
  ];

  // Build one domain summary in the shape SiriReadiness.aggregate consumes.
  function buildDomain(key, name, subtitle, defs, ctx, out, gate) {
    var lenses = defs.map(function (d) {
      var score = clamp(d[3](ctx, out));
      var launchReady = gate(d[0]) !== false;
      return {
        key: d[0], name: d[1], weight: d[2], score: Math.round(score),
        points: round1((score / 100) * d[2]), band: band(score), launchReady: launchReady
      };
    });
    var totalPoints = round1(lenses.reduce(function (a, l) { return a + l.points; }, 0));
    var maxPoints = lenses.reduce(function (a, l) { return a + l.weight; }, 0);
    var pct = maxPoints > 0 ? Math.round((totalPoints / maxPoints) * 1000) / 10 : 0;
    var blocked = lenses.some(function (l) { return !l.launchReady; });
    var evidence = {
      acceptedFlags: lenses.filter(function (l) { return l.score < 100; }).length,
      dismissedFlags: 0,
      blockers: lenses.filter(function (l) { return !l.launchReady; }).length,
      warnings: lenses.filter(function (l) { return l.launchReady && l.score < 80; }).length
    };
    return {
      key: key, name: name, subtitle: subtitle, max: maxPoints,
      totalPoints: totalPoints, maxPoints: maxPoints, pct: pct,
      band: band(pct), blocked: blocked, verdict: blocked ? 'Blocked for review' : band(pct).label,
      lenses: lenses, evidence: evidence
    };
  }

  // Local fallback summation — identical math to SiriReadiness.aggregate so the
  // engine never throws if siri-readiness.js is not loaded.
  function localAggregate(domains) {
    var totalPoints = round1(domains.reduce(function (a, d) { return a + (d.totalPoints || 0); }, 0));
    var maxPoints = domains.reduce(function (a, d) { return a + (d.maxPoints || 0); }, 0);
    var pct = maxPoints > 0 ? Math.round((totalPoints / maxPoints) * 1000) / 10 : 0;
    var blocked = domains.some(function (d) { return d.blocked; });
    var b = band(pct);
    var evidence = domains.reduce(function (acc, d) {
      var e = d.evidence || {};
      acc.acceptedFlags += e.acceptedFlags || 0; acc.dismissedFlags += e.dismissedFlags || 0;
      acc.blockers += e.blockers || 0; acc.warnings += e.warnings || 0; return acc;
    }, { acceptedFlags: 0, dismissedFlags: 0, blockers: 0, warnings: 0 });
    return {
      domains: domains, totalPoints: totalPoints, maxPoints: maxPoints, pct: pct,
      band: b, blocked: blocked, verdict: blocked ? 'Blocked for review' : b.label,
      evidence: evidence,
      domainsBlocked: domains.filter(function (d) { return d.blocked; }).length,
      lensesBlocked: domains.reduce(function (a, d) { return a + d.lenses.filter(function (l) { return !l.launchReady; }).length; }, 0)
    };
  }

  function assessLaunch(project, opts) {
    var ctx = buildContext(project, opts);
    var out = { notes: [] };

    // Orthogonal launch gate — flips launchReady WITHOUT touching any score.
    var blockedKeys = {};
    if (ctx.N === 0) { blockedKeys.purpose_alignment = true; blockedKeys.completion_burden = true; }
    if (ctx.N > 0 && !ctx.hasConstructs) blockedKeys.construct_definition = true;
    if ((ctx.sensitiveItems.length > 0 || ctx.sensitiveDeclared) && !ctx.declinePath) blockedKeys.sensitive_safety = true;
    // Phase 2D: now that a consent/privacy field exists, undocumented consent
    // hard-blocks launch (number unchanged; verdict held until documented).
    if (!ctx.consentDocumented && !ctx.hasConsent) blockedKeys.consent_privacy = true;
    if (ctx.sdsiCritical) {
      blockedKeys.purpose_alignment = true;
      out.notes.push(note('warn', 'Purpose Alignment', 'The Build Check found a critical design issue. Resolve it before launch; the launch verdict is held until it is.'));
    }
    function gate(key) { return blockedKeys[key] ? false : true; }

    var validity = buildDomain('validity', 'Survey Design Strength Index / SDSI', 'Validity Readiness', VALIDITY, ctx, out, gate);
    var reliability = buildDomain('reliability', 'Reliability Readiness', '', RELIABILITY, ctx, out, gate);
    var administration = buildDomain('administration', 'Administration Readiness', '', ADMINISTRATION, ctx, out, gate);

    var agg = (root.SiriReadiness && root.SiriReadiness.aggregate) || localAggregate;
    var result = agg([validity, reliability, administration]);

    // Deployment / compliance checklist derived from the same evidence.
    result.checklist = [
      { t: 'Consent / privacy documented', ok: ctx.consentDocumented || ctx.hasConsent },
      { t: 'Respondent instructions provided', ok: ctx.instrProvided || ctx.hasInstructions },
      { t: 'Every item mapped to a construct', ok: ctx.hasConstructs && ctx.unmapped === 0 && ctx.N > 0 },
      { t: 'Completion length within tolerance', ok: ctx.N >= 4 && ctx.N <= 40 },
      { t: 'No undeclared sensitive topics', ok: !((ctx.sensitiveItems.length > 0 || ctx.sensitiveDeclared) && !ctx.declinePath) }
    ];
    // Advisory notes ("what to look at"), most severe first, de-duplicated.
    var seen = {}, order = { warn: 0, info: 1 };
    result.notes = out.notes.filter(function (n) {
      var k = n.sev + '|' + n.lens + '|' + n.msg; if (seen[k]) return false; seen[k] = true; return true;
    }).sort(function (a, b2) { return (order[a.sev] || 9) - (order[b2.sev] || 9); });

    return result;
  }

  root.LaunchCheck = {
    assess: assessLaunch,
    VALIDITY: VALIDITY, RELIABILITY: RELIABILITY, ADMINISTRATION: ADMINISTRATION,
    SCALED_TYPES: SCALED_TYPES, CHOICE_TYPES: CHOICE_TYPES, STRUCTURAL_TYPES: STRUCTURAL_TYPES
  };
})(typeof window !== 'undefined' ? window : this);

if (typeof module !== 'undefined' && module.exports) {
  module.exports = (typeof window !== 'undefined' ? window : this).LaunchCheck;
}
