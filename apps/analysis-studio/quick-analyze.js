// Standalone Quick Analyze page initializer.
// All rendering lives in the shared engine (quick-analyze-core.js / window.QuickAnalyze)
// so the page and the in-context popup stay identical. This file just wires BOOT
// to the engine and renders full-width into #qa-root.
(function () {
  'use strict';
  var BOOT = window.QA_BOOT || {};
  var root = document.getElementById('qa-root');
  if (!root || !BOOT.projectId || !window.QuickAnalyze) return;

  document.body.style.background = '#f4f5f7';
  root.style.display = 'block';
  root.style.maxWidth = '1080px';
  root.style.margin = '0 auto';
  root.style.padding = '28px 24px 60px';

  window.QuickAnalyze.renderAll(root, BOOT.projectId, {
    mode:         BOOT.mode === 'report' ? 'report' : 'auto',
    projectTitle: BOOT.projectTitle || '',
    workspaceUrl: BOOT.workspaceUrl || '',
    compact:      false,
  });
})();
