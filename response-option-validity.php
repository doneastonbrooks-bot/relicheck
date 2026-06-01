<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'response_option_validity';
$mount_section        = 'instrument_quality';
$mount_item           = 'response_option_validity';
$mount_breadcrumb     = ['Instrument Quality', 'Response-Option Validity'];
$mount_title          = 'Response-Option Validity';
$mount_intro          = "A pre-data validity check: do the response choices let respondents answer each item meaningfully and accurately? This judges the options themselves — not scale length or redundancy, which are reliability concerns. The AI proposes response-option issues with the exact wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic, and a launch gate flags a scale that does not fit its stem regardless of the number.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
