<?php
// studio-da.php — Descriptive Studio landing page (v4 style).
require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/studio-da.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['da'];
$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$landing_title         = 'Descriptive Studio — ReliCheck';
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
  <img src="/Descriptive%20Studio.png" alt="Descriptive Studio" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1"><span class="thin">Know your data</span><br>before the claim.</h1>
  <p class="sl-body rv rv-d2">Descriptive analysis is not a preliminary step. It is where patterns emerge, distributions speak, and the shape of your evidence becomes visible. Descriptive Studio helps you see what your data actually says.</p>
  <div class="sl-actions rv rv-d3">
    <a href="/descriptive-analysis-workspace.php" class="sl-btn-a">Open Descriptive Studio</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Frequencies &amp; distributions</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">See the shape</span><br>of your data.</h2>
    <p class="sl-feat-body rv rv-d2">Frequency tables, histograms, and distribution summaries for every variable. Know which responses cluster, which spread wide, and which values stand out before any comparison begins.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-rows">
    <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Strongly agree</span><span class="sv-row-score">34%</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:34%"></div></div></div>
    <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Agree</span><span class="sv-row-score">41%</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:41%"></div></div></div>
    <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Neutral</span><span class="sv-row-score">14%</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:14%"></div></div></div>
    <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Disagree</span><span class="sv-row-score">11%</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:11%"></div></div></div>
  </div></div>
</section>
</div>

<div style="background:#f5f5f7">
<section class="sl-feat-sect flip">
  <div>
    <div class="sl-feat-tag rv">Cross-tabs &amp; group summaries</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Compare across</span><br>every group.</h2>
    <p class="sl-feat-body rv rv-d2">Group means, medians, and frequency breakdowns across any categorical variable. See how responses differ by department, role, tenure, or any grouping that matters to your question.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-table"><table><thead><tr><td>Group</td><td>N</td><td>Mean</td><td>SD</td></tr></thead><tbody>
    <tr><td>Engineering</td><td class="dim">42</td><td class="dim">3.8</td><td class="dim">0.7</td></tr>
    <tr><td>Operations</td><td class="dim">38</td><td class="dim">3.2</td><td class="dim">0.9</td></tr>
    <tr><td>Administration</td><td class="dim">29</td><td class="dim">4.1</td><td class="dim">0.6</td></tr>
  </tbody></table></div></div>
</section>
</div>

<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Scale scores</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">From items to</span><br>construct scores.</h2>
    <p class="sl-feat-body rv rv-d2">Average or sum Likert items into construct scores and see how those scores distribute across your sample. The first step toward knowing whether your constructs behaved as intended.</p>
  </div>
  <div class="sl-visual rv rv-d1"><div class="sv-result">
    <div class="sv-result-tag">Mean construct score</div>
    <div class="sv-result-stat">3.7</div>
    <div class="sv-result-sub">Mean construct score</div>
    <div class="sv-result-note">SD 0.82 &middot; Range 1.0 &ndash; 5.0 &middot; N = 109</div>
  </div></div>
</section>
</div>

<section class="sl-cta">
  <h2 class="sl-cta-h rv">Good analysis starts<br><em>with honest description.</em></h2>
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
$landing_tagline = 'See what the data says before the interpretation begins.';
include __DIR__ . '/_landing_foot.php';
