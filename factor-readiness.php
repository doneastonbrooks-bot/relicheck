<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'factor_readiness';
$mount_section        = 'instrument_quality';
$mount_item           = 'factor_readiness';
$mount_breadcrumb     = ['Instrument Quality', 'Factor Readiness'];
$mount_title          = 'Factor Readiness';
$mount_intro          = "Before you run a factor analysis, you need to know the data can support one. Kaiser-Meyer-Olkin sampling adequacy and Bartlett's test of sphericity say yes or no in numbers, with the threshold cut-offs called out explicitly.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
$mount_stub = [
  'title' => 'Factor Readiness',
  'body'  => 'Before you run a factor analysis, the data has to support one. This view computes <strong>Kaiser-Meyer-Olkin sampling adequacy</strong> and <strong>Bartlett\'s test of sphericity</strong>, calling out the threshold cut-offs explicitly. A simple yes or no, in numbers.',
];
include __DIR__ . '/_studio_mount.php';
