<?php
// ReliCheck Overview Suite render shell.
// -------------------------------------------------------------------
// One markup shell, three lenses (project_snapshot, sample_profile,
// data_quality). The mount page sets window.OVERVIEW_LENS; the engine
// shows/hides the matching section and fills it.
?>

<section class="ov-app" aria-label="Overview">

  <!-- ===== Source ribbon ===== -->
  <div class="ov-source" id="ovSource" hidden style="display:none;">
    <span class="ov-source-pip" aria-hidden="true"></span>
    <span class="ov-source-label" id="ovSourceLabel">Sample data</span>
    <span class="ov-source-meta" id="ovSourceMeta">—</span>
  </div>

  <!-- ===== Empty ===== -->
  <div class="ov-empty" id="ovEmpty" hidden>
    <p>No dataset is available. Run <a href="/evidence-intake.php?studio=survey">Evidence Intake</a> first.</p>
  </div>

  <!-- ===== LENS: project_snapshot ===== -->
  <section class="ov-lens" data-lens="project_snapshot" hidden>
    <header class="ov-lens-head">
      <div class="ov-eyebrow"><span class="pip" aria-hidden="true"></span><span>Project Snapshot</span></div>
      <h3 id="ovSnapTitle">—</h3>
      <p class="ov-lens-sub" id="ovSnapSub">—</p>
    </header>
    <div class="ov-snap-grid" id="ovSnapGrid"></div>
    <div class="ov-snap-extras" id="ovSnapExtras"></div>
  </section>

  <!-- ===== LENS: sample_profile ===== -->
  <section class="ov-lens" data-lens="sample_profile" hidden>
    <header class="ov-lens-head">
      <div class="ov-eyebrow"><span class="pip" aria-hidden="true"></span><span>Sample Profile</span></div>
      <h3 id="ovSpTitle">Who is in this dataset</h3>
      <p class="ov-lens-sub" id="ovSpSub">—</p>
    </header>
    <div class="ov-sp-grid" id="ovSpGrid"></div>
    <p class="ov-detail-note" id="ovSpFoot"></p>
  </section>

  <!-- ===== LENS: data_quality ===== -->
  <section class="ov-lens" data-lens="data_quality" hidden>
    <header class="ov-lens-head">
      <div class="ov-eyebrow"><span class="pip" aria-hidden="true"></span><span>Data Quality</span></div>
      <h3 id="ovDqTitle">—</h3>
      <p class="ov-lens-sub" id="ovDqSub">—</p>
    </header>
    <div class="ov-dq-scorecard" id="ovDqScorecard"></div>
    <h4 class="ov-block-h">Detail</h4>
    <div class="ov-dq-detail" id="ovDqDetail"></div>
    <footer class="ov-lens-foot">
      <h4 class="ov-block-h">What this means</h4>
      <p class="ov-interp" id="ovDqInterp">—</p>
    </footer>
  </section>

</section>
