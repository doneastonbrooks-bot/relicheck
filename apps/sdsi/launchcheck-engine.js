/* ════════════════════════════════════════════════════════════════════════
   SIRI — Launch Check engine (deterministic, 50-point whole-survey readiness)
   ────────────────────────────────────────────────────────────────────────
   SIRI ("Survey Instrument Readiness Index") reviews the survey AS A WHOLE
   INSTRUMENT before deployment. It is the companion to SDSI (the 50-point
   item/question-level validity score). It does NOT re-judge individual
   questions (that is SDSI) and it does NOT compute response reliability (that
   is RSSI, after data are collected).

       SDSI = 50-point item/question-level validity score
       SIRI = 50-point whole-survey readiness score
       Total Survey Strength = SDSI + SIRI = 100

   FIVE DOMAINS, each worth 10 points:
       1. Survey Purpose & Alignment
       2. Construct Coverage
       3. Survey Structure & Flow
       4. Scale & Measurement Design
       5. Deployment Readiness & Evidence Use

   Each survey starts with 50 SIRI points. Each issue subtracts from its domain
   by severity (Critical -10, High -6, Medium -3, Low -1). Domains floor at 0;
   SIRI floors at 0. SIRI = sum of the five domain scores.

   Total band (on SDSI + SIRI):
       90-100 Strong / 80-89 Good / 70-79 Caution / 60-69 Weak / 0-59 Not ready
   A Critical deployment blocker caps the readiness BAND (never the number),
   mirroring SDSI: >=1 -> at most Caution, >=3 -> at most Weak, >=20% -> Not ready.
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  var STRUCTURAL_TYPES = {
    'Section Text': true, 'Instructions': true, 'Consent': true,
    'Page Break': true, 'Thank-you Message': true, 'information': true
  };
  var SCALED_TYPES = { 'Likert Scale': true, 'Likert (5-pt)': true, 'Likert (7-pt)': true, 'Rating Scale': true, 'NPS': true, 'Slider': true };
  var OPEN_TYPES = { 'Open-Ended': true, 'Short Answer': true, 'Long Answer': true, 'Comment Box': true, 'open_text': true };

  var SEV = { critical: 10, high: 6, medium: 3, low: 1 };

  var DOMAINS = [
    { key: 'purpose',    name: 'Survey Purpose & Alignment' },
    { key: 'coverage',   name: 'Construct Coverage' },
    { key: 'structure',  name: 'Survey Structure & Flow' },
    { key: 'scale',      name: 'Scale & Measurement Design' },
    { key: 'deployment', name: 'Deployment Readiness & Evidence Use' }
  ];

  function clamp10(x) { return Math.min(10, Math.max(0, x)); }
  function round1(n) { return Math.round(n * 10) / 10; }
  function lc(s) { return String(s == null ? '' : s).toLowerCase(); }

  var SENSITIVE_RE = /\b(age|sex)\b|\b(race|ethnic|gender|sexual orientation|disab|income|salary|wage|religio|citizen|immigr|national|marital|health|medical|pregnan|veteran)/i;
  var CONSENT_RE = /consent|privacy|voluntary|confidential|anonym|your responses (will|are)|will be used|how (we|your data) (use|is used)|prefer not to|optional and help|data (is|are|will be) (stored|used|kept)/i;
  var FREQ_RE = /\bhow (often|frequently|regularly)\b|\bfrequency\b/i;
  var NA_RE = /not applicable|^n\/a$|does not apply/i;

  function buildContext(project) {
    var items = project.items || [];
    var ans = items.filter(function (it) { return !STRUCTURAL_TYPES[it.type]; });
    var structural = items.filter(function (it) { return STRUCTURAL_TYPES[it.type]; });
    var scaled = ans.filter(function (it) { return SCALED_TYPES[it.type]; });
    var open = ans.filter(function (it) { return OPEN_TYPES[it.type]; });

    var byConstruct = {};
    ans.forEach(function (it) {
      if (!SCALED_TYPES[it.type]) return;
      var cn = String(it.construct || '').trim();
      if (cn) { byConstruct[cn] = byConstruct[cn] || { scaled: 0 }; byConstruct[cn].scaled += 1; }
    });
    var unmappedScaled = scaled.filter(function (it) { return String(it.construct || '').trim() === ''; }).length;

    var lr = project.launchReadiness || {};
    var consentItem = structural.some(function (it) { return CONSENT_RE.test(lc(it.prompt)); });
    var hasConsent = consentItem || !!(lr.consent && (lr.consent.documented || CONSENT_RE.test(lc(lr.consent.statement))));

    var requiredSensitiveNoDecline = 0;
    ans.forEach(function (it) {
      var low = lc(it.prompt);
      if (!SENSITIVE_RE.test(low)) return;
      var opts = (it.options || []).map(lc);
      var hasDecline = opts.some(function (o) { return /prefer not|rather not/.test(o); });
      if (it.required && !hasDecline) requiredSensitiveNoDecline += 1;
    });

    return {
      project: project, items: items, ans: ans, N: ans.length,
      scaled: scaled, open: open, structural: structural,
      byConstruct: byConstruct, unmappedScaled: unmappedScaled,
      sections: (project.sections || []),
      purpose: String(project.purpose || '').trim(),
      audience: String(project.population || project.audience || '').trim(),
      plannedUse: String(project.planned_use || project.plannedUse || project.intended_use || '').trim(),
      title: String(project.title || '').trim(),
      hasConsent: hasConsent,
      requiredSensitiveNoDecline: requiredSensitiveNoDecline
    };
  }

  function scoreDomain(flags) {
    var s = 10;
    flags.forEach(function (fl) { s -= (SEV[fl.severity] || 0); });
    return { points: clamp10(s), flags: flags };
  }
  function f(severity, message, suggestion) { return { severity: severity, message: message, suggestion: suggestion || '' }; }

  // ── Domain 1: Survey Purpose & Alignment ──────────────────────────────────
  function dPurpose(ctx) {
    var fl = [];
    if (!ctx.purpose) fl.push(f('high', 'No survey purpose or research question is recorded.', 'Add a one or two sentence purpose stating what you want to learn and which decision it informs.'));
    else if (ctx.purpose.length < 25) fl.push(f('medium', 'The purpose statement is very brief.', 'Expand it so every section can be checked against a clear goal.'));
    if (!ctx.audience) fl.push(f('medium', 'No intended audience is recorded.', 'Name who will answer so wording and reading level can fit them.'));
    if (!ctx.plannedUse) fl.push(f('low', 'No planned use for the results is recorded.', 'State how the results will be used so items can be aligned to it.'));
    return scoreDomain(fl);
  }

  // ── Domain 2: Construct Coverage ──────────────────────────────────────────
  function dCoverage(ctx) {
    var fl = [];
    var names = Object.keys(ctx.byConstruct);
    if (ctx.scaled.length === 0) {
      if (ctx.N > 0 && names.length === 0) fl.push(f('low', 'No measurement constructs are defined.', 'Name what the survey measures so coverage can be judged.'));
      return scoreDomain(fl);
    }
    var multi = names.filter(function (n) { return ctx.byConstruct[n].scaled >= 3; }).length;
    var thin = names.filter(function (n) { return ctx.byConstruct[n].scaled > 0 && ctx.byConstruct[n].scaled < 3; }).length;
    if (multi === 0) fl.push(f('medium', 'No construct is measured by three or more items, so no construct is covered with enough depth.', 'Build at least one multi-item scale (three or more items) per key construct.'));
    if (thin > 0) fl.push(f('low', thin + ' construct' + (thin === 1 ? '' : 's') + ' rely on only one or two items.', 'Add items so each construct is measured from a few angles.'));
    if (ctx.unmappedScaled > 0) fl.push(f('medium', ctx.unmappedScaled + ' scaled item' + (ctx.unmappedScaled === 1 ? ' is' : 's are') + ' not mapped to any construct.', 'Assign each scaled item to the construct it measures.'));
    return scoreDomain(fl);
  }

  // ── Domain 3: Survey Structure & Flow ─────────────────────────────────────
  function dStructure(ctx) {
    var fl = [];
    var n = ctx.N;
    if (n === 0) { fl.push(f('high', 'The survey has no answerable questions.', 'Add questions before assessing readiness.')); return scoreDomain(fl); }
    if (n < 4) fl.push(f('medium', 'The survey is very short (' + n + ' questions) for a full instrument.', 'Add items so the purpose is covered with enough depth.'));
    else if (n > 40) fl.push(f('medium', 'The survey is long (' + n + ' questions); respondent fatigue is likely.', 'Trim to the items that serve the purpose, or split into waves.'));
    if (n > 8 && ctx.sections.length <= 1) fl.push(f('low', 'A multi-question survey is presented without sections.', 'Group related questions into clearly paced sections.'));
    if (n >= 5 && (ctx.open.length / n) > 0.5) fl.push(f('medium', 'More than half the questions are open-ended, a heavy writing burden.', 'Convert some to closed questions to lower burden.'));
    var interleaved = ctx.ans.some(function (it, i) {
      if (!SENSITIVE_RE.test(lc(it.prompt))) return false;
      var sec = lc(it.section || '');
      return ctx.ans.slice(i + 1).some(function (j) { return !SENSITIVE_RE.test(lc(j.prompt)) && lc(j.section || '') !== sec; });
    });
    if (interleaved) fl.push(f('medium', 'Demographic questions are interleaved with the main items instead of grouped.', 'Place demographics in their own section, usually at the end.'));
    return scoreDomain(fl);
  }

  // ── Domain 4: Scale & Measurement Design ──────────────────────────────────
  function dScale(ctx) {
    var fl = [];
    if (ctx.scaled.length === 0) return scoreDomain(fl);
    var pts = {};
    ctx.scaled.forEach(function (it) { var p = parseInt((it.settings || {}).points, 10); if (p) pts[p] = 1; });
    if (Object.keys(pts).length > 1) fl.push(f('medium', 'Likert questions use different scale lengths across the survey.', 'Use one consistent Likert length so responses compare cleanly.'));
    var mismatch = ctx.scaled.filter(function (it) {
      var anchors = lc((it.settings || {}).likertLow + ' ' + (it.settings || {}).likertHigh);
      var agree = anchors.indexOf('agree') !== -1 || anchors.trim() === '';
      return FREQ_RE.test(lc(it.prompt)) && agree;
    }).length;
    if (mismatch > 0) fl.push(f('medium', mismatch + ' item' + (mismatch === 1 ? '' : 's') + ' ask about frequency but use an agreement scale.', 'Match the scale to the stem (use a frequency scale for "how often" items).'));
    var condNoNA = ctx.scaled.filter(function (it) {
      var low = lc(it.prompt);
      var conditional = /\b(supervisor|manager|my team|my department)\b/.test(low);
      var hasNA = (it.options || []).some(function (o) { return NA_RE.test(lc(o)); });
      return conditional && !hasNA;
    }).length;
    if (condNoNA >= 1) fl.push(f('low', 'Some questions assume a condition (e.g. having a supervisor) without a "Not applicable" option.', 'Add a "Not applicable" option where the question may not apply.'));
    return scoreDomain(fl);
  }

  // ── Domain 5: Deployment Readiness & Evidence Use ─────────────────────────
  function dDeployment(ctx) {
    var fl = [];
    if (ctx.requiredSensitiveNoDecline > 0) fl.push(f('critical', ctx.requiredSensitiveNoDecline + ' required sensitive question' + (ctx.requiredSensitiveNoDecline === 1 ? '' : 's') + ' offer no way to decline and no stated purpose.', 'Make sensitive questions optional, add "Prefer not to answer", and state why the data is collected.'));
    if (!ctx.hasConsent) fl.push(f(ctx.requiredSensitiveNoDecline > 0 ? 'medium' : 'high', 'No consent or privacy statement is provided.', 'Add a short notice on what is collected, why, how it is stored, and that participation is voluntary.'));
    if (!ctx.plannedUse) fl.push(f('medium', 'No planned use or analysis is documented, so readiness for analysis cannot be confirmed.', 'State how the results will be analyzed and used.'));
    return scoreDomain(fl);
  }

  function totalBandFromKey(k) {
    return { notready: { key: 'notready', label: 'Not ready' }, weak: { key: 'weak', label: 'Weak' },
      caution: { key: 'caution', label: 'Caution' }, good: { key: 'good', label: 'Good' }, strong: { key: 'strong', label: 'Strong' } }[k];
  }
  function totalBand(total) {
    if (total >= 90) return totalBandFromKey('strong');
    if (total >= 80) return totalBandFromKey('good');
    if (total >= 70) return totalBandFromKey('caution');
    if (total >= 60) return totalBandFromKey('weak');
    return totalBandFromKey('notready');
  }

  function assessLaunch(project, opts) {
    project = project || {}; opts = opts || {};
    var ctx = buildContext(project);

    var scorers = { purpose: dPurpose, coverage: dCoverage, structure: dStructure, scale: dScale, deployment: dDeployment };
    var allFlags = [];
    var domains = DOMAINS.map(function (d) {
      var out = scorers[d.key](ctx);
      out.flags.forEach(function (fl) { allFlags.push({ domain: d.name, domainKey: d.key, severity: fl.severity, message: fl.message, suggestion: fl.suggestion }); });
      return { key: d.key, name: d.name, points: round1(out.points), max: 10, warn: out.points < 10, flagCount: out.flags.length };
    });

    var siri = round1(domains.reduce(function (a, d) { return a + d.points; }, 0));
    var siriPct = Math.round((siri / 50) * 100);
    var siriCriticals = allFlags.filter(function (fl) { return fl.severity === 'critical'; }).length;

    var sdsi = (opts.sdsiResult && opts.sdsiResult.total != null) ? opts.sdsiResult.total
             : (opts.sdsiScore != null ? opts.sdsiScore : null);

    var result = {
      siri: siri, max: 50, pct: siriPct,
      domains: domains, flags: allFlags,
      totalPoints: siri, maxPoints: 50, blocked: false,
      notes: allFlags.map(function (fl) { return { sev: (fl.severity === 'critical' || fl.severity === 'high') ? 'warn' : 'info', lens: fl.domain, msg: fl.message }; })
    };

    if (sdsi != null) {
      var total = round1(sdsi + siri);
      var raw = totalBand(total);
      var sdsiBlockers = (opts.sdsiResult && opts.sdsiResult.deployment_blocker_count != null) ? opts.sdsiResult.deployment_blocker_count : 0;
      var blockers = sdsiBlockers + siriCriticals;
      var scored = ctx.N || 1;
      var ORDER = ['notready', 'weak', 'caution', 'good', 'strong'];
      var cap = null, headline = '';
      if (blockers >= 1) { cap = 'caution'; headline = blockers + ' deployment blocker' + (blockers === 1 ? '' : 's') + ' detected.'; }
      if (blockers >= 3) { cap = 'weak'; }
      if ((blockers / scored) >= 0.20) { cap = 'notready'; headline = Math.round((blockers / scored) * 100) + '% of questions contain critical deployment blockers.'; }
      var display = raw, wasCapped = false;
      if (cap && ORDER.indexOf(raw.key) > ORDER.indexOf(cap)) { display = totalBandFromKey(cap); wasCapped = true; }

      result.sdsi = sdsi;
      result.total = total; result.total_max = 100;
      result.total_raw_band = raw.label;
      result.total_band = display.label; result.total_band_key = display.key;
      result.total_band_was_capped = wasCapped;
      result.deployment_blocker_count = blockers;
      result.blocker_headline = headline;
      result.total_band_cap_reason = wasCapped
        ? ('The total score is ' + raw.label + ', but readiness is capped at ' + display.label + ' because ' + blockers + ' critical deployment blocker' + (blockers === 1 ? ' was' : 's were') + ' detected.')
        : '';
    }
    return result;
  }

  root.LaunchCheck = {
    assess: assessLaunch,
    DOMAINS: DOMAINS,
    SCALED_TYPES: SCALED_TYPES, STRUCTURAL_TYPES: STRUCTURAL_TYPES,
    totalBand: totalBand
  };
})(typeof window !== 'undefined' ? window : this);

if (typeof module !== 'undefined' && module.exports) {
  module.exports = (typeof window !== 'undefined' ? window : this).LaunchCheck;
}
