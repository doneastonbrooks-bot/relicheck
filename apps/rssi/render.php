<?php
// apps/rssi/render.php
// RSSI (ReliCheck Strength Survey Index) dashboard body.
// Expects from mount: $rssi_project (assoc, with title + id + meta),
// $rssi_user (assoc), $rssi_back_url.
// All numeric placeholders are hydrated client-side by rssi.js.

$rssi_proj_title = $rssi_project['title'] ?? 'Untitled survey';
$rssi_proj_id    = (int)($rssi_project['id'] ?? 0);
$rssi_user_full  = $rssi_user['name'] ?? $rssi_user['email'] ?? 'You';
$rssi_initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $rssi_user_full) ?: 'U', 0, 2));
?>
<div class="rssi-app" data-project-id="<?= $rssi_proj_id ?>">
<div class="app">

  <!-- =================== SIDEBAR =================== -->
  <aside class="sidebar">
    <div class="brand brand-logo-only">
      <img src="/RSSI-logo.png" alt="ReliCheck Strength Survey Index" class="brand-logo-full">
    </div>

    <div class="search">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="5"/><path d="m11 11 3 3" stroke-linecap="round"/></svg>
      <span>Search</span>
      <span class="kbd">⌘K</span>
    </div>

    <div class="nav-group">
      <div class="nav-label">Diagnostic</div>

      <!-- Every sidebar link is in-app: scrolls to the matching dimension card.
           NO links escape to the Survey Studio. -->
      <a class="nav-item active" data-nav="overview" href="#rssi-overview">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 9.5 8 4l6 5.5V13a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1Z" stroke-linejoin="round"/></svg></span>
        Overview
      </a>
      <!-- Sidebar order = Spec §2 canonical taxonomy. Anchors match the
           ids the dim-grid emits in rssi.js (V2_DOMAINS keys), so every
           click scrolls to a real card. -->
      <a class="nav-item" data-nav="reliability" href="#dim-reliability">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5 2.5 3.8v3.7c0 3.4 2.4 6.2 5.5 7 3.1-.8 5.5-3.6 5.5-7V3.8L8 1.5Z" stroke-linejoin="round"/></svg></span>
        Reliability
      </a>
      <a class="nav-item" data-nav="validity" href="#dim-validity">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="5.5"/><path d="M6 8.5 7.5 10 10 6.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        Validity
      </a>
      <a class="nav-item" data-nav="construct-alignment" href="#dim-construct_alignment">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="3" rx="1"/><rect x="2" y="7" width="9" height="3" rx="1"/><rect x="2" y="11" width="6" height="3" rx="1"/></svg></span>
        Construct Alignment
      </a>
      <a class="nav-item" data-nav="item-prompt-quality" href="#dim-item_prompt_quality">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="3" rx="1"/><rect x="2" y="7.5" width="12" height="2" rx="0.7"/><rect x="2" y="11" width="8" height="2" rx="0.7"/></svg></span>
        Item / Prompt Quality
      </a>
      <a class="nav-item" data-nav="bias-clarity" href="#dim-bias_clarity">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="7" r="4"/><path d="M2 14l5-5M14 14l-2-2"/></svg></span>
        Bias &amp; Clarity Review
      </a>
      <a class="nav-item" data-nav="scale-structure" href="#dim-scale_structure">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 13V3M3 13h10M5.5 11V8M8 11V5M10.5 11V9" stroke-linecap="round"/></svg></span>
        Scale Structure
      </a>
      <a class="nav-item" data-nav="factor-readiness" href="#dim-factor_readiness">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8a5 5 0 1 0 10 0A5 5 0 0 0 3 8Z"/><path d="M8 3v10M3 8h10" stroke-linecap="round"/></svg></span>
        Factor Readiness
      </a>
      <a class="nav-item" data-nav="response-scale-review" href="#dim-response_scale_review">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8h10M3 4h10M3 12h6"/><circle cx="6" cy="8" r="1" fill="currentColor" stroke="none"/><circle cx="10" cy="4" r="1" fill="currentColor" stroke="none"/></svg></span>
        Response Scale Review
      </a>
    </div>

    <div class="nav-group">
      <div class="nav-label">Improve</div>
      <a class="nav-item" data-nav="recommendations" href="#rssi-issues">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5a4.5 4.5 0 0 0-2.7 8.1V11h5.4v-1.4A4.5 4.5 0 0 0 8 1.5ZM6 13h4M7 14.5h2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        Recommendations
      </a>
      <a class="nav-item" data-nav="export" href="javascript:window.print()">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2v8m0 0L5 7m3 3 3-3M3 12v1a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        Export Report
      </a>
    </div>

    <div class="sidebar-footer">
      <div class="project-card">
        <div class="label">Current survey</div>
        <div class="name"><?= htmlspecialchars($rssi_proj_title) ?></div>
        <div class="meta">
          <span id="rssiProjMetaItems">— items</span><span class="dot"></span><span id="rssiProjMetaResp">— responses</span>
        </div>
        <a class="switch" href="/rssi.php">
          <span>Switch survey</span>
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m4 2 4 4-4 4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
      </div>
    </div>
  </aside>

  <!-- =================== MAIN =================== -->
  <main class="main">

    <!-- Top bar -->
    <div class="topbar">
      <div class="crumbs">
        <span>ReliCheck</span><span class="sep">›</span>
        <span>Apps</span><span class="sep">›</span>
        <span class="here">Strength Survey Index</span>
      </div>
      <div class="topbar-actions">
        <button class="btn" type="button" onclick="location.reload()">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 7a5 5 0 1 1-1.5-3.5L12 5M12 2v3h-3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Re-score
        </button>
        <button class="btn" type="button" onclick="window.print()">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 8.5V11a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V8.5M7 2v6.5m0 0L4.5 6M7 8.5 9.5 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Export
        </button>
        <a class="btn btn-primary" href="/rssi.php">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 7h8m0 0L8 4m3 3-3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Score another
        </a>
      </div>
    </div>

    <!-- Sample data banner — visible only when no saved analysis exists -->
    <div class="sample-banner" id="rssiSampleBanner" hidden>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><circle cx="12" cy="16" r="0.6" fill="currentColor" stroke="none"/></svg>
      <span>This is a <strong>preview using sample data</strong>. <a href="/rssi.php">Upload your survey data</a> to populate this report with your real numbers.</span>
    </div>

    <!-- Anchor target for the sidebar's "Overview" link -->
    <a id="rssi-overview" style="display:block;height:0;"></a>

    <!-- Print-only exec-board PDF cover header (KNOWN_ISSUES #22
         polish). Mirrors rssi-upload.php's .rssi-print-cover block. -->
    <div class="rssi-print-cover" aria-hidden="true">
      <div class="rssi-print-cover-brand">
        <img src="/logo-brand.svg" alt="" aria-hidden="true">
      </div>
      <div class="rssi-print-cover-text">
        <div class="rssi-print-cover-eyebrow">Strength Survey Index report</div>
        <h1 class="rssi-print-cover-title" id="rssiPrintCoverTitle"><?= htmlspecialchars($rssi_proj_title) ?></h1>
        <div class="rssi-print-cover-meta">
          Prepared by <strong><?= htmlspecialchars($rssi_user_full) ?></strong>
          &middot; Scored <span id="rssiPrintCoverDate">&mdash;</span>
          &middot; ReliCheck Strength Survey Index v2.0
        </div>
      </div>
    </div>

    <!-- Title row -->
    <div class="title-row">
      <div>
        <h1 class="h1"><?= htmlspecialchars($rssi_proj_title) ?></h1>
        <div class="subtitle-row">
          <span class="pill pill-blue" id="rssiVerdictPill">—</span>
          <span id="rssiItemCount">— items</span><span class="dot"></span>
          <span id="rssiRespCount">— responses</span><span class="dot"></span>
          <span id="rssiComputedAt">Not yet scored</span><span class="dot"></span>
          <span>By <strong style="color:var(--text); font-weight:500;"><?= htmlspecialchars($rssi_user_full) ?></strong></span>
        </div>
      </div>
    </div>

    <!-- Hero score -->
    <section class="card hero">
      <div class="ring-wrap">
        <svg viewBox="0 0 120 120">
          <circle class="ring-bg" cx="60" cy="60" r="52" fill="none" stroke-width="10"/>
          <circle class="ring-fg" id="rssiRingFg" cx="60" cy="60" r="52" fill="none" stroke-width="10"
                  stroke-dasharray="326.7" stroke-dashoffset="326.7"/>
        </svg>
        <div class="ring-center">
          <div>
            <div class="score" id="rssiScore">—</div>
            <div class="out-of">out of 100</div>
            <div class="badge" id="rssiBadge">Pending</div>
          </div>
        </div>
      </div>
      <div class="hero-copy">
        <!-- Print-only "Executive summary" eyebrow above the hero h2. -->
        <div class="rssi-print-eyebrow" aria-hidden="true">Executive summary</div>
        <h2 id="rssiHeroH2">Score not computed yet.</h2>
        <p id="rssiHeroP">Once a Strength Index analysis is saved to this report, the headline read will appear here in plain language.</p>

        <!-- v2 three-lens triplet (Spec §3.2). Ring shows the headline
             (Respondent-Centered, Spec §3.4); all three lens scores
             surface as chips below. Per-chip ⓘ icons + shared "?"
             affordance share the same popover pattern as the standalone
             dashboard so the report reads consistently. -->
        <div class="lens-triplet" id="rssiLensTriplet" role="group" aria-label="Three RSSI lens scores">
          <div class="lens-chip" data-lens="psychometric_core">
            <span class="lens-chip-label">Psychometric Core
              <button type="button" class="lens-info-icon" data-lens-info="psychometric_core" aria-label="About Psychometric Core" aria-expanded="false">ⓘ</button>
            </span>
            <span class="lens-chip-score" id="rssiLens_psychometric_core">—</span>
          </div>
          <div class="lens-chip lens-chip-headline" data-lens="respondent_centered">
            <span class="lens-chip-label">Respondent-Centered <span class="lens-chip-badge">Headline</span>
              <button type="button" class="lens-info-icon" data-lens-info="respondent_centered" aria-label="About Respondent-Centered" aria-expanded="false">ⓘ</button>
            </span>
            <span class="lens-chip-score" id="rssiLens_respondent_centered">—</span>
          </div>
          <div class="lens-chip" data-lens="validity_forward">
            <span class="lens-chip-label">Validity-Forward <span class="lens-cap-pill" id="rssiLensCapPill" hidden>Limited evidence</span>
              <button type="button" class="lens-info-icon" data-lens-info="validity_forward" aria-label="About Validity-Forward" aria-expanded="false">ⓘ</button>
            </span>
            <span class="lens-chip-score" id="rssiLens_validity_forward">—</span>
          </div>
          <button type="button" class="lens-help" id="rssiLensHelp"
                  aria-label="About the three RSSI lenses"
                  aria-expanded="false" aria-controls="rssiLensHelpPopover">?</button>
          <div class="lens-help-popover" id="rssiLensHelpPopover" role="dialog" aria-label="About the three RSSI lenses" hidden>
            <p><strong>Three lenses, one set of sub-scores.</strong> Each lens applies a different weight vector to the same eight domain scores (Spec §3.2):</p>
            <ul>
              <li><strong>Psychometric Core</strong> — favors Reliability, Validity, and Factor Readiness. Reads "how statistically sound is this instrument?"</li>
              <li><strong>Respondent-Centered (headline)</strong> — favors Item / Prompt Quality, Bias &amp; Clarity, and Response Scale Review. Reads "how well does this survey work for the people taking it?"</li>
              <li><strong>Validity-Forward</strong> — favors Validity, Construct Alignment, and Bias &amp; Clarity. Reads "is there evidence this instrument measures what it claims to?"</li>
            </ul>
            <p>When the three lenses disagree by more than 10 points, an interpretation appears at the top of <em>What Do These Numbers Mean?</em> explaining why.</p>
          </div>
          <div class="lens-info-popover" id="rssiLensInfoPopover" role="dialog" hidden></div>
        </div>

        <div class="hero-meta">
          <span class="item"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="5.5"/><path d="M5 7.5 6.5 9 9.5 5.5" stroke-linecap="round" stroke-linejoin="round"/></svg> Confidence <strong id="rssiConfidence">—</strong></span>
          <span class="item"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 11V3h10v8M2 11h10M5 11v2h4v-2" stroke-linejoin="round"/></svg> <span id="rssiHeroMetaItems">— items · — scales</span></span>
          <span class="item"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 11V3m0 0 3 3M3 3l-3 3" transform="translate(4 0)" stroke-linecap="round" stroke-linejoin="round"/></svg> <span id="rssiHeroMetaSource">Live data</span></span>
        </div>
      </div>

      <div class="tier">
        <div class="tier-track">
          <div class="tier-marker" id="rssiTierMarker" style="left: 0%;"></div>
        </div>
        <div class="tier-labels" id="rssiTierLabels">
          <span data-band="weak">Weak</span>
          <span data-band="fair">Fair</span>
          <span data-band="good">Good</span>
          <span data-band="strong">Strong</span>
          <span data-band="excellent">Excellent</span>
        </div>
      </div>
    </section>

    <!-- Dimensions -->
    <div class="section-head">
      <div>
        <h3>Diagnostic dimensions</h3>
        <div class="section-sub">The score combines eight domains across instrument design and respondent experience. Click any card to drill into the analysis.</div>
      </div>
    </div>

    <div class="dim-grid" id="rssiDimGrid">
      <!-- Cards injected by rssi.js -->
    </div>

    <!-- ────────────────────────────────────────────────────────────
         "What Do These Numbers Mean?" explain panel.

         Same structure as the standalone dashboard so the report
         reads consistently. rssi.js writes:
           - overall + 8 domain row bands + interpretation copy
           - 3 lens row band pills
           - disagreement readout (created/destroyed by JS based on
             the engine's null contract; #20 fix lands here too)
         ──────────────────────────────────────────────────────────── -->
    <section class="card rssi-hero-side" aria-labelledby="explainTitle" style="margin-bottom: 22px;">
      <h3 class="explain-title" id="explainTitle">What Do These Numbers Mean?</h3>
      <div class="explain-scroll">
        <div class="explain-row" data-explain="overall">
          <div class="explain-head">
            <span class="explain-label">Overall Strength Score</span>
            <span class="explain-band" id="explainBand_overall">—</span>
          </div>
          <p class="explain-text" id="explain_overall">A plain-language read of the overall score will appear here once the survey is scored.</p>
        </div>

        <!-- Disagreement readout slot. JS owns visibility — populated
             only when the engine returns a non-null sentence
             (Spec §3.5; KNOWN_ISSUES #20 fix supplies the coverage
             caveat when domains were skipped). -->
        <div id="rssiDisagreementSlot"></div>

        <!-- Three lens explanation rows. Static body copy per Spec §3.2
             weight vectors; band pill populates with bandFor() against
             each lens score. -->
        <div class="explain-row explain-lens" data-explain="lens_psychometric_core">
          <div class="explain-head"><span class="explain-label">Psychometric Core lens</span><span class="explain-band" id="explainBand_lens_psychometric_core">—</span></div>
          <p class="explain-text">Weights reliability, validity, factor structure, and construct alignment most, with the respondent-side domains contributing less. Captures how statistically sound the instrument is. A high score means scales hold together, constructs separate cleanly, and the data is factorable.</p>
        </div>
        <div class="explain-row explain-lens" data-explain="lens_respondent_centered">
          <div class="explain-head"><span class="explain-label">Respondent-Centered lens <span class="lens-chip-badge">Headline</span></span><span class="explain-band" id="explainBand_lens_respondent_centered">—</span></div>
          <p class="explain-text">Weights item quality, bias and clarity, and response design most, with the statistical domains contributing less. Captures how well the survey works for the people taking it. A high score means items read clearly, response scales are well-designed, and respondents engage seriously.</p>
        </div>
        <div class="explain-row explain-lens" data-explain="lens_validity_forward">
          <div class="explain-head"><span class="explain-label">Validity-Forward lens <span class="lens-cap-pill" id="explainCapPill_validity_forward" hidden>Limited evidence</span></span><span class="explain-band" id="explainBand_lens_validity_forward">—</span></div>
          <p class="explain-text">Treats validity as the most important property, weighting validity, construct alignment, and bias and clarity most. Captures whether there is evidence the survey measures what it claims. A high score means convergent and discriminant validity hold, scales align with their constructs, and items are unbiased.</p>
        </div>

        <!-- Eight canonical domain rows, Spec §2 order. JS populates
             band + per-band interpretation copy. -->
        <div class="explain-row" data-explain="reliability">
          <div class="explain-head"><span class="explain-label">Reliability</span><span class="explain-band" id="explainBand_reliability">—</span></div>
          <p class="explain-text" id="explain_reliability">—</p>
        </div>
        <div class="explain-row" data-explain="validity">
          <div class="explain-head"><span class="explain-label">Validity</span><span class="explain-band" id="explainBand_validity">—</span></div>
          <p class="explain-text" id="explain_validity">—</p>
        </div>
        <div class="explain-row" data-explain="construct_alignment">
          <div class="explain-head"><span class="explain-label">Construct Alignment</span><span class="explain-band" id="explainBand_construct_alignment">—</span></div>
          <p class="explain-text" id="explain_construct_alignment">—</p>
        </div>
        <div class="explain-row" data-explain="item_prompt_quality">
          <div class="explain-head"><span class="explain-label">Item / Prompt Quality</span><span class="explain-band" id="explainBand_item_prompt_quality">—</span></div>
          <p class="explain-text" id="explain_item_prompt_quality">—</p>
        </div>
        <div class="explain-row" data-explain="bias_clarity">
          <div class="explain-head"><span class="explain-label">Bias &amp; Clarity Review</span><span class="explain-band" id="explainBand_bias_clarity">—</span></div>
          <p class="explain-text" id="explain_bias_clarity">—</p>
        </div>
        <div class="explain-row" data-explain="scale_structure">
          <div class="explain-head"><span class="explain-label">Scale Structure</span><span class="explain-band" id="explainBand_scale_structure">—</span></div>
          <p class="explain-text" id="explain_scale_structure">—</p>
        </div>
        <div class="explain-row" data-explain="factor_readiness">
          <div class="explain-head"><span class="explain-label">Factor Readiness</span><span class="explain-band" id="explainBand_factor_readiness">—</span></div>
          <p class="explain-text" id="explain_factor_readiness">—</p>
        </div>
        <div class="explain-row" data-explain="response_scale_review">
          <div class="explain-head"><span class="explain-label">Response Scale Review</span><span class="explain-band" id="explainBand_response_scale_review">—</span></div>
          <p class="explain-text" id="explain_response_scale_review">—</p>
        </div>
      </div>
    </section>

    <!-- Issues panel. Same dual-heading treatment as the standalone:
         "Top issues to fix" on screen, "Recommended actions" in print.
         Flow order on print: dim grid → recommended actions → methods. -->
    <div class="section-head rssi-issues-block">
      <div>
        <h3 class="rssi-issues-screen-head">Top issues to fix</h3>
        <h3 class="rssi-issues-print-head">Recommended actions</h3>
        <div class="section-sub rssi-issues-screen-sub">Highest-impact items first. Each fix improves the overall score.</div>
        <div class="section-sub rssi-issues-print-sub">Highest-impact items first. Each fix improves the overall score.</div>
      </div>
    </div>

    <a id="rssi-issues" style="display:block;height:0;"></a>
    <div class="card issues rssi-issues-block" id="rssiIssues">
      <!-- Issues injected by rssi.js -->
    </div>

    <!-- §8.1 methods paragraph card. Engine-composed research-methods
         prose (KNOWN_ISSUES #21 fix). Hidden when null. Same DOM ids as
         the standalone dashboard so rssi.js paints both surfaces
         identically. Final section on print (research appendix). -->
    <section class="card rssi-methods-paragraph" id="rssiMethodsParagraph" hidden aria-hidden="true">
      <div class="methods-head">
        <h3>Methods <span class="rssi-print-only-inline">(appendix)</span></h3>
        <button type="button" class="methods-copy-btn" id="rssiMethodsCopyBtn" aria-label="Copy methods paragraph to clipboard">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="5" y="5" width="9" height="9" rx="1.5"/><path d="M3 11V3.5A1.5 1.5 0 0 1 4.5 2H11" stroke-linecap="round"/></svg>
          Copy
        </button>
      </div>
      <p class="methods-body" id="rssiMethodsBody">—</p>
    </section>

    <!-- Print-only footer -->
    <div class="rssi-print-footer">
      Scored with ReliCheck Strength Survey Index v2.0 · Generated <?= date('M j, Y \a\t g:i a') ?> · <?= htmlspecialchars($rssi_proj_title) ?>
    </div>

  </main>

  <!-- =================== RIGHT RAIL =================== -->
  <aside class="rail">
    <div class="block">
      <h4>What this means</h4>
      <p class="lead" id="rssiWhatThisMeans" style="margin: 8px 0 0;">
        Once you run and save a Strength Index analysis for this survey, a plain-language read of the result will appear here.
      </p>
    </div>

    <div class="block">
      <h4>Improvement priorities</h4>
      <div id="rssiPriorities" style="margin-top: 6px;">
        <p class="lead" style="color:var(--text-3);">Priorities appear after the first analysis is saved.</p>
      </div>
    </div>

    <div class="block cta-block">
      <h4>Export this report</h4>
      <p class="lead">Save your Strength Survey Index report as a polished PDF — ready to email, attach to a proposal, or hand to a client.</p>
      <a class="cta-btn" href="javascript:window.print()">
        <span>Print / Save as PDF</span>
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 8.5V11a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V8.5M7 2v6.5m0 0L4.5 6M7 8.5 9.5 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
      <a class="cta-link" href="/rssi.php" style="text-decoration:none;">
        <span>Or score another survey</span>
        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m4 2 4 4-4 4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </a>
    </div>
  </aside>

</div>
</div>
