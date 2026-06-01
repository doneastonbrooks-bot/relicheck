<?php
// ReliCheck Strength Index — the brand-critical headline lens.
// Uses the full /apps/strength-index/ engine. Per [[relicheck-strength-index-formula]]
// the 6-domain weighted composite is canonical and must NOT be altered.
$mount_app            = 'strength_index';
$mount_lens           = '';
$mount_section        = 'instrument_quality';
$mount_item           = 'strength_index';
$mount_breadcrumb     = ['Instrument Quality', 'ReliCheck Strength Index'];
$mount_title          = 'ReliCheck Strength Index';
$mount_intro          = 'One headline number, six domains, every check you need to publish, share, or act on this instrument. Lower components point to exactly what to fix.';
$mount_dataset_global = 'STRENGTH_DATASET';
$mount_lens_global    = '';
$mount_stub = [
  'title' => 'ReliCheck Strength Index',
  'body'  => 'One headline number that says how trustworthy this instrument is. The composite reads <strong>six weighted domains</strong> — Reliability, Factor Structure, Item Quality, Response Quality, Open-Ended Alignment, and Actionability. The components below name exactly what to fix first.',
];
include __DIR__ . '/_studio_mount.php';
