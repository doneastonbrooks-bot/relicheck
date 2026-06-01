<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'administration_consistency';
$mount_section        = 'instrument_quality';
$mount_item           = 'administration_consistency';
$mount_breadcrumb     = ['Instrument Quality', 'Administration Consistency for Reliability'];
$mount_title          = 'Administration Consistency for Reliability';
$mount_intro          = "A pre-data reliability-readiness check: are the conditions under which respondents answer standardized enough to support consistent responses? This lens belongs to Reliability Readiness only when a problem affects the CONSISTENCY of response conditions — for example, when branching or skip logic exposes respondents to a required scale inconsistently. Broader fielding, consent, safety, timing, and launch logistics are handled later by Administration Readiness. The AI proposes consistency issues with the wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic; no alpha, omega, item-total or inter-item correlations, or factor analysis is used.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
