<?php
$mount_app = 'inferential_extensions'; $mount_lens = 'paired_t';
$mount_section = 'inferential'; $mount_item = 'paired_t_test';
$mount_breadcrumb = ['Inferential', 'Paired t-test'];
$mount_title = 'Paired-samples t-test';
$mount_intro = 'Compare two related measurements on the same respondents (pre/post, or two scales). Reports t, df, p, and Cohen\'s d for paired data.';
$mount_dataset_global = 'INFEXT_DATASET'; $mount_lens_global = 'INFEXT_LENS';
include __DIR__ . '/_studio_mount.php';
