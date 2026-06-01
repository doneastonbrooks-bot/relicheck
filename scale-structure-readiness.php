<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'scale_structure_readiness';
$mount_section        = 'instrument_quality';
$mount_item           = 'scale_structure_readiness';
$mount_breadcrumb     = ['Instrument Quality', 'Scale Structure Readiness'];
$mount_title          = 'Scale Structure Readiness';
$mount_intro          = "A pre-data reliability-readiness check: does the survey have a declared and defensible item structure before any data exists? It asks whether intended scales/subscales are declared, whether each has enough items, whether single- and two-item measures are handled honestly, and whether the scoring plan matches the structure. It is NOT a statistical check — no alpha, omega, item-total or inter-item correlations, or factor analysis. The AI proposes structure issues with the wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic, and a launch gate flags an undeclared or indefensible structure regardless of the number.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
