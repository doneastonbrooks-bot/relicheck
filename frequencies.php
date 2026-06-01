<?php
$mount_app            = 'descriptive';
$mount_lens           = 'frequencies';
$mount_section        = 'descriptive';
$mount_item           = 'frequencies';
$mount_breadcrumb     = ['Descriptive', 'Frequencies'];
$mount_title          = 'Frequencies';
$mount_intro          = 'Counts and percentages for every categorical and Likert variable, with bar visualizations.';
$mount_dataset_global = 'DESCRIPTIVE_DATASET';
$mount_lens_global    = 'DESCRIPTIVE_LENS';
$mount_stub = [
  'title' => 'Frequencies',
  'body'  => 'Counts and percentages for every <strong>categorical and Likert variable</strong>, with a bar visualization. Click <strong>Run</strong> to see how respondents answered each closed-ended question.',
];
include __DIR__ . '/_studio_mount.php';
