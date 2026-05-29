<?php
// ReliCheck Studio Registry
// -------------------------------------------------------------------
// Source of truth for which studios exist and their identity. Used by:
//   - Platform Shell welcome (the studio picker tiles)
//   - Studio Template (the identity strip at the top of a studio page)
// Item visibility per studio lives in _studio_items_catalog.php.
//
// To add a new studio: add a key here (slug as the array key), then add
// items that target it in the catalog. The status field drives what shows
// on the welcome picker and what's editable in a given pass.
//   live = published; beta = limited use; demo = read-only sample;
//   dev  = in development (visible to internal users only)

return [
  'survey' => [
    'slug'           => 'survey',
    'name'           => 'Survey Studio',
    'mark'           => '/Survey%20Studio.png',
    'description'    => 'Build, distribute, and judge the strength of any survey. Reliability, validity, response quality, in one report.',
    'status'         => 'live',
    'status_label'   => 'Live',
    'accent'         => '#e85d3a',
    'accent_soft'    => '#fdeee9',
    'primary_action' => 'Run Strength Index',
    'primary_route'  => '/strength-index.php',
    'sample'         => [
      'project' => 'Workplace Equity Survey, 2026',
      'context' => '412 responses',
    ],
    'route' => '/studio-survey.php',
  ],
  'mm' => [
    'slug'           => 'mm',
    'name'           => 'MM Studio',
    'mark'           => '/MM%20Studio.png',
    'description'    => 'Mixed methods. Pair quantitative scales with qualitative themes under one explanatory, exploratory, or convergent design.',
    'status'         => 'beta',
    'status_label'   => 'Beta',
    'accent'         => '#6d4ad8',
    'accent_soft'    => '#efeafd',
    'primary_action' => 'Build Joint Display',
    'primary_route'  => '/joint-display.php',
    'sample'         => [
      'project' => 'Belonging & Retention Study',
      'context' => '212 surveys, 18 interviews',
    ],
    'route' => '/studio-mm.php',
  ],
  'tia' => [
    'slug'           => 'tia',
    'name'           => 'TIA Studio',
    'mark'           => '/TIA%20Studio.png',
    'description'    => 'Test and item analysis. Difficulty, discrimination, distractors, rubrics, and cognitive demand for classroom and standardized tests.',
    'status'         => 'dev',
    'status_label'   => 'In development',
    'accent'         => '#0e8a6f',
    'accent_soft'    => '#e3f5ee',
    'primary_action' => 'Run Item Analysis',
    'primary_route'  => '/item-quality.php',
    'sample'         => [
      'project' => '8th Grade Math Test, 2026',
      'context' => '184 students, 40 items',
    ],
    'route' => '/studio-tia.php',
  ],
  '360' => [
    'slug'           => '360',
    'name'           => '360 Studio',
    'mark'           => '/360%20Studio.png',
    'description'    => 'Multi-rater feedback. Self, peer, manager, and direct reports. Perception gaps, competency profiles, and growth signals.',
    'status'         => 'demo',
    'status_label'   => 'Demo',
    'accent'         => '#2563eb',
    'accent_soft'    => '#e6efff',
    'primary_action' => 'Open Perception Gaps',
    'primary_route'  => '/rater-group-comparison.php',
    'sample'         => [
      'project' => 'Leadership 360, Cohort 4',
      'context' => '12 ratees, 84 raters',
    ],
    'route' => '/studio-360.php',
  ],
  'rssi' => [
    'slug'           => 'rssi',
    'kind'           => 'app',
    'name'           => 'ReliCheck Strength Survey Index',
    'mark'           => '/rssi-icon.svg',
    'description'    => 'Put a survey in, get a polished credibility report out. One headline score, six diagnostic dimensions, top issues to fix — ready to print, share, or attach to a deliverable.',
    'status'         => 'live',
    'status_label'   => 'Live',
    'accent'         => '#007AFF',
    'accent_soft'    => '#F1F6FF',
    'primary_action' => 'Run Strength Report',
    'primary_route'  => '/rssi-report.php',
    'sample'         => [
      'project' => 'Workplace Equity Survey, 2026',
      'context' => '412 responses',
    ],
    'route' => '/rssi.php',
  ],
];
