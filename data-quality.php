<?php
// Data Quality — about the DATASET (completion, duplicates, response
// behavior). Distinct from Item / Prompt Quality which is about each
// item's psychometric properties (item-rest, discrimination, etc.).
$mount_app            = 'overview';
$mount_lens           = 'data_quality';
$mount_section        = 'overview';
$mount_item           = 'data_quality';
$mount_breadcrumb     = ['Overview', 'Data Quality'];
$mount_title          = 'Data Quality';
$mount_intro          = "Is the data itself trustworthy? Completion rate, duplicates, straight-lining, response time, and missingness. (For psychometric properties of individual items — discrimination, item-rest, ceiling/floor — see Instrument Quality → Item / Prompt Quality.)";
$mount_dataset_global = 'OVERVIEW_DATASET';
$mount_lens_global    = 'OVERVIEW_LENS';
include __DIR__ . '/_studio_mount.php';
