<?php
// ReliCheck Inferential Analysis render shell.
// -------------------------------------------------------------------
// Markup-only (no html/head/body — the studio chrome handles those).
// Emits a test picker, variable selectors, the Run button, an
// assumption-check strip, and a results card. The engine fills the
// results when the user clicks Run.
//
// When mounted from a dedicated route (t-test.php / anova.php /
// chi-square.php / correlation.php), the mount page sets
//   <script>window.INFERENTIAL_TEST = 't_test'</script>
// and the engine hides the test picker on those routes (the rail item
// already tells the user which test they're running).
//
// Element IDs are stable so the engine can target them.
?>

<section class="inf-app" aria-label="Inferential Analysis">

  <!-- ===== Test picker (segmented control) ===== -->
  <div class="inf-picker" id="infPicker" role="tablist" aria-label="Test type">
    <button class="inf-tab" type="button" data-test="t_test"     role="tab" aria-selected="false">T-test</button>
    <button class="inf-tab" type="button" data-test="anova"      role="tab" aria-selected="false">ANOVA</button>
    <button class="inf-tab" type="button" data-test="chi_square" role="tab" aria-selected="false">Chi-square</button>
    <button class="inf-tab" type="button" data-test="correlation" role="tab" aria-selected="false">Correlation</button>
  </div>

  <!-- ===== Variable pickers ===== -->
  <div class="inf-setup">
    <div class="inf-setup-grid">

      <!-- Outcome / first variable -->
      <div class="inf-field">
        <label class="inf-label" for="infVar1">
          <span class="inf-label-text" id="infVar1Label">Outcome variable</span>
          <span class="inf-label-hint" id="infVar1Hint">Numeric or Likert</span>
        </label>
        <select class="inf-select" id="infVar1" aria-describedby="infVar1Hint"></select>
      </div>

      <!-- Grouping / second variable -->
      <div class="inf-field">
        <label class="inf-label" for="infVar2">
          <span class="inf-label-text" id="infVar2Label">Grouping variable</span>
          <span class="inf-label-hint" id="infVar2Hint">Categorical with 2 levels</span>
        </label>
        <select class="inf-select" id="infVar2" aria-describedby="infVar2Hint"></select>
      </div>

      <!-- Options strip (test-specific) -->
      <div class="inf-options" id="infOptions">
        <label class="inf-toggle">
          <input type="checkbox" id="infWelch" checked />
          <span>Welch's correction (recommended; doesn't assume equal variances)</span>
        </label>
      </div>

    </div>

    <div class="inf-actions">
      <button class="btn btn-primary inf-run" type="button" id="infRun">Run analysis</button>
      <span class="inf-status" id="infStatus" role="status" aria-live="polite"></span>
    </div>
  </div>

  <!-- ===== Assumption check strip ===== -->
  <div class="inf-assumptions" id="infAssumptions" hidden>
    <h4 class="inf-block-h">Assumption check</h4>
    <ul class="inf-assumption-list" id="infAssumptionList"></ul>
  </div>

  <!-- ===== Results card ===== -->
  <article class="inf-results" id="infResults" hidden aria-live="polite">

    <header class="inf-results-head">
      <div class="inf-results-eyebrow">
        <span class="pip" aria-hidden="true"></span>
        <span id="infTestLabel">Test result</span>
      </div>
      <h3 class="inf-results-headline" id="infHeadline">—</h3>
      <p class="inf-results-context" id="infContext">—</p>
    </header>

    <!-- Stat strip: statistic, df, p-value, effect size -->
    <div class="inf-stat-strip">
      <div class="inf-stat">
        <label id="infStatNameLabel">Statistic</label>
        <span class="v" id="infStatistic">—</span>
      </div>
      <div class="inf-stat">
        <label>Degrees of freedom</label>
        <span class="v" id="infDf">—</span>
      </div>
      <div class="inf-stat" id="infPCell">
        <label>p-value</label>
        <span class="v" id="infP">—</span>
        <span class="inf-pip-pill" id="infPPill" data-tone="muted">—</span>
      </div>
      <div class="inf-stat" id="infEffectCell">
        <label id="infEffectLabel">Effect size</label>
        <span class="v" id="infEffect">—</span>
        <span class="inf-effect-band" id="infEffectBand">—</span>
      </div>
    </div>

    <!-- Detail block: group means / contingency table / correlation cloud -->
    <div class="inf-detail" id="infDetail"></div>

    <!-- Plain-language interpretation -->
    <footer class="inf-results-foot">
      <h4 class="inf-block-h">What this means</h4>
      <p class="inf-interp" id="infInterp">—</p>
      <p class="inf-next" id="infNext"></p>
    </footer>

  </article>

  <!-- ===== Empty state (shown until first run) ===== -->
  <div class="inf-empty" id="infEmpty">
    <p>Pick a test, choose your variables, and click <strong>Run analysis</strong>.</p>
    <p class="inf-empty-sub">ReliCheck will compute the statistic, the exact p-value, and an effect size — and tell you in plain language whether the difference is real and how big it is.</p>
  </div>

</section>
