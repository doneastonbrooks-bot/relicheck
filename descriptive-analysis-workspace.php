<?php
// Descriptive Analysis Studio — WORKSPACE.
// Entered from the landing page (/descriptive-analysis-studio.php) via a start
// CTA. Mounts the tested descriptive engines headless. Computes NO reliability;
// the Scale Scores lens runs alpha-free under studio=descriptive. See
// [[project_studio_architecture]].

$_defs = require __DIR__ . '/_analysis_studio_defs.php';
$studio_def = $_defs['descriptive'];
$studio_def['self'] = $studio_def['workspace_route']; // shell self-links stay in the workspace

include __DIR__ . '/_analysis_studio_shell.php';
