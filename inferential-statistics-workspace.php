<?php
// Inferential Statistics Studio — WORKSPACE.
// Entered from the landing page (/inferential-statistics-studio.php) via a
// start CTA. Mounts the tested inferential engines headless and renders the
// persistent "Variables & Fit" preview panel (preview flag set in the def).
// Computes NO reliability — that lives in RSSI. See [[project_studio_architecture]].

$_defs = require __DIR__ . '/_analysis_studio_defs.php';
$studio_def = $_defs['inferential'];
$studio_def['self'] = $studio_def['workspace_route']; // shell self-links stay in the workspace

// ?v4=1 previews the new design-led MM-style workspace (Phase 3, in progress)
// without disturbing the current live shell. Flip the default once verified.
if (isset($_GET['v4'])) {
  include __DIR__ . '/_analysis_studio_v4_shell.php';
} else {
  include __DIR__ . '/_analysis_studio_shell.php';
}
