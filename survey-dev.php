<?php
// survey-dev.php — Survey Development System landing page (v4 style).
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

<section class="sl-hero">
  <img src="<?= htmlspecialchars($landing_logo) ?>" alt="<?= htmlspecialchars($landing_logo_name) ?>" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">Survey design,</span><br>done right.</h1>
  <p class="sl-body rv rv-d2">The Survey Intelligence Readiness Index evaluates your survey before launch. Validity, reliability, and administration reviewed and scored so you know exactly where your instrument stands.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/develop.php?db=1&start=choose" class="sl-btn-a">Open SIRI</a>
    <a href="/develop.php?db=1&start=choose" class="sl-btn-b">Bring in existing data &#8594;</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Design strength</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Know what your</span><br>survey is doing.</h2>
    <p class="sl-feat-body rv rv-d2">Check construct validity, item clarity, response scales, and domain coverage before a single response arrives. SIRI reviews each lens and flags what needs work.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-rows">
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Validity Readiness</span><span class="sv-row-score">78</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:78%"></div></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Reliability Readiness</span><span class="sv-row-score">65</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:65%"></div></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Administration Readiness</span><span class="sv-row-score">88</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:88%"></div></div></div>
      </div></div>
</section>
</div>

<div style="background:#f5f5f7">
<section class="sl-feat-sect flip">
  <div>
    <div class="sl-feat-tag rv">100-point index</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">One score.</span><br>Every lens.</h2>
    <p class="sl-feat-body rv rv-d2">SIRI's 100-point LaunchCheck index breaks down exactly where your survey is ready and where it needs attention, with specific fixes to act on before you collect a single response.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-score-hero">
        <div class="sv-score-num">82</div><div class="sv-score-max">/ 100</div>
        <div class="sv-score-band">Ready to strengthen</div>
        <div class="sv-score-note">3 items need attention before launch</div>
      </div></div>
</section>
</div>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Pre-launch report</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Launch with</span><br>evidence.</h2>
    <p class="sl-feat-body rv rv-d2">Generate a readiness report you can show to stakeholders before you collect data. Every score is backed by specific lens findings. Every flag has a suggested fix.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-list">
        <div class="sv-item"><span class="sv-dot ok">✓</span>Constructs defined and grounded</div>
        <div class="sv-item"><span class="sv-dot ok">✓</span>Response scales validated</div>
        <div class="sv-item"><span class="sv-dot mid">!</span>Reliability plan incomplete</div>
        <div class="sv-item"><span class="sv-dot ok">✓</span>Administration protocol set</div>
        <div class="sv-item"><span class="sv-dot ok">✓</span>Pilot testing planned</div>
      </div></div>
</section>
</div>

<section class="sl-cta">
  <h2 class="sl-cta-h rv">Your survey is your instrument.<br><em>Make it hold up.</em></h2>
  <div class="rv rv-d1"><a href="/develop.php?db=1&start=choose" class="sl-cta-btn">Open SIRI</a></div>
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
