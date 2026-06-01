<?php
// studio-360.php — 360 Studio landing page (v4 style).
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

<section class="sl-hero">
  <img src="/360%20Studio.png" alt="360 Studio" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">See yourself</span><br>as others do.</h1>
  <p class="sl-body rv rv-d2">360 Studio surfaces the gap between how leaders rate themselves and how their managers, peers, and direct reports see them, across every competency, in one display.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/360-wizard.php?step=1" class="sl-btn-a">Open 360 Studio</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Perception gaps</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Where self and others</span><br>diverge.</h2>
    <p class="sl-feat-body rv rv-d2">See exactly where self-ratings differ from each rater group and by how much. Blind spots show up as high self / low others. Hidden strengths show up in reverse.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-compare">
        <div class="sv-cmp-row">
          <div class="sv-cmp-label">Communication<span>Self 4.8 / Others 3.2</span></div>
          <div class="sv-cmp-bars"><div class="sv-cmp-bar others" style="width:64%"></div><div class="sv-cmp-bar self" style="width:96%"></div></div>
        </div>
        <div class="sv-cmp-row">
          <div class="sv-cmp-label">Strategic thinking<span>Self 3.9 / Others 4.4</span></div>
          <div class="sv-cmp-bars"><div class="sv-cmp-bar others" style="width:88%"></div><div class="sv-cmp-bar self" style="width:78%"></div></div>
        </div>
        <div class="sv-cmp-row">
          <div class="sv-cmp-label">Team development<span>Self 4.2 / Others 4.1</span></div>
          <div class="sv-cmp-bars"><div class="sv-cmp-bar others" style="width:82%"></div><div class="sv-cmp-bar self" style="width:84%"></div></div>
        </div>
        <div class="sv-cmp-legend"><span><span class="sv-cmp-dot" style="background:var(--accent)"></span>Others</span><span><span class="sv-cmp-dot" style="background:color-mix(in srgb,var(--accent) 35%,white)"></span>Self</span></div>
      </div></div>
</section>
</div>

<div style="background:#f5f5f7">
<section class="sl-feat-sect flip">
  <div>
    <div class="sl-feat-tag rv">Multi-rater views</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Manager. Peer.</span><br>Direct report.</h2>
    <p class="sl-feat-body rv rv-d2">Compare how each rater group sees the same competencies. Manager feedback often diverges from peer feedback in predictable ways. Seeing them together reveals the full picture.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-rows">
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Manager (n=1)</span><span class="sv-row-score">3.8</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:76%"></div></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Peers (n=5)</span><span class="sv-row-score">4.1</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:82%"></div></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Direct reports (n=6)</span><span class="sv-row-score">3.4</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:68%"></div></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Self</span><span class="sv-row-score">4.8</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:96%"></div></div></div>
      </div></div>
</section>
</div>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Consistent patterns</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">The feedback that</span><br>keeps showing up.</h2>
    <p class="sl-feat-body rv rv-d2">When the same theme appears across all rater groups, it cannot be explained away. 360 Studio surfaces those patterns, so development conversations are grounded in evidence.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-list">
        <div class="sv-item"><span class="sv-dot ok">✓</span>Strategic thinking rated high by all groups</div>
        <div class="sv-item"><span class="sv-dot mid">!</span>Communication gap: self 4.8, others avg 3.2</div>
        <div class="sv-item"><span class="sv-dot ok">✓</span>Integrity and follow-through: consistent across raters</div>
        <div class="sv-item"><span class="sv-dot mid">!</span>Listening flagged by peers and direct reports</div>
      </div></div>
</section>
</div>

<section class="sl-cta">
  <h2 class="sl-cta-h rv">The gap between self and others<br><em>is where growth begins.</em></h2>
  <div class="rv rv-d1"><a href="/360-wizard.php?step=1" class="sl-cta-btn">Open 360 Studio</a></div>
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
