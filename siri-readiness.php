<?php
// Top-level SIRI dashboard (100-point Survey Instrument Readiness Index).
// Sits ABOVE Validity, Reliability & Administration Readiness: it assembles the
// three domain dashboards into the single pre-launch readiness score. Not an
// Instrument Quality lens — it borrows the instrument_quality app entry only for
// its CSS (.iq-vr-* / .iq-siri-* dashboard styles); $mount_workspace_empty
// suppresses that app's engine render + JS so we mount our own aggregator chain.
$mount_app        = 'instrument_quality';
$mount_section    = 'instrument_quality';
$mount_breadcrumb = ['Instrument Quality', 'SIRI Dashboard'];
$mount_title      = 'SIRI Dashboard';
$mount_intro      = "The full 100-point Survey Instrument Readiness Index: SDSI / Validity Readiness (50) + Reliability Readiness (35) + Administration Readiness (15). Each lens owns its own math and each domain aggregator owns its own domain score; this dashboard only sums the three domain contributions. An unresolved launch blocker in any domain holds the whole index at \"Blocked for review\" without changing the number. SIRI is a pre-launch review of whether the instrument is ready to collect interpretable data — it never evaluates survey results (that is RSSI, after data collection).";

// Live mode: the aggregator fetches each domain's saved reviews for this project
// and re-runs the SAME engines on the saved inputs (deterministic — never a
// different score). The "open" buttons jump to the live domain dashboards.
$mount_workspace_empty = <<<'HTML'
<div id="siriReadiness"></div>
<script src="/apps/sdsi/validity-lens-engine.js" defer></script>
<script src="/apps/sdsi/validity-specs.js" defer></script>
<script src="/apps/sdsi/dignity-engine.js" defer></script>
<script src="/apps/sdsi/access-engine.js" defer></script>
<script src="/apps/sdsi/reliability-specs.js" defer></script>
<script src="/apps/sdsi/administration-specs.js" defer></script>
<script src="/apps/sdsi/validity-readiness.js" defer></script>
<script src="/apps/sdsi/reliability-readiness.js" defer></script>
<script src="/apps/sdsi/administration-readiness.js" defer></script>
<script>window.SIRI_MODE = 'live';</script>
<script src="/apps/sdsi/siri-readiness.js" defer></script>
HTML;

include __DIR__ . '/_studio_mount.php';
