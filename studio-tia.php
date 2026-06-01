<?php
// studio-tia.php — TIA Studio landing page (v4 style).
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

<section class="sl-hero">
  <img src="/TIA%20Studio.png" alt="TIA Studio" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">Every item</span><br>tells a story.</h1>
  <p class="sl-body rv rv-d2">TIA Studio analyzes the quality of your test items. Difficulty, discrimination, cognitive demand, and whether your instrument is actually measuring what you think it measures.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/tia-wizard.php?step=1" class="sl-btn-a">Open TIA Studio</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Item analysis</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">See exactly how</span><br>each item performed.</h2>
    <p class="sl-feat-body rv rv-d2">Difficulty, discrimination, and point-biserial correlations reveal which items separated students who knew the material from those who did not, and which ones need revision.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-table"><table><thead><tr><td>Item</td><td>Difficulty</td><td>Discrimination</td><td>Status</td></tr></thead><tbody>
        <tr><td>Q1</td><td class="dim">0.72</td><td class="dim">0.41</td><td><span class="sv-flag ok">Good</span></td></tr>
        <tr><td>Q4</td><td class="dim">0.38</td><td class="dim">0.58</td><td><span class="sv-flag ok">Good</span></td></tr>
        <tr><td>Q7</td><td class="dim">0.91</td><td class="dim">0.08</td><td><span class="sv-flag warn">Too easy</span></td></tr>
        <tr><td>Q12</td><td class="dim">0.22</td><td class="dim">-0.04</td><td><span class="sv-flag crit">Review</span></td></tr>
        </tbody></table></div></div>
</section>
</div>

<div style="background:#f5f5f7">
<section class="sl-feat-sect flip">
  <div>
    <div class="sl-feat-tag rv">Cognitive demand</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">What is your test</span><br>actually asking?</h2>
    <p class="sl-feat-body rv rv-d2">Map items to Bloom's Taxonomy to understand whether your test measures recall, application, or analysis. See if the cognitive demand matches your learning goals.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-rows">
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Remember / Recall</span><span class="sv-row-score">8 items</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:40%"></div></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Understand / Apply</span><span class="sv-row-score">9 items</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:45%"></div></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Analyze / Evaluate</span><span class="sv-row-score">3 items</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:15%"></div></div></div>
      </div></div>
</section>
</div>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Test reliability</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">How much do you</span><br>trust the score?</h2>
    <p class="sl-feat-body rv rv-d2">Alpha estimates, item-level flags, and scale-level diagnostics across the full instrument. Know whether your test score is stable enough to make decisions from.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-result">
        <div class="sv-result-tag">Coefficient Alpha</div>
        <div class="sv-result-stat">0.84</div>
        <div class="sv-result-sub">Based on 20 items, 184 students</div>
        <div class="sv-result-note">Removing Q12 raises alpha to 0.87. Removing Q7 has minimal effect.</div>
      </div></div>
</section>
</div>

<section class="sl-cta">
  <h2 class="sl-cta-h rv">Good tests come from<br><em>good items.</em></h2>
  <div class="rv rv-d1"><a href="/tia-wizard.php?step=1" class="sl-cta-btn">Open TIA Studio</a></div>
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
