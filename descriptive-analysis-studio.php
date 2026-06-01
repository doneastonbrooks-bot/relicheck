<?php
// descriptive-analysis-studio.php — Descriptive Analysis Studio landing page (v4 style).
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

<section class="sl-hero">
  <img src="/Descriptive%20Studio.png" alt="Descriptive Analysis Studio" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">Know what is</span><br>in your data.</h1>
  <p class="sl-body rv rv-d2">Descriptive Studio summarizes what is present before you test what is true. Frequencies, distributions, group summaries, and item rankings. The full picture before any inference.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/descriptive-analysis-workspace.php" class="sl-btn-a">Open Descriptive Studio</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Frequencies</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Who responded,</span><br>and how.</h2>
    <p class="sl-feat-body rv rv-d2">See category-by-category counts and percentages for every variable in your dataset. Know who is in your sample before you draw any conclusions about them.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-table"><table><thead><tr><td>Response</td><td>Count</td><td>Percent</td></tr></thead><tbody>
        <tr><td>Strongly agree</td><td class="dim">84</td><td class="dim">32.6%</td></tr>
        <tr><td>Agree</td><td class="dim">97</td><td class="dim">37.6%</td></tr>
        <tr><td>Neutral</td><td class="dim">42</td><td class="dim">16.3%</td></tr>
        <tr><td>Disagree</td><td class="dim">25</td><td class="dim">9.7%</td></tr>
        <tr><td>Strongly disagree</td><td class="dim">10</td><td class="dim">3.9%</td></tr>
        </tbody></table></div></div>
</section>
</div>

<div style="background:#f5f5f7">
<section class="sl-feat-sect flip">
  <div>
    <div class="sl-feat-tag rv">Distributions</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">The shape of</span><br>your data.</h2>
    <p class="sl-feat-body rv rv-d2">Means, medians, ranges, and spread for every numeric variable. Understand whether your data is skewed, bunched, or normally distributed before you run a single test.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-rows">
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Mean</span><span class="sv-row-score">3.91</span></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Median</span><span class="sv-row-score">4.00</span></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Std deviation</span><span class="sv-row-score">0.94</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:47%"></div></div></div>
        <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Range</span><span class="sv-row-score">1 to 5</span></div></div>
      </div></div>
</section>
</div>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Group summaries</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Compare groups</span><br>before you test.</h2>
    <p class="sl-feat-body rv rv-d2">See how different groups look on key variables before running a single inferential test. Group summaries let you spot patterns early and ask the right questions first.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-compare">
        <div class="sv-cmp-row">
          <div class="sv-cmp-label">Group A (n=142)<span>Mean 4.2</span></div>
          <div class="sv-cmp-bars"><div class="sv-cmp-bar others" style="width:84%"></div></div>
        </div>
        <div class="sv-cmp-row">
          <div class="sv-cmp-label">Group B (n=98)<span>Mean 3.6</span></div>
          <div class="sv-cmp-bars"><div class="sv-cmp-bar others" style="width:72%"></div></div>
        </div>
        <div class="sv-cmp-row">
          <div class="sv-cmp-label">Group C (n=172)<span>Mean 3.9</span></div>
          <div class="sv-cmp-bars"><div class="sv-cmp-bar others" style="width:78%"></div></div>
        </div>
      </div></div>
</section>
</div>

<section class="sl-cta">
  <h2 class="sl-cta-h rv">Before you test anything,<br><em>know what you have.</em></h2>
  <div class="rv rv-d1"><a href="/descriptive-analysis-workspace.php" class="sl-cta-btn">Open Descriptive Studio</a></div>
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
