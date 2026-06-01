<?php
// Shared landing page for the two analysis destinations. A studio landing
// page (e.g. /descriptive-analysis-studio.php) sets $studio_def to a row from
// _analysis_studio_defs.php and includes this file. It explains the studio,
// then offers the four start CTAs that route INTO the workspace (or to SIRI
// for survey creation). The live workspace/engine lives elsewhere
// (_analysis_studio_shell.php via the *-workspace.php pages).
//
// This page renders NO analysis and NO live Variables & Fit panel — it only
// describes the studio. Reliability is never computed here.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode($_SERVER['SCRIPT_NAME'] . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

if (!isset($studio_def) || !is_array($studio_def)) {
  http_response_code(500);
  echo 'Landing error: $studio_def not set.';
  exit;
}

$sd_slug      = $studio_def['slug'];
$sd_name      = $studio_def['name'];
$sd_quest     = $studio_def['question'];
$sd_accent    = $studio_def['accent'];
$sd_deep      = $studio_def['accent_deep'] ?? $sd_accent;
$sd_soft      = $studio_def['accent_soft'];
$sd_mark      = $studio_def['mark'] ?? '';
$sd_workspace = $studio_def['workspace_route'];
$sd_siri      = $studio_def['siri_route'] ?? '/survey-dev.php';
$sd_rssi      = $studio_def['rssi_route'] ?? '/rssi.php';
$ld           = $studio_def['landing'] ?? [];

$user_full  = $user['name'] ?? $user['email'] ?? 'You';
$initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$shell_page_title    = $sd_name . ' — ReliCheck';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = '';
$shell_body_attrs    = 'data-analysis-landing="' . htmlspecialchars($sd_slug) . '"';

include __DIR__ . '/_platform_shell_header.php';
?>

<style>
  :root { --asl-accent: <?= htmlspecialchars($sd_accent) ?>; --asl-deep: <?= htmlspecialchars($sd_deep) ?>; --asl-soft: <?= htmlspecialchars($sd_soft) ?>; }
  .asl-back { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:var(--ink-4,#5a657a); text-decoration:none; margin-bottom:18px; }
  .asl-back:hover { color:var(--ink-2,#1c2238); }
  .asl-hero { position:relative; padding:8px 0 12px; overflow:hidden; }
  .asl-hero::before { content:""; position:absolute; top:-130px; right:-150px; width:520px; height:520px; background:radial-gradient(closest-side, var(--asl-soft), transparent 70%); border-radius:50%; z-index:0; }
  .asl-hero > * { position:relative; z-index:1; }
  .asl-eyebrow { display:inline-flex; align-items:center; gap:9px; padding:6px 12px; background:var(--asl-soft); color:var(--asl-deep); border-radius:999px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:16px; }
  .asl-eyebrow img { width:18px; height:18px; border-radius:4px; }
  .asl-hero h1 { font-family:'Fraunces','Georgia',serif; font-size:46px; line-height:1.08; font-weight:600; color:var(--ink-1,#1c2238); margin:0 0 14px; max-width:720px; }
  .asl-hero .lede { font-size:17px; line-height:1.55; color:var(--ink-3,#424a5e); max-width:600px; margin:0; }

  .asl-cols { display:grid; grid-template-columns:1.3fr 1fr; gap:28px; margin-top:36px; align-items:start; }
  @media (max-width:880px) { .asl-cols { grid-template-columns:1fr; } }
  .asl-about p { font-size:15.5px; line-height:1.6; color:var(--ink-3,#424a5e); margin:0 0 16px; }
  .asl-about h3 { font-family:Inter,sans-serif; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:var(--ink-5,#7a8499); margin:22px 0 10px; }
  .asl-list { list-style:none; padding:0; margin:0; display:grid; grid-template-columns:1fr 1fr; gap:7px 18px; }
  @media (max-width:560px) { .asl-list { grid-template-columns:1fr; } }
  .asl-list li { font-size:14px; color:var(--ink-2,#1c2238); padding-left:20px; position:relative; }
  .asl-list li::before { content:""; position:absolute; left:2px; top:8px; width:7px; height:7px; border-radius:50%; background:var(--asl-accent); }
  .asl-note { display:flex; gap:9px; align-items:flex-start; font-size:13.5px; line-height:1.5; color:var(--ink-3,#424a5e); background:var(--bg-tint,#f5f7fb); border:1px solid var(--line,#e6e9f0); border-radius:10px; padding:11px 13px; margin-top:10px; }
  .asl-note svg { flex-shrink:0; margin-top:2px; color:var(--asl-accent); }
  .asl-vf { border:1px dashed var(--asl-accent); border-radius:10px; padding:12px 14px; margin-top:16px; font-size:13.5px; line-height:1.5; color:var(--ink-3,#424a5e); background:#fff; }
  .asl-vf strong { color:var(--ink-1,#1c2238); }

  /* Start card */
  .asl-start { border:1px solid rgba(15,23,42,0.10); border-radius:18px; background:#fff; box-shadow:0 4px 16px rgba(15,23,42,0.08); padding:22px; }
  .asl-start h2 { font-family:'Fraunces','Georgia',serif; font-size:21px; font-weight:600; margin:0 0 4px; color:var(--ink-1,#1c2238); }
  .asl-start .guide { font-size:13px; line-height:1.5; color:var(--ink-4,#5a657a); margin:0 0 16px; }
  .asl-cta { display:flex; align-items:center; gap:11px; width:100%; text-align:left; padding:13px 15px; margin-bottom:9px; border-radius:12px; border:1px solid rgba(15,23,42,0.12); background:#fff; color:var(--ink-1,#1c2238); font-family:inherit; font-size:14.5px; font-weight:600; cursor:pointer; text-decoration:none; transition:border-color .14s, transform .14s; }
  .asl-cta:hover { border-color:var(--asl-accent); transform:translateY(-1px); }
  .asl-cta .ci { width:34px; height:34px; flex-shrink:0; display:flex; align-items:center; justify-content:center; border-radius:9px; background:var(--asl-soft); color:var(--asl-deep); }
  .asl-cta small { display:block; font-weight:500; font-size:12px; color:var(--ink-5,#7a8499); margin-top:1px; }
  .asl-cta.is-primary { background:var(--ink-1,#1c2238); border-color:var(--ink-1,#1c2238); color:#fff; }
  .asl-cta.is-primary .ci { background:rgba(255,255,255,0.12); color:#fff; }
  .asl-cta.is-primary small { color:rgba(255,255,255,0.7); }
  .asl-cta.is-siri { border-style:dashed; }
  #aslRecentWrap { margin-bottom:9px; }
</style>

<a class="asl-back" href="/app-2026v4.php">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
  All studios
</a>

<section class="asl-hero">
  <div class="asl-eyebrow"><?php if ($sd_mark): ?><img src="<?= htmlspecialchars($sd_mark) ?>" alt=""><?php endif; ?><?= htmlspecialchars($sd_name) ?></div>
  <h1><?= htmlspecialchars($sd_quest) ?></h1>
  <p class="lede"><?= htmlspecialchars($studio_def['lede']) ?></p>
</section>

<div class="asl-cols">
  <div class="asl-about">
    <?php if (!empty($ld['about'])): ?><p><?= htmlspecialchars($ld['about']) ?></p><?php endif; ?>
    <?php if (!empty($ld['accepts'])): ?><p style="color:var(--ink-4,#5a657a);font-size:14px;"><?= htmlspecialchars($ld['accepts']) ?></p><?php endif; ?>

    <?php if (!empty($ld['can_do'])): ?>
      <h3>What you can do here</h3>
      <ul class="asl-list">
        <?php foreach ($ld['can_do'] as $item): ?><li><?= htmlspecialchars($item) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if (!empty($ld['variables_fit'])): ?>
      <div class="asl-vf"><strong>Variables &amp; Fit.</strong> <?= htmlspecialchars($ld['variables_fit']) ?></div>
    <?php endif; ?>

    <?php foreach (($ld['notes'] ?? []) as $note): ?>
      <div class="asl-note">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16.5" r=".6" fill="currentColor"/></svg>
        <span><?= htmlspecialchars($note) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="asl-start">
    <h2>Start</h2>
    <p class="guide">If you already have data, upload it or open a saved project. If you need to build or deploy a survey first, start in SIRI.</p>

    <div id="aslRecentWrap" hidden>
      <a class="asl-cta is-primary" id="aslRecent" href="#">
        <span class="ci"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9 9 0 0 0-6.36 2.64L3 8"/><path d="M3 3v5h5"/></svg></span>
        <span>Continue recent project<small id="aslRecentName"></small></span>
      </a>
    </div>

    <button class="asl-cta" type="button" id="aslSiriBtn">
      <span class="ci"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
      <span>Open from SIRI responses<small>Analyze a published survey's collected responses</small></span>
    </button>

    <a class="asl-cta" id="aslUpload" href="/evidence-intake.php?studio=survey&amp;return=<?= rawurlencode($sd_workspace) ?>">
      <span class="ci"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span>
      <span>Upload data<small>Bring a CSV or spreadsheet, then continue here</small></span>
    </a>

    <button class="asl-cta" type="button" id="aslProjectBtn">
      <span class="ci"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></span>
      <span>Open saved project<small>Pick one of your existing projects</small></span>
    </button>

    <a class="asl-cta is-siri" href="<?= htmlspecialchars($sd_siri) ?>">
      <span class="ci"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span>
      <span>Create a survey<small>Build, publish, or deploy in SIRI first</small></span>
    </a>

    <a class="asl-cta is-siri" href="<?= htmlspecialchars($sd_rssi) ?>">
      <span class="ci"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6z"/></svg></span>
      <span>Open RSSI<small>Reliability, validity, item analysis &amp; official strength scoring</small></span>
    </a>
  </div>
</div>

<script>
(function () {
  const WORKSPACE = <?= json_encode($sd_workspace) ?>;
  const SLUG = <?= json_encode($sd_slug) ?>;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }

  // ---- Continue recent project (written by the workspace shell) ----
  try {
    const raw = window.localStorage.getItem('relicheck.recent.' + SLUG);
    if (raw) {
      const r = JSON.parse(raw);
      if (r && r.id) {
        const wrap = document.getElementById('aslRecentWrap');
        const link = document.getElementById('aslRecent');
        document.getElementById('aslRecentName').textContent = r.title ? (' · ' + r.title) : '';
        link.href = WORKSPACE + '?project_id=' + encodeURIComponent(r.id) + '&source=recent&kind=siri';
        wrap.hidden = false;
      }
    }
  } catch (e) {}

  // ---- SIRI / saved-project pickers → route into the WORKSPACE ----
  function pickFromList(opts) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px;';
    const panel = document.createElement('div');
    panel.style.cssText = 'background:#fff;border-radius:16px;max-width:520px;width:100%;max-height:72vh;overflow:auto;box-shadow:0 20px 60px rgba(15,23,42,0.3);padding:22px;';
    panel.innerHTML = '<h3 style="font-family:Fraunces,Georgia,serif;font-size:20px;margin:0 0 4px;">' + opts.title + '</h3>'
      + '<p style="font-size:13.5px;color:#5a657a;margin:0 0 16px;">' + opts.sub + '</p>'
      + '<div id="aslPickList" style="display:flex;flex-direction:column;gap:8px;"><p style="color:#7a8499;font-size:14px;">Loading…</p></div>'
      + '<button id="aslPickClose" style="margin-top:16px;background:none;border:none;color:#5a657a;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>';
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    panel.querySelector('#aslPickClose').addEventListener('click', function () { overlay.remove(); });

    // SIRI / survey-dev projects (authoritative survey_projects.id).
    fetch('/api/dev/project-list.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        let list = (data && data.ok && Array.isArray(data.projects)) ? data.projects : [];
        if (opts.publishedOnly) list = list.filter(function (s) { return s.status === 'published'; });
        const host = panel.querySelector('#aslPickList');
        if (!list.length) { host.innerHTML = '<p style="color:#7a8499;font-size:14px;">' + opts.empty + '</p>'; return; }
        host.innerHTML = list.map(function (s) {
          const rc = (s.response_count || 0);
          return '<a href="' + WORKSPACE + '?project_id=' + encodeURIComponent(s.id) + '&source=' + opts.source + '&kind=siri" '
            + 'style="display:block;padding:12px 14px;border:1px solid #e6e9f0;border-radius:10px;text-decoration:none;color:#1c2238;">'
            + '<div style="font-weight:700;font-size:14.5px;">' + esc(s.title || 'Untitled project') + '</div>'
            + '<div style="font-size:12.5px;color:#7a8499;margin-top:2px;">' + rc + ' response' + (rc !== 1 ? 's' : '')
            + (s.status === 'published' ? ' · Published' : ' · Draft') + '</div></a>';
        }).join('');
      })
      .catch(function () { panel.querySelector('#aslPickList').innerHTML = '<p style="color:#c2492f;font-size:14px;">Could not load your projects.</p>'; });
  }

  document.getElementById('aslSiriBtn').addEventListener('click', function () {
    pickFromList({ title: 'Open from SIRI responses', sub: 'Pick a published survey to analyze its collected responses.', source: 'siri', publishedOnly: true, empty: 'No published surveys with responses yet. Create and publish one in SIRI first.' });
  });
  document.getElementById('aslProjectBtn').addEventListener('click', function () {
    pickFromList({ title: 'Open saved project', sub: 'Pick any of your survey projects.', source: 'project', publishedOnly: false, empty: 'No saved projects yet. Create one in SIRI, or upload data.' });
  });
})();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
