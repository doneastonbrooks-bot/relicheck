<?php
$mount_app = 'descriptive'; $mount_lens = 'top_bottom_items';
$mount_section = 'descriptive'; $mount_item = 'top_bottom_items';
$mount_breadcrumb = ['Descriptive', 'Top / Bottom Items'];
$mount_title = 'Top / Bottom Items';
$mount_intro = 'The 5 highest- and 5 lowest-scoring items across all your Likert scales, with means and SDs.';
$mount_dataset_global = 'DESCRIPTIVE_DATASET'; $mount_lens_global = 'DESCRIPTIVE_LENS';
$mount_stub = [
  'title' => 'Top &amp; Bottom Items',
  'body'  => 'Every <strong>Likert item ranked by mean</strong>, with top-3 strengths and bottom-3 weaknesses surfaced. Flags low-variance items, ceiling effects, and floor effects. Click <strong>Run</strong> to see what your respondents agree and disagree with most.',
];
include __DIR__ . '/_studio_mount.php';
