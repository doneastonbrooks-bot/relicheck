<?php
// rssi.php — RSSI landing page (v4 style).
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

<section class="sl-hero">
  <img src="/rssi-icon.png" alt="RSSI" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">How strong is</span><br>your evidence?</h1>
  <p class="sl-body rv rv-d2">RSSI evaluates whether your survey responses are reliable enough to interpret, defend, and act on. Internal consistency, item performance, response quality, and score interpretability in one index.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/rssi-app.php" class="sl-btn-a">Run RSSI</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Internal consistency</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Do your items</span><br>hold together?</h2>
    <p class="sl-feat-body rv rv-d2">Cronbach's alpha and item-total correlations show how well your scale items hang together. A low alpha means your items may be measuring different things, and your scores may be unreliable.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-result">
        <div class="sv-result-tag">Cronbach Alpha</div>
        <div class="sv-result-stat">0.87</div>
        <div class="sv-result-sub">12-item Belonging scale, n=412</div>
        <div class="sv-result-note">Removing item 9 raises alpha to 0.89. Item 9 has the lowest corrected item-total correlation (r = .18).</div>
      </div></div>
</section>
</div>

<div style="background:#f5f5f7">
<section class="sl-feat-sect flip">
  <div>
    <div class="sl-feat-tag rv">Item performance</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Which items are</span><br>pulling you down?</h2>
    <p class="sl-feat-body rv rv-d2">Flag items that are too easy, too hard, or reducing your scale reliability. Item-level diagnostics show you exactly which items to revise before your next data collection.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-table"><table><thead><tr><td>Item</td><td>Item-total r</td><td>Alpha if removed</td><td>Flag</td></tr></thead><tbody>
        <tr><td>Item 3</td><td class="dim">0.62</td><td class="dim">0.85</td><td><span class="sv-flag ok">Good</span></td></tr>
        <tr><td>Item 7</td><td class="dim">0.58</td><td class="dim">0.86</td><td><span class="sv-flag ok">Good</span></td></tr>
        <tr><td>Item 9</td><td class="dim">0.18</td><td class="dim">0.89</td><td><span class="sv-flag warn">Weak</span></td></tr>
        <tr><td>Item 11</td><td class="dim">0.71</td><td class="dim">0.84</td><td><span class="sv-flag ok">Good</span></td></tr>
        </tbody></table></div></div>
</section>
</div>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Score interpretability</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">What do your</span><br>scores mean?</h2>
    <p class="sl-feat-body rv rv-d2">Understand what your scores actually mean, what band they fall in, and whether they are strong enough to act on. RSSI tells you not just the number, but what you can responsibly say about it.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-score-hero">
        <div class="sv-score-num">87</div>
        <div class="sv-score-max">/ 100</div>
        <div class="sv-score-band">Confident evidence</div>
        <div class="sv-score-note">Scores in this range support reliable interpretation and reporting.</div>
      </div></div>
</section>
</div>

<section class="sl-cta">
  <h2 class="sl-cta-h rv">Your responses are in.<br><em>Find out what they are worth.</em></h2>
  <div class="rv rv-d1"><a href="/rssi-app.php" class="sl-cta-btn">Run RSSI</a></div>
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
