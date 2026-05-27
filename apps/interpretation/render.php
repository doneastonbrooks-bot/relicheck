<?php
// ReliCheck Interpretation render shell.
// -------------------------------------------------------------------
// One markup shell, eight lenses. The mount page sets
//   <script>window.INTERPRETATION_LENS = '<lens_key>';</script>
// before loading the engine. The engine then fills the same DOM:
//   - the eyebrow gets the lens name
//   - the headline gets a one-line summary
//   - the body slot (#interpBody) gets the lens-specific output
//     (bullets, table, prose paragraphs, or a mix)
//   - the saved-blocks ribbon shows how many results are in scope
//   - the empty-state appears when localStorage has no blocks
//
// Element IDs are stable so every lens can target them.
?>

<section class="interp-app" aria-label="Interpretation">

<!-- Studio-aware CTA wiring. Reads body[data-current-studio] + ?project_id
     and rewrites every data-studio-cta link so the user stays in their
     studio (no more hardcoded /studio-survey.php). Per [[relicheck-nav-contract]]
     "no cross-studio links inside a studio". -->
<script>
(function () {
  const studio    = (document.body.getAttribute('data-current-studio') || 'survey').trim();
  const projectId = (document.body.getAttribute('data-project-id')    || '').trim();
  const qs = projectId ? ('?studio=' + encodeURIComponent(studio) + '&project_id=' + encodeURIComponent(projectId))
                       : ('?studio=' + encodeURIComponent(studio));
  const routes = {
    'studio-home':       '/studio-' + studio + '.php',
    'strength_index':    '/strength-index.php' + qs,
    't_test':            '/t-test.php' + qs,
    'correlation':       '/correlation.php' + qs,
    'open_ended_summary':'/open-ended-summary.php' + qs,
  };
  document.querySelectorAll('[data-studio-cta]').forEach(a => {
    const k = a.getAttribute('data-studio-cta');
    if (routes[k]) a.setAttribute('href', routes[k]);
  });
})();
</script>

  <!-- ===== Saved-blocks status ribbon ===== -->
  <div class="interp-source" id="interpSource" data-source="empty">
    <span class="interp-source-pip" aria-hidden="true"></span>
    <span class="interp-source-label" id="interpSourceLabel">No saved results yet</span>
    <span class="interp-source-meta" id="interpSourceMeta">—</span>
    <a class="interp-source-cta" href="#" id="interpSourceCta" data-studio-cta="studio-home">Run an analysis</a>
  </div>

  <!-- ===== Header card ===== -->
  <header class="interp-head">
    <div class="interp-eyebrow">
      <span class="pip" aria-hidden="true"></span>
      <span id="interpLensName">Interpretation</span>
    </div>
    <h3 class="interp-headline" id="interpHeadline">—</h3>
    <p class="interp-sub" id="interpSub">—</p>
  </header>

  <!-- ===== Empty state (no saved blocks yet) ===== -->
  <div class="interp-empty" id="interpEmpty" hidden>
    <h4>Nothing to interpret yet</h4>
    <p>This lens reads from the analyses you have saved into your report. Run any analysis app (Strength Index, T-Test, ANOVA, Effect Size, etc.) and click <strong>Save to Report</strong> in the studio chrome. Come back here and the interpretation will appear automatically.</p>
    <div class="interp-empty-grid" id="interpEmptyGrid">
      <a class="interp-empty-card" href="#" data-studio-cta="strength_index">
        <span class="interp-empty-card-name">Strength Index</span>
        <span class="interp-empty-card-sub">Run, then save the composite score</span>
      </a>
      <a class="interp-empty-card" href="#" data-studio-cta="t_test">
        <span class="interp-empty-card-name">T-Test</span>
        <span class="interp-empty-card-sub">Compare two groups, save the result</span>
      </a>
      <a class="interp-empty-card" href="#" data-studio-cta="correlation">
        <span class="interp-empty-card-name">Correlation</span>
        <span class="interp-empty-card-sub">Save the r value and CI</span>
      </a>
      <a class="interp-empty-card" href="#" data-studio-cta="open_ended_summary">
        <span class="interp-empty-card-name">Open-Ended Summary</span>
        <span class="interp-empty-card-sub">Save the qualitative read</span>
      </a>
    </div>
  </div>

  <!-- ===== Body slot (filled per lens) ===== -->
  <div class="interp-body" id="interpBody" aria-live="polite"></div>

  <!-- ===== Footer (next step / AI upgrade hook) ===== -->
  <footer class="interp-foot" id="interpFoot" hidden>
    <div class="interp-foot-row">
      <h4 class="interp-block-h">What's next</h4>
      <p class="interp-next" id="interpNext">—</p>
    </div>
    <div class="interp-ai" id="interpAiHook" hidden>
      <span class="interp-ai-badge">AI upgrade available</span>
      <span class="interp-ai-note" id="interpAiNote">This page is rule-based v1. An AI pass that reads every saved block and writes a narrative version can be wired in later.</span>
    </div>
  </footer>

</section>
