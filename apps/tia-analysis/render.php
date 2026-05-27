<?php
// ReliCheck TIA Analysis render shell.
// Five lenses on student-by-item response data + answer key.
?>

<section class="tia-app" aria-label="TIA Analysis">

  <!-- Dataset source ribbon (hidden; mount strip is authoritative) -->
  <div class="tia-source" id="tiaSource" hidden style="display:none;">
    <span class="tia-source-pip" aria-hidden="true"></span>
    <span class="tia-source-label" id="tiaSourceLabel">Sample test</span>
    <span class="tia-source-meta" id="tiaSourceMeta">—</span>
  </div>

  <div class="tia-empty" id="tiaEmpty" hidden>
    <p>No test data is available. Run <a href="/evidence-intake.php?studio=tia">Evidence Intake (TIA)</a> to upload student-by-item responses with an answer key.</p>
  </div>

  <div class="tia-setup" id="tiaSetup" hidden>
    <!-- For DIF only: pick a grouping variable -->
    <div class="tia-lens-setup" data-lens="dif" hidden>
      <div class="tia-field">
        <label class="tia-label">
          <span class="tia-label-text">Demographic for DIF</span>
          <span class="tia-label-hint">Categorical (e.g., gender, grade level)</span>
        </label>
        <select class="tia-select" id="tiaDifGroup"></select>
      </div>
      <button class="btn btn-primary" type="button" id="tiaDifRun">Run DIF</button>
    </div>
  </div>

  <article class="tia-results" id="tiaResults" aria-live="polite">
    <header class="tia-head">
      <div class="tia-eyebrow"><span class="pip" aria-hidden="true"></span><span id="tiaLensName">—</span></div>
      <h3 id="tiaHeadline">—</h3>
      <p class="tia-sub" id="tiaSub">—</p>
    </header>

    <div class="tia-stat-strip" id="tiaStatStrip"></div>
    <div class="tia-body" id="tiaBody"></div>

    <footer class="tia-foot">
      <h4 class="tia-block-h">Interpretation</h4>
      <p class="tia-interp" id="tiaInterp">—</p>
    </footer>
  </article>

</section>
