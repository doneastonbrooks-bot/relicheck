<?php
// ReliCheck Analysis Type Taxonomy — single source of truth for variable
// classification across all RE systems (SIRI, RSSI, SDSI, all studios).
//
// Include with:  require_once __DIR__ . '/_type_taxonomy.php';
//
// Principle: every variable has a database storage type AND an analysis role.
// These constants define the analysis layer. Storage type (INT, VARCHAR, TEXT)
// is a separate concern and must never drive analytic decisions.

// ── Controlled vocabulary: analysis_type ────────────────────────────────────
// All systems must validate incoming analysis_type values against this list.
const RC_ANALYSIS_TYPES = [
    'identifier',           // linking only — respondent_id, UUID; never analyzed
    'likert_item',          // ordered scale item measuring a latent construct (ordinal)
                            //   construct_id IS NOT NULL → treated as a scale item for reliability
                            //   construct_id IS NULL     → standalone ordinal item
    'scale_score',          // computed aggregate of likert_items (mean/sum); treated as interval
    'open_ended',           // raw text response to a specific survey question prompt
    'narrative',            // extended qualitative text not anchored to a question
                            //   (interview transcripts, field notes, documents)
    'qualitative_code',     // code label applied to a text response
    'theme',                // higher-level qualitative pattern grouping multiple codes
    'demographic_nominal',  // nominal category — no natural order (gender, role, school_type)
    'demographic_ordinal',  // ordered category (education_level, income_band, grade_level)
    'demographic_numeric',  // numeric demographic value (age, years_teaching, caseload_size)
    'binary',               // exactly two mutually exclusive states (yes/no, pass/fail)
    'date_time',            // temporal value (submitted_at, birth_date)
    'metadata',             // system-generated context data — not analyzed directly
    'computed_score',       // score produced by a ReliCheck engine (SIRI, RSSI, sub-domain score)
    'file_reference',       // attachment or file path — not analyzed
    'structural',           // non-data item (Section Text, Page Break, Instructions, Thank-you)
];

// ── Measurement level per analysis_type ─────────────────────────────────────
// Used to gate valid statistical operations and display the right labels in UI.
const RC_MEASUREMENT_LEVEL = [
    'identifier'          => 'nominal',
    'likert_item'         => 'ordinal',
    'scale_score'         => 'interval',
    'open_ended'          => 'text',
    'narrative'           => 'text',
    'qualitative_code'    => 'nominal',
    'theme'               => 'nominal',
    'demographic_nominal' => 'nominal',
    'demographic_ordinal' => 'ordinal',
    'demographic_numeric' => 'ratio',
    'binary'              => 'dichotomous',
    'date_time'           => 'temporal',
    'metadata'            => 'none',
    'computed_score'      => 'interval',
    'file_reference'      => 'none',
    'structural'          => 'none',
];

// ── Controlled vocabulary: role ──────────────────────────────────────────────
// Role describes how a variable is used in a specific analysis — distinct from
// what it IS (analysis_type). A demographic_nominal variable can be a 'grouping'
// role in one analysis and a 'predictor' in another. Stored in variable_metadata.
const RC_ROLES = [
    'item',        // belongs to a scale/construct; analyzed for reliability
    'outcome',     // dependent variable in a comparison or regression
    'predictor',   // independent variable / covariate
    'grouping',    // splits respondents into comparison groups
    'linking',     // identifier / join key; no statistical analysis
    'contextual',  // provides background context; not directly analyzed
];

// ── Allowed analyses per analysis_type ──────────────────────────────────────
// Keys are analysis operation identifiers used by studios to decide which
// pipeline steps and report sections to offer for a given variable.
const RC_ALLOWED_ANALYSES = [
    'identifier'          => [],
    'likert_item'         => [
        'distribution',           // response frequency per scale point
        'missing_data',           // count and rate of missing responses
        'descriptives',           // mean, SD, median (treating ordinal as interval)
        'item_total_correlation', // corrected item-total correlation within construct
        'alpha_if_deleted',       // Cronbach alpha if this item were removed
        'reliability',            // full reliability analysis (requires construct_id)
        'scale_score',            // compute aggregate score across construct items
    ],
    'scale_score'         => [
        'descriptives',           // mean, SD, min, max, skew
        'group_comparison',       // t-test or ANOVA by grouping variable
        'correlation',            // Pearson r with another scale_score or numeric
        'regression',             // linear regression (outcome or predictor)
        'distribution',           // histogram / normality check
    ],
    'open_ended'          => [
        'coding',                 // manual or AI-assisted thematic coding
        'theme_discovery',        // unsupervised theme identification
        'sentiment',              // positive / neutral / negative classification
        'exemplar_quotes',        // surfacing representative responses
        'frequency',              // code or word frequency counts
    ],
    'narrative'           => [
        'semantic_search',        // find responses matching a concept
        'deep_coding',            // intensive thematic / discourse analysis
        'content_analysis',       // structured category-based analysis
        'ai_interpretation',      // ReliCheck Intelligence assisted interpretation
    ],
    'qualitative_code'    => [
        'code_frequency',         // how often each code appears
        'co_occurrence',          // codes that appear together in the same response
        'theme_counts',           // roll-up counts by parent theme
    ],
    'theme'               => [
        'frequency',              // theme prevalence across responses
        'theme_by_group',         // theme frequency broken down by demographic group
        'mixed_methods_display',  // joint display linking theme counts to scale scores
    ],
    'demographic_nominal' => [
        'frequencies',            // count per category
        'percentages',            // proportion per category
        'crosstab',               // cross-tabulation with another categorical variable
        'chi_square',             // test of independence
        'subgroup_comparison',    // compare scale scores across categories
        'equity_gap',             // flag outcome differences across demographic groups
    ],
    'demographic_ordinal' => [
        'frequencies',
        'percentages',
        'ordered_comparison',     // compare across ordered categories
        'median',
        'rank_based_analysis',    // Mann-Whitney, Kruskal-Wallis
    ],
    'demographic_numeric' => [
        'descriptives',
        'group_comparison',
        'correlation',
        'regression',
    ],
    'binary'              => [
        'proportions',            // percent true / false
        'chi_square',
        'phi_coefficient',        // effect size for 2x2 tables
        'logistic_regression',
    ],
    'date_time'           => [
        'temporal_filter',        // filter responses by date range
        'response_timing',        // time-to-completion, submission trends
    ],
    'metadata'            => [
        'filter',                 // use as a filter / segment variable
        'audit_trail',            // display in response audit view
    ],
    'computed_score'      => [
        'descriptives',
        'distribution',
        'group_comparison',
    ],
    'file_reference'      => [],
    'structural'          => [],
];

// ── Map: SIRI builder display type → analysis_type ──────────────────────────
// Used when loading a SIRI-native project into any analysis studio.
// Rating Scale, Slider, and NPS are mapped to 'likert_item' as the smart default
// on the assumption that they are construct-assigned; call rc_analysis_type()
// which will fall back to 'demographic_numeric' when construct_id is absent.
const RC_DISPLAY_TYPE_MAP = [
    // Likert family
    'Likert Scale'       => 'likert_item',
    'Likert (5-pt)'      => 'likert_item',
    'Likert (7-pt)'      => 'likert_item',
    'Rating Scale'       => 'likert_item',   // ambiguous — see rc_analysis_type()
    'Rating'             => 'likert_item',   // legacy alias
    'NPS'                => 'likert_item',   // ambiguous — see rc_analysis_type()
    'Slider'             => 'likert_item',   // ambiguous — see rc_analysis_type()
    // Purely numeric
    'Numeric'            => 'demographic_numeric',
    'Number'             => 'demographic_numeric',
    // Nominal categorical
    'Multiple Choice'    => 'demographic_nominal',
    'Single Choice'      => 'demographic_nominal',  // legacy
    'Checkboxes'         => 'demographic_nominal',
    'Dropdown'           => 'demographic_nominal',
    'Demographic'        => 'demographic_nominal',
    'Matrix/Grid'        => 'demographic_nominal',
    'Matrix'             => 'demographic_nominal',  // legacy
    // Ordinal categorical
    'Ranking'            => 'demographic_ordinal',
    // Binary
    'Yes/No'             => 'binary',
    'True/False'         => 'binary',
    'Consent'            => 'binary',
    // Open-ended
    'Open-Ended'         => 'open_ended',    // legacy
    'Short Answer'       => 'open_ended',
    'Long Answer'        => 'open_ended',
    'Comment Box'        => 'open_ended',
    // Identifiers / contact info
    'Email'              => 'identifier',
    'Phone'              => 'identifier',
    // Temporal
    'Date'               => 'date_time',
    // Structural — not data
    'Section Text'       => 'structural',
    'Page Break'         => 'structural',
    'Thank-you Message'  => 'structural',
    'Instructions'       => 'structural',
];

// Ambiguous display types: treat as likert_item when construct-assigned,
// demographic_numeric when standalone.
const RC_AMBIGUOUS_TYPES = ['Rating Scale', 'Rating', 'NPS', 'Slider'];

// ── Map: datasets.column_meta legacy type → analysis_type ───────────────────
// Used when seeding variable_metadata from an uploaded dataset at link time.
// These are initial guesses; the DataMap lets users confirm or correct them.
const RC_DATASET_TYPE_MAP = [
    'likert'      => 'likert_item',
    'single'      => 'demographic_nominal',
    'multi'       => 'demographic_nominal',
    'open'        => 'open_ended',
    'numeric'     => 'demographic_numeric',
    'criterion'   => 'scale_score',
    'demographic' => 'demographic_nominal',
    'identifier'  => 'identifier',
    'ignore'      => 'structural',
    'free_text'   => 'open_ended',
    'date'        => 'date_time',
];

// ── Functions ────────────────────────────────────────────────────────────────

/**
 * Map a datasets.column_meta legacy type string to canonical analysis_type.
 * Falls back to 'open_ended' for unknown values.
 */
function rc_analysis_type_from_dataset_type(string $datasetType): string
{
    return RC_DATASET_TYPE_MAP[$datasetType] ?? 'open_ended';
}

/**
 * Resolve a SIRI builder display type to its canonical analysis_type.
 *
 * @param string   $displayType  The item type string stored in survey_items.type
 * @param int|null $constructId  The item's construct_id from settings JSON; null = unassigned
 * @return string  A value from RC_ANALYSIS_TYPES
 */
function rc_analysis_type(string $displayType, ?int $constructId = null): string
{
    $type = RC_DISPLAY_TYPE_MAP[$displayType] ?? 'open_ended';
    if (in_array($displayType, RC_AMBIGUOUS_TYPES, true) && $constructId === null) {
        return 'demographic_numeric';
    }
    return $type;
}

/**
 * Validate that a string is in the RC_ANALYSIS_TYPES controlled vocabulary.
 */
function rc_valid_analysis_type(string $type): bool
{
    return in_array($type, RC_ANALYSIS_TYPES, true);
}

/**
 * Return the measurement level for an analysis_type.
 */
function rc_measurement_level(string $analysisType): string
{
    return RC_MEASUREMENT_LEVEL[$analysisType] ?? 'none';
}

/**
 * Return the allowed analysis operations for an analysis_type.
 *
 * @return string[]
 */
function rc_allowed_analyses(string $analysisType): array
{
    return RC_ALLOWED_ANALYSES[$analysisType] ?? [];
}

/**
 * Return true when a variable with this analysis_type contributes to
 * reliability scoring (i.e. it is a scale item in a construct).
 */
function rc_is_reliability_eligible(string $analysisType, ?int $constructId = null): bool
{
    return $analysisType === 'likert_item' && $constructId !== null;
}
