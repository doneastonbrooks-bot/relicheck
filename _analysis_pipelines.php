<?php
// Pipeline definitions for the analysis studios (Descriptive + Inferential).
// Mirrors the SHAPE of MM's pipeline (a numbered left-rail sequence) WITHOUT
// touching MM. Each studio has a fixed, linear pipeline: a Start step, then
// its analysis steps, then a Report step. Keyed by studio kind.
//
// Each step: id, label, dot (strand color class: 'quan' here — these studios
// are quantitative), mode ('start' | 'work' | 'report'), and for work steps a
// 'tool' key the workspace uses to render the MM-style presentation.

return [
  'descriptive' => [
    ['id' => 'start',            'label' => 'Start',                'dot' => 'quan', 'mode' => 'start'],
    ['id' => 'overview',         'label' => 'Overview',             'dot' => 'quan', 'mode' => 'overview'],
    ['id' => 'frequencies',      'label' => 'Frequencies',          'dot' => 'quan', 'mode' => 'work', 'tool' => 'frequencies'],
    ['id' => 'distributions',    'label' => 'Means & Distributions','dot' => 'quan', 'mode' => 'work', 'tool' => 'distributions'],
    ['id' => 'cross_tabs',       'label' => 'Cross-Tabs',           'dot' => 'quan', 'mode' => 'work', 'tool' => 'cross_tabs'],
    ['id' => 'group_summaries',  'label' => 'Group Summaries',      'dot' => 'quan', 'mode' => 'work', 'tool' => 'group_summaries'],
    ['id' => 'top_bottom_items', 'label' => 'Top & Bottom Items',   'dot' => 'quan', 'mode' => 'work', 'tool' => 'top_bottom_items'],
    ['id' => 'scale_scores',     'label' => 'Scale Scores',         'dot' => 'quan', 'mode' => 'work', 'tool' => 'scale_scores'],
    ['id' => 'report',           'label' => 'Report',               'dot' => 'quan', 'mode' => 'report'],
  ],
  'inferential' => [
    ['id' => 'start',            'label' => 'Start',                'dot' => 'quan', 'mode' => 'start'],
    ['id' => 'overview',         'label' => 'Overview',             'dot' => 'quan', 'mode' => 'overview'],
    ['id' => 'variables_fit',    'label' => 'Variables & Fit',      'dot' => 'quan', 'mode' => 'work', 'tool' => 'variables_fit'],
    ['id' => 't_test',           'label' => 't-Test',               'dot' => 'quan', 'mode' => 'work', 'tool' => 't_test'],
    ['id' => 'anova',            'label' => 'ANOVA',                'dot' => 'quan', 'mode' => 'work', 'tool' => 'anova'],
    ['id' => 'chi_square',       'label' => 'Chi-Square',           'dot' => 'quan', 'mode' => 'work', 'tool' => 'chi_square'],
    ['id' => 'correlation',      'label' => 'Correlation',          'dot' => 'quan', 'mode' => 'work', 'tool' => 'correlation'],
    ['id' => 'regression',       'label' => 'Regression',           'dot' => 'quan', 'mode' => 'work', 'tool' => 'regression'],
    ['id' => 'effect_sizes',     'label' => 'Effect Sizes',         'dot' => 'quan', 'mode' => 'work', 'tool' => 'effect_sizes'],
    ['id' => 'report',           'label' => 'Report',               'dot' => 'quan', 'mode' => 'report'],
  ],
];
