/* ════════════════════════════════════════════════════════════════════════
   SDSI — Reliability Readiness — the five factory-lens specifications
   ────────────────────────────────────────────────────────────────────────
   Reliability Readiness is 35 of SDSI's 100 points. It is a PRE-DATA review:
   it asks whether the survey is *designed* to produce consistent, stable,
   interpretable responses before any data exists. It shares the same
   deterministic factory as the validity lenses (validity-lens-engine.js);
   only the check vocabulary, blockers, and SDSI weight differ.

   ╔══════════════════════════════════════════════════════════════════════╗
   ║  ALPHA FENCE — NO RESPONSE-DATA STATISTICS.                            ║
   ║  These lenses never use Cronbach's alpha, McDonald's omega,           ║
   ║  item-total or inter-item correlations, factor analysis, or any       ║
   ║  statistic computed from responses. Those belong to RSSI, AFTER data  ║
   ║  collection. The AI reviews DESIGN and CONCEPTUAL structure only.     ║
   ╚══════════════════════════════════════════════════════════════════════╝

   Five lenses (sum = 35 = Reliability Readiness):
     scale_structure_readiness 8 · item_clarity 8 ·
     response_scale_consistency 7 · redundancy_balance 6 ·
     administration_consistency 6

   Blocker philosophy (per design brief): most reliability-readiness problems
   LOWER the score and produce recommendations — they do NOT block launch.
   Blockers are reserved for cases where the survey cannot support a CLAIMED
   reliability interpretation at all:
     • no_declared_scale_structure          (Scale Structure)
     • scale_score_not_defensible           (Scale Structure)
     • inconsistent_required_scale_exposure (Administration Consistency)

   Anti-double-count: if a problem is already scored under Validity Readiness,
   do not subtract again here unless there is a DISTINCT reliability problem.
   (e.g. a scale that does not match its stem = Response-Option Validity;
   similar items using different anchors within a scale = Response Scale
   Consistency here.)
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  var SPECS = {

    // ── 1. Scale Structure Readiness (8) ────────────────────────────────
    // Does the survey have a DECLARED and DEFENSIBLE item structure before any
    // data exists? Not a statistical-reliability check. Branching/skip exposure
    // is deliberately NOT here — it lives in Administration Consistency, which
    // asks whether respondents are exposed to the structure consistently.
    scale_structure_readiness: {
      key: 'scale_structure_readiness', name: 'Scale Structure Readiness', noun: 'scale-structure', weight: 8,
      intro: 'Does the survey have a declared and defensible item structure before any data exists? This lens asks whether intended scales/subscales are declared, whether each has enough items to support later consistency analysis, whether single- and two-item measures are handled honestly, and whether the scoring/reporting plan matches the structure. It is NOT a statistical-reliability check — no alpha, omega, item-total or inter-item correlations, or factor analysis. Whether respondents are *exposed* to that structure consistently (branching/skip logic) is handled by Administration Consistency, not here.',
      reviewerPrompt: 'Before scoring this lens, declare how the survey intends to organize items into scales, subscales, composites, or single indicators. If the survey does not provide a structure, enter the reviewer-inferred structure and mark it as inferred. If no defensible structure can be declared, leave the scale structure blank and flag structure_undeclared.',
      checks: {
        structure_undeclared:       'The survey does not declare which items belong to which scale, subscale, or single-indicator measure',
        single_item_scale_claim:    'A one-item measure is presented, labeled, or reported as if it were a scale rather than a single indicator',
        two_item_scale_uncautioned: 'A two-item scale is presented as a full scale without a cautionary note about its thin structure',
        insufficient_items_for_scale:'A declared scale/subscale has three or more items but still appears too thin, narrow, or underdeveloped for the intended composite interpretation',
        score_structure_mismatch:   'The planned overall, subscale, or composite reporting plan does not match the declared item structure',
        subscale_structure_unclear: 'Scales/subscales are declared, but boundaries, labels, or item assignments are unclear enough that later interpretation would be unstable'
      },
      severityHints: {
        structure_undeclared: 'major', single_item_scale_claim: 'major', two_item_scale_uncautioned: 'moderate',
        insufficient_items_for_scale: 'major', score_structure_mismatch: 'major', subscale_structure_unclear: 'moderate'
      },
      severityGuidance: 'Use minor only when the structure issue is cosmetic and does not affect interpretation. Use moderate when the structure is usable but needs caution, clearer labeling, or better documentation. Use major when the structure weakens or threatens a planned scale, subscale, or composite interpretation. Use critical only when the structure problem supports a high-stakes, published, or decision-making composite score that the item structure cannot defend.',
      contextFields: [
        { key: 'construct',             label: 'Primary construct', type: 'text' },
        { key: 'definition',            label: 'Construct definition', type: 'textarea' },
        { key: 'purpose',               label: 'Stated survey purpose', type: 'textarea' },
        { key: 'respondents',           label: 'Intended respondent group', type: 'text' },
        { key: 'declared_scales',       label: 'Declared scales/subscales (survey-provided)', type: 'list' },
        { key: 'reviewer_scales',       label: 'Reviewer-declared scales/subscales (if the survey provides none)', type: 'list' },
        { key: 'single_item_indicators',label: 'Single-item indicators, if any', type: 'list' },
        { key: 'two_item_scales',       label: 'Two-item scales, if any', type: 'list' },
        { key: 'score_reporting',       label: 'Planned score reporting', type: 'select',
          options: [
            { value: 'overall',           label: 'Overall composite score' },
            { value: 'subscale',          label: 'Subscale scores' },
            { value: 'both',              label: 'Overall and subscale scores' },
            { value: 'item_level',        label: 'Item-level only (no composite)' },
            { value: 'single_indicators', label: 'Single indicators only' }
          ] },
        { key: 'intended_use', label: 'Intended use of scores', type: 'text' },
        { key: 'stakes',       label: 'Stakes of the decisions the results will drive', type: 'select',
          options: [
            { value: 'low',      label: 'Low-stakes' },
            { value: 'moderate', label: 'Moderate-stakes' },
            { value: 'high',     label: 'High-stakes' }
          ] }
      ],
      blockers: [
        // no_declared_scale_structure: structure_undeclared accepted AND no
        // structure from EITHER source (survey declared_scales OR reviewer_scales)
        // AND a composite (overall/subscale/both) is intended. A reviewer-inferred
        // structure clears it (per the reviewer prompt); item-level / single-
        // indicator reporting clears it (no composite to defend).
        { key: 'no_declared_scale_structure',
          label: 'No scale structure declared (survey or reviewer) while a composite score is intended — a reliability interpretation cannot be claimed',
          test: function (f, mitigations, context) {
            if (f.check !== 'structure_undeclared') return false;
            var hasStructure = !!(context && (
              (Array.isArray(context.declared_scales) && context.declared_scales.length > 0) ||
              (Array.isArray(context.reviewer_scales) && context.reviewer_scales.length > 0)
            ));
            var compositeIntended = !!(context && (context.score_reporting === 'overall' || context.score_reporting === 'subscale' || context.score_reporting === 'both'));
            return !hasStructure && compositeIntended;
          } },
        // scale_score_not_defensible: score_structure_mismatch accepted AND a
        // composite (overall/subscale/both) is planned. Item-level / single-
        // indicator reporting clears it.
        { key: 'scale_score_not_defensible',
          label: 'A composite score is planned but the item structure cannot support it — revise the structure or drop the composite before launch',
          test: function (f, mitigations, context) {
            if (f.check !== 'score_structure_mismatch') return false;
            return !!(context && (context.score_reporting === 'overall' || context.score_reporting === 'subscale' || context.score_reporting === 'both'));
          } }
      ],
      sample: {
        title: 'Sample: staff wellbeing survey reporting subscale scores',
        context: { construct: 'Staff wellbeing', definition: 'Staff experience of workload, belonging, and support at work.',
          purpose: 'Identify wellbeing areas to improve.', respondents: 'School staff',
          declared_scales: ['Workload', 'Belonging', 'Support'],
          single_item_indicators: [], two_item_scales: ['Support'],
          score_reporting: 'subscale', intended_use: 'Internal planning', stakes: 'moderate' },
        flags: [
          { check: 'single_item_scale_claim', item_ref: 'belonging',
            quote: 'Belonging subscale = 1 item, reported as a subscale score',
            severity: 'major',
            rationale: 'A one-item measure is reported as a "Belonging subscale" rather than as a single indicator, so it is claimed as a scale it cannot support.',
            suggested_revision: 'Add belonging items, or relabel belonging as a single-indicator item rather than a subscale score.' },
          { check: 'two_item_scale_uncautioned', item_ref: 'support',
            quote: 'Support subscale = 2 items',
            severity: 'moderate',
            rationale: 'A two-item subscale is reported like a full scale with no caution about the thin structure.',
            suggested_revision: 'Add support items, or present the support subscale with a limitation note about its two-item structure.' }
        ]
      }
    },

    // ── 2. Item Clarity / Wording Consistency (8) ───────────────────────
    // Will respondents in the intended group interpret each item CONSISTENTLY
    // before any data exists? This is about clarity and consistency of wording —
    // NOT construct fit (Item-to-Construct Alignment), response options
    // (Response-Option Validity), dignity (Dignity / Framing), redundancy
    // (Redundancy Balance), or any post-data statistic (RSSI). Readability is
    // scored ONCE: if it blocks participation it belongs to Access; if it makes
    // interpretation vary among those who can participate, it belongs here.
    item_clarity: {
      key: 'item_clarity', name: 'Item Clarity / Wording Consistency', noun: 'item-clarity', weight: 8,
      intro: 'Will respondents in the intended group interpret each item consistently? This is reliability readiness because unclear or inconsistent wording produces inconsistent respondent interpretation. It is not about whether the item measures the right construct (Item-to-Construct Alignment), the response options (Response-Option Validity), dignity (Dignity / Framing), or redundancy (Redundancy Balance). Readability is scored once: an access barrier belongs to Access; wording that makes interpretation vary among those who can participate belongs here.',
      reviewerPrompt: 'Before scoring this lens, review whether respondents in the intended group are likely to interpret each item consistently. Pay attention to vague wording, unclear referents, shifting timeframes, confusing negation, reading demand, and whether parallel items use consistent phrasing. If a readability issue prevents participation, surface it under Access rather than double-scoring it here.',
      checks: {
        vague_wording:               'The item uses broad, undefined, or imprecise language that different respondents may interpret differently',
        inconsistent_terminology:    'The survey uses different terms for the same person, group, setting, behavior, or concept across similar items',
        unclear_referent:            'The item does not clearly identify who or what the question is referring to',
        shifting_timeframe:          'The timeframe for answering is missing, unclear, or shifts across similar items without explanation',
        excessive_complexity:        'The item’s sentence structure, length, or syntax is complex enough to make consistent interpretation difficult',
        confusing_negation:          'The item uses negative wording, double negatives, or reversal phrasing that respondents are likely to misread',
        reading_level_mismatch:      'The vocabulary or reading demand is above the intended respondent group in a way that risks inconsistent interpretation among respondents',
        inconsistent_parallel_phrasing:'Items intended to function together use noticeably different phrasing structures, making it unclear whether response differences reflect the construct or the wording'
      },
      severityHints: {
        vague_wording: 'moderate', inconsistent_terminology: 'moderate', unclear_referent: 'moderate',
        shifting_timeframe: 'moderate', excessive_complexity: 'moderate', confusing_negation: 'moderate',
        reading_level_mismatch: 'moderate', inconsistent_parallel_phrasing: 'moderate'
      },
      severityGuidance: 'Use minor when the wording issue is isolated and easy to correct without affecting interpretation. Use moderate when the wording issue may cause different respondents to interpret the item differently. Use major when the wording issue affects a required item, a central scale item, or multiple items in a way that threatens score consistency. Use critical only when wording creates severe interpretation risk in a high-stakes use case — in most cases, severe readability barriers should be handled under Access rather than this lens. Do not flag reverse-worded items simply because they are reverse-worded; flag them only when the wording is likely to be misread. Do not flag advanced vocabulary simply because it is advanced; flag it only when it is above the expected respondent group or likely to create inconsistent interpretation.',
      contextFields: [
        { key: 'construct',       label: 'Primary construct', type: 'text' },
        { key: 'definition',      label: 'Construct definition', type: 'textarea' },
        { key: 'purpose',         label: 'Stated survey purpose', type: 'textarea' },
        { key: 'respondents',     label: 'Intended respondent group', type: 'text' },
        { key: 'age_grade_band',  label: 'Respondent age/grade band', type: 'text' },
        { key: 'reading_level',   label: 'Expected reading level', type: 'text' },
        { key: 'language',        label: 'Language of administration', type: 'text' },
        { key: 'respondent_type', label: 'Respondent type', type: 'select',
          options: [
            { value: 'students', label: 'Students' },
            { value: 'families', label: 'Families' },
            { value: 'staff',    label: 'Staff' },
            { value: 'mixed',    label: 'Mixed respondents' }
          ] },
        { key: 'parallel_items_intended', label: 'Are items intended to be parallel within scales?', type: 'select',
          options: [
            { value: 'yes', label: 'Yes' },
            { value: 'no',  label: 'No' }
          ] },
        { key: 'reverse_worded_intentional', label: 'Are reverse-worded items used intentionally?', type: 'select',
          options: [
            { value: 'yes', label: 'Yes — by design' },
            { value: 'no',  label: 'No' }
          ] },
        { key: 'stakes', label: 'Stakes of the decisions the results will drive', type: 'select',
          options: [
            { value: 'low',      label: 'Low-stakes' },
            { value: 'moderate', label: 'Moderate-stakes' },
            { value: 'high',     label: 'High-stakes' }
          ] },
        // Infrastructure for the widespread_clarity_risk warning (denominator).
        // Supplied by the client from the proposal's item list; the warning is
        // silent without it.
        { key: 'item_count', label: 'Number of items reviewed', type: 'number' }
      ],
      // No launch blocker: clarity problems lower the score and generate
      // revision recommendations. A report-level caution fires when a large
      // share of reviewed items have clarity flags (needs context.item_count).
      blockers: [],
      warnings: [
        { key: 'widespread_clarity_risk',
          label: 'Widespread clarity risk: many items may be interpreted inconsistently across respondents.',
          test: function (accepted, context) {
            var n = context && Number(context.item_count);
            if (!(n > 0)) return false;
            var flaggedItems = {};
            accepted.forEach(function (f) { if (f.item_ref) flaggedItems[f.item_ref] = true; });
            return (Object.keys(flaggedItems).length / n) > 0.4;
          } }
      ],
      sample: {
        title: 'Sample: middle-school climate survey',
        context: { respondents: 'Students in grades 6–8', reading_level: 'Grade 6', item_count: 10 },
        flags: [
          { check: 'confusing_negation', item_ref: 'q5',
            quote: 'I do not feel that school is never a place where I am unsupported.',
            severity: 'major',
            rationale: 'Triple negative; respondents will not reliably parse whether agreement means supported or unsupported.',
            suggested_revision: 'Rewrite positively: “I feel supported at school.”' },
          { check: 'shifting_timeframe', item_ref: 'q8',
            quote: 'How often do you feel safe? (earlier items asked “this week”)',
            severity: 'moderate',
            rationale: 'The timeframe shifts from “this week” to unspecified, so respondents answer over different windows.',
            suggested_revision: 'State a consistent timeframe (e.g. “in the past week”) across items.' }
        ]
      }
    },

    // ── 3. Response Scale Consistency (7) ───────────────────────────────
    // Do similar items use consistent response formats and anchors?
    // Inconsistent formats add avoidable measurement noise.
    response_scale_consistency: {
      key: 'response_scale_consistency', name: 'Response Scale Consistency', noun: 'response-scale', weight: 7,
      intro: 'Do similar items use consistent response formats and anchors? This is reliability readiness because inconsistent response formats within a scale add avoidable measurement noise. It is distinct from Response-Option Validity: a scale that does not match its stem is a validity problem; similar items using DIFFERENT anchors within the same scale is a consistency problem scored here. Do not flag response-scale variation simply because different sections use different scales — flag it when similar items, sibling items, or items intended to be combined into the same scale use inconsistent response formats without clear rationale.',
      reviewerPrompt: 'Before scoring this lens, review whether items intended to function together use response formats consistently. Pay attention to scale type, number of response points, anchor labels, direction, midpoint meaning, N/A or skip-option handling, and whether reverse-coded or reverse-direction items are clearly intentional and documented.',
      checks: {
        mixed_scale_types:       'Items intended to function within the same scale/subscale use different response formats (agreement, frequency, satisfaction, importance, confidence, etc.)',
        inconsistent_point_count:'Similar or combinable items use a different number of response points (e.g. mixing 4-, 5-, and 7-point scales)',
        inconsistent_anchor_labels:'Similar or combinable items use different anchor labels for the same response idea',
        inconsistent_direction:  'Scale direction changes across similar items without clear design rationale or documentation',
        unclear_midpoint:        'The midpoint is unlabeled, inconsistently labeled, or used so its meaning is unclear across similar items',
        inconsistent_na_handling:'Not applicable, don’t know, skip, decline, or prefer-not-to-answer options are offered inconsistently across similar items',
        reverse_coding_unclear:  'A reverse-coded or reverse-direction item appears intentional, but the design does not clearly document or signal the reversal in a way that reduces respondent confusion and scoring risk'
      },
      severityHints: {
        mixed_scale_types: 'major', inconsistent_point_count: 'moderate', inconsistent_anchor_labels: 'moderate',
        inconsistent_direction: 'major', unclear_midpoint: 'minor', inconsistent_na_handling: 'moderate',
        reverse_coding_unclear: 'moderate'
      },
      severityGuidance: 'Use minor when the inconsistency is isolated and unlikely to affect interpretation. Use moderate when the inconsistency may add response noise or require clearer documentation. Use major when the inconsistency affects items intended to be combined into a scale or subscale. Reserve critical only when a response-scale inconsistency is tied to a high-stakes reporting use and creates severe scoring risk — in most cases the blocker-level issue belongs to Scale Structure Readiness, not here.',
      contextFields: [
        { key: 'construct',        label: 'Primary construct', type: 'text' },
        { key: 'definition',       label: 'Construct definition', type: 'textarea' },
        { key: 'declared_scales',  label: 'Declared scales/subscales (one per line)', type: 'list' },
        { key: 'item_scale_map',   label: 'Item-to-scale map, if available', type: 'textarea' },
        { key: 'item_types',       label: 'Response formats present (agreement, frequency, satisfaction, etc.)', type: 'list' },
        { key: 'point_counts',     label: 'Number of response points used (e.g. 4, 5, 7)', type: 'list' },
        { key: 'anchor_labels',    label: 'Anchor labels used', type: 'list' },
        { key: 'reverse_coded_intentional', label: 'Are reverse-coded items used intentionally?', type: 'select',
          options: [ { value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' } ] },
        { key: 'na_options_used',  label: 'Are N/A, don’t know, skip, decline, or prefer-not-to-answer options used?', type: 'select',
          options: [ { value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' } ] },
        { key: 'score_reporting',  label: 'Planned score reporting', type: 'select',
          options: [
            { value: 'overall',           label: 'Overall composite' },
            { value: 'subscale',          label: 'Subscale scores' },
            { value: 'both',              label: 'Both overall and subscale' },
            { value: 'item_level',        label: 'Item-level only' },
            { value: 'single_indicators', label: 'Single indicators only' }
          ] },
        { key: 'stakes', label: 'Stakes level', type: 'select',
          options: [
            { value: 'low',      label: 'Low-stakes' },
            { value: 'moderate', label: 'Moderate-stakes' },
            { value: 'high',     label: 'High-stakes' }
          ] },
        // Infrastructure for the widespread_scale_format_risk warning
        // (denominator). Supplied from the proposal's item list; the warning is
        // silent without it.
        { key: 'item_count', label: 'Number of items reviewed', type: 'number' }
      ],
      // No launch blocker: response-scale problems lower the score and generate
      // recommendations. If a scoring plan becomes indefensible, Scale Structure
      // Readiness owns that gate (scale_score_not_defensible) — do not
      // double-count it here.
      blockers: [],
      warnings: [
        { key: 'widespread_scale_format_risk',
          label: 'Widespread response-format inconsistency: the survey may need a scale-format redesign rather than isolated edits.',
          test: function (accepted, context) {
            var n = context && Number(context.item_count);
            if (!(n > 0)) return false;
            var flaggedItems = {};
            accepted.forEach(function (f) { if (f.item_ref) flaggedItems[f.item_ref] = true; });
            return (Object.keys(flaggedItems).length / n) > 0.4;
          } }
      ],
      sample: {
        title: 'Sample: engagement scale with mixed formats',
        context: { declared_scales: ['Engagement'], item_types: ['agreement', 'frequency'], item_count: 8 },
        flags: [
          { check: 'mixed_scale_types', item_ref: 'q3',
            quote: 'Engagement items q1–q2 use Strongly disagree…Strongly agree; q3 uses Never…Always',
            severity: 'major',
            rationale: 'Mixing agreement and frequency formats within one scale makes the items unsafe to combine into a single engagement score.',
            suggested_revision: 'Put all engagement items on one consistent response format.' },
          { check: 'inconsistent_direction', item_ref: 'q6',
            quote: 'q6 reverses the scale (Always…Never) with no reverse-coding note',
            severity: 'major',
            rationale: 'Direction flips on a similar item with no indication, so raw responses will be inconsistent across the scale.',
            suggested_revision: 'Keep a consistent direction, or clearly mark and reverse-code intended reversals.' }
        ]
      }
    },

    // ── 4. Redundancy Balance (6) ───────────────────────────────────────
    // Within a scale, items should be related enough to measure the same
    // construct but not duplicates. PRE-DATA, CONCEPTUAL similarity only —
    // never inter-item correlations.
    redundancy_balance: {
      key: 'redundancy_balance', name: 'Redundancy Balance', noun: 'redundancy', weight: 6,
      intro: 'Within a scale, are items related enough to measure the same construct but not so repetitive that they become duplicates? This is a PRE-DATA review of CONCEPTUAL similarity only — it never calculates inter-item correlations. Too much repetition inflates apparent consistency; too little overlap means the items are not measuring one thing.',
      reviewerPrompt: 'Before scoring this lens, review whether items within each scale have balanced conceptual overlap. Items should be related enough to belong together but varied enough to avoid duplication or one-note measurement. Do not use empirical reliability statistics. This is a pre-data conceptual review of item-pool balance.',
      checks: {
        duplicate_items:               'Two or more items ask essentially the same question with only trivial wording differences',
        excessive_paraphrasing:        'Several items reword the same idea without adding meaningful conceptual coverage',
        narrow_item_cluster:           'A scale places too much weight on one narrow facet of the construct, making that facet dominate',
        low_item_variety:              'A scale lacks enough conceptual variety to represent the intended dimension or construct in a balanced way',
        insufficient_conceptual_overlap:'Items within a scale share too little conceptual relationship to support interpretation as one scale',
        unrelated_sibling_item:        'One item appears conceptually disconnected from the other items in the same scale, even if it may be relevant elsewhere in the survey',
        scale_scatter_risk:            'The scale covers too many loosely connected ideas, creating an unfocused item pool that may not support a coherent reliability interpretation later'
      },
      severityHints: {
        duplicate_items: 'moderate', excessive_paraphrasing: 'minor', narrow_item_cluster: 'moderate',
        low_item_variety: 'moderate', insufficient_conceptual_overlap: 'major', unrelated_sibling_item: 'major',
        scale_scatter_risk: 'moderate'
      },
      severityGuidance: 'Use minor when the redundancy or variety issue is isolated and easy to fix. Use moderate when the issue affects item balance within a scale but the scale remains interpretable. Use major when items are so conceptually loose, disconnected, or poorly balanced that the intended scale interpretation is threatened. Reserve critical only for rare high-stakes cases where a scale drives consequential decisions and the item pool is conceptually indefensible — in most cases keep this a score penalty or advisory warning, not a blocker. Judge conceptual meaning only — never a correlation, alpha, item-total, inter-item statistic, factor structure, or loading.',
      contextFields: [
        { key: 'construct',        label: 'Primary construct', type: 'text' },
        { key: 'definition',       label: 'Construct definition', type: 'textarea' },
        { key: 'declared_scales',  label: 'Declared scales/subscales (one per line)', type: 'list' },
        { key: 'item_scale_map',   label: 'Item-to-scale map', type: 'textarea' },
        { key: 'items_per_scale',  label: 'Items assigned to each scale/subscale', type: 'textarea' },
        { key: 'score_reporting',  label: 'Intended score reporting', type: 'select',
          options: [
            { value: 'overall',           label: 'Overall composite' },
            { value: 'subscale',          label: 'Subscale scores' },
            { value: 'both',              label: 'Both overall and subscale' },
            { value: 'item_level',        label: 'Item-level only' },
            { value: 'single_indicators', label: 'Single indicators only' }
          ] },
        { key: 'expected_facets',  label: 'Expected facets within each scale', type: 'textarea' },
        { key: 'scale_breadth',    label: 'Is the scale intended to be broad or narrow?', type: 'select',
          options: [ { value: 'broad', label: 'Broad' }, { value: 'narrow', label: 'Narrow' } ] },
        { key: 'stakes', label: 'Stakes level', type: 'select',
          options: [
            { value: 'low',      label: 'Low-stakes' },
            { value: 'moderate', label: 'Moderate-stakes' },
            { value: 'high',     label: 'High-stakes' }
          ] },
        // Infrastructure for the widespread_redundancy_risk warning
        // (denominator). Supplied from the proposal's item list; the warning is
        // silent without it.
        { key: 'item_count', label: 'Number of items reviewed', type: 'number' }
      ],
      // No launch blocker: redundancy/scatter problems lower the score and
      // generate recommendations. If the score structure itself is indefensible,
      // Scale Structure Readiness owns that gate — do not double-count here.
      blockers: [],
      warnings: [
        { key: 'widespread_redundancy_risk',
          label: 'Widespread redundancy or scatter: the survey likely needs item-pool redesign rather than isolated item edits.',
          test: function (accepted, context) {
            var n = context && Number(context.item_count);
            if (!(n > 0)) return false;
            var flaggedItems = {};
            accepted.forEach(function (f) { if (f.item_ref) flaggedItems[f.item_ref] = true; });
            return (Object.keys(flaggedItems).length / n) > 0.4;
          } }
      ],
      sample: {
        title: 'Sample: belonging scale with duplicate items',
        context: { declared_scales: ['Belonging'], items_per_scale: 'Belonging: 6 items', item_count: 6 },
        flags: [
          { check: 'duplicate_items', item_ref: 'q2',
            quote: 'q1 “I feel I belong at school.” / q2 “I feel like I belong here at school.”',
            severity: 'moderate',
            rationale: 'q1 and q2 are near-duplicates; the pair adds repetition rather than coverage of belonging.',
            suggested_revision: 'Keep one and replace the other with a distinct facet (e.g. peer acceptance).' },
          { check: 'low_item_variety', item_ref: 'belonging',
            quote: 'All six belonging items ask about feeling accepted by peers',
            severity: 'moderate',
            rationale: 'The scale covers only peer acceptance, leaving adult belonging and participation unmeasured — a one-note scale.',
            suggested_revision: 'Add items covering other facets of belonging for variety.' }
        ]
      }
    },

    // ── 5. Administration Consistency for Reliability (6) ───────────────
    // Are survey conditions standardized enough to support consistent
    // responses? Scoped to consistency-of-conditions only — broader fielding,
    // consent, safety, and launch logistics belong to Administration Readiness.
    administration_consistency: {
      key: 'administration_consistency', name: 'Administration Consistency for Reliability', noun: 'administration-consistency', weight: 6,
      intro: 'Are the conditions under which respondents answer standardized enough to support consistent responses? This lens belongs to Reliability Readiness only when a problem affects the CONSISTENCY of response conditions. Broader fielding, consent, safety, timing, and launch logistics are handled later by Administration Readiness (15 points).',
      reviewerPrompt: 'Before scoring this lens, review whether respondents will receive consistent instructions, timeframes, roles, modes, versions, and item exposure conditions. Pay special attention to branching, skip logic, required/optional settings, and whether respondents receive enough common items to support the intended score interpretation.',
      checks: {
        inconsistent_instructions:  'Instructions differ across sections, versions, or respondent paths in ways that may change how items are answered',
        unstable_timeframe:         'The survey does not provide a stable reference window for comparable items, or different sections use inconsistent timeframes without explanation',
        unclear_respondent_role:    'The survey does not clearly specify the perspective respondents should use when answering (self, parent/guardian, teacher, staff, student, observer)',
        uncontrolled_mode_variation:'Different modes or versions are used without controls to preserve consistency (paper vs. online, read-aloud vs. self-administered, translated vs. original, randomized vs. fixed order)',
        inconsistent_required_logic:'Required and optional settings differ across similar items or scale items without clear rationale',
        uneven_scale_exposure:      'Skip, display, or branching logic causes different respondents to see different subsets of items within a scale or subscale',
        insufficient_common_items:  'The administration or branching design may leave too few shared items across respondents to support the intended overall, subscale, or composite score'
      },
      severityHints: {
        inconsistent_instructions: 'moderate', unstable_timeframe: 'moderate', unclear_respondent_role: 'moderate',
        uncontrolled_mode_variation: 'major', inconsistent_required_logic: 'moderate', uneven_scale_exposure: 'major',
        insufficient_common_items: 'major'
      },
      severityGuidance: 'Use minor when the administration inconsistency is isolated and unlikely to affect interpretation. Use moderate when the inconsistency may affect how respondents answer but does not threaten the intended score. Use major when the inconsistency affects scale items, respondent exposure, modes, versions, or required logic in a way that threatens score consistency. Reserve critical only when a high-stakes score or decision would rest on inconsistent administration conditions — in most cases keep the issue a score penalty or the existing orthogonal blocker.',
      contextFields: [
        { key: 'respondents',      label: 'Intended respondent group', type: 'text' },
        { key: 'respondent_roles', label: 'Respondent roles (one per line)', type: 'list' },
        { key: 'modes',            label: 'Administration modes/versions (one per line)', type: 'list' },
        { key: 'version_types',    label: 'Translated, read-aloud, paper, online, mobile, or alternate versions used (one per line)', type: 'list' },
        { key: 'has_branching',    label: 'Does the survey use branching or skip logic?', type: 'select',
          options: [
            { value: 'yes', label: 'Yes' },
            { value: 'no',  label: 'No' }
          ] },
        { key: 'required_logic',   label: 'Required/optional item logic', type: 'textarea' },
        { key: 'reference_timeframe', label: 'Reference timeframe for answering', type: 'text' },
        { key: 'score_reporting',  label: 'Planned score reporting', type: 'select',
          options: [
            { value: 'overall',           label: 'Overall composite' },
            { value: 'subscale',          label: 'Subscale scores' },
            { value: 'both',              label: 'Both overall and subscale' },
            { value: 'item_level',        label: 'Item-level only' },
            { value: 'single_indicators', label: 'Single indicators only' }
          ] },
        { key: 'min_common_items', label: 'Minimum common items required for intended score interpretation, if known', type: 'number' },
        { key: 'paths_reviewed',   label: 'Sections or respondent paths reviewed', type: 'textarea' },
        { key: 'stakes', label: 'Stakes level', type: 'select',
          options: [
            { value: 'low',      label: 'Low-stakes' },
            { value: 'moderate', label: 'Moderate-stakes' },
            { value: 'high',     label: 'High-stakes' }
          ] },
        // Infrastructure for the widespread_administration_consistency_risk
        // warning (denominator: item count or reviewed-path count). Supplied
        // from the proposal; the warning is silent without it.
        { key: 'item_count', label: 'Item count or reviewed-path denominator', type: 'number' }
      ],
      blockers: [
        // Fires when branching gives uneven scale exposure OR too few common
        // items remain AND a composite score is planned: the claimed composite
        // would be computed from different item sets across respondents.
        // Item-level / single-indicator reporting clears it.
        { key: 'inconsistent_required_scale_exposure',
          label: 'Branching/exposure design means some respondents will not receive enough common items for a claimed composite score — fix the logic or drop the composite before launch',
          test: function (f, mitigations, context) {
            if (f.check !== 'uneven_scale_exposure' && f.check !== 'insufficient_common_items') return false;
            return !!(context && (context.score_reporting === 'overall' || context.score_reporting === 'subscale' || context.score_reporting === 'both'));
          } }
      ],
      warnings: [
        { key: 'widespread_administration_consistency_risk',
          label: 'Widespread administration inconsistency: the survey flow may need redesign rather than isolated edits.',
          test: function (accepted, context) {
            var n = context && Number(context.item_count);
            if (!(n > 0)) return false;
            var flaggedItems = {};
            accepted.forEach(function (f) { if (f.item_ref) flaggedItems[f.item_ref] = true; });
            return (Object.keys(flaggedItems).length / n) > 0.4;
          } }
      ],
      sample: {
        title: 'Sample: branching climate survey with subscale scores',
        context: { respondents: 'Students in grades 6–8', modes: ['Online'], has_branching: 'yes', score_reporting: 'subscale' },
        flags: [
          { check: 'uneven_scale_exposure', item_ref: 'safety',
            quote: 'Students who answer “no” to q4 skip the remaining 4 safety items',
            severity: 'major',
            rationale: 'Branching means a large group answers only one safety item, so the safety subscale score is computed from different item sets across respondents.',
            suggested_revision: 'Remove the skip on scale items, or report safety item-by-item rather than as a subscale score.' },
          { check: 'unstable_timeframe', item_ref: 'intro',
            quote: 'No timeframe is stated for the climate items',
            severity: 'moderate',
            rationale: 'Without a stable reference window, respondents answer over different timeframes, adding noise.',
            suggested_revision: 'State a uniform timeframe (e.g. “this school year”) in the section instructions.' }
        ]
      }
    }

  };

  // Order Reliability Readiness presents the five lenses.
  var ORDER = ['scale_structure_readiness', 'item_clarity', 'response_scale_consistency', 'redundancy_balance', 'administration_consistency'];

  root.SDSI_RELIABILITY_SPECS = SPECS;
  root.SDSI_RELIABILITY_ORDER = ORDER;

  // Build live engines when the factory is present (browser: validity-lens-engine.js
  // loads first; node: require it). The factory is construct-agnostic — the
  // same locked spine scores validity and reliability lenses identically.
  var factory = (typeof root.ValidityLens !== 'undefined') ? root.ValidityLens
              : (typeof module !== 'undefined' && module.exports) ? require('./validity-lens-engine.js')
              : null;
  if (factory) {
    var lenses = {};
    ORDER.forEach(function (k) { lenses[k] = factory.make(SPECS[k]); });
    root.SDSI_RELIABILITY_LENSES = lenses;
  }

})(typeof window !== 'undefined' ? window : this);

if (typeof module !== 'undefined' && module.exports) {
  var _r = (typeof window !== 'undefined' ? window : this);
  module.exports = { SPECS: _r.SDSI_RELIABILITY_SPECS, ORDER: _r.SDSI_RELIABILITY_ORDER, LENSES: _r.SDSI_RELIABILITY_LENSES };
}
