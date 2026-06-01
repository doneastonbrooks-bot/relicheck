<?php
// descriptive-analysis-studio.php — Descriptive Analysis Studio landing page (v4 style, matches studio-mm.php).
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/descriptive-analysis-studio.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['descriptive'];

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$landing_title         = 'Descriptive Analysis Studio — ReliCheck';
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
  <img src="/Descriptive%20Studio.png" alt="Descriptive Analysis Studio" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">Know what is</span><br>in your data.</h1>
  <p class="sl-body rv rv-d2">Descriptive Studio summarizes what is present before you test what is true. Frequencies, distributions, group summaries, and item rankings. The full picture before any inference.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/analysis-upload-wizard.php?studio=descriptive" class="sl-btn-a">Open Descriptive Studio</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<!-- FEATURES -->
<section class="sl-features">
  <div class="sl-features-inner">
    <div class="sl-feature-card rv">
      <span class="sl-fc-icon">📊</span>
      <h3 class="sl-fc-h">Frequencies</h3>
      <p class="sl-fc-body">See who responded and how, category by category, with counts and percentages for every variable.</p>
    </div>
    <div class="sl-feature-card rv rv-d1">
      <span class="sl-fc-icon">📉</span>
      <h3 class="sl-fc-h">Distributions</h3>
      <p class="sl-fc-body">Means, medians, ranges, and spread for every numeric variable so you understand the shape of your data.</p>
    </div>
    <div class="sl-feature-card rv rv-d2">
      <span class="sl-fc-icon">👥</span>
      <h3 class="sl-fc-h">Group Summaries</h3>
      <p class="sl-fc-body">Compare how different groups look on key variables before running a single statistical test.</p>
    </div>
  </div>
</section>

<!-- DARK CTA -->
<section class="sl-cta">
  <h2 class="sl-cta-h rv">Before you test anything,<br><em>know what you have.</em></h2>
  <div class="rv rv-d1">
    <a href="/analysis-upload-wizard.php?studio=descriptive" class="sl-cta-btn">Open Descriptive Studio</a>
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
$landing_tagline = 'Describe before you infer.';
include __DIR__ . '/_landing_foot.php';
