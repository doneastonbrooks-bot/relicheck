<?php
// Reliability — thin mount, the way it should always have been.
// Delegates to apps/instrument-quality/ engine pinned at the
// 'reliability' lens. The engine owns the calculation, table,
// summary paragraphs, and Judgment / Evidence / Meaning / Action
// footer. One source of truth across every studio.
$mount_app            = 'instrument_quality';
$mount_lens           = 'reliability';
$mount_section        = 'instrument_quality';
$mount_item           = 'reliability';
$mount_breadcrumb     = ['Instrument Quality', 'Reliability'];
$mount_title          = 'Reliability';
$mount_intro          = "Internal consistency of every scale, item by item. Each row carries a judgment, the evidence behind it, and a recommended action.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
$mount_stub = [
  'title' => 'Reliability',
  'body'  => 'This view checks whether your scales hang together. Cronbach\'s <strong>α</strong> and McDonald\'s <strong>ω</strong>, plus item-rest correlations and per-item drop-flags. The result tells you which items help, which hurt, and whether the scale is publishable.',
];
include __DIR__ . '/_studio_mount.php';
