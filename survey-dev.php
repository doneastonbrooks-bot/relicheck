<?php
// survey-dev.php — Survey Development System landing page (v4 style, matches studio-mm.php).
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/survey-dev.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['survey'];

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

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
<link rel="stylesheet" href="/studio-landing.css">

<!-- HERO -->
<section class="sl-hero">
  <img src="/SIRI.png" alt="Survey Development System" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">Survey design,</span><br>done right.</h1>
  <p class="sl-body rv rv-d2">The Survey Intelligence Readiness Index evaluates your survey before launch. Validity, reliability, and administration reviewed and scored so you know exactly where your instrument stands.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/develop.php?db=1&start=scratch" class="sl-btn-a">Open SIRI</a>
    <a href="/develop.php?db=1&start=import" class="sl-btn-b">Import existing survey →</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<!-- FEATURES -->
<section class="sl-features">
  <div class="sl-features-inner">
    <div class="sl-feature-card rv">
      <span class="sl-fc-icon">🔍</span>
      <h3 class="sl-fc-h">Design Strength</h3>
      <p class="sl-fc-body">Check construct validity, item clarity, response scales, and domain coverage before a single response arrives.</p>
    </div>
    <div class="sl-feature-card rv rv-d1">
      <span class="sl-fc-icon">📊</span>
      <h3 class="sl-fc-h">100-Point Readiness Score</h3>
      <p class="sl-fc-body">SIRI's index breaks down exactly where your survey is ready and where it needs attention, with specific fixes to act on.</p>
    </div>
    <div class="sl-feature-card rv rv-d2">
      <span class="sl-fc-icon">🚀</span>
      <h3 class="sl-fc-h">Launch with Confidence</h3>
      <p class="sl-fc-body">Generate a readiness report you can show to stakeholders before collecting your first response.</p>
    </div>
  </div>
</section>

<!-- DARK CTA -->
<section class="sl-cta">
  <h2 class="sl-cta-h rv">Your survey is your instrument.<br><em>Build it to hold up.</em></h2>
  <div class="rv rv-d1">
    <a href="/develop.php?db=1&start=scratch" class="sl-cta-btn">Open SIRI</a>
  </div>
</section>



<script>
(function(){
  var obs = new IntersectionObserver(function(entries){
    entries.forEach(function(e){ if(e.isIntersecting){ e.target.classList.add('in'); obs.unobserve(e.target); }});
  }, { threshold: 0.12 });
  document.querySelectorAll('.rv').forEach(function(el){ obs.observe(el); });
})();
</script>

<?php
$landing_tagline = 'Survey design done right.';
include __DIR__ . '/_landing_foot.php';
