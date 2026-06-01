<?php
// ReliCheck Analysis App Registry
// -------------------------------------------------------------------
// Apps are self-contained, plug-and-play modules. Each app declares
// which studios may mount it (via 'studios' = studio slugs from
// _studio_registry.php), what it produces ('outputs'), and what it
// consumes ('inputs'). A studio page mounts an app by including the
// app's render file inside the .studio-work column.
//
// Universal apps (data upload, descriptives, etc.) list all four
// studios. Methodology-specific apps list only the studios that need
// them. Slugs match _studio_registry.php; see [[relicheck-studio-registry]]
// for the slug stability rule.
//
// To add an app:
//   1. Create /apps/<app-key>/ with render.php, <key>.css, <key>.js
//   2. Add an entry here with key, name, studios, render, css, js,
//      inputs, outputs.
//   3. The studio pages that mount it require this registry and
//      include the app's render file inside .studio-work.

return [

  // Survey Deployment Workspace — survey-studio-only. Configures audience,
  // access, identity mode, channels, schedule, reminders, and branding before
  // launch. Readiness score (0–100 from 11 weighted checks) guards the Launch
  // button. POSTs to /api/surveys/update.php to publish the survey.
  // Mount page: /survey-deploy.php?studio=survey&project_id=N
  'deploy' => [
    'key'         => 'deploy',
    'name'        => 'Survey Deployment',
    'category'    => 'Deploy',
    'studios'     => ['survey'],
    'render'      => __DIR__ . '/apps/deploy/render.php',
    'css'         => '/apps/deploy/deploy.css',
    'js'          => '/apps/deploy/deploy.js',
    'inputs'      => [],
    'outputs'     => ['is_published'],
    'description' => 'Full deployment workspace for survey projects. Seven configuration sections (Audience, Access Control, Identity Mode, Deployment Channel, Schedule, Reminder Strategy, Branding & Intro) feed an 11-check readiness score. Scoring is weighted (audience 15, identity 15, access 10, channel 10, schedule 10, intro 10, confidentiality statement 10, reminder 5, preview 5, survey length 5, open-ended balance 5). Launch button is enabled at score ≥ 50. At score ≥ 80 status reads "Ready to Launch". POSTs {id, is_published: true} to /api/surveys/update.php on launch.',
  ],

  // Evidence Intake: one shared engine + four per-studio configs.
  // Mount via /evidence-intake.php?studio=<slug>. See
  // [[relicheck-evidence-intake]] memory for the architecture.
  // Configs live at /apps/evidence-intake/configs/<slug>.config.php.
  // The two earlier apps (data_upload + data_upload_test) are
  // superseded; their old URLs redirect to this one.
  'evidence_intake' => [
    'key'         => 'evidence_intake',
    'name'        => 'Evidence Intake',
    'category'    => 'Setup',
    'studios'     => ['survey', 'mm', 'tia', '360'],
    'render'      => __DIR__ . '/apps/evidence-intake/render.php',
    'css'         => '/apps/evidence-intake/evidence-intake.css',
    'js'          => '/apps/evidence-intake/evidence-intake.js',
    'configs_dir' => __DIR__ . '/apps/evidence-intake/configs/',
    'inputs'      => [],
    'outputs'     => ['dataset', 'answer_key?'],
    'description' => 'One shared upload engine with four per-studio configs rendered as four discrete Evidence Intake Wizards (Survey, MM, TIA, 360). The engine handles drag/paste/choose, parsing, schema detection, the role-mapping checkbox table, and (for TIA) the answer-key step. Each config drives step labels, accepted evidence types, type-column checkboxes, sample data, and (in later passes) validation rules and analysis readiness.',
  ],

  // First real analysis app. Composites Reliability + Validity + Response
  // Quality + Completion + Coverage into one 0-100 score. v1 implements
  // Survey-shape math (Cronbach's α on Likert, k=10 anonymity floor on
  // categorical demographics). TIA / MM / 360 variants come later when
  // each studio's instrument-quality math is implemented.
  'strength_index' => [
    'key'         => 'strength_index',
    'name'        => 'ReliCheck Strength Index',
    'category'    => 'Quality',
    'studios'     => ['survey'],
    'render'      => __DIR__ . '/apps/strength-index/render.php',
    'css'         => '/apps/strength-index/strength-index.css',
    'js'          => '/apps/strength-index/strength-index.js',
    'inputs'      => ['dataset'],
    'outputs'     => ['strength_score', 'component_scores', 'interpretation'],
    'description' => 'Composite instrument-quality score from five components (Reliability via Cronbach\'s α on Likert items, Validity from inter-item correlation, Response Quality from straight-lining, Completion from missingness, Coverage from k=10 anonymity floor). Single 0-100 score, per-component breakdown, and a plain-language interpretation that points at the weakest component.',
  ],

  // Open-Ended Summary: universal descriptive layer for free-text fields.
  // Mounts in all four studios via /open-ended-summary.php?studio=<slug>;
  // each studio supplies its own language config (e.g. "open-ended responses"
  // in Survey, "comments" in 360). Produces per-question stats (response
  // rate, length distribution, top words, sample quotes) and aggregate
  // signals that feed the Strength Index Open-Ended Alignment domain.
  // Theme extraction and codebooks live in MM Studio's Theme Analysis app.
  'open_ended_summary' => [
    'key'         => 'open_ended_summary',
    'name'        => 'Open-Ended Summary',
    'category'    => 'Descriptive',
    'studios'     => ['survey', 'mm', 'tia', '360'],
    'render'      => __DIR__ . '/apps/open-ended-summary/render.php',
    'css'         => '/apps/open-ended-summary/open-ended-summary.css',
    'js'          => '/apps/open-ended-summary/open-ended-summary.js',
    'configs_dir' => __DIR__ . '/apps/open-ended-summary/configs/',
    'inputs'      => ['dataset'],
    'outputs'     => ['open_ended_summary', 'open_ended_signals'],
    'description' => 'Per-question descriptive read on open-ended fields: response rate, word-count stats, length distribution, top words and bigrams (stopword-filtered), and three representative sample quotes. Aggregates across all open fields and exposes the signals (response_rate, mean_words, substantive_count) consumed by the Strength Index Open-Ended Alignment domain. Universal across studios via one engine + four per-studio language configs.',
  ],

  // Inferential: one engine, four tests (t-test, ANOVA, chi-square,
  // correlation). Real p-values via the regularized incomplete beta
  // (t and F) and incomplete gamma (chi-square) functions. Mount pages:
  //   /t-test.php, /anova.php, /chi-square.php, /correlation.php
  // Each ?studio=<slug> pins the test via window.INFERENTIAL_TEST so the
  // shared engine renders only that test on the dedicated route.
  // Effect sizes: Cohen's d, η² + ω², Cramer's V, r². Per-test assumption
  // checks rendered inline. Sets the universal RELICHECK_APP_STATE.
  'inferential' => [
    'key'         => 'inferential',
    'name'        => 'Inferential Analysis',
    'category'    => 'Inferential',
    'studios'     => ['survey', 'mm', 'tia', '360'],
    'render'      => __DIR__ . '/apps/inferential/render.php',
    'css'         => '/apps/inferential/inferential.css',
    'js'          => '/apps/inferential/inferential.js',
    'inputs'      => ['dataset'],
    'outputs'     => ['test_result', 'statistic', 'p_value', 'effect_size', 'interpretation'],
    'description' => 'One engine, four tests: Welch\'s / Student\'s t-test, one-way ANOVA, chi-square test of independence, and Pearson correlation. Real p-values from regularized incomplete beta (t, F) and incomplete gamma (chi-square) continued fractions. Reports the statistic, exact p-value, an effect-size band (Cohen\'s d / η² / Cramer\'s V / r²), the relevant assumption checks, a plain-language headline, and a recommended next step. Mount via /t-test.php?studio=<slug>, /anova.php?studio=<slug>, /chi-square.php?studio=<slug>, or /correlation.php?studio=<slug>.',
  ],

  // Effect Size: size-led complement to the inferential suite. Three
  // modes (from-data / from-summary / convert). Computes Cohen's d,
  // η² + ω², Cramer's V, Pearson r + r², and odds ratio. From-summary
  // supports paper-reading workflows (paste M / SD / n or a 2x2 count
  // table). Convert mode maps d ↔ r ↔ η² ↔ OR using Cohen (1988) and
  // Chen, Cohen & Chen (2010) for OR thresholds. Mounts at
  // /effect-size.php?studio=<slug>; rail item effect_sizes under
  // Inferential.
  'effect_size' => [
    'key'         => 'effect_size',
    'name'        => 'Effect Size',
    'category'    => 'Inferential',
    'studios'     => ['survey', 'mm', 'tia', '360'],
    'render'      => __DIR__ . '/apps/effect-size/render.php',
    'css'         => '/apps/effect-size/effect-size.css',
    'js'          => '/apps/effect-size/effect-size.js',
    'inputs'      => ['dataset?'],
    'outputs'     => ['effect_size_value', 'effect_size_band', 'ci95?', 'conversions?'],
    'description' => 'Compute and convert effect sizes. From raw data (pick variables), from summary stats (means/SDs/ns or 2x2 counts), or convert between Cohen\'s d, Pearson r, η², and odds ratio. Reports the size, band (Cohen 1988 thresholds), 95% CI where applicable (Hedges & Olkin for d, Fisher z for r, Wald on log OR), and a one-line interpretation. A sticky reference card shows the band thresholds for every metric.',
  ],

  // Interpretation Suite: one engine, eight lenses. Each lens reads
  // saved blocks from localStorage and the current dataset, then
  // renders prose / lists / tables in a shared markup shell. Mount
  // pages pin the lens via window.INTERPRETATION_LENS:
  //   /ai-interpretation.php       lens: ai_interpretation
  //   /key-findings.php             lens: key_findings
  //   /practical-significance.php   lens: practical_significance
  //   /limitations.php              lens: limitations
  //   /recommended-actions.php      lens: recommended_actions
  //   /teaching-moments.php         lens: teaching_moments
  //   /decision-readiness.php       lens: decision_readiness
  //   /evidence-alignment.php       lens: evidence_alignment
  // The engine is rule-based v1; an AI pass (real API call against the
  // same payload) is the planned upgrade and is flagged in the UI.
  'interpretation' => [
    'key'         => 'interpretation',
    'name'        => 'Interpretation',
    'category'    => 'Interpretation',
    'studios'     => ['survey', 'mm', 'tia', '360'],
    'render'      => __DIR__ . '/apps/interpretation/render.php',
    'css'         => '/apps/interpretation/interpretation.css',
    'js'          => '/apps/interpretation/interpretation.js',
    'inputs'      => ['saved_blocks', 'dataset?'],
    'outputs'     => ['interpretation_text', 'verdict?', 'flags?', 'actions?', 'ranked_findings?'],
    'description' => 'One engine, eight lenses on the same saved-blocks corpus. Lenses: AI Interpretation (plain-language read of one block), Key Findings (ranks blocks, surfaces top 3-5), Practical Significance (translates effect sizes to real-world meaning), Limitations (scans for n / missingness / reliability / multiple-comparisons / sparse-cell warnings), Recommended Actions (turns findings into next steps), Teaching Moments (explains the method used), Decision Readiness (judges overall evidence strength: exploratory / operational / high-stakes), and Evidence Alignment (maps findings against a project-stated purpose). Reads from relicheck.report.<project_id>.default and relicheck.dataset.<project_id>.',
  ],

  // Reporting Suite: one engine, eight lenses, all consuming the same
  // saved-blocks corpus. Mount pages:
  //   /report-builder.php       lens: report_builder     (interactive: include/exclude/reorder/notes/title)
  //   /executive-summary.php    lens: executive_summary  (auto-drafted)
  //   /methodology.php          lens: methodology        (auto-drafted)
  //   /findings.php             lens: findings           (polished report-ready findings)
  //   /tables-figures.php       lens: tables_figures     (numbered tables)
  //   /recommendations.php      lens: recommendations    (formal recommendation list)
  //   /export.php               lens: export             (PDF/HTML/Markdown/JSON/CSV downloads)
  //   /appendix.php             lens: appendix           (per-block full payload)
  // The Report Builder writes back to localStorage (block order, include
  // map, custom title, inline notes); all other lenses are read-only.
  'reporting' => [
    'key'         => 'reporting',
    'name'        => 'Reporting',
    'category'    => 'Reporting',
    'studios'     => ['survey', 'mm', 'tia', '360'],
    'render'      => __DIR__ . '/apps/reporting/render.php',
    'css'         => '/apps/reporting/reporting.css',
    'js'          => '/apps/reporting/reporting.js',
    'inputs'      => ['saved_blocks', 'dataset?', 'purpose?'],
    'outputs'     => ['report_html', 'report_markdown', 'report_json', 'report_csv', 'blocks_included'],
    'description' => 'Assembles saved analyses into a deliverable. Report Builder is interactive: title editing, include/exclude per block, drag-free reorder, inline notes, clear-all. Seven read-only lenses render specific report sections: Executive Summary (auto-drafted), Methodology, Findings (report-ready), Tables &amp; Figures (numbered), Recommendations, Export (PDF via print, plus HTML/Markdown/JSON/CSV/clipboard downloads), and Appendix (full payloads). All persistence is project-scoped in localStorage.',
  ],

  // Descriptive Suite: one engine, six lenses, all reading the current
  // dataset (uploaded via Evidence Intake or the page's demo). Mount
  // pages:
  //   /frequencies.php          lens: frequencies
  //   /cross-tabs.php           lens: cross_tabs
  //   /distributions.php        lens: distributions
  //   /group-summaries.php      lens: group_summaries
  //   /top-bottom-items.php     lens: top_bottom_items
  //   /scale-scores.php         lens: scale_scores
  // Skewness, kurtosis, Cronbach's alpha, etc. are computed from raw
  // values; CSS bars / histograms render without a charting library.
  'descriptive' => [
    'key'         => 'descriptive',
    'name'        => 'Descriptive Suite',
    'category'    => 'Descriptive',
    'studios'     => ['survey', 'mm', 'tia', '360'],
    'render'      => __DIR__ . '/apps/descriptive/render.php',
    'css'         => '/apps/descriptive/descriptive.css',
    'js'          => '/apps/descriptive/descriptive.js',
    'inputs'      => ['dataset'],
    'outputs'     => ['frequencies?', 'cross_tabs?', 'distribution?', 'group_means?', 'item_rankings?', 'scale_composite?'],
    'description' => 'Six descriptive lenses on the current dataset: Frequencies (count + cumulative % for a categorical/Likert variable, with bar visualization), Cross-Tabs (2D contingency with row/col/total %, heat-tinted cells, marginals), Distributions (CSS histogram + n / mean / median / mode / SD / variance / quantiles / IQR / skewness / kurtosis), Group Summaries (per-group means with deviation bars from the grand mean), Top &amp; Bottom Items (every Likert item ranked by mean, top-3 / bottom-3 callouts, low-variance / ceiling / floor flags), and Scale Scores (pick items, compute sum or item-mean composite, Cronbach\'s α of the picked items, distribution of the composite).',
  ],

  // Inferential Extensions: six advanced inferential lenses sharing
  // one engine, separate from the core Inferential Suite. Mount pages:
  //   /paired-t-test.php          lens: paired_t
  //   /welch-anova.php            lens: welch_anova
  //   /post-hoc.php               lens: post_hoc          (Games-Howell + Bonferroni-Holm)
  //   /regression.php             lens: regression       (OLS, 1-2 predictors)
  //   /confidence-interval.php    lens: confidence_interval (mean / prop / mean-diff / r)
  //   /assumption-checks.php      lens: assumption_check (normality + Levene's)
  // Statistical helpers (incomplete beta/gamma, t/F/chi-square p-values,
  // matrix inverse, normal CDF) are inlined; the app is self-contained.
  'inferential_extensions' => [
    'key'         => 'inferential_extensions',
    'name'        => 'Inferential Extensions',
    'category'    => 'Inferential',
    'studios'     => ['survey', 'mm', 'tia', '360'],
    'render'      => __DIR__ . '/apps/inferential-extensions/render.php',
    'css'         => '/apps/inferential-extensions/inferential-extensions.css',
    'js'          => '/apps/inferential-extensions/inferential-extensions.js',
    'inputs'      => ['dataset'],
    'outputs'     => ['test_result', 'statistic', 'p_value', 'effect_size?', 'ci?', 'recommendation?'],
    'description' => 'Six lenses extending the core inferential suite: Paired T-Test (within-subject comparison with Cohen\'s d_z + 95% CI), Welch ANOVA (F-test that handles unequal variances), Post-Hoc Comparison (pairwise Welch t-tests with Bonferroni-Holm correction, defensible without the studentized range distribution), Regression (OLS with 1 or 2 predictors, full coefficient table with SEs / t / p / CI, R² / adjusted R² / overall F), Confidence Interval (t-based mean, Wilson proportion, Welch mean-difference, Fisher-z r), and Assumption Check (skewness/kurtosis + Kolmogorov-Smirnov-style normality test + Levene\'s test for homogeneity of variance, with explicit recommendation of the right test variant).',
  ],

  // Overview Suite: three meta-lenses on the dataset.
  //   /project-snapshot.php  lens: project_snapshot
  //   /sample-profile.php    lens: sample_profile
  //   /data-quality.php      lens: data_quality
  'overview' => [
    'key'         => 'overview',
    'name'        => 'Overview Suite',
    'category'    => 'Setup',
    'studios'     => ['survey', 'mm', 'tia', '360'],
    'render'      => __DIR__ . '/apps/overview/render.php',
    'css'         => '/apps/overview/overview.css',
    'js'          => '/apps/overview/overview.js',
    'inputs'      => ['dataset'],
    'outputs'     => ['snapshot?', 'sample_profile?', 'data_quality_score?', 'data_quality_checks?'],
    'description' => 'Three lenses on the dataset before analysis begins: Project Snapshot (scorecard of n, variables by type, completion %, response date range, purpose statement, full variable list with missingness), Sample Profile (stacked frequency tables for every categorical variable with bar visualization, useful for confirming sample composition matches expectations), and Data Quality (7-check audit covering duplicate full rows, duplicate IDs, straight-lining on Likert items, numeric outliers via Tukey 1.5×IQR, invalid values, low-effort opens under 5 chars, and high item-level missingness; produces a 0-100 quality score with band, alert/warn/clean counts, and a plain-language readiness interpretation).',
  ],

  // Instrument Quality Detail Suite — six dedicated views on the
  // instrument's quality. Most of the math overlaps with the Strength
  // Index but each lens presents it differently (and adds Bartlett's
  // test of sphericity + per-item MSA + correlation-based scale
  // clustering + response-option distribution).
  //   /validity.php           lens: validity
  //   /item-quality.php       lens: item_quality
  //   /scale-structure.php    lens: scale_structure
  //   /factor-readiness.php   lens: factor_readiness
  //   /bias-clarity.php       lens: bias_clarity
  //   /response-scale.php     lens: response_scale
  'instrument_quality' => [
    'key'         => 'instrument_quality',
    'name'        => 'Instrument Quality Detail',
    'category'    => 'Quality',
    'studios'     => ['survey', 'mm', 'tia', '360'],
    'render'      => __DIR__ . '/apps/instrument-quality/render.php',
    'css'         => '/apps/instrument-quality/instrument-quality.css',
    'js'          => '/apps/instrument-quality/instrument-quality.js',
    'inputs'      => ['dataset'],
    'outputs'     => ['kmo?', 'bartlett?', 'scales?', 'item_diagnostics?', 'validity_concerns?'],
    'description' => 'Six instrument-quality lenses: Validity Review (Cronbach\'s α + average inter-item r + item-rest flags + content concerns list), Item Quality (full per-item diagnostic table with mean / SD / missingness / ceiling / floor / skew / kurtosis and a per-item flag count), Scale Structure (greedy correlation-merge clustering of Likert items into candidate scales at r ≥ 0.40, with α and avg r per cluster), Factor Readiness (Kaiser-Meyer-Olkin overall + per-item MSA + Bartlett\'s test of sphericity via χ² on the determinant of the correlation matrix), Bias &amp; Clarity Review (rule-based variable-name analysis for double-barreled markers, negation, embedded digits, all-caps, with AI-hook for future item-prompt analysis), and Response Scale Review (per-Likert-item bar chart of response-option use, ceiling / floor / midpoint-overuse / range-restriction flags).',
  ],

  // TIA Studio Analysis — five lenses on student-by-item test data.
  //   /item-difficulty.php           lens: item_difficulty
  //   /item-discrimination.php       lens: item_discrimination
  //   /distractor-analysis.php       lens: distractor_analysis
  //   /answer-key-validation.php     lens: answer_key_validation
  //   /dif.php                       lens: dif
  // Dataset shape: variables with types ['item_response'] + an
  // answer_key array attached to the dataset.
  'tia_analysis' => [
    'key'         => 'tia_analysis',
    'name'        => 'TIA Analysis',
    'category'    => 'Quality',
    'studios'     => ['tia'],
    'render'      => __DIR__ . '/apps/tia-analysis/render.php',
    'css'         => '/apps/tia-analysis/tia-analysis.css',
    'js'          => '/apps/tia-analysis/tia-analysis.js',
    'inputs'      => ['dataset', 'answer_key'],
    'outputs'     => ['item_difficulty?', 'item_discrimination?', 'distractor_breakdown?', 'miskey_flags?', 'dif_gaps?'],
    'description' => 'Five test-item-analysis lenses: Item Difficulty (p-value per item with easy/moderate/hard bands), Item Discrimination (point-biserial r + Kelley\'s upper-lower D index on the top and bottom 27% of students), Distractor Analysis (for MC items: which distractors function, which are unused, which dangerously attract top scorers), Answer Key Validation (flags items where the upper group\'s most common answer disagrees with the key — a near-certain sign of miskey), and Differential Item Functioning (per-item p-value gap across a 2-level demographic with mild/strong DIF flags). Uses an inline sample of 20 students × 8 MC items in the demo (one item is deliberately miskeyed to showcase the validation lens).',
  ],

  // MM Studio Analysis Suite — seven lenses for mixed-methods work.
  // Codebook is project-scoped state in localStorage at
  // relicheck.codebook.<project_id>; the analysis lenses all consume it.
  //   /codebook-builder.php       lens: codebook_builder    (interactive)
  //   /theme-analysis.php          lens: theme_analysis
  //   /quote-extractor.php         lens: quote_extractor
  //   /theme-by-group.php          lens: theme_by_group
  //   /joint-display.php           lens: joint_display       (reads saved blocks)
  //   /integration-quality.php     lens: integration_quality
  //   /qual-to-quant.php           lens: qual_to_quant       (CSV export)
  'mm_analysis' => [
    'key'         => 'mm_analysis',
    'name'        => 'MM Analysis',
    'category'    => 'Quality',
    'studios'     => ['mm'],
    'render'      => __DIR__ . '/apps/mm-analysis/render.php',
    'css'         => '/apps/mm-analysis/mm-analysis.css',
    'js'          => '/apps/mm-analysis/mm-analysis.js',
    'inputs'      => ['dataset', 'codebook'],
    'outputs'     => ['codebook?', 'theme_tags?', 'quotes?', 'theme_by_group?', 'integration_score?', 'qq_csv?'],
    'description' => 'Seven mixed-methods lenses: Codebook Builder (interactive CRUD on a project-scoped codebook with name, description, keywords per theme; persists to localStorage; "Suggest from data" auto-discovers candidate themes from bigram clustering), Theme Analysis (auto-tags open-ended responses against the codebook by keyword match; falls back to auto-discovery when codebook is empty), Quote Extractor (3-5 representative quotes per theme, scored by keyword density × response length), Theme by Group (theme × demographic cross-tab with heat-tinted cells), Joint Display (quant findings from saved blocks alongside qual themes), Integration Quality (0-100 rubric across quant breadth, qual breadth, qual coverage, and quant-qual co-presence), and Qual → Quant Variable Builder (converts every theme into a 0/1 indicator variable per respondent, exportable as CSV for downstream inferential analysis).',
  ],

  // 360 Studio Analysis Suite — seven lenses on rater × ratee × competency data.
  'threesixty_analysis' => [
    'key'         => 'threesixty_analysis',
    'name'        => '360 Analysis',
    'category'    => 'Quality',
    'studios'     => ['360'],
    'render'      => __DIR__ . '/apps/threesixty/render.php',
    'css'         => '/apps/threesixty/threesixty.css',
    'js'          => '/apps/threesixty/threesixty.js',
    'inputs'      => ['dataset', 'codebook?'],
    'outputs'     => ['rater_means?', 'self_other_gaps?', 'profile?', 'confidentiality_violations?', 'comment_themes?', 'dev_plan?', 'cohort_stats?'],
    'description' => 'Seven 360-feedback lenses on ratee × rater × competency-score data: Rater Group Comparison (per-competency means by Self / Peer / Manager / Direct Report with the others-aggregate column), Self/Other Gap (self ratings vs the mean of others per competency, banded as aligned / moderate / over-rates / under-rates), Competency Profile (per-competency aggregate ranked, with top-2 strengths and bottom-2 gaps highlighted), Confidentiality Threshold (flags rater groups with n < k=3, the k-anonymity floor for raters), Comment Theme (theme analysis on comment fields by rater group, reuses the MM codebook), Development Plan (auto-drafted focus-areas synthesis from the lowest competencies and biggest self-others gaps), and Cohort Summary (aggregate across all ratees: cohort-wide strengths, gaps, and SD across ratees per competency). Inline sample: 4 ratees × 6 raters each (Self + 2 Peer + 1 Manager + 2 Direct Report) × 5 competencies + comment field.',
  ],

];
