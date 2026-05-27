<?php
// ReliCheck MM Analysis render shell.
// Seven lenses sharing one engine. Codebook is project-scoped state
// stored in localStorage (relicheck.codebook.<project_id>).
?>

<section class="mm-app" aria-label="Mixed-Methods Analysis">

  <!-- Dataset source ribbon (hidden; mount strip is authoritative) -->
  <div class="mm-source" id="mmSource" hidden style="display:none;">
    <span class="mm-source-pip" aria-hidden="true"></span>
    <span class="mm-source-label" id="mmSourceLabel">Sample data</span>
    <span class="mm-source-meta" id="mmSourceMeta">—</span>
    <span class="mm-source-cta" id="mmCodebookBadge">— codes</span>
  </div>

  <div class="mm-empty" id="mmEmpty" hidden>
    <p>No dataset is available. Run <a href="/evidence-intake.php?studio=mm">Evidence Intake (MM)</a> first.</p>
  </div>

  <!-- ===== LENS: codebook_builder ===== -->
  <section class="mm-lens" data-lens="codebook_builder" hidden>
    <header class="mm-head">
      <div class="mm-eyebrow"><span class="pip" aria-hidden="true"></span><span>Codebook Builder</span></div>
      <h3>Define your themes</h3>
      <p class="mm-sub" id="mmCbSub">Each theme has a name, an optional description, and a list of keywords. Theme Analysis auto-tags responses whose text matches any of a theme's keywords.</p>
    </header>
    <div class="mm-cb-toolbar">
      <button class="btn btn-primary" type="button" id="mmCbAdd">+ Add theme</button>
      <button class="btn btn-ghost"   type="button" id="mmCbSuggest">Suggest from data</button>
      <button class="btn btn-ghost"   type="button" id="mmCbClear">Clear codebook</button>
      <span class="mm-cb-status" id="mmCbStatus"></span>
    </div>
    <div class="mm-cb-themes" id="mmCbThemes" aria-live="polite"></div>
  </section>

  <!-- ===== LENS: theme_analysis ===== -->
  <section class="mm-lens" data-lens="theme_analysis" hidden>
    <header class="mm-head">
      <div class="mm-eyebrow"><span class="pip" aria-hidden="true"></span><span>Theme Analysis</span></div>
      <h3 id="mmTaTitle">—</h3>
      <p class="mm-sub" id="mmTaSub">—</p>
    </header>
    <div class="mm-body" id="mmTaBody"></div>
    <footer class="mm-foot"><h4 class="mm-block-h">Interpretation</h4><p class="mm-interp" id="mmTaInterp">—</p></footer>
  </section>

  <!-- ===== LENS: quote_extractor ===== -->
  <section class="mm-lens" data-lens="quote_extractor" hidden>
    <header class="mm-head">
      <div class="mm-eyebrow"><span class="pip" aria-hidden="true"></span><span>Quote / Evidence Extractor</span></div>
      <h3 id="mmQeTitle">—</h3>
      <p class="mm-sub" id="mmQeSub">—</p>
    </header>
    <div class="mm-body" id="mmQeBody"></div>
    <footer class="mm-foot"><h4 class="mm-block-h">Interpretation</h4><p class="mm-interp" id="mmQeInterp">—</p></footer>
  </section>

  <!-- ===== LENS: theme_by_group ===== -->
  <section class="mm-lens" data-lens="theme_by_group" hidden>
    <header class="mm-head">
      <div class="mm-eyebrow"><span class="pip" aria-hidden="true"></span><span>Theme by Group</span></div>
      <h3 id="mmTgTitle">—</h3>
      <p class="mm-sub" id="mmTgSub">—</p>
    </header>
    <div class="mm-tg-setup">
      <label class="mm-label"><span class="mm-label-text">Group by</span>
        <select class="mm-select" id="mmTgGroup"></select>
      </label>
      <button class="btn btn-primary" type="button" id="mmTgRun">Compute</button>
    </div>
    <div class="mm-body" id="mmTgBody"></div>
    <footer class="mm-foot"><h4 class="mm-block-h">Interpretation</h4><p class="mm-interp" id="mmTgInterp">—</p></footer>
  </section>

  <!-- ===== LENS: joint_display ===== -->
  <section class="mm-lens" data-lens="joint_display" hidden>
    <header class="mm-head">
      <div class="mm-eyebrow"><span class="pip" aria-hidden="true"></span><span>Joint Display</span></div>
      <h3 id="mmJdTitle">Quant findings × Qual themes</h3>
      <p class="mm-sub" id="mmJdSub">—</p>
    </header>
    <div class="mm-body" id="mmJdBody"></div>
    <footer class="mm-foot"><h4 class="mm-block-h">Interpretation</h4><p class="mm-interp" id="mmJdInterp">—</p></footer>
  </section>

  <!-- ===== LENS: integration_quality ===== -->
  <section class="mm-lens" data-lens="integration_quality" hidden>
    <header class="mm-head">
      <div class="mm-eyebrow"><span class="pip" aria-hidden="true"></span><span>Integration Quality</span></div>
      <h3 id="mmIqTitle">—</h3>
      <p class="mm-sub" id="mmIqSub">—</p>
    </header>
    <div class="mm-iq-scorecard" id="mmIqScorecard"></div>
    <div class="mm-body" id="mmIqBody"></div>
    <footer class="mm-foot"><h4 class="mm-block-h">Interpretation</h4><p class="mm-interp" id="mmIqInterp">—</p></footer>
  </section>

  <!-- ===== LENS: qual_to_quant ===== -->
  <section class="mm-lens" data-lens="qual_to_quant" hidden>
    <header class="mm-head">
      <div class="mm-eyebrow"><span class="pip" aria-hidden="true"></span><span>Qual → Quant Variable Builder</span></div>
      <h3 id="mmQqTitle">—</h3>
      <p class="mm-sub" id="mmQqSub">—</p>
    </header>
    <div class="mm-qq-actions">
      <button class="btn btn-primary" type="button" id="mmQqExport">Download as CSV</button>
      <button class="btn btn-ghost"   type="button" id="mmQqCopy">Copy CSV to clipboard</button>
    </div>
    <div class="mm-body" id="mmQqBody"></div>
    <footer class="mm-foot"><h4 class="mm-block-h">Interpretation</h4><p class="mm-interp" id="mmQqInterp">—</p></footer>
  </section>

</section>
