<?php
// Top-level Validity Readiness dashboard (50-point domain = 7 lenses).
// Not an Instrument Quality lens: it assembles the per-lens saved reviews into
// one score. Borrows the instrument_quality app entry only for its CSS (the
// .iq-vr-* dashboard styles); $mount_workspace_empty suppresses that app's
// engine render + JS so we mount our own aggregator script chain instead.
$mount_app        = 'instrument_quality';
$mount_section    = 'instrument_quality';
$mount_breadcrumb = ['Instrument Quality', 'Validity Readiness'];
$mount_title      = 'Validity Readiness';
$mount_intro      = "The 50-point Validity domain: seven lenses (Construct Definition, Purpose Alignment, Dimension / Domain Coverage, Item-to-Construct Alignment, Response-Option Validity, Dignity / Framing, Access) each own their own math. This dashboard only sums the points they already produced. An unresolved launch blocker on any lens holds the whole domain at \"Blocked for review\" without changing the number.";

// Live mode: the aggregator fetches each lens's saved review for this project
// and re-runs the SAME engines on the saved inputs (deterministic — never a
// different score). The "open review" buttons jump to the live lens pages.
$mount_workspace_empty = <<<'HTML'
<div id="validityReadiness"></div>
<script src="/apps/sdsi/validity-lens-engine.js" defer></script>
<script src="/apps/sdsi/validity-specs.js" defer></script>
<script src="/apps/sdsi/dignity-engine.js" defer></script>
<script src="/apps/sdsi/access-engine.js" defer></script>
<script>window.VR_MODE = 'live';</script>
<script src="/apps/sdsi/validity-readiness.js" defer></script>
HTML;

include __DIR__ . '/_studio_mount.php';
