<?php
$mount_app            = 'descriptive';
$mount_lens           = 'group_summaries';
$mount_section        = 'descriptive';
$mount_item           = 'group_summaries';
$mount_breadcrumb     = ['Descriptive', 'Group Summaries'];
$mount_title          = 'Group Summaries';
$mount_intro          = 'Means and counts broken down by each categorical variable. Pick a grouping variable in the panel.';
$mount_dataset_global = 'DESCRIPTIVE_DATASET';
$mount_lens_global    = 'DESCRIPTIVE_LENS';
$mount_stub = [
  'title' => 'Group Summaries',
  'body'  => 'Means and counts broken down by each <strong>categorical variable</strong> (e.g., department, role, grade level). Click <strong>Run</strong> to see how subgroups compare against the overall average.',
];
include __DIR__ . '/_studio_mount.php';
