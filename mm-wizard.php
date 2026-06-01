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
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id, title, notes, data_kinds, purposes FROM mm_projects WHERE id = :id AND user_id = :uid');
  $stmt->execute([':id' => $projectId, ':uid' => $uid]);
  $project = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$project) { header('Location: /mm-wizard.php?step=1'); exit; }

  // Best-effort chosen_design from mm_project_framing
  $project['chosen_design'] = '';
  try {
    $fs = $pdo->prepare('SELECT chosen_design FROM mm_project_framing WHERE project_id = :p LIMIT 1');
    $fs->execute([':p' => $projectId]);
    $fr = $fs->fetch(PDO::FETCH_ASSOC);
    if ($fr && !empty($fr['chosen_design'])) $project['chosen_design'] = (string)$fr['chosen_design'];
  } catch (Throwable $e) {}
}
if ($initStep > 1 && !$project) { header('Location: /mm-wizard.php?step=1'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['mm'];

// MM Evidence Intake config (embedded into Step 2).
// render.php reads $intake_config, so the variable name has to match.
$intake_config = require __DIR__ . '/apps/evidence-intake/configs/mm.config.php';

$user_full           = $user['name'] ?? $user['email'] ?? 'You';
$initials            = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$shell_page_title    = 'MM Studio Wizard — ReliCheck';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = $project ? (string)$project['title'] : 'New MM project';
// data-current-studio is what studio-template.css uses to flip --accent
// to the studio's color (violet for MM). Without it, --accent falls back
// to Survey orange and every .btn-primary on the page renders orange.
$shell_body_attrs    = 'data-current-studio="mm" data-studio-landing="mm" data-mm-wizard-modal="1"';

// Pre-decoded existing answers (for deep-linking back into the wizard)
$stepData = $project ? (json_decode((string)($project['data_kinds'] ?? '[]'), true) ?: []) : [];
$stepPurp = $project ? (json_decode((string)($project['purposes']   ?? '[]'), true) ?: []) : [];
$designMap = ['A_explain_numbers'=>'design_a','B_comments_to_themes'=>'design_b','C_compare_themes_groups'=>'design_c'];
$initDesign = $project ? ($designMap[$project['chosen_design']] ?? '') : '';

include __DIR__ . '/_platform_shell_header.php';
?>

<!-- Evidence Intake CSS (Step 2 is embedded inline) -->
<link rel="stylesheet" href="/apps/evidence-intake/evidence-intake.css">

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
    grid-template-columns: repeat(5, minmax(0, 1fr));
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

  /* Step 2 embed: hide the upload-app's stand-alone chrome, let it inherit the modal's padding */
  .embed-intake .upload-app { padding: 0 !important; }
  .embed-intake .upload-step { padding-top: 6px; }
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
            1 => ['Title',       'Name your study.'],
            2 => ['Upload Data', 'CSV, Excel, paste.'],
            3 => ['Data Kind',   'What did you collect?'],
            4 => ['Intent',      'What do you want to know?'],
            5 => ['Design',      'Pick a MM design.'],
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

      <!-- ===== STEP 2: Structure Data (Evidence Intake embedded inline) ===== -->
      <div class="mm-step-panel embed-intake" data-step="2">
        <h2>Bring in your data</h2>
        <p class="wiz-sub">Upload a CSV or Excel file, paste from a spreadsheet, or use the sample dataset. Then confirm what each column is. The wizard will return you here when you're done.</p>
        <?php include __DIR__ . '/apps/evidence-intake/render.php'; ?>
      </div>

      <!-- ===== STEP 3: Data kind ===== -->
      <div class="mm-step-panel" data-step="3">
        <h2>What kind of data do you have?</h2>
        <p class="wiz-sub">Select all that apply. Mixed-methods work often spans more than one. Your choices shape the rest of the project.</p>
        <div id="mmWizDataKindOpts">
            <?php
              $opts = [
                ['open_ended_only',           'Open-ended responses only',                                'Comments, interview text, focus group notes, or any qualitative responses with no numeric scores attached.'],
                ['survey_plus_open',          'Survey data with open-ended responses',                    'Closed-ended survey items plus one or more "please explain" comment columns.'],
                ['survey_plus_separate_qual', 'Survey data and separate interview / focus group data',    'Quantitative survey results AND qualitative data collected separately.'],
                ['quant_only_with_qual',      'Quantitative data only, but I want to add qualitative interpretation', 'Scores or metrics already in hand; you plan to add qualitative interpretation now.'],
                ['from_scratch',              'I want to build a mixed-methods study from scratch',       'No data yet. Start the project structure and add data later.'],
              ];
              foreach ($opts as $o) {
                $on = in_array($o[0], $stepData, true);
                echo '<label class="wiz-opt' . ($on ? ' is-on' : '') . '" data-val="' . htmlspecialchars($o[0]) . '">'
                  . '<input type="checkbox"' . ($on ? ' checked' : '') . '>'
                  . '<div><strong>' . htmlspecialchars($o[1]) . '</strong><div class="opt-help">' . htmlspecialchars($o[2]) . '</div></div>'
                  . '</label>';
              }
            ?>
          </div>
      </div>

      <!-- ===== STEP 4: Intent ===== -->
      <div class="mm-step-panel" data-step="4">
        <h2>What are you trying to understand?</h2>
        <p class="wiz-sub">Select all that apply. You can change these later.</p>
        <div id="mmWizPurposeOpts">
            <?php
              $opts = [
                ['explain_survey_results',   'Explain survey results',                  'Use comments to explain why the numbers came out the way they did.'],
                ['find_themes',              'Find themes in open-ended responses',     'Group qualitative responses into the patterns that recur most.'],
                ['compare_groups',           'Compare groups',                          'See how themes or scores differ across roles, departments, or other groups.'],
                ['build_variables_from_text','Build variables from text',               'Turn open-ended responses into quantitative variables for statistical analysis.'],
                ['strengthen_report',        'Strengthen a report with qualitative evidence', 'Bring quotes and themes into a report driven by quantitative findings.'],
                ['mixed_methods_section',    'Create a mixed-methods findings section', 'Produce a full integrated findings section with joint displays.'],
                ['evaluation_accreditation', 'Prepare evaluation or accreditation evidence', 'Generate a defensible evidence package for an evaluation or accreditation review.'],
                ['pre_survey_exploration',   'Explore patterns before building a survey',    'Use qualitative data to find the constructs worth measuring next.'],
              ];
              foreach ($opts as $o) {
                $on = in_array($o[0], $stepPurp, true);
                echo '<label class="wiz-opt' . ($on ? ' is-on' : '') . '" data-val="' . htmlspecialchars($o[0]) . '">'
                  . '<input type="checkbox"' . ($on ? ' checked' : '') . '>'
                  . '<div><strong>' . htmlspecialchars($o[1]) . '</strong><div class="opt-help">' . htmlspecialchars($o[2]) . '</div></div>'
                  . '</label>';
              }
            ?>
          </div>
      </div>

      <!-- ===== STEP 5: Design ===== -->
      <div class="mm-step-panel" data-step="5">
        <h2>Choose your mixed-methods design</h2>
        <p class="wiz-sub">Three classic designs. We highlight one as <em>Recommended</em> based on your Data Kind and Intent answers. The final choice is yours.</p>
        <div id="mmWizDesignOpts" data-current="<?= htmlspecialchars($initDesign) ?>">
            <?php
              $designs = [
                ['design_a','Explanatory Sequential','Quant first, then qual. Run the numbers, then use comments to explain why they came out the way they did.'],
                ['design_b','Exploratory Sequential','Qual first, then quant. Find themes in open-ended data, then turn them into variables or test them with numbers.'],
                ['design_c','Convergent Parallel','Both at once. Analyze quant and qual independently and compare them side by side in a joint display.'],
              ];
              foreach ($designs as $d) {
                $on = ($d[0] === $initDesign);
                echo '<label class="wiz-opt' . ($on ? ' is-on' : '') . '" data-val="' . htmlspecialchars($d[0]) . '" data-design>'
                  . '<input type="radio" name="mmWizDesign"' . ($on ? ' checked' : '') . '>'
                  . '<div><strong>' . htmlspecialchars($d[1]) . '</strong>'
                  . '<span class="wiz-opt-pill" data-rec hidden>Recommended</span>'
                  . '<div class="opt-help">' . htmlspecialchars($d[2]) . '</div></div>'
                  . '</label>';
              }
            ?>
        </div>
      </div>

    </div>

    <!-- Sticky foot: Back / Continue -->
    <div class="mm-modal-foot">
      <button type="button" class="btn-ghost" id="mmWizBackBtn">&larr; Back</button>
      <button type="button" class="btn-primary" id="mmWizNextBtn">Continue &rarr;</button>
    </div>

  </div>
</div>

<!-- Hand the MM Evidence Intake config to the engine -->
<script>
  // Mark the wizard host so the embedded Evidence Intake's "Ready" dispatches
  // a custom event instead of redirecting via window.location.
  window.RELICHECK_WIZARD_HOST = true;
  window.INTAKE_CONFIG = <?= json_encode($intake_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  window.RELICHECK_PROJECT_ID = <?= json_encode($projectId) ?>;
</script>
<script src="/apps/evidence-intake/evidence-intake.js" defer></script>

<script>
(function () {
  // ---------- State ----------
  // 5 outer steps: 1=Title, 2=Upload, 3=Data Kind, 4=Intent, 5=Design.
  const state = {
    step:      <?= (int)$initStep ?>,
    projectId: <?= (int)$projectId ?>,
    title:     <?= json_encode((string)($project['title'] ?? '')) ?>,
    desc:      <?= json_encode((string)($project['notes'] ?? '')) ?>,
    dataKinds: <?= json_encode($stepData) ?>,
    purposes:  <?= json_encode($stepPurp) ?>,
    design:    <?= json_encode($initDesign) ?>,
  };

  const stepCards = document.querySelectorAll('[data-step-card]');
  const panels    = document.querySelectorAll('.mm-step-panel');
  const titleEl   = document.getElementById('mmWizDialogTitle');
  const backBtn   = document.getElementById('mmWizBackBtn');
  const nextBtn   = document.getElementById('mmWizNextBtn');
  const closeBtn  = document.getElementById('mmWizCloseBtn');

  // ---------- Render ----------
  function showStep(n, options) {
    options = options || {};
    state.step = n;
    stepCards.forEach(c => {
      const k = parseInt(c.getAttribute('data-step-card'), 10);
      c.classList.toggle('is-active', k === n);
      c.classList.toggle('is-done', k < n);
    });
    panels.forEach(p => {
      const k = parseInt(p.getAttribute('data-step'), 10);
      p.classList.toggle('is-active', k === n);
    });
    const titleSuffix = { 1: 'Title', 2: 'Upload Data', 3: 'Data Kind', 4: 'Intent', 5: 'Design' };
    titleEl.textContent = n === 1
      ? (state.title ? state.title + ' — Title' : 'New MM project — Title')
      : (state.title || 'Your project') + ' — ' + titleSuffix[n];

    backBtn.style.visibility = (n === 1) ? 'hidden' : 'visible';
    // Step 2 is the embedded Evidence Intake, which renders its own
    // "Continue to analysis" button (#continueFromMap). That button is the
    // only path that runs finishWizard() → saves the dataset to
    // relicheck.dataset.<PID> → dispatches relicheck:intake-complete.
    // The wizard footer's Continue would advance to Step 3 without ever
    // committing the upload, producing the "old data populated" symptom.
    nextBtn.style.visibility = (n === 2) ? 'hidden' : 'visible';
    nextBtn.textContent = (n === 5) ? 'Start project →' : 'Continue →';

    // Keep URL in sync (no reload, so the back-button works and bookmarks land right)
    if (!options.skipUrlSync) {
      const url = new URL(window.location.href);
      url.searchParams.set('step', String(n));
      if (state.projectId) url.searchParams.set('project_id', String(state.projectId));
      window.history.replaceState({ step: n }, '', url.toString());
    }

    // Refresh the design recommendation pip whenever the Design step is shown
    if (n === 5) refreshDesignRecommendation();
  }

  // ---------- Step 1 logic ----------
  function commitStep1() {
    const title = document.getElementById('mmWizTitle').value.trim();
    const desc  = document.getElementById('mmWizDesc').value.trim();
    const err   = document.getElementById('mmWizTitleErr');
    if (!title) { err.classList.add('is-on'); return Promise.reject(); }
    err.classList.remove('is-on');

    state.title = title; state.desc = desc;
    nextBtn.disabled = true; const orig = nextBtn.textContent; nextBtn.textContent = 'Saving…';

    const isUpdate = state.projectId > 0;
    const url    = isUpdate ? '/api/mm/project.php'   : '/api/mm/projects.php';
    const method = isUpdate ? 'PATCH' : 'POST';
    const body   = isUpdate ? { id: state.projectId, title, notes: desc } : { title, notes: desc };

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
      .finally(() => {
        nextBtn.disabled = false; nextBtn.textContent = orig;
      });
  }

  // ---------- Step 2 logic ----------
  // The embedded Evidence Intake fires 'relicheck:intake-complete' on document
  // when the user clicks Ready (because we set RELICHECK_WIZARD_HOST = true).
  // Listen for that and advance to Step 3 (Data Kind).
  document.addEventListener('relicheck:intake-complete', function () {
    if (state.step === 2) showStep(3);
  });

  // ---------- Steps 3 & 4 (multi-select) ----------
  function wireMultiSelect(hostId, slot) {
    const host = document.getElementById(hostId);
    if (!host) return;
    const opts = host.querySelectorAll('.wiz-opt');
    opts.forEach(o => o.addEventListener('click', function (e) {
      e.preventDefault();
      const cb = o.querySelector('input[type="checkbox"]');
      cb.checked = !cb.checked;
      o.classList.toggle('is-on', cb.checked);
      const arr = [];
      opts.forEach(x => { if (x.querySelector('input').checked) arr.push(x.getAttribute('data-val')); });
      state[slot] = arr;
      // If the Design step is mounted, keep recommendation in sync
      refreshDesignRecommendation();
    }));
  }
  wireMultiSelect('mmWizDataKindOpts', 'dataKinds');
  wireMultiSelect('mmWizPurposeOpts',  'purposes');

  // Design (single select)
  const designHost = document.getElementById('mmWizDesignOpts');
  if (designHost) {
    designHost.querySelectorAll('[data-design]').forEach(o => o.addEventListener('click', function (e) {
      e.preventDefault();
      const v = o.getAttribute('data-val');
      state.design = v;
      designHost.querySelectorAll('[data-design]').forEach(x => {
        const on = x.getAttribute('data-val') === v;
        x.classList.toggle('is-on', on);
        const r = x.querySelector('input[type="radio"]');
        if (r) r.checked = on;
      });
    }));
  }

  function refreshDesignRecommendation() {
    if (!designHost) return;
    const dks = new Set(state.dataKinds || []);
    const ps  = new Set(state.purposes  || []);
    const rec = { design_a: false, design_b: false, design_c: false };
    if (dks.has('survey_plus_open') || ps.has('explain_survey_results')) rec.design_a = true;
    if (dks.has('open_ended_only')  || ps.has('find_themes') || ps.has('build_variables_from_text')) rec.design_b = true;
    if (ps.has('compare_groups') || ps.has('mixed_methods_section') || ps.has('evaluation_accreditation') || ps.has('strengthen_report')) rec.design_c = true;
    designHost.querySelectorAll('[data-design]').forEach(o => {
      const key = o.getAttribute('data-val');
      const pill = o.querySelector('[data-rec]');
      if (!pill) return;
      pill.hidden = !rec[key];
    });
  }
  refreshDesignRecommendation();

  function commitFinish() {
    if (!state.dataKinds.length) { alert('Go back and pick at least one data kind.'); return Promise.reject(); }
    if (!state.purposes.length)  { alert('Go back and pick at least one purpose.'); return Promise.reject(); }
    if (!state.design)           { alert('Pick a mixed-methods design before continuing.'); return Promise.reject(); }
    const designSrv = { design_a: 'A_explain_numbers', design_b: 'B_comments_to_themes', design_c: 'C_compare_themes_groups' }[state.design];
    nextBtn.disabled = true; const orig = nextBtn.textContent; nextBtn.textContent = 'Finishing…';

    const postWiz = (body) => fetch('/api/mm/wizard.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ project_id: state.projectId }, body)),
    }).then(r => r.json());

    return postWiz({ step: 'data_kind',     values: state.dataKinds })
      .then(d => { if (!d.ok) throw new Error(d.message || 'Could not save data kinds'); })
      .then(() => postWiz({ step: 'purpose', values: state.purposes }))
      .then(d => { if (!d.ok) throw new Error(d.message || 'Could not save purposes'); })
      .then(() => postWiz({ step: 'design_choice', value: designSrv }))
      .then(d => { if (!d.ok) throw new Error(d.message || 'Could not save design'); })
      .then(() => postWiz({ step: 'complete' }))
      .then(() => { window.location.href = '/project-snapshot.php?studio=mm&project_id=' + encodeURIComponent(state.projectId); })
      .catch(err => { alert(err.message || err); nextBtn.disabled = false; nextBtn.textContent = orig; });
  }

  // ---------- Navigation ----------
  nextBtn.addEventListener('click', function () {
    if (state.step === 1) {
      commitStep1().then(() => showStep(2)).catch(() => {});
    } else if (state.step === 2) {
      // No-op. Step 2's advance is owned by Evidence Intake's
      // "Continue to analysis" button (#continueFromMap), which fires
      // relicheck:intake-complete after saving the dataset. The wizard
      // footer's Continue is hidden on this step (see showStep), but the
      // Cmd/Ctrl+Enter shortcut can still fire this handler — keep it inert.
      return;
    } else if (state.step === 3) {
      if (!state.dataKinds.length) { alert('Pick at least one data kind before continuing.'); return; }
      showStep(4);
    } else if (state.step === 4) {
      if (!state.purposes.length) { alert('Pick at least one purpose before continuing.'); return; }
      showStep(5);
    } else if (state.step === 5) {
      commitFinish();
    }
  });
  backBtn.addEventListener('click', function () {
    if (state.step > 1) showStep(state.step - 1);
  });
  closeBtn.addEventListener('click', function () {
    if (state.projectId) {
      window.location.href = '/studio-mm-projects.php?project_id=' + encodeURIComponent(state.projectId);
    } else {
      window.location.href = '/studio-mm.php';
    }
  });

  // Allow Escape to close, Cmd/Ctrl+Enter to advance
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeBtn.click();
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') nextBtn.click();
  });

  // Initial render
  showStep(state.step, { skipUrlSync: true });
})();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
