<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'fielding_plan';
$mount_section        = 'instrument_quality';
$mount_item           = 'fielding_plan';
$mount_breadcrumb     = ['Instrument Quality', 'Fielding Plan & Timing'];
$mount_title          = 'Fielding Plan & Timing';
$mount_intro          = "A pre-launch administration-readiness check: can this survey actually be launched and managed as intended — delivered to the right people, at the right time, through the right channel, with clear ownership and follow-up? This practical, operational lens reviews the target population, delivery channel, launch window, close date, distribution ownership, reminder/follow-up plan, and major launch dependencies. Target-population clarity here is fielding clarity, not statistical sample representativeness. It never evaluates survey results — that belongs to RSSI, after data collection. The AI proposes fielding issues with the wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic, and an orthogonal launch gate flags a missing target population, or a required/expected survey with no open/close plan or a major operational gap, regardless of the number.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
