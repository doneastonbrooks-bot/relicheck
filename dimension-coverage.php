<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'dimension_coverage';
$mount_section        = 'instrument_quality';
$mount_item           = 'dimension_coverage';
$mount_breadcrumb     = ['Instrument Quality', 'Dimension / Domain Coverage'];
$mount_title          = 'Dimension / Domain Coverage';
$mount_intro          = "A pre-data validity check: does the survey cover the major dimensions of the construct without obvious gaps or overrepresentation? A construct sampled too narrowly — or dominated by one facet — is under-represented. The AI proposes coverage issues with the exact wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic, and a launch gate flags a major coverage gap regardless of the number.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
