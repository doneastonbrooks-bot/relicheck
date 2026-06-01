<?php
$mount_app = 'descriptive'; $mount_lens = 'scale_scores';
$mount_section = 'descriptive'; $mount_item = 'scale_scores';
$mount_breadcrumb = ['Descriptive', 'Scale Scores'];
$mount_title = 'Scale Scores';
$mount_intro = 'Composite means and SDs per named scale (e.g., Belonging, Voice). Distribution of total scores across respondents.';
$mount_dataset_global = 'DESCRIPTIVE_DATASET'; $mount_lens_global = 'DESCRIPTIVE_LENS';
$mount_stub = [
  'title' => 'Scale Scores',
  'body'  => 'Pick a set of Likert items, compute their <strong>composite score</strong> (sum or item-mean), get <strong>Cronbach&rsquo;s &alpha;</strong> for internal consistency, and see the distribution of the composite across respondents. Click <strong>Run</strong> to start.',
];
include __DIR__ . '/_studio_mount.php';
