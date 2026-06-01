<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'response_scale_consistency';
$mount_section        = 'instrument_quality';
$mount_item           = 'response_scale_consistency';
$mount_breadcrumb     = ['Instrument Quality', 'Response Scale Consistency'];
$mount_title          = 'Response Scale Consistency';
$mount_intro          = "A pre-data reliability-readiness check: do similar items use consistent response formats and anchors? Inconsistent formats within a scale add avoidable measurement noise. This is distinct from Response-Option Validity (whether a scale matches its stem) — here the concern is similar or sibling items, or items meant to be combined into the same scale, using inconsistent response formats without clear rationale. The AI proposes consistency issues with the wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic; no alpha, omega, item-total or inter-item correlations, or factor analysis is used.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
