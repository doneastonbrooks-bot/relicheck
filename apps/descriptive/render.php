<?php
// ReliCheck Descriptive Suite render shell.
// -------------------------------------------------------------------
// One markup shell, six lenses. The mount page sets
//   window.DESCRIPTIVE_LENS = '<lens_key>';
// before loading the engine. The engine shows/hides the matching
// section and fills it.
?>

<section class="dx-app" aria-label="Descriptive Analysis">

  <!-- ===== Dataset source ribbon =====
       Kept in DOM so the engine's setAttribute('data-source', ...) call
       doesn't error, but hidden via CSS — the mount template's context
       strip already shows source + row count, more accurately. -->
  <div class="dx-source" id="dxSource" hidden style="display:none;">
    <span class="dx-source-pip" aria-hidden="true"></span>
    <span class="dx-source-label" id="dxSourceLabel">Sample data</span>
    <span class="dx-source-meta" id="dxSourceMeta">—</span>
    <a class="dx-source-cta" id="dxSourceCta" href="/evidence-intake.php?studio=survey">Add or replace data</a>
  </div>

  <!-- ===== Setup card (lens-specific inputs) ===== -->
  <div class="dx-setup">

    <!-- LENS: frequencies -->
    <div class="dx-lens-setup" data-lens="frequencies" hidden>
      <div class="dx-field">
        <label class="dx-label" for="dxFreqVar">
          <span class="dx-label-text">Variable</span>
          <span class="dx-label-hint">Categorical or Likert</span>
        </label>
        <select class="dx-select" id="dxFreqVar"></select>
      </div>
      <button class="btn btn-primary dx-run" type="button" id="dxFreqRun">Compute</button>
    </div>

    <!-- LENS: cross_tabs -->
    <div class="dx-lens-setup" data-lens="cross_tabs" hidden>
      <div class="dx-setup-grid">
        <div class="dx-field">
          <label class="dx-label" for="dxCtRow"><span class="dx-label-text">Row variable</span><span class="dx-label-hint">Categorical</span></label>
          <select class="dx-select" id="dxCtRow"></select>
        </div>
        <div class="dx-field">
          <label class="dx-label" for="dxCtCol"><span class="dx-label-text">Column variable</span><span class="dx-label-hint">Categorical</span></label>
          <select class="dx-select" id="dxCtCol"></select>
        </div>
      </div>
      <div class="dx-options">
        <label class="dx-toggle">
          <input type="radio" name="dxCtPct" value="row" checked /> Row %
        </label>
        <label class="dx-toggle">
          <input type="radio" name="dxCtPct" value="col" /> Column %
        </label>
        <label class="dx-toggle">
          <input type="radio" name="dxCtPct" value="total" /> Total %
        </label>
      </div>
      <button class="btn btn-primary dx-run" type="button" id="dxCtRun">Compute</button>
    </div>

    <!-- LENS: distributions -->
    <div class="dx-lens-setup" data-lens="distributions" hidden>
      <div class="dx-field">
        <label class="dx-label" for="dxDistVar"><span class="dx-label-text">Variable</span><span class="dx-label-hint">Numeric or Likert</span></label>
        <select class="dx-select" id="dxDistVar"></select>
      </div>
      <div class="dx-field">
        <label class="dx-label" for="dxDistBins"><span class="dx-label-text">Bins</span><span class="dx-label-hint">For the histogram</span></label>
        <input type="number" class="dx-input" id="dxDistBins" value="8" min="2" max="40" step="1"/>
      </div>
      <button class="btn btn-primary dx-run" type="button" id="dxDistRun">Compute</button>
    </div>

    <!-- LENS: group_summaries -->
    <div class="dx-lens-setup" data-lens="group_summaries" hidden>
      <div class="dx-setup-grid">
        <div class="dx-field">
          <label class="dx-label" for="dxGsOutcome"><span class="dx-label-text">Outcome</span><span class="dx-label-hint">Numeric or Likert</span></label>
          <select class="dx-select" id="dxGsOutcome"></select>
        </div>
        <div class="dx-field">
          <label class="dx-label" for="dxGsGroup"><span class="dx-label-text">Group</span><span class="dx-label-hint">Categorical</span></label>
          <select class="dx-select" id="dxGsGroup"></select>
        </div>
      </div>
      <button class="btn btn-primary dx-run" type="button" id="dxGsRun">Compute</button>
    </div>

    <!-- LENS: top_bottom_items -->
    <div class="dx-lens-setup" data-lens="top_bottom_items" hidden>
      <p class="dx-setup-note">Ranks every Likert/Scale item in the dataset by its mean, with companion views for variance (which items discriminate best, which items might be ceiling- or floor-bound). No pickers — uses all Likert variables.</p>
      <button class="btn btn-primary dx-run" type="button" id="dxTbRun">Rank items</button>
    </div>

    <!-- LENS: scale_scores -->
    <div class="dx-lens-setup" data-lens="scale_scores" hidden>
      <h4 class="dx-block-h">Pick the items in the scale</h4>
      <div class="dx-checklist" id="dxScaleItems" role="group" aria-label="Scale items"></div>
      <div class="dx-options">
        <label class="dx-toggle">
          <input type="radio" name="dxScaleMode" value="mean" checked /> Item-mean composite
        </label>
        <label class="dx-toggle">
          <input type="radio" name="dxScaleMode" value="sum"  /> Sum composite
        </label>
      </div>
      <button class="btn btn-primary dx-run" type="button" id="dxScaleRun">Compute scale</button>
    </div>

  </div>

  <span class="dx-status" id="dxStatus" role="status" aria-live="polite"></span>

  <!-- ===== Empty state (no dataset) ===== -->
  <div class="dx-empty" id="dxEmpty" hidden>
    <p>No dataset is loaded yet. Run <a href="/evidence-intake.php?studio=survey">Evidence Intake</a> first, or use this page's demo by reloading.</p>
  </div>

  <!-- ===== Results panel — visible by default, filled on load by the engine ===== -->
  <article class="dx-results" id="dxResults" aria-live="polite">

    <header class="dx-results-head">
      <div class="dx-results-eyebrow"><span class="pip" aria-hidden="true"></span><span id="dxLensName">Descriptive</span></div>
      <h3 id="dxResultsHeadline">—</h3>
      <p class="dx-results-sub" id="dxResultsSub">—</p>
    </header>

    <div class="dx-results-body" id="dxResultsBody"></div>

    <footer class="dx-results-foot">
      <h4 class="dx-block-h">What this shows</h4>
      <p class="dx-interp" id="dxInterp">—</p>
    </footer>

  </article>

</section>
