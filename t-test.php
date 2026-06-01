<?php
$mount_app            = 'inferential';
$mount_lens           = 't_test';
$mount_section        = 'inferential';
$mount_item           = 't_test';
$mount_breadcrumb     = ['Inferential', 't-test'];
$mount_title          = 'Independent-samples t-test';
$mount_intro          = 'Compare the means of one continuous variable across two groups. Reports t, df, p, and Cohen\'s d with assumption checks.';
$mount_dataset_global = 'INFERENTIAL_DATASET';
$mount_lens_global    = 'INFERENTIAL_TEST';
include __DIR__ . '/_studio_mount.php';
