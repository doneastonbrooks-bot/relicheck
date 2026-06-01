<?php
// studio-360.php — 360 Studio landing page (v4 style, matches studio-mm.php).
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/studio-360.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['360'];

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$landing_title         = '360 Studio — ReliCheck';
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
  <img src="/360%20Studio.png" alt="360 Studio" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">See yourself</span><br>as others do.</h1>
  <p class="sl-body rv rv-d2">360 Studio surfaces the gap between how leaders rate themselves and how their managers, peers, and direct reports see them, across every competency, in one display.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/360-wizard.php?step=1" class="sl-btn-a">Open 360 Studio</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<!-- FEATURES -->
<section class="sl-features">
  <div class="sl-features-inner">
    <div class="sl-feature-card rv">
      <span class="sl-fc-icon">👁</span>
      <h3 class="sl-fc-h">Perception Gaps</h3>
      <p class="sl-fc-body">See exactly where self-ratings diverge from each rater group and whether the gap is a blind spot or a hidden strength.</p>
    </div>
    <div class="sl-feature-card rv rv-d1">
      <span class="sl-fc-icon">🔄</span>
      <h3 class="sl-fc-h">Multi-Rater Views</h3>
      <p class="sl-fc-body">Compare across manager, peer, and direct-report perspectives in a single unified display.</p>
    </div>
    <div class="sl-feature-card rv rv-d2">
      <span class="sl-fc-icon">🎯</span>
      <h3 class="sl-fc-h">Consistent Patterns</h3>
      <p class="sl-fc-body">Identify the themes that show up across all raters. The feedback that appears everywhere is the feedback that matters.</p>
    </div>
  </div>
</section>

<!-- DARK CTA -->
<section class="sl-cta">
  <h2 class="sl-cta-h rv">The gap between self and others<br><em>is where growth begins.</em></h2>
  <div class="rv rv-d1">
    <a href="/360-wizard.php?step=1" class="sl-cta-btn">Open 360 Studio</a>
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
$landing_tagline = 'See the full picture.';
include __DIR__ . '/_landing_foot.php';
