<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'completion_burden';
$mount_section        = 'instrument_quality';
$mount_item           = 'completion_burden';
$mount_breadcrumb     = ['Instrument Quality', 'Completion Burden & Launch Logistics'];
$mount_title          = 'Completion Burden & Launch Logistics';
$mount_intro          = "A pre-launch administration-readiness check: is the survey practical for respondents to complete once they receive it? This respondent-completion-facing lens reviews overall length/density, a stated estimated completion time, required-item burden, device/mode readiness, save-and-return clarity, final submission clarity, and other respondent-facing completion friction. It is NOT Fielding Plan & Timing (whether the organization can launch and manage the survey), NOT Access (whether respondents can reach it), and NOT Item Clarity (whether wording is hard to interpret). It never evaluates survey results — that belongs to RSSI, after data collection. The AI proposes completion issues with the wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic, and an orthogonal launch gate flags a required/expected survey that may not be reasonably completable in the expected mode regardless of the number.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
