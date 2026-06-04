<?php
// studio-is.php — Inferential Studio landing page (v4 style).
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/studio-is.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['is'];
$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$landing_title         = 'Inferential Studio — ReliCheck';
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
  <img src="/Inferential%20Studio.png" alt="Inferential Studio" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">Test the claim,</span><br>not the hunch.</h1>
  <p class="sl-body rv rv-d2">Inferential statistics let you say something beyond the sample in front of you. Inferential Studio guides you from research question to hypothesis test to effect size, so every claim you make is grounded in evidence you can explain.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/inferential-statistics-workspace.php?v4=1" class="sl-btn-a">Open Inferential Studio</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Hypothesis testing</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Is the difference</span><br>real or noise?</h2>
    <p class="sl-feat-body rv rv-d2">t-tests, chi-square, ANOVA, and Mann-Whitney for comparing groups across continuous and categorical outcomes. Know whether what you observed is likely to hold beyond your sample.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-result">
    <div class="sv-result-tag">Independent samples t-test</div>
    <div class="sv-result-stat">p = .003</div>
    <div class="sv-result-sub">t(78) = 3.21 &middot; d = 0.71</div>
    <div class="sv-result-note">The difference between groups is statistically significant with a large effect.</div>
  </div></div>
</section>
</div>

<div style="background:#f5f5f7">
<section class="sl-feat-sect flip">
  <div>
    <div class="sl-feat-tag rv">Relationships &amp; correlations</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">What moves</span><br>together?</h2>
    <p class="sl-feat-body rv rv-d2">Pearson and Spearman correlations, regression coefficients, and scatter relationships between your key variables. Understand which constructs are linked and how strongly.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-table"><table><thead><tr><td>Variable A</td><td>Variable B</td><td>r</td><td>p</td></tr></thead><tbody>
    <tr><td>Manager Support</td><td class="dim">Engagement</td><td class="dim">0.61</td><td class="dim">&lt;.001</td></tr>
    <tr><td>Clarity</td><td class="dim">Satisfaction</td><td class="dim">0.44</td><td class="dim">.002</td></tr>
    <tr><td>Tenure</td><td class="dim">Burnout</td><td class="dim">-0.31</td><td class="dim">.018</td></tr>
  </tbody></table></div></div>
</section>
</div>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Effect sizes</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Significance is not</span><br>the whole story.</h2>
    <p class="sl-feat-body rv rv-d2">Cohen's d, eta-squared, and odds ratios alongside every test result. A statistically significant finding with a small effect tells a very different story than one with a large one.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-rows">
    <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Small (d &lt; 0.2)</span><span class="sv-row-score">20%</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:20%"></div></div></div>
    <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Medium (d 0.2&ndash;0.8)</span><span class="sv-row-score">50%</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:50%"></div></div></div>
    <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Large (d &gt; 0.8)</span><span class="sv-row-score">80%</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:80%"></div></div></div>
  </div></div>
</section>
</div>

<section class="sl-cta">
  <h2 class="sl-cta-h rv">A finding worth reporting<br><em>is a finding worth testing.</em></h2>
  <div class="rv rv-d1"><a href="/inferential-statistics-workspace.php?v4=1" class="sl-cta-btn">Open Inferential Studio</a></div>
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
$landing_tagline = 'From hypothesis to evidence you can stand behind.';
include __DIR__ . '/_landing_foot.php';
