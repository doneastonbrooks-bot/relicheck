<?php
// ReliCheck Effect Size render shell.
// -------------------------------------------------------------------
// Three modes (tabs):
//   - From data:    pick variables, compute one of d / η² / V / r / OR
//   - From summary: type the summary stats (M, SD, n, or 2x2 counts)
//   - Convert:      enter one effect size, get the others
//
// Each mode swaps the input area; the right column is the results card
// with the size, the band, a 95% CI (when applicable), and a one-line
// interpretation. A sticky reference card at the bottom shows Cohen's
// thresholds so the user has the rulers in view.
?>

<section class="es-app" aria-label="Effect Size">

  <!-- ===== Mode tabs ===== -->
  <div class="es-tabs" role="tablist" aria-label="Mode">
    <button class="es-tab is-active" type="button" data-mode="from_data"    role="tab" aria-selected="true">From data</button>
    <button class="es-tab"            type="button" data-mode="from_summary" role="tab" aria-selected="false">From summary stats</button>
    <button class="es-tab"            type="button" data-mode="convert"      role="tab" aria-selected="false">Convert between metrics</button>
  </div>

  <div class="es-body">

    <!-- ============ LEFT: input panel (swaps by mode) ============ -->
    <aside class="es-input">

      <!-- ===== Mode: from data ===== -->
      <div class="es-input-pane" data-pane="from_data">
        <h4 class="es-block-h">Choose an effect size</h4>
        <div class="es-pick" role="tablist" aria-label="Effect-size kind (from data)">
          <button class="es-chip" type="button" data-kind="d">Cohen's d</button>
          <button class="es-chip" type="button" data-kind="eta">η² (ANOVA)</button>
          <button class="es-chip" type="button" data-kind="v">Cramer's V</button>
          <button class="es-chip" type="button" data-kind="r">Pearson r</button>
          <button class="es-chip" type="button" data-kind="or">Odds ratio</button>
        </div>

        <div class="es-fields">
          <div class="es-field">
            <label for="esDataVar1"><span class="es-field-label" id="esDataVar1Label">Outcome (numeric)</span></label>
            <select class="es-select" id="esDataVar1"></select>
          </div>
          <div class="es-field">
            <label for="esDataVar2"><span class="es-field-label" id="esDataVar2Label">Grouping (2-level categorical)</span></label>
            <select class="es-select" id="esDataVar2"></select>
          </div>
        </div>

        <button class="btn btn-primary" type="button" id="esDataRun">Compute</button>
        <span class="es-status" id="esDataStatus" role="status" aria-live="polite"></span>
      </div>

      <!-- ===== Mode: from summary ===== -->
      <div class="es-input-pane" data-pane="from_summary" hidden>
        <h4 class="es-block-h">Choose an effect size</h4>
        <div class="es-pick" role="tablist" aria-label="Effect-size kind (from summary)">
          <button class="es-chip" type="button" data-kind="d">Cohen's d</button>
          <button class="es-chip" type="button" data-kind="eta">η² (ANOVA)</button>
          <button class="es-chip" type="button" data-kind="v">Cramer's V</button>
          <button class="es-chip" type="button" data-kind="r">Pearson r</button>
          <button class="es-chip" type="button" data-kind="or">Odds ratio</button>
        </div>

        <!-- Cohen's d inputs (two groups) -->
        <div class="es-sumset" data-kind="d">
          <div class="es-row">
            <div class="es-field"><label>Group 1 mean (M₁)</label><input type="number" step="any" id="esSumM1"/></div>
            <div class="es-field"><label>Group 1 SD (SD₁)</label><input type="number" step="any" id="esSumSd1"/></div>
            <div class="es-field"><label>Group 1 n (n₁)</label><input type="number" step="1" min="2" id="esSumN1"/></div>
          </div>
          <div class="es-row">
            <div class="es-field"><label>Group 2 mean (M₂)</label><input type="number" step="any" id="esSumM2"/></div>
            <div class="es-field"><label>Group 2 SD (SD₂)</label><input type="number" step="any" id="esSumSd2"/></div>
            <div class="es-field"><label>Group 2 n (n₂)</label><input type="number" step="1" min="2" id="esSumN2"/></div>
          </div>
        </div>

        <!-- η² inputs (ANOVA from summary) -->
        <div class="es-sumset" data-kind="eta" hidden>
          <div class="es-row">
            <div class="es-field"><label>SS between groups</label><input type="number" step="any" id="esEtaSsB"/></div>
            <div class="es-field"><label>SS within groups</label><input type="number" step="any" id="esEtaSsW"/></div>
          </div>
          <div class="es-row">
            <div class="es-field"><label>df between (k − 1)</label><input type="number" step="1" min="1" id="esEtaDfB"/></div>
            <div class="es-field"><label>df within (N − k)</label><input type="number" step="1" min="1" id="esEtaDfW"/></div>
          </div>
        </div>

        <!-- Cramer's V inputs (2x2 only here; for larger tables use the chi-square app) -->
        <div class="es-sumset" data-kind="v" hidden>
          <div class="es-2x2">
            <div></div><div class="es-2x2-head">Col 1</div><div class="es-2x2-head">Col 2</div>
            <div class="es-2x2-head">Row 1</div>
              <input type="number" step="1" min="0" id="esVa" placeholder="a"/>
              <input type="number" step="1" min="0" id="esVb" placeholder="b"/>
            <div class="es-2x2-head">Row 2</div>
              <input type="number" step="1" min="0" id="esVc" placeholder="c"/>
              <input type="number" step="1" min="0" id="esVd" placeholder="d"/>
          </div>
          <p class="es-hint">For larger tables (3×3 etc.), run the Chi-Square app instead — Cramer's V comes out automatically.</p>
        </div>

        <!-- Pearson r inputs -->
        <div class="es-sumset" data-kind="r" hidden>
          <div class="es-row">
            <div class="es-field"><label>Correlation r</label><input type="number" step="any" min="-1" max="1" id="esSumR"/></div>
            <div class="es-field"><label>Sample size n</label><input type="number" step="1" min="3" id="esSumNr"/></div>
          </div>
        </div>

        <!-- Odds ratio inputs (2x2) -->
        <div class="es-sumset" data-kind="or" hidden>
          <div class="es-2x2">
            <div></div><div class="es-2x2-head">Outcome +</div><div class="es-2x2-head">Outcome −</div>
            <div class="es-2x2-head">Exposed</div>
              <input type="number" step="1" min="0" id="esORa" placeholder="a"/>
              <input type="number" step="1" min="0" id="esORb" placeholder="b"/>
            <div class="es-2x2-head">Not exposed</div>
              <input type="number" step="1" min="0" id="esORc" placeholder="c"/>
              <input type="number" step="1" min="0" id="esORd" placeholder="d"/>
          </div>
          <p class="es-hint">OR = (a·d) / (b·c). 95% CI via Wald on log(OR). For sparse cells (any zero) we apply Haldane's 0.5 correction.</p>
        </div>

        <button class="btn btn-primary" type="button" id="esSumRun">Compute</button>
        <span class="es-status" id="esSumStatus" role="status" aria-live="polite"></span>
      </div>

      <!-- ===== Mode: convert ===== -->
      <div class="es-input-pane" data-pane="convert" hidden>
        <h4 class="es-block-h">Convert from</h4>
        <div class="es-pick" role="tablist" aria-label="Source metric">
          <button class="es-chip" type="button" data-src="d">Cohen's d</button>
          <button class="es-chip" type="button" data-src="r">Pearson r</button>
          <button class="es-chip" type="button" data-src="eta">η²</button>
          <button class="es-chip" type="button" data-src="or">Odds ratio</button>
        </div>

        <div class="es-fields">
          <div class="es-field">
            <label><span class="es-field-label" id="esConvSrcLabel">Cohen's d</span></label>
            <input type="number" step="any" id="esConvValue"/>
          </div>
        </div>

        <button class="btn btn-primary" type="button" id="esConvRun">Convert</button>
        <span class="es-status" id="esConvStatus" role="status" aria-live="polite"></span>
      </div>

    </aside>

    <!-- ============ RIGHT: results panel ============ -->
    <article class="es-results" id="esResults" aria-live="polite">
      <div class="es-results-empty" id="esResultsEmpty">
        <p>Pick a mode, enter inputs, and click <strong>Compute</strong>.</p>
        <p class="es-results-empty-sub">The result lands here with the size, the band (negligible / small / medium / large), a 95% confidence interval when applicable, and a one-line interpretation.</p>
      </div>

      <div class="es-results-shown" id="esResultsShown" hidden>
        <header class="es-results-head">
          <div class="es-results-eyebrow">
            <span class="pip" aria-hidden="true"></span>
            <span id="esResultsKindLabel">Effect size</span>
          </div>
          <div class="es-size">
            <span class="es-size-name" id="esSizeName">d</span>
            <span class="es-size-equals">=</span>
            <span class="es-size-value" id="esSizeValue">—</span>
          </div>
          <div class="es-band-row">
            <span class="es-band" id="esBand" data-tone="muted">—</span>
            <span class="es-ci" id="esCi"></span>
          </div>
        </header>

        <div class="es-detail" id="esDetail"></div>

        <footer class="es-results-foot">
          <h4 class="es-block-h">What this means</h4>
          <p class="es-interp" id="esInterp">—</p>
        </footer>
      </div>
    </article>

  </div>

  <!-- ============ Reference card (always visible) ============ -->
  <div class="es-reference">
    <h4 class="es-block-h">Cohen's reference thresholds</h4>
    <div class="es-ref-grid">
      <div class="es-ref-col">
        <div class="es-ref-name">Cohen's d</div>
        <div class="es-ref-list">
          <div><span class="es-ref-band" data-tone="muted">negligible</span> <code>|d| &lt; 0.20</code></div>
          <div><span class="es-ref-band" data-tone="warn">small</span> <code>0.20 ≤ |d| &lt; 0.50</code></div>
          <div><span class="es-ref-band" data-tone="ok">medium</span> <code>0.50 ≤ |d| &lt; 0.80</code></div>
          <div><span class="es-ref-band" data-tone="strong">large</span> <code>|d| ≥ 0.80</code></div>
        </div>
      </div>
      <div class="es-ref-col">
        <div class="es-ref-name">η² (ANOVA)</div>
        <div class="es-ref-list">
          <div><span class="es-ref-band" data-tone="muted">negligible</span> <code>η² &lt; 0.01</code></div>
          <div><span class="es-ref-band" data-tone="warn">small</span> <code>0.01–0.06</code></div>
          <div><span class="es-ref-band" data-tone="ok">medium</span> <code>0.06–0.14</code></div>
          <div><span class="es-ref-band" data-tone="strong">large</span> <code>η² ≥ 0.14</code></div>
        </div>
      </div>
      <div class="es-ref-col">
        <div class="es-ref-name">Pearson r</div>
        <div class="es-ref-list">
          <div><span class="es-ref-band" data-tone="muted">negligible</span> <code>|r| &lt; 0.10</code></div>
          <div><span class="es-ref-band" data-tone="warn">small</span> <code>0.10–0.30</code></div>
          <div><span class="es-ref-band" data-tone="ok">medium</span> <code>0.30–0.50</code></div>
          <div><span class="es-ref-band" data-tone="strong">large</span> <code>|r| ≥ 0.50</code></div>
        </div>
      </div>
      <div class="es-ref-col">
        <div class="es-ref-name">Odds ratio</div>
        <div class="es-ref-list">
          <div><span class="es-ref-band" data-tone="muted">negligible</span> <code>0.83 &lt; OR &lt; 1.21</code></div>
          <div><span class="es-ref-band" data-tone="warn">small</span> <code>1.21–1.86 or its reciprocal</code></div>
          <div><span class="es-ref-band" data-tone="ok">medium</span> <code>1.86–3.00 or reciprocal</code></div>
          <div><span class="es-ref-band" data-tone="strong">large</span> <code>OR ≥ 3.00 or ≤ 0.33</code></div>
        </div>
      </div>
    </div>
    <p class="es-ref-foot">Cohen (1988) thresholds are conventional, not laws. A "small" d that replicates is often more important than a "large" d that doesn't. Always report the size alongside the p-value.</p>
  </div>

</section>
