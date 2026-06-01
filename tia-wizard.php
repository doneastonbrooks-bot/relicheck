<?php
// TIA Studio Wizard (v4) — single-popup edition. Modeled on mm-wizard.php.
// -------------------------------------------------------------------
// Three outer steps:
//   1. Set Up   title + notes (creates the TIA project)
//   2. Upload   Evidence Intake engine (embedded) — student × item +
//               answer key + scoring (the Intake's TIA config handles
//               the answer-key step itself, step 3 of the Intake flow)
//   3. Review   confirm scoring defaults + finish → Project Snapshot
//
// URL params:
//   ?step=1|2|3            deep-link
//   ?project_id=N          required for steps 2-3

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/tia-wizard.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$initStep   = max(1, min(3, (int)($_GET['step'] ?? 1)));
$projectId  = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
$demoMode   = !empty($_GET['demo']);

$demoTitle  = 'TIA Sample — Grade 8 Reading Quiz';
$demoNotes  = 'Walk-through using a 20-student × 8-item dataset; one item is deliberately miskeyed so the Answer Key Validation lens has something to flag.';

$project = null;
if ($projectId > 0) {
  $pdo = db();
  try {
    $stmt = $pdo->prepare('SELECT id, title, notes, settings FROM tia_projects WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $project = null; }
  if (!$project) { header('Location: /tia-wizard.php?step=1'); exit; }
}
if ($initStep > 1 && !$project) { header('Location: /tia-wizard.php?step=1'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['tia'];

$intake_config = require __DIR__ . '/apps/evidence-intake/configs/tia.config.php';

$user_full           = $user['name'] ?? $user['email'] ?? 'You';
$initials            = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$shell_page_title    = 'TIA Studio Wizard — ReliCheck';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = $project ? (string)$project['title'] : 'New TIA project';
$shell_body_attrs    = 'data-current-studio="tia" data-studio-landing="tia" data-tia-wizard-modal="1"';

$priorSettings = $project ? (json_decode((string)($project['settings'] ?? '{}'), true) ?: []) : [];
$initScoring   = (string)($priorSettings['scoring_mode'] ?? 'proportion');

include __DIR__ . '/_platform_shell_header.php';
?>

<link rel="stylesheet" href="/apps/evidence-intake/evidence-intake.css">

<style>
  :root {
    --landing-accent:      <?= htmlspecialchars($studio['accent']) ?>;
    --landing-accent-soft: <?= htmlspecialchars($studio['accent_soft']) ?>;
  }
  .tia-modal-backdrop { position: fixed; inset: 64px 0 0 0; background: rgba(20,24,38,0.42); backdrop-filter: blur(2px); -webkit-backdrop-filter: blur(2px); z-index: 50; overflow-y: auto; padding: 32px 24px 60px; box-sizing: border-box; }
  .tia-modal { max-width: 980px; margin: 0 auto; background: #fff; border-radius: 16px; box-shadow: 0 24px 60px rgba(8,12,24,0.28), 0 2px 6px rgba(8,12,24,0.12); overflow: hidden; }
  .tia-modal-head { position: sticky; top: 0; z-index: 2; background: #fff; border-bottom: 1px solid var(--line); padding: 16px 22px 14px; }
  .tia-modal-titlebar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
  .tia-modal-titlebar .title-side { display: flex; align-items: center; gap: 10px; font-family: 'Fraunces','Georgia',serif; font-size: 16px; font-weight: 600; color: var(--ink-1,#1c2238); }
  .tia-modal-titlebar .title-side img { width: 22px; height: 22px; border-radius: 5px; }
  .tia-modal-close { background: transparent; border: none; cursor: pointer; width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: var(--ink-4); }
  .tia-modal-close:hover { background: var(--bg-tint,#f2f4f8); color: var(--ink-1); }
  .tia-modal-steps { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 6px; }
  .tia-step-card { padding: 9px 11px; border: 1px solid var(--line); border-radius: 10px; background: #fff; transition: border-color 0.18s, padding 0.18s; }
  .tia-step-card.is-done { border-color: #d2e9d8; background: #f2faf4; }
  .tia-step-card.is-active { border: 2px solid var(--landing-accent); padding: 8px 10px; }
  .tia-step-card .pip { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: #cfd6df; color: #fff; font-size: 10.5px; font-weight: 700; }
  .tia-step-card.is-done .pip { background: #1f7a3a; }
  .tia-step-card.is-active .pip { background: var(--landing-accent); }
  .tia-step-card .label { display: inline-block; margin-left: 6px; font-weight: 600; font-size: 12.5px; color: var(--ink-1); }
  .tia-step-card .sub { color: var(--ink-4); font-size: 11.5px; line-height: 1.3; margin: 3px 0 0 26px; }
  .tia-modal-body { padding: 26px 30px; }
  .tia-step-panel { display: none; }
  .tia-step-panel.is-active { display: block; }
  .tia-step-panel h2 { font-family: 'Fraunces','Georgia',serif; font-size: 22px; font-weight: 600; margin: 0 0 6px; color: var(--ink-1); }
  .tia-step-panel .wiz-sub { color: var(--ink-3); font-size: 14px; margin: 0 0 18px; line-height: 1.5; }
  .wiz-field { margin-bottom: 18px; }
  .wiz-field label { display: block; font-weight: 600; font-size: 13.5px; margin-bottom: 6px; color: var(--ink-2); }
  .wiz-field .hint { font-weight: 400; color: var(--ink-5); font-size: 12.5px; margin-left: 6px; }
  .wiz-field input[type="text"], .wiz-field textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font-size: 14px; box-sizing: border-box; font-family: inherit; resize: vertical; }
  .wiz-field input[type="text"]:focus, .wiz-field textarea:focus { outline: 2px solid var(--landing-accent-soft); border-color: var(--landing-accent); }
  .wiz-err { color: #c2492f; font-size: 13px; margin-top: 6px; display: none; }
  .wiz-err.is-on { display: block; }
  .wiz-opt { display: flex; align-items: flex-start; gap: 12px; border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; margin-bottom: 8px; background: #fff; cursor: pointer; user-select: none; }
  .wiz-opt:hover { border-color: var(--landing-accent); }
  .wiz-opt.is-on { border-color: var(--landing-accent); background: var(--landing-accent-soft); }
  .wiz-opt input { margin-top: 3px; flex-shrink: 0; }
  .wiz-opt strong { font-size: 13.5px; color: var(--ink-1); }
  .wiz-opt .opt-help { color: var(--ink-4); font-size: 12.5px; margin-top: 3px; line-height: 1.4; }
  .tia-modal-foot { position: sticky; bottom: 0; z-index: 2; background: #fff; border-top: 1px solid var(--line); padding: 14px 22px; display: flex; align-items: center; justify-content: space-between; }
  .btn-primary { background: var(--ink-1); color: #fff; border: 1px solid var(--ink-1); border-radius: 999px; cursor: pointer; font-weight: 600; padding: 9px 18px; font-size: 14px; }
  .btn-primary:hover { background: var(--landing-accent); border-color: var(--landing-accent); }
  .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
  .btn-ghost { background: #fff; color: var(--ink-3); border: 1px solid var(--line); border-radius: 999px; cursor: pointer; font-weight: 600; padding: 9px 18px; font-size: 14px; text-decoration: none; display: inline-block; }
  .btn-ghost:hover { border-color: var(--ink-4); }
  .embed-intake .upload-app { padding: 0 !important; }
  .embed-intake .upload-step { padding-top: 6px; }
</style>

<div class="tia-modal-backdrop" id="tiaWizBackdrop">
  <div class="tia-modal" role="dialog" aria-modal="true" aria-labelledby="tiaWizDialogTitle">

    <div class="tia-modal-head">
      <div class="tia-modal-titlebar">
        <div class="title-side">
          <img src="<?= htmlspecialchars($studio['mark']) ?>" alt="">
          <span id="tiaWizDialogTitle">New TIA project</span>
        </div>
        <button type="button" class="tia-modal-close" id="tiaWizCloseBtn" aria-label="Close wizard" title="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="tia-modal-steps">
        <?php
          $stops = [
            1 => ['Set Up',  'Title and description.'],
            2 => ['Upload',  'Student × item + answer key.'],
            3 => ['Review',  'Scoring defaults and finish.'],
          ];
          foreach ($stops as $n => $info) {
            echo '<div class="tia-step-card" data-step-card="' . $n . '">'
              . '<span class="pip">' . $n . '</span>'
              . '<span class="label">' . htmlspecialchars($info[0]) . '</span>'
              . '<div class="sub">' . htmlspecialchars($info[1]) . '</div>'
              . '</div>';
          }
        ?>
      </div>
    </div>

    <div class="tia-modal-body">

      <!-- ===== STEP 1 ===== -->
      <div class="tia-step-panel" data-step="1">
        <h2>Title your test</h2>
        <p class="wiz-sub">Give the test a clear name. You can edit this any time.</p>
        <div class="wiz-field">
          <label for="tiaWizTitle">Test title <span style="color:#c2492f;">*</span></label>
          <input id="tiaWizTitle" type="text" maxlength="200" placeholder="e.g., 2026 Grade 8 Reading Comprehension Quiz" value="<?= htmlspecialchars((string)($project['title'] ?? ($demoMode ? $demoTitle : ''))) ?>">
          <p class="wiz-err" id="tiaWizTitleErr">Add a test title to continue.</p>
        </div>
        <div class="wiz-field">
          <label for="tiaWizNotes">Notes <span class="hint">(optional)</span></label>
          <textarea id="tiaWizNotes" rows="4" maxlength="2000" placeholder="One or two sentences on the assessment, the population, or the testing context."><?= htmlspecialchars((string)($project['notes'] ?? ($demoMode ? $demoNotes : ''))) ?></textarea>
        </div>
      </div>

      <!-- ===== STEP 2 (Evidence Intake embedded) ===== -->
      <div class="tia-step-panel embed-intake" data-step="2">
        <h2>Bring in your test data</h2>
        <p class="wiz-sub">Upload a CSV or Excel file of student × item responses. The Intake walks through column types and then the answer key + scoring. The wizard returns you here when you're done.</p>
        <?php include __DIR__ . '/apps/evidence-intake/render.php'; ?>
      </div>

      <!-- ===== STEP 3 ===== -->
      <div class="tia-step-panel" data-step="3">
        <h2>Scoring defaults</h2>
        <p class="wiz-sub">Pick how you want item scores reported by default. You can change this per analysis later.</p>
        <div id="tiaWizScoringOpts">
          <?php
            $scoringOpts = [
              ['proportion', 'Proportion correct (0.00–1.00)', 'The default. Each item is 1 point; the score is the share answered correctly.'],
              ['raw',        'Raw count correct',              'Score = number of items answered correctly. Useful when comparing classes with the same test length.'],
              ['percent',    'Percent correct (0–100)',        'Same as proportion, scaled to 0–100 for reporting.'],
            ];
            foreach ($scoringOpts as $o) {
              $on = ($o[0] === $initScoring);
              echo '<label class="wiz-opt' . ($on ? ' is-on' : '') . '" data-val="' . htmlspecialchars($o[0]) . '">'
                . '<input type="radio" name="tiaScoring"' . ($on ? ' checked' : '') . '>'
                . '<div><strong>' . htmlspecialchars($o[1]) . '</strong><div class="opt-help">' . htmlspecialchars($o[2]) . '</div></div>'
                . '</label>';
            }
          ?>
        </div>
      </div>

    </div>

    <div class="tia-modal-foot">
      <button type="button" class="btn-ghost" id="tiaWizBackBtn">&larr; Back</button>
      <button type="button" class="btn-primary" id="tiaWizNextBtn">Continue &rarr;</button>
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
  const state = {
    step:      <?= (int)$initStep ?>,
    projectId: <?= (int)$projectId ?>,
    title:     <?= json_encode((string)($project['title'] ?? '')) ?>,
    notes:     <?= json_encode((string)($project['notes'] ?? '')) ?>,
    scoring:   <?= json_encode($initScoring) ?>,
  };

  const stepCards = document.querySelectorAll('[data-step-card]');
  const panels    = document.querySelectorAll('.tia-step-panel');
  const titleEl   = document.getElementById('tiaWizDialogTitle');
  const backBtn   = document.getElementById('tiaWizBackBtn');
  const nextBtn   = document.getElementById('tiaWizNextBtn');
  const closeBtn  = document.getElementById('tiaWizCloseBtn');

  function showStep(n, options) {
    options = options || {};
    state.step = n;
    stepCards.forEach(c => {
      const k = parseInt(c.getAttribute('data-step-card'), 10);
      c.classList.toggle('is-active', k === n);
      c.classList.toggle('is-done',   k < n);
    });
    panels.forEach(p => {
      const k = parseInt(p.getAttribute('data-step'), 10);
      p.classList.toggle('is-active', k === n);
    });
    const titleSuffix = { 1: 'Set Up', 2: 'Upload', 3: 'Review' };
    titleEl.textContent = n === 1
      ? (state.title ? state.title + ' — Set Up' : 'New TIA project — Set Up')
      : (state.title || 'Your test') + ' — ' + titleSuffix[n];
    backBtn.style.visibility = (n === 1) ? 'hidden' : 'visible';
    nextBtn.textContent = (n === 3) ? 'Start analyzing →' : 'Continue →';
    if (!options.skipUrlSync) {
      const url = new URL(window.location.href);
      url.searchParams.set('step', String(n));
      if (state.projectId) url.searchParams.set('project_id', String(state.projectId));
      window.history.replaceState({ step: n }, '', url.toString());
    }
  }

  function commitStep1() {
    const title = document.getElementById('tiaWizTitle').value.trim();
    const notes = document.getElementById('tiaWizNotes').value.trim();
    const err   = document.getElementById('tiaWizTitleErr');
    if (!title) { err.classList.add('is-on'); return Promise.reject(); }
    err.classList.remove('is-on');
    state.title = title; state.notes = notes;
    nextBtn.disabled = true; const orig = nextBtn.textContent; nextBtn.textContent = 'Saving…';

    const isUpdate = state.projectId > 0;
    const url    = isUpdate ? '/api/tia/project.php'  : '/api/tia/projects.php';
    const method = isUpdate ? 'PATCH' : 'POST';
    const body   = isUpdate ? { id: state.projectId, title, notes } : { title, notes };
    return fetch(url, {
      method, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
    })
      .then(r => r.json())
      .then(data => {
        if (!data.ok) throw new Error(data.message || 'Could not save');
        const pid = (data.project && data.project.id) || state.projectId;
        state.projectId = pid;
        window.RELICHECK_PROJECT_ID = pid;
      })
      .finally(() => { nextBtn.disabled = false; nextBtn.textContent = orig; });
  }

  document.addEventListener('relicheck:intake-complete', function () {
    if (state.step === 2) showStep(3);
  });

  const scoringHost = document.getElementById('tiaWizScoringOpts');
  if (scoringHost) {
    scoringHost.querySelectorAll('.wiz-opt').forEach(o => o.addEventListener('click', function (e) {
      e.preventDefault();
      const v = o.getAttribute('data-val');
      state.scoring = v;
      scoringHost.querySelectorAll('.wiz-opt').forEach(x => {
        const on = x.getAttribute('data-val') === v;
        x.classList.toggle('is-on', on);
        const r = x.querySelector('input[type="radio"]');
        if (r) r.checked = on;
      });
    }));
  }

  function commitFinish() {
    nextBtn.disabled = true; const orig = nextBtn.textContent; nextBtn.textContent = 'Finishing…';
    return fetch('/api/tia/project.php', {
      method: 'PATCH', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: state.projectId, settings: { scoring_mode: state.scoring } }),
    })
      .then(r => r.json())
      .then(d => { if (!d.ok) throw new Error(d.message || 'Could not save scoring'); })
      .then(() => { window.location.href = '/project-snapshot.php?studio=tia&project_id=' + encodeURIComponent(state.projectId); })
      .catch(err => { alert(err.message || err); nextBtn.disabled = false; nextBtn.textContent = orig; });
  }

  nextBtn.addEventListener('click', function () {
    if (state.step === 1) commitStep1().then(() => showStep(2)).catch(() => {});
    else if (state.step === 2) showStep(3);
    else if (state.step === 3) commitFinish();
  });
  backBtn.addEventListener('click', function () { if (state.step > 1) showStep(state.step - 1); });
  closeBtn.addEventListener('click', function () {
    if (state.projectId) window.location.href = '/studio-tia-projects.php?project_id=' + encodeURIComponent(state.projectId);
    else                 window.location.href = '/studio-tia.php';
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeBtn.click();
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') nextBtn.click();
  });

  showStep(state.step, { skipUrlSync: true });
})();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
