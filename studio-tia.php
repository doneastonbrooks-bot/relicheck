<?php
// TIA Studio entry page — RSSI-style landing (no left rail).
// Hero + 4 action tiles + "what TIA helps you do" + recent TIA projects.
// TIA is `dev`; /api/tia/projects.php may not exist yet, so the recent
// strip fails gracefully to an empty state.

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

$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
if ($projectId > 0) {
  $pdo = db();
  try {
    $stmt = $pdo->prepare('SELECT id FROM tia_projects WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
      header('Location: /project-snapshot.php?studio=tia&project_id=' . $projectId);
      exit;
    }
  } catch (Throwable $e) { /* fall through to the landing */ }
}

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

<!-- ===== Hero ===== -->
<section class="lp-hero">
  <div class="eyebrow"><img src="<?= htmlspecialchars($studio['mark']) ?>" alt=""><?= htmlspecialchars($studio['status_label']) ?></div>
  <h1>Understand how your <span class="accent">test items perform.</span></h1>
  <p class="lede">Analyze item difficulty, discrimination, distractors, rubrics, and cognitive demand before making decisions from assessment results.</p>
</section>

<!-- ===== Primary actions ===== -->
<div class="lp-cta-row">
  <a class="lp-cta-tile primary" href="/studio-tia-projects.php">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Open TIA Studio</div><div class="lp-cta-sub">Pick a test and dive in</div></span>
  </a>
  <a class="lp-cta-tile" href="/tia-wizard.php?step=1">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Upload test data</div><div class="lp-cta-sub">Scores, totals, and answer key</div></span>
  </a>
  <a class="lp-cta-tile" href="/studio-tia-projects.php?demo=1">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Try a sample</div><div class="lp-cta-sub">Read-only walk-through</div></span>
  </a>
</div>

<!-- ===== Sample workflow ===== -->
<section class="lp-section">
  <div class="lp-eyebrow-c">How it works</div>
  <div class="lp-flow-card">
    <div class="lp-flow">
      <div class="lp-flow-step"><div class="n">1</div><h4>Load the test</h4><p>Bring in item-level scores, totals, and the answer key.</p></div>
      <div class="lp-flow-step"><div class="n">2</div><h4>Item analysis</h4><p>See difficulty and discrimination for every item.</p></div>
      <div class="lp-flow-step"><div class="n">3</div><h4>Distractors and rubrics</h4><p>Check which options work and bring rubric-scored items in.</p></div>
      <div class="lp-flow-step"><div class="n">4</div><h4>Decide</h4><p>Keep, revise, or retire items before you act on results.</p></div>
    </div>
  </div>
</section>

<!-- ===== What TIA Studio helps you do ===== -->
<section class="lp-section">
  <div class="lp-section-head" style="text-align:center;"><h2>What TIA Studio helps you do</h2></div>
  <div class="lp-features">
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="10" width="3" height="7"/><rect x="12" y="6" width="3" height="11"/><rect x="17" y="13" width="3" height="4"/></svg></div>
      <h3>Difficulty and discrimination</h3>
      <p>See how hard each item is and how well it separates stronger from weaker test-takers.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/></svg></div>
      <h3>Distractors and answer keys</h3>
      <p>Check which options pull responses, validate the key, and flag items that are not working.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a7 7 0 0 0-4 12.7V17a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2v-2.3A7 7 0 0 0 12 2z"/><path d="M9 22h6"/></svg></div>
      <h3>Rubrics and cognitive demand</h3>
      <p>Bring rubric-scored items and cognitive demand into the same analysis as your multiple-choice items.</p>
    </div>
  </div>
</section>

<!-- ===== Recent TIA projects ===== -->
<section class="lp-section" style="max-width:940px;">
  <div class="lp-section-head" style="display:flex;justify-content:space-between;align-items:baseline;">
    <h2>Recent TIA projects</h2>
    <a href="/studio-tia-projects.php" style="text-decoration:none;color:var(--accent);font-weight:600;font-size:13px;">See all →</a>
  </div>
  <div class="lp-recent-grid" id="tiaRecent"><p class="lp-recent-loading">Loading your recent TIA projects…</p></div>
</section>

<script>
  (function () {
    const host = document.getElementById('tiaRecent');
    if (!host) return;
    fetch('/api/tia/projects.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        const list = (data && data.ok && Array.isArray(data.projects)) ? data.projects.slice(0, 6) : [];
        if (!list.length) { host.innerHTML = '<p class="lp-recent-empty">No TIA projects yet. Click <strong>Upload test data</strong> above to start one.</p>'; return; }
        host.innerHTML = list.map(function (p) {
          return '<a class="lp-recent-card" href="/studio-tia.php?project_id=' + encodeURIComponent(p.id) + '">' +
                   '<span class="stripe" aria-hidden="true"></span>' +
                   '<div class="r-title">' + esc(p.title || 'Untitled test') + '</div>' +
                   '<div class="r-meta">Updated ' + esc((p.updated_at || '').slice(0,10) || '—') + '</div>' +
                 '</a>';
        }).join('');
      })
      .catch(function () { host.innerHTML = '<p class="lp-recent-empty">No TIA projects yet. Click <strong>Upload test data</strong> above to start one.</p>'; });
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }
  })();
</script>

<?php
$landing_tagline = 'Know which items earn their place on the test.';
include __DIR__ . '/_landing_foot.php';
