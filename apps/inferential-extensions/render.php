<?php
// ReliCheck Inferential Extensions render shell.
// -------------------------------------------------------------------
// One markup shell, six lenses. The mount page sets
//   window.INFEXT_LENS = '<lens_key>';
// before loading the engine. The engine shows/hides the matching
// setup pane and fills the results.
?>

<section class="ix-app" aria-label="Inferential Extensions">

  <!-- ===== Dataset source ribbon (hidden; mount strip is authoritative) ===== -->
  <div class="ix-source" id="ixSource" hidden style="display:none;">
    <span class="ix-source-pip" aria-hidden="true"></span>
    <span class="ix-source-label" id="ixSourceLabel">Sample data</span>
    <span class="ix-source-meta" id="ixSourceMeta">—</span>
  </div>

  <!-- ===== Empty state (no dataset) ===== -->
  <div class="ix-empty" id="ixEmpty" hidden>
    <p>No dataset is available. Run <a href="/evidence-intake.php?studio=survey">Evidence Intake</a> first.</p>
  </div>

  <!-- ===== Setup card (lens-specific) ===== -->
  <div class="ix-setup">

    <!-- LENS: paired_t -->
    <div class="ix-lens-setup" data-lens="paired_t" hidden>
      <div class="ix-setup-grid">
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">First measurement</span><span class="ix-label-hint">Numeric or Likert</span></label>
          <select class="ix-select" id="ixPairA"></select>
        </div>
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Second measurement</span><span class="ix-label-hint">Numeric or Likert</span></label>
          <select class="ix-select" id="ixPairB"></select>
        </div>
      </div>
      <p class="ix-setup-note">Each row is treated as a pair. Each respondent's value on the first variable is compared to their own value on the second. Use this for pre/post, self/other, or repeated-measures comparisons.</p>
      <button class="btn btn-primary ix-run" type="button" id="ixPairRun">Run paired t-test</button>
    </div>

    <!-- LENS: welch_anova -->
    <div class="ix-lens-setup" data-lens="welch_anova" hidden>
      <div class="ix-setup-grid">
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Outcome variable</span><span class="ix-label-hint">Numeric or Likert</span></label>
          <select class="ix-select" id="ixWaOutcome"></select>
        </div>
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Grouping variable</span><span class="ix-label-hint">Categorical with 3+ levels</span></label>
          <select class="ix-select" id="ixWaGroup"></select>
        </div>
      </div>
      <p class="ix-setup-note">Welch's ANOVA does not assume the groups have equal variances. Use it when standard ANOVA's homogeneity-of-variance check fails.</p>
      <button class="btn btn-primary ix-run" type="button" id="ixWaRun">Run Welch ANOVA</button>
    </div>

    <!-- LENS: post_hoc -->
    <div class="ix-lens-setup" data-lens="post_hoc" hidden>
      <div class="ix-setup-grid">
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Outcome variable</span><span class="ix-label-hint">Numeric or Likert</span></label>
          <select class="ix-select" id="ixPhOutcome"></select>
        </div>
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Grouping variable</span><span class="ix-label-hint">Categorical with 3+ levels</span></label>
          <select class="ix-select" id="ixPhGroup"></select>
        </div>
      </div>
      <p class="ix-setup-note">After a significant ANOVA, find which pairs of groups differ. Uses pairwise Welch's t-tests with Bonferroni-Holm correction (defensible without assuming equal variances; equivalent to Games-Howell in approach).</p>
      <button class="btn btn-primary ix-run" type="button" id="ixPhRun">Run post-hoc comparisons</button>
    </div>

    <!-- LENS: regression -->
    <div class="ix-lens-setup" data-lens="regression" hidden>
      <div class="ix-field">
        <label class="ix-label"><span class="ix-label-text">Outcome (Y)</span><span class="ix-label-hint">Numeric or Likert</span></label>
        <select class="ix-select" id="ixRegY"></select>
      </div>
      <div class="ix-setup-grid">
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Predictor 1 (X₁)</span><span class="ix-label-hint">Numeric or Likert</span></label>
          <select class="ix-select" id="ixRegX1"></select>
        </div>
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Predictor 2 (X₂, optional)</span><span class="ix-label-hint">Numeric or Likert</span></label>
          <select class="ix-select" id="ixRegX2"></select>
        </div>
      </div>
      <p class="ix-setup-note">Ordinary least squares linear regression. Add a second predictor to fit a multiple regression. Inspect residuals visually before drawing strong conclusions about coefficients.</p>
      <button class="btn btn-primary ix-run" type="button" id="ixRegRun">Run regression</button>
    </div>

    <!-- LENS: confidence_interval -->
    <div class="ix-lens-setup" data-lens="confidence_interval" hidden>
      <h4 class="ix-block-h">Estimand</h4>
      <div class="ix-pick">
        <button class="ix-chip is-active" type="button" data-ci="mean">Mean (one variable)</button>
        <button class="ix-chip"            type="button" data-ci="prop">Proportion</button>
        <button class="ix-chip"            type="button" data-ci="meandiff">Mean difference (2 groups)</button>
        <button class="ix-chip"            type="button" data-ci="pearson_r">Pearson r</button>
      </div>

      <div class="ix-ciset" data-ci="mean">
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Variable</span><span class="ix-label-hint">Numeric or Likert</span></label>
          <select class="ix-select" id="ixCiMeanVar"></select>
        </div>
      </div>
      <div class="ix-ciset" data-ci="prop" hidden>
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Categorical variable</span><span class="ix-label-hint">"Yes" / "No" or similar</span></label>
          <select class="ix-select" id="ixCiPropVar"></select>
        </div>
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Level to estimate proportion of</span><span class="ix-label-hint">Which value counts as "success"</span></label>
          <select class="ix-select" id="ixCiPropLvl"></select>
        </div>
      </div>
      <div class="ix-ciset" data-ci="meandiff" hidden>
        <div class="ix-setup-grid">
          <div class="ix-field">
            <label class="ix-label"><span class="ix-label-text">Outcome</span><span class="ix-label-hint">Numeric or Likert</span></label>
            <select class="ix-select" id="ixCiDiffOut"></select>
          </div>
          <div class="ix-field">
            <label class="ix-label"><span class="ix-label-text">Grouping</span><span class="ix-label-hint">2-level categorical</span></label>
            <select class="ix-select" id="ixCiDiffGrp"></select>
          </div>
        </div>
      </div>
      <div class="ix-ciset" data-ci="pearson_r" hidden>
        <div class="ix-setup-grid">
          <div class="ix-field">
            <label class="ix-label"><span class="ix-label-text">Variable X</span></label>
            <select class="ix-select" id="ixCiPrX"></select>
          </div>
          <div class="ix-field">
            <label class="ix-label"><span class="ix-label-text">Variable Y</span></label>
            <select class="ix-select" id="ixCiPrY"></select>
          </div>
        </div>
      </div>

      <div class="ix-options">
        <label class="ix-toggle">
          <span>Confidence level</span>
          <select class="ix-select-small" id="ixCiLevel">
            <option value="0.90">90%</option>
            <option value="0.95" selected>95%</option>
            <option value="0.99">99%</option>
          </select>
        </label>
      </div>
      <button class="btn btn-primary ix-run" type="button" id="ixCiRun">Compute CI</button>
    </div>

    <!-- LENS: assumption_check -->
    <div class="ix-lens-setup" data-lens="assumption_check" hidden>
      <div class="ix-setup-grid">
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Outcome variable</span><span class="ix-label-hint">Numeric or Likert</span></label>
          <select class="ix-select" id="ixAcOutcome"></select>
        </div>
        <div class="ix-field">
          <label class="ix-label"><span class="ix-label-text">Grouping (optional)</span><span class="ix-label-hint">Categorical, for homogeneity check</span></label>
          <select class="ix-select" id="ixAcGroup"></select>
        </div>
      </div>
      <p class="ix-setup-note">Tests two assumptions: <strong>normality</strong> (skewness + kurtosis bands and a K-S-style statistic vs the fitted normal) and <strong>homogeneity of variance</strong> (Levene's test on absolute deviations from group medians). Recommends the right test variant.</p>
      <button class="btn btn-primary ix-run" type="button" id="ixAcRun">Check assumptions</button>
    </div>

  </div>

  <span class="ix-status" id="ixStatus" role="status" aria-live="polite"></span>

  <!-- ===== Results card ===== -->
  <article class="ix-results" id="ixResults" hidden>
    <header class="ix-results-head">
      <div class="ix-results-eyebrow"><span class="pip" aria-hidden="true"></span><span id="ixLensName">Result</span></div>
      <h3 id="ixHeadline">—</h3>
      <p class="ix-results-sub" id="ixContext">—</p>
    </header>
    <div class="ix-stat-strip" id="ixStatStrip"></div>
    <div class="ix-results-body" id="ixBody"></div>
    <footer class="ix-results-foot">
      <h4 class="ix-block-h">What this means</h4>
      <p class="ix-interp" id="ixInterp">—</p>
      <p class="ix-next" id="ixNext"></p>
    </footer>
  </article>

</section>
