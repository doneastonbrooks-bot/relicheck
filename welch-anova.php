<?php
$mount_app = 'inferential_extensions'; $mount_lens = 'welch_anova';
$mount_section = 'inferential'; $mount_item = 'welch_anova';
$mount_breadcrumb = ['Inferential', 'Welch\'s ANOVA'];
$mount_title = 'Welch\'s ANOVA';
$mount_intro = 'ANOVA without the equal-variance assumption. Use when groups have very different SDs or sample sizes.';
$mount_dataset_global = 'INFEXT_DATASET'; $mount_lens_global = 'INFEXT_LENS';
include __DIR__ . '/_studio_mount.php';
