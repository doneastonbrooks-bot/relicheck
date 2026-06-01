<?php
$mount_app            = 'descriptive';
$mount_lens           = 'cross_tabs';
$mount_section        = 'descriptive';
$mount_item           = 'cross_tabs';
$mount_breadcrumb     = ['Descriptive', 'Cross-tabs'];
$mount_title          = 'Cross-tabulations';
$mount_intro          = 'Two-way frequency tables for any pair of categorical variables, with row/column percentages.';
$mount_dataset_global = 'DESCRIPTIVE_DATASET';
$mount_lens_global    = 'DESCRIPTIVE_LENS';
$mount_stub = [
  'title' => 'Cross-tabulations',
  'body'  => 'Two-way frequency tables for any pair of <strong>categorical variables</strong>, with row, column, and total percentages plus heat-tinted cells. Click <strong>Run</strong> to see how groups differ.',
];
include __DIR__ . '/_studio_mount.php';
