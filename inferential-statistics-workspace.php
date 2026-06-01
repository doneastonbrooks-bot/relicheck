<?php
// Inferential Statistics Studio — WORKSPACE.
// Entered from the landing page (/inferential-statistics-studio.php) via a
// start CTA. Mounts the tested inferential engines headless and renders the
// persistent "Variables & Fit" preview panel (preview flag set in the def).
// Computes NO reliability — that lives in RSSI. See [[project_studio_architecture]].

$_defs = require __DIR__ . '/_analysis_studio_defs.php';
$studio_def = $_defs['inferential'];
$studio_def['self'] = $studio_def['workspace_route']; // shell self-links stay in the workspace

include __DIR__ . '/_analysis_studio_shell.php';
