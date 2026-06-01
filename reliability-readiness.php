<?php
// Top-level Reliability Readiness dashboard (35-point domain = 5 lenses).
// Not an Instrument Quality lens: it assembles the per-lens saved reviews into
// one score. Borrows the instrument_quality app entry only for its CSS (the
// .iq-vr-* dashboard styles); $mount_workspace_empty suppresses that app's
// engine render + JS so we mount our own aggregator script chain instead.
$mount_app        = 'instrument_quality';
$mount_section    = 'instrument_quality';
$mount_breadcrumb = ['Instrument Quality', 'Reliability Readiness'];
$mount_title      = 'Reliability Readiness';
$mount_intro      = "The 35-point Reliability domain: five pre-data lenses (Scale Structure Readiness, Item Clarity / Wording Consistency, Response Scale Consistency, Redundancy Balance, Administration Consistency for Reliability) each own their own math. This dashboard only sums the points they already produced. An unresolved launch blocker on any lens holds the whole domain at \"Blocked for review\" without changing the number. This is a pre-administration design review — it never uses Cronbach's alpha, omega, item-total or inter-item correlations, factor analysis, or any response-data statistic.";

// Live mode: the aggregator fetches each lens's saved review for this project
// and re-runs the SAME engines on the saved inputs (deterministic — never a
// different score). The "open review" buttons jump to the live lens pages.
$mount_workspace_empty = <<<'HTML'
<div id="reliabilityReadiness"></div>
<script src="/apps/sdsi/validity-lens-engine.js" defer></script>
<script src="/apps/sdsi/reliability-specs.js" defer></script>
<script>window.RR_MODE = 'live';</script>
<script src="/apps/sdsi/reliability-readiness.js" defer></script>
HTML;

include __DIR__ . '/_studio_mount.php';
