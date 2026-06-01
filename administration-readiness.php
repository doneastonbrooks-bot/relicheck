<?php
// Top-level Administration Readiness dashboard (15-point domain = 5 lenses).
// Not an Instrument Quality lens: it assembles the per-lens saved reviews into
// one score. Borrows the instrument_quality app entry only for its CSS (the
// .iq-vr-* dashboard styles); $mount_workspace_empty suppresses that app's
// engine render + JS so we mount our own aggregator script chain instead.
$mount_app        = 'instrument_quality';
$mount_section    = 'instrument_quality';
$mount_breadcrumb = ['Instrument Quality', 'Administration Readiness'];
$mount_title      = 'Administration Readiness';
$mount_intro      = "The 15-point Administration domain and third SIRI domain: five pre-launch lenses (Respondent Instructions & Guidance, Consent, Privacy & Use Transparency, Fielding Plan & Timing, Sensitive-Topic & Safety Readiness, Completion Burden & Launch Logistics) each own their own math. This dashboard only sums the points they already produced. An unresolved launch blocker on any lens holds the whole domain at \"Blocked for review\" without changing the number. This is a pre-launch review of whether the survey is ready to be fielded responsibly, clearly, safely, and practically — it does not evaluate survey results or post-administration data quality.";

// Live mode: the aggregator fetches each lens's saved review for this project
// and re-runs the SAME engines on the saved inputs (deterministic — never a
// different score). The "open review" buttons jump to the live lens pages.
$mount_workspace_empty = <<<'HTML'
<div id="administrationReadiness"></div>
<script src="/apps/sdsi/validity-lens-engine.js" defer></script>
<script src="/apps/sdsi/administration-specs.js" defer></script>
<script>window.AR_MODE = 'live';</script>
<script src="/apps/sdsi/administration-readiness.js" defer></script>
HTML;

include __DIR__ . '/_studio_mount.php';
