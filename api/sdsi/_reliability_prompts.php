<?php
// Shared vocabulary + proposer-prompt builder for the five factory RELIABILITY
// READINESS lenses (scale_structure_readiness, item_clarity,
// response_scale_consistency, redundancy_balance, administration_consistency).
//
// This mirrors the check vocabulary, severities, severity guidance, blocker
// conditions, warnings, context fields, and SDSI weights declared in
// apps/sdsi/reliability-specs.js. The JS specs drive the client engine + UI;
// this PHP copy is the server-side validation gate for AI proposals and the
// prompt content. KEEP THE TWO IN LOCKSTEP — the lockstep test
// (apps/sdsi/reliability-prompts-lockstep.test.js) compares this file against
// reliability-specs.js (component keys, check keys, severityHints, context
// field keys + select options, blocker keys, warning keys) and fails on drift.
//
// ╔══════════════════════════════════════════════════════════════════════╗
// ║  ALPHA FENCE — NO RESPONSE-DATA STATISTICS.                           ║
// ║  These lenses never use Cronbach's alpha, McDonald's omega,           ║
// ║  item-total or inter-item correlations, factor analysis, or any       ║
// ║  statistic computed from responses. Those belong to RSSI, AFTER data  ║
// ║  collection. The AI reviews DESIGN and CONCEPTUAL structure only.     ║
// ╚══════════════════════════════════════════════════════════════════════╝

declare(strict_types=1);

/**
 * Returns the full spec for a reliability component, or null if unknown.
 * Shape: [ label, noun, weight, principle, checks{key=>label},
 *          checkDesc{key=>oneLine}, severityHints{key=>sev}, severityGuidance,
 *          contextFields[ {key,label,type,options?} ], blockerConditions[string],
 *          warnings[string] ]
 */
function reliability_component_spec(string $component): ?array
{
    static $specs = null;
    if ($specs === null) {
        $specs = [

            // ── 1. Scale Structure Readiness (8) ────────────────────────────
            'scale_structure_readiness' => [
                'label'  => 'Scale Structure Readiness',
                'noun'   => 'scale-structure',
                'weight' => 8,
                'principle' =>
                    "Judge whether the survey has a DECLARED and DEFENSIBLE item structure before any data exists. " .
                    "Ask whether intended scales/subscales are declared, whether each has enough items to support later " .
                    "consistency analysis, whether single- and two-item measures are handled honestly, and whether the " .
                    "scoring/reporting plan matches the structure. This is NOT a statistical-reliability check. Branching/skip " .
                    "exposure is NOT scored here — whether respondents are exposed to the structure consistently belongs to " .
                    "Administration Consistency.",
                'checks' => [
                    'structure_undeclared'        => 'Item-to-scale structure not declared',
                    'single_item_scale_claim'     => 'One-item measure presented as a scale',
                    'two_item_scale_uncautioned'  => 'Two-item scale presented without caution',
                    'insufficient_items_for_scale'=> 'Declared scale too thin for the intended composite',
                    'score_structure_mismatch'    => 'Reporting plan does not match the item structure',
                    'subscale_structure_unclear'  => 'Subscale boundaries/assignments unclear',
                ],
                'checkDesc' => [
                    'structure_undeclared'        => 'the survey does not declare which items belong to which scale, subscale, or single-indicator measure.',
                    'single_item_scale_claim'     => 'a one-item measure is presented, labeled, or reported as if it were a scale rather than a single indicator.',
                    'two_item_scale_uncautioned'  => 'a two-item scale is presented as a full scale without a cautionary note about its thin structure.',
                    'insufficient_items_for_scale'=> 'a declared scale/subscale has three or more items but still appears too thin, narrow, or underdeveloped for the intended composite interpretation.',
                    'score_structure_mismatch'    => 'the planned overall, subscale, or composite reporting plan does not match the declared item structure.',
                    'subscale_structure_unclear'  => 'scales/subscales are declared, but boundaries, labels, or item assignments are unclear enough that later interpretation would be unstable.',
                ],
                'severityHints' => [
                    'structure_undeclared' => 'major', 'single_item_scale_claim' => 'major', 'two_item_scale_uncautioned' => 'moderate',
                    'insufficient_items_for_scale' => 'major', 'score_structure_mismatch' => 'major', 'subscale_structure_unclear' => 'moderate',
                ],
                'severityGuidance' =>
                    "Use minor only when the structure issue is cosmetic and does not affect interpretation. Use moderate when " .
                    "the structure is usable but needs caution, clearer labeling, or better documentation. Use major when the " .
                    "structure weakens or threatens a planned scale, subscale, or composite interpretation. Use critical only " .
                    "when the structure problem supports a high-stakes, published, or decision-making composite score that the " .
                    "item structure cannot defend.",
                'contextFields' => [
                    ['key' => 'construct',              'label' => 'Primary construct',                'type' => 'text'],
                    ['key' => 'definition',             'label' => 'Construct definition',             'type' => 'textarea'],
                    ['key' => 'purpose',                'label' => 'Stated survey purpose',            'type' => 'textarea'],
                    ['key' => 'respondents',            'label' => 'Intended respondent group',        'type' => 'text'],
                    ['key' => 'declared_scales',        'label' => 'Declared scales/subscales (survey-provided)', 'type' => 'list'],
                    ['key' => 'reviewer_scales',        'label' => 'Reviewer-declared scales/subscales (if the survey provides none)', 'type' => 'list'],
                    ['key' => 'single_item_indicators', 'label' => 'Single-item indicators, if any',   'type' => 'list'],
                    ['key' => 'two_item_scales',        'label' => 'Two-item scales, if any',          'type' => 'list'],
                    ['key' => 'score_reporting',        'label' => 'Planned score reporting',          'type' => 'select',
                        'options' => ['overall', 'subscale', 'both', 'item_level', 'single_indicators']],
                    ['key' => 'intended_use',           'label' => 'Intended use of scores',           'type' => 'text'],
                    ['key' => 'stakes',                 'label' => 'Stakes of the decisions the results will drive', 'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                ],
                'blockerConditions' => ['no_declared_scale_structure', 'scale_score_not_defensible'],
                'warnings'          => [],
            ],

            // ── 2. Item Clarity / Wording Consistency (8) ───────────────────
            'item_clarity' => [
                'label'  => 'Item Clarity / Wording Consistency',
                'noun'   => 'item-clarity',
                'weight' => 8,
                'principle' =>
                    "Judge whether respondents in the intended group are likely to interpret each item CONSISTENTLY. " .
                    "Unclear or inconsistent wording produces inconsistent respondent interpretation, which is a reliability " .
                    "readiness problem. This is NOT about whether the item measures the right construct (Item-to-Construct " .
                    "Alignment), the response options (Response-Option Validity), dignity (Dignity / Framing), or redundancy " .
                    "(Redundancy Balance). Readability is scored ONCE: a barrier that prevents participation belongs to Access; " .
                    "wording that makes interpretation vary among those who can participate belongs here.",
                'checks' => [
                    'vague_wording'                  => 'Broad/imprecise language open to different interpretations',
                    'inconsistent_terminology'       => 'Different terms for the same idea across similar items',
                    'unclear_referent'               => 'Item does not clearly identify who/what it refers to',
                    'shifting_timeframe'             => 'Answering timeframe missing/unclear/shifting across items',
                    'excessive_complexity'           => 'Sentence structure too complex for consistent interpretation',
                    'confusing_negation'             => 'Negation/double-negative likely to be misread',
                    'reading_level_mismatch'         => 'Reading demand above the intended respondent group',
                    'inconsistent_parallel_phrasing' => 'Items meant to function together use different phrasing',
                ],
                'checkDesc' => [
                    'vague_wording'                  => 'the item uses broad, undefined, or imprecise language that different respondents may interpret differently.',
                    'inconsistent_terminology'       => 'the survey uses different terms for the same person, group, setting, behavior, or concept across similar items.',
                    'unclear_referent'               => 'the item does not clearly identify who or what the question is referring to.',
                    'shifting_timeframe'             => 'the timeframe for answering is missing, unclear, or shifts across similar items without explanation.',
                    'excessive_complexity'           => 'the item’s sentence structure, length, or syntax is complex enough to make consistent interpretation difficult.',
                    'confusing_negation'             => 'the item uses negative wording, double negatives, or reversal phrasing that respondents are likely to misread.',
                    'reading_level_mismatch'         => 'the vocabulary or reading demand is above the intended respondent group in a way that risks inconsistent interpretation among respondents.',
                    'inconsistent_parallel_phrasing' => 'items intended to function together use noticeably different phrasing structures, making it unclear whether response differences reflect the construct or the wording.',
                ],
                'severityHints' => [
                    'vague_wording' => 'moderate', 'inconsistent_terminology' => 'moderate', 'unclear_referent' => 'moderate',
                    'shifting_timeframe' => 'moderate', 'excessive_complexity' => 'moderate', 'confusing_negation' => 'moderate',
                    'reading_level_mismatch' => 'moderate', 'inconsistent_parallel_phrasing' => 'moderate',
                ],
                'severityGuidance' =>
                    "Use minor when the wording issue is isolated and easy to correct without affecting interpretation. Use " .
                    "moderate when the wording issue may cause different respondents to interpret the item differently. Use major " .
                    "when the wording issue affects a required item, a central scale item, or multiple items in a way that " .
                    "threatens score consistency. Use critical only when wording creates severe interpretation risk in a " .
                    "high-stakes use case — in most cases, severe readability barriers should be handled under Access rather than " .
                    "this lens. Do not flag reverse-worded items simply because they are reverse-worded; flag them only when the " .
                    "wording is likely to be misread. Do not flag advanced vocabulary simply because it is advanced; flag it only " .
                    "when it is above the expected respondent group or likely to create inconsistent interpretation.",
                'contextFields' => [
                    ['key' => 'construct',                'label' => 'Primary construct',         'type' => 'text'],
                    ['key' => 'definition',               'label' => 'Construct definition',      'type' => 'textarea'],
                    ['key' => 'purpose',                  'label' => 'Stated survey purpose',     'type' => 'textarea'],
                    ['key' => 'respondents',              'label' => 'Intended respondent group', 'type' => 'text'],
                    ['key' => 'age_grade_band',           'label' => 'Respondent age/grade band', 'type' => 'text'],
                    ['key' => 'reading_level',            'label' => 'Expected reading level',    'type' => 'text'],
                    ['key' => 'language',                 'label' => 'Language of administration','type' => 'text'],
                    ['key' => 'respondent_type',          'label' => 'Respondent type',           'type' => 'select',
                        'options' => ['students', 'families', 'staff', 'mixed']],
                    ['key' => 'parallel_items_intended',  'label' => 'Are items intended to be parallel within scales?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'reverse_worded_intentional', 'label' => 'Are reverse-worded items used intentionally?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'stakes',                   'label' => 'Stakes of the decisions the results will drive', 'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                    ['key' => 'item_count',               'label' => 'Number of items reviewed',  'type' => 'number'],
                ],
                'blockerConditions' => [],
                'warnings'          => ['widespread_clarity_risk'],
            ],

            // ── 3. Response Scale Consistency (7) ───────────────────────────
            'response_scale_consistency' => [
                'label'  => 'Response Scale Consistency',
                'noun'   => 'response-scale',
                'weight' => 7,
                'principle' =>
                    "Judge whether items intended to function together use CONSISTENT response formats and anchors. " .
                    "Inconsistent formats within a scale add avoidable measurement noise. This is distinct from Response-Option " .
                    "Validity: a scale that does not match its stem is a validity problem; similar items using DIFFERENT anchors " .
                    "within the same scale is a consistency problem scored here. Do not flag response-scale variation simply " .
                    "because different sections use different scales — flag it when similar items, sibling items, or items " .
                    "intended to be combined into the same scale use inconsistent response formats without clear rationale.",
                'checks' => [
                    'mixed_scale_types'        => 'Same scale uses different response formats',
                    'inconsistent_point_count' => 'Combinable items use a different number of response points',
                    'inconsistent_anchor_labels'=> 'Combinable items use different anchor labels for the same idea',
                    'inconsistent_direction'   => 'Scale direction changes across similar items without rationale',
                    'unclear_midpoint'         => 'Midpoint unlabeled/inconsistent so its meaning is unclear',
                    'inconsistent_na_handling' => 'N/A / skip / decline options offered inconsistently',
                    'reverse_coding_unclear'   => 'Reverse-coded item not clearly documented or signaled',
                ],
                'checkDesc' => [
                    'mixed_scale_types'         => 'items intended to function within the same scale/subscale use different response formats (agreement, frequency, satisfaction, importance, confidence, etc.).',
                    'inconsistent_point_count'  => 'similar or combinable items use a different number of response points (e.g. mixing 4-, 5-, and 7-point scales).',
                    'inconsistent_anchor_labels'=> 'similar or combinable items use different anchor labels for the same response idea.',
                    'inconsistent_direction'    => 'scale direction changes across similar items without clear design rationale or documentation.',
                    'unclear_midpoint'          => 'the midpoint is unlabeled, inconsistently labeled, or used so its meaning is unclear across similar items.',
                    'inconsistent_na_handling'  => 'not applicable, don’t know, skip, decline, or prefer-not-to-answer options are offered inconsistently across similar items.',
                    'reverse_coding_unclear'    => 'a reverse-coded or reverse-direction item appears intentional, but the design does not clearly document or signal the reversal in a way that reduces respondent confusion and scoring risk.',
                ],
                'severityHints' => [
                    'mixed_scale_types' => 'major', 'inconsistent_point_count' => 'moderate', 'inconsistent_anchor_labels' => 'moderate',
                    'inconsistent_direction' => 'major', 'unclear_midpoint' => 'minor', 'inconsistent_na_handling' => 'moderate',
                    'reverse_coding_unclear' => 'moderate',
                ],
                'severityGuidance' =>
                    "Use minor when the inconsistency is isolated and unlikely to affect interpretation. Use moderate when the " .
                    "inconsistency may add response noise or require clearer documentation. Use major when the inconsistency " .
                    "affects items intended to be combined into a scale or subscale. Reserve critical only when a response-scale " .
                    "inconsistency is tied to a high-stakes reporting use and creates severe scoring risk — in most cases the " .
                    "blocker-level issue belongs to Scale Structure Readiness, not here.",
                'contextFields' => [
                    ['key' => 'construct',                'label' => 'Primary construct',         'type' => 'text'],
                    ['key' => 'definition',               'label' => 'Construct definition',      'type' => 'textarea'],
                    ['key' => 'declared_scales',          'label' => 'Declared scales/subscales (one per line)', 'type' => 'list'],
                    ['key' => 'item_scale_map',           'label' => 'Item-to-scale map, if available', 'type' => 'textarea'],
                    ['key' => 'item_types',               'label' => 'Response formats present (agreement, frequency, satisfaction, etc.)', 'type' => 'list'],
                    ['key' => 'point_counts',             'label' => 'Number of response points used (e.g. 4, 5, 7)', 'type' => 'list'],
                    ['key' => 'anchor_labels',            'label' => 'Anchor labels used',        'type' => 'list'],
                    ['key' => 'reverse_coded_intentional','label' => 'Are reverse-coded items used intentionally?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'na_options_used',          'label' => 'Are N/A, don’t know, skip, decline, or prefer-not-to-answer options used?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'score_reporting',          'label' => 'Planned score reporting',   'type' => 'select',
                        'options' => ['overall', 'subscale', 'both', 'item_level', 'single_indicators']],
                    ['key' => 'stakes',                   'label' => 'Stakes level',              'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                    ['key' => 'item_count',               'label' => 'Number of items reviewed',  'type' => 'number'],
                ],
                'blockerConditions' => [],
                'warnings'          => ['widespread_scale_format_risk'],
            ],

            // ── 4. Redundancy Balance (6) ───────────────────────────────────
            'redundancy_balance' => [
                'label'  => 'Redundancy Balance',
                'noun'   => 'redundancy',
                'weight' => 6,
                'principle' =>
                    "Judge whether items within a scale have BALANCED conceptual overlap — related enough to measure the same " .
                    "construct but not so repetitive that they become duplicates. This is a PRE-DATA review of CONCEPTUAL " .
                    "similarity ONLY. NEVER use correlations, alpha, item-total or inter-item statistics, factor structure, or " .
                    "loadings. Too much repetition inflates apparent consistency; too little overlap means the items are not " .
                    "measuring one thing.",
                'checks' => [
                    'duplicate_items'                => 'Two or more items ask essentially the same question',
                    'excessive_paraphrasing'         => 'Several items reword the same idea without adding coverage',
                    'narrow_item_cluster'            => 'Scale over-weights one narrow facet of the construct',
                    'low_item_variety'               => 'Scale lacks conceptual variety for the intended dimension',
                    'insufficient_conceptual_overlap'=> 'Items share too little conceptual relationship to be one scale',
                    'unrelated_sibling_item'         => 'One item is conceptually disconnected from its scale siblings',
                    'scale_scatter_risk'             => 'Scale covers too many loosely connected ideas (unfocused pool)',
                ],
                'checkDesc' => [
                    'duplicate_items'                => 'two or more items ask essentially the same question with only trivial wording differences.',
                    'excessive_paraphrasing'         => 'several items reword the same idea without adding meaningful conceptual coverage.',
                    'narrow_item_cluster'            => 'a scale places too much weight on one narrow facet of the construct, making that facet dominate.',
                    'low_item_variety'               => 'a scale lacks enough conceptual variety to represent the intended dimension or construct in a balanced way.',
                    'insufficient_conceptual_overlap'=> 'items within a scale share too little conceptual relationship to support interpretation as one scale.',
                    'unrelated_sibling_item'         => 'one item appears conceptually disconnected from the other items in the same scale, even if it may be relevant elsewhere in the survey.',
                    'scale_scatter_risk'             => 'the scale covers too many loosely connected ideas, creating an unfocused item pool that may not support a coherent reliability interpretation later.',
                ],
                'severityHints' => [
                    'duplicate_items' => 'moderate', 'excessive_paraphrasing' => 'minor', 'narrow_item_cluster' => 'moderate',
                    'low_item_variety' => 'moderate', 'insufficient_conceptual_overlap' => 'major', 'unrelated_sibling_item' => 'major',
                    'scale_scatter_risk' => 'moderate',
                ],
                'severityGuidance' =>
                    "Use minor when the redundancy or variety issue is isolated and easy to fix. Use moderate when the issue " .
                    "affects item balance within a scale but the scale remains interpretable. Use major when items are so " .
                    "conceptually loose, disconnected, or poorly balanced that the intended scale interpretation is threatened. " .
                    "Reserve critical only for rare high-stakes cases where a scale drives consequential decisions and the item " .
                    "pool is conceptually indefensible — in most cases keep this a score penalty or advisory warning, not a " .
                    "blocker. Judge conceptual meaning only — never a correlation, alpha, item-total, inter-item statistic, factor " .
                    "structure, or loading.",
                'contextFields' => [
                    ['key' => 'construct',       'label' => 'Primary construct',         'type' => 'text'],
                    ['key' => 'definition',      'label' => 'Construct definition',      'type' => 'textarea'],
                    ['key' => 'declared_scales', 'label' => 'Declared scales/subscales (one per line)', 'type' => 'list'],
                    ['key' => 'item_scale_map',  'label' => 'Item-to-scale map',         'type' => 'textarea'],
                    ['key' => 'items_per_scale', 'label' => 'Items assigned to each scale/subscale', 'type' => 'textarea'],
                    ['key' => 'score_reporting', 'label' => 'Intended score reporting',  'type' => 'select',
                        'options' => ['overall', 'subscale', 'both', 'item_level', 'single_indicators']],
                    ['key' => 'expected_facets', 'label' => 'Expected facets within each scale', 'type' => 'textarea'],
                    ['key' => 'scale_breadth',   'label' => 'Is the scale intended to be broad or narrow?', 'type' => 'select',
                        'options' => ['broad', 'narrow']],
                    ['key' => 'stakes',          'label' => 'Stakes level',              'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                    ['key' => 'item_count',      'label' => 'Number of items reviewed',  'type' => 'number'],
                ],
                'blockerConditions' => [],
                'warnings'          => ['widespread_redundancy_risk'],
            ],

            // ── 5. Administration Consistency for Reliability (6) ───────────
            'administration_consistency' => [
                'label'  => 'Administration Consistency for Reliability',
                'noun'   => 'administration-consistency',
                'weight' => 6,
                'principle' =>
                    "Judge whether the conditions under which respondents answer are standardized enough to support CONSISTENT " .
                    "responses. This belongs to Reliability Readiness only when a problem affects the consistency of response " .
                    "conditions — instructions, timeframe, respondent role/perspective, mode/version controls, required logic, " .
                    "and item-exposure under branching/skip logic. Broader fielding, consent, privacy, recruitment timing, " .
                    "safety, and launch logistics are handled later by Administration Readiness (15 points), NOT here.",
                'checks' => [
                    'inconsistent_instructions'  => 'Instructions differ across sections/versions/paths',
                    'unstable_timeframe'         => 'No stable reference window for comparable items',
                    'unclear_respondent_role'    => 'Perspective respondents should answer from is unspecified',
                    'uncontrolled_mode_variation'=> 'Mode/version differences without consistency controls',
                    'inconsistent_required_logic'=> 'Required/optional settings differ across similar items',
                    'uneven_scale_exposure'      => 'Branching gives respondents different subsets of a scale',
                    'insufficient_common_items'  => 'Too few shared items across respondents for the score',
                ],
                'checkDesc' => [
                    'inconsistent_instructions'  => 'instructions differ across sections, versions, or respondent paths in ways that may change how items are answered.',
                    'unstable_timeframe'         => 'the survey does not provide a stable reference window for comparable items, or different sections use inconsistent timeframes without explanation.',
                    'unclear_respondent_role'    => 'the survey does not clearly specify the perspective respondents should use when answering (self, parent/guardian, teacher, staff, student, observer).',
                    'uncontrolled_mode_variation'=> 'different modes or versions are used without controls to preserve consistency (paper vs. online, read-aloud vs. self-administered, translated vs. original, randomized vs. fixed order).',
                    'inconsistent_required_logic'=> 'required and optional settings differ across similar items or scale items without clear rationale.',
                    'uneven_scale_exposure'      => 'skip, display, or branching logic causes different respondents to see different subsets of items within a scale or subscale.',
                    'insufficient_common_items'  => 'the administration or branching design may leave too few shared items across respondents to support the intended overall, subscale, or composite score.',
                ],
                'severityHints' => [
                    'inconsistent_instructions' => 'moderate', 'unstable_timeframe' => 'moderate', 'unclear_respondent_role' => 'moderate',
                    'uncontrolled_mode_variation' => 'major', 'inconsistent_required_logic' => 'moderate', 'uneven_scale_exposure' => 'major',
                    'insufficient_common_items' => 'major',
                ],
                'severityGuidance' =>
                    "Use minor when the administration inconsistency is isolated and unlikely to affect interpretation. Use " .
                    "moderate when the inconsistency may affect how respondents answer but does not threaten the intended score. " .
                    "Use major when the inconsistency affects scale items, respondent exposure, modes, versions, or required logic " .
                    "in a way that threatens score consistency. Reserve critical only when a high-stakes score or decision would " .
                    "rest on inconsistent administration conditions — in most cases keep the issue a score penalty or the existing " .
                    "orthogonal blocker.",
                'contextFields' => [
                    ['key' => 'respondents',       'label' => 'Intended respondent group', 'type' => 'text'],
                    ['key' => 'respondent_roles',  'label' => 'Respondent roles (one per line)', 'type' => 'list'],
                    ['key' => 'modes',             'label' => 'Administration modes/versions (one per line)', 'type' => 'list'],
                    ['key' => 'version_types',     'label' => 'Translated, read-aloud, paper, online, mobile, or alternate versions used (one per line)', 'type' => 'list'],
                    ['key' => 'has_branching',     'label' => 'Does the survey use branching or skip logic?', 'type' => 'select',
                        'options' => ['yes', 'no']],
                    ['key' => 'required_logic',    'label' => 'Required/optional item logic', 'type' => 'textarea'],
                    ['key' => 'reference_timeframe','label' => 'Reference timeframe for answering', 'type' => 'text'],
                    ['key' => 'score_reporting',   'label' => 'Planned score reporting',   'type' => 'select',
                        'options' => ['overall', 'subscale', 'both', 'item_level', 'single_indicators']],
                    ['key' => 'min_common_items',  'label' => 'Minimum common items required for intended score interpretation, if known', 'type' => 'number'],
                    ['key' => 'paths_reviewed',    'label' => 'Sections or respondent paths reviewed', 'type' => 'textarea'],
                    ['key' => 'stakes',            'label' => 'Stakes level',              'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                    ['key' => 'item_count',        'label' => 'Item count or reviewed-path denominator', 'type' => 'number'],
                ],
                'blockerConditions' => ['inconsistent_required_scale_exposure'],
                'warnings'          => ['widespread_administration_consistency_risk'],
            ],

        ];
    }
    return $specs[$component] ?? null;
}

/** All valid reliability component keys, in display order (mirrors SDSI_RELIABILITY_ORDER). */
function reliability_components(): array
{
    return ['scale_structure_readiness', 'item_clarity', 'response_scale_consistency', 'redundancy_balance', 'administration_consistency'];
}

/**
 * Builds the AI proposer system prompt for one reliability component. The model
 * PROPOSES flags with verbatim evidence; it never computes a score. Severity
 * drives the penalty downstream; the model must not emit numbers. The alpha
 * fence is stated explicitly: this is a PRE-DATA design review, never a
 * response-data statistic.
 */
function reliability_system_prompt(array $spec): string
{
    $checkLines = [];
    foreach ($spec['checks'] as $key => $label) {
        $desc = $spec['checkDesc'][$key] ?? $label;
        $checkLines[] = " - {$key} : {$desc}";
    }
    $checkBlock = implode("\n", $checkLines);
    $label = $spec['label'];
    $severityGuidance = isset($spec['severityGuidance'])
        ? "\n" . $spec['severityGuidance'] . "\n"
        : '';

    return <<<SYS
You review survey instruments BEFORE any data is collected, for {$label} — one
component of Reliability Readiness. You are advisory: you PROPOSE issues with
verbatim evidence; a human makes the final call. You never compute the score.

{$spec['principle']}

ALPHA FENCE — this is a PRE-DATA DESIGN review. NEVER use Cronbach's alpha,
McDonald's omega, item-total or inter-item correlations, factor analysis, or any
statistic computed from responses. Those belong to RSSI, AFTER data is collected.
Review design and conceptual structure only.

THE CHECKS (use these exact keys):
{$checkBlock}

SEVERITY (propose one; the human may override): minor, moderate, major, critical.
 - minor: small weakness; consistency mostly holds.
 - moderate: a real weakness that should be addressed.
 - major: a substantial reliability-readiness threat for this component.
 - critical: the component fails as designed.
{$severityGuidance}Do NOT output penalty numbers — severity alone drives the penalty downstream.

For EVERY proposed flag output: check, item_ref (the item id, a scale/subscale
name, or a context field key like "definition"/"score_reporting" when the issue
is in the declared context rather than an item), quote (verbatim evidence from
the item or the declared context), severity, rationale (one line: why it fires
AND why it matters for reliability readiness), suggested_revision (a concrete fix).

Be conservative: only flag what the text actually supports, and always quote the
exact words. If nothing fires, return an empty flags array. Output STRICT JSON
only, no prose outside the JSON, in this shape:
{ "flags": [ { "check": "...", "item_ref": "...", "quote": "...", "severity": "...", "rationale": "...", "suggested_revision": "..." } ], "notes": "" }
SYS;
}
