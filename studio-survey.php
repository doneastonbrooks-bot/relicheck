<?php
// Survey Studio entry page (v4) — Step 2 of the user flow.
// -------------------------------------------------------------------
// Platform Shell only — no Studio Template, no left rail.
// Studio-themed hero (Survey orange/red), tiles, recent surveys strip.
// Per [[relicheck-studio-landing-pattern]].

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/studio-survey.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

// Bookmark / deep-link → skip hero, jump to Overview
$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
if ($projectId > 0) {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id FROM surveys WHERE id = :id AND owner_id = :uid AND (archived_at IS NULL)');
  $stmt->execute([':id' => $projectId, ':uid' => $uid]);
  if ($stmt->fetch(PDO::FETCH_ASSOC)) {
    header('Location: /project-snapshot.php?studio=survey&project_id=' . $projectId);
    exit;
  }
}

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['survey'];

$user_full  = $user['name'] ?? $user['email'] ?? 'You';
$initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$shell_page_title    = 'Survey Studio — ReliCheck';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = 'No project open';
$shell_body_attrs    = 'data-current-studio="survey" data-studio-landing="survey"';

include __DIR__ . '/_platform_shell_header.php';
?>

<style>
  :root {
    --landing-accent:      <?= htmlspecialchars($studio['accent']) ?>;
    --landing-accent-soft: <?= htmlspecialchars($studio['accent_soft']) ?>;
  }
  .studio-hero { position: relative; padding: 56px 0 36px; overflow: hidden; }
  .studio-hero::before { content: ""; position: absolute; top: -120px; right: -160px; width: 540px; height: 540px; background: radial-gradient(closest-side, var(--landing-accent-soft), transparent 70%); border-radius: 50%; z-index: 0; pointer-events: none; }
  .studio-hero > * { position: relative; z-index: 1; }
  .studio-back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ink-4, #5a657a); text-decoration: none; margin-bottom: 20px; }
  .studio-back-link:hover { color: var(--ink-2, #1c2238); }
  .studio-hero-eyebrow { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; background: var(--landing-accent-soft); color: var(--landing-accent); border-radius: 999px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 16px; }
  .studio-hero-eyebrow img { width: 18px; height: 18px; border-radius: 4px; }
  .studio-hero h1 { font-family: 'Fraunces', 'Georgia', serif; font-size: 48px; line-height: 1.08; font-weight: 600; color: var(--ink-1, #1c2238); margin: 0 0 14px; max-width: 760px; }
  .studio-hero h1 em { font-style: italic; color: var(--landing-accent); font-weight: 500; }
  .studio-hero .lede { font-size: 17px; line-height: 1.55; color: var(--ink-3, #424a5e); max-width: 620px; margin: 0; }
  .studio-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; margin-top: 36px; }
  .studio-tile-card { display: flex; flex-direction: column; gap: 8px; padding: 24px; background: #fff; border: 1px solid rgba(15,23,42,0.08); border-radius: 26px; box-shadow: 0 4px 12px rgba(15,23,42,0.10); text-decoration: none; color: inherit; transition: border-color 0.15s var(--ease), transform 0.15s var(--ease), box-shadow 0.15s var(--ease); min-height: 150px; }
  .studio-tile-card:hover { border-color: var(--landing-accent); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15,23,42,0.16); }
  .studio-tile-card .icon { width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; background: var(--landing-accent-soft); color: var(--landing-accent); border-radius: 10px; margin-bottom: 6px; }
  .studio-tile-card .tile-title { font-family: Inter, -apple-system, BlinkMacSystemFont, "SF Pro Text", sans-serif; font-size: 22px; font-weight: 700; line-height: 1.25; letter-spacing: -0.025em; color: var(--ink-1, #1c2238); }
  .studio-tile-card .tile-desc { font-size: 14px; line-height: 1.55; color: var(--ink-4, #5a657a); }
  .studio-tile-card.is-primary { background: var(--ink-1, #1c2238); border-color: var(--ink-1, #1c2238); }
  .studio-tile-card.is-primary .tile-title { color: #fff; }
  .studio-tile-card.is-primary .tile-desc  { color: rgba(255,255,255,0.7); }
  .studio-tile-card.is-primary .icon { background: rgba(255,255,255,0.12); color: #fff; }
  .studio-tile-card.is-primary:hover { border-color: var(--landing-accent); transform: translateY(-2px); }
  .studio-section { margin-top: 48px; padding-top: 28px; border-top: 1px solid var(--line); }
  .studio-section-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 14px; }
  .studio-section-head h2 { font-family: 'Fraunces', 'Georgia', serif; font-size: 22px; font-weight: 600; margin: 0; color: var(--ink-1, #1c2238); }
  .studio-section-head .head-meta { font-size: 13px; color: var(--ink-4, #5a657a); }
  .recent-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
  .recent-empty, .recent-loading, .recent-error { grid-column: 1 / -1; padding: 18px; background: #fff; border: 1px dashed var(--line); border-radius: 12px; color: var(--ink-4); text-align: center; font-size: 14px; }
  .recent-error { color: #c2492f; border-color: #f3d8c0; background: #fff5f3; }
  .recent-card { position: relative; display: flex; flex-direction: column; gap: 6px; padding: 16px 18px; background: #fff; border: 1px solid var(--line); border-radius: 12px; text-decoration: none; color: inherit; transition: border-color 0.15s var(--ease), transform 0.15s var(--ease); }
  .recent-card:hover { border-color: var(--landing-accent); transform: translateY(-1px); }
  .recent-card .stripe { position: absolute; top: 0; left: 0; width: 3px; height: 100%; background: var(--landing-accent); border-radius: 12px 0 0 12px; }
  .recent-card .pathway { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-5); }
  .recent-card .title { font-family: 'Fraunces', 'Georgia', serif; font-size: 16.5px; font-weight: 600; color: var(--ink-1, #1c2238); line-height: 1.3; }
  .recent-card .meta { font-size: 12.5px; color: var(--ink-5); font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace; }
</style>

<section class="studio-hero">
  <a class="studio-back-link" href="/app-2026v4.php">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    All studios
  </a>
  <div class="studio-hero-eyebrow">
    <img src="<?= htmlspecialchars($studio['mark']) ?>" alt="">
    <?= htmlspecialchars($studio['name']) ?> · <?= htmlspecialchars($studio['status_label']) ?>
  </div>
  <h1>One survey, <em>one strong report</em>.</h1>
  <p class="lede"><?= htmlspecialchars($studio['description']) ?></p>
</section>

<section>
  <div class="studio-tiles">
    <!--
      Three create-a-survey paths. Update the href values to point at your
      actual builder / AI / template URLs once those are live; placeholders
      below route to the wizard so the tiles still go somewhere usable.
    -->
    <a class="studio-tile-card" href="/survey-builder.php">
      <span class="icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span>
      <div class="tile-title">Create a new survey</div>
      <div class="tile-desc">Open the survey builder. Start with a blank canvas and add items, scales, and logic from the ground up.</div>
    </a>
    <a class="studio-tile-card" href="/studio-survey-projects.php">
      <span class="icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg></span>
      <div class="tile-title">Recent Surveys</div>
      <div class="tile-desc">Open one of your surveys and jump straight to its Overview.</div>
    </a>
    <a class="studio-tile-card" href="/studio-survey-projects.php?demo=1">
      <span class="icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
      <div class="tile-title">Try a sample</div>
      <div class="tile-desc"><?= htmlspecialchars($studio['sample']['project']) ?>. Read-only walk-through of the Strength Index and every Survey analysis.</div>
    </a>
    <a class="studio-tile-card" href="/methodology-survey.php">
      <span class="icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></span>
      <div class="tile-title">How Survey works</div>
      <div class="tile-desc">Reliability, validity, factor structure, response quality, open-ended fit. One score, five checks.</div>
    </a>
  </div>
</section>

<section class="studio-section" aria-labelledby="recent-title">
  <div class="studio-section-head">
    <h2 id="recent-title">Recent surveys</h2>
    <a class="head-meta" href="/studio-survey-projects.php" style="text-decoration:none;color:var(--landing-accent);font-weight:600;">See all →</a>
  </div>
  <div class="recent-grid" id="svRecent">
    <p class="recent-loading">Loading your recent surveys…</p>
  </div>
</section>

<script>
  (function () {
    const host = document.getElementById('svRecent');
    if (!host) return;
    fetch('/api/surveys/list.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        const list = (data && data.ok && Array.isArray(data.surveys)) ? data.surveys.slice(0, 6) : [];
        if (!list.length) {
          host.innerHTML = '<p class="recent-empty">No surveys yet. Start one above — <strong>from scratch</strong>, with <strong>ReliCheck Intelligence</strong>, or from the <strong>template suite</strong>.</p>';
          return;
        }
        host.innerHTML = list.map(function (s) {
          const status = s.is_published ? 'Published' : 'Draft';
          return '<a class="recent-card" href="/studio-survey.php?project_id=' + encodeURIComponent(s.id) + '">' +
                   '<span class="stripe" aria-hidden="true"></span>' +
                   '<div class="pathway">' + esc(status) + '</div>' +
                   '<div class="title">' + esc(s.title || 'Untitled survey') + '</div>' +
                   '<div class="meta">' + (s.response_count || 0) + ' responses · ' + esc((s.updated_at || '').slice(0,10) || '—') + '</div>' +
                 '</a>';
        }).join('');
      })
      .catch(function () { host.innerHTML = '<p class="recent-error">Could not load your recent surveys.</p>'; });
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }
  })();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
