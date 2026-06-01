<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'response_scale';
$mount_section        = 'instrument_quality';
$mount_item           = 'response_scale_review';
$mount_breadcrumb     = ['Instrument Quality', 'Response Scale Review'];
$mount_title          = 'Response Scale Review';
$mount_intro          = "Is the response scale earning its number of points? Endpoint usage, skip patterns, and effective scale range per item — flags scales where respondents only use a few options or always hug the middle.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
