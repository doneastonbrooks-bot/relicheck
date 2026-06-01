<?php
// Inferential Statistics Studio — LANDING page (mirrors the studio landing
// design, like studio-tia.php / studio-mm.php). CTA tiles route into the
// workspace (/inferential-statistics-workspace.php). Renders no analysis and
// no live Variables & Fit panel. See [[project_studio_architecture]].

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/inferential-statistics-studio.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['inferential'];

$user_full = $user['name'] ?? $user['email'] ?? 'You';
$initials  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$landing_title         = 'Inferential Statistics Studio — ReliCheck';
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
  <h1>Test <span class="accent">what your data can support.</span></h1>
  <p class="lede">Run t-tests, ANOVA, chi-square, correlation, regression, effect sizes, and assumption checks — with a Variables &amp; Fit panel that helps you choose the right analysis responsibly.</p>
</section>

<!-- ===== Primary actions ===== -->
<div class="lp-cta-row">
  <a class="lp-cta-tile primary" href="#" id="isOpenSiri">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Open from SIRI responses</div><div class="lp-cta-sub">Analyze a published survey</div></span>
  </a>
  <a class="lp-cta-tile" href="/analysis-upload-wizard.php?studio=inferential">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Upload data</div><div class="lp-cta-sub">Bring a CSV or spreadsheet</div></span>
  </a>
  <a class="lp-cta-tile" href="#" id="isOpenProject">
    <span class="lp-cta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></span>
    <span class="lp-cta-text"><div class="lp-cta-title">Open saved project</div><div class="lp-cta-sub">Pick one of your projects</div></span>
  </a>
</div>
<p style="text-align:center;font-size:13px;color:var(--text-2,#5a657a);margin:14px auto 0;max-width:660px;">
  Full descriptive analysis lives in the <a href="/descriptive-analysis-studio.php" style="color:var(--accent);font-weight:600;text-decoration:none;">Descriptive Analysis Studio</a>.
  Reliability and strength evidence live in <a href="/rssi.php" style="color:var(--accent);font-weight:600;text-decoration:none;">RSSI</a> — not here.
</p>

<!-- ===== How it works ===== -->
<section class="lp-section">
  <div class="lp-eyebrow-c">How it works</div>
  <div class="lp-flow-card">
    <div class="lp-flow">
      <div class="lp-flow-step"><div class="n">1</div><h4>Open your data</h4><p>From SIRI responses, an upload, or a saved project.</p></div>
      <div class="lp-flow-step"><div class="n">2</div><h4>Check Variables &amp; Fit</h4><p>See variable types, valid n, missingness, and group sizes before you choose a test.</p></div>
      <div class="lp-flow-step"><div class="n">3</div><h4>Run the test</h4><p>t-tests, ANOVA, chi-square, correlation, or regression.</p></div>
      <div class="lp-flow-step"><div class="n">4</div><h4>Interpret</h4><p>Effect sizes, confidence intervals, and assumption checks.</p></div>
    </div>
  </div>
</section>

<!-- ===== What Inferential Statistics Studio helps you do ===== -->
<section class="lp-section">
  <div class="lp-section-head" style="text-align:center;"><h2>What Inferential Statistics Studio helps you do</h2></div>
  <div class="lp-features">
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/></svg></div>
      <h3>Variables &amp; Fit</h3>
      <p>Understand your variables — types, valid n, missingness, distributions, group sizes — so you never pick a test blindly.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="M9 6h6a3 3 0 0 1 3 3v6"/></svg></div>
      <h3>Tests, models, and comparisons</h3>
      <p>t-tests, ANOVA, chi-square, correlation, and regression for differences and relationships.</p>
    </div>
    <div class="lp-feature">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a7 7 0 0 0-4 12.7V17a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2v-2.3A7 7 0 0 0 12 2z"/><path d="M9 22h6"/></svg></div>
      <h3>Effect sizes and assumptions</h3>
      <p>Back every result with effect sizes, confidence intervals, and assumption checks.</p>
    </div>
  </div>
</section>

<script>
(function () {
  var WORKSPACE = '/inferential-statistics-workspace.php';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }
  function picker(opts) {
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px;';
    var p = document.createElement('div');
    p.style.cssText = 'background:#fff;border-radius:16px;max-width:520px;width:100%;max-height:72vh;overflow:auto;box-shadow:0 20px 60px rgba(15,23,42,0.3);padding:22px;font-family:Inter,system-ui,sans-serif;';
    p.innerHTML = '<h3 style="font-size:19px;font-weight:700;margin:0 0 4px;">' + opts.title + '</h3><p style="font-size:13.5px;color:#5a657a;margin:0 0 16px;">' + opts.sub + '</p><div id="isList" style="display:flex;flex-direction:column;gap:8px;"><p style="color:#7a8499;font-size:14px;">Loading…</p></div><button id="isClose" style="margin-top:16px;background:none;border:none;color:#5a657a;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>';
    ov.appendChild(p); document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    p.querySelector('#isClose').addEventListener('click', function () { ov.remove(); });
    fetch('/api/dev/project-list.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        var list = (data && data.ok && Array.isArray(data.projects)) ? data.projects : [];
        if (opts.publishedOnly) list = list.filter(function (s) { return s.status === 'published'; });
        var host = p.querySelector('#isList');
        if (!list.length) { host.innerHTML = '<p style="color:#7a8499;font-size:14px;">' + opts.empty + '</p>'; return; }
        host.innerHTML = list.map(function (s) {
          var rc = s.response_count || 0;
          return '<a href="' + WORKSPACE + '?project_id=' + encodeURIComponent(s.id) + '&source=' + opts.source + '&kind=siri" style="display:block;padding:12px 14px;border:1px solid #e6e9f0;border-radius:10px;text-decoration:none;color:#1c2238;">'
            + '<div style="font-weight:700;font-size:14.5px;">' + esc(s.title || 'Untitled project') + '</div>'
            + '<div style="font-size:12.5px;color:#7a8499;margin-top:2px;">' + rc + ' response' + (rc !== 1 ? 's' : '') + (s.status === 'published' ? ' · Published' : ' · Draft') + '</div></a>';
        }).join('');
      })
      .catch(function () { p.querySelector('#isList').innerHTML = '<p style="color:#c2492f;font-size:14px;">Could not load your projects.</p>'; });
  }
  var a = document.getElementById('isOpenSiri'); if (a) a.addEventListener('click', function (e) { e.preventDefault(); picker({ title: 'Open from SIRI responses', sub: 'Pick a published survey to analyze its responses.', source: 'siri', publishedOnly: true, empty: 'No published surveys yet. Create and publish one in SIRI first.' }); });
  var b = document.getElementById('isOpenProject'); if (b) b.addEventListener('click', function (e) { e.preventDefault(); picker({ title: 'Open saved project', sub: 'Pick any of your survey projects.', source: 'project', publishedOnly: false, empty: 'No saved projects yet.' }); });
})();
</script>

<?php
$landing_tagline = 'Know what your data can responsibly support.';
include __DIR__ . '/_landing_foot.php';
