<?php
// Missing Data — thin mount, lens of apps/descriptive/. The engine owns
// the per-variable + per-row missingness tables, pattern detection, and
// interpretation. Single source of truth across studios.
$mount_app            = 'descriptive';
$mount_lens           = 'missing_data';
$mount_section        = 'descriptive';
$mount_item           = 'missing_data';
$mount_breadcrumb     = ['Descriptive', 'Missing Data'];
$mount_title          = 'Missing Data';
$mount_intro          = "Where is the data missing, and is the pattern systematic? Per-variable rates, per-row buckets, and a pattern hint.";
$mount_dataset_global = 'DESCRIPTIVE_DATASET';
$mount_lens_global    = 'DESCRIPTIVE_LENS';
include __DIR__ . '/_studio_mount.php';
