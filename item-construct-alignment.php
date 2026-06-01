<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'item_construct_alignment';
$mount_section        = 'instrument_quality';
$mount_item           = 'item_construct_alignment';
$mount_breadcrumb     = ['Instrument Quality', 'Item-to-Construct Alignment'];
$mount_title          = 'Item-to-Construct Alignment';
$mount_intro          = "A pre-data validity check: does each item map clearly to the construct or dimension it should measure? Off-construct, ambiguous, or double-barreled items inject construct-irrelevant variance. The AI proposes mapping issues with the exact wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic and traceable to the flagged items.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
