<?php
// survey-dev.php — Survey Development System LANDING page.
// RSSI-style entry surface (no left rail) that sits in front of the
// develop.php builder workspace. The three primary actions deep-link into
// develop.php with a ?start= hint so the user lands on the right entry.
//
// User flow:
//   1. /app-2026v4.php  hub
//   2. /survey-dev.php   ← this file (hero + 3 actions + flow preview)
//   3. /develop.php?db=1&start=...  the workspace (with the stepper rail)

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  header('Location: /login.html?return=' . urlencode('/survey-dev.php'));
  exit;
}
$user = current_user();
if (!$user) {
  $_SESSION = []; session_destroy();
  header('Location: /login.html');
  exit;
}

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['survey'];

$user_full  = $user['name'] ?? $user['email'] ?? 'You';
$initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$landing_title         = 'Survey Development System — ReliCheck';
$landing_accent        = $studio['accent'];
$landing_accent_deep   = $studio['accent_deep'] ?? $studio['accent'];
$landing_accent_soft   = $studio['accent_soft'];
$landing_logo          = $studio['mark'];
$landing_logo_name     = $studio['name'];
$landing_pill_label    = $studio['status_label'];
$landing_show_back     = true;
$landing_user_initials = $initials;
$landing_user_full     = $user_full;

include __DIR__ . '/_landing_head.php';
?>

<!-- ===== Hero ===== -->
<section class="lp-hero">
  <h1>
    Build a survey
    <span class="accent">strong enough to trust.</span>
  </h1>
  <p class="lede">
    ReliCheck guides your survey from first draft to launch readiness, response collection, and evidence-strength reporting.
  </p>
</section>

<!-- ===== Three primary actions ===== -->
<div class="lp-cta-row">
  <a class="lp-cta-tile primary" href="/develop.php?db=1&amp;start=scratch">
    <span class="lp-cta-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
    </span>
    <span class="lp-cta-text">
      <div class="lp-cta-title">Start New Survey</div>
      <div class="lp-cta-sub">A blank workspace, full control</div>
    </span>
  </a>
  <a class="lp-cta-tile" href="/develop.php?db=1&amp;start=import">
    <span class="lp-cta-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
    </span>
    <span class="lp-cta-text">
      <div class="lp-cta-title">Bring In Existing Survey</div>
      <div class="lp-cta-sub">Paste or import what you have</div>
    </span>
  </a>
  <a class="lp-cta-tile" href="/develop.php?db=1&amp;start=existing">
    <span class="lp-cta-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
    </span>
    <span class="lp-cta-text">
      <div class="lp-cta-title">Open Saved Project</div>
      <div class="lp-cta-sub">Resume where you left off</div>
    </span>
  </a>
</div>

<div style="text-align:center;margin:-34px 0 56px">
  <a href="#flow" style="font-size:13.5px;font-weight:600;color:var(--accent);text-decoration:none">View sample workflow ↓</a>
</div>

<!-- ===== Flow preview ===== -->
<section class="lp-section" id="flow" style="scroll-margin-top:90px">
  <div class="lp-eyebrow-c">How it works</div>
  <div class="lp-flow-card">
    <div class="lp-flow">
      <div class="lp-flow-step">
        <div class="n">1</div>
        <h4>SDSI Build Check</h4>
        <p>Score the survey's development strength while you build it.</p>
      </div>
      <div class="lp-flow-step">
        <div class="n">2</div>
        <h4>Revise and Strengthen</h4>
        <p>Act on item-level guidance to tighten constructs and wording.</p>
      </div>
      <div class="lp-flow-step">
        <div class="n">3</div>
        <h4>SIRI Launch Check</h4>
        <p>Confirm the completed survey is ready before it goes live.</p>
      </div>
      <div class="lp-flow-step">
        <div class="n">4</div>
        <h4>Publish and Collect</h4>
        <p>Share a live link and gather responses securely.</p>
      </div>
      <div class="lp-flow-step">
        <div class="n">5</div>
        <h4>RSSI Evidence Strength</h4>
        <p>Judge whether the collected data is strong enough to trust.</p>
      </div>
    </div>
  </div>
</section>

<!-- ===== What this helps you do ===== -->
<section class="lp-section">
  <div class="lp-section-head" style="text-align:center;">
    <h2>What the Survey Development System helps you do</h2>
  </div>
  <div class="lp-features">
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></div>
      <h3>Build with structure</h3>
      <p>Define constructs and items in a workspace that checks your design as it grows, not after it is too late.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
      <h3>Launch with confidence</h3>
      <p>Resolve readiness blockers before publishing, so you collect responses on an instrument that holds up.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg></div>
      <h3>Report evidence you can defend</h3>
      <p>Turn collected responses into a clear strength report that says what you can claim, and what you should not.</p>
    </div>
  </div>
</section>

<?php
$landing_tagline = 'From first draft to evidence you can defend.';
include __DIR__ . '/_landing_foot.php';
