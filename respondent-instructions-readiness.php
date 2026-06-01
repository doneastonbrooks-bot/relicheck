<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'respondent_instructions';
$mount_section        = 'instrument_quality';
$mount_item           = 'respondent_instructions';
$mount_breadcrumb     = ['Instrument Quality', 'Respondent Instructions & Guidance'];
$mount_title          = 'Respondent Instructions & Guidance';
$mount_intro          = "A pre-launch administration-readiness check: do respondents know what the survey is for, how to complete it, what timeframe to use, and what kind of answers are expected? It reviews the opening instructions, respondent-facing purpose, completion expectations, timeframe guidance, section transitions, and respondent role. It is NOT a check of construct fit (Validity) or response consistency (Reliability), and it never evaluates survey results — that belongs to RSSI, after data collection. The AI proposes guidance issues with the wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic, and an orthogonal launch gate flags an absent instruction block on a required or moderate/high-stakes survey regardless of the number.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
