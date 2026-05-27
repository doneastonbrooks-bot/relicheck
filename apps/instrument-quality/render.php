<?php
// ReliCheck Instrument Quality Detail render shell.
// -------------------------------------------------------------------
// Six lenses, one shell. Mount page sets window.IQ_LENS.
?>

<section class="iq-app" aria-label="Instrument Quality">

  <!-- Source ribbon —
       Kept in DOM so the engine's setAttribute('data-source', ...) call
       doesn't error, but hidden — the mount template's context strip
       already shows source + row count more accurately. -->
  <div class="iq-source" id="iqSource" hidden style="display:none;">
    <span class="iq-source-pip" aria-hidden="true"></span>
    <span class="iq-source-label" id="iqSourceLabel">Sample data</span>
    <span class="iq-source-meta" id="iqSourceMeta">—</span>
  </div>

  <div class="iq-empty" id="iqEmpty" hidden>
    <p>No dataset is available. Run <a href="/evidence-intake.php?studio=survey">Evidence Intake</a> first.</p>
  </div>

  <!-- Each lens hosts one large section -->
  <section class="iq-lens" data-lens="reliability" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Reliability</span></div>
      <h3 id="iqRelTitle">—</h3>
      <p class="iq-sub" id="iqRelSub">—</p>
    </header>
    <div class="iq-body" id="iqRelBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><div class="iq-interp" id="iqRelInterp">—</div></footer>
  </section>

  <section class="iq-lens" data-lens="construct_alignment" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Construct Alignment</span></div>
      <h3 id="iqCaTitle">—</h3>
      <p class="iq-sub" id="iqCaSub">—</p>
    </header>
    <div class="iq-body" id="iqCaBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><div class="iq-interp" id="iqCaInterp">—</div></footer>
  </section>

  <section class="iq-lens" data-lens="validity" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Validity Review</span></div>
      <h3 id="iqValTitle">—</h3>
      <p class="iq-sub" id="iqValSub">—</p>
    </header>
    <div class="iq-body" id="iqValBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqValInterp">—</p></footer>
  </section>

  <section class="iq-lens" data-lens="item_quality" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Item Quality</span></div>
      <h3 id="iqIqTitle">—</h3>
      <p class="iq-sub" id="iqIqSub">—</p>
    </header>
    <div class="iq-body" id="iqIqBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqIqInterp">—</p></footer>
  </section>

  <section class="iq-lens" data-lens="scale_structure" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Scale Structure</span></div>
      <h3 id="iqSsTitle">—</h3>
      <p class="iq-sub" id="iqSsSub">—</p>
    </header>
    <div class="iq-body" id="iqSsBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqSsInterp">—</p></footer>
  </section>

  <section class="iq-lens" data-lens="factor_readiness" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Factor Readiness</span></div>
      <h3 id="iqFrTitle">—</h3>
      <p class="iq-sub" id="iqFrSub">—</p>
    </header>
    <div class="iq-body" id="iqFrBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqFrInterp">—</p></footer>
  </section>

  <section class="iq-lens" data-lens="bias_clarity" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Bias &amp; Clarity Review</span></div>
      <h3 id="iqBcTitle">—</h3>
      <p class="iq-sub" id="iqBcSub">—</p>
    </header>
    <div class="iq-body" id="iqBcBody"></div>
    <div class="iq-ai-hook">
      <span class="iq-ai-badge">AI upgrade available</span>
      <span class="iq-ai-note">The v1 below is rule-based on the variable names that ReliCheck can see. An AI pass that reads the actual item prompts (when wired in from the Survey Builder) catches double-barreled wording, leading items, jargon, and cultural assumptions.</span>
    </div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqBcInterp">—</p></footer>
  </section>

  <section class="iq-lens" data-lens="response_scale" hidden>
    <header class="iq-head">
      <div class="iq-eyebrow"><span class="pip" aria-hidden="true"></span><span>Response Scale Review</span></div>
      <h3 id="iqRsTitle">—</h3>
      <p class="iq-sub" id="iqRsSub">—</p>
    </header>
    <div class="iq-body" id="iqRsBody"></div>
    <footer class="iq-foot"><h4 class="iq-block-h">Interpretation</h4><p class="iq-interp" id="iqRsInterp">—</p></footer>
  </section>

</section>
