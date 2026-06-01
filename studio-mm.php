<?php
// MM Studio — cinematic landing page.
// "Something Matters" ecosystem intro, Apple-style full-bleed sections.
// "something matters" lands only at the end — Nike/Apple style.

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

// Deep-link: project_id → skip landing, open studio directly.
$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
if ($projectId > 0) {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id FROM mm_projects WHERE id = :id AND user_id = :uid AND status <> "archived"');
  $stmt->execute([':id' => $projectId, ':uid' => $uid]);
  if ($stmt->fetch(PDO::FETCH_ASSOC)) {
    header('Location: /mmstudioV4.php?project_id=' . $projectId);
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
/* ── Escape the lp-page container; sections own their geometry ── */
.lp-page { max-width: none !important; padding: 0 !important; }

/* ── Shared tokens ── */
:root {
  --ink-a:  #1d1d1f;
  --ink-b:  #6e6e73;
  --ink-c:  #86868b;
  --bg-w:   #ffffff;
  --bg-l:   #f5f5f7;
  --bg-dk:  #1d1d1f;
  --w:      min(75vw, 1280px);
}

/* ── Scroll-reveal ── */
.rv { opacity: 0; transform: translateY(24px); transition: opacity .7s ease, transform .7s ease; }
.rv.in { opacity: 1; transform: none; }
.rv-d1 { transition-delay: .12s; } .rv-d2 { transition-delay: .24s; } .rv-d3 { transition-delay: .36s; }

/* ════════════════════════════════════════
   HERO
════════════════════════════════════════ */
.mm-hero {
  min-height: 94vh;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center;
  padding-top: 100px; padding-bottom: 80px;
  padding-left:  max(48px, calc(50% - 640px));
  padding-right: max(48px, calc(50% - 640px));
  background:
    radial-gradient(ellipse at 50% -10%, color-mix(in srgb, var(--accent) 9%, transparent) 0%, transparent 60%),
    var(--bg-w);
}
@media (max-width: 768px) { .mm-hero { padding-left: 24px; padding-right: 24px; } }
@media (max-width: 480px) { .mm-hero { padding-left: 16px; padding-right: 16px; } }
.mm-hero-logo {
  width: 100px; height: 100px; object-fit: contain;
  margin-bottom: 56px; border-radius: 22px;
}
.mm-hero-h1 {
  font-size: clamp(42px, 6.5vw, 76px);
  font-weight: 900; letter-spacing: -.05em; line-height: 1.03;
  color: var(--ink-a); margin: 0 auto 28px; max-width: 16ch;
}
.mm-hero-h1 .thin { font-weight: 200; color: var(--ink-b); }
.mm-hero-body {
  font-size: clamp(15px, 1.8vw, 18px); font-weight: 300; color: var(--ink-b);
  line-height: 1.7; max-width: 50ch; margin: 0 auto 48px;
}
.mm-hero-actions { display: flex; align-items: center; gap: 16px; justify-content: center; flex-wrap: wrap; }
.mm-btn-a {
  background: var(--accent); color: #fff;
  font-size: 15px; font-weight: 700; padding: 14px 30px; border-radius: 999px;
  text-decoration: none; transition: opacity .15s, transform .15s;
}
.mm-btn-a:hover { opacity: .86; transform: translateY(-1px); }
.mm-scroll-cue {
  margin-top: 72px;
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  font-size: 10.5px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase;
  color: var(--ink-c);
}
.mm-scroll-cue::after { content: ''; width: 1px; height: 36px; background: var(--ink-c); opacity: .3; }

/* ════════════════════════════════════════
   ECOSYSTEM — Something Matters Suite
════════════════════════════════════════ */
.mm-eco {
  background: var(--bg-w);
  border-top: 1px solid rgba(0,0,0,.07);
  border-bottom: 1px solid rgba(0,0,0,.07);
  padding: 60px 32px;
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
.mm-eco-arrow { display: flex; align-items: center; color: var(--ink-c); padding: 0 10px; font-size: 20px; font-weight: 200; }
.mm-eco-card {
  border: 1px solid rgba(0,0,0,.09); border-radius: 20px; padding: 26px 22px;
  display: flex; flex-direction: column; gap: 7px; transition: .18s;
}
.mm-eco-card.current {
  background: color-mix(in srgb, var(--accent) 6%, white);
  border-color: color-mix(in srgb, var(--accent) 30%, transparent);
}
.mm-eco-card:not(.current) { opacity: .7; }
.mm-eco-card:not(.current) a:hover { opacity: 1; }
.mm-eco-card a { text-decoration: none; color: inherit; display: contents; }
.mm-eco-ico {
  width: 42px; height: 42px; border-radius: 13px;
  display: grid; place-items: center; font-size: 18px; margin-bottom: 4px;
}
.eco-siri  .mm-eco-ico { background: #e8f0fe; }
.eco-mm    .mm-eco-ico { background: color-mix(in srgb, var(--accent) 12%, white); }
.eco-rssi  .mm-eco-ico { background: #e8f9ed; }
.mm-eco-name { font-size: 16px; font-weight: 800; color: var(--ink-a); }
.mm-eco-full { font-size: 10.5px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--ink-c); }
.mm-eco-desc { font-size: 12.5px; color: var(--ink-b); line-height: 1.55; margin-top: 3px; }
.eco-current-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
  color: var(--accent); margin-top: 4px;
}
.eco-current-badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: var(--accent); }
.eco-go { font-size: 12px; font-weight: 700; color: var(--ink-c); margin-top: 6px; display: block; text-decoration: none; }
.eco-go:hover { color: var(--accent); }
@media (max-width: 680px) {
  .mm-eco-grid { grid-template-columns: 1fr; }
  .mm-eco-arrow { justify-content: center; padding: 4px 0; transform: rotate(90deg); }
  .mm-eco-card:not(.current) { display: none; }
}

/* ════════════════════════════════════════
   FEATURE SECTIONS
════════════════════════════════════════ */
.mm-feature {
  width: var(--w); margin: 0 auto;
  padding-top: 96px; padding-bottom: 96px;
  padding-left: 0; padding-right: 0;
  display: grid; grid-template-columns: 1fr 1fr; gap: 80px;
  align-items: center;
}
@media (max-width: 1200px) {
  .mm-feature, .mm-feature.flip { width: auto; margin: 0; padding-left: 48px; padding-right: 48px; }
}
@media (max-width: 768px) {
  .mm-feature, .mm-feature.flip { grid-template-columns: 1fr; direction: ltr; gap: 40px; padding-left: 24px; padding-right: 24px; }
}
@media (max-width: 480px) {
  .mm-feature, .mm-feature.flip { padding-left: 16px; padding-right: 16px; }
}
.mm-feature.flip { direction: rtl; }
.mm-feature.flip > * { direction: ltr; }
.mm-feature-wrap { background: var(--bg-l); }
.mm-fs-tag { font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--accent); margin-bottom: 14px; }
.mm-fs-h { font-size: clamp(28px, 3.8vw, 42px); font-weight: 800; letter-spacing: -.03em; line-height: 1.08; color: var(--ink-a); margin-bottom: 18px; }
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
  display: flex; align-items: center; gap: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.07);
}
.mm-pipe-n {
  width: 30px; height: 30px; border-radius: 50%; background: var(--accent);
  color: #fff; font-size: 12px; font-weight: 800; display: grid; place-items: center; flex: none;
}
.mm-pipe-label { font-size: 13.5px; font-weight: 700; color: var(--ink-a); }
.mm-pipe-sub   { font-size: 11.5px; color: var(--ink-c); margin-top: 2px; }

/* AI exchange visual */
.mm-ai-visual { width: 100%; display: flex; flex-direction: column; gap: 12px; }
.mm-ai-bubble {
  background: #fff; border-radius: 14px; padding: 14px 16px;
  box-shadow: 0 2px 10px rgba(0,0,0,.07); font-size: 13px; color: var(--ink-a); line-height: 1.5;
}
.mm-ai-bubble.ai { background: color-mix(in srgb, var(--accent) 8%, white); border-left: 3px solid var(--accent); }
.mm-ai-label { font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--ink-c); margin-bottom: 5px; }

/* Report visual */
.mm-report-visual { width: 100%; display: flex; flex-direction: column; gap: 8px; }
.mm-report-section { background: #fff; border-radius: 10px; padding: 12px 14px; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
.mm-rs-label { font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--accent); margin-bottom: 4px; }
.mm-rs-bar { height: 7px; border-radius: 999px; background: color-mix(in srgb, var(--accent) 18%, white); position: relative; overflow: hidden; }
.mm-rs-bar::after { content: ''; position: absolute; inset: 0 auto 0 0; border-radius: inherit; background: var(--accent); }
.mm-rs-bar.b90::after { width: 90%; } .mm-rs-bar.b75::after { width: 75%; } .mm-rs-bar.b82::after { width: 82%; }

@media (max-width: 760px) {
  .mm-feature, .mm-feature.flip { grid-template-columns: 1fr; direction: ltr; gap: 40px; padding: 60px 24px; }
}

/* ════════════════════════════════════════
   "SOMETHING MATTERS" CTA — the emotional landing
   This is the only place we say it.
════════════════════════════════════════ */
.mm-start {
  background: var(--bg-dk); color: #fff; text-align: center;
  padding-top: 130px; padding-bottom: 130px;
  padding-left:  max(48px, calc(50% - 640px));
  padding-right: max(48px, calc(50% - 640px));
}
@media (max-width: 768px) { .mm-start { padding-left: 24px; padding-right: 24px; } }
@media (max-width: 480px) { .mm-start { padding-left: 16px; padding-right: 16px; } }
.mm-start-quote {
  font-size: clamp(14px, 1.6vw, 17px); font-weight: 300; letter-spacing: .02em;
  color: rgba(255,255,255,.4); line-height: 2; margin-bottom: 40px;
  font-style: italic;
}
.mm-start-h {
  font-size: clamp(38px, 5.5vw, 66px); font-weight: 900;
  letter-spacing: -.04em; line-height: 1.04; margin-bottom: 48px;
}
.mm-start-h em { font-style: normal; color: var(--accent); }
.mm-start-actions { display: flex; align-items: center; gap: 16px; justify-content: center; flex-wrap: wrap; }
.mm-start-btn {
  background: var(--accent); color: #fff;
  font-size: 16px; font-weight: 700; padding: 16px 34px; border-radius: 999px;
  text-decoration: none; transition: opacity .15s, transform .15s;
}
.mm-start-btn:hover { opacity: .88; transform: translateY(-1px); }
.mm-start-ghost {
  font-size: 15px; font-weight: 600; color: rgba(255,255,255,.45);
  text-decoration: none; transition: color .15s;
}
.mm-start-ghost:hover { color: rgba(255,255,255,.8); }

/* ════════════════════════════════════════
   RECENT PROJECTS
════════════════════════════════════════ */
.mm-recent-wrap {
  padding-top: 80px; padding-bottom: 100px;
  padding-left:  max(48px, calc(50% - 640px));
  padding-right: max(48px, calc(50% - 640px));
  background: var(--bg-w); border-top: 1px solid rgba(0,0,0,.06);
}
@media (max-width: 768px) { .mm-recent-wrap { padding-left: 24px; padding-right: 24px; } }
@media (max-width: 480px) { .mm-recent-wrap { padding-left: 16px; padding-right: 16px; } }
.mm-recent-inner { max-width: 1280px; margin: 0 auto; }
.mm-recent-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 24px; }
.mm-recent-h { font-size: 22px; font-weight: 800; color: var(--ink-a); }
.mm-recent-all { font-size: 13px; font-weight: 700; color: var(--accent); text-decoration: none; }
.mm-recent-all:hover { opacity: .75; }
#mmRecentGrid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.mm-proj-card {
  background: #f5f5f7; border-radius: 16px; padding: 20px;
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
  <img src="/MM%20Studio.png" alt="MM Studio" class="mm-hero-logo rv">
  <h1 class="mm-hero-h1 rv rv-d1">
    <span class="thin">The numbers</span><br>
    show what.<br>
    <span class="thin">The words</span><br>
    show why.
  </h1>
  <p class="mm-hero-body rv rv-d2">
    Mixed Methods Studio connects your quantitative results with your qualitative
    responses. Every finding has evidence. Every statistic has a human explanation.
  </p>
  <div class="mm-hero-actions rv rv-d3">
    <a href="/mmstudioV4.php" class="mm-btn-a">Open MM Studio</a>
  </div>
  <div class="mm-scroll-cue rv" style="transition-delay:.5s">Scroll</div>
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
          <div class="mm-eco-desc">Strengthen your survey before you launch. Catch design blind spots before the first response arrives.</div>
          <span class="eco-go">Go to SIRI →</span>
        </a>
      </div>

      <div class="mm-eco-arrow">→</div>

      <div class="mm-eco-card eco-mm current rv rv-d2">
        <div class="mm-eco-ico">⟁</div>
        <div class="mm-eco-name">MM Studio</div>
        <div class="mm-eco-full">Mixed Methods Studio</div>
        <div class="mm-eco-desc">Analyze both strands. Integrate them. Build a credible story from numbers and narratives, in one connected studio.</div>
        <span class="eco-current-badge">You are here</span>
      </div>

      <div class="mm-eco-arrow">→</div>

      <div class="mm-eco-card eco-rssi rv rv-d3">
        <a href="/rssi.php">
          <div class="mm-eco-ico">◎</div>
          <div class="mm-eco-name">RSSI</div>
          <div class="mm-eco-full">ReliCheck Survey Strength Index</div>
          <div class="mm-eco-desc">Once responses are in, evaluate the strength of your evidence: reliability, item performance, and interpretability.</div>
          <span class="eco-go">Go to RSSI →</span>
        </a>
      </div>

    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════
     FEATURE 1 — Know what you have
══════════════════════════════════════════ -->
<div style="background:var(--bg-w)">
<section class="mm-feature">
  <div>
    <div class="mm-fs-tag rv">Step 01</div>
    <h2 class="mm-fs-h rv rv-d1">Know what<br>you have<br><span class="light">before you analyze it.</span></h2>
    <p class="mm-fs-body rv rv-d2">Map your variables. See what the numbers look like before you ask what they mean. Data quality checks surface problems early. Your analysis is built on solid ground, not assumptions.</p>
  </div>
  <div class="mm-visual rv rv-d1">
    <div class="mm-pipe">
      <div class="mm-pipe-step"><span class="mm-pipe-n">01</span><div><div class="mm-pipe-label">Map &amp; Check</div><div class="mm-pipe-sub">Variable types, missing data, quality flags.</div></div></div>
      <div class="mm-pipe-step"><span class="mm-pipe-n">02</span><div><div class="mm-pipe-label">Analyze Both Strands</div><div class="mm-pipe-sub">Statistics and qualitative themes, side by side.</div></div></div>
      <div class="mm-pipe-step"><span class="mm-pipe-n">03</span><div><div class="mm-pipe-label">Integrate</div><div class="mm-pipe-sub">Joint display. Convergence. Meta-inferences.</div></div></div>
      <div class="mm-pipe-step"><span class="mm-pipe-n">04</span><div><div class="mm-pipe-label">Report</div><div class="mm-pipe-sub">Evidence-backed write-up, ready to download.</div></div></div>
    </div>
  </div>
</section>
</div>

<!-- ══════════════════════════════════════════
     FEATURE 2 — The themes no question box could predict
══════════════════════════════════════════ -->
<div class="mm-feature-wrap">
<section class="mm-feature flip">
  <div>
    <div class="mm-fs-tag rv">Step 02</div>
    <h2 class="mm-fs-h rv rv-d1">Surface the themes<br><span class="light">no question box<br>could predict.</span></h2>
    <p class="mm-fs-body rv rv-d2">Open-ended responses hold what your scales couldn't capture. ReliCheck Intelligence helps surface patterns. You read, you code, you decide what's real. The insight is yours.</p>
  </div>
  <div class="mm-visual rv rv-d1">
    <div class="mm-ai-visual">
      <div class="mm-ai-bubble">
        <div class="mm-ai-label">Your respondent</div>
        "I didn't feel like anyone would actually read these answers."
      </div>
      <div class="mm-ai-bubble ai">
        <div class="mm-ai-label">ReliCheck Intelligence</div>
        11 responses use similar language around visibility and being heard. Group this as a theme?
      </div>
      <div class="mm-ai-bubble">
        <div class="mm-ai-label">Your call</div>
        Yes. "Perceived invisibility." Add to codebook.
      </div>
    </div>
  </div>
</section>
</div>

<!-- ══════════════════════════════════════════
     FEATURE 3 — Connect the patterns
══════════════════════════════════════════ -->
<div style="background:var(--bg-w)">
<section class="mm-feature">
  <div>
    <div class="mm-fs-tag rv">Step 03–04</div>
    <h2 class="mm-fs-h rv rv-d1">Connect the patterns.<br><span class="light">Build the argument.<br>Share the finding.</span></h2>
    <p class="mm-fs-body rv rv-d2">Joint displays put your numbers and narratives side by side. Convergence analysis shows where they agree. Meta-inferences give you the conclusion neither strand could reach alone. Then download the report.</p>
  </div>
  <div class="mm-visual rv rv-d1">
    <div class="mm-report-visual">
      <div class="mm-report-section"><div class="mm-rs-label">Theme convergence</div><div class="mm-rs-bar b90"></div></div>
      <div class="mm-report-section"><div class="mm-rs-label">Statistical support</div><div class="mm-rs-bar b75"></div></div>
      <div class="mm-report-section"><div class="mm-rs-label">Evidence strength</div><div class="mm-rs-bar b82"></div></div>
      <div class="mm-report-section" style="background:color-mix(in srgb, var(--accent) 6%, white); border-left:3px solid var(--accent)">
        <div class="mm-rs-label">Report ready</div>
        <div style="font-size:12.5px;color:#1d1d1f;margin-top:4px">Mixed Methods Findings · Word · Markdown</div>
      </div>
    </div>
  </div>
</section>
</div>

<!-- ══════════════════════════════════════════
     THE "SOMETHING MATTERS" MOMENT — end of page, only here
══════════════════════════════════════════ -->
<section class="mm-start">
  <p class="mm-start-quote rv">
    "You didn't collect this data out of habit.<br>
    You collected it because you needed to know.<br>
    You collected it because something needed to change."
  </p>
  <h2 class="mm-start-h rv rv-d1">
    Start because<br><em>something matters.</em>
  </h2>
  <div class="mm-start-actions rv rv-d2">
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
      <span class="mm-recent-h">Pick up where you left off</span>
      <a href="/studio-mm-projects.php" class="mm-recent-all">See all →</a>
    </div>
    <div id="mmRecentGrid"><p class="mm-recent-empty">Loading your recent MM projects…</p></div>
  </div>
</div>

<script>
/* ── Scroll-reveal observer ── */
(function(){
  var obs = new IntersectionObserver(function(entries){
    entries.forEach(function(e){ if(e.isIntersecting){ e.target.classList.add('in'); obs.unobserve(e.target); }});
  }, { threshold: 0.12 });
  document.querySelectorAll('.rv').forEach(function(el){ obs.observe(el); });
})();

/* ── Recent projects ── */
(function(){
  var host = document.getElementById('mmRecentGrid');
  if (!host) return;
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
  fetch('/api/mm/projects.php',{credentials:'same-origin',headers:{Accept:'application/json'}})
    .then(function(r){ return r.ok?r.json():null; })
    .then(function(data){
      var projects=(data&&data.ok&&Array.isArray(data.projects))?data.projects.slice(0,6):[];
      if(!projects.length){
        host.innerHTML='<p class="mm-recent-empty">No MM projects yet. <a href="/mmstudioV4.php" style="color:var(--accent);font-weight:700">open MM Studio</a> to start one.</p>';
        return;
      }
      host.innerHTML=projects.map(function(p){
        return '<a class="mm-proj-card rv in" href="/mmstudioV4.php?project_id='+encodeURIComponent(p.id)+'">'
          +'<span class="mm-proj-label">'+esc(p.pathway||'MM')+'</span>'
          +'<span class="mm-proj-title">'+esc(p.title||'Untitled project')+'</span>'
          +'<span class="mm-proj-meta">Updated '+esc((p.updated_at||'').slice(0,10)||'—')+'</span>'
          +'</a>';
      }).join('');
    })
    .catch(function(){ host.innerHTML='<p class="mm-recent-empty">Could not load recent projects.</p>'; });
})();
</script>

<?php
$landing_tagline = 'Start because something matters.';
include __DIR__ . '/_landing_foot.php';
