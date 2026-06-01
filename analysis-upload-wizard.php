<?php
// Analysis upload wizard — shared by Descriptive & Inferential studios.
// Mirrors the TIA/MM wizard modal (Set Up → Upload → Review) so "Upload data"
// in those studios starts with the same popup as the other studios.
//
//   Step 1 Set Up   title/notes → creates a survey_projects row (the saved
//                   project that later appears in "Open saved project")
//   Step 2 Upload   the shared Evidence Intake engine, embedded; on finish it
//                   saves the dataset to localStorage[relicheck.dataset.<id>]
//   Step 3 Review   confirm → open the studio workspace, where the existing
//                   data loader reads that dataset
//
// ?studio=descriptive|inferential   (required)
// ?step=1|2|3 , ?project_id=N        deep-link

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/analysis-upload-wizard.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$slug = $_GET['studio'] ?? '';
if (!in_array($slug, ['descriptive', 'inferential'], true)) { header('Location: /app-2026v4.php'); exit; }

$ROUTES = [
  'descriptive' => ['workspace' => '/descriptive-analysis-workspace.php', 'landing' => '/descriptive-analysis-studio.php'],
  'inferential' => ['workspace' => '/inferential-statistics-workspace.php', 'landing' => '/inferential-statistics-studio.php'],
];
$workspaceRoute = $ROUTES[$slug]['workspace'];
$landingRoute   = $ROUTES[$slug]['landing'];

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios[$slug];

$initStep  = max(1, min(3, (int)($_GET['step'] ?? 1)));
$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;

$project = null;
if ($projectId > 0) {
  $pdo = db();
  try {
    $stmt = $pdo->prepare('SELECT id, title, purpose AS notes FROM survey_projects WHERE id = :id AND user_id = :uid AND status <> "archived"');
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $project = null; }
  if (!$project) { header('Location: /analysis-upload-wizard.php?studio=' . $slug . '&step=1'); exit; }
}
if ($initStep > 1 && !$project) { header('Location: /analysis-upload-wizard.php?studio=' . $slug . '&step=1'); exit; }

// Uploaded analysis data uses the shared survey Evidence Intake config.
$intake_config = require __DIR__ . '/apps/evidence-intake/configs/survey.config.php';

$user_full           = $user['name'] ?? $user['email'] ?? 'You';
$initials            = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$shell_page_title    = $studio['name'] . ' — Upload';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = $project ? (string)$project['title'] : ('New ' . $studio['name'] . ' upload');
$shell_body_attrs    = 'data-analysis-wizard="' . htmlspecialchars($slug) . '"';

include __DIR__ . '/_platform_shell_header.php';
?>

<link rel="stylesheet" href="/apps/evidence-intake/evidence-intake.css">

<style>
  :root {
    --landing-accent:      <?= htmlspecialchars($studio['accent']) ?>;
    --landing-accent-soft: <?= htmlspecialchars($studio['accent_soft']) ?>;
  }
  .aw-backdrop { position: fixed; inset: 64px 0 0 0; background: rgba(20,24,38,0.42); backdrop-filter: blur(2px); -webkit-backdrop-filter: blur(2px); z-index: 50; overflow-y: auto; padding: 32px 24px 60px; box-sizing: border-box; }
  .aw-modal { max-width: 980px; margin: 0 auto; background: #fff; border-radius: 16px; box-shadow: 0 24px 60px rgba(8,12,24,0.28), 0 2px 6px rgba(8,12,24,0.12); overflow: hidden; }
  .aw-head { position: sticky; top: 0; z-index: 2; background: #fff; border-bottom: 1px solid var(--line); padding: 16px 22px 14px; }
  .aw-titlebar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
  .aw-titlebar .title-side { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 16px; color: var(--ink-1,#1c2238); }
  .aw-titlebar .title-side img { width: 22px; height: 22px; border-radius: 5px; }
  .aw-close { background: transparent; border: none; cursor: pointer; width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: var(--ink-4); }
  .aw-close:hover { background: var(--bg-tint,#f2f4f8); color: var(--ink-1); }
  .aw-steps { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 6px; }
  .aw-step-card { padding: 9px 11px; border: 1px solid var(--line); border-radius: 10px; background: #fff; transition: border-color 0.18s, padding 0.18s; }
  .aw-step-card.is-done { border-color: color-mix(in srgb, var(--landing-accent) 35%, #fff); background: var(--landing-accent-soft); }
  .aw-step-card.is-active { border: 2px solid var(--landing-accent); padding: 8px 10px; }
  .aw-step-card .pip { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: #cfd6df; color: #fff; font-size: 10.5px; font-weight: 700; }
  .aw-step-card.is-done .pip, .aw-step-card.is-active .pip { background: var(--landing-accent); }
  .aw-step-card .label { display: inline-block; margin-left: 6px; font-weight: 600; font-size: 12.5px; color: var(--ink-1); }
  .aw-step-card .sub { color: var(--ink-4); font-size: 11.5px; line-height: 1.3; margin: 3px 0 0 26px; }
  .aw-body { padding: 26px 30px; }
  .aw-panel { display: none; }
  .aw-panel.is-active { display: block; }
  .aw-panel h2 { font-size: 22px; font-weight: 700; margin: 0 0 6px; color: var(--ink-1); }
  .aw-panel .wiz-sub { color: var(--ink-3); font-size: 14px; margin: 0 0 18px; line-height: 1.5; }
  .wiz-field { margin-bottom: 18px; }
  .wiz-field label { display: block; font-weight: 600; font-size: 13.5px; margin-bottom: 6px; color: var(--ink-2); }
  .wiz-field .hint { font-weight: 400; color: var(--ink-5); font-size: 12.5px; margin-left: 6px; }
  .wiz-field input[type="text"], .wiz-field textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font-size: 14px; box-sizing: border-box; font-family: inherit; resize: vertical; }
  .wiz-field input[type="text"]:focus, .wiz-field textarea:focus { outline: 2px solid var(--landing-accent-soft); border-color: var(--landing-accent); }
  .wiz-err { color: #c2492f; font-size: 13px; margin-top: 6px; display: none; }
  .wiz-err.is-on { display: block; }
  .aw-foot { position: sticky; bottom: 0; z-index: 2; background: #fff; border-top: 1px solid var(--line); padding: 14px 22px; display: flex; align-items: center; justify-content: space-between; }
  .btn-primary { background: var(--ink-1); color: #fff; border: 1px solid var(--ink-1); border-radius: 999px; cursor: pointer; font-weight: 600; padding: 9px 18px; font-size: 14px; }
  .btn-primary:hover { background: var(--landing-accent); border-color: var(--landing-accent); }
  .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
  .btn-ghost { background: #fff; color: var(--ink-3); border: 1px solid var(--line); border-radius: 999px; cursor: pointer; font-weight: 600; padding: 9px 18px; font-size: 14px; }
  .btn-ghost:hover { border-color: var(--ink-4); }
  .embed-intake .upload-app { padding: 0 !important; }
  .embed-intake .upload-step { padding-top: 6px; }
</style>

<div class="aw-backdrop">
  <div class="aw-modal" role="dialog" aria-modal="true" aria-labelledby="awDialogTitle">
    <div class="aw-head">
      <div class="aw-titlebar">
        <div class="title-side"><img src="<?= htmlspecialchars($studio['mark']) ?>" alt=""><span id="awDialogTitle"><?= htmlspecialchars($studio['name']) ?> — Set Up</span></div>
        <button type="button" class="aw-close" id="awCloseBtn" aria-label="Close" title="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="aw-steps">
        <?php
          $stops = [1 => ['Set Up', 'Name your dataset.'], 2 => ['Upload', 'CSV or spreadsheet.'], 3 => ['Review', 'Confirm and open.']];
          foreach ($stops as $n => $info) {
            echo '<div class="aw-step-card" data-step-card="' . $n . '"><span class="pip">' . $n . '</span><span class="label">' . htmlspecialchars($info[0]) . '</span><div class="sub">' . htmlspecialchars($info[1]) . '</div></div>';
          }
        ?>
      </div>
    </div>

    <div class="aw-body">
      <!-- STEP 1 -->
      <div class="aw-panel" data-step="1">
        <h2>Name your dataset</h2>
        <p class="wiz-sub">Give this upload a clear name. It becomes a saved project you can reopen later.</p>
        <div class="wiz-field">
          <label for="awTitle">Dataset name <span style="color:#c2492f;">*</span></label>
          <input id="awTitle" type="text" maxlength="200" placeholder="e.g., 2026 Climate Survey responses" value="<?= htmlspecialchars((string)($project['title'] ?? '')) ?>">
          <p class="wiz-err" id="awTitleErr">Add a name to continue.</p>
        </div>
        <div class="wiz-field">
          <label for="awNotes">Notes <span class="hint">(optional)</span></label>
          <textarea id="awNotes" rows="3" maxlength="2000" placeholder="One or two sentences on the data or context."><?= htmlspecialchars((string)($project['notes'] ?? '')) ?></textarea>
        </div>
      </div>

      <!-- STEP 2 (Evidence Intake embedded) -->
      <div class="aw-panel embed-intake" data-step="2">
        <h2>Upload your data</h2>
        <p class="wiz-sub">Bring in a CSV or Excel file. The Intake walks through the columns; the wizard returns you here when you're done.</p>
        <?php include __DIR__ . '/apps/evidence-intake/render.php'; ?>
      </div>

      <!-- STEP 3 -->
      <div class="aw-panel" data-step="3">
        <h2>Your data is ready</h2>
        <p class="wiz-sub">Open <?= htmlspecialchars($studio['name']) ?> to start summarizing and analyzing. Your dataset is saved as a project you can reopen any time.</p>
      </div>
    </div>

    <div class="aw-foot">
      <button type="button" class="btn-ghost" id="awBackBtn">&larr; Back</button>
      <button type="button" class="btn-primary" id="awNextBtn">Continue &rarr;</button>
    </div>
  </div>
</div>

<script>
  window.RELICHECK_WIZARD_HOST = true;
  window.INTAKE_CONFIG = <?= json_encode($intake_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  window.RELICHECK_PROJECT_ID = <?= json_encode($projectId) ?>;
</script>
<script src="/apps/evidence-intake/evidence-intake.js" defer></script>

<script>
(function () {
  var WORKSPACE = <?= json_encode($workspaceRoute) ?>;
  var LANDING   = <?= json_encode($landingRoute) ?>;
  var SLUG      = <?= json_encode($slug) ?>;
  var STUDIO    = <?= json_encode($studio['name']) ?>;
  var state = { step: <?= (int)$initStep ?>, projectId: <?= (int)$projectId ?>, title: <?= json_encode((string)($project['title'] ?? '')) ?> };

  var cards = document.querySelectorAll('[data-step-card]');
  var panels = document.querySelectorAll('.aw-panel');
  var titleEl = document.getElementById('awDialogTitle');
  var backBtn = document.getElementById('awBackBtn');
  var nextBtn = document.getElementById('awNextBtn');
  var closeBtn = document.getElementById('awCloseBtn');

  function showStep(n, opts) {
    opts = opts || {}; state.step = n;
    cards.forEach(function (c) { var k = parseInt(c.getAttribute('data-step-card'), 10); c.classList.toggle('is-active', k === n); c.classList.toggle('is-done', k < n); });
    panels.forEach(function (p) { p.classList.toggle('is-active', parseInt(p.getAttribute('data-step'), 10) === n); });
    var suffix = { 1: 'Set Up', 2: 'Upload', 3: 'Review' };
    titleEl.textContent = (state.title || STUDIO) + ' — ' + suffix[n];
    backBtn.style.visibility = (n === 1) ? 'hidden' : 'visible';
    nextBtn.textContent = (n === 3) ? ('Open ' + STUDIO + ' →') : 'Continue →';
    nextBtn.style.display = (n === 2) ? 'none' : ''; // step 2 advances via intake-complete
    if (!opts.skipUrlSync) {
      var url = new URL(window.location.href); url.searchParams.set('step', String(n)); url.searchParams.set('studio', SLUG);
      if (state.projectId) url.searchParams.set('project_id', String(state.projectId));
      window.history.replaceState({ step: n }, '', url.toString());
    }
  }

  function commitStep1() {
    var title = document.getElementById('awTitle').value.trim();
    var notes = document.getElementById('awNotes').value.trim();
    var err = document.getElementById('awTitleErr');
    if (!title) { err.classList.add('is-on'); return Promise.reject(); }
    err.classList.remove('is-on'); state.title = title;
    if (state.projectId > 0) { return Promise.resolve(); } // already created
    nextBtn.disabled = true; var orig = nextBtn.textContent; nextBtn.textContent = 'Saving…';
    return fetch('/api/dev/project-create.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ title: title, purpose: notes, source: 'existing' })
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!(d && (d.ok || d.created) && d.project)) throw new Error((d && (d.message || d.error)) || 'Could not save');
      state.projectId = d.project.id; window.RELICHECK_PROJECT_ID = d.project.id;
    }).finally(function () { nextBtn.disabled = false; nextBtn.textContent = orig; });
  }

  // Evidence Intake fires this when the upload + mapping is finished. It has
  // already saved the dataset to localStorage[relicheck.dataset.<projectId>].
  document.addEventListener('relicheck:intake-complete', function () { if (state.step === 2) showStep(3); });

  nextBtn.addEventListener('click', function () {
    if (state.step === 1) commitStep1().then(function () { showStep(2); }).catch(function () {});
    else if (state.step === 3) window.location.href = WORKSPACE + '?project_id=' + encodeURIComponent(state.projectId) + '&source=upload';
  });
  backBtn.addEventListener('click', function () { if (state.step > 1) showStep(state.step - 1); });
  closeBtn.addEventListener('click', function () { window.location.href = LANDING; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeBtn.click(); });

  showStep(state.step, { skipUrlSync: true });
})();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
