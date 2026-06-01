<?php
// rssi.php — RSSI landing page. RSSI is the trademark product: lead with it.
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
<style>
/* ── RSSI product showcase ────────────────────────────────── */
@keyframes rssi-drift { 0%,100%{background-position:0% 50%} 50%{background-position:100% 50%} }
.rssi-showcase {
  background: linear-gradient(135deg, #04111f 0%, #001a38 40%, #030d1a 100%);
  background-size: 200% 200%;
  animation: rssi-drift 18s ease infinite;
  padding: 100px 32px 90px; text-align: center; position: relative; overflow: hidden;
}
.rssi-showcase::before {
  content: ''; position: absolute; inset: 0;
  background: radial-gradient(ellipse at 50% 60%, rgba(0,122,255,.18) 0%, transparent 65%);
  pointer-events: none;
}
.rssi-show-eyebrow {
  font-size: 11px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase;
  color: rgba(255,255,255,.38); margin-bottom: 44px;
  display: flex; align-items: center; gap: 12px; justify-content: center;
}
.rssi-show-eyebrow::before, .rssi-show-eyebrow::after {
  content: ''; width: 36px; height: 1px; background: rgba(255,255,255,.18);
}
.rssi-show-score {
  line-height: 1; margin-bottom: 16px;
}
.rssi-show-num {
  font-size: clamp(96px, 14vw, 160px); font-weight: 900;
  letter-spacing: -.05em; color: var(--accent);
}
.rssi-show-denom {
  font-size: clamp(28px, 4vw, 44px); font-weight: 200;
  color: rgba(255,255,255,.35); vertical-align: super;
}
.rssi-show-band {
  display: inline-block; margin-bottom: 52px;
  font-size: 13px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
  color: var(--accent); background: rgba(0,122,255,.14); padding: 7px 20px; border-radius: 999px;
}
.rssi-domains {
  display: grid; grid-template-columns: repeat(4,1fr); gap: 14px;
  max-width: 820px; margin: 0 auto;
}
.rssi-domain {
  background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.09);
  border-radius: 16px; padding: 18px 16px; text-align: left;
}
.rssi-domain-name {
  font-size: 10.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
  color: rgba(255,255,255,.38); margin-bottom: 10px;
}
.rssi-domain-score { font-size: 30px; font-weight: 900; color: #fff; letter-spacing: -.02em; }
.rssi-domain-max   { font-size: 14px; font-weight: 300; color: rgba(255,255,255,.32); }
.rssi-domain-bar   { height: 4px; background: rgba(255,255,255,.1); border-radius: 999px; margin-top: 12px; overflow: hidden; }
.rssi-domain-fill  { height: 100%; border-radius: 999px; background: var(--accent); }
@media(max-width:700px) { .rssi-domains { grid-template-columns: repeat(2,1fr); } }
</style>

<!-- ── HERO: the product, by name ─────────────────────────── -->
<section class="sl-hero">
  <img src="/rssi-icon.png" alt="RSSI" class="sl-logo rv">
  <h1 class="sl-h1 rv rv-d1">
    <span class="thin">ReliCheck</span><br>
    Survey Strength Index.
  </h1>
  <p class="sl-body rv rv-d2">
    One score. Four dimensions. A clear verdict on whether your survey evidence
    is strong enough to interpret, defend, and act on.
  </p>
  <div class="sl-actions rv rv-d3">
    <a href="/rssi-app.php" class="sl-btn-a">Run RSSI</a>
  </div>
  <div class="sl-scroll rv" style="transition-delay:.5s">Scroll</div>
</section>

<!-- ── THE INDEX: full product showcase ───────────────────── -->
<section class="rssi-showcase">
  <div class="rssi-show-eyebrow">The ReliCheck Survey Strength Index</div>
  <div class="rssi-show-score">
    <span class="rssi-show-num rv">87</span><span class="rssi-show-denom">/100</span>
  </div>
  <div class="rssi-show-band rv rv-d1">Confident evidence</div>
  <div class="rssi-domains rv rv-d2">
    <div class="rssi-domain">
      <div class="rssi-domain-name">Internal consistency</div>
      <div><span class="rssi-domain-score">31</span><span class="rssi-domain-max">/35</span></div>
      <div class="rssi-domain-bar"><div class="rssi-domain-fill" style="width:89%"></div></div>
    </div>
    <div class="rssi-domain">
      <div class="rssi-domain-name">Item performance</div>
      <div><span class="rssi-domain-score">19</span><span class="rssi-domain-max">/25</span></div>
      <div class="rssi-domain-bar"><div class="rssi-domain-fill" style="width:76%"></div></div>
    </div>
    <div class="rssi-domain">
      <div class="rssi-domain-name">Response quality</div>
      <div><span class="rssi-domain-score">18</span><span class="rssi-domain-max">/20</span></div>
      <div class="rssi-domain-bar"><div class="rssi-domain-fill" style="width:90%"></div></div>
    </div>
    <div class="rssi-domain">
      <div class="rssi-domain-name">Score interpretability</div>
      <div><span class="rssi-domain-score">19</span><span class="rssi-domain-max">/20</span></div>
      <div class="rssi-domain-bar"><div class="rssi-domain-fill" style="width:95%"></div></div>
    </div>
  </div>
</section>

<!-- ── FEATURE 1: Internal Consistency ────────────────────── -->
<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Internal consistency</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Do your items</span><br>hold together?</h2>
    <p class="sl-feat-body rv rv-d2">Cronbach's alpha and item-total correlations show how well your scale items hang together as a measure. A weak alpha means your items may be pulling in different directions.</p>
  </div>
  <div class="sl-visual rv rv-d1">
    <div class="sv-result">
      <div class="sv-result-tag">Cronbach Alpha</div>
      <div class="sv-result-stat">0.87</div>
      <div class="sv-result-sub">12-item Belonging scale, n=412</div>
      <div class="sv-result-note">Removing item 9 raises alpha to 0.89. Item 9 has the lowest corrected item-total correlation (r = .18).</div>
    </div>
  </div>
</section>
</div>

<!-- ── FEATURE 2: Item Performance ────────────────────────── -->
<div style="background:#f5f5f7">
<section class="sl-feat-sect flip">
  <div>
    <div class="sl-feat-tag rv">Item performance</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Which items are</span><br>pulling you down?</h2>
    <p class="sl-feat-body rv rv-d2">Flag items that are too easy, too hard, or reducing your scale reliability. Item-level diagnostics show you exactly which items to revise before your next data collection.</p>
  </div>
  <div class="sl-visual rv rv-d1">
    <div class="sv-table"><table>
      <thead><tr><td>Item</td><td>Item-total r</td><td>Alpha if removed</td><td>Flag</td></tr></thead>
      <tbody>
        <tr><td>Item 3</td><td class="dim">0.62</td><td class="dim">0.85</td><td><span class="sv-flag ok">Good</span></td></tr>
        <tr><td>Item 7</td><td class="dim">0.58</td><td class="dim">0.86</td><td><span class="sv-flag ok">Good</span></td></tr>
        <tr><td>Item 9</td><td class="dim">0.18</td><td class="dim">0.89</td><td><span class="sv-flag warn">Weak</span></td></tr>
        <tr><td>Item 11</td><td class="dim">0.71</td><td class="dim">0.84</td><td><span class="sv-flag ok">Good</span></td></tr>
      </tbody>
    </table></div>
  </div>
</section>
</div>

<!-- ── FEATURE 3: What the score means ────────────────────── -->
<div style="background:#fff">
<section class="sl-feat-sect">
  <div>
    <div class="sl-feat-tag rv">Score interpretability</div>
    <h2 class="sl-feat-h rv rv-d1"><span class="light">Not just a number.</span><br>A verdict.</h2>
    <p class="sl-feat-body rv rv-d2">RSSI tells you what band your score falls in and what you can responsibly say about it. Confident evidence. Emerging evidence. Insufficient data. The index gives you the language, not just the number.</p>
  </div>
  <div class="sl-visual rv rv-d1">
    <div class="sv-rows">
      <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">85 and above</span><span class="sv-row-score">Confident</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:100%"></div></div></div>
      <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">70 to 84</span><span class="sv-row-score">Emerging</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:72%"></div></div></div>
      <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">55 to 69</span><span class="sv-row-score">Limited</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:45%"></div></div></div>
      <div class="sv-row"><div class="sv-row-top"><span class="sv-row-label">Below 55</span><span class="sv-row-score">Not yet reliable</span></div><div class="sv-bar"><div class="sv-bar-fill" style="width:18%"></div></div></div>
    </div>
  </div>
</section>
</div>

<!-- ── DARK CTA ────────────────────────────────────────────── -->
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
