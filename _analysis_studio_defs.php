<?php
// Shared definitions for the two analysis destinations (Descriptive +
// Inferential). Single source of truth so identity (colors, logo, routes,
// copy), the tool list, and the preview flag stay consistent across each
// studio's LANDING page (_analysis_studio_landing.php) and its WORKSPACE
// (_analysis_studio_shell.php). Keyed by studio slug.
//
// Route model (see [[project_studio_architecture]]):
//   main app tile  → studio_route   (landing page, explains the studio)
//   landing CTAs   → workspace_route (the analysis shell) or SIRI (create)
//
// Boundary: neither studio computes reliability / Cronbach's alpha / item
// analysis / strength scoring. Those live in RSSI.

return [

  'descriptive' => [
    'slug'            => 'descriptive',
    'name'            => 'Descriptive Analysis Studio',
    'question'        => 'What is present in the data?',
    'lede'            => 'Summarize and visualize your responses: frequencies, distributions, group comparisons, and item rankings. No claims, no reliability — just a clear picture of what the data shows.',
    'accent'          => '#c2271b',
    'accent_deep'     => '#a11f15',
    'accent_soft'     => '#fbe9e7',
    'mark'            => '/Descriptive%20Studio.png',
    'studio_route'    => '/descriptive-analysis-studio.php',
    'workspace_route' => '/descriptive-analysis-workspace.php',
    'siri_route'      => '/survey-dev.php',
    'rssi_route'      => '/rssi.php',
    'landing'         => [
      'about'   => 'Descriptive Analysis Studio summarizes and organizes your quantitative data before you make any claims or run statistical tests. It gives you a clear, honest picture of what is actually present in your responses.',
      'accepts' => 'Works with collected SIRI responses, an uploaded data file, or a saved project.',
      'can_do'  => [
        'Frequencies and percentages',
        'Means, medians, and standard deviations',
        'Distributions and visual summaries',
        'Cross-tabs',
        'Group summaries',
        'Item rankings (top and bottom)',
        'Descriptive scale summaries',
      ],
      'notes'   => [
        'Reliability is not computed here. Reliability lives in RSSI.',
      ],
    ],
    'tools'           => [
      ['key' => 'frequencies',      'label' => 'Frequencies',       'route' => '/frequencies.php',      'desc' => 'Counts & percentages'],
      ['key' => 'distributions',    'label' => 'Distributions',     'route' => '/distributions.php',    'desc' => 'Mean, median, SD, shape'],
      ['key' => 'cross_tabs',       'label' => 'Cross-Tabs',        'route' => '/cross-tabs.php',       'desc' => 'Two-variable contingency'],
      ['key' => 'group_summaries',  'label' => 'Group Summaries',   'route' => '/group-summaries.php',  'desc' => 'Means by group'],
      ['key' => 'top_bottom_items', 'label' => 'Top & Bottom Items','route' => '/top-bottom-items.php', 'desc' => 'Items ranked by mean'],
      ['key' => 'scale_scores',     'label' => 'Scale Scores',      'route' => '/scale-scores.php',     'desc' => 'Composite summaries (no reliability)'],
    ],
  ],

  'inferential' => [
    'slug'            => 'inferential',
    'name'            => 'Inferential Statistics Studio',
    'question'        => 'What can the data support?',
    'lede'            => 'Test differences, relationships, and models: t-tests, ANOVA, chi-square, correlation, regression, effect sizes, and assumptions — with supported interpretation.',
    'accent'          => '#c79114',
    'accent_deep'     => '#a87a0e',
    'accent_soft'     => '#fbf2dc',
    'mark'            => '/Inferential%20Studio.png',
    'studio_route'    => '/inferential-statistics-studio.php',
    'workspace_route' => '/inferential-statistics-workspace.php',
    'siri_route'      => '/survey-dev.php',
    'rssi_route'      => '/rssi.php',
    // Persistent "Variables & Fit" pre-analysis preview layer in the WORKSPACE
    // only. Decision support, not full descriptive analysis. No reliability.
    'preview'         => 'variables_fit',
    'landing'         => [
      'about'   => 'Inferential Statistics Studio helps you test differences, examine relationships, model outcomes, check assumptions, calculate effect sizes, and develop evidence-supported interpretations of your data.',
      'accepts' => 'Works with collected SIRI responses, an uploaded data file, or a saved project.',
      'can_do'  => [
        't-tests and Welch t-test',
        'One-way ANOVA, Welch ANOVA, and post-hoc comparisons',
        'Chi-square tests',
        'Correlation',
        'Regression',
        'Confidence intervals',
        'Effect sizes',
        'Assumption checks',
      ],
      'variables_fit' => 'Variables & Fit helps you understand your variables — types, valid n, missingness, distributions, and group sizes — before you choose a test, so you never select an analysis blindly. The live panel appears inside the workspace.',
      'notes'   => [
        'Full descriptive analysis belongs in Descriptive Analysis Studio.',
        'Reliability is not computed here. Reliability lives in RSSI.',
      ],
    ],
    'tools'           => [
      ['key' => 't_test',              'label' => 't-Test',              'route' => '/t-test.php',              'desc' => "Student's & Welch"],
      ['key' => 'anova',               'label' => 'One-Way ANOVA',       'route' => '/anova.php',               'desc' => 'Compare 3+ group means'],
      ['key' => 'chi_square',          'label' => 'Chi-Square',          'route' => '/chi-square.php',          'desc' => 'Categorical association'],
      ['key' => 'correlation',         'label' => 'Correlation',         'route' => '/correlation.php',         'desc' => 'Pearson relationship'],
      ['key' => 'paired_t_test',       'label' => 'Paired t-Test',       'route' => '/paired-t-test.php',       'desc' => 'Within-subject comparison'],
      ['key' => 'welch_anova',         'label' => 'Welch ANOVA',         'route' => '/welch-anova.php',         'desc' => 'Unequal variances'],
      ['key' => 'post_hoc',            'label' => 'Post-Hoc',            'route' => '/post-hoc.php',            'desc' => 'Pairwise comparisons'],
      ['key' => 'regression',          'label' => 'Regression',          'route' => '/regression.php',          'desc' => 'OLS, 1-2 predictors'],
      ['key' => 'confidence_interval', 'label' => 'Confidence Intervals','route' => '/confidence-interval.php', 'desc' => 'Mean / prop / diff / r'],
      ['key' => 'effect_sizes',        'label' => 'Effect Sizes',        'route' => '/effect-size.php',         'desc' => "Cohen's d, η², r, OR"],
      ['key' => 'assumption_checks',   'label' => 'Assumption Checks',   'route' => '/assumption-checks.php',   'desc' => 'Normality & homogeneity'],
    ],
  ],

];
