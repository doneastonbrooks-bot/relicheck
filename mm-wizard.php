<?php
// MM Studio Wizard — title + description form, then DatasetUpload.open().
// Step 1: user fills title + description (creates the mm_projects row).
// On Continue: DatasetUpload.open() fires (same widget as D/I Studio).
// On upload complete: redirect to mmstudioV4.php?project_id=N.

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
  /* Body */
  .mm-modal-body { padding: 26px 30px; }
  .mm-modal-body h2 {
    font-family: 'Fraunces', 'Georgia', serif;
    font-size: 22px; font-weight: 600; margin: 0 0 6px;
    color: var(--ink-1);
  }
  .wiz-sub { color: var(--ink-3); font-size: 14px; margin: 0 0 18px; line-height: 1.5; }

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

    </div>

    <!-- Body -->
    <div class="mm-modal-body">
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

    <!-- Sticky foot -->
    <div class="mm-modal-foot">
      <button type="button" class="btn-primary" id="mmWizNextBtn">Continue &rarr;</button>
    </div>

  </div>
</div>

<script src="/apps/studio/dataset-upload.js" defer></script>
<script>
(function () {
  const state = {
    projectId: <?= (int)$projectId ?>,
  };

  const nextBtn  = document.getElementById('mmWizNextBtn');
  const closeBtn = document.getElementById('mmWizCloseBtn');

  function openUpload() {
    if (!window.DatasetUpload) { setTimeout(openUpload, 80); return; }
    DatasetUpload.open({
      projectType: 'mm',
      projectId:   state.projectId,
      onLoaded:    function (_ds, pid) {
        window.location.href = '/mmstudioV4.php?project_id=' + encodeURIComponent(pid || state.projectId);
      },
    });
  }

  function commitAndUpload() {
    const title = document.getElementById('mmWizTitle').value.trim();
    const desc  = document.getElementById('mmWizDesc').value.trim();
    const err   = document.getElementById('mmWizTitleErr');
    if (!title) { err.classList.add('is-on'); return; }
    err.classList.remove('is-on');
    nextBtn.disabled = true;
    nextBtn.textContent = 'Saving…';

    const isUpdate = state.projectId > 0;
    const url    = isUpdate ? '/api/mm/project.php'  : '/api/mm/projects.php';
    const method = isUpdate ? 'PATCH' : 'POST';
    const body   = isUpdate ? { id: state.projectId, title, notes: desc } : { title, notes: desc };

    fetch(url, {
      method, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.message || 'Could not save');
        state.projectId = (data.project && data.project.id) || state.projectId;
        nextBtn.disabled = false; nextBtn.textContent = 'Continue →';
        openUpload();
      })
      .catch(function (e) {
        nextBtn.disabled = false; nextBtn.textContent = 'Continue →';
        alert(e && e.message ? e.message : 'Could not save. Please try again.');
      });
  }

  nextBtn.addEventListener('click', commitAndUpload);

  closeBtn.addEventListener('click', function () {
    window.location.href = state.projectId
      ? '/mmstudioV4.php?project_id=' + encodeURIComponent(state.projectId)
      : '/mmstudioV4.php';
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeBtn.click();
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') nextBtn.click();
  });
})();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
