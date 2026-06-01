<?php
// inferential-statistics-studio.php — Inferential Statistics Studio landing page (v4 style).
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/inferential-statistics-studio.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['inferential'];
$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$landing_title         = 'Inferential Statistics Studio — ReliCheck';
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
  <img src="/Inferential%20Studio.png" alt="Inferential Statistics Studio" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">Test what your</span><br>data can support.</h1>
  <p class="sl-body rv rv-d2">Inferential Statistics Studio helps you draw defensible conclusions. Comparisons, relationships, and regression with effect sizes and assumption checks built into every test.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/analysis-upload-wizard.php?studio=inferential" class="sl-btn-a">Open Inferential Studio</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Comparisons</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Is the difference</span><br>real?</h2>
    <p class="sl-feat-body rv rv-d2">t-tests, ANOVA, and chi-square with effect sizes and assumption checks. Every test tells you what it can claim and what it cannot, so you never overclaim your findings.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-result">
        <div class="sv-result-tag">Independent samples t-test</div>
        <div class="sv-result-stat">t = 3.42, p = .001</div>
        <div class="sv-result-sub">Group A (M=4.2) vs Group B (M=3.6), n=240</div>
        <div class="sv-result-note">Cohen's d = 0.61 (medium effect). Levene's test: equal variances assumed (p = .34).</div>
      </div></div>
</section>
</div>

<div style="background:#f5f5f7">
<section class="sl-feat-sect flip">
  <div>
    <div class="sl-feat-tag rv">Relationships</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">What predicts</span><br>what?</h2>
    <p class="sl-feat-body rv rv-d2">Correlations and regression to understand which variables move together and how strongly. Identify predictors, check assumptions, and interpret effect sizes alongside significance.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-rows">
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Engagement vs Belonging</span><span class="sv-row-score">r = .68</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:68%"></div></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Belonging vs Retention intent</span><span class="sv-row-score">r = .54</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:54%"></div></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Engagement vs Tenure</span><span class="sv-row-score">r = .21</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:21%"></div></div></div>
      </div></div>
</section>
</div>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Defensible results</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Know what you</span><br>can claim.</h2>
    <p class="sl-feat-body rv rv-d2">Every output shows what the test can support and what it cannot. Significance without effect size is not enough. Inferential Studio gives you both, plus the plain-language reading.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-list">
        <div class="sv-item"><span class="sv-dot ok">✓</span>Group difference is statistically significant</div>
        <div class="sv-item"><span class="sv-dot ok">✓</span>Effect size is medium (d = 0.61)</div>
        <div class="sv-item"><span class="sv-dot ok">✓</span>Assumptions met: normality and equal variances</div>
        <div class="sv-item"><span class="sv-dot mid">!</span>Sample size in Group B limits generalizability</div>
      </div></div>
</section>
</div>

<section class="sl-cta">
  <h2 class="sl-cta-h rv">Statistics are not answers.<br><em>They are evidence.</em></h2>
  <div class="rv rv-d1"><a href="/analysis-upload-wizard.php?studio=inferential" class="sl-cta-btn">Open Inferential Studio</a></div>
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
$landing_tagline = 'Test what your data can support.';
include __DIR__ . '/_landing_foot.php';
