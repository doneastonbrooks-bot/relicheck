<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'redundancy_balance';
$mount_section        = 'instrument_quality';
$mount_item           = 'redundancy_balance';
$mount_breadcrumb     = ['Instrument Quality', 'Redundancy Balance'];
$mount_title          = 'Redundancy Balance';
$mount_intro          = "A pre-data reliability-readiness check: within a scale, are items related enough to measure the same construct but not so repetitive that they become duplicates? This is a review of CONCEPTUAL similarity only — it never calculates inter-item correlations. Too much repetition inflates apparent consistency; too little overlap means the items are not measuring one thing. The AI proposes redundancy and coverage issues with the wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic; no alpha, omega, item-total or inter-item correlations, or factor analysis is used.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
