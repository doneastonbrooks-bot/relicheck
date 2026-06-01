<?php
$mount_app            = 'instrument_quality';
$mount_lens           = 'sensitive_safety';
$mount_section        = 'instrument_quality';
$mount_item           = 'sensitive_safety';
$mount_breadcrumb     = ['Instrument Quality', 'Sensitive-Topic & Safety Readiness'];
$mount_title          = 'Sensitive-Topic & Safety Readiness';
$mount_intro          = "A pre-launch administration-readiness check: is sensitive survey content introduced, framed, supported, and protected safely enough before launch? It reviews whether sensitive content has enough context and stated purpose, a safe decline path, support/resource language, a follow-up plan when risk may be disclosed, careful placement, and safeguards for minors and other vulnerable groups. It is NOT Dignity/Framing or Access — score once, surface twice: an issue that primarily belongs to Dignity or Access is scored there and surfaced here without a second penalty. It never evaluates survey results — that belongs to RSSI, after data collection. The AI proposes safety issues with the wording that triggered each; you keep, dismiss, or re-grade them. The score is deterministic, and an orthogonal launch gate flags sensitive disclosure requested without a safe response path, needed safeguards, or a follow-up process regardless of the number.";
$mount_dataset_global = 'IQ_DATASET';
$mount_lens_global    = 'IQ_LENS';
include __DIR__ . '/_studio_mount.php';
