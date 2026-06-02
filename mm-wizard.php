<?php
// MM Studio Wizard (v4) — single-popup edition.
// -------------------------------------------------------------------
// The whole 3-step wizard lives in ONE modal-style popup that opens
// over the Platform Shell. JS drives step transitions — no full-page
// reloads between steps. Step 2 (Structure Data) embeds the Evidence
// Intake engine inline so the user never leaves the popup.
//
// Outer steps:
//   1. Set Up        title + description (creates the MM project)
//   2. Structure     Evidence Intake engine (embedded)
//   3. Choose Design data_kind + purpose + design_choice (all in one panel)
//
// On finish: redirect to /project-snapshot.php?studio=mm&project_id=N.
//
// URL params:
//   ?step=1|2|3            deep-link to a specific step (default: 1)
//   ?project_id=N          required for step 2 and 3

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/mm-wizard.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$initStep   = max(1, min(5, (int)($_GET['step'] ?? 1)));
$projectId  = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
$demoMode   = !empty($_GET['demo']);

// Demo mode: pre-fill a friendly title + description so the user can click
// straight through. The Step 2 Evidence Intake also has a "Use sample data"
// button that loads the sample CSV.
$demoTitle = 'MM Sample Project — Belonging & Retention';
$demoDesc  = 'A read-only walk-through using the sample Belonging & Retention dataset. Click through Title → Upload Data (then "Use sample data") → Data Kind → Intent → Design.';

$project = null;
if ($projectId > 0) {
  $pdo  = db();
  $stmt = $pdo->prepare('SELECT id, title, notes FROM mm_projects WHERE id = :id AND user_id = :uid');
  $stmt->execute([':id' => $projectId, ':uid' => $uid]);
  $project = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$project) { header('Location: /mm-wizard.php?step=1'); exit; }
}
if ($initStep > 1 && !$project) { header('Location: /mm-wizard.php?step=1'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['mm'];

$user_full           = $user['name'] ?? $user['email'] ?? 'You';
$initials            = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$shell_page_title    = 'MM Studio Wizard — ReliCheck';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = $project ? (string)$project['title'] : 'New MM project';
$shell_body_attrs    = 'data-current-studio="mm" data-studio-landing="mm" data-mm-wizard-modal="1"';

include __DIR__ . '/_platform_shell_header.php';
?>

<!-- Unified upload widget CSS (reuses au-* classes from analysis-studio.css) -->
<link rel="stylesheet" href="/apps/analysis-studio/analysis-studio.css">

<style>
  :root {
    --landing-accent:      <?= htmlspecialchars($studio['accent']) ?>;
    --landing-accent-soft: <?= htmlspecialchars($studio['accent_soft']) ?>;
  }

  /* Modal backdrop covering the whole viewport beneath the Platform Shell content. */
  .mm-modal-backdrop {
    position: fixed; inset: 64px 0 0 0; /* leave the nav visible above */
    background: rgba(20, 24, 38, 0.42);
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
    z-index: 50;
    overflow-y: auto;
    padding: 32px 24px 60px;
    box-sizing: border-box;
  }

  /* The popup itself: one card, all steps inside. */
  .mm-modal {
    max-width: 980px; margin: 0 auto;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 60px rgba(8, 12, 24, 0.28), 0 2px 6px rgba(8, 12, 24, 0.12);
    overflow: hidden;
  }

  /* Sticky stepper bar at the top of the popup */
  .mm-modal-head {
    position: sticky; top: 0; z-index: 2;
    background: #fff;
    border-bottom: 1px solid var(--line);
    padding: 16px 22px 14px;
  }
  .mm-modal-titlebar {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; margin-bottom: 14px;
  }
  .mm-modal-titlebar .title-side {
    display: flex; align-items: center; gap: 10px;
    font-family: 'Fraunces', 'Georgia', serif;
    font-size: 16px; font-weight: 600; color: var(--ink-1, #1c2238);
  }
  .mm-modal-titlebar .title-side img { width: 22px; height: 22px; border-radius: 5px; }
  .mm-modal-close {
    background: transparent; border: none; cursor: pointer;
    width: 32px; height: 32px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--ink-4);
  }
  .mm-modal-close:hover { background: var(--bg-tint, #f2f4f8); color: var(--ink-1); }
  .mm-modal-steps {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px;
  }
  .mm-step-card {
    padding: 9px 11px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: #fff;
    transition: border-color 0.18s, padding 0.18s;
  }
  .mm-step-card.is-done   { border-color: #d2e9d8; background: #f2faf4; }
  .mm-step-card.is-active { border: 2px solid var(--landing-accent); padding: 8px 10px; }
  .mm-step-card .pip {
    display: inline-flex; align-items: center; justify-content: center;
    width: 20px; height: 20px; border-radius: 50%;
    background: #cfd6df; color: #fff;
    font-size: 10.5px; font-weight: 700;
  }
  .mm-step-card.is-done .pip   { background: #1f7a3a; }
  .mm-step-card.is-active .pip { background: var(--landing-accent); }
  .mm-step-card .label   { display: inline-block; margin-left: 6px; font-weight: 600; font-size: 12.5px; color: var(--ink-1); }
  .mm-step-card .sub     { color: var(--ink-4); font-size: 11.5px; line-height: 1.3; margin: 3px 0 0 26px; }

  /* Step panels */
  .mm-modal-body { padding: 26px 30px; }
  .mm-step-panel { display: none; }
  .mm-step-panel.is-active { display: block; }
  .mm-step-panel h2 {
    font-family: 'Fraunces', 'Georgia', serif;
    font-size: 22px; font-weight: 600; margin: 0 0 6px;
    color: var(--ink-1);
  }
  .mm-step-panel .wiz-sub { color: var(--ink-3); font-size: 14px; margin: 0 0 18px; line-height: 1.5; }

  /* Form fields */
  .wiz-field { margin-bottom: 18px; }
  .wiz-field label { display: block; font-weight: 600; font-size: 13.5px; margin-bottom: 6px; color: var(--ink-2); }
  .wiz-field .hint { font-weight: 400; color: var(--ink-5); font-size: 12.5px; margin-left: 6px; }
  .wiz-field input[type="text"],
  .wiz-field textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font-size: 14px; box-sizing: border-box; font-family: inherit; resize: vertical; }
  .wiz-field input[type="text"]:focus,
  .wiz-field textarea:focus { outline: 2px solid var(--landing-accent-soft); border-color: var(--landing-accent); }
  .wiz-err { color: #c2492f; font-size: 13px; margin-top: 6px; display: none; }
  .wiz-err.is-on { display: block; }

  /* (Sub-stepper removed - A/B/C are now first-class outer steps 3, 4, 5.) */

  .wiz-opt {
    display: flex; align-items: flex-start; gap: 12px;
    border: 1px solid var(--line); border-radius: 10px;
    padding: 12px 14px; margin-bottom: 8px;
    background: #fff; cursor: pointer; user-select: none;
  }
  .wiz-opt:hover { border-color: var(--landing-accent); }
  .wiz-opt.is-on { border-color: var(--landing-accent); background: var(--landing-accent-soft); }
  .wiz-opt input { margin-top: 3px; flex-shrink: 0; }
  .wiz-opt strong { font-size: 13.5px; color: var(--ink-1); }
  .wiz-opt .opt-help { color: var(--ink-4); font-size: 12.5px; margin-top: 3px; line-height: 1.4; }
  .wiz-opt-pill {
    display: inline-block; margin-left: 8px;
    background: #1f7a3a; color: #fff;
    font-size: 10.5px; padding: 2px 7px; border-radius: 999px;
    font-weight: 600;
  }

  /* Sticky action bar at the bottom of the popup */
  .mm-modal-foot {
    position: sticky; bottom: 0; z-index: 2;
    background: #fff; border-top: 1px solid var(--line);
    padding: 14px 22px;
    display: flex; align-items: center; justify-content: space-between;
  }
  .btn-primary { background: var(--ink-1); color: #fff; border: 1px solid var(--ink-1); border-radius: 999px; cursor: pointer; font-weight: 600; padding: 9px 18px; font-size: 14px; }
  .btn-primary:hover { background: var(--landing-accent); border-color: var(--landing-accent); }
  .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
  .btn-ghost { background: #fff; color: var(--ink-3); border: 1px solid var(--line); border-radius: 999px; cursor: pointer; font-weight: 600; padding: 9px 18px; font-size: 14px; text-decoration: none; display: inline-block; }
  .btn-ghost:hover { border-color: var(--ink-4); }

  /* Step 2 embed: DatasetUpload inline styles */
  .du-embed { padding: 0; }
  .du-dropzone { border: 2px dashed #c8d0df; border-radius: 12px; padding: 32px 20px; text-align: center; }
  .du-dropzone.over { border-color: var(--landing-accent); background: var(--landing-accent-soft); }
  .du-drop-icon { width: 40px; height: 40px; color: #7b8fad; margin: 0 auto 12px; display: block; }
  .du-drop-h { font-weight: 600; font-size: 15px; color: var(--ink-1); margin-bottom: 6px; }
  .du-drop-or { color: var(--ink-5); font-size: 13px; margin-bottom: 10px; }
  .du-file-types { color: var(--ink-5); font-size: 12px; margin-top: 10px; line-height: 1.4; }
  .du-chosen { margin-top: 10px; font-size: 13px; color: var(--ink-2); display: flex; align-items: center; gap: 6px; }
  .du-change { background: none; border: none; color: var(--landing-accent); cursor: pointer; font-size: 13px; text-decoration: underline; padding: 0; margin-left: 6px; }
  .du-embed-actions { margin-top: 18px; }
  .du-embed-submit { width: 100%; }
</style>

<div class="mm-modal-backdrop" id="mmWizBackdrop">
  <div class="mm-modal" role="dialog" aria-modal="true" aria-labelledby="mmWizDialogTitle">

    <!-- Sticky head: title + 3-step card stepper -->
    <div class="mm-modal-head">
      <div class="mm-modal-titlebar">
        <div class="title-side">
          <img src="<?= htmlspecialchars($studio['mark']) ?>" alt="">
          <span id="mmWizDialogTitle">New MM project</span>
        </div>
        <button type="button" class="mm-modal-close" id="mmWizCloseBtn" aria-label="Close wizard" title="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="mm-modal-steps">
        <?php
          $stops = [
            1 => ['Set Up',      'Name your study.'],
            2 => ['Upload Data', 'CSV, Excel, or JSON.'],
          ];
          foreach ($stops as $n => $info) {
            echo '<div class="mm-step-card" data-step-card="' . $n . '">'
              . '<span class="pip">' . $n . '</span>'
              . '<span class="label">' . htmlspecialchars($info[0]) . '</span>'
              . '<div class="sub">' . htmlspecialchars($info[1]) . '</div>'
              . '</div>';
          }
        ?>
      </div>
    </div>

    <!-- Body: 3 panels, only one visible at a time -->
    <div class="mm-modal-body">

      <!-- ===== STEP 1: Set Up ===== -->
      <div class="mm-step-panel" data-step="1">
        <h2>Title your study</h2>
        <p class="wiz-sub">Start with the study itself. A clear title now makes the work easier to come back to.</p>
        <div class="wiz-field">
          <label for="mmWizTitle">Study title <span style="color:#c2492f;">*</span></label>
          <input id="mmWizTitle" type="text" maxlength="200" placeholder="e.g., 2026 Workplace Engagement Study" value="<?= htmlspecialchars((string)($project['title'] ?? ($demoMode ? $demoTitle : ''))) ?>">
          <p class="wiz-err" id="mmWizTitleErr">Add a study title to continue.</p>
        </div>
        <div class="wiz-field">
          <label for="mmWizDesc">Description <span class="hint">(optional)</span></label>
          <textarea id="mmWizDesc" rows="4" maxlength="2000" placeholder="A brief description of the study, the population, the research question, or anything that will help you orient when you return to this project."><?= htmlspecialchars((string)($project['notes'] ?? ($demoMode ? $demoDesc : ''))) ?></textarea>
        </div>
      </div>

      <!-- ===== STEP 2: Upload Data ===== -->
      <div class="mm-step-panel" data-step="2">
        <h2>Bring in your data</h2>
        <p class="wiz-sub">Upload a CSV, Excel, TSV, or JSON file. Column types are detected automatically. You can review and adjust them in the Data Map inside the studio.</p>
        <div id="mmEmbedUpload"></div>
      </div>

    </div>

    <!-- Sticky foot: Back / Continue -->
    <div class="mm-modal-foot">
      <button type="button" class="btn-ghost" id="mmWizBackBtn">&larr; Back</button>
      <button type="button" class="btn-primary" id="mmWizNextBtn">Continue &rarr;</button>
    </div>

  </div>
</div>

<script src="/apps/studio/dataset-upload.js" defer></script>
<script>
(function () {
  // 2 steps: 1=Set Up (title + description), 2=Upload Data.
  const state = {
    step:      <?= (int)min($initStep, 2) ?>,
    projectId: <?= (int)$projectId ?>,
    title:     <?= json_encode((string)($project['title'] ?? '')) ?>,
  };

  const stepCards = document.querySelectorAll('[data-step-card]');
  const panels    = document.querySelectorAll('.mm-step-panel');
  const titleEl   = document.getElementById('mmWizDialogTitle');
  const backBtn   = document.getElementById('mmWizBackBtn');
  const nextBtn   = document.getElementById('mmWizNextBtn');
  const closeBtn  = document.getElementById('mmWizCloseBtn');

  let embedMounted = false;

  function showStep(n, options) {
    options = options || {};
    state.step = n;
    stepCards.forEach(function (c) {
      const k = parseInt(c.getAttribute('data-step-card'), 10);
      c.classList.toggle('is-active', k === n);
      c.classList.toggle('is-done', k < n);
    });
    panels.forEach(function (p) {
      const k = parseInt(p.getAttribute('data-step'), 10);
      p.classList.toggle('is-active', k === n);
    });
    titleEl.textContent = n === 1
      ? (state.title ? state.title + ' — Set Up' : 'New MM project')
      : (state.title || 'Your project') + ' — Upload Data';

    backBtn.style.visibility = (n === 1) ? 'hidden' : 'visible';
    nextBtn.style.visibility = (n === 2) ? 'hidden' : 'visible';
    nextBtn.textContent = 'Continue →';

    if (!options.skipUrlSync) {
      const url = new URL(window.location.href);
      url.searchParams.set('step', String(n));
      if (state.projectId) url.searchParams.set('project_id', String(state.projectId));
      window.history.replaceState({ step: n }, '', url.toString());
    }

    // Mount the upload widget once when step 2 first becomes visible.
    if (n === 2 && !embedMounted && state.projectId) {
      embedMounted = true;
      if (window.DatasetUpload) {
        DatasetUpload.embed('#mmEmbedUpload', {
          projectId:   state.projectId,
          projectType: 'mm',
          onLoaded:    function (_ds, pid) {
            window.location.href = '/mmstudioV4.php?project_id=' + encodeURIComponent(pid || state.projectId);
          },
        });
      }
    }
  }

  function commitStep1() {
    const title = document.getElementById('mmWizTitle').value.trim();
    const desc  = document.getElementById('mmWizDesc').value.trim();
    const err   = document.getElementById('mmWizTitleErr');
    if (!title) { err.classList.add('is-on'); return Promise.reject(); }
    err.classList.remove('is-on');
    state.title = title;
    nextBtn.disabled = true;
    const orig = nextBtn.textContent;
    nextBtn.textContent = 'Saving…';

    const isUpdate = state.projectId > 0;
    const url    = isUpdate ? '/api/mm/project.php'  : '/api/mm/projects.php';
    const method = isUpdate ? 'PATCH' : 'POST';
    const body   = isUpdate ? { id: state.projectId, title, notes: desc } : { title, notes: desc };

    return fetch(url, {
      method, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.message || 'Could not save');
        state.projectId = (data.project && data.project.id) || state.projectId;
      })
      .finally(function () { nextBtn.disabled = false; nextBtn.textContent = orig; });
  }

  nextBtn.addEventListener('click', function () {
    if (state.step === 1) commitStep1().then(function () { showStep(2); }).catch(function () {});
  });
  backBtn.addEventListener('click', function () {
    if (state.step > 1) showStep(state.step - 1);
  });
  closeBtn.addEventListener('click', function () {
    window.location.href = state.projectId
      ? '/mmstudioV4.php?project_id=' + encodeURIComponent(state.projectId)
      : '/mmstudioV4.php';
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeBtn.click();
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') nextBtn.click();
  });

  showStep(state.step, { skipUrlSync: true });
})();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
