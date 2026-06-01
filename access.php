<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'access';
$mount_section        = 'instrument_quality';
$mount_item           = 'access';
$mount_breadcrumb     = ['Instrument Quality', 'Access Readiness'];
$mount_title          = 'Access Readiness';
$mount_intro          = "A pre-data validity check: can the intended respondents actually reach the items — read them, understand them, answer them in their language, and finish without undue burden? The AI proposes access barriers with the exact phrase that triggered each; you keep, dismiss, or re-grade them. The score is deterministic, and a launch gate stops exclusionary barriers regardless of the number.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
