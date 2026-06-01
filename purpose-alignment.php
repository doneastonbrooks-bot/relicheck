<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'purpose_alignment';
$mount_section        = 'instrument_quality';
$mount_item           = 'purpose_alignment';
$mount_breadcrumb     = ['Instrument Quality', 'Purpose Alignment'];
$mount_title          = 'Purpose Alignment';
$mount_intro          = "A pre-data validity check: do the items, demographics, and intended use align with the stated purpose? Data that does not serve the purpose is noise; a use the instrument cannot support is a validity threat. The AI proposes alignment issues with the exact wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic, and a launch gate flags a missing purpose regardless of the number.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
