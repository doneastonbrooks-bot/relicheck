/* ════════════════════════════════════════════════════════════════════════
   SDSI — Validity Readiness — the five factory-lens specifications
   ────────────────────────────────────────────────────────────────────────
   Content (NOT math) for the five validity lenses that share
   validity-lens-engine.js. Each spec supplies: the check vocabulary (failure
   modes the AI may propose), a default-severity hint per check (the AI may
   override; the human settles), the reviewer-declared context fields, the
   orthogonal launch blockers, the SDSI weight, and a harness sample.

   These are VALIDITY checks only. None of them use Cronbach's alpha, omega,
   item-total correlations, or internal-consistency logic — scale length,
   redundancy, and internal consistency belong to Reliability Readiness.

   Weights (sum with Dignity 8 + Access 8 = 50 = Validity Readiness):
     construct_definition 8 · purpose_alignment 7 · dimension_coverage 8 ·
     item_construct_alignment 7 · response_option_validity 4
   ════════════════════════════════════════════════════════════════════════ */
(function (root) {
  'use strict';

  var SPECS = {

    // ── 1. Construct Definition (8) ─────────────────────────────────────
    construct_definition: {
      key: 'construct_definition', name: 'Construct Definition', noun: 'construct-definition', weight: 8,
      intro: 'Does the survey clearly name and define the primary construct it claims to measure? Construct Definition is the foundation — if the construct is unclear, the rest of validity readiness becomes unstable.',
      reviewerPrompt: 'Before scoring this lens, declare what the survey is supposed to measure. If the survey itself does not state the construct clearly, enter the construct you believe the instrument is trying to measure, then mark whether that construct is explicit in the survey or inferred by you as the reviewer.',
      checks: {
        construct_unnamed:    'Primary construct not clearly named',
        definition_absent:    'Construct named but never defined',
        definition_vague:     'Definition too general/abstract to guide item interpretation',
        construct_too_broad:  'Construct too broad to cover without clearer boundaries or subdimensions',
        construct_conflation: 'Two or more constructs blended as if they were one'
      },
      severityHints: {
        construct_unnamed: 'major', definition_absent: 'major', definition_vague: 'moderate',
        construct_too_broad: 'moderate', construct_conflation: 'major'
      },
      contextFields: [
        { key: 'title',            label: 'Stated survey title', type: 'text' },
        { key: 'purpose',          label: 'Stated survey purpose', type: 'textarea' },
        { key: 'construct',        label: 'Primary construct (reviewer-declared)', type: 'text' },
        { key: 'construct_source', label: 'Is the construct explicit in the survey, or inferred by you?', type: 'select',
          options: [
            { value: 'stated',   label: 'Stated in the survey' },
            { value: 'inferred', label: 'Inferred by the reviewer' },
            { value: 'missing',  label: 'Missing entirely' }
          ] },
        { key: 'definition',       label: 'Construct definition (reviewer-declared)', type: 'textarea' },
        { key: 'respondents',      label: 'Intended respondent group', type: 'text' },
        { key: 'intended_use',     label: 'Intended use of findings', type: 'text' }
      ],
      blockers: [
        // Fires only when the construct is BOTH unnamed AND undefined in the
        // instrument AND the reviewer has not supplied a declared construct.
        // definition_vague / construct_too_broad lower the score but never block.
        { key: 'no_defined_construct',
          label: 'No clearly named and defined construct, and none declared by the reviewer — validity cannot be established',
          test: function (f, mitigations, context, protectedOnItem, accepted) {
            if (f.check !== 'construct_unnamed') return false; // anchor → fire once
            var hasAbsent = (accepted || []).some(function (g) { return g.check === 'definition_absent'; });
            var declared = !!(context && typeof context.construct === 'string' && context.construct.trim() !== '');
            return hasAbsent && !declared;
          } }
      ],
      sample: {
        title: 'Sample: student engagement survey',
        context: { title: 'Student Engagement Survey', construct: 'Student engagement', construct_source: 'inferred',
          definition: 'How students feel about school and how hard they work.' },
        flags: [
          { check: 'definition_vague', item_ref: 'definition',
            quote: 'How students feel about school and how hard they work.',
            severity: 'moderate',
            rationale: 'The definition is colloquial and unbounded — "feel about school" could mean belonging, satisfaction, or motivation, so item selection is not constrained by the construct.',
            suggested_revision: 'Define engagement explicitly (e.g., behavioral, emotional, and cognitive involvement in learning) with boundaries that exclude general satisfaction.' },
          { check: 'construct_conflation', item_ref: 'construct',
            quote: 'feel about school and how hard they work',
            severity: 'major',
            rationale: 'Conflates an affective dimension (feelings) with a behavioral one (effort) under one undifferentiated label, so scores mix two constructs.',
            suggested_revision: 'Name the dimensions separately under one defined construct, or pick the single construct the instrument is meant to measure.' }
        ]
      }
    },

    // ── 2. Purpose Alignment (7) ────────────────────────────────────────
    purpose_alignment: {
      key: 'purpose_alignment', name: 'Purpose Alignment', noun: 'purpose-alignment', weight: 7,
      intro: 'Does the survey serve the stated reason it is being given? Purpose Alignment is not about whether the construct is clear (that is Construct Definition) — it asks whether the items, demographics, audience, and intended use stay faithful to the stated purpose.',
      reviewerPrompt: 'Before scoring this lens, declare what the survey is supposed to help someone understand, decide, or improve. If the survey itself does not state a purpose, enter the purpose you infer from the instrument, then mark whether that purpose is explicit in the survey or inferred by you as the reviewer.',
      checks: {
        purpose_unstated:          'Survey does not state why it is being administered',
        item_purpose_misalignment: 'Item does not clearly serve the stated purpose',
        demographic_unjustified:   'Demographic/background data not connected to the purpose',
        use_mismatch:              'Stated use of results not supported by the survey content',
        scope_creep:               'Expands beyond the stated purpose, weakening focus',
        audience_purpose_mismatch: 'Respondent group not positioned to answer for the purpose'
      },
      severityHints: {
        purpose_unstated: 'major', item_purpose_misalignment: 'moderate', demographic_unjustified: 'moderate',
        use_mismatch: 'major', scope_creep: 'moderate', audience_purpose_mismatch: 'major'
      },
      contextFields: [
        { key: 'purpose',          label: 'Stated survey purpose', type: 'textarea' },
        { key: 'purpose_declared', label: 'Reviewer-declared purpose (if the survey states none)', type: 'textarea' },
        { key: 'purpose_source',   label: 'Is the purpose explicit in the survey, or inferred?', type: 'select',
          options: [
            { value: 'stated',   label: 'Stated in the survey' },
            { value: 'inferred', label: 'Inferred by the reviewer' },
            { value: 'missing',  label: 'Missing entirely' }
          ] },
        { key: 'intended_use',       label: 'Intended use of findings', type: 'text' },
        { key: 'respondents',        label: 'Intended respondent group', type: 'text' },
        { key: 'decision_supported', label: 'Intended decision the survey supports', type: 'text' },
        { key: 'stakes',             label: 'Stakes of the decisions the results will drive', type: 'select',
          options: [
            { value: 'low',      label: 'Low-stakes' },
            { value: 'moderate', label: 'Moderate-stakes' },
            { value: 'high',     label: 'High-stakes' }
          ] },
        { key: 'required_demographics', label: 'Required demographic/background variables', type: 'list' },
        { key: 'optional_demographics', label: 'Optional demographic/background variables', type: 'list' }
      ],
      blockers: [
        // Fires only when the purpose is unstated in the instrument AND the
        // reviewer has declared none. scope_creep never blocks on its own.
        { key: 'no_stated_purpose',
          label: 'No stated purpose, and none declared by the reviewer — alignment cannot be judged',
          test: function (f, mitigations, context) {
            if (f.check !== 'purpose_unstated') return false;
            var declared = !!(context && (
              (typeof context.purpose === 'string' && context.purpose.trim() !== '') ||
              (typeof context.purpose_declared === 'string' && context.purpose_declared.trim() !== '')
            ));
            return !declared;
          } },
        // Fires when a use mismatch lands on a high-stakes decision: the content
        // does not support a use that could drive consequential decisions.
        { key: 'high_stakes_use_mismatch',
          label: 'High-stakes use not supported by the survey content — do not use for consequential decisions until resolved',
          test: function (f, mitigations, context) {
            if (f.check !== 'use_mismatch') return false;
            return !!(context && context.stakes === 'high');
          } }
      ],
      sample: {
        title: 'Sample: program-improvement feedback survey',
        context: { purpose: 'Improve our after-school program based on family feedback.', purpose_source: 'stated',
          intended_use: 'Internal program planning', respondents: 'Families of enrolled students',
          decision_supported: 'Which program activities to keep or change', stakes: 'moderate' },
        flags: [
          { check: 'item_purpose_misalignment', item_ref: 'q9',
            quote: 'How likely are you to recommend our program to others? (0–10)',
            severity: 'moderate',
            rationale: 'A net-promoter marketing item does not inform program improvement and dilutes the instrument relative to its stated purpose.',
            suggested_revision: 'Replace with an item asking which specific program activities families would change or keep.' },
          { check: 'demographic_unjustified', item_ref: 'q14',
            quote: 'What is your annual household income?',
            severity: 'moderate',
            rationale: 'Income is collected with no stated link to program improvement, adding burden and bias risk without serving the purpose.',
            suggested_revision: 'Remove, or state how income informs a planning decision and make it optional.' }
        ]
      }
    },

    // ── 3. Dimension / Domain Coverage (8) ──────────────────────────────
    dimension_coverage: {
      key: 'dimension_coverage', name: 'Dimension / Domain Coverage', noun: 'coverage', weight: 8,
      intro: 'Does the survey cover the major parts of the construct well enough to support interpretation? This lens is not about whether the construct is clearly defined (that is Construct Definition) or about individual item wording (that is Item-to-Construct Alignment) — it asks whether the overall domain map is complete, balanced, and free of dimensions that do not belong.',
      reviewerPrompt: 'Before scoring this lens, declare the major dimensions or domains the survey should cover. If the survey already names dimensions, enter them. If it does not, enter the dimensions you believe are necessary to interpret the construct responsibly. Mark whether each dimension is survey-provided or reviewer-inferred.',
      checks: {
        dimension_missing:    'A stated or expected dimension of the construct has no item coverage',
        dimension_thin:       'A dimension has too few items or too little variety to support interpretation',
        domain_gap:           'An important part of the construct is not adequately represented',
        overrepresentation:   'One dimension or topic receives disproportionate item coverage',
        irrelevant_dimension: 'A dimension/section is included that does not belong to the construct or purpose',
        dimension_overlap:    'Two or more dimensions are not conceptually distinct enough to interpret separately'
      },
      severityHints: {
        dimension_missing: 'major', dimension_thin: 'moderate', domain_gap: 'major',
        overrepresentation: 'moderate', irrelevant_dimension: 'moderate', dimension_overlap: 'moderate'
      },
      severityGuidance: 'Use minor only when the coverage issue is isolated and unlikely to affect interpretation. Use moderate when a dimension is thin, overlapping, or overrepresented but the overall construct is still interpretable. Use major when the survey omits or underrepresents a major part of the construct. Reserve critical for when the coverage problem makes the intended interpretation indefensible, especially in moderate- or high-stakes use.',
      contextFields: [
        { key: 'construct',           label: 'Primary construct (reviewer-declared)', type: 'text' },
        { key: 'definition',          label: 'Construct definition', type: 'textarea' },
        { key: 'purpose',             label: 'Stated survey purpose', type: 'textarea' },
        { key: 'respondents',         label: 'Intended respondent group', type: 'text' },
        { key: 'intended_use',        label: 'Intended use of findings', type: 'text' },
        { key: 'expected_dimensions', label: 'Expected dimensions/domains (one per line or comma-separated)', type: 'list' },
        { key: 'survey_dimensions',   label: 'Survey-provided dimensions/domains, if any', type: 'list' },
        { key: 'required_dimensions', label: 'Dimensions required for interpretation', type: 'list' },
        { key: 'score_reporting',     label: 'How results will be reported', type: 'select',
          options: [
            { value: 'overall',  label: 'Overall score only' },
            { value: 'subscale', label: 'Subscale scores only' },
            { value: 'both',     label: 'Overall and subscale scores' }
          ] },
        { key: 'stakes', label: 'Stakes of the decisions the results will drive', type: 'select',
          options: [
            { value: 'low',      label: 'Low-stakes' },
            { value: 'moderate', label: 'Moderate-stakes' },
            { value: 'high',     label: 'High-stakes' }
          ] }
      ],
      blockers: [
        // Narrow: fires when a missing dimension or domain gap is accepted AND the
        // results will be interpreted as the OVERALL construct (not a narrow
        // subscale). A subscale-only report is not threatened by a broad-construct
        // gap, so 'subscale' clears it. dimension_thin / overrepresentation /
        // irrelevant_dimension / dimension_overlap never block on their own — they
        // lower the score, and the reviewer escalates severity if a gap makes the
        // overall interpretation indefensible.
        { key: 'major_coverage_gap',
          label: 'A central part of the construct is missing or underrepresented for an overall-construct interpretation — broaden before launch',
          test: function (f, mitigations, context) {
            if (f.check !== 'dimension_missing' && f.check !== 'domain_gap') return false;
            return !(context && context.score_reporting === 'subscale');
          } }
      ],
      sample: {
        title: 'Sample: school climate survey',
        context: { construct: 'School climate', definition: 'Students’ shared experience of safety, belonging, and adult support at school.',
          purpose: 'Identify climate areas to improve school-wide.', intended_use: 'School improvement planning',
          respondents: 'Students in grades 6–8', expected_dimensions: ['Safety', 'Belonging', 'Adult support', 'Engagement'],
          required_dimensions: ['Safety', 'Belonging', 'Adult support'], score_reporting: 'overall', stakes: 'moderate' },
        flags: [
          { check: 'overrepresentation', item_ref: 'safety',
            quote: '9 of 12 items ask about hallway and bathroom safety',
            severity: 'moderate',
            rationale: 'Safety dominates the item count, so the total score behaves like a safety scale rather than a climate scale.',
            suggested_revision: 'Rebalance items so safety, belonging, and adult support each carry comparable weight.' },
          { check: 'domain_gap', item_ref: 'belonging',
            quote: '(belonging and adult support have one item between them)',
            severity: 'major',
            rationale: 'Belonging and adult support are central to school climate but are barely represented, so an overall climate score cannot be interpreted responsibly.',
            suggested_revision: 'Add items on peer belonging, adult-student relationships, and feeling cared for at school.' }
        ]
      }
    },

    // ── 4. Item-to-Construct Alignment (7) ──────────────────────────────
    item_construct_alignment: {
      key: 'item_construct_alignment', name: 'Item-to-Construct Alignment', noun: 'item-alignment', weight: 7,
      intro: 'Does each item actually measure the construct or dimension it is assigned to? This lens is not about missing dimensions (that is Dimension / Domain Coverage), unclear purpose (Purpose Alignment), response choices (Response-Option Validity), or redundancy/internal consistency (Reliability Readiness) — it asks, item by item, whether the item maps cleanly to what it claims to measure.',
      reviewerPrompt: 'Before scoring this lens, declare how each item is supposed to map to the survey’s construct or dimensions. If the survey provides a dimension map, use it. If not, create a reviewer-inferred map so the alignment review can proceed. Mark whether each item assignment is survey-provided, reviewer-inferred, or AI-suggested. Do not flag an item as off-construct simply because it is not part of a scale — first decide whether it measures a construct, supports routing, provides context, or is a standalone descriptive item.',
      checks: {
        item_off_construct:          'Item does not clearly measure the assigned construct or dimension',
        item_ambiguous_mapping:      'Item could reasonably belong to more than one construct/dimension',
        construct_contamination:     'Item introduces a second construct that may distort interpretation',
        double_barreled:             'Item asks about two or more ideas at once',
        item_too_broad:              'Item is so broad that responses could reflect many different meanings',
        item_too_context_dependent:  'Item cannot be interpreted without context the survey does not provide',
        item_assumption_loaded:      'Item assumes a condition/experience/belief that may not be true for the respondent'
      },
      severityHints: {
        item_off_construct: 'major', item_ambiguous_mapping: 'moderate', construct_contamination: 'major',
        double_barreled: 'moderate', item_too_broad: 'moderate', item_too_context_dependent: 'moderate',
        item_assumption_loaded: 'moderate'
      },
      severityGuidance: 'Use minor only when the issue is isolated and the intended construct is still easy to interpret. Use moderate when the item creates ambiguity or weakens interpretation but does not threaten the entire scale. Use major when the item clearly measures the wrong construct, contaminates a scale, or could lead to misleading conclusions. Reserve critical for when item-level misalignment could support harmful or indefensible decisions in a high-stakes use case.',
      contextFields: [
        { key: 'construct',           label: 'Primary construct (reviewer-declared)', type: 'text' },
        { key: 'definition',          label: 'Construct definition', type: 'textarea' },
        { key: 'purpose',             label: 'Stated survey purpose', type: 'textarea' },
        { key: 'respondents',         label: 'Intended respondent group', type: 'text' },
        { key: 'expected_dimensions', label: 'Expected dimensions/domains (one per line or comma-separated)', type: 'list' },
        { key: 'survey_dimensions',   label: 'Survey-provided dimensions/domains', type: 'list' },
        { key: 'item_dimension_map',  label: 'Item-to-dimension map, if available', type: 'textarea' },
        { key: 'assignment_source',   label: 'How items are assigned to dimensions', type: 'select',
          options: [
            { value: 'survey',   label: 'Survey-provided' },
            { value: 'reviewer', label: 'Reviewer-inferred' },
            { value: 'ai',       label: 'AI-suggested' },
            { value: 'mixed',    label: 'Mixed' }
          ] },
        { key: 'score_reporting', label: 'Intended score reporting', type: 'select',
          options: [
            { value: 'overall',    label: 'Overall score' },
            { value: 'subscale',   label: 'Subscale scores' },
            { value: 'item_level', label: 'Item-level reporting' },
            { value: 'mixed',      label: 'Mixed' }
          ] },
        { key: 'stakes', label: 'Stakes of the decisions the results will drive', type: 'select',
          options: [
            { value: 'low',      label: 'Low-stakes' },
            { value: 'moderate', label: 'Moderate-stakes' },
            { value: 'high',     label: 'High-stakes' }
          ] }
      ],
      // No launch blocker: item-level misalignment lowers the score and generates
      // revision flags, but does not block launch on its own. A high-stakes use
      // problem is already gated under Purpose Alignment.
      blockers: [],
      // Report-level caution only — advisory, never changes the score or blocks
      // launch. Fires when >40% of the items reviewed are accepted as
      // off-construct or contaminated. Needs a denominator (context.item_count,
      // supplied by the client from the proposal's item list); without it, silent.
      warnings: [
        { key: 'scale_interpretation_risk',
          label: 'Scale interpretation risk: many items may not measure the assigned construct.',
          test: function (accepted, context) {
            var n = context && Number(context.item_count);
            if (!(n > 0)) return false;
            var bad = accepted.filter(function (f) {
              return f.check === 'item_off_construct' || f.check === 'construct_contamination';
            }).length;
            return (bad / n) > 0.4;
          } }
      ],
      sample: {
        title: 'Sample: student belonging survey',
        context: { construct: 'Student belonging', definition: 'Students’ sense of being accepted, supported, and able to participate at school.',
          purpose: 'Understand where students feel they belong.', respondents: 'Students in grades 6–8',
          expected_dimensions: ['Peer belonging', 'Adult support', 'Identity safety', 'Participation'],
          assignment_source: 'reviewer', score_reporting: 'overall', stakes: 'moderate', item_count: 8 },
        flags: [
          { check: 'construct_contamination', item_ref: 'q3',
            quote: 'My teachers explain assignments clearly.',
            severity: 'major',
            rationale: 'This reads as instructional clarity, not belonging or adult support, so it introduces a second construct into the belonging scale.',
            suggested_revision: 'Replace with an item about feeling cared for or supported by adults at school.' },
          { check: 'double_barreled', item_ref: 'q4',
            quote: 'I feel safe and respected at school.',
            severity: 'moderate',
            rationale: 'Combines safety and respect; a student may feel respected but not safe, so the answer is ambiguous.',
            suggested_revision: 'Split into two items, one for safety and one for respect.' },
          { check: 'item_assumption_loaded', item_ref: 'q7',
            quote: 'When I attend leadership meetings, adults listen to my ideas.',
            severity: 'moderate',
            rationale: 'Assumes the student attends leadership meetings; students who do not cannot answer meaningfully.',
            suggested_revision: 'Rephrase so it applies to all students, e.g. “When I share an idea at school, adults listen.”' }
        ]
      }
    },

    // ── 5. Response-Option Validity (4) ─────────────────────────────────
    response_option_validity: {
      key: 'response_option_validity', name: 'Response-Option Validity', noun: 'response-option', weight: 4,
      intro: 'Do the response options let respondents answer each item accurately and meaningfully? This lens is not about item wording (unless the wording mismatches the options), redundancy or internal consistency (Reliability Readiness), or identity erasure as a dignity issue (Dignity / Framing) — it asks whether the answer choices distort, restrict, confuse, or weaken the meaning of the response.',
      reviewerPrompt: 'Before scoring this lens, review whether each item’s response options allow the intended respondents to answer accurately. Pay special attention to required items, demographic items, sensitive items, and items where the response scale may not match the wording of the question. If an issue is primarily dignity, identity erasure, or extractive disclosure, score it under Dignity / Framing — surface it here only as an answerability note, and do not double-subtract unless the response option itself creates a distinct measurement problem.',
      checks: {
        options_not_exhaustive:      'Options do not cover the likely range of valid answers',
        options_overlap:             'Options are not mutually exclusive; unclear which to choose',
        scale_mismatch:              'Response scale does not match the item stem or the judgment requested',
        missing_applicable_escape:   'Item lacks a needed N/A, “don’t know,” “prefer not to answer,” skip, or escape',
        unbalanced_scale:            'Options are unevenly weighted, leading, or biased toward one side',
        unclear_scale_labels:        'Scale labels are vague, inconsistent, incomplete, or hard to interpret',
        inconsistent_scale_direction:'Scale direction changes across similar items, confusing respondents',
        forced_choice_risk:          'Forces a single choice where respondents may need multiple, other, or none'
      },
      severityHints: {
        options_not_exhaustive: 'moderate', options_overlap: 'moderate', scale_mismatch: 'major',
        missing_applicable_escape: 'moderate', unbalanced_scale: 'moderate', unclear_scale_labels: 'moderate',
        inconsistent_scale_direction: 'moderate', forced_choice_risk: 'moderate'
      },
      severityGuidance: 'Use minor only when the issue is isolated and unlikely to affect interpretation. Use moderate when it may distort responses for a meaningful subset of respondents or items. Use major when the options make an item difficult to interpret or systematically misrepresent answers. Reserve critical for when the problem makes a required item unanswerable or unsafe in a moderate- or high-stakes use case. Do not flag a missing neutral/N/A merely because a neutral option is absent — some forced-choice designs are intentional; flag missing_applicable_escape only when respondents may genuinely lack a valid answer, lack information to answer, or need a safe way not to disclose.',
      contextFields: [
        { key: 'construct',         label: 'Primary construct (reviewer-declared)', type: 'text' },
        { key: 'definition',        label: 'Construct definition', type: 'textarea' },
        { key: 'purpose',           label: 'Stated survey purpose', type: 'textarea' },
        { key: 'respondents',       label: 'Intended respondent group', type: 'text' },
        { key: 'intended_use',      label: 'Intended use of findings', type: 'text' },
        { key: 'item_types',        label: 'Item types present (agreement, frequency, satisfaction, importance, confidence, categorical, demographic, open-ended, mixed)', type: 'list' },
        { key: 'required_items',    label: 'Required items, if any', type: 'list' },
        { key: 'sensitive_items',   label: 'Sensitive or identity-related items, if any', type: 'list' },
        { key: 'forced_choice_intentional', label: 'Are forced-choice items intentional?', type: 'select',
          options: [
            { value: 'yes',  label: 'Yes — by design' },
            { value: 'no',   label: 'No' },
            { value: 'some', label: 'Some are, some are not' }
          ] },
        { key: 'escape_options_allowed', label: 'Are N/A, prefer-not-to-answer, skip, or write-in options allowed?', type: 'select',
          options: [
            { value: 'yes',  label: 'Yes' },
            { value: 'no',   label: 'No' },
            { value: 'some', label: 'On some items' }
          ] },
        { key: 'stakes', label: 'Stakes of the decisions the results will drive', type: 'select',
          options: [
            { value: 'low',      label: 'Low-stakes' },
            { value: 'moderate', label: 'Moderate-stakes' },
            { value: 'high',     label: 'High-stakes' }
          ] }
      ],
      blockers: [
        // Narrow: fires only for an answerability-breaking check on a REQUIRED
        // item. `f.required` is stamped server-side by the proposer from the
        // survey's item list (authoritative). Centrality and "effectively
        // unanswerable / likely to misclassify" are the reviewer's judgment,
        // affirmed by accepting the flag (the AI's rationale must argue both).
        { key: 'unanswerable_scale',
          label: 'A required, central item is effectively unanswerable or likely to misclassify respondents — fix the response options before launch',
          test: function (f) {
            if (!f.required) return false;
            return f.check === 'scale_mismatch' || f.check === 'options_not_exhaustive'
                || f.check === 'forced_choice_risk' || f.check === 'missing_applicable_escape';
          } }
      ],
      sample: {
        title: 'Sample: school climate & services survey',
        context: { construct: 'School climate', purpose: 'Improve school-wide supports.',
          respondents: 'Families of enrolled students', intended_use: 'Program planning',
          item_types: ['frequency', 'satisfaction', 'categorical'], required_items: ['q2'],
          forced_choice_intentional: 'no', escape_options_allowed: 'no', stakes: 'moderate' },
        flags: [
          { check: 'scale_mismatch', item_ref: 'q2', required: true,
            quote: 'How often do you feel safe at school? [Strongly disagree … Strongly agree]',
            severity: 'major',
            rationale: 'A frequency stem ("how often") is paired with an agreement scale, so a required, central climate item cannot be answered meaningfully.',
            suggested_revision: 'Use a frequency scale (Never / Rarely / Sometimes / Often / Always).' },
          { check: 'missing_applicable_escape', item_ref: 'q8',
            quote: 'How helpful were tutoring services for your child? [Very unhelpful … Very helpful]',
            severity: 'moderate',
            rationale: 'Families whose child did not receive tutoring have no valid answer, forcing inaccurate responses.',
            suggested_revision: 'Add a “Not applicable — my child did not receive tutoring” option.' },
          { check: 'unbalanced_scale', item_ref: 'q5',
            quote: 'Rate your satisfaction with school meals. [Bad / Okay / Excellent]',
            severity: 'moderate',
            rationale: 'The scale is uneven and lacks balanced positive and negative anchors, biasing responses upward.',
            suggested_revision: 'Use a balanced scale (Very dissatisfied / Dissatisfied / Neutral / Satisfied / Very satisfied).' }
        ]
      }
    }

  };

  // Order Validity Readiness presents the five (Dignity + Access render
  // separately via their own engines).
  var ORDER = ['construct_definition', 'purpose_alignment', 'dimension_coverage', 'item_construct_alignment', 'response_option_validity'];

  root.SDSI_VALIDITY_SPECS = SPECS;
  root.SDSI_VALIDITY_ORDER = ORDER;

  // Build live engines when the factory is present (browser: validity-lens-engine.js
  // loads first; node: require it).
  var factory = (typeof root.ValidityLens !== 'undefined') ? root.ValidityLens
              : (typeof module !== 'undefined' && module.exports) ? require('./validity-lens-engine.js')
              : null;
  if (factory) {
    var lenses = {};
    ORDER.forEach(function (k) { lenses[k] = factory.make(SPECS[k]); });
    root.SDSI_VALIDITY_LENSES = lenses;
  }

})(typeof window !== 'undefined' ? window : this);

if (typeof module !== 'undefined' && module.exports) {
  var _r = (typeof window !== 'undefined' ? window : this);
  module.exports = { SPECS: _r.SDSI_VALIDITY_SPECS, ORDER: _r.SDSI_VALIDITY_ORDER, LENSES: _r.SDSI_VALIDITY_LENSES };
}
