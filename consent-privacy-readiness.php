<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'consent_privacy';
$mount_section        = 'instrument_quality';
$mount_item           = 'consent_privacy';
$mount_breadcrumb     = ['Instrument Quality', 'Consent, Privacy & Use Transparency'];
$mount_title          = 'Consent, Privacy & Use Transparency';
$mount_intro          = "A pre-launch administration-readiness check: are respondents clearly told whether participation is voluntary, required, expected, or unstated; how their responses will be used; who may see individual responses or summary results; and whether privacy/confidentiality claims match the data collected? This lens flags TRANSPARENCY READINESS RISKS — not legal, IRB, FERPA, COPPA, HIPAA, district-policy, or employment-law compliance. It never evaluates survey results — that belongs to RSSI, after data collection. The AI proposes transparency issues with the wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic, and an orthogonal launch gate flags a missing participation statement, unclear required/expected status, or a privacy claim that may be inaccurate regardless of the number.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
