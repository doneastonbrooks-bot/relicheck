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
      <a class="nav-item" data-nav="reliability" href="#dim-reliability">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5 2.5 3.8v3.7c0 3.4 2.4 6.2 5.5 7 3.1-.8 5.5-3.6 5.5-7V3.8L8 1.5Z" stroke-linejoin="round"/></svg></span>
        Reliability
      </a>
      <a class="nav-item" data-nav="validity" href="#dim-validity">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="5.5"/><path d="M6 8.5 7.5 10 10 6.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        Validity
      </a>
      <a class="nav-item" data-nav="item-quality" href="#dim-item_quality">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="3" rx="1"/><rect x="2" y="7.5" width="12" height="2" rx="0.7"/><rect x="2" y="11" width="8" height="2" rx="0.7"/></svg></span>
        Item Quality
      </a>
      <a class="nav-item" data-nav="scale-structure" href="#dim-scale_strength">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 13V3M3 13h10M5.5 11V8M8 11V5M10.5 11V9" stroke-linecap="round"/></svg></span>
        Scale Structure
      </a>
      <a class="nav-item" data-nav="factor" href="#dim-factor_structure">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8a5 5 0 1 0 10 0A5 5 0 0 0 3 8Z"/><path d="M8 3v10M3 8h10" stroke-linecap="round"/></svg></span>
        Factor Readiness
      </a>
      <a class="nav-item" data-nav="response-quality" href="#dim-response_quality">
        <span class="ico"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2 1.5 13.5h13L8 2Z" stroke-linejoin="round"/><path d="M8 7v3M8 11.8v.4" stroke-linecap="round"/></svg></span>
        Response Quality
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
        <h2 id="rssiHeroH2">Score not computed yet.</h2>
        <p id="rssiHeroP">Once a Strength Index analysis is saved to this report, the headline read will appear here in plain language.</p>
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
        <div class="section-sub">Six factors contribute to the overall score. Click any card to drill into the analysis.</div>
      </div>
    </div>

    <div class="dim-grid" id="rssiDimGrid">
      <!-- Cards injected by rssi.js -->
    </div>

    <!-- Top issues -->
    <div class="section-head">
      <div>
        <h3>Top issues to fix</h3>
        <div class="section-sub">Highest-impact items first. Each fix improves the overall score.</div>
      </div>
    </div>

    <a id="rssi-issues" style="display:block;height:0;"></a>
    <div class="card issues" id="rssiIssues">
      <!-- Issues injected by rssi.js -->
    </div>

    <!-- Print-only footer -->
    <div class="rssi-print-footer">
      ReliCheck Strength Survey Index · Generated <?= date('M j, Y \a\t g:i a') ?> · <?= htmlspecialchars($rssi_proj_title) ?>
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
      <p class="lead">Save your Strength Survey Index report as a polished, one-page PDF — ready to email, attach to a proposal, or hand to a client.</p>
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
