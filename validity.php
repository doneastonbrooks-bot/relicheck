<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'validity';
$mount_section        = 'instrument_quality';
$mount_item           = 'validity';
$mount_breadcrumb     = ['Instrument Quality', 'Validity'];
$mount_title          = 'Validity';
$mount_intro          = "Is this instrument measuring what its scale names claim? Construct, content, and face validity diagnostics — the deepest credibility check before you publish or share findings.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
$mount_stub = [
  'title' => 'Validity Review',
  'body'  => 'Is the instrument measuring what its scale names claim? This view assembles <strong>construct, content, and face validity</strong> diagnostics — inter-item correlations, factor structure, item-rest behavior, and flag items that may not belong. The deepest credibility check before you publish.',
];
include __DIR__ . '/_studio_mount.php';
