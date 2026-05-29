<?php
// Shared vocabulary + proposer-prompt builder for the five factory validity
// lenses (construct_definition, purpose_alignment, dimension_coverage,
// item_construct_alignment, response_option_validity).
//
// This mirrors the check vocabulary, severities, blocker conditions, context
// fields, and SDSI weights declared in apps/sdsi/validity-specs.js. The JS specs
// drive the client engine + UI; this PHP copy is the server-side validation
// gate for AI proposals and the prompt content. Keep the two in lockstep.
//
// These are VALIDITY checks only — never alpha/omega/item-total/internal
// consistency (those belong to Reliability Readiness).

declare(strict_types=1);

/**
 * Returns the full spec for a validity component, or null if unknown.
 * Shape: [ label, noun, weight, principle, checks{key=>label},
 *          checkDesc{key=>oneLine}, severityHints{key=>sev},
 *          contextFields[ {key,label,type} ], blockerConditions[string] ]
 */
function validity_component_spec(string $component): ?array
{
    static $specs = null;
    if ($specs === null) {
        $specs = [

            'construct_definition' => [
                'label'  => 'Construct Definition',
                'noun'   => 'construct-definition',
                'weight' => 8,
                'principle' =>
                    "Judge whether the survey clearly NAMES and DEFINES the primary construct it claims to measure. " .
                    "Construct Definition is the foundation of validity readiness — if the construct is unclear, the rest " .
                    "becomes unstable. An unnamed construct, a missing or vague definition, a construct too broad to bound " .
                    "without subdimensions, or two constructs fused under one label all break the chain from construct to item.",
                'checks' => [
                    'construct_unnamed'    => 'Primary construct not clearly named',
                    'definition_absent'    => 'Construct named but never defined',
                    'definition_vague'     => 'Definition too general/abstract to guide item interpretation',
                    'construct_too_broad'  => 'Construct too broad to cover without clearer boundaries or subdimensions',
                    'construct_conflation' => 'Two or more constructs blended as if they were one',
                ],
                'checkDesc' => [
                    'construct_unnamed'    => 'the survey does not clearly name the primary construct it is trying to measure.',
                    'definition_absent'    => 'the survey names a construct but does not define what it means.',
                    'definition_vague'     => 'the construct definition is too general, abstract, or unclear to guide item interpretation.',
                    'construct_too_broad'  => 'the construct is so broad (e.g. "school climate", "student experience", "teacher effectiveness") that the survey cannot reasonably cover it without clearer boundaries or subdimensions.',
                    'construct_conflation' => 'the survey blends two or more different constructs as if they were one.',
                ],
                'severityHints' => [
                    'construct_unnamed' => 'major', 'definition_absent' => 'major', 'definition_vague' => 'moderate',
                    'construct_too_broad' => 'moderate', 'construct_conflation' => 'major',
                ],
                'contextFields' => [
                    ['key' => 'title',            'label' => 'Stated survey title',                                    'type' => 'text'],
                    ['key' => 'purpose',          'label' => 'Stated survey purpose',                                  'type' => 'textarea'],
                    ['key' => 'construct',        'label' => 'Primary construct (reviewer-declared)',                  'type' => 'text'],
                    ['key' => 'construct_source', 'label' => 'Is the construct explicit in the survey, or inferred?', 'type' => 'select',
                        'options' => ['stated', 'inferred', 'missing']],
                    ['key' => 'definition',       'label' => 'Construct definition (reviewer-declared)',               'type' => 'textarea'],
                    ['key' => 'respondents',      'label' => 'Intended respondent group',                             'type' => 'text'],
                    ['key' => 'intended_use',     'label' => 'Intended use of findings',                              'type' => 'text'],
                ],
                'blockerConditions' => ['no_defined_construct'],
            ],

            'purpose_alignment' => [
                'label'  => 'Purpose Alignment',
                'noun'   => 'purpose-alignment',
                'weight' => 7,
                'principle' =>
                    "Judge whether the items, demographics, audience, and intended use stay FAITHFUL to the stated purpose. " .
                    "Purpose Alignment is NOT about whether the construct is clear — that is Construct Definition. Do not flag a " .
                    "vague construct here. Flag only when the purpose is stated or inferable, yet the items, demographics, " .
                    "respondent group, or intended use DRIFT away from it. Data that does not serve the purpose is noise; an " .
                    "intended use the instrument cannot support is a validity threat; a demographic with no link to the purpose " .
                    "adds burden and bias without payoff.",
                'checks' => [
                    'purpose_unstated'          => 'Survey does not state why it is being administered',
                    'item_purpose_misalignment' => 'Item does not clearly serve the stated purpose',
                    'demographic_unjustified'   => 'Demographic/background data not connected to the purpose',
                    'use_mismatch'              => 'Stated use of results not supported by the survey content',
                    'scope_creep'               => 'Expands beyond the stated purpose, weakening focus',
                    'audience_purpose_mismatch' => 'Respondent group not positioned to answer for the purpose',
                ],
                'checkDesc' => [
                    'purpose_unstated'          => 'no purpose or intended use is stated, so alignment cannot be judged.',
                    'item_purpose_misalignment' => 'the item does not clearly inform the stated purpose.',
                    'demographic_unjustified'   => 'a demographic/background variable is collected with no stated link to the purpose.',
                    'use_mismatch'              => 'the stated use of results is not supported by the survey content.',
                    'scope_creep'               => 'the survey expands beyond the stated purpose, weakening its focus.',
                    'audience_purpose_mismatch' => 'the respondent group is not positioned to answer in a way that serves the purpose.',
                ],
                'severityHints' => [
                    'purpose_unstated' => 'major', 'item_purpose_misalignment' => 'moderate', 'demographic_unjustified' => 'moderate',
                    'use_mismatch' => 'major', 'scope_creep' => 'moderate', 'audience_purpose_mismatch' => 'major',
                ],
                'severityGuidance' =>
                    "Calibrate severity to how far the drift reaches: minor for an isolated item; moderate for a section or " .
                    "cluster of items; major when the drift threatens the stated USE of the results. Reserve critical for " .
                    "high-stakes, consequential decisions only — e.g. personnel/teacher evaluation, student placement, " .
                    "disciplinary decisions, resource/funding denial, eligibility or employment decisions.",
                'contextFields' => [
                    ['key' => 'purpose',          'label' => 'Stated survey purpose',                              'type' => 'textarea'],
                    ['key' => 'purpose_declared', 'label' => 'Reviewer-declared purpose (if the survey states none)', 'type' => 'textarea'],
                    ['key' => 'purpose_source',   'label' => 'Is the purpose explicit in the survey, or inferred?', 'type' => 'select',
                        'options' => ['stated', 'inferred', 'missing']],
                    ['key' => 'intended_use',       'label' => 'Intended use of findings',                'type' => 'text'],
                    ['key' => 'respondents',        'label' => 'Intended respondent group',               'type' => 'text'],
                    ['key' => 'decision_supported', 'label' => 'Intended decision the survey supports',   'type' => 'text'],
                    ['key' => 'stakes',             'label' => 'Stakes of the decisions the results will drive', 'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                    ['key' => 'required_demographics', 'label' => 'Required demographic/background variables', 'type' => 'list'],
                    ['key' => 'optional_demographics', 'label' => 'Optional demographic/background variables', 'type' => 'list'],
                ],
                'blockerConditions' => ['no_stated_purpose', 'high_stakes_use_mismatch'],
            ],

            'dimension_coverage' => [
                'label'  => 'Dimension / Domain Coverage',
                'noun'   => 'coverage',
                'weight' => 8,
                'principle' =>
                    "Judge whether the survey covers the major PARTS of the construct well enough to support interpretation. " .
                    "This is NOT about whether the construct is clearly defined (that is Construct Definition), and NOT about " .
                    "individual item wording or single-item drift (that is Item-to-Construct Alignment). Flag only when the " .
                    "overall domain map is incomplete, uneven, too thin, or includes dimensions that do not belong. " .
                    "A construct sampled too narrowly — or dominated by one facet — does not behave like the construct it claims to measure. " .
                    "If the survey intentionally measures only one narrow domain, do not flag domain_gap merely for omitting the broader " .
                    "construct; the mismatch between a broad stated construct and narrow coverage belongs to Construct Definition or Purpose Alignment.",
                'checks' => [
                    'dimension_missing'    => 'A stated or expected dimension of the construct has no item coverage',
                    'dimension_thin'       => 'A dimension has too few items or too little variety to support interpretation',
                    'domain_gap'           => 'An important part of the construct is not adequately represented',
                    'overrepresentation'   => 'One dimension or topic receives disproportionate item coverage',
                    'irrelevant_dimension' => 'A dimension/section is included that does not belong to the construct or purpose',
                    'dimension_overlap'    => 'Two or more dimensions are not conceptually distinct enough to interpret separately',
                ],
                'checkDesc' => [
                    'dimension_missing'    => 'a stated or expected dimension has no items mapped to it.',
                    'dimension_thin'       => 'a dimension has too few items or too little item variety to support meaningful interpretation.',
                    'domain_gap'           => 'an important part of the construct is not adequately represented, even if it was never formally named as a dimension.',
                    'overrepresentation'   => 'one dimension or topic receives disproportionate item coverage compared with the rest of the construct.',
                    'irrelevant_dimension' => 'a dimension or section does not clearly belong to the construct or the stated purpose.',
                    'dimension_overlap'    => 'two or more dimensions are not conceptually distinct (e.g. belonging/inclusion/connection measured the same way), so the survey appears more differentiated than the items support.',
                ],
                'severityHints' => [
                    'dimension_missing' => 'major', 'dimension_thin' => 'moderate', 'domain_gap' => 'major',
                    'overrepresentation' => 'moderate', 'irrelevant_dimension' => 'moderate', 'dimension_overlap' => 'moderate',
                ],
                'severityGuidance' =>
                    "Use minor only when the coverage issue is isolated and unlikely to affect interpretation. Use moderate when a " .
                    "dimension is thin, overlapping, or overrepresented but the overall construct is still interpretable. Use major " .
                    "when the survey omits or underrepresents a major part of the construct. Reserve critical for when the coverage " .
                    "problem makes the intended interpretation indefensible, especially in moderate- or high-stakes use.",
                'contextFields' => [
                    ['key' => 'construct',           'label' => 'Primary construct (reviewer-declared)', 'type' => 'text'],
                    ['key' => 'definition',          'label' => 'Construct definition',                  'type' => 'textarea'],
                    ['key' => 'purpose',             'label' => 'Stated survey purpose',                 'type' => 'textarea'],
                    ['key' => 'respondents',         'label' => 'Intended respondent group',             'type' => 'text'],
                    ['key' => 'intended_use',        'label' => 'Intended use of findings',              'type' => 'text'],
                    ['key' => 'expected_dimensions', 'label' => 'Expected dimensions/domains (one per line or comma-separated)', 'type' => 'list'],
                    ['key' => 'survey_dimensions',   'label' => 'Survey-provided dimensions/domains, if any', 'type' => 'list'],
                    ['key' => 'required_dimensions', 'label' => 'Dimensions required for interpretation', 'type' => 'list'],
                    ['key' => 'score_reporting',     'label' => 'How results will be reported',          'type' => 'select',
                        'options' => ['overall', 'subscale', 'both']],
                    ['key' => 'stakes',              'label' => 'Stakes of the decisions the results will drive', 'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                ],
                'blockerConditions' => ['major_coverage_gap'],
            ],

            'item_construct_alignment' => [
                'label'  => 'Item-to-Construct Alignment',
                'noun'   => 'item-alignment',
                'weight' => 7,
                'principle' =>
                    "Judge whether EACH item actually measures the construct or dimension it is assigned to. " .
                    "This is NOT about missing dimensions (Dimension / Domain Coverage), unclear purpose (Purpose Alignment), " .
                    "response choices (Response-Option Validity), or item redundancy / internal consistency (Reliability Readiness). " .
                    "Flag item by item when an item drifts off its assigned construct, is ambiguous, contaminated by a second " .
                    "construct, double-barreled, too broad, too context-dependent, or built on an assumption. " .
                    "Do NOT flag an item as off-construct merely because it is not part of a scale — first decide whether it " .
                    "measures a construct, supports routing, provides context, or is a standalone descriptive item; descriptive " .
                    "items should be marked descriptive, not punished for failing to align with a scale.",
                'checks' => [
                    'item_off_construct'         => 'Item does not clearly measure the assigned construct or dimension',
                    'item_ambiguous_mapping'     => 'Item could reasonably belong to more than one construct/dimension',
                    'construct_contamination'    => 'Item introduces a second construct that may distort interpretation',
                    'double_barreled'            => 'Item asks about two or more ideas at once',
                    'item_too_broad'             => 'Item is so broad that responses could reflect many different meanings',
                    'item_too_context_dependent' => 'Item cannot be interpreted without context the survey does not provide',
                    'item_assumption_loaded'     => 'Item assumes a condition/experience/belief that may not be true for the respondent',
                ],
                'checkDesc' => [
                    'item_off_construct'         => 'the item does not clearly measure the assigned construct or dimension.',
                    'item_ambiguous_mapping'     => 'the item could reasonably belong to more than one construct or dimension, so interpretation is unclear.',
                    'construct_contamination'    => 'the item appears to measure the assigned construct but actually introduces another (e.g. satisfaction, compliance, performance, behavior, attitude), distorting interpretation.',
                    'double_barreled'            => 'the item asks about two or more ideas at once, so it is unclear which part the respondent is answering.',
                    'item_too_broad'             => 'the item is so broad that responses could reflect many different experiences or meanings.',
                    'item_too_context_dependent' => 'the item cannot be interpreted without additional context the survey does not provide.',
                    'item_assumption_loaded'     => 'the item assumes a condition, experience, behavior, or belief that may not be true for the respondent.',
                ],
                'severityHints' => [
                    'item_off_construct' => 'major', 'item_ambiguous_mapping' => 'moderate', 'construct_contamination' => 'major',
                    'double_barreled' => 'moderate', 'item_too_broad' => 'moderate', 'item_too_context_dependent' => 'moderate',
                    'item_assumption_loaded' => 'moderate',
                ],
                'severityGuidance' =>
                    "Use minor only when the issue is isolated and the intended construct is still easy to interpret. Use moderate " .
                    "when the item creates ambiguity or weakens interpretation but does not threaten the entire scale. Use major " .
                    "when the item clearly measures the wrong construct, contaminates a scale, or could lead to misleading " .
                    "conclusions. Reserve critical for when item-level misalignment could support harmful or indefensible " .
                    "decisions in a high-stakes use case.",
                'contextFields' => [
                    ['key' => 'construct',           'label' => 'Primary construct (reviewer-declared)', 'type' => 'text'],
                    ['key' => 'definition',          'label' => 'Construct definition',                  'type' => 'textarea'],
                    ['key' => 'purpose',             'label' => 'Stated survey purpose',                 'type' => 'textarea'],
                    ['key' => 'respondents',         'label' => 'Intended respondent group',             'type' => 'text'],
                    ['key' => 'expected_dimensions', 'label' => 'Expected dimensions/domains (one per line or comma-separated)', 'type' => 'list'],
                    ['key' => 'survey_dimensions',   'label' => 'Survey-provided dimensions/domains',    'type' => 'list'],
                    ['key' => 'item_dimension_map',  'label' => 'Item-to-dimension map, if available',   'type' => 'textarea'],
                    ['key' => 'assignment_source',   'label' => 'How items are assigned to dimensions',  'type' => 'select',
                        'options' => ['survey', 'reviewer', 'ai', 'mixed']],
                    ['key' => 'score_reporting',     'label' => 'Intended score reporting',              'type' => 'select',
                        'options' => ['overall', 'subscale', 'item_level', 'mixed']],
                    ['key' => 'stakes',              'label' => 'Stakes of the decisions the results will drive', 'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                ],
                'blockerConditions' => [],
            ],

            'response_option_validity' => [
                'label'  => 'Response-Option Validity',
                'noun'   => 'response-option',
                'weight' => 4,
                'principle' =>
                    "Judge whether the RESPONSE OPTIONS let respondents answer each item accurately and meaningfully. " .
                    "This is NOT about item wording (unless the wording mismatches the options), redundancy or internal " .
                    "consistency (Reliability Readiness), or identity erasure as a dignity issue (Dignity / Framing). " .
                    "Flag only when the answer choices distort, restrict, confuse, or weaken the meaning of the response. " .
                    "SCORE ONCE, SURFACE TWICE: if an issue is primarily dignity, identity erasure, or extractive disclosure, " .
                    "it is scored under Dignity / Framing or Access — surface it here only as an answerability note and do NOT " .
                    "subtract again unless the response option itself creates a distinct measurement problem.",
                'checks' => [
                    'options_not_exhaustive'       => 'Options do not cover the likely range of valid answers',
                    'options_overlap'              => 'Options are not mutually exclusive; unclear which to choose',
                    'scale_mismatch'               => 'Response scale does not match the item stem or the judgment requested',
                    'missing_applicable_escape'    => 'Item lacks a needed N/A, “don’t know,” “prefer not to answer,” skip, or escape',
                    'unbalanced_scale'             => 'Options are unevenly weighted, leading, or biased toward one side',
                    'unclear_scale_labels'         => 'Scale labels are vague, inconsistent, incomplete, or hard to interpret',
                    'inconsistent_scale_direction' => 'Scale direction changes across similar items, confusing respondents',
                    'forced_choice_risk'           => 'Forces a single choice where respondents may need multiple, other, or none',
                ],
                'checkDesc' => [
                    'options_not_exhaustive'       => 'the options do not cover the likely range of valid answers.',
                    'options_overlap'              => 'two or more options are not mutually exclusive, so it is unclear which to choose.',
                    'scale_mismatch'               => 'the response scale does not match the item stem or the kind of judgment requested (e.g. a frequency stem with an agreement scale).',
                    'missing_applicable_escape'    => 'the item needs but does not provide an appropriate "not applicable", "don’t know", "prefer not to answer", skip, or other valid escape option.',
                    'unbalanced_scale'             => 'the options are unevenly weighted, leading, or biased toward one side of the response range.',
                    'unclear_scale_labels'         => 'the scale labels are vague, inconsistent, incomplete, or hard to interpret.',
                    'inconsistent_scale_direction' => 'the direction of the scale changes across similar items in a way that may confuse respondents or distort responses.',
                    'forced_choice_risk'           => 'the item forces a single choice when respondents may need to select multiple answers, explain another option, or indicate that none fit.',
                ],
                'severityHints' => [
                    'options_not_exhaustive' => 'moderate', 'options_overlap' => 'moderate', 'scale_mismatch' => 'major',
                    'missing_applicable_escape' => 'moderate', 'unbalanced_scale' => 'moderate', 'unclear_scale_labels' => 'moderate',
                    'inconsistent_scale_direction' => 'moderate', 'forced_choice_risk' => 'moderate',
                ],
                'severityGuidance' =>
                    "Use minor only when the issue is isolated and unlikely to affect interpretation. Use moderate when it may " .
                    "distort responses for a meaningful subset of respondents or items. Use major when the options make an item " .
                    "difficult to interpret or systematically misrepresent answers. Reserve critical for when the problem makes a " .
                    "required item unanswerable or unsafe in a moderate- or high-stakes use case. Do NOT flag a missing neutral/N/A " .
                    "merely because a neutral option is absent — some forced-choice designs are intentional; flag " .
                    "missing_applicable_escape only when respondents may genuinely lack a valid answer, lack information to answer, " .
                    "or need a safe way not to disclose.",
                'contextFields' => [
                    ['key' => 'construct',       'label' => 'Primary construct (reviewer-declared)', 'type' => 'text'],
                    ['key' => 'definition',      'label' => 'Construct definition',                  'type' => 'textarea'],
                    ['key' => 'purpose',         'label' => 'Stated survey purpose',                 'type' => 'textarea'],
                    ['key' => 'respondents',     'label' => 'Intended respondent group',             'type' => 'text'],
                    ['key' => 'intended_use',    'label' => 'Intended use of findings',              'type' => 'text'],
                    ['key' => 'item_types',      'label' => 'Item types present (agreement, frequency, satisfaction, importance, confidence, categorical, demographic, open-ended, mixed)', 'type' => 'list'],
                    ['key' => 'required_items',  'label' => 'Required items, if any',                'type' => 'list'],
                    ['key' => 'sensitive_items', 'label' => 'Sensitive or identity-related items, if any', 'type' => 'list'],
                    ['key' => 'forced_choice_intentional', 'label' => 'Are forced-choice items intentional?', 'type' => 'select',
                        'options' => ['yes', 'no', 'some']],
                    ['key' => 'escape_options_allowed',    'label' => 'Are N/A, prefer-not-to-answer, skip, or write-in options allowed?', 'type' => 'select',
                        'options' => ['yes', 'no', 'some']],
                    ['key' => 'stakes', 'label' => 'Stakes of the decisions the results will drive', 'type' => 'select',
                        'options' => ['low', 'moderate', 'high']],
                ],
                'blockerConditions' => ['unanswerable_scale'],
            ],

        ];
    }
    return $specs[$component] ?? null;
}

/** All valid component keys. */
function validity_components(): array
{
    return ['construct_definition', 'purpose_alignment', 'dimension_coverage', 'item_construct_alignment', 'response_option_validity'];
}

/**
 * Builds the AI proposer system prompt for one component. The model PROPOSES
 * flags with evidence; it never computes a score. Severity drives the penalty
 * downstream; the model must not emit numbers.
 */
function validity_system_prompt(array $spec): string
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
component of Validity Readiness. You are advisory: you PROPOSE issues with
verbatim evidence; a human makes the final call. You never compute the score.

{$spec['principle']}

This is a VALIDITY check only. NEVER use Cronbach's alpha, omega, item-total
correlations, or internal-consistency logic — scale length, redundancy, and
internal consistency belong to Reliability Readiness, not here.

THE CHECKS (use these exact keys):
{$checkBlock}

SEVERITY (propose one; the human may override): minor, moderate, major, critical.
 - minor: small weakness; the construct/purpose mapping mostly holds.
 - moderate: a real weakness that should be addressed.
 - major: a substantial validity threat for this component.
 - critical: the component fails as written.
{$severityGuidance}Do NOT output penalty numbers — severity alone drives the penalty downstream.

For EVERY proposed flag output: check, item_ref (the item id, a dimension name,
or a context field key like "definition"/"purpose" when the issue is in the
declared context rather than an item), quote (verbatim evidence from the item or
the declared context), severity, rationale (one line: why it fires AND why it
matters for validity), suggested_revision (a concrete fix).

Be conservative: only flag what the text actually supports, and always quote the
exact words. If nothing fires, return an empty flags array. Output STRICT JSON
only, no prose outside the JSON, in this shape:
{ "flags": [ { "check": "...", "item_ref": "...", "quote": "...", "severity": "...", "rationale": "...", "suggested_revision": "..." } ], "notes": "" }
SYS;
}
