<?php
// rssi.php — RSSI landing page (v4 style, matches studio-mm.php).
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/rssi.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['rssi'];

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$landing_title         = 'RSSI — ReliCheck';
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
  <img src="/rssi-icon.png" alt="RSSI" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">How strong is</span><br>your evidence?</h1>
  <p class="sl-body rv rv-d2">RSSI evaluates whether your survey responses are reliable enough to interpret, defend, and act on. Internal consistency, item performance, response quality, and score interpretability in one index.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/rssi-app.php" class="sl-btn-a">Run RSSI</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<!-- FEATURES -->
<section class="sl-features">
  <div class="sl-features-inner">
    <div class="sl-feature-card rv">
      <span class="sl-fc-icon">🔬</span>
      <h3 class="sl-fc-h">Internal Consistency</h3>
      <p class="sl-fc-body">Cronbach's alpha and item-total correlations show how well your items hold together as a scale.</p>
    </div>
    <div class="sl-feature-card rv rv-d1">
      <span class="sl-fc-icon">⚡</span>
      <h3 class="sl-fc-h">Item Performance</h3>
      <p class="sl-fc-body">Flag items that are too easy, too hard, or dragging your scale reliability down before you report results.</p>
    </div>
    <div class="sl-feature-card rv rv-d2">
      <span class="sl-fc-icon">📐</span>
      <h3 class="sl-fc-h">Score Interpretability</h3>
      <p class="sl-fc-body">Understand what your scores actually mean and whether they are strong enough to act on and defend.</p>
    </div>
  </div>
</section>

<!-- DARK CTA -->
<section class="sl-cta">
  <h2 class="sl-cta-h rv">Your responses are in.<br><em>Find out what they are worth.</em></h2>
  <div class="rv rv-d1">
    <a href="/rssi-app.php" class="sl-cta-btn">Run RSSI</a>
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
$landing_tagline = 'Know whether your evidence holds up.';
include __DIR__ . '/_landing_foot.php';
