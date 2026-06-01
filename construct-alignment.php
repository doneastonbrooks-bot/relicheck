<?php
// Construct Alignment — thin mount. Delegates to the instrument_quality
// engine pinned at the 'construct_alignment' lens. The engine owns the
// clustering, evidence table, candidate-cluster cards, and J/E/M/A footer.
$mount_app            = 'instrument_quality';
$mount_lens           = 'construct_alignment';
$mount_section        = 'instrument_quality';
$mount_item           = 'construct_alignment';
$mount_breadcrumb     = ['Instrument Quality', 'Construct Alignment'];
$mount_title          = 'Construct Alignment';
$mount_intro          = "Do the items group according to their intended scale names — or is the structure being driven by wording, scoring direction, or method effects?";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
