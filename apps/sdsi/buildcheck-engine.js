/* ════════════════════════════════════════════════════════════════════════
   SDSI — Build Check engine (deterministic, calibrated 50-point design score)
   ────────────────────────────────────────────────────────────────────────
   The Build Check is the user-facing 50-point score the Survey Development
   System shows WHILE someone is building a survey — the "improve as you build"
   companion to SIRI (the 100-point Launch Check, which stays the final
   pre-launch gate). This is the deterministic, no-AI half of the hybrid
   design; an optional "Deep check with ReliCheck Intelligence" can later add
   richer flags, but the number here always stands on its own.

   SEVEN CATEGORIES (point budgets sum to 50)
       1. Purpose & alignment            8
       2. Construct clarity              8
       3. Item quality                  10
       4. Response-scale quality         8
       5. Survey flow & burden           5
       6. Bias, accessibility & clarity  5
       7. Reliability readiness          6

   READINESS LABELS (on the 50-point total)
       45–50    Strong design
       40–44.9  Solid design, minor improvements
       35–39.9  Developing design, revise before launch
       30–34.9  Weak design, major revision needed
       below 30 Not ready for launch review

   CALIBRATION MODEL (Phase 2B.1)
   A purely penalty-from-100 model is too forgiving: a short, clean-but-thin
   survey looks identical to a deep, well-developed one because the heuristics
   only detect DEFECTS, never the ABSENCE of development. So each category now
   blends two ideas, both deterministic and explainable:

     • Development / sufficiency — does the instrument actually have the
       structure the category rewards? (constructs defined and covered by
       enough items, a substantive purpose, adequate length, real scales).
       Missing development lowers the category's internal score directly.
     • Proportional defects — a flaw counts relative to survey size. One
       double-barreled item out of 6 hurts far more than one out of 40, so
       item-level and bias penalties are normalised by the item count.

   Internal score is 0–100 per category, then weighted to its share of 50.

   FORMAT NEUTRALITY (preserved and tested)
   Reliability readiness only assesses the closed, scaled items that actually
   rely on internal consistency. A survey with no scaled items (e.g. an
   open-response / qualitative design) is NEVER penalised on reliability — it
   scores the full weight there. Construct clarity still applies, because
   naming what you explore is good practice in any methodology.
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  // The locked SIRI lens spine (validity-lens-engine.js) is referenced for its
  // severity vocabulary so the two systems speak the same language; the Build
  // Check's own arithmetic is calibrated separately and does not mutate it.
  var SPINE = root.ValidityLens || null;

  var CATEGORIES = [
    { key: 'purpose',     name: 'Purpose & alignment',           weight: 8  },
    { key: 'construct',   name: 'Construct clarity',             weight: 8  },
    { key: 'item',        name: 'Item quality',                  weight: 10 },
    { key: 'scale',       name: 'Response-scale quality',        weight: 8  },
    { key: 'flow',        name: 'Survey flow & burden',          weight: 5  },
    { key: 'bias',        name: 'Bias, accessibility & clarity', weight: 5  },
    { key: 'reliability', name: 'Reliability readiness',         weight: 6  }
  ];

  // How much a flaw "costs" as a share of an item, by severity (legacy demerit
  // model, still used by perItemDemerit for any internal helpers).
  var DEMERIT = { critical: 1.0, major: 0.85, moderate: 0.6, minor: 0.3 };

  // ── SDSI scoring model (per-item, five 10-point domains) ──────────────────
  // Each scored item starts at 50 (10 per domain). Each flag subtracts from its
  // domain by severity; domains and item scores floor at 0. Survey SDSI is the
  // average of all scored item scores.
  var SCORE_DOMAINS = [
    { key: 'completeness', name: 'Completeness' },
    { key: 'clarity',      name: 'Clarity' },
    { key: 'neutrality',   name: 'Neutrality & Single Construct' },
    { key: 'response',     name: 'Response Quality' },
    { key: 'dignity',      name: 'Dignity, Privacy & Analysis Readiness' }
  ];
  // The reviewer's fine-grained domains fold onto the five scoring domains.
  var DOMAIN_MAP = {
    'Completeness': 'completeness',
    'Clarity': 'clarity',
    'Neutrality': 'neutrality', 'Single Construct': 'neutrality',
    'Response Fit': 'response', 'Category Quality': 'response', 'Scale Consistency': 'response',
    'Dignity and Framing': 'dignity', 'Privacy and Sensitivity': 'dignity', 'Analysis Readiness': 'dignity'
  };
  var SEV_PENALTY = { critical: 10, high: 6, medium: 3, low: 1 };

  // ── Item-validity score caps (max final item SDSI score per triggered flaw) ──
  // Domain scores measure QUALITY; these caps protect item VALIDITY. After the
  // five domains are summed into the raw item score, the most restrictive cap
  // triggered by that item's flags is applied: final = min(raw, mostRestrictive).
  // A serious item-level flaw therefore cannot leave a question scoring in the
  // low-to-mid 40s just because only one domain was dinged. Survey-level issues
  // (purpose, coverage, flow) are NOT here; they live in SIRI. Keyed by flag_key;
  // flags absent from this map impose no cap (the raw item score stands).
  var ITEM_CAP = {
    missing_question_text:         10,
    placeholder_question_text:     15,
    missing_response_options:      15, // closed-ended item with no usable options
    placeholder_options:           20,
    too_few_options:               20, // options cannot produce usable data
    question_scale_mismatch:       30,
    required_sensitive_item:       30, // required sensitive item, no "Prefer not to answer"
    double_barreled:               35,
    leading:                       35,
    loaded_wording:                35,
    assumptive_wording:            35,
    absolute_wording:              35, // same response-biasing family as leading/loaded
    overlapping_categories:        35,
    category_gaps:                 35,
    non_exhaustive_categories:     35, // category gaps (no exhaustive / "Other")
    inconsistent_option_structure: 35,
    informal_category_label:       38, // informal or dignity-risk category label
    open_ended_too_broad:          38,
    reading_clarity_concern:       42,
    negative_wording:              45  // minor wording issue
  };

  var SCALED_TYPES = { 'Likert Scale': true, 'Likert (5-pt)': true, 'Likert (7-pt)': true, 'Rating Scale': true, 'NPS': true, 'Slider': true };
  // Choice questions that require the author to supply real answer options. These
  // are evaluated for option quality in catScale. 'Single Choice' was missing,
  // which let single-choice questions (the common demographic type) escape the
  // options check entirely. Yes/No and True/False are self-defining and excluded
  // on purpose.
  var CHOICE_TYPES = {
    'Single Choice': true, 'Multiple Choice': true, 'Multiple Answers / Checkboxes': true,
    'Checkboxes': true, 'Dropdown': true, 'Ranking': true
  };
  var STRUCTURAL_TYPES = {
    'Section Text': true, 'Page Break': true, 'Thank-you Message': true,
    'Consent': true, 'Instructions': true
  };
  // Closed-ended types whose author MUST supply real answer options. (Yes/No and
  // True/False are self-defining and handled separately.)
  var NEEDS_OPTIONS = {
    'Single Choice': true, 'Multiple Choice': true, 'Multiple Answers / Checkboxes': true,
    'Checkboxes': true, 'Dropdown': true, 'Demographic': true, 'Categorical': true,
    'Ranking': true, 'Matrix/Grid': true, 'Matrix': true
  };
  var OPEN_TYPES = { 'Open-Ended': true, 'Short Answer': true, 'Long Answer': true, 'Comment Box': true };
  var SELF_DEFINING = { 'Yes/No': true, 'True/False': true };

  function isClosed(t) { return !!(SCALED_TYPES[t] || NEEDS_OPTIONS[t] || SELF_DEFINING[t]); }
  function isLikertish(t) { return t === 'Likert Scale' || t === 'Likert (5-pt)' || t === 'Likert (7-pt)'; }

  function clamp(s) { return Math.min(100, Math.max(0, s)); }
  function round1(n) { return Math.round(n * 10) / 10; }
  function lc(s) { return String(s == null ? '' : s).toLowerCase(); }
  function words(s) { return lc(s).split(/[^a-z0-9']+/).filter(Boolean); }

  function flag(category, check, severity, opts) {
    opts = opts || {};
    return {
      category: category, check: check, severity: severity,
      item_ref: opts.item_ref != null ? opts.item_ref : null,
      item_no: opts.item_no != null ? opts.item_no : null,
      quote: opts.quote || '', message: opts.message || '', suggestion: opts.suggestion || ''
    };
  }
  function worst(flags) {
    var d = 0;
    flags.forEach(function (f) { var v = DEMERIT[f.severity] || 0; if (v > d) d = v; });
    return d;
  }
  // Sum of the worst demerit PER ITEM across a set of item-level findings — the
  // proportional model: one item's flaws cost at most one item's worth.
  function perItemDemerit(flags) {
    var byItem = {};
    flags.forEach(function (f) { var k = f.item_ref; (byItem[k] = byItem[k] || []).push(f); });
    var sum = 0; Object.keys(byItem).forEach(function (k) { sum += worst(byItem[k]); });
    return sum;
  }

  var LEADING_WORDS = ['obviously', 'clearly', 'surely', 'everyone knows', "don't you agree", 'isn\'t it', 'wouldn\'t you', 'as you know'];
  var ABSOLUTE_WORDS = ['always', 'never', 'every ', 'all of', 'none of', 'completely', 'totally', 'rarely'];
  var LOADED_WORDS = ['failure', 'foolish', 'irresponsible', 'lazy', 'stupid', 'crazy', 'suffer', 'unfortunately'];
  var ASSUMPTIVE_WORDS = ['since you', 'now that you', 'given that you', 'as you already', 'after you'];

  // ── Item-validity vocabularies (the pre-deployment review layer) ──────────
  // A response option that is a placeholder, not a real answer choice.
  var PLACEHOLDER_OPT_RE = /^(option|choice|response option|answer)\s*[0-9a-d]*$|^(add|new|enter|untitled)\s+option$|^(lorem ipsum|test|sample|tbd|todo|n\/a placeholder)$|choice\s*[a-d]$/i;
  // Question text that is a placeholder, not a real question.
  var PLACEHOLDER_Q_RE = /^(untitled question|question text|enter question|add question|new question|lorem ipsum|test question|sample question|untitled|question \d+)$/i;
  // Informal / dignity-risk category labels.
  var INFORMAL_LABELS = ['the floor', 'low level', 'bottom level', 'unskilled', 'non-professional', 'regular worker', 'just staff', 'subordinate', 'grunt', 'peon'];
  // Sensitive demographic topics that need a decline path + a stated purpose.
  // Leading word-boundary + stem (no trailing \b, so "disab" matches "disability").
  // "age"/"sex" are matched as whole words to avoid false hits in "agency"/"sextant".
  var SENSITIVE_RE = /\b(age|sex)\b|\b(race|ethnic|gender|sexual orientation|disab|income|salary|wage|educat|religio|citizen|immigr|national|marital|health|medical|pregnan|veteran|household)/i;
  var TENURE_RE = /\b(tenure|years employed|length of employment|time (with|at) (the )?(organization|company|org)|time in role|how long have you (worked|been))\b/i;
  var ROLE_LEVEL_RE = /\b(role level|position level|job level|employee level|management level|seniority)\b/i;
  var SUPERVISORY_RE = /supervis|direct reports|manage (employees|people|staff)|people you manage/i;
  var FREQUENCY_Q_RE = /\bhow (often|frequently|regularly)\b|\bfrequency\b/i;
  var SATISFACTION_Q_RE = /\bhow satisfied\b|\bsatisfaction with\b/i;
  var TIMEFRAME_RE = /\b(today|yesterday|this (week|month|year)|past (\d+ )?(day|week|month|year)|last (\d+ )?(day|week|month|year)|per (day|week|month)|daily|weekly|monthly|in the (last|past))\b/i;
  function hasOpt(opts, re) { return opts.some(function (o) { return re.test(o); }); }
  var PNA_RE = /prefer not|rather not say|decline to|choose not to/i;
  var OTHER_RE = /^other\b|please specify/i;
  var NA_RE = /not applicable|^n\/a$|does not apply/i;

  // Parse a numeric range from a category label ("1-3 years", "55+", "Less than 1").
  function parseRange(s) {
    s = lc(s);
    var m;
    // loOpen/hiOpen mark a STRICTLY-EXCLUSIVE boundary ("more than X" excludes X;
    // "less than X" excludes X). Inclusive open bands ("at least", "up to", "55+")
    // keep their endpoint. Used so an open band touching a neighbor at a shared
    // endpoint is not mistaken for an overlap.
    if (/(more than|over|above|at least|or more|\+)/.test(s)) { m = s.match(/(\d+)/); if (m) return { lo: +m[1], hi: Infinity, loOpen: /more than|over|above/.test(s) }; }
    if (/(less than|under|below|up to|fewer than)/.test(s)) { m = s.match(/(\d+)/); if (m) return { lo: 0, hi: +m[1], hiOpen: /less than|under|below|fewer than/.test(s) }; }
    m = s.match(/(\d+)\s*(?:[-–—]|to)\s*(\d+)/); if (m) return { lo: +m[1], hi: +m[2] };
    m = s.match(/^\D*(\d+)\D*$/); if (m) return { lo: +m[1], hi: +m[1] };
    return null;
  }

  // ── Context shared by every category function ─────────────────────────────
  function buildContext(project) {
    var items = project.items || [];
    var ans = items.filter(function (it) { return !STRUCTURAL_TYPES[it.type]; });
    var constructs = (project.constructs || []).filter(function (c) { return String(c.name || '').trim() !== ''; });
    var scaled = ans.filter(function (it) { return SCALED_TYPES[it.type]; });
    var scaleItems = ans.filter(function (it) { return SCALED_TYPES[it.type] || CHOICE_TYPES[it.type]; });
    // Every closed-ended item (scaled, choice, demographic, yes/no) — the set whose
    // response options the validity layer scores. Used as the catScale denominator.
    var closedItems = ans.filter(function (it) { return isClosed(it.type); });

    // Map answerable items to defined constructs (by name).
    var byConstruct = {}; // construct name -> { all:count, scaled:count }
    constructs.forEach(function (c) { byConstruct[c.name] = { all: 0, scaled: 0 }; });
    var unmapped = 0;
    ans.forEach(function (it) {
      var cn = String(it.construct || '').trim();
      if (cn && byConstruct[cn]) {
        byConstruct[cn].all += 1;
        if (SCALED_TYPES[it.type]) byConstruct[cn].scaled += 1;
      } else {
        unmapped += 1;
      }
    });

    return {
      project: project, items: items, ans: ans, N: ans.length,
      constructs: constructs, hasConstructs: constructs.length > 0,
      scaled: scaled, scaleItems: scaleItems, closedItems: closedItems,
      byConstruct: byConstruct, unmapped: unmapped,
      sections: project.sections || []
    };
  }

  function itemNo(it, i) { return it.item_no != null ? it.item_no : (i + 1); }
  function itemRef(it, i) { return it.item_ref != null ? it.item_ref : ('i' + i); }

  // ── Per-question validity reviewer ────────────────────────────────────────
  // The pre-deployment item-validity layer. Runs over EVERY answerable question
  // regardless of type and emits rich findings (the M-spec output). Each finding
  // also carries the legacy fields (category/check/severity/message/suggestion)
  // so it feeds the existing 50-pt category scoring + UI without a separate path.
  //   severity_level (user vocab): critical | high | medium | low
  //   severity (internal, drives DEMERIT):  critical | major   | moderate | minor
  var SEV2INT = { critical: 'critical', high: 'major', medium: 'moderate', low: 'minor' };

  function reviewQuestions(ctx) {
    var found = [];
    ctx.ans.forEach(function (it, i) {
      var no = itemNo(it, i), ref = itemRef(it, i), type = it.type || '';
      var text = String(it.prompt || '').trim(), low = lc(text), w = words(text);
      var opts = (it.options || []).map(function (o) { return String(o == null ? '' : o).trim(); });
      var nonEmpty = opts.filter(function (o) { return o !== ''; });
      var placeholders = nonEmpty.filter(function (o) { return PLACEHOLDER_OPT_RE.test(o); });
      var meaningful = nonEmpty.filter(function (o) { return !PLACEHOLDER_OPT_RE.test(o); });

      function add(def) {
        found.push({
          category: def.cat, check: def.key, severity: SEV2INT[def.sev] || 'moderate',
          item_ref: ref, item_no: no, quote: text,
          message: def.msg, suggestion: def.fix || '',
          flag_key: def.key, flag_label: def.label, severity_level: def.sev,
          domain: def.domain, problem_summary: def.msg, why_it_matters: def.why || '',
          suggested_revision: def.fix || '', suggested_options: def.options || null,
          question_id: it.item_ref != null ? it.item_ref : null,
          question_number: no, question_text: text, question_type: type
        });
      }

      // 1. Question text -----------------------------------------------------
      if (text === '') {
        add({ key: 'missing_question_text', label: 'Missing question text', sev: 'critical', domain: 'Completeness', cat: 'item',
          msg: 'Question ' + no + ' has no question text.', why: 'A question with no text cannot be answered or interpreted.', fix: 'Write the question, or remove the empty item.' });
      } else if (PLACEHOLDER_Q_RE.test(text)) {
        add({ key: 'placeholder_question_text', label: 'Placeholder question text', sev: 'critical', domain: 'Completeness', cat: 'item',
          msg: 'Question ' + no + ' still uses placeholder text ("' + text + '").', why: 'Placeholder text means the real question was never written.', fix: 'Replace it with the actual question respondents should answer.' });
      } else {
        // Clarity / neutrality wording checks (all types)
        if (!CHOICE_TYPES[type] && !NEEDS_OPTIONS[type] && /\b(and|or)\b/.test(low) && low.split(/\b(?:and|or)\b/).length === 2 && text.length > 30) {
          add({ key: 'double_barreled', label: 'Double-barreled item', sev: 'high', domain: 'Single Construct', cat: 'item',
            msg: 'Question ' + no + ' asks about two things at once ("and"/"or").', why: 'Respondents who feel differently about each part cannot answer accurately, and the data conflates two constructs.', fix: 'Split it into separate single-idea questions.' });
        }
        if (LEADING_WORDS.some(function (lw) { return low.indexOf(lw) !== -1; })) {
          add({ key: 'leading', label: 'Leading wording', sev: 'high', domain: 'Neutrality', cat: 'bias',
            msg: 'Question ' + no + ' uses leading wording that nudges the answer.', why: 'Leading wording biases responses toward a preferred answer.', fix: 'Rephrase neutrally so no response is signaled as correct.' });
        }
        if (LOADED_WORDS.some(function (lw) { return low.indexOf(lw) !== -1; })) {
          add({ key: 'loaded_wording', label: 'Loaded wording', sev: 'high', domain: 'Neutrality', cat: 'bias',
            msg: 'Question ' + no + ' uses emotionally loaded wording.', why: 'Loaded language pressures respondents and distorts honest answers.', fix: 'Use neutral, respectful language.' });
        }
        if (ASSUMPTIVE_WORDS.some(function (lw) { return low.indexOf(lw) !== -1; })) {
          add({ key: 'assumptive_wording', label: 'Assumptive wording', sev: 'medium', domain: 'Neutrality', cat: 'bias',
            msg: 'Question ' + no + ' assumes something about the respondent.', why: 'Assumptive framing excludes respondents for whom the premise is false.', fix: 'Remove the assumption or add a screening question first.' });
        }
        if (ABSOLUTE_WORDS.some(function (lw) { return low.indexOf(lw) !== -1; })) {
          add({ key: 'absolute_wording', label: 'Absolute wording', sev: 'medium', domain: 'Clarity', cat: 'bias',
            msg: 'Question ' + no + ' uses an absolute term (e.g. "always"/"never").', why: 'Absolutes are hard to answer truthfully and push respondents to disagree.', fix: 'Soften absolutes so the item fits a range of real experiences.' });
        }
        if (/\bnot\b.*\b(no|never|none|nothing|cannot|n't)\b/.test(low) || /n't\b.*\bnot\b/.test(low)) {
          add({ key: 'negative_wording', label: 'Negative wording', sev: 'low', domain: 'Clarity', cat: 'bias',
            msg: 'Question ' + no + ' may contain a double negative.', why: 'Double negatives are easy to misread and produce error.', fix: 'Rephrase positively so the meaning is immediate.' });
        }
        if (text.length > 200) {
          add({ key: 'item_length_concern', label: 'Item length concern', sev: 'low', domain: 'Clarity', cat: 'item',
            msg: 'Question ' + no + ' is long and may be hard to read.', why: 'Long items increase reading burden and misinterpretation.', fix: 'Tighten to one clear sentence.' });
        }
        if (w.length > 0 && w.length < 3 && !isClosed(type)) {
          add({ key: 'reading_clarity_concern', label: 'Reading clarity concern', sev: 'low', domain: 'Clarity', cat: 'item',
            msg: 'Question ' + no + ' is very short and may be unclear.', why: 'A fragment may not read as a complete, answerable question.', fix: 'Make sure it reads as a full question.' });
        }
      }

      // 2. Closed-ended response options ------------------------------------
      if (NEEDS_OPTIONS[type]) {
        if (nonEmpty.length === 0) {
          add({ key: 'missing_response_options', label: 'Missing response options', sev: 'critical', domain: 'Completeness', cat: 'scale',
            msg: 'Question ' + no + ' (' + type + ') has no response options.', why: 'A closed-ended question with no options cannot collect any answer.', fix: 'Add the real answer choices respondents will see.' });
          add({ key: 'analysis_readiness_concern', label: 'Analysis-readiness concern', sev: 'high', domain: 'Analysis Readiness', cat: 'scale',
            msg: 'Question ' + no + ' cannot produce interpretable data as written.', why: 'Without usable options the question yields nothing to analyze.', fix: 'Define real, mutually exclusive answer options.' });
        } else if (nonEmpty.length < 2) {
          add({ key: 'too_few_options', label: 'Too few response options', sev: 'high', domain: 'Completeness', cat: 'scale',
            msg: 'Question ' + no + ' has fewer than two response options.', why: 'A single option gives respondents no real choice.', fix: 'Add the remaining answer choices.' });
        }
        if (nonEmpty.length >= 1 && placeholders.length > 0 && meaningful.length < 2) {
          add({ key: 'placeholder_options', label: 'Placeholder response option', sev: 'critical', domain: 'Completeness', cat: 'scale',
            msg: 'This question contains placeholder response options and cannot produce interpretable data.', why: 'Placeholder options (e.g. "Option 1") were never replaced with real answer choices.', fix: 'Replace the placeholders with the actual response choices.' });
          add({ key: 'analysis_readiness_concern', label: 'Analysis-readiness concern', sev: 'high', domain: 'Analysis Readiness', cat: 'scale',
            msg: 'Question ' + no + ' cannot produce interpretable data because its options are placeholders.', why: 'Placeholder categories have no meaning to analyze.', fix: 'Provide real, distinct answer categories.' });
        }
        // Category quality (mutually exclusive, exhaustive, consistent) on real options
        if (meaningful.length >= 2) {
          var ranges = meaningful.map(parseRange).filter(Boolean);
          if (ranges.length >= 2 && ranges.length >= meaningful.length - 1) {
            var sorted = ranges.slice().sort(function (a, b) { return a.lo - b.lo; });
            var overlap = false, gap = false;
            for (var k = 0; k < sorted.length - 1; k++) {
              var a = sorted[k], b = sorted[k + 1];
              if (a.hi > b.lo) overlap = true;
              // A shared endpoint (a.hi === b.lo) is a REAL overlap only when both
              // sides are inclusive there ("0-1" | "1-3" share 1). If either side is
              // an exclusive open band ("Less than 1" | "1-3", "7-10" | "More than 10"),
              // they only touch — not overlap.
              else if (a.hi === b.lo && !a.hiOpen && !b.loOpen) overlap = true;
              else if (isFinite(a.hi) && (b.lo - a.hi) > 1) gap = true;
            }
            if (overlap) add({ key: 'overlapping_categories', label: 'Overlapping categories', sev: 'high', domain: 'Category Quality', cat: 'scale',
              msg: 'Question ' + no + ' has overlapping numeric ranges (a value falls in more than one option).', why: 'Overlapping ranges make answers ambiguous and uncountable.', fix: 'Use non-overlapping buckets, e.g. "1-3", "4-6", "7-10".' });
            if (gap) add({ key: 'category_gaps', label: 'Category gaps', sev: 'high', domain: 'Category Quality', cat: 'scale',
              msg: 'Question ' + no + ' has gaps between its ranges (some values fit no option).', why: 'Respondents whose value falls in a gap cannot answer.', fix: 'Make the ranges contiguous so every value has a home.' });
          }
          // Informal / dignity-risk labels
          meaningful.forEach(function (o) {
            if (INFORMAL_LABELS.indexOf(lc(o)) !== -1) {
              add({ key: 'informal_category_label', label: 'Informal or unclear category label', sev: 'high', domain: 'Dignity and Framing', cat: 'bias',
                msg: 'Option "' + o + '" in question ' + no + ' is an informal or undignified label.', why: 'Disrespectful category labels harm respondent dignity and skew who answers honestly.', fix: 'Use a neutral, professional label such as "Frontline staff / individual contributor".' });
            }
          });
        }
      }

      // 3. Demographic / sensitive items ------------------------------------
      var isSensitive = SENSITIVE_RE.test(low) || TENURE_RE.test(low) || ROLE_LEVEL_RE.test(low);
      if (isSensitive && (NEEDS_OPTIONS[type] || SELF_DEFINING[type] || SCALED_TYPES[type])) {
        if (it.required) {
          add({ key: 'required_sensitive_item', label: 'Required sensitive item', sev: 'high', domain: 'Privacy and Sensitivity', cat: 'bias',
            msg: 'Question ' + no + ' asks for sensitive information and is marked required.', why: 'Forcing an answer to a sensitive question is coercive and can violate research ethics.', fix: 'Make the question optional and add a "Prefer not to answer" choice.' });
        }
        if (nonEmpty.length >= 1 && !hasOpt(nonEmpty, PNA_RE)) {
          add({ key: 'missing_prefer_not_to_answer', label: 'Missing prefer not to answer option', sev: it.required ? 'high' : 'medium', domain: 'Privacy and Sensitivity', cat: 'bias',
            msg: 'Question ' + no + ' is sensitive but offers no "Prefer not to answer" option.', why: 'Respondents need a dignified way to decline a sensitive question.', fix: 'Add a "Prefer not to answer" option.' });
        }
        if (!String(it.purpose_note || it.help || '').trim() && !String(ctx.project.purpose || '').toLowerCase().match(SENSITIVE_RE)) {
          add({ key: 'sensitive_item_without_context', label: 'Sensitive item without context', sev: 'medium', domain: 'Privacy and Sensitivity', cat: 'bias',
            msg: 'Question ' + no + ' collects sensitive information without explaining why.', why: 'Respondents are more willing and trust is preserved when the purpose of sensitive data is stated.', fix: 'Add a short note on why this is collected and how it will be used.' });
        }
        add({ key: 'demographic_purpose_check', label: 'Demographic purpose check', sev: 'low', domain: 'Privacy and Sensitivity', cat: 'bias',
          msg: 'Confirm question ' + no + ' (demographic) is needed for your analysis.', why: 'Collecting demographics you will not analyze adds burden and privacy risk for no benefit.', fix: 'Keep it only if it serves a stated analysis; otherwise remove it.' });
      }

      // 4. Tenure-specific -----------------------------------------------------
      if (TENURE_RE.test(low) && NEEDS_OPTIONS[type] && meaningful.length >= 2) {
        if (!hasOpt(meaningful, /less than|under|up to|fewer than|0\s*[-–]\s*1/i)) {
          add({ key: 'category_gaps', label: 'Category gaps', sev: 'medium', domain: 'Category Quality', cat: 'scale',
            msg: 'Tenure question ' + no + ' has no entry-level band (e.g. "Less than 1 year").', why: 'New hires have no option that fits them.', fix: 'Add a "Less than 1 year" band.',
            options: ['Less than 1 year', '1-3 years', '4-6 years', '7-10 years', 'More than 10 years', 'Prefer not to answer'] });
        }
        if (!hasOpt(meaningful, /more than|over|\+|or more|at least/i)) {
          add({ key: 'non_exhaustive_categories', label: 'Non-exhaustive categories', sev: 'medium', domain: 'Category Quality', cat: 'scale',
            msg: 'Tenure question ' + no + ' has no top band (e.g. "More than 10 years").', why: 'Long-tenured respondents are capped out with no fitting option.', fix: 'Add an open-ended top band.',
            options: ['Less than 1 year', '1-3 years', '4-6 years', '7-10 years', 'More than 10 years', 'Prefer not to answer'] });
        }
        if (!/(current (role|organization|company)|department|profession|this (organization|company))/.test(low)) {
          add({ key: 'unclear_referent', label: 'Unclear referent', sev: 'medium', domain: 'Clarity', cat: 'item',
            msg: 'Tenure question ' + no + ' does not say tenure with what (current role, organization, or profession).', why: 'Respondents interpret "tenure" differently, so answers are not comparable.', fix: 'Specify, e.g. "How long have you worked in your current organization?"' });
        }
      }

      // 5. Role-level-specific -------------------------------------------------
      if (ROLE_LEVEL_RE.test(low) && NEEDS_OPTIONS[type] && meaningful.length >= 2) {
        if (!hasOpt(meaningful, OTHER_RE)) {
          add({ key: 'missing_other_option', label: 'Missing other option', sev: 'medium', domain: 'Category Quality', cat: 'scale',
            msg: 'Role-level question ' + no + ' has no "Other" option.', why: 'Roles that do not fit the listed levels have nowhere to go.', fix: 'Add an "Other" option.',
            options: ['Frontline staff / individual contributor', 'Team lead', 'Supervisor / manager', 'Director / senior manager', 'Executive / senior leadership', 'Other', 'Prefer not to answer'] });
        }
        // Mixed-construct options: some describe supervisory STATUS (a verb/phrase
        // like "Directly supervise", "Direct reports") or tenure ranges, rather than
        // a role LEVEL. A legitimate level title that happens to contain "supervisor"
        // or "manager" (e.g. "Supervisor / manager") is NOT mixing and must not trip this.
        var STATUS_PHRASE_RE = /direct reports|directly supervis|people you (manage|supervis)|manage (employees|people|staff)|number of (direct reports|people)|responsible for (employees|staff|people)/i;
        var mixed = meaningful.some(function (o) { return STATUS_PHRASE_RE.test(lc(o)) || parseRange(o); });
        var levelish = meaningful.some(function (o) { return /(staff|contributor|lead|supervis|manager|management|director|executive|leadership|entry|senior|junior|upper|frontline|associate|officer|coordinator)/i.test(o); });
        if (mixed && levelish) {
          add({ key: 'inconsistent_option_structure', label: 'Inconsistent option structure', sev: 'high', domain: 'Category Quality', cat: 'scale',
            msg: 'Role-level question ' + no + ' mixes different constructs in its options (e.g. role level, tenure, supervisory status).', why: 'Options must measure one thing; mixing them makes the answer uninterpretable.', fix: 'List only role levels; ask supervisory status and tenure as separate questions.',
            options: ['Frontline staff / individual contributor', 'Team lead', 'Supervisor / manager', 'Director / senior manager', 'Executive / senior leadership', 'Other', 'Prefer not to answer'] });
        }
        // Unclear single-word/awkward labels (not recognizable role levels)
        meaningful.forEach(function (o) {
          if (SUPERVISORY_RE.test(lc(o)) && !/manager|supervisor/.test(lc(o))) {
            add({ key: 'informal_category_label', label: 'Unclear category label', sev: 'medium', domain: 'Category Quality', cat: 'scale',
              msg: 'Option "' + o + '" in question ' + no + ' is unclear as a role level.', why: 'A supervisory phrase is not a role-level category.', fix: 'Replace with a clear level, and ask supervisory status separately.' });
          }
        });
        // (Removed the unconditional "confirm coverage" nag: it fired on every
        // role-level question, including complete lists that already include
        // "Other". Genuinely missing "Other" is already covered by
        // missing_other_option above.)
      }

      // 6. Question-scale mismatch (Response Fit) ----------------------------
      if (isLikertish(type)) {
        var s = it.settings || {};
        var anchors = lc((s.likertLow || '') + ' ' + (s.likertHigh || ''));
        var agreementScale = anchors.indexOf('agree') !== -1 || anchors === ' ' || anchors.trim() === '';
        if (FREQUENCY_Q_RE.test(low) && agreementScale) {
          add({ key: 'question_scale_mismatch', label: 'Question-scale mismatch', sev: 'high', domain: 'Response Fit', cat: 'scale',
            msg: 'Question ' + no + ' asks "how often" but uses an agreement scale.', why: 'A frequency question answered on an agree/disagree scale produces meaningless data.', fix: 'Use a frequency scale (e.g. Never, Rarely, Sometimes, Often, Always).' });
          add({ key: 'missing_timeframe', label: 'Missing timeframe', sev: 'medium', domain: 'Response Fit', cat: 'scale',
            msg: 'Frequency question ' + no + ' has no timeframe.', why: 'Frequency is uninterpretable without a window (per week, in the past month).', fix: 'Add a timeframe, e.g. "in the past month".' });
        }
        if (SATISFACTION_Q_RE.test(low) && FREQUENCY_Q_RE.test(anchors)) {
          add({ key: 'question_scale_mismatch', label: 'Question-scale mismatch', sev: 'high', domain: 'Response Fit', cat: 'scale',
            msg: 'Question ' + no + ' asks "how satisfied" but uses a frequency scale.', why: 'The scale does not match the question stem.', fix: 'Use a satisfaction scale (Very dissatisfied to Very satisfied).' });
        }
      }

      // 7. Open-ended ----------------------------------------------------------
      if (OPEN_TYPES[type] && text !== '') {
        if (/(anything|everything|tell us about|thoughts|comments|feedback|general)\b/.test(low) && w.length < 9) {
          add({ key: 'open_ended_too_broad', label: 'Open-ended item too broad', sev: 'medium', domain: 'Clarity', cat: 'item',
            msg: 'Question ' + no + ' is an open question that is too broad to answer usefully.', why: 'Vague open prompts produce scattered, hard-to-code answers.', fix: 'Focus it, e.g. "What is one change that would help you do your job more effectively?"' });
        }
      }

      // 8. Matrix / grid -------------------------------------------------------
      if ((type === 'Matrix/Grid' || type === 'Matrix') && meaningful.length > 8) {
        add({ key: 'matrix_fatigue_risk', label: 'Matrix fatigue risk', sev: 'medium', domain: 'Analysis Readiness', cat: 'scale',
          msg: 'Matrix question ' + no + ' has ' + meaningful.length + ' rows, which invites straight-lining.', why: 'Large grids cause fatigue and low-quality straight-lined responses.', fix: 'Split into smaller grids or fewer rows.' });
      }
    });

    // Survey-level: too many open-ended questions
    var openN = ctx.ans.filter(function (it) { return OPEN_TYPES[it.type]; }).length;
    if (ctx.N >= 5 && openN / ctx.N > 0.5) {
      found.push({
        category: 'flow', check: 'open_ended_burden', severity: 'minor', item_ref: null, item_no: null, quote: '',
        message: openN + ' of ' + ctx.N + ' questions are open-ended, a heavy writing burden.', suggestion: 'Convert some to closed questions to lower burden and ease analysis.',
        flag_key: 'open_ended_burden', flag_label: 'Open-ended burden concern', severity_level: 'low', domain: 'Analysis Readiness',
        problem_summary: openN + ' of ' + ctx.N + ' questions are open-ended.', why_it_matters: 'Too many open questions lower completion and are hard to analyze.',
        suggested_revision: 'Convert some to closed questions.', suggested_options: null,
        question_id: null, question_number: null, question_text: '', question_type: 'survey'
      });
    }
    return found;
  }

  // ── Category 1: Purpose & alignment (8) ───────────────────────────────────
  function catPurpose(ctx) {
    var p = ctx.project, flags = [];
    var purpose = String(p.purpose || '').trim();
    if (purpose === '') {
      flags.push(flag('purpose', 'purpose_missing', 'critical', {
        message: 'No study purpose or research question is recorded.',
        suggestion: 'Add a one or two sentence purpose so every item can be checked against it.'
      }));
      return { internal: 0, flags: flags };
    }
    var s = 100;
    if (purpose.length < 25) {
      s -= 28;
      flags.push(flag('purpose', 'purpose_thin', 'moderate', {
        quote: purpose, message: 'The purpose is very short, so it is hard to tell what each item should support.',
        suggestion: 'Expand the purpose to name what you want to learn and about whom.'
      }));
    }
    if (String(p.population || '').trim() === '') {
      s -= 12;
      flags.push(flag('purpose', 'population_missing', 'minor', {
        message: 'No target population is recorded.',
        suggestion: 'Name who will answer this survey so wording and reading level can fit them.'
      }));
    }
    if (ctx.N === 0) {
      s -= 55;
      flags.push(flag('purpose', 'no_items', 'major', {
        message: 'There are no answerable questions yet to align to the purpose.',
        suggestion: 'Add questions that each map to part of your purpose.'
      }));
    } else {
      // Alignment is hard to trust when nothing names what is being measured.
      if (!ctx.hasConstructs) {
        s -= 10;
        flags.push(flag('purpose', 'no_construct_alignment', 'minor', {
          message: 'Items are not tied to any defined construct, so alignment to the purpose cannot be checked.',
          suggestion: 'Define the construct(s) this survey measures and map items to them.'
        }));
      }
      if (ctx.N < 4) {
        s -= 8;
        flags.push(flag('purpose', 'thin_coverage', 'minor', {
          message: 'There are only ' + ctx.N + ' question' + (ctx.N === 1 ? '' : 's') + ' to cover the purpose.',
          suggestion: 'Add items so the purpose is covered from more than one angle.'
        }));
      }
    }
    return { internal: clamp(s), flags: flags };
  }

  // ── Category 2: Construct clarity (8) ─────────────────────────────────────
  function catConstruct(ctx) {
    var flags = [];
    if (ctx.N === 0) return { internal: 0, flags: flags };
    if (!ctx.hasConstructs) {
      flags.push(flag('construct', 'no_constructs', 'major', {
        message: 'No constructs are defined, so it is unclear what each item is meant to measure.',
        suggestion: 'Name the one or two things this survey measures and define each in a plain sentence.'
      }));
      return { internal: 25, flags: flags };
    }
    var s = 100, undef = 0, thinDef = 0;
    ctx.constructs.forEach(function (c) {
      var def = String(c.definition || '').trim();
      if (def === '') {
        undef += 1;
        flags.push(flag('construct', 'definition_absent', 'moderate', {
          quote: c.name, message: 'The construct "' + c.name + '" has no definition.',
          suggestion: 'Define it in one plain sentence so items can be checked against it.'
        }));
      } else if (def.length < 20) {
        thinDef += 1;
        flags.push(flag('construct', 'definition_thin', 'minor', {
          quote: c.name, message: 'The definition for "' + c.name + '" is very brief.',
          suggestion: 'Expand the definition so it clearly bounds what counts as this construct.'
        }));
      }
    });
    s -= Math.min(undef * 22, 55);
    s -= Math.min(thinDef * 10, 30);

    if (ctx.unmapped > 0) {
      var share = ctx.unmapped / ctx.N;
      s -= Math.round(40 * share);
      flags.push(flag('construct', 'unmapped_items', 'moderate', {
        message: ctx.unmapped + ' of ' + ctx.N + ' items are not mapped to any construct.',
        suggestion: 'Assign each item to the construct it measures, or remove items that do not belong.'
      }));
    }
    // Coverage: a construct measured by too few items is underdeveloped.
    var thinCover = 0;
    ctx.constructs.forEach(function (c) {
      var n = ctx.byConstruct[c.name].all;
      if (n >= 1 && n < 3) {
        thinCover += (n === 1 ? 15 : 10);
        flags.push(flag('construct', 'thin_construct_coverage', 'minor', {
          message: '"' + c.name + '" is measured by only ' + n + ' item' + (n === 1 ? '' : 's') + '.',
          suggestion: 'Add items so each construct is measured from a few angles (three or more is typical).'
        }));
      }
    });
    s -= Math.min(thinCover, 36);
    return { internal: clamp(s), flags: flags };
  }

  // ── Category 3: Item quality (10) — from the per-question reviewer ─────────
  function catItem(ctx) {
    if (ctx.N === 0) return { internal: 0, flags: [] };
    var flags = ctx.review.filter(function (f) { return f.category === 'item' && f.item_ref != null; });
    return { internal: clamp(100 * (1 - perItemDemerit(flags) / ctx.N)), flags: flags };
  }

  // ── Category 4: Response-scale quality (8) — options + scale fit ──────────
  function catScale(ctx) {
    if (ctx.N === 0) return { internal: 0, flags: [] };
    var denom = ctx.closedItems.length;
    if (denom === 0) return { internal: 100, flags: [] }; // qualitative: nothing to assess
    var flags = ctx.review.filter(function (f) { return f.category === 'scale' && f.item_ref != null; });
    var internal = 100 * (1 - perItemDemerit(flags) / denom);
    // Survey-level: mixed Likert scale lengths reduce comparability.
    var likertPoints = {};
    ctx.scaled.forEach(function (it) {
      if (isLikertish(it.type)) { var pts = parseInt((it.settings || {}).points, 10); if (pts) likertPoints[pts] = 1; }
    });
    var extra = [];
    if (Object.keys(likertPoints).length > 1) {
      internal -= 12;
      extra.push(flag('scale', 'mixed_likert', 'minor', {
        message: 'Likert questions use different scale lengths.',
        suggestion: 'Use one consistent Likert length so responses compare cleanly.'
      }));
    }
    return { internal: clamp(internal), flags: flags.concat(extra) };
  }

  // ── Category 5: Survey flow & burden (5) — length sufficiency ─────────────
  function catFlow(ctx) {
    var flags = [], n = ctx.N;
    if (n === 0) {
      flags.push(flag('flow', 'empty_survey', 'critical', {
        message: 'The survey has no answerable questions.',
        suggestion: 'Add questions before running the Build Check for a meaningful score.'
      }));
      return { internal: 0, flags: flags };
    }
    var s = 100;
    if (n < 4) {
      s -= 22;
      flags.push(flag('flow', 'very_short', 'moderate', {
        message: 'The survey has only ' + n + ' question' + (n === 1 ? '' : 's') + ', which is thin for most purposes.',
        suggestion: 'Add items so the instrument covers its purpose with enough depth.'
      }));
    } else if (n < 6) {
      s -= 10;
      flags.push(flag('flow', 'short', 'minor', {
        message: 'The survey is short (' + n + ' questions).',
        suggestion: 'Confirm this is enough to cover your purpose.'
      }));
    } else if (n < 10) {
      s -= 4;
    } else if (n > 40) {
      s -= 25;
      flags.push(flag('flow', 'too_long', 'moderate', {
        message: 'The survey has ' + n + ' questions, a heavy burden for most respondents.',
        suggestion: 'Trim to the items that directly serve your purpose, or split into sections.'
      }));
    } else if (n > 30) {
      s -= 12;
      flags.push(flag('flow', 'lengthy', 'minor', {
        message: 'The survey is long (' + n + ' questions); watch respondent fatigue.',
        suggestion: 'Consider trimming or grouping into clearly paced sections.'
      }));
    }
    if (n > 15 && ctx.sections.length <= 1) {
      s -= 10;
      flags.push(flag('flow', 'no_sections', 'minor', {
        message: 'A long survey on a single page can feel overwhelming.',
        suggestion: 'Group related questions into sections or pages to ease the flow.'
      }));
    }
    return { internal: clamp(s), flags: flags };
  }

  // ── Category 6: Bias, accessibility, dignity & privacy (5) — from reviewer ─
  function catBias(ctx) {
    if (ctx.N === 0) return { internal: 0, flags: [] };
    var flags = ctx.review.filter(function (f) { return f.category === 'bias' && f.item_ref != null; });
    return { internal: clamp(100 * (1 - perItemDemerit(flags) / ctx.N)), flags: flags };
  }

  // ── Category 7: Reliability readiness (6) — format-neutral ────────────────
  function catReliability(ctx) {
    var flags = [];
    if (ctx.N === 0) return { internal: 0, flags: flags };
    if (ctx.scaled.length === 0) return { internal: 100, flags: flags }; // qualitative / non-scaled: not assessed, never penalised

    var s = 100;
    if (!ctx.hasConstructs) {
      s -= 50;
      flags.push(flag('reliability', 'no_construct_grouping', 'moderate', {
        message: 'There are scaled items but no constructs grouping them, so internal consistency cannot be assessed.',
        suggestion: 'Group related scaled items under a construct so a reliable scale can be formed.'
      }));
      return { internal: clamp(s), flags: flags };
    }
    var thin = 0, anyMulti = false;
    ctx.constructs.forEach(function (c) {
      var n = ctx.byConstruct[c.name].scaled;
      if (n >= 3) anyMulti = true;
      else if (n >= 1) {
        thin += 18;
        flags.push(flag('reliability', 'thin_scale', 'minor', {
          message: 'The scale for "' + c.name + '" has only ' + n + ' scaled item' + (n === 1 ? '' : 's') + '; internal consistency needs at least three.',
          suggestion: 'Add items to this scale if you plan to report internal consistency.'
        }));
      }
    });
    s -= Math.min(thin, 45);
    if (!anyMulti) {
      s -= 20;
      flags.push(flag('reliability', 'no_multi_item_scale', 'moderate', {
        message: 'No construct has three or more scaled items, so internal consistency cannot be estimated.',
        suggestion: 'Build at least one multi-item scale (three or more items) per construct you want to measure reliably.'
      }));
    }
    return { internal: clamp(s), flags: flags };
  }

  function readinessLabel(total) {
    if (total >= 45) return { key: 'strong',   label: 'Strong' };
    if (total >= 38) return { key: 'good',     label: 'Good' };
    if (total >= 30) return { key: 'caution',  label: 'Caution' };
    if (total >= 20) return { key: 'weak',     label: 'Weak' };
    return                  { key: 'notready', label: 'Not ready' };
  }

  // Survey-level structural concerns (no purpose, no constructs, empty/short,
  // thin scales) are advisory in SDSI — they are overall-readiness signals that
  // SIRI scores. SDSI surfaces them as flags but does NOT score them; the SDSI
  // number is purely the per-item validity average.
  var ADVISORY_SCORERS = { purpose: catPurpose, construct: catConstruct, flow: catFlow, reliability: catReliability };

  function assessBuild(project) {
    project = project || {};
    var ctx = buildContext(project);
    // The per-question validity reviewer is the single source of item-level findings.
    ctx.review = reviewQuestions(ctx);

    var allFlags = ctx.review.slice();

    // ── Per-item, five-domain scoring ─────────────────────────────────────
    // Each scored item starts at 50 (10 per domain). Each item-level flag
    // subtracts from its domain by severity. Domains and items floor at 0.
    var itemScores = ctx.ans.map(function (it, i) {
      var ref = itemRef(it, i), no = itemNo(it, i);
      var dom = { completeness: 10, clarity: 10, neutrality: 10, response: 10, dignity: 10 };
      var triggered = []; // [{ flag_key, cap }] — every flag on this item that carries a cap
      ctx.review.forEach(function (f) {
        if (f.item_ref !== ref) return;
        var d = DOMAIN_MAP[f.domain]; if (d) dom[d] -= (SEV_PENALTY[f.severity_level] || 0);
        var c = ITEM_CAP[f.flag_key];
        if (c != null) triggered.push({ flag_key: f.flag_key, cap: c });
      });
      Object.keys(dom).forEach(function (k) { if (dom[k] < 0) dom[k] = 0; });
      // Raw item SDSI = sum of the five domain scores (the quality reading).
      var raw = dom.completeness + dom.clarity + dom.neutrality + dom.response + dom.dignity;
      if (raw < 0) raw = 0;

      // ── Item-validity caps (compounding) ──────────────────────────────────
      // The most restrictive triggered cap bounds the item. Each ADDITIONAL
      // capped flaw compounds a small penalty (-4 each) so a question with
      // several serious flaws scores below one with a single correctable issue.
      // final = max(0, min(raw, mostRestrictiveCap) - 4 * (triggeredCount - 1)).
      var mr = null, mrFlag = null, additional = 0, compoundPenalty = 0, score = raw;
      if (triggered.length > 0) {
        mr = triggered[0]; triggered.forEach(function (t) { if (t.cap < mr.cap) mr = t; });
        mrFlag = mr.flag_key;
        additional = triggered.length - 1;
        compoundPenalty = additional * 4;
        score = Math.max(0, Math.min(raw, mr.cap) - compoundPenalty);
      }
      return {
        item_ref: ref, item_no: no, question_text: String(it.prompt || ''), question_type: it.type || '',
        score: score, domains: dom,
        // ── Item-cap transparency contract ──
        item_sdsi_raw_score: raw,
        triggered_caps: triggered.map(function (t) { return t.flag_key + ':' + t.cap; }),
        most_restrictive_cap: (mr ? mr.cap : null),
        additional_capped_flaw_count: additional,
        compound_cap_penalty: compoundPenalty,
        item_sdsi_final_score: score,
        // Back-compat aliases used by the screen / earlier code.
        score_raw: raw, cap_applied: (mr ? mr.cap : null), cap_flag: mrFlag
      };
    });

    var N = itemScores.length;
    var total = N > 0 ? round1(itemScores.reduce(function (a, s) { return a + s.score; }, 0) / N) : 0;
    var pct = Math.round((total / 50) * 100);
    var rawBand = readinessLabel(total);

    // ── Deployment-blocker band cap (readiness interpretation ONLY) ───────────
    // The SDSI NUMBER stays the per-item average (never capped). But the readiness
    // BAND cannot hide critical, deployment-blocking problems. A "blocker" is a
    // question carrying a Critical flag (missing/placeholder question text, missing
    // /placeholder response options — including on required sensitive items).
    var blockedQuestionIds = [], criticalFlagCount = 0;
    itemScores.forEach(function (s) {
      var crits = ctx.review.filter(function (f) { return f.item_ref === s.item_ref && f.severity_level === 'critical'; });
      if (crits.length) blockedQuestionIds.push(s.item_ref);
      criticalFlagCount += crits.length;
    });
    var blockerCount = blockedQuestionIds.length;
    var blockerPct = N > 0 ? (blockerCount / N) : 0;
    var ORDER = ['notready', 'weak', 'caution', 'good', 'strong'];
    var BAND_LABEL = { notready: 'Not ready', weak: 'Weak', caution: 'Caution' };

    // Most-severe applicable cap wins (proportion > count > any).
    var cap = null, headline = '';
    if (blockerCount >= 1) { cap = 'caution'; headline = blockerCount + ' question' + (blockerCount === 1 ? ' is' : 's are') + ' not deployment-ready.'; }
    if (blockerCount >= 3) { cap = 'weak'; }
    if (blockerPct >= 0.20) { cap = 'notready'; headline = Math.round(blockerPct * 100) + '% of questions contain critical deployment blockers.'; }

    var band = rawBand, wasCapped = false, capReason = '';
    if (cap && ORDER.indexOf(rawBand.key) > ORDER.indexOf(cap)) {
      band = { key: cap, label: BAND_LABEL[cap] };
      wasCapped = true;
      capReason = 'The SDSI score average is ' + rawBand.label + ', but the readiness band is capped at ' + band.label +
        ' because ' + (blockerCount === 1 ? 'a critical item-level blocker was' : blockerCount + ' critical item-level blockers were') + ' detected.';
    }

    // The five 10-point domain cards = the average domain score across items
    // (so the five domain averages sum to the survey total).
    var categories = SCORE_DOMAINS.map(function (d) {
      var avg = N > 0 ? round1(itemScores.reduce(function (a, s) { return a + s.domains[d.key]; }, 0) / N) : 0;
      var flagCount = ctx.review.filter(function (f) { return f.item_ref != null && DOMAIN_MAP[f.domain] === d.key; }).length;
      return {
        key: d.key, name: d.name, weight: 10,
        points: avg, internal: Math.round(avg * 10),
        warn: itemScores.some(function (s) { return s.domains[d.key] < 10; }), flagCount: flagCount
      };
    });

    // Advisory (unscored) survey-level structural flags.
    Object.keys(ADVISORY_SCORERS).forEach(function (k) {
      allFlags = allFlags.concat(ADVISORY_SCORERS[k](ctx).flags);
    });

    var strengths = categories.filter(function (c) { return c.flagCount === 0 && c.points >= 10; })
      .map(function (c) { return c.name + ' is in good shape.'; });

    var serious = allFlags.filter(function (f) {
      return f.severity === 'moderate' || f.severity === 'major' || f.severity === 'critical';
    });
    var issues = serious.map(function (f) { return f.message; });
    var recommendations = serious.filter(function (f) { return f.suggestion; }).map(function (f) { return f.suggestion; });
    var itemFlags = allFlags.filter(function (f) { return f.item_ref != null || f.item_no != null; });

    return {
      // `band`/`bandKey` are the DISPLAY (capped) band, so the existing UI shows
      // the deployment-aware readiness. `total` is always the raw average.
      total: total, max: 50, pct: pct, band: band.label, bandKey: band.key,
      blocked: false, // the Build Check never blocks launch; that is SIRI's job
      categories: categories, strengths: strengths, issues: issues,
      recommendations: recommendations, itemFlags: itemFlags, flags: allFlags,
      // Per-item SDSI scores (the unit of the model): { item_ref, item_no,
      // question_text, score 0-50, domains:{completeness,clarity,neutrality,response,dignity} }.
      itemScores: itemScores, scoredItems: itemScores.length,
      // ── Readiness-interpretation contract (band cap; number never capped) ──
      sdsi_score_numeric: total,
      sdsi_raw_band: rawBand.label,
      sdsi_display_band: band.label,
      sdsi_band_was_capped: wasCapped,
      sdsi_band_cap_reason: capReason,
      critical_flag_count: criticalFlagCount,
      deployment_blocker_count: blockerCount,
      blocked_question_ids: blockedQuestionIds,
      blocker_headline: headline,
      // Back-compat aliases (earlier field names).
      unusableItems: blockerCount, bandCapped: wasCapped,
      // The full per-question validity review (M-spec): rich, structured findings
      // for every question — flag_key, flag_label, severity_level, domain,
      // problem_summary, why_it_matters, suggested_revision, suggested_options.
      questionReview: ctx.review
    };
  }

  root.BuildCheck = {
    assess: assessBuild,
    CATEGORIES: CATEGORIES,
    DEMERIT: DEMERIT,
    readinessLabel: readinessLabel,
    spineLinked: !!SPINE
  };
})(typeof window !== 'undefined' ? window : this);

if (typeof module !== 'undefined' && module.exports) {
  module.exports = (typeof window !== 'undefined' ? window : this).BuildCheck;
}
