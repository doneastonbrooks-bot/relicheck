// ReliCheck Analysis Type Taxonomy — JS mirror of api/dev/_type_taxonomy.php
// Single source of truth for variable classification in all studio clients.
//
// Principle: every variable has a database storage type AND an analysis role.
// These constants define the analysis layer. Storage type (INT, VARCHAR, TEXT)
// is a separate concern and must never drive analytic decisions.
//
// Usage: include before data-map.js and any studio pipeline JS.
// Exposes global: RCTaxonomy

(function () {
  'use strict';

  // ── Controlled vocabulary: analysis_type ──────────────────────────────────
  var ANALYSIS_TYPES = [
    'identifier',           // linking only — respondent_id, UUID; never analyzed
    'likert_item',          // ordered scale item measuring a latent construct (ordinal)
                            //   construct_id set   → scale item, eligible for reliability
                            //   construct_id absent → standalone ordinal item
    'scale_score',          // computed aggregate of likert_items; treated as interval
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
    'computed_score',       // score produced by a ReliCheck engine (SIRI, RSSI, domain score)
    'file_reference',       // attachment or file path — not analyzed
    'structural',           // non-data item (Section Text, Page Break, Instructions, Thank-you)
  ];

  // ── Human-readable labels for the data map UI ─────────────────────────────
  var LABELS = {
    identifier:           'Identifier',
    likert_item:          'Likert Item',
    scale_score:          'Scale Score',
    open_ended:           'Open-Ended Response',
    narrative:            'Narrative / Qualitative Text',
    qualitative_code:     'Qualitative Code',
    theme:                'Theme',
    demographic_nominal:  'Demographic (Nominal)',
    demographic_ordinal:  'Demographic (Ordinal)',
    demographic_numeric:  'Demographic (Numeric)',
    binary:               'Binary (Yes/No)',
    date_time:            'Date / Time',
    metadata:             'Metadata',
    computed_score:       'Computed Score',
    file_reference:       'File Reference',
    structural:           'Structural (Non-data)',
  };

  // ── Short definitions shown in data map tooltip ────────────────────────────
  var DEFINITIONS = {
    identifier:           'Links records only. Never statistically analyzed (e.g. respondent ID).',
    likert_item:          'Single ordered scale question measuring a latent construct. Treated as ordinal; becomes a scale item when assigned to a construct.',
    scale_score:          'Aggregate score computed from multiple Likert items (mean or sum). Treated as interval-level.',
    open_ended:           'Raw text response to a specific survey question. Supports coding, theme discovery, and qualitative analysis.',
    narrative:            'Extended qualitative text not anchored to a question prompt — transcripts, field notes, documents.',
    qualitative_code:     'A label applied to a text response (e.g. "student_dependency"). One response may have multiple codes.',
    theme:                'Higher-level qualitative pattern grouping several related codes.',
    demographic_nominal:  'Categorical group with no natural order (e.g. gender, role, school type).',
    demographic_ordinal:  'Ordered categorical group (e.g. education level, income band).',
    demographic_numeric:  'Numeric demographic value (e.g. age, years of experience).',
    binary:               'Exactly two mutually exclusive states (yes/no, true/false, pass/fail).',
    date_time:            'Temporal value — used for filtering and response-timing analysis.',
    metadata:             'System-generated context (e.g. submitted_at, source platform). Not directly analyzed.',
    computed_score:       'Score produced by a ReliCheck engine (SIRI, RSSI, domain score).',
    file_reference:       'File attachment or path. Not statistically analyzed.',
    structural:           'Non-data item — Section Text, Page Break, Instructions, Thank-you message.',
  };

  // ── Measurement level per analysis_type ───────────────────────────────────
  var MEASUREMENT_LEVEL = {
    identifier:           'nominal',
    likert_item:          'ordinal',
    scale_score:          'interval',
    open_ended:           'text',
    narrative:            'text',
    qualitative_code:     'nominal',
    theme:                'nominal',
    demographic_nominal:  'nominal',
    demographic_ordinal:  'ordinal',
    demographic_numeric:  'ratio',
    binary:               'dichotomous',
    date_time:            'temporal',
    metadata:             'none',
    computed_score:       'interval',
    file_reference:       'none',
    structural:           'none',
  };

  // ── Controlled vocabulary: role ───────────────────────────────────────────
  var ROLES = [
    'item',        // belongs to a scale/construct; analyzed for reliability
    'outcome',     // dependent variable in a comparison or regression
    'predictor',   // independent variable / covariate
    'grouping',    // splits respondents into comparison groups
    'linking',     // identifier / join key; no statistical analysis
    'contextual',  // provides background context; not directly analyzed
  ];

  var ROLE_LABELS = {
    item:       'Scale item',
    outcome:    'Outcome variable',
    predictor:  'Predictor variable',
    grouping:   'Grouping variable',
    linking:    'Linking / ID only',
    contextual: 'Contextual',
  };

  // ── Allowed analyses per analysis_type ────────────────────────────────────
  // Keys match operation identifiers used by studio pipeline steps.
  var ALLOWED_ANALYSES = {
    identifier:           [],
    likert_item:          ['distribution', 'missing_data', 'descriptives', 'item_total_correlation', 'alpha_if_deleted', 'reliability', 'scale_score'],
    scale_score:          ['descriptives', 'group_comparison', 'correlation', 'regression', 'distribution'],
    open_ended:           ['coding', 'theme_discovery', 'sentiment', 'exemplar_quotes', 'frequency'],
    narrative:            ['semantic_search', 'deep_coding', 'content_analysis', 'ai_interpretation'],
    qualitative_code:     ['code_frequency', 'co_occurrence', 'theme_counts'],
    theme:                ['frequency', 'theme_by_group', 'mixed_methods_display'],
    demographic_nominal:  ['frequencies', 'percentages', 'crosstab', 'chi_square', 'subgroup_comparison', 'equity_gap'],
    demographic_ordinal:  ['frequencies', 'percentages', 'ordered_comparison', 'median', 'rank_based_analysis'],
    demographic_numeric:  ['descriptives', 'group_comparison', 'correlation', 'regression'],
    binary:               ['proportions', 'chi_square', 'phi_coefficient', 'logistic_regression'],
    date_time:            ['temporal_filter', 'response_timing'],
    metadata:             ['filter', 'audit_trail'],
    computed_score:       ['descriptives', 'distribution', 'group_comparison'],
    file_reference:       [],
    structural:           [],
  };

  // Human-readable labels for each analysis operation (used in suggested-analyses column).
  var ANALYSIS_LABELS = {
    distribution:           'Response distribution',
    missing_data:           'Missing data',
    descriptives:           'Descriptive statistics',
    item_total_correlation: 'Item-total correlation',
    alpha_if_deleted:       'Alpha if deleted',
    reliability:            'Reliability analysis (α, ω)',
    scale_score:            'Compute scale score',
    group_comparison:       'Group comparison (t-test / ANOVA)',
    correlation:            'Correlation',
    regression:             'Regression',
    coding:                 'Thematic coding',
    theme_discovery:        'Theme discovery',
    sentiment:              'Sentiment analysis',
    exemplar_quotes:        'Exemplar quotes',
    frequency:              'Frequency analysis',
    semantic_search:        'Semantic search',
    deep_coding:            'Deep coding',
    content_analysis:       'Content analysis',
    ai_interpretation:      'ReliCheck Intelligence interpretation',
    code_frequency:         'Code frequency',
    co_occurrence:          'Code co-occurrence',
    theme_counts:           'Theme counts',
    theme_by_group:         'Theme by group',
    mixed_methods_display:  'Mixed-methods joint display',
    frequencies:            'Frequencies',
    percentages:            'Percentages',
    crosstab:               'Cross-tabulation',
    chi_square:             'Chi-square test',
    subgroup_comparison:    'Subgroup comparison',
    equity_gap:             'Equity gap analysis',
    ordered_comparison:     'Ordered comparison',
    median:                 'Median',
    rank_based_analysis:    'Rank-based analysis',
    proportions:            'Proportions',
    phi_coefficient:        'Phi coefficient',
    logistic_regression:    'Logistic regression',
    temporal_filter:        'Temporal filter',
    response_timing:        'Response timing',
    filter:                 'Filter / segment',
    audit_trail:            'Audit trail',
  };

  // ── Map: SIRI builder display type → analysis_type ────────────────────────
  // Rating Scale, Slider, and NPS are ambiguous.
  // Call resolveDisplayType(displayType, constructId) rather than reading this directly.
  var DISPLAY_TYPE_MAP = {
    // Likert family
    'Likert Scale':       'likert_item',
    'Likert (5-pt)':      'likert_item',
    'Likert (7-pt)':      'likert_item',
    'Rating Scale':       'likert_item',   // ambiguous
    'Rating':             'likert_item',   // legacy alias, ambiguous
    'NPS':                'likert_item',   // ambiguous
    'Slider':             'likert_item',   // ambiguous
    // Purely numeric
    'Numeric':            'demographic_numeric',
    'Number':             'demographic_numeric',
    // Nominal categorical
    'Multiple Choice':    'demographic_nominal',
    'Single Choice':      'demographic_nominal',
    'Checkboxes':         'demographic_nominal',
    'Dropdown':           'demographic_nominal',
    'Demographic':        'demographic_nominal',
    'Matrix/Grid':        'demographic_nominal',
    'Matrix':             'demographic_nominal',
    // Ordinal categorical
    'Ranking':            'demographic_ordinal',
    // Binary
    'Yes/No':             'binary',
    'True/False':         'binary',
    'Consent':            'binary',
    // Open-ended
    'Open-Ended':         'open_ended',
    'Short Answer':       'open_ended',
    'Long Answer':        'open_ended',
    'Comment Box':        'open_ended',
    // Identifiers
    'Email':              'identifier',
    'Phone':              'identifier',
    // Temporal
    'Date':               'date_time',
    // Structural
    'Section Text':       'structural',
    'Page Break':         'structural',
    'Thank-you Message':  'structural',
    'Instructions':       'structural',
  };

  var AMBIGUOUS_DISPLAY_TYPES = ['Rating Scale', 'Rating', 'NPS', 'Slider'];

  // ── Public API ─────────────────────────────────────────────────────────────

  window.RCTaxonomy = {

    // Full lists
    analysisTypes:    ANALYSIS_TYPES,
    roles:            ROLES,

    // Lookup maps
    labels:           LABELS,
    definitions:      DEFINITIONS,
    measurementLevel: MEASUREMENT_LEVEL,
    roleLabels:       ROLE_LABELS,
    allowedAnalyses:  ALLOWED_ANALYSES,
    analysisLabels:   ANALYSIS_LABELS,

    /**
     * Resolve a SIRI builder display type to its canonical analysis_type.
     *
     * @param {string}      displayType  The item type from survey_items.type
     * @param {number|null} constructId  The item's constructId; null = unassigned
     * @returns {string}    A value from analysisTypes
     */
    resolveDisplayType: function (displayType, constructId) {
      var type = DISPLAY_TYPE_MAP[displayType] || 'open_ended';
      if (AMBIGUOUS_DISPLAY_TYPES.indexOf(displayType) !== -1 && !constructId) {
        return 'demographic_numeric';
      }
      return type;
    },

    /**
     * Validate that a string is in the controlled vocabulary.
     */
    isValid: function (analysisType) {
      return ANALYSIS_TYPES.indexOf(analysisType) !== -1;
    },

    /**
     * Return the allowed analysis operations for an analysis_type.
     * @returns {string[]}
     */
    getAllowedAnalyses: function (analysisType) {
      return ALLOWED_ANALYSES[analysisType] || [];
    },

    /**
     * Return human-readable labels for allowed analyses on an analysis_type.
     * @returns {Array<{key: string, label: string}>}
     */
    getAllowedAnalysesLabeled: function (analysisType) {
      return (ALLOWED_ANALYSES[analysisType] || []).map(function (key) {
        return { key: key, label: ANALYSIS_LABELS[key] || key };
      });
    },

    /**
     * Return true when a variable is eligible for reliability analysis
     * (likert_item assigned to a construct).
     */
    isReliabilityEligible: function (analysisType, constructId) {
      return analysisType === 'likert_item' && !!constructId;
    },

    /**
     * Return true when a variable type produces numeric data and can
     * participate in quantitative analyses (group comparison, regression, etc.).
     */
    isQuantitative: function (analysisType) {
      return ['likert_item', 'scale_score', 'demographic_numeric', 'binary', 'computed_score'].indexOf(analysisType) !== -1;
    },

    /**
     * Return true when a variable type holds text and can participate
     * in qualitative analyses.
     */
    isQualitative: function (analysisType) {
      return ['open_ended', 'narrative', 'qualitative_code', 'theme'].indexOf(analysisType) !== -1;
    },

    /**
     * Return true when a variable should be excluded from analysis display
     * (identifiers, structural items, file references).
     */
    isNonData: function (analysisType) {
      return ['identifier', 'structural', 'file_reference', 'metadata'].indexOf(analysisType) !== -1;
    },
  };

}());
