<?php
// 360 Studio Wizard (v4) — single-popup edition. Modeled on mm-wizard.php.
// -------------------------------------------------------------------
// 360 panels are bound to an existing survey (per the survey_360_panels
// schema). The wizard therefore needs a survey picker in step 1.
//
// Three outer steps:
//   1. Set Up   panel name + pick the underlying survey
//   2. Upload   Evidence Intake (embedded) — ratee × rater data
//   3. Settings confidentiality + self-assessment toggle → launch
//
// On finish: POST /api/panels/create.php (with survey_id + name +
// confidentiality_mode + self_assessment), redirect to
// /project-snapshot.php?studio=360&project_id=N.
//
// URL params:
//   ?step=1|2|3            deep-link
//   ?project_id=N          required for step 2-3

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/360-wizard.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$initStep   = max(1, min(3, (int)($_GET['step'] ?? 1)));
$projectId  = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;

$project = null;
if ($projectId > 0) {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id, name AS title, survey_id, self_assessment, confidentiality_mode FROM survey_360_panels WHERE id = :id AND user_id = :uid');
  $stmt->execute([':id' => $projectId, ':uid' => $uid]);
  $project = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$project) { header('Location: /360-wizard.php?step=1'); exit; }
}
if ($initStep > 1 && !$project) { header('Location: /360-wizard.php?step=1'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['360'];

$intake_config = require __DIR__ . '/apps/evidence-intake/configs/360.config.php';

$user_full           = $user['name'] ?? $user['email'] ?? 'You';
$initials            = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$shell_page_title    = '360 Studio Wizard — ReliCheck';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = $project ? (string)$project['title'] : 'New 360 panel';
$shell_body_attrs    = 'data-current-studio="360" data-studio-landing="360" data-t6-wizard-modal="1"';

$initConfMode  = (string)($project['confidentiality_mode'] ?? 'anonymous');
$initSelfAssess= !empty($project['self_assessment']) ? 1 : 0;

include __DIR__ . '/_platform_shell_header.php';
?>

<link rel="stylesheet" href="/apps/evidence-intake/evidence-intake.css">

<style>
  :root {
    --landing-accent:      <?= htmlspecialchars($studio['accent']) ?>;
    --landing-accent-soft: <?= htmlspecialchars($studio['accent_soft']) ?>;
  }
  .t6-modal-backdrop { position: fixed; inset: 64px 0 0 0; background: rgba(20,24,38,0.42); backdrop-filter: blur(2px); -webkit-backdrop-filter: blur(2px); z-index: 50; overflow-y: auto; padding: 32px 24px 60px; box-sizing: border-box; }
  .t6-modal { max-width: 980px; margin: 0 auto; background: #fff; border-radius: 16px; box-shadow: 0 24px 60px rgba(8,12,24,0.28), 0 2px 6px rgba(8,12,24,0.12); overflow: hidden; }
  .t6-modal-head { position: sticky; top: 0; z-index: 2; background: #fff; border-bottom: 1px solid var(--line); padding: 16px 22px 14px; }
  .t6-modal-titlebar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
  .t6-modal-titlebar .title-side { display: flex; align-items: center; gap: 10px; font-family: 'Fraunces','Georgia',serif; font-size: 16px; font-weight: 600; color: var(--ink-1,#1c2238); }
  .t6-modal-titlebar .title-side img { width: 22px; height: 22px; border-radius: 5px; }
  .t6-modal-close { background: transparent; border: none; cursor: pointer; width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: var(--ink-4); }
  .t6-modal-close:hover { background: var(--bg-tint,#f2f4f8); color: var(--ink-1); }
  .t6-modal-steps { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 6px; }
  .t6-step-card { padding: 9px 11px; border: 1px solid var(--line); border-radius: 10px; background: #fff; transition: border-color 0.18s, padding 0.18s; }
  .t6-step-card.is-done { border-color: #d2e9d8; background: #f2faf4; }
  .t6-step-card.is-active { border: 2px solid var(--landing-accent); padding: 8px 10px; }
  .t6-step-card .pip { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: #cfd6df; color: #fff; font-size: 10.5px; font-weight: 700; }
  .t6-step-card.is-done .pip { background: #1f7a3a; }
  .t6-step-card.is-active .pip { background: var(--landing-accent); }
  .t6-step-card .label { display: inline-block; margin-left: 6px; font-weight: 600; font-size: 12.5px; color: var(--ink-1); }
  .t6-step-card .sub { color: var(--ink-4); font-size: 11.5px; line-height: 1.3; margin: 3px 0 0 26px; }
  .t6-modal-body { padding: 26px 30px; }
  .t6-step-panel { display: none; }
  .t6-step-panel.is-active { display: block; }
  .t6-step-panel h2 { font-family: 'Fraunces','Georgia',serif; font-size: 22px; font-weight: 600; margin: 0 0 6px; color: var(--ink-1); }
  .t6-step-panel .wiz-sub { color: var(--ink-3); font-size: 14px; margin: 0 0 18px; line-height: 1.5; }
  .wiz-field { margin-bottom: 18px; }
  .wiz-field label { display: block; font-weight: 600; font-size: 13.5px; margin-bottom: 6px; color: var(--ink-2); }
  .wiz-field .hint { font-weight: 400; color: var(--ink-5); font-size: 12.5px; margin-left: 6px; }
  .wiz-field input[type="text"], .wiz-field select { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font-size: 14px; box-sizing: border-box; font-family: inherit; background: #fff; }
  .wiz-field input[type="text"]:focus, .wiz-field select:focus { outline: 2px solid var(--landing-accent-soft); border-color: var(--landing-accent); }
  .wiz-err { color: #c2492f; font-size: 13px; margin-top: 6px; display: none; }
  .wiz-err.is-on { display: block; }
  .wiz-opt { display: flex; align-items: flex-start; gap: 12px; border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; margin-bottom: 8px; background: #fff; cursor: pointer; user-select: none; }
  .wiz-opt:hover { border-color: var(--landing-accent); }
  .wiz-opt.is-on { border-color: var(--landing-accent); background: var(--landing-accent-soft); }
  .wiz-opt input { margin-top: 3px; flex-shrink: 0; }
  .wiz-opt strong { font-size: 13.5px; color: var(--ink-1); }
  .wiz-opt .opt-help { color: var(--ink-4); font-size: 12.5px; margin-top: 3px; line-height: 1.4; }
  .t6-modal-foot { position: sticky; bottom: 0; z-index: 2; background: #fff; border-top: 1px solid var(--line); padding: 14px 22px; display: flex; align-items: center; justify-content: space-between; }
  .btn-primary { background: var(--ink-1); color: #fff; border: 1px solid var(--ink-1); border-radius: 999px; cursor: pointer; font-weight: 600; padding: 9px 18px; font-size: 14px; }
  .btn-primary:hover { background: var(--landing-accent); border-color: var(--landing-accent); }
  .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
  .btn-ghost { background: #fff; color: var(--ink-3); border: 1px solid var(--line); border-radius: 999px; cursor: pointer; font-weight: 600; padding: 9px 18px; font-size: 14px; text-decoration: none; display: inline-block; }
  .btn-ghost:hover { border-color: var(--ink-4); }
  .embed-intake .upload-app { padding: 0 !important; }
  .embed-intake .upload-step { padding-top: 6px; }
  .t6-survey-empty { padding: 14px 16px; background: var(--bg-tint, #f5f7fb); border: 1px dashed var(--line); border-radius: 10px; color: var(--ink-3); font-size: 13.5px; line-height: 1.5; }
  .t6-survey-empty a { color: var(--landing-accent); font-weight: 600; text-decoration: none; }
  .t6-toggle { display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; color: var(--ink-2); margin-top: 6px; }
</style>

<div class="t6-modal-backdrop" id="t6WizBackdrop">
  <div class="t6-modal" role="dialog" aria-modal="true" aria-labelledby="t6WizDialogTitle">

    <div class="t6-modal-head">
      <div class="t6-modal-titlebar">
        <div class="title-side">
          <img src="<?= htmlspecialchars($studio['mark']) ?>" alt="">
          <span id="t6WizDialogTitle">New 360 panel</span>
        </div>
        <button type="button" class="t6-modal-close" id="t6WizCloseBtn" aria-label="Close wizard" title="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="t6-modal-steps">
        <?php
          $stops = [
            1 => ['Set Up',   'Name and pick a survey.'],
            2 => ['Upload',   'Ratee × rater data.'],
            3 => ['Settings', 'Confidentiality, launch.'],
          ];
          foreach ($stops as $n => $info) {
            echo '<div class="t6-step-card" data-step-card="' . $n . '">'
              . '<span class="pip">' . $n . '</span>'
              . '<span class="label">' . htmlspecialchars($info[0]) . '</span>'
              . '<div class="sub">' . htmlspecialchars($info[1]) . '</div>'
              . '</div>';
          }
        ?>
      </div>
    </div>

    <div class="t6-modal-body">

      <!-- ===== STEP 1 ===== -->
      <div class="t6-step-panel" data-step="1">
        <h2>Name your panel and pick a survey</h2>
        <p class="wiz-sub">A 360 panel runs on top of one of your existing surveys. The survey defines the items raters answer. Pick one below or create a new survey first.</p>
        <div class="wiz-field">
          <label for="t6WizName">Panel name <span style="color:#c2492f;">*</span></label>
          <input id="t6WizName" type="text" maxlength="160" placeholder="e.g., 2026 Q1 Leadership 360" value="<?= htmlspecialchars((string)($project['title'] ?? '')) ?>">
          <p class="wiz-err" id="t6WizNameErr">Add a panel name to continue.</p>
        </div>
        <div class="wiz-field">
          <label for="t6WizSurvey">Underlying survey <span style="color:#c2492f;">*</span></label>
          <select id="t6WizSurvey"><option value="">Loading your surveys…</option></select>
          <p class="wiz-err" id="t6WizSurveyErr">Pick a survey to base the panel on.</p>
        </div>
      </div>

      <!-- ===== STEP 2 (Evidence Intake embedded) ===== -->
      <div class="t6-step-panel embed-intake" data-step="2">
        <h2>Bring in your rater data</h2>
        <p class="wiz-sub">Upload a CSV or Excel file of ratee × rater observations. The Intake walks through column types and roles, then returns you here.</p>
        <?php include __DIR__ . '/apps/evidence-intake/render.php'; ?>
      </div>

      <!-- ===== STEP 3 ===== -->
      <div class="t6-step-panel" data-step="3">
        <h2>Confidentiality and launch</h2>
        <p class="wiz-sub">Pick how rater identity is handled in reports, then start analyzing.</p>
        <div id="t6WizConfOpts">
          <?php
            $confOpts = [
              ['anonymous', 'Anonymous reporting (default)', 'Reports show ratings only when at least the confidentiality-threshold number of raters answered. Individual rater identities are never surfaced.'],
              ['named',     'Named raters',                  'Reports may surface individual ratings tied to a rater. Use only with explicit, informed consent from raters.'],
            ];
            foreach ($confOpts as $o) {
              $on = ($o[0] === $initConfMode);
              echo '<label class="wiz-opt' . ($on ? ' is-on' : '') . '" data-val="' . htmlspecialchars($o[0]) . '">'
                . '<input type="radio" name="t6Conf"' . ($on ? ' checked' : '') . '>'
                . '<div><strong>' . htmlspecialchars($o[1]) . '</strong><div class="opt-help">' . htmlspecialchars($o[2]) . '</div></div>'
                . '</label>';
            }
          ?>
        </div>
        <label class="t6-toggle">
          <input type="checkbox" id="t6WizSelfAssess" <?= $initSelfAssess ? 'checked' : '' ?>>
          <span>Include a self-assessment from each ratee (Self / Other gap analysis becomes available).</span>
        </label>
      </div>

    </div>

    <div class="t6-modal-foot">
      <button type="button" class="btn-ghost" id="t6WizBackBtn">&larr; Back</button>
      <button type="button" class="btn-primary" id="t6WizNextBtn">Continue &rarr;</button>
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
    name:      <?= json_encode((string)($project['title'] ?? '')) ?>,
    surveyId:  <?= (int)($project['survey_id'] ?? 0) ?>,
    conf:      <?= json_encode($initConfMode) ?>,
    selfAssess:<?= (int)$initSelfAssess ?>,
  };

  const stepCards = document.querySelectorAll('[data-step-card]');
  const panels    = document.querySelectorAll('.t6-step-panel');
  const titleEl   = document.getElementById('t6WizDialogTitle');
  const backBtn   = document.getElementById('t6WizBackBtn');
  const nextBtn   = document.getElementById('t6WizNextBtn');
  const closeBtn  = document.getElementById('t6WizCloseBtn');
  const surveySel = document.getElementById('t6WizSurvey');

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
    const titleSuffix = { 1: 'Set Up', 2: 'Upload', 3: 'Launch' };
    titleEl.textContent = n === 1
      ? (state.name ? state.name + ' — Set Up' : 'New 360 panel — Set Up')
      : (state.name || 'Your panel') + ' — ' + titleSuffix[n];
    backBtn.style.visibility = (n === 1) ? 'hidden' : 'visible';
    nextBtn.textContent = (n === 3) ? 'Launch panel →' : 'Continue →';
    if (!options.skipUrlSync) {
      const url = new URL(window.location.href);
      url.searchParams.set('step', String(n));
      if (state.projectId) url.searchParams.set('project_id', String(state.projectId));
      window.history.replaceState({ step: n }, '', url.toString());
    }
  }

  // Load existing surveys for the picker.
  (function loadSurveys() {
    fetch('/api/surveys/list.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(r => r.ok ? r.json() : { ok: false })
      .then(d => {
        const surveys = (d && d.ok && Array.isArray(d.surveys)) ? d.surveys : [];
        if (!surveys.length) {
          surveySel.innerHTML = '<option value="">— no surveys yet —</option>';
          surveySel.disabled = true;
          const empty = document.createElement('div');
          empty.className = 't6-survey-empty';
          empty.innerHTML = 'No surveys in your account yet. <a href="/survey-wizard.php?step=1">Start a survey first</a>, then come back here.';
          surveySel.parentNode.appendChild(empty);
          return;
        }
        surveySel.innerHTML = '<option value="">— pick a survey —</option>' +
          surveys.map(s => '<option value="' + s.id + '"' + (s.id === state.surveyId ? ' selected' : '') + '>' +
            escHtml(s.title || 'Untitled') + (s.is_published ? ' · published' : ' · draft') + '</option>').join('');
      })
      .catch(() => { surveySel.innerHTML = '<option value="">Could not load surveys</option>'; });
  })();
  function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }

  function commitStep1() {
    const name = document.getElementById('t6WizName').value.trim();
    const sid  = parseInt(surveySel.value, 10) || 0;
    const nameErr   = document.getElementById('t6WizNameErr');
    const surveyErr = document.getElementById('t6WizSurveyErr');
    let bad = false;
    if (!name) { nameErr.classList.add('is-on'); bad = true; } else { nameErr.classList.remove('is-on'); }
    if (!sid)  { surveyErr.classList.add('is-on'); bad = true; } else { surveyErr.classList.remove('is-on'); }
    if (bad) return Promise.reject();

    state.name = name; state.surveyId = sid;
    nextBtn.disabled = true; const orig = nextBtn.textContent; nextBtn.textContent = 'Saving…';

    if (state.projectId > 0) {
      // Already created — no rename API surfaced for panels in v4; just advance.
      nextBtn.disabled = false; nextBtn.textContent = orig;
      return Promise.resolve();
    }
    return fetch('/api/panels/create.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ survey_id: state.surveyId, name: state.name, self_assessment: !!state.selfAssess, confidentiality_mode: state.conf }),
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) throw new Error(d.message || 'Could not create panel');
        state.projectId = d.panel_id;
        window.RELICHECK_PROJECT_ID = d.panel_id;
      })
      .finally(() => { nextBtn.disabled = false; nextBtn.textContent = orig; });
  }

  document.addEventListener('relicheck:intake-complete', function () {
    if (state.step === 2) showStep(3);
  });

  const confHost = document.getElementById('t6WizConfOpts');
  if (confHost) {
    confHost.querySelectorAll('.wiz-opt').forEach(o => o.addEventListener('click', function (e) {
      e.preventDefault();
      const v = o.getAttribute('data-val');
      state.conf = v;
      confHost.querySelectorAll('.wiz-opt').forEach(x => {
        const on = x.getAttribute('data-val') === v;
        x.classList.toggle('is-on', on);
        const r = x.querySelector('input[type="radio"]');
        if (r) r.checked = on;
      });
    }));
  }
  const selfCb = document.getElementById('t6WizSelfAssess');
  if (selfCb) selfCb.addEventListener('change', () => { state.selfAssess = selfCb.checked ? 1 : 0; });

  function commitFinish() {
    nextBtn.disabled = true; const orig = nextBtn.textContent; nextBtn.textContent = 'Finishing…';
    // No update endpoint is wired yet for panel settings post-create; settings
    // were captured at /api/panels/create.php. Jump to the snapshot.
    window.location.href = '/project-snapshot.php?studio=360&project_id=' + encodeURIComponent(state.projectId);
  }

  nextBtn.addEventListener('click', function () {
    if (state.step === 1) commitStep1().then(() => showStep(2)).catch(() => {});
    else if (state.step === 2) showStep(3);
    else if (state.step === 3) commitFinish();
  });
  backBtn.addEventListener('click', function () { if (state.step > 1) showStep(state.step - 1); });
  closeBtn.addEventListener('click', function () {
    if (state.projectId) window.location.href = '/studio-360-projects.php?project_id=' + encodeURIComponent(state.projectId);
    else                 window.location.href = '/studio-360.php';
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeBtn.click();
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') nextBtn.click();
  });

  showStep(state.step, { skipUrlSync: true });
})();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
