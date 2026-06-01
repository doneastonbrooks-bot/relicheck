<?php
// studio-tia.php — TIA Studio landing page (v4 style, matches studio-mm.php).
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/studio-tia.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['tia'];

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$landing_title         = 'TIA Studio — ReliCheck';
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
  <img src="/TIA%20Studio.png" alt="TIA Studio" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">Every item</span><br>tells a story.</h1>
  <p class="sl-body rv rv-d2">TIA Studio analyzes the quality of your test items. Difficulty, discrimination, cognitive demand, and whether your instrument is actually measuring what you think it is.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/tia-wizard.php?step=1" class="sl-btn-a">Open TIA Studio</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<!-- FEATURES -->
<section class="sl-features">
  <div class="sl-features-inner">
    <div class="sl-feature-card rv">
      <span class="sl-fc-icon">📈</span>
      <h3 class="sl-fc-h">Item Analysis</h3>
      <p class="sl-fc-body">Difficulty, discrimination, and point-biserial correlations reveal which items performed well and which need revision.</p>
    </div>
    <div class="sl-feature-card rv rv-d1">
      <span class="sl-fc-icon">🧠</span>
      <h3 class="sl-fc-h">Cognitive Demand</h3>
      <p class="sl-fc-body">Map items to Bloom's Taxonomy to understand what your test is actually asking students to do.</p>
    </div>
    <div class="sl-feature-card rv rv-d2">
      <span class="sl-fc-icon">📋</span>
      <h3 class="sl-fc-h">Test Reliability</h3>
      <p class="sl-fc-body">Alpha estimates, item-level flags, and scale-level diagnostics across the full instrument.</p>
    </div>
  </div>
</section>

<!-- DARK CTA -->
<section class="sl-cta">
  <h2 class="sl-cta-h rv">Good tests come from<br><em>good items.</em></h2>
  <div class="rv rv-d1">
    <a href="/tia-wizard.php?step=1" class="sl-cta-btn">Open TIA Studio</a>
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
$landing_tagline = 'Test quality starts with item quality.';
include __DIR__ . '/_landing_foot.php';
