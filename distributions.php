<?php
$mount_app            = 'descriptive';
$mount_lens           = 'distributions';
$mount_section        = 'descriptive';
$mount_item           = 'means_distributions';
$mount_breadcrumb     = ['Descriptive', 'Means & Distributions'];
$mount_title          = 'Means & Distributions';
$mount_intro          = 'Mean, SD, min/max, skew and kurtosis per numeric/Likert variable, with histograms.';
$mount_dataset_global = 'DESCRIPTIVE_DATASET';
$mount_lens_global    = 'DESCRIPTIVE_LENS';
$mount_stub = [
  'title' => 'Means &amp; Distributions',
  'body'  => '<strong>Mean, SD, min/max, skew, and kurtosis</strong> for every numeric or Likert variable, plus a histogram of the distribution. Click <strong>Run</strong> to see the shape of each item.',
];
include __DIR__ . '/_studio_mount.php';
