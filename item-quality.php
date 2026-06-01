<?php
// Item / Prompt Quality — the psychometric character of each individual
// item (NOT the dataset). Item-rest, "if-deleted alpha", discrimination,
// endpoint use, ceiling/floor. The clearest signal of which specific
// items are pulling your instrument down. Per user feedback: Instrument
// Quality is the money-maker — content here should be critical.
$mount_app            = 'instrument_quality';
$mount_lens           = 'item_quality';
$mount_section        = 'instrument_quality';
$mount_item           = 'item_quality';
$mount_breadcrumb     = ['Instrument Quality', 'Item / Prompt Quality'];
$mount_title          = 'Item / Prompt Quality';
$mount_intro          = "Which items earn their place on this instrument? Per-item diagnostics: item-rest correlations, 'if this item were dropped' α, discrimination, endpoint use, and ceiling / floor flags. The most actionable view in Instrument Quality — every flagged item names a specific revision or removal candidate.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
$mount_stub = [
  'title' => 'Item / Prompt Quality',
  'body'  => 'Which items earn their place on the instrument? This view runs <strong>per-item diagnostics</strong> — item-rest correlations, "if this item were dropped" α, discrimination, endpoint use, ceiling and floor flags. Every flagged item names a specific revision or removal candidate.',
];
include __DIR__ . '/_studio_mount.php';
