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

  // How much a flaw "costs" as a share of an item, by severity. Used by the
  // proportional categories (item quality, bias) so a flaw in a small survey
  // bites harder than the same flaw in a long one.
  var DEMERIT = { critical: 1.0, major: 0.85, moderate: 0.6, minor: 0.3 };

  var SCALED_TYPES = { 'Likert Scale': true, 'Rating Scale': true, 'NPS': true, 'Slider': true };
  var CHOICE_TYPES = {
    'Multiple Choice': true, 'Multiple Answers / Checkboxes': true,
    'Checkboxes': true, 'Dropdown': true, 'Ranking': true
  };
  var STRUCTURAL_TYPES = {
    'Section Text': true, 'Page Break': true, 'Thank-you Message': true,
    'Consent': true, 'Instructions': true
  };

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

  var LEADING_WORDS = ['obviously', 'clearly', 'surely', 'everyone knows', "don't you agree", 'isn\'t it', 'wouldn\'t you', 'as you know'];
  var ABSOLUTE_WORDS = ['always', 'never', 'every ', 'all of', 'none of', 'completely', 'totally', 'rarely'];
  var LOADED_WORDS = ['failure', 'foolish', 'irresponsible', 'lazy', 'stupid', 'crazy', 'suffer', 'unfortunately'];

  // ── Context shared by every category function ─────────────────────────────
  function buildContext(project) {
    var items = project.items || [];
    var ans = items.filter(function (it) { return !STRUCTURAL_TYPES[it.type]; });
    var constructs = (project.constructs || []).filter(function (c) { return String(c.name || '').trim() !== ''; });
    var scaled = ans.filter(function (it) { return SCALED_TYPES[it.type]; });
    var scaleItems = ans.filter(function (it) { return SCALED_TYPES[it.type] || CHOICE_TYPES[it.type]; });

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
      scaled: scaled, scaleItems: scaleItems,
      byConstruct: byConstruct, unmapped: unmapped,
      sections: project.sections || []
    };
  }

  function itemNo(it, i) { return it.item_no != null ? it.item_no : (i + 1); }
  function itemRef(it, i) { return it.item_ref != null ? it.item_ref : ('i' + i); }

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

  // ── Category 3: Item quality (10) — proportional to survey size ───────────
  function catItem(ctx) {
    var flags = [];
    if (ctx.N === 0) return { internal: 0, flags: flags };
    var demeritSum = 0;
    ctx.ans.forEach(function (it, i) {
      var no = itemNo(it, i), ref = itemRef(it, i), itemFlags = [];
      var prompt = String(it.prompt || '').trim();
      if (prompt === '') {
        itemFlags.push(flag('item', 'item_empty', 'major', {
          item_ref: ref, item_no: no, message: 'Question ' + no + ' has no prompt text.',
          suggestion: 'Write the question or remove the empty item.'
        }));
      } else {
        var low = lc(prompt), w = words(prompt);
        if (/\b(and|or)\b/.test(low) && !CHOICE_TYPES[it.type] && low.split(/\b(?:and|or)\b/).length === 2 && prompt.length > 30) {
          itemFlags.push(flag('item', 'double_barreled', 'moderate', {
            item_ref: ref, item_no: no, quote: prompt,
            message: 'Question ' + no + ' may ask about two things at once ("and"/"or").',
            suggestion: 'Split it into separate questions so each can be answered cleanly.'
          }));
        }
        if (LEADING_WORDS.some(function (lw) { return low.indexOf(lw) !== -1; })) {
          itemFlags.push(flag('item', 'leading', 'moderate', {
            item_ref: ref, item_no: no, quote: prompt,
            message: 'Question ' + no + ' uses leading wording that nudges the answer.',
            suggestion: 'Rephrase neutrally so it does not signal a preferred response.'
          }));
        }
        if (prompt.length > 200) {
          itemFlags.push(flag('item', 'too_long', 'minor', {
            item_ref: ref, item_no: no, message: 'Question ' + no + ' is long and may be hard to read.',
            suggestion: 'Tighten the wording to one clear sentence.'
          }));
        }
        if (w.length > 0 && w.length < 3) {
          itemFlags.push(flag('item', 'fragment', 'minor', {
            item_ref: ref, item_no: no, quote: prompt,
            message: 'Question ' + no + ' is very short and may be unclear.',
            suggestion: 'Make sure it reads as a complete, answerable question.'
          }));
        }
      }
      demeritSum += worst(itemFlags);
      flags = flags.concat(itemFlags);
    });
    var internal = clamp(100 * (1 - demeritSum / ctx.N));
    return { internal: internal, flags: flags };
  }

  // ── Category 4: Response-scale quality (8) ────────────────────────────────
  function catScale(ctx) {
    var flags = [];
    if (ctx.N === 0) return { internal: 0, flags: flags };
    if (ctx.scaleItems.length === 0) return { internal: 100, flags: flags }; // qualitative: nothing to assess
    var demeritSum = 0, likertPoints = {};
    ctx.scaleItems.forEach(function (it, i) {
      var no = itemNo(it, i), ref = itemRef(it, i), s = it.settings || {}, itemFlags = [];
      if (CHOICE_TYPES[it.type] && (it.options || []).length < 2) {
        itemFlags.push(flag('scale', 'too_few_options', 'moderate', {
          item_ref: ref, item_no: no, message: 'Question ' + no + ' is a choice question with fewer than two options.',
          suggestion: 'Add response options so respondents have real choices.'
        }));
      }
      if (it.type === 'Rating Scale' && !s.max) {
        itemFlags.push(flag('scale', 'rating_no_max', 'minor', {
          item_ref: ref, item_no: no, message: 'Question ' + no + ' is a rating scale with no maximum set.',
          suggestion: 'Set the rating range so the scale is consistent.'
        }));
      }
      if (it.type === 'Likert Scale') { var pts = parseInt(s.points, 10); if (pts) likertPoints[pts] = 1; }
      demeritSum += worst(itemFlags);
      flags = flags.concat(itemFlags);
    });
    var internal = 100 * (1 - demeritSum / ctx.scaleItems.length);
    if (Object.keys(likertPoints).length > 1) {
      internal -= 12;
      flags.push(flag('scale', 'mixed_likert', 'minor', {
        message: 'Likert questions use different scale lengths.',
        suggestion: 'Use one consistent Likert length so responses compare cleanly.'
      }));
    }
    return { internal: clamp(internal), flags: flags };
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

  // ── Category 6: Bias, accessibility & clarity (5) — proportional ──────────
  function catBias(ctx) {
    var flags = [];
    if (ctx.N === 0) return { internal: 0, flags: flags };
    var demeritSum = 0;
    ctx.ans.forEach(function (it, i) {
      var prompt = String(it.prompt || '');
      if (prompt.trim() === '') return;
      var no = itemNo(it, i), ref = itemRef(it, i), low = lc(prompt), itemFlags = [];
      if (LOADED_WORDS.some(function (wd) { return low.indexOf(wd) !== -1; })) {
        itemFlags.push(flag('bias', 'loaded_language', 'moderate', {
          item_ref: ref, item_no: no, quote: prompt,
          message: 'Question ' + no + ' uses emotionally loaded wording.',
          suggestion: 'Use neutral, respectful language so respondents can answer honestly.'
        }));
      }
      if (ABSOLUTE_WORDS.some(function (wd) { return low.indexOf(wd) !== -1; })) {
        itemFlags.push(flag('bias', 'absolute_terms', 'minor', {
          item_ref: ref, item_no: no, quote: prompt,
          message: 'Question ' + no + ' uses an absolute term (e.g. "always"/"never") that is hard to answer truthfully.',
          suggestion: 'Soften absolutes so the question fits a range of real experiences.'
        }));
      }
      if (/\bnot\b.*\b(no|never|none|nothing|cannot|n't)\b/.test(low) || /n't\b.*\bnot\b/.test(low)) {
        itemFlags.push(flag('bias', 'double_negative', 'minor', {
          item_ref: ref, item_no: no, quote: prompt,
          message: 'Question ' + no + ' may contain a double negative, which is hard to read.',
          suggestion: 'Rephrase positively so the meaning is immediately clear.'
        }));
      }
      demeritSum += worst(itemFlags);
      flags = flags.concat(itemFlags);
    });
    return { internal: clamp(100 * (1 - demeritSum / ctx.N)), flags: flags };
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
    if (total >= 45)   return { key: 'strong',     label: 'Strong design' };
    if (total >= 40)   return { key: 'solid',      label: 'Solid design, minor improvements' };
    if (total >= 35)   return { key: 'developing', label: 'Developing design, revise before launch' };
    if (total >= 30)   return { key: 'weak',       label: 'Weak design, major revision needed' };
    return                    { key: 'notready',   label: 'Not ready for launch review' };
  }

  var SCORERS = {
    purpose: catPurpose, construct: catConstruct, item: catItem, scale: catScale,
    flow: catFlow, bias: catBias, reliability: catReliability
  };

  function assessBuild(project) {
    project = project || {};
    var ctx = buildContext(project);

    var allFlags = [];
    var categories = CATEGORIES.map(function (cat) {
      var out = SCORERS[cat.key](ctx);
      allFlags = allFlags.concat(out.flags);
      var points = round1((out.internal / 100) * cat.weight);
      var hasModeratePlus = out.flags.some(function (f) {
        return f.severity === 'moderate' || f.severity === 'major' || f.severity === 'critical';
      });
      return {
        key: cat.key, name: cat.name, weight: cat.weight,
        points: points, internal: Math.round(out.internal),
        warn: hasModeratePlus || out.internal < 80, flagCount: out.flags.length
      };
    });

    var total = Math.min(50, Math.max(0, round1(categories.reduce(function (a, c) { return a + c.points; }, 0))));
    var pct = Math.round((total / 50) * 100);
    var band = readinessLabel(total);

    var strengths = categories.filter(function (c) { return c.flagCount === 0 && c.internal >= 95; })
      .map(function (c) { return c.name + ' is in good shape.'; });

    var serious = allFlags.filter(function (f) {
      return f.severity === 'moderate' || f.severity === 'major' || f.severity === 'critical';
    });
    var issues = serious.map(function (f) { return f.message; });
    var recommendations = serious.filter(function (f) { return f.suggestion; }).map(function (f) { return f.suggestion; });
    var itemFlags = allFlags.filter(function (f) { return f.item_ref != null || f.item_no != null; });

    return {
      total: total, max: 50, pct: pct, band: band.label, bandKey: band.key,
      blocked: false, // the Build Check never blocks launch; that is SIRI's job
      categories: categories, strengths: strengths, issues: issues,
      recommendations: recommendations, itemFlags: itemFlags, flags: allFlags
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
