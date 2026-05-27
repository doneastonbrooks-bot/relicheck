<?php
// ReliCheck Strength Index render shell.
// -------------------------------------------------------------------
// Emits the score card + component grid + interpretation panel.
// The mounting page is responsible for the breadcrumb and the
// section h2 above this (because those are studio-shell chrome, not
// app content). After this render, the page provides:
//   <script>window.STRENGTH_DATASET = {...}</script>
//   <script src="/apps/strength-index/strength-index.js"></script>
// and the engine fills in the scores from the dataset.
//
// Element IDs / data attributes: stable so the engine can target them.
?>

<section class="strength-app" aria-label="ReliCheck Strength Index">

  <!-- Hidden mirrors for the engine's existing element IDs. The engine
       writes the numeric score, verdict text, and verdict note to these
       targets; the visible layout above reads from them via observers.
       Brand contract (6 weighted domains, 4-part Reliability) preserved —
       no engine math changes. See [[relicheck-strength-index-formula]]. -->
  <div class="strength-engine-mirrors" hidden aria-hidden="true">
    <span id="strengthScore">—</span>
    <span id="strengthVerdict"><span class="verdict-label">—</span></span>
    <span id="strengthVerdictNote">—</span>
  </div>

  <!-- ===== Apple-Fitness headline: ring + at-a-glance ===== -->
  <div class="grid hero strength-headline">
    <!-- LEFT: Ring + subscore bars -->
    <div class="card strength-ring-card">
      <div class="ring-wrap" style="width: 260px; height: 260px;">
        <svg id="strengthRingSvg" width="260" height="260" style="transform: rotate(-90deg);">
          <defs>
            <linearGradient id="strengthRingGrad" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%"   stop-color="#34C759" stop-opacity="0.85"/>
              <stop offset="100%" stop-color="#34C759" stop-opacity="1"/>
            </linearGradient>
          </defs>
          <circle cx="130" cy="130" r="117" stroke="var(--surface-2)" stroke-width="22" fill="none"/>
          <circle id="strengthRingArc"
                  cx="130" cy="130" r="117"
                  stroke="url(#strengthRingGrad)" stroke-width="22" fill="none"
                  stroke-dasharray="735" stroke-dashoffset="735"
                  stroke-linecap="round"
                  style="transition: stroke-dashoffset 1.2s cubic-bezier(.2,.7,.2,1);"/>
        </svg>
        <div class="ring-center">
          <div>
            <div class="ring-score"><span id="strengthRingScore">—</span><sup>/100</sup></div>
            <div class="ring-label" id="strengthRingLabel">Computing…</div>
          </div>
        </div>
      </div>

      <div class="ss-bars">
        <div class="ss-row" data-domain="reliability">
          <div class="ss-head"><span class="ss-label">Reliability</span><span class="ss-num" data-fill="num">—</span></div>
          <div class="bar"><div data-fill="bar" style="width:0%;background:#34C759;"></div></div>
        </div>
        <div class="ss-row" data-domain="factor_structure">
          <div class="ss-head"><span class="ss-label">Validity</span><span class="ss-num" data-fill="num">—</span></div>
          <div class="bar"><div data-fill="bar" style="width:0%;background:#0b84ff;"></div></div>
        </div>
        <div class="ss-row" data-domain="item_quality">
          <div class="ss-head"><span class="ss-label">Trustworth.</span><span class="ss-num" data-fill="num">—</span></div>
          <div class="bar"><div data-fill="bar" style="width:0%;background:#FF9500;"></div></div>
        </div>
        <div class="ss-pills">
          <span class="pill good" id="strengthReadyPill" hidden>Ready for inferential testing</span>
          <span class="pill info" id="strengthVersionPill">v2.1 · today</span>
        </div>
      </div>
    </div>

    <!-- RIGHT: At a glance -->
    <div class="card strength-glance">
      <div class="card-header">
        <div>
          <h3 class="card-title">At a glance</h3>
          <div class="card-sub" id="strengthGlanceSub">Composite of 6 signals</div>
        </div>
      </div>
      <div class="glance-list">
        <div class="glance-row">
          <div class="glance-label">Sample</div>
          <div class="glance-value" id="agSample">—</div>
        </div>
        <div class="glance-row">
          <div class="glance-label">Response rate</div>
          <div class="glance-value" id="agResponse">—</div>
        </div>
        <div class="glance-row">
          <div class="glance-label">Items reviewed</div>
          <div class="glance-value" id="agItems">—</div>
          <div class="glance-sub" id="agItemsSub"></div>
        </div>
        <div class="glance-row">
          <div class="glance-label">Domains scored</div>
          <div class="glance-value" id="agDomains">6 / 6</div>
        </div>
        <div class="glance-divider"></div>
        <div class="glance-tags">
          <span class="pill quant">QUANT</span>
          <span class="pill qual">QUAL</span>
          <span class="pill mm">MM</span>
        </div>
      </div>
    </div>
  </div>

  <style>
    /* Headline grid */
    .strength-headline { gap: 24px; margin-bottom: 32px; }
    .strength-ring-card {
      display: flex; align-items: center; gap: 36px;
      flex-wrap: wrap;
      padding: 36px 40px !important;
    }
    .strength-ring-card .ring-wrap { flex-shrink: 0; }
    .strength-ring-card .ring-score { font-size: 64px; letter-spacing: -0.04em; }
    .strength-ring-card .ring-score sup { font-size: 22px; vertical-align: 18px; font-weight: 500; color: var(--rc-text-muted); }
    .strength-ring-card .ring-label { font-size: 14px; color: var(--rc-text-muted); margin-top: 6px; font-weight: 500; }

    .ss-bars { flex: 1 1 240px; min-width: 0; display: flex; flex-direction: column; gap: 18px; }
    .ss-row .ss-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px; }
    .ss-label { font-size: 14px; font-weight: 500; color: var(--rc-text-primary); }
    .ss-num { font-family: var(--rc-font-mono); font-size: 13px; font-weight: 600; }
    .ss-row[data-domain="reliability"] .ss-num      { color: #1F7A3F; }
    .ss-row[data-domain="factor_structure"] .ss-num { color: #0356C9; }
    .ss-row[data-domain="item_quality"] .ss-num     { color: #A35F00; }
    .ss-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px; }

    /* At a glance card */
    .strength-glance { padding: 32px 36px !important; }
    .strength-glance .card-header { margin-bottom: 8px; }
    .glance-list { display: flex; flex-direction: column; gap: 18px; }
    .glance-row { display: flex; flex-direction: column; gap: 2px; }
    .glance-label { font-size: 13px; color: var(--rc-text-muted); font-weight: 500; }
    .glance-value {
      font-family: var(--rc-font-sans);
      font-size: 28px; font-weight: 700;
      letter-spacing: -0.025em;
      color: var(--rc-text-primary);
      line-height: 1.1;
    }
    .glance-sub { font-size: 12.5px; color: var(--rc-text-muted); margin-top: 2px; }
    .glance-divider { height: 1px; background: var(--rc-border-soft); margin: 4px 0; }
    .glance-tags { display: flex; gap: 6px; flex-wrap: wrap; }

    @media (max-width: 1100px) {
      .strength-headline { grid-template-columns: 1fr !important; }
    }
  </style>

  <script>
    // Bridge engine output → image-2 layout.
    // Engine still writes to #strengthScore, #strengthVerdict, and exposes
    // window.RELICHECK_INSTRUMENT_DIAGNOSTICS once it finishes. We observe
    // the score element and re-render the ring + subscore bars + glance.
    (function () {
      const scoreEl  = document.getElementById('strengthScore');
      const ringNum  = document.getElementById('strengthRingScore');
      const ringArc  = document.getElementById('strengthRingArc');
      const ringLbl  = document.getElementById('strengthRingLabel');
      const verdLbl  = document.querySelector('#strengthVerdict .verdict-label');
      if (!scoreEl || !ringArc) return;

      const RING_FULL = 2 * Math.PI * 117;
      ringArc.setAttribute('stroke-dasharray', RING_FULL.toFixed(2));
      ringArc.setAttribute('stroke-dashoffset', RING_FULL.toFixed(2));

      function syncRing() {
        const n = parseFloat(scoreEl.textContent);
        if (!isFinite(n)) return;
        if (ringNum) ringNum.textContent = Math.round(n);
        ringArc.style.strokeDashoffset = (RING_FULL - (RING_FULL * (n / 100))).toFixed(2);
        if (ringLbl && verdLbl && verdLbl.textContent && verdLbl.textContent !== '—') {
          ringLbl.textContent = verdLbl.textContent;
        }
      }

      function syncSubscores() {
        const d = window.RELICHECK_INSTRUMENT_DIAGNOSTICS;
        if (!d || !d.domains) return;
        document.querySelectorAll('.ss-row[data-domain]').forEach(row => {
          const key = row.getAttribute('data-domain');
          const dom = d.domains[key];
          if (!dom) return;
          const num = row.querySelector('[data-fill="num"]');
          const bar = row.querySelector('[data-fill="bar"]');
          if (num) num.textContent = (dom.score == null ? '—' : Math.round(dom.score));
          if (bar) bar.style.width = (dom.score == null ? 0 : Math.max(0, Math.min(100, dom.score))) + '%';
        });
        // Ready-for-inferential pill
        const readyPill = document.getElementById('strengthReadyPill');
        if (readyPill && d.reportStatus) {
          if (String(d.reportStatus).toLowerCase().indexOf('ready') !== -1
              || String(d.reportStatus).toLowerCase().indexOf('strong') !== -1) {
            readyPill.hidden = false;
          }
        }
        // Version stamp
        const ver = document.getElementById('strengthVersionPill');
        if (ver) {
          const today = new Date().toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
          ver.textContent = 'v2.1 · ' + today;
        }
      }

      function syncGlance() {
        const dataset = window.STRENGTH_DATASET_RESOLVED || window.STRENGTH_DATASET;
        if (!dataset || !dataset.variables) return;
        const n     = dataset.rowCount || (dataset.variables[0] && dataset.variables[0].values ? dataset.variables[0].values.length : 0);
        const items = dataset.variables.length;

        // Likert sub-count for "32 SES + 8 PSSM"-style breakdown
        let likert = 0, openEnded = 0;
        dataset.variables.forEach(v => {
          if (v.types && v.types.indexOf('likert')   !== -1) likert++;
          if (v.types && v.types.indexOf('open')     !== -1) openEnded++;
        });

        // Completion / response rate
        let totalCells = 0, missing = 0;
        dataset.variables.forEach(v => {
          (v.values || []).forEach(val => {
            totalCells++;
            if (val === '' || val == null) missing++;
          });
        });
        const completion = totalCells ? Math.round(((totalCells - missing) / totalCells) * 100) : 0;

        document.getElementById('agSample').textContent   = n;
        document.getElementById('agResponse').textContent = completion + '%';
        document.getElementById('agItems').textContent    = items;
        const sub = document.getElementById('agItemsSub');
        if (sub) {
          const parts = [];
          if (likert)    parts.push(likert    + ' Likert');
          if (openEnded) parts.push(openEnded + ' open-ended');
          sub.textContent = parts.join(' + ');
        }
      }

      function syncAll() { syncRing(); syncSubscores(); syncGlance(); }
      syncAll();
      new MutationObserver(syncAll).observe(scoreEl, { childList: true, characterData: true, subtree: true });
      if (verdLbl) new MutationObserver(syncAll).observe(verdLbl, { childList: true, characterData: true, subtree: true });
      // Belt-and-suspenders — re-pull from window globals after the engine has run.
      setTimeout(syncAll, 400);
      setTimeout(syncAll, 1200);
    })();
  </script>

  <!-- ===== Readiness label + report status (filled by engine) ===== -->
  <div class="strength-readiness-bar">
    <div class="strength-readiness" id="strengthReadiness" data-tone="adequate" hidden>
      <div class="readiness-label-wrap">
        <span class="readiness-eyebrow">Readiness</span>
        <span class="readiness-label">—</span>
      </div>
      <p class="readiness-judgment">—</p>
    </div>
    <div class="strength-report-status" id="strengthReportStatus" data-tone="adequate" hidden>
      <span class="report-status-eyebrow">Report status</span>
      <span class="report-status-label">—</span>
    </div>
  </div>

  <!-- ===== What to fix first (filled by engine) ===== -->
  <section class="strength-fix-first" id="strengthFixFirst" aria-label="Top fix priorities"></section>

  <!-- ===== Component grid (six canonical domains) ===== -->
  <div class="component-grid" id="strengthComponents">
    <div class="component-card component-card-wide" data-component="reliability">
      <div class="component-name">Internal Consistency &amp; Scale Reliability <span class="domain-max">/ 25</span></div>
      <div class="component-score" data-fill="score">—</div>
      <div class="component-note" data-fill="note">computing…</div>
      <div class="component-bar"><span data-fill="bar" style="width:0%"></span></div>
    </div>
    <div class="component-card" data-component="factor_structure">
      <div class="component-name">Factor Structure <span class="domain-max">/ 20</span></div>
      <div class="component-score" data-fill="score">—</div>
      <div class="component-note" data-fill="note">computing…</div>
      <div class="component-bar"><span data-fill="bar" style="width:0%"></span></div>
    </div>
    <div class="component-card" data-component="item_quality">
      <div class="component-name">Item Quality <span class="domain-max">/ 20</span></div>
      <div class="component-score" data-fill="score">—</div>
      <div class="component-note" data-fill="note">computing…</div>
      <div class="component-bar"><span data-fill="bar" style="width:0%"></span></div>
    </div>
    <div class="component-card" data-component="response_quality">
      <div class="component-name">Response Quality <span class="domain-max">/ 15</span></div>
      <div class="component-score" data-fill="score">—</div>
      <div class="component-note" data-fill="note">computing…</div>
      <div class="component-bar"><span data-fill="bar" style="width:0%"></span></div>
    </div>
    <div class="component-card" data-component="open_ended">
      <div class="component-name">Open-Ended Alignment <span class="domain-max">/ 10</span></div>
      <div class="component-score" data-fill="score">—</div>
      <div class="component-note" data-fill="note">computing…</div>
      <div class="component-bar"><span data-fill="bar" style="width:0%"></span></div>
    </div>
    <div class="component-card" data-component="actionability">
      <div class="component-name">Actionability <span class="domain-max">/ 10</span></div>
      <div class="component-score" data-fill="score">—</div>
      <div class="component-note" data-fill="note">computing…</div>
      <div class="component-bar"><span data-fill="bar" style="width:0%"></span></div>
    </div>
  </div>

  <!-- ===== Interpretation panel ===== -->
  <div class="interp-card">
    <h3>What this means</h3>
    <p id="interpLead">Computing your interpretation…</p>
    <p id="interpFocus"></p>
    <div class="interp-actions">
      <button class="btn btn-ghost" type="button" id="interpFocusBtn" hidden>Open panel</button>
      <button class="btn btn-ghost" type="button" id="strengthMethodBtn">View methodology note</button>
    </div>
  </div>

  <!-- ===== Per-domain explainers — the deepest part of the product =====
       One card per Strength Index domain. Each covers What / Why / How /
       Score meaning. Click a domain card above to scroll its explainer
       into view; the "Open panel" button does the same. -->
  <div class="strength-explainers" id="strengthExplainers" aria-label="Domain explainers">
    <h3 class="explainers-head">Understand each indicator</h3>
    <p class="explainers-sub">Six independent checks roll up into the Strength Index. Lower domain scores tell you exactly what to fix; the explainers below say how each is computed and what the score means.</p>

    <article class="explainer-card" id="explainer-reliability" data-domain="reliability">
      <header>
        <h4>Internal Consistency &amp; Scale Reliability</h4>
      </header>
      <p class="what"><strong>What it measures.</strong> Whether the items within a scale move together — i.e., they tap the same underlying construct. The single most influential domain in the Strength Index.</p>
      <p class="why"><strong>Why it matters.</strong> If the items don't hang together, the scale isn't really measuring one thing, and any mean, comparison, or correlation built on it is suspect.</p>
      <p class="how"><strong>How it's computed.</strong> Cronbach's α, McDonald's ω, inter-rater / inter-item agreement, item-rest correlations, and a redundancy penalty.</p>
      <p class="bands"><strong>Score meaning.</strong> Publishable, acceptable for research, borderline (revise weak items), or rebuild before using.</p>
    </article>

    <article class="explainer-card" id="explainer-factor_structure" data-domain="factor_structure">
      <header>
        <h4>Factor Structure</h4>
      </header>
      <p class="what"><strong>What it measures.</strong> Whether your items group into the factors you expected. Cross-loadings, sampling adequacy, sphericity, and the eigenvalue pattern.</p>
      <p class="why"><strong>Why it matters.</strong> A clean factor structure is the evidence that the items represent the constructs your scale names claim — the core of construct validity.</p>
      <p class="how"><strong>How it's computed.</strong> KMO sampling adequacy, Bartlett's test of sphericity, factor readiness, primary-loading clarity, and cross-loading flags.</p>
      <p class="bands"><strong>Score meaning.</strong> Clean factor structure, mixed (at least one item to revise), or not publishable as-is.</p>
    </article>

    <article class="explainer-card" id="explainer-item_quality" data-domain="item_quality">
      <header>
        <h4>Item Quality</h4>
      </header>
      <p class="what"><strong>What it measures.</strong> The strength of each individual item: discrimination, endpoint use, response-distribution health, whether removing it would improve the scale.</p>
      <p class="why"><strong>Why it matters.</strong> A scale is only as strong as its weakest items. Two or three bad items can drag a whole instrument below threshold.</p>
      <p class="how"><strong>How it's computed.</strong> Item-rest correlations, "if-item-deleted" α, response-scale endpoint usage, ceiling/floor flags, and discrimination indices.</p>
      <p class="bands"><strong>Score meaning.</strong> Strong items across the board, some items underperform, or at least one item must be revised or removed.</p>
    </article>

    <article class="explainer-card" id="explainer-response_quality" data-domain="response_quality">
      <header>
        <h4>Response Quality</h4>
      </header>
      <p class="what"><strong>What it measures.</strong> Whether respondents actually engaged with the survey. Completion rate, straight-lining, response time, duplicate detection, missingness.</p>
      <p class="why"><strong>Why it matters.</strong> Even a perfect instrument produces unusable data if respondents click through without reading. This is the floor under everything else.</p>
      <p class="how"><strong>How it's computed.</strong> Completion rate, straight-line index, time-on-task outliers, duplicate fingerprints, and item-level missingness.</p>
      <p class="bands"><strong>Score meaning.</strong> High-quality responses, workable with light cleaning, or re-collect / aggressively clean before analysis.</p>
    </article>

    <article class="explainer-card" id="explainer-open_ended" data-domain="open_ended">
      <header>
        <h4>Open-Ended Alignment</h4>
      </header>
      <p class="what"><strong>What it measures.</strong> Whether what people say in their own words lines up with what the quantitative scales show. Theme-to-score alignment.</p>
      <p class="why"><strong>Why it matters.</strong> Open-ended responses are the validity check on the closed-ended ones. When the comments contradict the numbers, the numbers are usually the ones missing the story.</p>
      <p class="how"><strong>How it's computed.</strong> Codebook theme overlap with high vs. low scorers, sentiment-vs-score consistency, and salience of expected constructs in the qual data.</p>
      <p class="bands"><strong>Score meaning.</strong> Numbers and voice agree, mixed signals (investigate divergence), or numbers and voice diverge (likely a face-validity problem).</p>
    </article>

    <article class="explainer-card" id="explainer-actionability" data-domain="actionability">
      <header>
        <h4>Actionability</h4>
      </header>
      <p class="what"><strong>What it measures.</strong> Whether the findings are big enough, clear enough, and specific enough to act on. Effect sizes, group-difference magnitudes, decision-readiness.</p>
      <p class="why"><strong>Why it matters.</strong> A statistically significant but tiny effect is rarely worth changing policy over. Actionability filters "interesting" from "decision-relevant."</p>
      <p class="how"><strong>How it's computed.</strong> Effect-size magnitudes (Cohen's d, η², r), group-comparison clarity, scale-level variance, and a decision-readiness composite.</p>
      <p class="bands"><strong>Score meaning.</strong> Findings drive decisions, informative but soft, or statistically real but practically weak.</p>
    </article>
  </div>

  <!-- ===== Methodology note (hidden until "View methodology note" clicked) ===== -->
  <!-- Per product direction: do not expose internal weights or point values
       to the end user. Methodology note describes the domains and what each
       contributes, without numeric weights. -->
  <div class="strength-method" id="strengthMethod" hidden>
    <h3>Methodology note</h3>
    <p>The ReliCheck Strength Index is a composite that integrates six independent checks of an instrument's quality. Each domain contributes to the headline score; the domain views above explain what each one measures and how to read it.</p>
    <table class="method-table">
      <thead><tr><th>Domain</th><th>What it draws on</th></tr></thead>
      <tbody>
        <tr><td>Internal Consistency &amp; Scale Reliability</td><td>Cronbach's α, McDonald's ω, inter-rater / inter-item agreement, item-rest correlations, redundancy</td></tr>
        <tr><td>Factor Structure</td><td>KMO, Bartlett, factor readiness, loading clarity</td></tr>
        <tr><td>Item Quality</td><td>item-rest, if-item-deleted α, endpoint use, ceiling/floor</td></tr>
        <tr><td>Response Quality</td><td>completion, straight-lining, time-on-task, duplicates, missingness</td></tr>
        <tr><td>Open-Ended Alignment</td><td>theme-to-score consistency, sentiment-vs-score, construct salience</td></tr>
        <tr><td>Actionability</td><td>effect size, group-difference clarity, decision readiness</td></tr>
      </tbody>
    </table>
    <p class="method-band"><strong>Composite bands.</strong> Publish-ready · strong for internal use · acceptable with caveats · revise the instrument before reporting.</p>
  </div>

  <!-- ===== Domain explainers + methodology — wiring + styles ===== -->
  <style>
    /* Readiness bar — sits between the big score card and the domain grid */
    .strength-readiness-bar {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 12px;
      margin: 18px 0 22px;
      align-items: stretch;
    }
    @media (max-width: 760px) { .strength-readiness-bar { grid-template-columns: 1fr; } }
    .strength-readiness, .strength-report-status {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px 20px;
    }
    .strength-readiness[data-tone="strong"]   { border-left: 4px solid #1f7a3a; }
    .strength-readiness[data-tone="adequate"] { border-left: 4px solid #b35a00; }
    .strength-readiness[data-tone="caution"]  { border-left: 4px solid #c2492f; }
    .strength-readiness[data-tone="weak"]     { border-left: 4px solid #8b1a1a; }
    .readiness-eyebrow, .report-status-eyebrow {
      display: block; font-size: 11px; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.06em;
      color: var(--ink-5); margin-bottom: 4px;
    }
    .readiness-label {
      font-family: 'Fraunces', 'Georgia', serif;
      font-size: 22px; font-weight: 600; color: var(--ink-1, #1c2238);
    }
    .readiness-judgment {
      margin: 8px 0 0; color: var(--ink-3, #424a5e);
      font-size: 14px; line-height: 1.5;
    }
    .strength-report-status {
      display: flex; flex-direction: column; justify-content: center;
      min-width: 200px;
    }
    .strength-report-status[data-tone="strong"]   { background: #f2faf4; border-color: #d2e9d8; }
    .strength-report-status[data-tone="adequate"] { background: #fff7ed; border-color: #fadcb5; }
    .strength-report-status[data-tone="weak"]     { background: #fff3f0; border-color: #f3c8b9; }
    .report-status-label {
      font-family: 'Fraunces', serif; font-size: 16px; font-weight: 600;
      color: var(--ink-1, #1c2238);
    }

    /* What to fix first — the prioritized action panel */
    .strength-fix-first { margin: 22px 0; }
    .strength-fix-first:empty { display: none; }
    .fix-head { font-family: 'Fraunces', 'Georgia', serif; font-size: 22px; font-weight: 600; margin: 0 0 6px; color: var(--ink-1, #1c2238); }
    .fix-sub { color: var(--ink-3, #424a5e); font-size: 14px; line-height: 1.5; max-width: 760px; margin: 0 0 12px; }
    .fix-list { display: grid; gap: 10px; }
    .fix-row {
      display: grid; grid-template-columns: 36px 1fr; gap: 14px;
      background: #fff; border: 1px solid var(--line); border-radius: 14px;
      padding: 18px 20px;
    }
    .fix-row[data-sev="critical"] { border-left: 4px solid #c2492f; }
    .fix-row[data-sev="watch"]    { border-left: 4px solid #b35a00; }
    .fix-row[data-sev="minor"]    { border-left: 4px solid var(--accent); }
    .fix-rank {
      width: 28px; height: 28px; border-radius: 999px;
      display: inline-flex; align-items: center; justify-content: center;
      background: var(--ink-1, #1c2238); color: #fff;
      font-family: 'Fraunces', serif; font-weight: 600; font-size: 13px;
    }
    .fix-row-head {
      display: flex; gap: 10px; align-items: center;
      margin-bottom: 4px;
    }
    .fix-sev {
      font-size: 10.5px; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.06em;
      padding: 3px 9px; border-radius: 999px;
    }
    .fix-row[data-sev="critical"] .fix-sev { background: #fdeae6; color: #c2492f; }
    .fix-row[data-sev="watch"]    .fix-sev { background: #fef3c7; color: #92400e; }
    .fix-row[data-sev="minor"]    .fix-sev { background: var(--accent-soft); color: var(--accent); }
    .fix-domain { font-size: 12px; color: var(--ink-5); font-weight: 600; }
    .fix-issue { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; margin: 0 0 8px; color: var(--ink-1, #1c2238); }
    .fix-jema { display: grid; gap: 6px; font-size: 13.5px; line-height: 1.55; color: var(--ink-3, #424a5e); }
    .fix-jema strong { color: var(--ink-1, #1c2238); }
    .fix-empty {
      padding: 18px 20px; background: #f2faf4; border: 1px solid #d2e9d8;
      border-radius: 12px; color: var(--ink-2); font-size: 14px; line-height: 1.55;
    }

    .strength-explainers { margin-top: 24px; }
    .explainers-head { font-family: 'Fraunces', 'Georgia', serif; font-size: 22px; font-weight: 600; margin: 0 0 6px; color: var(--ink-1, #1c2238); }
    .explainers-sub { color: var(--ink-3, #424a5e); font-size: 14px; line-height: 1.55; max-width: 760px; margin: 0 0 16px; }
    .explainer-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 18px 22px; margin-bottom: 12px; scroll-margin-top: 80px; }
    .explainer-card.is-focused { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
    .explainer-card header { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
    .explainer-card h4 { font-family: 'Fraunces', 'Georgia', serif; font-size: 19px; font-weight: 600; margin: 0; color: var(--ink-1, #1c2238); }
    .weight-pill { display: inline-block; padding: 4px 10px; background: var(--accent-soft); color: var(--accent); border-radius: 999px; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; }
    .explainer-card p { font-size: 14px; line-height: 1.55; color: var(--ink-3, #424a5e); margin: 6px 0; }
    .explainer-card strong { color: var(--ink-1, #1c2238); }
    .explainer-card .bands { background: var(--bg-tint, #f6f8fb); padding: 10px 14px; border-radius: 8px; margin-top: 10px; font-size: 13.5px; }

    .strength-method { margin-top: 18px; padding: 22px 24px; background: #fff; border: 1px solid var(--line); border-radius: 14px; }
    .strength-method h3 { font-family: 'Fraunces', serif; font-size: 20px; font-weight: 600; margin: 0 0 8px; color: var(--ink-1, #1c2238); }
    .strength-method p { color: var(--ink-3, #424a5e); font-size: 14px; line-height: 1.55; }
    .method-table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 13.5px; }
    .method-table th, .method-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid var(--line); }
    .method-table th { background: var(--bg-tint, #f6f8fb); color: var(--ink-2); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
    .method-table td:nth-child(2) { font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace; color: var(--accent); font-weight: 700; width: 60px; }
    .method-band { background: var(--accent-soft); border-radius: 8px; padding: 10px 14px; margin-top: 6px; }
    .method-source { color: var(--ink-5); font-size: 12.5px; margin-top: 10px; }

    .component-card { cursor: pointer; }
    .component-card:hover { border-color: var(--accent); }
  </style>
  <script>
  (function () {
    // Click a domain card above → scroll its explainer below into view + flash.
    document.querySelectorAll('.component-card[data-component]').forEach(card => {
      card.addEventListener('click', () => focusExplainer(card.getAttribute('data-component')));
    });
    // "Open panel" button (was hidden + unwired) — scrolls to the explainer
    // matching window.STRENGTH_FOCUS_DOMAIN (set by the engine), defaulting to reliability.
    const focusBtn = document.getElementById('interpFocusBtn');
    if (focusBtn) {
      focusBtn.removeAttribute('hidden');
      focusBtn.textContent = 'Open Internal Consistency & Scale Reliability panel';
      focusBtn.addEventListener('click', () => {
        const target = (window.STRENGTH_FOCUS_DOMAIN || 'reliability');
        focusExplainer(target);
        focusBtn.textContent = ({
          reliability:      'Open Internal Consistency & Scale Reliability panel',
          factor_structure: 'Open Factor Structure panel',
          item_quality:     'Open Item Quality panel',
          response_quality: 'Open Response Quality panel',
          open_ended:       'Open Open-Ended Alignment panel',
          actionability:    'Open Actionability panel',
        })[target] || 'Open panel';
      });
    }
    // Methodology toggle
    const mBtn = document.getElementById('strengthMethodBtn');
    const mBox = document.getElementById('strengthMethod');
    if (mBtn && mBox) {
      mBtn.addEventListener('click', () => {
        const open = !mBox.hasAttribute('hidden');
        if (open) { mBox.setAttribute('hidden', ''); mBtn.textContent = 'View methodology note'; }
        else      { mBox.removeAttribute('hidden'); mBtn.textContent = 'Hide methodology note'; mBox.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      });
    }
    function focusExplainer(domain) {
      const el = document.getElementById('explainer-' + domain);
      if (!el) return;
      document.querySelectorAll('.explainer-card.is-focused').forEach(c => c.classList.remove('is-focused'));
      el.classList.add('is-focused');
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(() => el.classList.remove('is-focused'), 2400);
    }
  })();
  </script>

  <!-- The full item-level Cronbach table used to render inline here. Per
       the spec it belongs on the dedicated Reliability rail item (so the
       Strength Index page stays an executive summary). The engine's
       renderReliabilityDetail() now no-ops when no #reliabilityDetailBody
       container is present — meaning this view stays clean. -->

</section>
