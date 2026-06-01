<?php
// 360 Studio entry page — RSSI-style landing (no left rail).
// Hero + 4 action tiles + "what 360 helps you do" + recent panels.

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

$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
if ($projectId > 0) {
  $pdo = db();
  try {
    $stmt = $pdo->prepare('SELECT id FROM survey_360_panels WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
      header('Location: /project-snapshot.php?studio=360&project_id=' . $projectId);
      exit;
    }
  } catch (Throwable $e) { /* fall through to the landing */ }
}

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

<!-- ===== Hero ===== -->
<section class="lp-hero">
  <div class="eyebrow"><img src="<?= htmlspecialchars($studio['mark']) ?>" alt=""><?= htmlspecialchars($studio['status_label']) ?></div>
  <h1>See feedback from <span class="accent">every angle.</span></h1>
  <p class="lede">Analyze self, peer, manager, and direct-report feedback to identify perception gaps, growth signals, and leadership patterns.</p>
</section>

<!-- ===== Primary actions ===== -->
<div class="lp-cta-row">
  <a class="lp-cta-tile primary" href="/studio-360-projects.php">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Open 360 Studio</div><div class="lp-cta-sub">Pick a panel and dive in</div></span>
  </a>
  <a class="lp-cta-tile" href="/360-wizard.php?step=1">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">New 360 panel</div><div class="lp-cta-sub">Survey, raters, and confidentiality</div></span>
  </a>
  <a class="lp-cta-tile" href="/studio-360-projects.php?demo=1">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Try a sample</div><div class="lp-cta-sub">Read-only walk-through</div></span>
  </a>
</div>

<!-- ===== Sample workflow ===== -->
<section class="lp-section">
  <div class="lp-eyebrow-c">How it works</div>
  <div class="lp-flow-card">
    <div class="lp-flow">
      <div class="lp-flow-step"><div class="n">1</div><h4>Build the panel</h4><p>Pick a survey and add ratees with their self, peer, manager, and report raters.</p></div>
      <div class="lp-flow-step"><div class="n">2</div><h4>Collect ratings</h4><p>Gather feedback with confidentiality thresholds that protect raters.</p></div>
      <div class="lp-flow-step"><div class="n">3</div><h4>Perception gaps</h4><p>Compare how each person is seen across rater groups.</p></div>
      <div class="lp-flow-step"><div class="n">4</div><h4>Growth signals</h4><p>Surface blind spots, hidden strengths, and leadership patterns.</p></div>
    </div>
  </div>
</section>

<!-- ===== What 360 Studio helps you do ===== -->
<section class="lp-section">
  <div class="lp-section-head" style="text-align:center;"><h2>What 360 Studio helps you do</h2></div>
  <div class="lp-features">
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 21v-2a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v2"/><path d="M16 14a4 4 0 0 1 5 4v3"/></svg></div>
      <h3>Compare rater perspectives</h3>
      <p>See how self, peer, manager, and direct-report ratings line up, and where they diverge.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg></div>
      <h3>Surface perception gaps</h3>
      <p>Highlight blind spots and hidden strengths by comparing how a person sees themselves versus how others rate them.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.4 7.4H22l-6 4.5 2.3 7.1L12 16.8 5.7 21l2.3-7.1-6-4.5h7.6z"/></svg></div>
      <h3>Build competency profiles</h3>
      <p>Turn multi-rater feedback into clear competency profiles and growth signals, with rater confidentiality protected.</p>
    </div>
  </div>
</section>

<!-- ===== Recent 360 panels ===== -->
<section class="lp-section" style="max-width:940px;">
  <div class="lp-section-head" style="display:flex;justify-content:space-between;align-items:baseline;">
    <h2>Recent 360 panels</h2>
    <a href="/studio-360-projects.php" style="text-decoration:none;color:var(--accent);font-weight:600;font-size:13px;">See all →</a>
  </div>
  <div class="lp-recent-grid" id="t6Recent"><p class="lp-recent-loading">Loading your recent 360 panels…</p></div>
</section>

<script>
  (function () {
    const host = document.getElementById('t6Recent');
    if (!host) return;
    fetch('/api/panels/list.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        const list = (data && data.ok && Array.isArray(data.panels)) ? data.panels.slice(0, 6) : [];
        if (!list.length) { host.innerHTML = '<p class="lp-recent-empty">No 360 panels yet. Click <strong>New 360 panel</strong> above to start one.</p>'; return; }
        host.innerHTML = list.map(function (p) {
          return '<a class="lp-recent-card" href="/studio-360.php?project_id=' + encodeURIComponent(p.id) + '">' +
                   '<span class="stripe" aria-hidden="true"></span>' +
                   '<div class="r-title">' + esc(p.title || p.name || 'Untitled panel') + '</div>' +
                   '<div class="r-meta">Updated ' + esc((p.updated_at || '').slice(0,10) || '—') + '</div>' +
                 '</a>';
        }).join('');
      })
      .catch(function () { host.innerHTML = '<p class="lp-recent-empty">No 360 panels yet. Click <strong>New 360 panel</strong> above to start one.</p>'; });
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }
  })();
</script>

<?php
$landing_tagline = 'Four perspectives, one honest picture.';
include __DIR__ . '/_landing_foot.php';
