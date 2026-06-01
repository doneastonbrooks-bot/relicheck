<?php
// Descriptive Analysis Studio — LANDING page (mirrors the studio landing
// design: _landing_head + hero + CTA tiles + how-it-works + features +
// _landing_foot, exactly like studio-tia.php / studio-mm.php). CTA tiles route
// into the workspace (/descriptive-analysis-workspace.php). Renders no analysis.
// See [[project_studio_architecture]].

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

<!-- ===== Hero ===== -->
<section class="lp-hero">
  <div class="eyebrow"><img src="<?= htmlspecialchars($studio['mark']) ?>" alt=""><?= htmlspecialchars($studio['status_label']) ?></div>
  <h1>Summarize <span class="accent">what is in your data.</span></h1>
  <p class="lede">Frequencies, distributions, group summaries, cross-tabs, and item rankings — a clear, honest picture of what your responses show, before you make any claims.</p>
</section>

<!-- ===== Primary actions ===== -->
<div class="lp-cta-row">
  <a class="lp-cta-tile primary" href="#" id="dsOpenSiri">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Open from SIRI responses</div><div class="lp-cta-sub">Analyze a published survey</div></span>
  </a>
  <a class="lp-cta-tile" href="/analysis-upload-wizard.php?studio=descriptive">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Upload data</div><div class="lp-cta-sub">Bring a CSV or spreadsheet</div></span>
  </a>
  <a class="lp-cta-tile" href="#" id="dsOpenProject">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Open saved project</div><div class="lp-cta-sub">Pick one of your projects</div></span>
  </a>
</div>
<p style="text-align:center;font-size:13px;color:var(--text-2,#5a657a);margin:14px auto 0;max-width:640px;">
  Need to build or deploy a survey? Start in <a href="/survey-dev.php" style="color:var(--accent);font-weight:600;text-decoration:none;">SIRI</a>.
  Reliability and strength evidence live in <a href="/rssi.php" style="color:var(--accent);font-weight:600;text-decoration:none;">RSSI</a> — not here.
</p>

<!-- ===== How it works ===== -->
<section class="lp-section">
  <div class="lp-eyebrow-c">How it works</div>
  <div class="lp-flow-card">
    <div class="lp-flow">
      <div class="lp-flow-step"><div class="n">1</div><h4>Open your data</h4><p>From SIRI responses, an upload, or a saved project.</p></div>
      <div class="lp-flow-step"><div class="n">2</div><h4>Summarize</h4><p>Frequencies, means, medians, standard deviations, and distributions.</p></div>
      <div class="lp-flow-step"><div class="n">3</div><h4>Compare</h4><p>Cross-tabs and group summaries to see what differs across groups.</p></div>
      <div class="lp-flow-step"><div class="n">4</div><h4>Read the picture</h4><p>Top and bottom items and scale-score summaries.</p></div>
    </div>
  </div>
</section>

<!-- ===== What Descriptive Analysis Studio helps you do ===== -->
<section class="lp-section">
  <div class="lp-section-head" style="text-align:center;"><h2>What Descriptive Analysis Studio helps you do</h2></div>
  <div class="lp-features">
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="10" width="3" height="7"/><rect x="12" y="6" width="3" height="11"/><rect x="17" y="13" width="3" height="4"/></svg></div>
      <h3>Frequencies and distributions</h3>
      <p>Counts, percentages, means, medians, standard deviations, and shape for every variable.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg></div>
      <h3>Cross-tabs and group summaries</h3>
      <p>See how responses break down across groups, side by side.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="14" y2="12"/><line x1="4" y1="18" x2="9" y2="18"/></svg></div>
      <h3>Item rankings and scale summaries</h3>
      <p>Rank items by mean and summarize scale scores — without computing reliability (that lives in RSSI).</p>
    </div>
  </div>
</section>

<script>
(function () {
  var WORKSPACE = '/descriptive-analysis-workspace.php';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }
  function picker(opts) {
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px;';
    var p = document.createElement('div');
    p.style.cssText = 'background:#fff;border-radius:16px;max-width:520px;width:100%;max-height:72vh;overflow:auto;box-shadow:0 20px 60px rgba(15,23,42,0.3);padding:22px;font-family:Inter,system-ui,sans-serif;';
    p.innerHTML = '<h3 style="font-size:19px;font-weight:700;margin:0 0 4px;">' + opts.title + '</h3><p style="font-size:13.5px;color:#5a657a;margin:0 0 16px;">' + opts.sub + '</p><div id="dsList" style="display:flex;flex-direction:column;gap:8px;"><p style="color:#7a8499;font-size:14px;">Loading…</p></div><button id="dsClose" style="margin-top:16px;background:none;border:none;color:#5a657a;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>';
    ov.appendChild(p); document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    p.querySelector('#dsClose').addEventListener('click', function () { ov.remove(); });
    fetch('/api/dev/project-list.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        var list = (data && data.ok && Array.isArray(data.projects)) ? data.projects : [];
        if (opts.publishedOnly) list = list.filter(function (s) { return s.status === 'published'; });
        var host = p.querySelector('#dsList');
        if (!list.length) { host.innerHTML = '<p style="color:#7a8499;font-size:14px;">' + opts.empty + '</p>'; return; }
        host.innerHTML = list.map(function (s) {
          var rc = s.response_count || 0;
          return '<a href="' + WORKSPACE + '?project_id=' + encodeURIComponent(s.id) + '&source=' + opts.source + '&kind=siri" style="display:block;padding:12px 14px;border:1px solid #e6e9f0;border-radius:10px;text-decoration:none;color:#1c2238;">'
            + '<div style="font-weight:700;font-size:14.5px;">' + esc(s.title || 'Untitled project') + '</div>'
            + '<div style="font-size:12.5px;color:#7a8499;margin-top:2px;">' + rc + ' response' + (rc !== 1 ? 's' : '') + (s.status === 'published' ? ' · Published' : ' · Draft') + '</div></a>';
        }).join('');
      })
      .catch(function () { p.querySelector('#dsList').innerHTML = '<p style="color:#c2492f;font-size:14px;">Could not load your projects.</p>'; });
  }
  var a = document.getElementById('dsOpenSiri'); if (a) a.addEventListener('click', function (e) { e.preventDefault(); picker({ title: 'Open from SIRI responses', sub: 'Pick a published survey to analyze its responses.', source: 'siri', publishedOnly: true, empty: 'No published surveys yet. Create and publish one in SIRI first.' }); });
  var b = document.getElementById('dsOpenProject'); if (b) b.addEventListener('click', function (e) { e.preventDefault(); picker({ title: 'Open saved project', sub: 'Pick any of your survey projects.', source: 'project', publishedOnly: false, empty: 'No saved projects yet.' }); });
})();
</script>

<?php
$landing_tagline = 'See clearly what is present in your data.';
include __DIR__ . '/_landing_foot.php';
