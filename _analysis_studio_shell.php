<?php
// Shared shell for the two new analysis destinations:
//   - descriptive-analysis-studio.php   ("What is present in the data?")
//   - inferential-statistics-studio.php ("What can the data support?")
//
// These are CLEAN destination shells per the locked ReliCheck architecture.
// They do NOT replace the old all-in-one Survey Studio roof (which stays).
// They RE-HOME the existing, tested engines (apps/descriptive/,
// apps/inferential/, apps/inferential-extensions/, apps/effect-size/) by
// mounting their existing analysis pages inside an iframe in embed mode
// (?embed=1) — the same headless-engine mechanism RSSI already uses.
//
// Boundary rules enforced here (see [[project_studio_architecture]]):
//   1. NO reliability / Cronbach's alpha / item analysis is computed in
//      this shell. Reliability is surfaced ONLY by reference, through the
//      upper-right RSSI control, from a saved RSSI run. If none exists, the
//      control shows an unavailable state.
//   2. Data intake is the uniform three-source model used everywhere:
//      Open from SIRI responses · Upload data · Open saved project.
//      No bespoke intake is invented here — it reuses the survey list and
//      the shared Evidence Intake.
//
// A page using this shell sets $studio_def (below) and includes this file.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

// ---------- Auth ----------
start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode($_SERVER['SCRIPT_NAME'] . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

// ---------- Studio definition (set by the including page) ----------
if (!isset($studio_def) || !is_array($studio_def)) {
  http_response_code(500);
  echo 'Studio shell error: $studio_def not set.';
  exit;
}
$sd_slug    = $studio_def['slug'];
$sd_name    = $studio_def['name'];
$sd_self    = $studio_def['self'];        // this page's own path, for return links
$sd_quest   = $studio_def['question'];
$sd_lede    = $studio_def['lede'];
$sd_accent  = $studio_def['accent'];
$sd_soft    = $studio_def['accent_soft'];
$sd_tools   = $studio_def['tools'];        // [ ['key'=>, 'label'=>, 'route'=>, 'desc'=>], ... ]

// ---------- Selected project + tool ----------
// project_id > 0  → a real survey project; the embedded engine reads its
//                   responses straight from the DB (server-side injection in
//                   _studio_mount.php). Ownership is verified there.
// project_id = 0  → upload flow; the engine scans localStorage for the most
//                   recently uploaded dataset.
$projectId  = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
$activeTool = isset($_GET['tool']) ? preg_replace('/[^a-z0-9_]/', '', (string)$_GET['tool']) : '';
$source     = isset($_GET['source']) ? preg_replace('/[^a-z]/', '', (string)$_GET['source']) : '';

// If a project_id was supplied, confirm the user owns that SIRI / survey-dev
// project (the embed re-checks too, but bouncing early avoids a dead iframe).
// Authoritative identity is survey_projects.id — the same id the embedded
// engines, the responses-dataset endpoint, and RSSI run-status all use.
$projectTitle = '';
if ($projectId > 0) {
  try {
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT title FROM survey_projects WHERE id = :id AND user_id = :uid AND status <> "archived"');
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $projectTitle = (string)$row['title']; }
    else { $projectId = 0; } // not owned → treat as no project
  } catch (Throwable $e) { $projectId = 0; }
}

// Validate the active tool against this studio's tool list.
$activeToolDef = null;
foreach ($sd_tools as $t) {
  if ($t['key'] === $activeTool) { $activeToolDef = $t; break; }
}

$user_full  = $user['name'] ?? $user['email'] ?? 'You';
$initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));

$shell_page_title    = $sd_name . ' — ReliCheck';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = $projectTitle !== '' ? $projectTitle : '';
$shell_body_attrs    = 'data-analysis-studio="' . htmlspecialchars($sd_slug) . '"';

include __DIR__ . '/_platform_shell_header.php';
?>

<style>
  :root {
    --as-accent:      <?= htmlspecialchars($sd_accent) ?>;
    --as-accent-soft: <?= htmlspecialchars($sd_soft) ?>;
  }
  .as-back-link { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:var(--ink-4,#5a657a); text-decoration:none; margin-bottom:16px; }
  .as-back-link:hover { color:var(--ink-2,#1c2238); }
  .as-head { display:flex; align-items:flex-start; justify-content:space-between; gap:24px; margin-bottom:8px; }
  .as-head-main { min-width:0; }
  .as-eyebrow { display:inline-flex; align-items:center; gap:8px; padding:5px 11px; background:var(--as-accent-soft); color:var(--as-accent); border-radius:999px; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:12px; }
  .as-head h1 { font-family:'Fraunces','Georgia',serif; font-size:36px; line-height:1.1; font-weight:600; color:var(--ink-1,#1c2238); margin:0 0 8px; }
  .as-head .lede { font-size:15.5px; line-height:1.5; color:var(--ink-3,#424a5e); max-width:560px; margin:0; }

  /* Upper-right RSSI reliability control */
  .as-rssi-ctl { flex-shrink:0; width:240px; border:1px solid rgba(15,23,42,0.10); border-radius:14px; background:#fff; box-shadow:0 2px 8px rgba(15,23,42,0.06); padding:14px; }
  .as-rssi-ctl .rk { display:flex; align-items:center; gap:7px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#0A6FE8; margin-bottom:8px; }
  .as-rssi-ctl .rk .dot { width:8px; height:8px; border-radius:50%; background:#cdd6e4; }
  .as-rssi-ctl.is-available .rk .dot { background:#0e8a6f; }
  .as-rssi-ctl .rstate { font-size:13px; line-height:1.45; color:var(--ink-4,#5a657a); }
  .as-rssi-ctl .rstate strong { color:var(--ink-2,#1c2238); }
  .as-rssi-ctl a.rlink { display:inline-flex; align-items:center; gap:6px; margin-top:10px; font-size:13px; font-weight:700; color:#0A6FE8; text-decoration:none; }
  .as-rssi-ctl a.rlink:hover { text-decoration:underline; }

  /* Intake bar */
  /* ---- Sticky studio dock (reusable template: logo bottom-left, centered controls) ---- */
  .studio-dock { position:fixed; left:0; right:0; bottom:0; z-index:60; padding:12px 22px; box-sizing:border-box;
    background:rgba(255,255,255,0.92); -webkit-backdrop-filter:saturate(1.4) blur(12px); backdrop-filter:saturate(1.4) blur(12px);
    border-top:1px solid var(--line,#e6e9f0); box-shadow:0 -4px 22px rgba(15,23,42,0.07); }
  .studio-dock-logo { position:absolute; left:22px; top:50%; transform:translateY(-50%); display:inline-flex; align-items:center; }
  .studio-dock-logo img { height:24px; width:auto; display:block; }
  .studio-dock-inner { display:flex; align-items:center; justify-content:center; gap:12px; flex-wrap:wrap; min-height:42px; }
  .studio-dock .lbl { font-size:12.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:var(--ink-5,#7a8499); margin-right:4px; }
  .as-intake-btn { display:inline-flex; align-items:center; gap:7px; padding:8px 14px; border-radius:10px; border:1px solid rgba(15,23,42,0.12); background:#fff; color:var(--ink-2,#1c2238); font-family:inherit; font-size:13.5px; font-weight:600; cursor:pointer; text-decoration:none; transition:border-color .14s, transform .14s; }
  .as-intake-btn:hover { border-color:var(--as-accent); transform:translateY(-1px); }
  .as-intake-btn svg { color:var(--as-accent); }
  .as-loaded-chip { display:inline-flex; align-items:center; gap:8px; font-size:13px; color:var(--ink-4,#5a657a); }
  .as-loaded-chip .gdot { width:8px; height:8px; border-radius:50%; background:#1f7a3a; }
  /* keep the workspace clear of the fixed dock */
  .as-body { padding-bottom:96px; }
  @media (max-width:760px) { .studio-dock-logo { display:none; } }

  /* Two-column body */
  .as-body { display:grid; grid-template-columns:236px 1fr; gap:20px; margin-top:18px; align-items:start; }
  .as-nav { border:1px solid var(--line,#e6e9f0); border-radius:14px; background:#fff; padding:10px; position:sticky; top:18px; }
  .as-nav .nav-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--ink-5,#7a8499); padding:8px 10px 6px; }
  .as-nav a { display:block; padding:9px 11px; border-radius:9px; font-size:14px; font-weight:600; color:var(--ink-2,#1c2238); text-decoration:none; transition:background .12s; }
  .as-nav a small { display:block; font-size:11.5px; font-weight:500; color:var(--ink-5,#7a8499); margin-top:1px; }
  .as-nav a:hover { background:var(--as-accent-soft); }
  .as-nav a.is-active { background:var(--as-accent); color:#fff; }
  .as-nav a.is-active small { color:rgba(255,255,255,0.8); }

  .as-work { min-height:440px; border:1px solid var(--line,#e6e9f0); border-radius:14px; background:#fff; overflow:hidden; }
  .as-work iframe { width:100%; min-height:760px; border:0; display:block; }

  /* Empty states */
  .as-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:64px 32px; min-height:440px; }
  .as-empty .ico { width:54px; height:54px; border-radius:14px; background:var(--as-accent-soft); color:var(--as-accent); display:flex; align-items:center; justify-content:center; margin-bottom:16px; }
  .as-empty h3 { font-family:'Fraunces','Georgia',serif; font-size:20px; font-weight:600; color:var(--ink-1,#1c2238); margin:0 0 6px; }
  .as-empty p { font-size:14px; line-height:1.5; color:var(--ink-4,#5a657a); max-width:420px; margin:0 0 4px; }

  @media (max-width:880px) { .as-body { grid-template-columns:1fr; } .as-nav { position:static; } .as-head { flex-direction:column; } .as-rssi-ctl { width:100%; } }
</style>

<a class="as-back-link" href="/app-2026v4.php">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
  All studios
</a>

<div class="as-head">
  <div class="as-head-main">
    <div class="as-eyebrow"><?= htmlspecialchars($sd_name) ?></div>
    <h1><?= htmlspecialchars($sd_quest) ?></h1>
    <p class="lede"><?= htmlspecialchars($sd_lede) ?></p>
  </div>

  <!-- Upper-right RSSI reliability control. References a saved RSSI run only;
       never computes reliability here. -->
  <div class="as-rssi-ctl" id="asRssiCtl" data-project-id="<?= (int)$projectId ?>">
    <div class="rk"><span class="dot"></span> Reliability · RSSI</div>
    <div class="rstate" id="asRssiState">Checking for an RSSI run…</div>
  </div>
</div>

<!-- Sticky studio dock: ReliCheck logo bottom-left, three-source data intake centered. -->
<div class="studio-dock" role="region" aria-label="Data">
  <a class="studio-dock-logo" href="/app-2026v4.php" aria-label="ReliCheck home"><img src="/logo-brand.svg" alt="ReliCheck"></a>
  <div class="studio-dock-inner">
    <span class="lbl">Data</span>
    <button class="as-intake-btn" type="button" id="asOpenSiri">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
      Open from SIRI responses
    </button>
    <a class="as-intake-btn" id="asUpload" href="/evidence-intake.php?studio=survey&amp;return=<?= rawurlencode($sd_self) ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Upload data
    </a>
    <button class="as-intake-btn" type="button" id="asOpenProject">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
      Open saved project
    </button>
    <span class="as-loaded-chip" id="asLoadedChip" hidden><span class="gdot"></span><span id="asLoadedText"></span></span>
  </div>
</div>

<?php
// Persistent pre-analysis preview layer. Rendered only when the studio opts in
// via $studio_def['preview'] (Inferential = 'variables_fit'). It lives ABOVE
// the tool workspace and stays visible after a tool is chosen — the user keeps
// it open while deciding what analysis to run. Lightweight, read-only; computes
// NO reliability (that lives in RSSI). See apps/inferential-preview/.
if (!empty($studio_def['preview']) && $studio_def['preview'] === 'variables_fit'):
?>
<section class="ivf-panel" id="ivfPanel" aria-label="Variables and Fit preview">
  <div class="ivf-head" id="ivfHead">
    <h2>Variables &amp; Fit</h2>
    <span class="ivf-sub">Which analysis can you responsibly run with these variables?</span>
    <button class="ivf-toggle" id="ivfToggle" type="button">Hide</button>
  </div>
  <div class="ivf-body" id="ivfBody">
    <div class="ivf-empty">Loading your variables…</div>
  </div>
</section>
<?php endif; ?>

<div class="as-body">
  <nav class="as-nav" aria-label="<?= htmlspecialchars($sd_name) ?> tools">
    <div class="nav-title">Tools</div>
    <?php foreach ($sd_tools as $t):
      $isActive = ($t['key'] === $activeTool);
      $href = htmlspecialchars($sd_self . '?tool=' . urlencode($t['key']) . ($projectId ? '&project_id=' . $projectId : '') . ($source ? '&source=' . $source : ''));
    ?>
      <a href="<?= $href ?>" class="<?= $isActive ? 'is-active' : '' ?>" data-tool="<?= htmlspecialchars($t['key']) ?>">
        <?= htmlspecialchars($t['label']) ?>
        <small><?= htmlspecialchars($t['desc']) ?></small>
      </a>
    <?php endforeach; ?>
  </nav>

  <section class="as-work" id="asWork">
    <?php if ($activeToolDef && ($projectId > 0)): ?>
      <!-- Mount the existing tested engine headless (embed mode) under this
           studio's own slug. The engine reads the dataset, which
           _studio_mount.php injects server-side from this survey's responses. -->
      <iframe
        title="<?= htmlspecialchars($activeToolDef['label']) ?>"
        src="<?= htmlspecialchars($activeToolDef['route'] . '?studio=' . $sd_slug . '&project_id=' . $projectId . '&embed=1') ?>"
        loading="eager"></iframe>
    <?php elseif ($activeToolDef): ?>
      <!-- Tool chosen but no project loaded. The engine can still run on an
           uploaded dataset (project_id=0, localStorage scan) — wire it client
           side once we confirm a dataset exists; otherwise show empty state. -->
      <div class="as-empty" id="asToolNoData" data-tool-route="<?= htmlspecialchars($activeToolDef['route']) ?>">
        <div class="ico"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/></svg></div>
        <h3>Load data to run <?= htmlspecialchars($activeToolDef['label']) ?></h3>
        <p>Open responses from a SIRI survey, upload a file, or open a saved project using the buttons above. This tool runs on the loaded dataset.</p>
      </div>
    <?php else: ?>
      <div class="as-empty">
        <div class="ico"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/></svg></div>
        <h3>No dataset loaded</h3>
        <p>Choose a data source above, then pick a tool from the left to begin. <?= htmlspecialchars($sd_name) ?> never computes reliability — that lives in RSSI.</p>
      </div>
    <?php endif; ?>
  </section>
</div>

<script>
(function () {
  const SELF = <?= json_encode($sd_self) ?>;
  const PROJECT_ID = <?= json_encode($projectId) ?>;
  const STUDIO_SLUG = <?= json_encode($sd_slug) ?>;

  // ---- Uniform intake: survey/project picker via the same surveys list
  // every studio uses. "Open from SIRI responses" = published surveys;
  // "Open saved project" = all of the user's surveys/projects. ----
  function pickFromList(opts) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px;';
    const panel = document.createElement('div');
    panel.style.cssText = 'background:#fff;border-radius:16px;max-width:520px;width:100%;max-height:72vh;overflow:auto;box-shadow:0 20px 60px rgba(15,23,42,0.3);padding:22px;';
    panel.innerHTML = '<h3 style="font-family:Fraunces,Georgia,serif;font-size:20px;margin:0 0 4px;">' + opts.title + '</h3>'
      + '<p style="font-size:13.5px;color:#5a657a;margin:0 0 16px;">' + opts.sub + '</p>'
      + '<div id="asPickList" style="display:flex;flex-direction:column;gap:8px;"><p style="color:#7a8499;font-size:14px;">Loading…</p></div>'
      + '<button id="asPickClose" style="margin-top:16px;background:none;border:none;color:#5a657a;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>';
    overlay.appendChild(panel);
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    panel.querySelector('#asPickClose').addEventListener('click', function () { overlay.remove(); });

    // SIRI / survey-dev projects (authoritative survey_projects.id).
    fetch('/api/dev/project-list.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        let list = (data && data.ok && Array.isArray(data.projects)) ? data.projects : [];
        if (opts.publishedOnly) list = list.filter(function (s) { return s.status === 'published'; });
        const host = panel.querySelector('#asPickList');
        if (!list.length) {
          host.innerHTML = '<p style="color:#7a8499;font-size:14px;">' + opts.empty + '</p>';
          return;
        }
        host.innerHTML = list.map(function (s) {
          const rc = (s.response_count || 0);
          return '<a href="' + SELF + '?project_id=' + encodeURIComponent(s.id) + '&source=' + opts.source + '&kind=siri" '
            + 'style="display:block;padding:12px 14px;border:1px solid #e6e9f0;border-radius:10px;text-decoration:none;color:#1c2238;">'
            + '<div style="font-weight:700;font-size:14.5px;">' + esc(s.title || 'Untitled project') + '</div>'
            + '<div style="font-size:12.5px;color:#7a8499;margin-top:2px;">' + rc + ' response' + (rc !== 1 ? 's' : '')
            + (s.status === 'published' ? ' · Published' : ' · Draft') + '</div></a>';
        }).join('');
      })
      .catch(function () {
        panel.querySelector('#asPickList').innerHTML = '<p style="color:#c2492f;font-size:14px;">Could not load your projects.</p>';
      });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }

  const siriBtn = document.getElementById('asOpenSiri');
  if (siriBtn) siriBtn.addEventListener('click', function () {
    pickFromList({ title: 'Open from SIRI responses', sub: 'Pick a published survey to analyze its collected responses.', source: 'siri', publishedOnly: true, empty: 'No published surveys with responses yet. Publish a survey in SIRI first.' });
  });
  const projBtn = document.getElementById('asOpenProject');
  if (projBtn) projBtn.addEventListener('click', function () {
    pickFromList({ title: 'Open saved project', sub: 'Pick any of your survey projects.', source: 'project', publishedOnly: false, empty: 'No saved projects yet.' });
  });

  // ---- "Loaded" chip + upload-dataset detection (project_id=0 flow) ----
  function findLocalDataset() {
    let best = null;
    try {
      for (let i = 0; i < window.localStorage.length; i++) {
        const k = window.localStorage.key(i);
        if (!k || k.indexOf('relicheck.dataset.') !== 0) continue;
        const w = JSON.parse(window.localStorage.getItem(k));
        if (w && w.payload && w.payload.dataset && (w.payload.dataset.rowCount || 0) > 0) {
          if (!best || (w.savedAt || 0) > (best.savedAt || 0)) best = { savedAt: w.savedAt || 0, ds: w.payload.dataset, key: k };
        }
      }
    } catch (e) {}
    return best;
  }
  const chip = document.getElementById('asLoadedChip');
  const chipText = document.getElementById('asLoadedText');
  if (PROJECT_ID > 0) {
    chip.hidden = false;
    const projName = <?= json_encode($projectTitle !== '' ? $projectTitle : 'Survey project') ?>;
    chipText.textContent = projName + ' · responses loaded';
    // Record last-opened project for this studio so the landing page can offer
    // "Continue recent project". (Browser Date is fine here — page JS, not a workflow.)
    try { window.localStorage.setItem('relicheck.recent.' + STUDIO_SLUG, JSON.stringify({ id: PROJECT_ID, title: projName, at: Date.now() })); } catch (e) {}
  } else {
    const local = findLocalDataset();
    if (local) {
      chip.hidden = false;
      const rc = local.ds.rowCount || 0;
      chipText.textContent = 'Uploaded dataset · ' + rc + ' row' + (rc !== 1 ? 's' : '');
      // If a tool is selected but we rendered the no-data empty state, mount
      // the engine now against the uploaded dataset (project_id=0 scan mode).
      const noData = document.getElementById('asToolNoData');
      if (noData) {
        const route = noData.getAttribute('data-tool-route');
        const work = document.getElementById('asWork');
        work.innerHTML = '<iframe title="analysis" src="' + route + '?studio=' + STUDIO_SLUG + '&project_id=0&embed=1" loading="eager" style="width:100%;min-height:760px;border:0;display:block;"></iframe>';
      }
    }
  }

  // ---- Upper-right RSSI reliability control ----
  // Reads the AUTHORITATIVE saved RSSI run via /api/rssi/run-status.php. It
  // never computes reliability or strength — it only references an existing
  // run. The endpoint returns {exists:false} when there is no run (or the
  // project id is from a different id-space / not owned).
  (function () {
    const ctl = document.getElementById('asRssiCtl');
    const state = document.getElementById('asRssiState');
    if (!ctl || !state) return;

    function esch(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }
    function addLink(href, label) {
      const a = document.createElement('a');
      a.className = 'rlink'; a.href = href; a.textContent = label;
      state.after(a);
    }
    const rssiHref = PROJECT_ID > 0 ? '/rssi.php?project_id=' + encodeURIComponent(PROJECT_ID) : '/rssi.php';

    function showNone() {
      ctl.classList.remove('is-available');
      state.innerHTML = '<strong>No RSSI run available.</strong> Reliability and strength evidence live in RSSI.';
      addLink(rssiHref, 'Open RSSI →');
    }

    // No project id → nothing authoritative to look up.
    if (!(PROJECT_ID > 0)) { showNone(); return; }

    fetch('/api/rssi/run-status.php?project_id=' + encodeURIComponent(PROJECT_ID), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.exists) { showNone(); return; }
        ctl.classList.add('is-available');
        if (d.withheld) {
          // Official score withheld (e.g. construct mapping missing).
          state.innerHTML = '<strong>RSSI run available.</strong><br>Official score withheld<br><span style="font-size:12px;">Construct mapping required</span>';
        } else {
          const score = (d.score != null) ? Math.round(d.score) : '—';
          state.innerHTML = '<strong>RSSI run available.</strong><br>Score: ' + esch(score) + (d.band ? ' / ' + esch(d.band) : '');
        }
        addLink(rssiHref, 'Open RSSI →');
      })
      .catch(showNone);
  })();
})();
</script>

<?php
// Load the Variables & Fit preview module (Inferential only). Cache-bust by
// file mtime, same convention as _studio_mount.php's engine loader.
if (!empty($studio_def['preview']) && $studio_def['preview'] === 'variables_fit'):
  $_ivf_css = '/apps/inferential-preview/inferential-preview.css';
  $_ivf_js  = '/apps/inferential-preview/inferential-preview.js';
  $_ivf_css_v = is_file(__DIR__ . $_ivf_css) ? filemtime(__DIR__ . $_ivf_css) : time();
  $_ivf_js_v  = is_file(__DIR__ . $_ivf_js)  ? filemtime(__DIR__ . $_ivf_js)  : time();
?>
<link rel="stylesheet" href="<?= htmlspecialchars($_ivf_css . '?v=' . $_ivf_css_v) ?>">
<script>window.INFPREVIEW_CONFIG = <?= json_encode(['projectId' => $projectId, 'activeTool' => $activeTool]) ?>;</script>
<script src="<?= htmlspecialchars($_ivf_js . '?v=' . $_ivf_js_v) ?>" defer></script>
<?php endif; ?>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
