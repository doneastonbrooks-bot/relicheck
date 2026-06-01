<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'item_clarity';
$mount_section        = 'instrument_quality';
$mount_item           = 'item_clarity';
$mount_breadcrumb     = ['Instrument Quality', 'Item Clarity / Wording Consistency'];
$mount_title          = 'Item Clarity / Wording Consistency';
$mount_intro          = "A pre-data reliability-readiness check: will respondents in the intended group interpret each item consistently? Unclear or inconsistent wording produces inconsistent interpretation, which undermines reliability before any data is collected. This is not about whether items measure the right construct, nor about response options or dignity — it is about wording that makes interpretation vary among those who can participate. The AI proposes clarity issues with the wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic; no alpha, omega, item-total or inter-item correlations, or factor analysis is used.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
