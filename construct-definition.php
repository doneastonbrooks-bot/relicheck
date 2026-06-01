<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'construct_definition';
$mount_section        = 'instrument_quality';
$mount_item           = 'construct_definition';
$mount_breadcrumb     = ['Instrument Quality', 'Construct Definition'];
$mount_title          = 'Construct Definition';
$mount_intro          = "A pre-data validity check: does the survey clearly name and define the primary construct it claims to measure? You cannot establish validity for a construct you have not bounded. The AI proposes definition issues with the exact wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic, and a launch gate flags an undefined construct regardless of the number.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
