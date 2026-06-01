<?php
// MM Studio entry page — RSSI-style landing (no left rail).
// Hero + 4 action tiles + "what MM helps you do" + recent MM projects.
//
// User flow:
//   1. /app-2026v4.php          hub
//   2. /studio-mm.php           ← this file (landing)
//   3. /studio-mm-projects.php  project picker  (or /mm-wizard.php to upload)
//   4. /project-snapshot.php?studio=mm&project_id=N  Overview
//
// Deep link: ?project_id=N (a valid owned project) jumps straight to Step 4.

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

// Bookmark / deep-link: project_id set → skip the landing.
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

<!-- ===== Hero ===== -->
<section class="lp-hero">
  <div class="eyebrow"><img src="<?= htmlspecialchars($studio['mark']) ?>" alt=""><?= htmlspecialchars($studio['status_label']) ?></div>
  <h1>Connect numbers, narratives, <span class="accent">and meaning.</span></h1>
  <p class="lede">Use mixed-methods evidence to connect quantitative results with qualitative themes, group differences, and explanatory patterns.</p>
</section>

<!-- ===== Primary actions ===== -->
<div class="lp-cta-row">
  <a class="lp-cta-tile primary" href="/studio-mm-projects.php">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Open MM Studio</div><div class="lp-cta-sub">Pick a project and dive in</div></span>
  </a>
  <a class="lp-cta-tile" href="/mm-wizard.php?step=1">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Upload data</div><div class="lp-cta-sub">Survey plus interviews into one project</div></span>
  </a>
  <a class="lp-cta-tile" href="/mm-wizard.php?step=1&amp;demo=1">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Try a sample</div><div class="lp-cta-sub">Read-only walk-through</div></span>
  </a>
</div>

<!-- ===== Sample workflow ===== -->
<section class="lp-section">
  <div class="lp-eyebrow-c">How it works</div>
  <div class="lp-flow-card">
    <div class="lp-flow">
      <div class="lp-flow-step"><div class="n">1</div><h4>Bring in evidence</h4><p>Pair survey scales with interviews or open-ended responses in one project.</p></div>
      <div class="lp-flow-step"><div class="n">2</div><h4>Build themes</h4><p>Code qualitative responses into themes you can compare.</p></div>
      <div class="lp-flow-step"><div class="n">3</div><h4>Compare groups</h4><p>See how groups differ across both kinds of evidence.</p></div>
      <div class="lp-flow-step"><div class="n">4</div><h4>Joint display</h4><p>Produce a mixed-methods display that ties numbers to narrative.</p></div>
    </div>
  </div>
</section>

<!-- ===== What MM Studio helps you do ===== -->
<section class="lp-section">
  <div class="lp-section-head" style="text-align:center;"><h2>What MM Studio helps you do</h2></div>
  <div class="lp-features">
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg></div>
      <h3>Connect the evidence</h3>
      <p>Pair quantitative scales with qualitative themes so the numbers and the narrative explain each other.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v4H4z"/><path d="M4 12h10v8H4z"/><path d="M18 12h2v8h-2z"/></svg></div>
      <h3>Build themes and compare groups</h3>
      <p>Code open-ended responses into themes, then compare how groups differ across both kinds of evidence.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></div>
      <h3>Create joint displays</h3>
      <p>Produce mixed-methods displays that put findings side by side for a report you can defend.</p>
    </div>
  </div>
</section>

<!-- ===== Recent MM projects ===== -->
<section class="lp-section" style="max-width:940px;">
  <div class="lp-section-head" style="display:flex;justify-content:space-between;align-items:baseline;">
    <h2>Recent MM projects</h2>
    <a href="/studio-mm-projects.php" style="text-decoration:none;color:var(--accent);font-weight:600;font-size:13px;">See all →</a>
  </div>
  <div class="lp-recent-grid" id="mmRecent"><p class="lp-recent-loading">Loading your recent MM projects…</p></div>
</section>

<script>
  (function () {
    const host = document.getElementById('mmRecent');
    if (!host) return;
    fetch('/api/mm/projects.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        const projects = (data && data.ok && Array.isArray(data.projects)) ? data.projects.slice(0, 6) : [];
        if (!projects.length) { host.innerHTML = '<p class="lp-recent-empty">No MM projects yet. Click <strong>Upload data</strong> above to start one.</p>'; return; }
        host.innerHTML = projects.map(function (p) {
          return '<a class="lp-recent-card" href="/studio-mm.php?project_id=' + encodeURIComponent(p.id) + '">' +
                   '<span class="stripe" aria-hidden="true"></span>' +
                   '<div class="r-label">' + esc(p.pathway || 'MM') + '</div>' +
                   '<div class="r-title">' + esc(p.title || 'Untitled project') + '</div>' +
                   '<div class="r-meta">Updated ' + esc((p.updated_at || '').slice(0, 10) || '—') + '</div>' +
                 '</a>';
        }).join('');
      })
      .catch(function () { host.innerHTML = '<p class="lp-recent-error">Could not load your recent MM projects.</p>'; });
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }
  })();
</script>

<?php
$landing_tagline = 'Two kinds of evidence, one credible story.';
include __DIR__ . '/_landing_foot.php';
