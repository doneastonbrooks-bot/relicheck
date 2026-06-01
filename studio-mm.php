<?php
// MM Studio — cinematic landing page.
// "Something Matters" ecosystem intro, Apple-style full-bleed sections.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/studio-mm.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

// Deep-link: project_id → skip landing and open the studio directly.
$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
if ($projectId > 0) {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id FROM mm_projects WHERE id = :id AND user_id = :uid AND status <> "archived"');
  $stmt->execute([':id' => $projectId, ':uid' => $uid]);
  if ($stmt->fetch(PDO::FETCH_ASSOC)) {
    header('Location: /project-snapshot.php?studio=mm&project_id=' . $projectId);
    exit;
  }
}

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['mm'];

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$landing_title         = 'MM Studio — ReliCheck';
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

<style>
/* ── Escape the lp-page container; sections own their own geometry ── */
.lp-page { max-width: none !important; padding: 0 !important; }

/* ── Shared tokens ── */
:root {
  --ink-a:  #1d1d1f;
  --ink-b:  #6e6e73;
  --ink-c:  #86868b;
  --bg-w:   #ffffff;
  --bg-l:   #f5f5f7;
  --bg-dk:  #1d1d1f;
  --r:      900px;      /* max reading width */
  --w:      1100px;     /* max layout width */
}

/* ── Scroll-reveal ── */
.rv { opacity: 0; transform: translateY(26px); transition: opacity .75s ease, transform .75s ease; }
.rv.in { opacity: 1; transform: none; }
.rv-d1 { transition-delay: .1s; } .rv-d2 { transition-delay: .2s; } .rv-d3 { transition-delay: .3s; }

/* ════════════════════════════════════════
   HERO
════════════════════════════════════════ */
.mm-hero {
  min-height: 94vh;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center;
  padding: 100px 32px 80px;
  background:
    radial-gradient(ellipse at 50% -10%, color-mix(in srgb, var(--accent) 10%, transparent) 0%, transparent 65%),
    var(--bg-w);
}
.mm-eyebrow {
  display: inline-flex; align-items: center; gap: 10px;
  font-size: 11px; font-weight: 700; letter-spacing: .15em; text-transform: uppercase;
  color: var(--ink-c); margin-bottom: 36px;
}
.mm-eyebrow::before, .mm-eyebrow::after {
  content: ''; display: block; width: 28px; height: 1px;
  background: var(--accent); opacity: .45;
}
.mm-hero-h1 {
  font-size: clamp(44px, 7vw, 80px);
  font-weight: 900; letter-spacing: -.05em; line-height: 1.02;
  color: var(--ink-a); margin: 0 auto 32px; max-width: 18ch;
}
.mm-hero-h1 .thin { font-weight: 200; color: var(--ink-b); }
.mm-hero-h1 em     { font-style: normal; color: var(--accent); }
.mm-hero-body {
  font-size: clamp(16px, 2vw, 19px); font-weight: 300; color: var(--ink-b);
  line-height: 1.7; max-width: 46ch; margin: 0 auto 48px;
}
.mm-hero-actions { display: flex; align-items: center; gap: 16px; justify-content: center; flex-wrap: wrap; }
.mm-btn-a {
  background: var(--accent); color: #fff;
  font-size: 15px; font-weight: 700; padding: 14px 30px; border-radius: 999px;
  text-decoration: none; transition: opacity .15s, transform .15s;
}
.mm-btn-a:hover { opacity: .86; transform: translateY(-1px); }
.mm-btn-b {
  font-size: 15px; font-weight: 600; color: var(--accent);
  text-decoration: none; display: flex; align-items: center; gap: 4px;
}
.mm-btn-b:hover { opacity: .75; }
.mm-scroll-cue {
  margin-top: 72px;
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  font-size: 10.5px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase;
  color: var(--ink-c);
}
.mm-scroll-cue::after {
  content: ''; width: 1px; height: 36px;
  background: var(--ink-c); opacity: .35;
}

/* ════════════════════════════════════════
   ECOSYSTEM — Something Matters Suite
════════════════════════════════════════ */
.mm-eco {
  background: var(--bg-w);
  border-top: 1px solid rgba(0,0,0,.07);
  border-bottom: 1px solid rgba(0,0,0,.07);
  padding: 64px 32px;
}
.mm-eco-inner { max-width: var(--w); margin: 0 auto; }
.mm-eco-label {
  text-align: center; font-size: 11px; font-weight: 700;
  letter-spacing: .12em; text-transform: uppercase; color: var(--ink-c); margin-bottom: 36px;
}
.mm-eco-grid {
  display: grid; grid-template-columns: 1fr auto 1fr auto 1fr; gap: 0;
  align-items: stretch; max-width: 860px; margin: 0 auto;
}
.mm-eco-arrow { display: flex; align-items: center; color: var(--ink-c); padding: 0 12px; font-size: 22px; font-weight: 200; }
.mm-eco-card {
  border: 1px solid rgba(0,0,0,.09); border-radius: 20px; padding: 28px 24px;
  display: flex; flex-direction: column; gap: 8px; transition: .18s; cursor: default;
}
.mm-eco-card.current {
  background: color-mix(in srgb, var(--accent) 6%, white);
  border-color: color-mix(in srgb, var(--accent) 30%, transparent);
}
.mm-eco-card a { text-decoration: none; color: inherit; display: contents; }
.mm-eco-card:not(.current) { opacity: .72; }
.mm-eco-card:not(.current):hover { opacity: 1; border-color: rgba(0,0,0,.18); }
.mm-eco-ico {
  width: 44px; height: 44px; border-radius: 13px;
  display: grid; place-items: center; font-size: 20px; margin-bottom: 6px;
}
.eco-siri  .mm-eco-ico { background: #e8f0fe; }
.eco-mm    .mm-eco-ico { background: color-mix(in srgb, var(--accent) 12%, white); }
.eco-rssi  .mm-eco-ico { background: #e8f9ed; }
.mm-eco-name { font-size: 17px; font-weight: 800; color: var(--ink-a); }
.mm-eco-full { font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--ink-c); }
.mm-eco-desc { font-size: 13px; color: var(--ink-b); line-height: 1.55; margin-top: 4px; }
.eco-current-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
  color: var(--accent); margin-top: 6px;
}
.eco-current-badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: var(--accent); }
.eco-link {
  font-size: 12px; font-weight: 700; color: var(--ink-c); margin-top: 8px; display: block;
}
.eco-link:hover { color: var(--accent); }
@media (max-width: 680px) {
  .mm-eco-grid { grid-template-columns: 1fr; }
  .mm-eco-arrow { justify-content: center; padding: 6px 0; transform: rotate(90deg); }
}

/* ════════════════════════════════════════
   FEATURE SECTIONS
════════════════════════════════════════ */
.mm-feature {
  padding: 100px 32px;
  display: grid; grid-template-columns: 1fr 1fr; gap: 80px;
  align-items: center; max-width: var(--w); margin: 0 auto;
}
.mm-feature.flip { direction: rtl; }
.mm-feature.flip > * { direction: ltr; }
.mm-feature-wrap { background: var(--bg-l); }
.mm-feature-wrap .mm-feature { max-width: var(--w); margin: 0 auto; }
.mm-fs-tag {
  font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
  color: var(--accent); margin-bottom: 14px;
}
.mm-fs-h {
  font-size: clamp(30px, 4vw, 44px); font-weight: 800; letter-spacing: -.03em;
  line-height: 1.08; color: var(--ink-a); margin-bottom: 18px;
}
.mm-fs-h .light { font-weight: 200; }
.mm-fs-body { font-size: 17px; color: var(--ink-b); line-height: 1.7; max-width: 42ch; }
.mm-visual {
  border-radius: 22px; overflow: hidden;
  background: linear-gradient(135deg, #f0ebff 0%, #e6eeff 100%);
  aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center;
  padding: 28px; box-shadow: 0 4px 32px rgba(108,63,196,.1);
}
.mm-feature.flip .mm-visual { direction: ltr; }

/* Pipeline visual */
.mm-pipe { width: 100%; display: flex; flex-direction: column; gap: 10px; }
.mm-pipe-step {
  background: #fff; border-radius: 14px; padding: 14px 16px;
  display: flex; align-items: center; gap: 12px;
  box-shadow: 0 2px 10px rgba(0,0,0,.07);
}
.mm-pipe-n {
  width: 30px; height: 30px; border-radius: 50%; background: var(--accent);
  color: #fff; font-size: 12px; font-weight: 800; display: grid; place-items: center; flex: none;
}
.mm-pipe-label { font-size: 13.5px; font-weight: 700; color: var(--ink-a); }
.mm-pipe-sub   { font-size: 11.5px; color: var(--ink-c); margin-top: 2px; }

/* AI visual */
.mm-ai-visual {
  width: 100%; display: flex; flex-direction: column; gap: 12px;
}
.mm-ai-bubble {
  background: #fff; border-radius: 14px; padding: 14px 16px;
  box-shadow: 0 2px 10px rgba(0,0,0,.07); font-size: 13px; color: var(--ink-a); line-height: 1.5;
}
.mm-ai-bubble.ai {
  background: color-mix(in srgb, var(--accent) 8%, white);
  border-left: 3px solid var(--accent);
}
.mm-ai-label {
  font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
  color: var(--ink-c); margin-bottom: 5px;
}

/* Report visual */
.mm-report-visual { width: 100%; display: flex; flex-direction: column; gap: 8px; }
.mm-report-section {
  background: #fff; border-radius: 10px; padding: 12px 14px;
  box-shadow: 0 1px 6px rgba(0,0,0,.06);
}
.mm-rs-label { font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--accent); margin-bottom: 4px; }
.mm-rs-bar { height: 7px; border-radius: 999px; background: color-mix(in srgb, var(--accent) 20%, white); position: relative; }
.mm-rs-bar::after { content: ''; position: absolute; inset: 0 auto 0 0; border-radius: inherit; background: var(--accent); }
.mm-rs-bar.b90::after { width: 90%; }
.mm-rs-bar.b75::after { width: 75%; }
.mm-rs-bar.b82::after { width: 82%; }

@media (max-width: 720px) {
  .mm-feature, .mm-feature.flip { grid-template-columns: 1fr; direction: ltr; gap: 40px; }
  .mm-feature { padding: 60px 24px; }
}

/* ════════════════════════════════════════
   MID-PAGE START CTA (the "something matters" moment)
════════════════════════════════════════ */
.mm-start {
  background: var(--bg-dk); color: #fff;
  padding: 120px 32px; text-align: center;
}
.mm-start-tag {
  font-size: 11px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase;
  color: rgba(255,255,255,.38); margin-bottom: 24px;
  display: flex; align-items: center; gap: 10px; justify-content: center;
}
.mm-start-tag::before, .mm-start-tag::after {
  content: ''; width: 24px; height: 1px; background: rgba(255,255,255,.2);
}
.mm-start-h {
  font-size: clamp(36px, 5.5vw, 64px); font-weight: 900;
  letter-spacing: -.04em; line-height: 1.04; margin-bottom: 20px;
}
.mm-start-h em { font-style: normal; color: var(--accent); }
.mm-start-body {
  font-size: 18px; color: rgba(255,255,255,.52); line-height: 1.65;
  max-width: 44ch; margin: 0 auto 48px;
}
.mm-start-actions { display: flex; align-items: center; gap: 16px; justify-content: center; flex-wrap: wrap; }
.mm-start-btn {
  background: var(--accent); color: #fff;
  font-size: 16px; font-weight: 700; padding: 16px 34px; border-radius: 999px;
  text-decoration: none; transition: opacity .15s, transform .15s;
}
.mm-start-btn:hover { opacity: .88; transform: translateY(-1px); }
.mm-start-ghost {
  font-size: 15px; font-weight: 600; color: rgba(255,255,255,.55);
  text-decoration: none; transition: color .15s;
}
.mm-start-ghost:hover { color: rgba(255,255,255,.85); }

/* ════════════════════════════════════════
   RECENT PROJECTS
════════════════════════════════════════ */
.mm-recent-wrap {
  padding: 80px 32px 100px; background: var(--bg-w);
  border-top: 1px solid rgba(0,0,0,.07);
}
.mm-recent-inner { max-width: 960px; margin: 0 auto; }
.mm-recent-head {
  display: flex; justify-content: space-between; align-items: baseline;
  margin-bottom: 24px;
}
.mm-recent-h { font-size: 22px; font-weight: 800; color: var(--ink-a); }
.mm-recent-all { font-size: 13px; font-weight: 700; color: var(--accent); text-decoration: none; }
.mm-recent-all:hover { opacity: .75; }
#mmRecentGrid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.mm-proj-card {
  background: var(--bg-l); border-radius: 16px; padding: 20px;
  text-decoration: none; display: flex; flex-direction: column; gap: 5px;
  border: 1px solid rgba(0,0,0,.06); transition: .16s;
}
.mm-proj-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(0,0,0,.08); }
.mm-proj-label { font-size: 10px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--accent); }
.mm-proj-title { font-size: 15px; font-weight: 700; color: var(--ink-a); line-height: 1.4; }
.mm-proj-meta  { font-size: 12px; color: var(--ink-c); margin-top: 4px; }
.mm-recent-empty { font-size: 14px; color: var(--ink-c); }
@media (max-width: 680px) { #mmRecentGrid { grid-template-columns: 1fr; } }
</style>

<!-- ══════════════════════════════════════════
     HERO
══════════════════════════════════════════ -->
<section class="mm-hero">
  <div class="mm-eyebrow">Mixed Methods Studio · ReliCheck</div>
  <h1 class="mm-hero-h1 rv">
    <span class="thin">You're not here</span><br>
    for the data.<br>
    <span class="thin">You're here because</span><br>
    <em>something matters.</em>
  </h1>
  <p class="mm-hero-body rv rv-d1">
    Mixed methods research is how you find out why — and prove it.
    Numbers show what happened. Narratives show why.
    Together, they show what it means for the people who matter.
  </p>
  <div class="mm-hero-actions rv rv-d2">
    <a href="/mmstudioV4.php" class="mm-btn-a">Open MM Studio</a>
    <a href="/mm-wizard.php?step=1" class="mm-btn-b">Upload your data →</a>
  </div>
  <div class="mm-scroll-cue rv rv-d3">Scroll</div>
</section>

<!-- ══════════════════════════════════════════
     SOMETHING MATTERS ECOSYSTEM
══════════════════════════════════════════ -->
<section class="mm-eco">
  <div class="mm-eco-inner">
    <p class="mm-eco-label rv">The Something Matters Research Suite</p>
    <div class="mm-eco-grid">

      <div class="mm-eco-card eco-siri rv rv-d1">
        <a href="/develop.php?db=1&start=choose">
          <div class="mm-eco-ico">🔭</div>
          <div class="mm-eco-name">SIRI</div>
          <div class="mm-eco-full">Survey Intelligence Readiness Index</div>
          <div class="mm-eco-desc">Strengthen your survey before you launch. Catch blind spots in design, reliability, and administration — before the first response arrives.</div>
          <span class="eco-link">Go to SIRI →</span>
        </a>
      </div>

      <div class="mm-eco-arrow">→</div>

      <div class="mm-eco-card eco-mm current rv rv-d2">
        <div class="mm-eco-ico">⟁</div>
        <div class="mm-eco-name">MM Studio</div>
        <div class="mm-eco-full">Mixed Methods Studio</div>
        <div class="mm-eco-desc">Analyze both strands. Integrate them. Build a credible story from numbers and narratives — in one connected studio.</div>
        <span class="eco-current-badge">You are here</span>
      </div>

      <div class="mm-eco-arrow">→</div>

      <div class="mm-eco-card eco-rssi rv rv-d3">
        <a href="/rssi.php">
          <div class="mm-eco-ico">◎</div>
          <div class="mm-eco-name">RSSI</div>
          <div class="mm-eco-full">ReliCheck Survey Strength Index</div>
          <div class="mm-eco-desc">Once responses are in, evaluate the strength of your survey evidence — reliability, item performance, response quality, and interpretability.</div>
          <span class="eco-link">Go to RSSI →</span>
        </a>
      </div>

    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════
     FEATURE 1 — The pipeline
══════════════════════════════════════════ -->
<div style="background:var(--bg-w)">
<section class="mm-feature">
  <div>
    <div class="mm-fs-tag rv">How it works</div>
    <h2 class="mm-fs-h rv rv-d1">From data to meaning.<br><span class="light">In one studio.</span></h2>
    <p class="mm-fs-body rv rv-d2">Map your variables, surface qualitative themes, run statistics, integrate both strands, and assemble a publish-ready report — without switching tools or losing the thread.</p>
  </div>
  <div class="mm-visual rv rv-d1">
    <div class="mm-pipe">
      <div class="mm-pipe-step"><span class="mm-pipe-n">01</span><div><div class="mm-pipe-label">Map &amp; Check</div><div class="mm-pipe-sub">Organize variables. Audit data quality.</div></div></div>
      <div class="mm-pipe-step"><span class="mm-pipe-n">02</span><div><div class="mm-pipe-label">Analyze Both Strands</div><div class="mm-pipe-sub">Statistics + qualitative themes.</div></div></div>
      <div class="mm-pipe-step"><span class="mm-pipe-n">03</span><div><div class="mm-pipe-label">Integrate</div><div class="mm-pipe-sub">Joint display. Convergence. Meta-inferences.</div></div></div>
      <div class="mm-pipe-step"><span class="mm-pipe-n">04</span><div><div class="mm-pipe-label">Report</div><div class="mm-pipe-sub">Evidence-backed write-up, ready to share.</div></div></div>
    </div>
  </div>
</section>
</div>

<!-- ══════════════════════════════════════════
     FEATURE 2 — ReliCheck Intelligence
══════════════════════════════════════════ -->
<div class="mm-feature-wrap">
<section class="mm-feature flip">
  <div>
    <div class="mm-fs-tag rv">ReliCheck Intelligence</div>
    <h2 class="mm-fs-h rv rv-d1">Built in.<br><span class="light">Not bolted on.</span></h2>
    <p class="mm-fs-body rv rv-d2">ReliCheck Intelligence drafts codebooks, suggests themes, picks supporting quotes, and scaffolds your analysis — but every conclusion is yours. You're not here to let AI decide. You're here because the finding matters.</p>
  </div>
  <div class="mm-visual rv rv-d1">
    <div class="mm-ai-visual">
      <div class="mm-ai-bubble">
        <div class="mm-ai-label">Your work</div>
        "Participants described feeling unheard in decision-making processes."
      </div>
      <div class="mm-ai-bubble ai">
        <div class="mm-ai-label">ReliCheck Intelligence</div>
        Three additional quotes match this theme. Cross-reference with the t-test result for Group B (p = .03)?
      </div>
      <div class="mm-ai-bubble">
        <div class="mm-ai-label">Your decision</div>
        Include all three. Flag the Group B finding for meta-inference.
      </div>
    </div>
  </div>
</section>
</div>

<!-- ══════════════════════════════════════════
     FEATURE 3 — Report
══════════════════════════════════════════ -->
<div style="background:var(--bg-w)">
<section class="mm-feature">
  <div>
    <div class="mm-fs-tag rv">Report Builder</div>
    <h2 class="mm-fs-h rv rv-d1">One report.<br><span class="light">All the evidence.</span></h2>
    <p class="mm-fs-body rv rv-d2">Your findings, limitations, supporting quotes, and statistical results assembled into a single document. Download as Word or Markdown. Send it to a colleague. Defend every claim.</p>
  </div>
  <div class="mm-visual rv rv-d1">
    <div class="mm-report-visual">
      <div class="mm-report-section"><div class="mm-rs-label">Theme convergence</div><div class="mm-rs-bar b90"></div></div>
      <div class="mm-report-section"><div class="mm-rs-label">Statistical support</div><div class="mm-rs-bar b75"></div></div>
      <div class="mm-report-section"><div class="mm-rs-label">Evidence strength</div><div class="mm-rs-bar b82"></div></div>
      <div class="mm-report-section" style="background:color-mix(in srgb, var(--accent) 6%, white); border-left:3px solid var(--accent)">
        <div class="mm-rs-label">Report ready</div>
        <div style="font-size:12.5px;color:var(--ink-a);margin-top:4px">Mixed Methods Findings Report · Word · Markdown</div>
      </div>
    </div>
  </div>
</section>
</div>

<!-- ══════════════════════════════════════════
     MID-PAGE START CTA — the "something matters" moment
══════════════════════════════════════════ -->
<section class="mm-start">
  <div class="mm-start-tag rv">Start your project</div>
  <h2 class="mm-start-h rv rv-d1">
    Your research<br>deserves <em>this.</em>
  </h2>
  <p class="mm-start-body rv rv-d2">
    Your survey responses are waiting. Your interview transcripts are waiting.
    Open MM Studio and bring them together.
  </p>
  <div class="mm-start-actions rv rv-d3">
    <a href="/mmstudioV4.php" class="mm-start-btn">Open MM Studio</a>
    <a href="/studio-mm-projects.php" class="mm-start-ghost">View your projects →</a>
  </div>
</section>

<!-- ══════════════════════════════════════════
     RECENT PROJECTS
══════════════════════════════════════════ -->
<div class="mm-recent-wrap">
  <div class="mm-recent-inner">
    <div class="mm-recent-head rv">
      <span class="mm-recent-h">Recent projects</span>
      <a href="/studio-mm-projects.php" class="mm-recent-all">See all →</a>
    </div>
    <div id="mmRecentGrid"><p class="mm-recent-empty">Loading your recent MM projects…</p></div>
  </div>
</div>

<script>
/* ── Scroll-reveal observer ── */
(function(){
  const obs = new IntersectionObserver(function(entries){
    entries.forEach(function(e){ if(e.isIntersecting){ e.target.classList.add('in'); obs.unobserve(e.target); }});
  }, { threshold: 0.12 });
  document.querySelectorAll('.rv').forEach(function(el){ obs.observe(el); });
})();

/* ── Recent projects ── */
(function(){
  const host = document.getElementById('mmRecentGrid');
  if (!host) return;
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
  fetch('/api/mm/projects.php', { credentials: 'same-origin', headers: { Accept: 'application/json' }})
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(data){
      const projects = (data && data.ok && Array.isArray(data.projects)) ? data.projects.slice(0,6) : [];
      if (!projects.length) {
        host.innerHTML = '<p class="mm-recent-empty">No MM projects yet — <a href="/mm-wizard.php?step=1" style="color:var(--accent);font-weight:700">upload data</a> to create one.</p>';
        return;
      }
      host.innerHTML = projects.map(function(p){
        return '<a class="mm-proj-card rv in" href="/studio-mm.php?project_id='+encodeURIComponent(p.id)+'">'
          +'<span class="mm-proj-label">'+esc(p.pathway||'MM')+'</span>'
          +'<span class="mm-proj-title">'+esc(p.title||'Untitled project')+'</span>'
          +'<span class="mm-proj-meta">Updated '+esc((p.updated_at||'').slice(0,10)||'—')+'</span>'
          +'</a>';
      }).join('');
    })
    .catch(function(){ host.innerHTML = '<p class="mm-recent-empty">Could not load recent projects.</p>'; });
})();
</script>

<?php
$landing_tagline = 'You\'re not here for the data. You\'re here because something matters.';
include __DIR__ . '/_landing_foot.php';
