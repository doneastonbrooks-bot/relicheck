<?php
// Survey Studio Wizard (v4).
// -------------------------------------------------------------------
// 3 outer steps:
//   1. Set Up        title + description (creates the survey)
//   2. Data          upload via Evidence Intake (handed off to
//                    /evidence-intake.php?studio=strength-survey&wizard=1)
//   3. Scales        confirm Likert scales and scoring direction
//                    (reads dataset from localStorage; persists scale
//                    settings to settings JSON on the survey record)
//
// On complete: redirect to /strength-index.php?studio=strength-survey&project_id=N
// (the Strength Index is Survey's headline analysis).
//
// Backend:
//   POST   /api/surveys/create.php   { title }       → { survey: {...} }
//   PATCH  /api/surveys/update.php   { id, title?, description?, settings? }
//   GET    /api/surveys/get.php?id=N

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/strength-survey-wizard.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$step       = max(1, min(3, (int)($_GET['step'] ?? 1)));
$projectId  = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;

$project = null;
if ($projectId > 0) {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id, title, description, settings FROM surveys WHERE id = :id AND owner_id = :uid AND (archived_at IS NULL)');
  $stmt->execute([':id' => $projectId, ':uid' => $uid]);
  $project = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$project) { header('Location: /strength-survey-wizard.php?step=1'); exit; }
}
if ($step > 1 && !$project) { header('Location: /strength-survey-wizard.php?step=1'); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['strength-survey'];

$user_full           = $user['name'] ?? $user['email'] ?? 'You';
$initials            = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user_full) ?: 'U', 0, 2));
$shell_page_title    = 'Survey Studio Wizard — ReliCheck';
$shell_user_initials = $initials;
$shell_user_full     = $user_full;
$shell_project_label = $project ? (string)$project['title'] : 'New survey';
$shell_body_attrs    = 'data-current-studio="strength-survey" data-studio-landing="strength-survey" data-survey-wizard="' . $step . '"';

include __DIR__ . '/_platform_shell_header.php';
?>

<style>
  :root {
    --landing-accent:      <?= htmlspecialchars($studio['accent']) ?>;
    --landing-accent-soft: <?= htmlspecialchars($studio['accent_soft']) ?>;
  }
  .wiz-shell { max-width: 980px; margin: 0 auto; padding: 28px 0 60px; }
  .wiz-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
  .wiz-back { font-size: 13px; color: var(--ink-4); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
  .wiz-back:hover { color: var(--ink-2); }
  .wiz-steps { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; margin-bottom: 24px; }
  .wiz-step-card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; }
  .wiz-step-card .pip { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #cfd6df; color: #fff; font-size: 11px; font-weight: 700; }
  .wiz-step-card.is-done .pip { background: #1f7a3a; }
  .wiz-step-card.is-active { border-color: var(--landing-accent); border-width: 2px; padding: 11px 13px; }
  .wiz-step-card.is-active .pip { background: var(--landing-accent); }
  .wiz-step-card .label { display: inline-block; margin-left: 8px; font-weight: 600; font-size: 13.5px; color: var(--ink-1); }
  .wiz-step-card .sub { color: var(--ink-4); font-size: 12.5px; line-height: 1.4; margin: 4px 0 0 30px; }
  .wiz-panel { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 28px 30px; }
  .wiz-panel h2 { font-size: 24px; font-weight: 600; margin: 0 0 6px; color: var(--ink-1); }
  .wiz-panel .wiz-sub { color: var(--ink-3); font-size: 14px; margin: 0 0 20px; line-height: 1.5; }
  .wiz-field { margin-bottom: 18px; }
  .wiz-field label { display: block; font-weight: 600; font-size: 13.5px; margin-bottom: 6px; color: var(--ink-2); }
  .wiz-field .hint { font-weight: 400; color: var(--ink-5); font-size: 12.5px; margin-left: 6px; }
  .wiz-field input[type="text"],
  .wiz-field textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font-size: 14px; box-sizing: border-box; font-family: inherit; resize: vertical; }
  .wiz-field input[type="text"]:focus,
  .wiz-field textarea:focus { outline: 2px solid var(--landing-accent-soft); border-color: var(--landing-accent); }
  .wiz-err { color: #c2492f; font-size: 13px; margin-top: 6px; display: none; }
  .wiz-err.is-on { display: block; }
  .wiz-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 22px; padding-top: 22px; border-top: 1px solid var(--line); }
  .btn-primary { background: var(--ink-1); color: #fff; border: 1px solid var(--ink-1); border-radius: 999px; cursor: pointer; font-weight: 600; padding: 10px 18px; font-size: 14px; }
  .btn-primary:hover { background: var(--landing-accent); border-color: var(--landing-accent); }
  .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
  .btn-ghost { background: #fff; color: var(--ink-3); border: 1px solid var(--line); border-radius: 999px; cursor: pointer; font-weight: 600; padding: 10px 18px; font-size: 14px; text-decoration: none; display: inline-block; }
  .btn-ghost:hover { border-color: var(--ink-4); }
  .wiz-intake-box { background: var(--landing-accent-soft); border: 1px dashed var(--landing-accent); border-radius: 12px; padding: 22px 24px; text-align: center; }
  .wiz-intake-box a { display: inline-block; margin-top: 10px; padding: 10px 20px; background: var(--ink-1); color: #fff; border-radius: 999px; text-decoration: none; font-weight: 600; font-size: 14px; }

  /* Step 3 scale rows */
  .scale-table {
    width: 100%; border-collapse: collapse;
    background: #fff; border: 1px solid var(--line);
    border-radius: 10px; overflow: hidden; margin-bottom: 10px;
  }
  .scale-table th { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-5); text-align: left; padding: 10px 14px; background: var(--bg-tint, #f7f8fa); border-bottom: 1px solid var(--line); }
  .scale-table td { padding: 10px 14px; font-size: 13.5px; border-top: 1px solid var(--line); vertical-align: middle; }
  .scale-table td input[type="text"] { width: 100%; padding: 7px 10px; border: 1px solid var(--line); border-radius: 6px; font-size: 13px; box-sizing: border-box; font-family: inherit; }
  .scale-table td select { padding: 6px 10px; border: 1px solid var(--line); border-radius: 6px; font-size: 13px; font-family: inherit; background: #fff; }
  .scale-table .item-name { font-weight: 600; color: var(--ink-1); font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace; font-size: 12.5px; }
  .scale-table .item-sample { color: var(--ink-5); font-size: 12px; font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace; }
  .scale-empty { padding: 24px; background: #fff; border: 1px dashed var(--line); border-radius: 12px; text-align: center; color: var(--ink-4); font-size: 14px; }
</style>

<div class="wiz-shell">

  <div class="wiz-top">
    <a class="wiz-back" href="/rssi.php">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
      Back to Survey Studio
    </a>
    <a class="wiz-back" href="/studio-strength-survey-projects.php" style="color:var(--ink-5);">All surveys &rarr;</a>
  </div>

  <div class="wiz-steps">
    <?php
      $stops = [
        1 => ['Set Up',  'Title and description.'],
        2 => ['Data',    'Upload CSV/Excel; confirm columns.'],
        3 => ['Scales',  'Group Likert items; set scoring direction.'],
      ];
      foreach ($stops as $n => $info) {
        $cls = '';
        if ($n === $step) $cls = 'is-active';
        elseif ($n < $step) $cls = 'is-done';
        $pip = ($n < $step) ? '&#10003;' : (string)$n;
        echo '<div class="wiz-step-card ' . $cls . '">'
          . '<span class="pip">' . $pip . '</span>'
          . '<span class="label">' . htmlspecialchars($info[0]) . '</span>'
          . '<div class="sub">' . htmlspecialchars($info[1]) . '</div>'
          . '</div>';
      }
    ?>
  </div>

  <div class="wiz-panel">

<?php if ($step === 1): ?>

    <!-- ===== STEP 1: Title ===== -->
    <h2>Title your survey</h2>
    <p class="wiz-sub">Give your survey a clear name. You'll be able to edit this any time.</p>
    <form id="svWizStep1">
      <div class="wiz-field">
        <label for="svWizTitle">Survey title <span style="color:#c2492f;">*</span></label>
        <input id="svWizTitle" name="title" type="text" maxlength="200" placeholder="e.g., 2026 Workplace Engagement Survey" value="<?= htmlspecialchars((string)($project['title'] ?? '')) ?>" required>
        <p class="wiz-err" id="svWizTitleErr">Add a survey title to continue.</p>
      </div>
      <div class="wiz-field">
        <label for="svWizDesc">Description <span class="hint">(optional)</span></label>
        <p class="wiz-sub" style="margin-bottom:6px;">One or two sentences on what this survey is about. Helps you orient when you come back later.</p>
        <textarea id="svWizDesc" name="description" rows="4" maxlength="2000" placeholder="A brief description of the survey, the audience, or the research question."><?= htmlspecialchars((string)($project['description'] ?? '')) ?></textarea>
      </div>
      <div class="wiz-actions">
        <a class="btn-ghost" href="/rssi.php">Cancel</a>
        <button type="submit" class="btn-primary" id="svWizStep1Btn">Continue to data &rarr;</button>
      </div>
    </form>

<?php elseif ($step === 2): ?>

    <!-- ===== STEP 2: Data ===== -->
    <h2>Bring in your data</h2>
    <p class="wiz-sub">Upload your survey responses as CSV or Excel, paste them in, or pick a sample. The Evidence Intake wizard will walk you through column types and then return you here for the Scales step.</p>
    <div class="wiz-intake-box">
      <strong style="display:block;font-size:16px;color:var(--ink-1);font-family:'Fraunces',serif;">Survey saved: <?= htmlspecialchars((string)$project['title']) ?></strong>
      <p style="color:var(--ink-3);font-size:14px;line-height:1.5;margin:6px 0 0;">Open the Survey Evidence Intake wizard. When you finish, you'll come back to set scales and scoring direction.</p>
      <a href="/evidence-intake.php?studio=strength-survey&amp;project_id=<?= (int)$projectId ?>&amp;wizard=1">Open Evidence Intake →</a>
    </div>
    <div class="wiz-actions">
      <a class="btn-ghost" href="/strength-survey-wizard.php?step=1&amp;project_id=<?= (int)$projectId ?>">&larr; Back</a>
      <a class="btn-primary" href="/strength-survey-wizard.php?step=3&amp;project_id=<?= (int)$projectId ?>" style="text-decoration:none;">Skip to scales step &rarr;</a>
    </div>

<?php elseif ($step === 3): ?>

    <!-- ===== STEP 3: Scales ===== -->
    <h2>Confirm scales and scoring</h2>
    <p class="wiz-sub">For each Likert/numeric item, set its scale name and scoring direction. Items in the same scale share a scale name. We'll use this to compute reliability (α / ω), distributions, and the Strength Index.</p>

    <div id="scaleHost">
      <div class="scale-empty" id="scaleEmpty">Reading your dataset…</div>
    </div>

    <div class="wiz-actions">
      <a class="btn-ghost" href="/strength-survey-wizard.php?step=2&amp;project_id=<?= (int)$projectId ?>">&larr; Back</a>
      <button class="btn-primary" id="svWizFinishBtn">Open Strength Index &rarr;</button>
    </div>

<?php endif; ?>

  </div>
</div>

<script>
(function () {
  const PROJECT_ID = <?= json_encode($projectId) ?>;

  // ---------- Step 1: create survey ----------
  const step1Form = document.getElementById('svWizStep1');
  if (step1Form) {
    step1Form.addEventListener('submit', function (e) {
      e.preventDefault();
      const title = document.getElementById('svWizTitle').value.trim();
      const desc  = document.getElementById('svWizDesc').value.trim();
      const err   = document.getElementById('svWizTitleErr');
      const btn   = document.getElementById('svWizStep1Btn');
      if (!title) { err.classList.add('is-on'); return; }
      err.classList.remove('is-on');
      btn.disabled = true; btn.textContent = 'Saving…';

      const isUpdate = PROJECT_ID > 0;
      const url = isUpdate ? '/api/surveys/update.php' : '/api/surveys/create.php';
      const body = isUpdate ? { id: PROJECT_ID, title: title, description: desc } : { title: title };

      fetch(url, {
        method: isUpdate ? 'PATCH' : 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(body),
      })
        .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
        .then(res => {
          if (!res.ok) throw new Error((res.data && res.data.message) || 'Could not save');
          const survey = res.data.survey || {};
          const id = isUpdate ? PROJECT_ID : survey.id;
          if (!id) throw new Error('No survey id returned');

          // If we just created and a description was entered, save via PATCH
          if (!isUpdate && desc) {
            return fetch('/api/surveys/update.php', {
              method: 'PATCH', credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id: id, description: desc }),
            }).then(() => id);
          }
          return id;
        })
        .then(id => {
          window.location.href = '/strength-survey-wizard.php?step=2&project_id=' + encodeURIComponent(id);
        })
        .catch(e => {
          btn.disabled = false; btn.textContent = 'Continue to data →';
          alert('Could not start the survey: ' + (e.message || e));
        });
    });
  }

  // ---------- Step 3: scales ----------
  const scaleHost = document.getElementById('scaleHost');
  if (scaleHost) {
    let dataset = null;
    try {
      const raw = window.localStorage.getItem('relicheck.dataset.' + PROJECT_ID);
      if (raw) {
        const wrap = JSON.parse(raw);
        if (wrap && wrap.payload && wrap.payload.dataset) dataset = wrap.payload.dataset;
      }
    } catch (e) {}

    if (!dataset || !dataset.variables) {
      scaleHost.innerHTML = '<div class="scale-empty">No dataset found for this survey yet. Go back and upload data first.</div>';
    } else {
      // Pull Likert + numeric items
      const likertItems = dataset.variables.filter(v => {
        return v.types && (v.types.indexOf('likert') !== -1 || v.types.indexOf('numeric') !== -1);
      });
      if (!likertItems.length) {
        scaleHost.innerHTML = '<div class="scale-empty">No Likert or numeric items detected. You can still continue to Strength Index, which will compute on the columns you flagged.</div>';
      } else {
        // Load any prior scale settings from localStorage
        const settingsKey = 'relicheck.scales.' + PROJECT_ID;
        let prior = {};
        try { prior = JSON.parse(window.localStorage.getItem(settingsKey) || '{}') || {}; } catch (e) {}

        let html = '<table class="scale-table"><thead><tr>' +
          '<th style="width:30%;">Item</th>' +
          '<th style="width:25%;">Sample</th>' +
          '<th style="width:25%;">Scale name</th>' +
          '<th style="width:14%;">Direction</th>' +
          '<th style="width:6%;">Reverse</th>' +
          '</tr></thead><tbody>';
        likertItems.forEach((v) => {
          const settings = prior[v.name] || {};
          const samp = (v.sample || v.values || []).slice(0, 4).join(' · ');
          html += '<tr data-var="' + escAttr(v.name) + '">' +
            '<td><div class="item-name">' + escHtml(v.name) + '</div></td>' +
            '<td><div class="item-sample">' + escHtml(samp) + '</div></td>' +
            '<td><input type="text" data-field="scale" placeholder="e.g., Belonging Scale" value="' + escAttr(settings.scale || '') + '"></td>' +
            '<td><select data-field="direction">' +
              '<option value="positive"' + (settings.direction === 'positive' ? ' selected' : '') + '>Higher = better</option>' +
              '<option value="negative"' + (settings.direction === 'negative' ? ' selected' : '') + '>Higher = worse</option>' +
              '<option value="neutral" ' + (settings.direction === 'neutral'  ? ' selected' : '') + '>Neutral</option>' +
            '</select></td>' +
            '<td style="text-align:center;"><input type="checkbox" data-field="reverse"' + (settings.reverse ? ' checked' : '') + '></td>' +
          '</tr>';
        });
        html += '</tbody></table>' +
          '<p style="font-size:13px;color:var(--ink-5);margin:8px 0 0;">Items left blank for "Scale name" will be treated as standalone single-item scales.</p>' +
          // Survey-level reverse-coding confirmation (KNOWN_ISSUES.md §4 #3,
          // spec §4E sub-component 2 tri-state). See survey-wizard.php for
          // the rationale; this file is the strength-survey-studio variant
          // of the same Step 3 form.
          '<div class="reverse-confirm" style="margin-top:14px;padding:12px;border:1px solid var(--border);border-radius:8px;background:var(--surface-2,#fafafa);">' +
            '<label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">' +
              '<input type="checkbox" id="reverseCodedConfirmed"' + (prior.__reverse_coded_confirmed ? ' checked' : '') + ' style="margin-top:3px;">' +
              '<span style="font-size:14px;line-height:1.45;">' +
                '<strong>I&rsquo;ve reviewed every item for reverse-coding.</strong> ' +
                '<span style="color:var(--ink-5);">Confirms the reverse checkboxes above are complete. Required for §4E Scale Structure to evaluate reverse-coded balance; without this confirmation the sub-component is skipped.</span>' +
              '</span>' +
            '</label>' +
          '</div>';
        scaleHost.innerHTML = html;
      }
    }

    document.getElementById('svWizFinishBtn').addEventListener('click', function () {
      const btn = this;
      const settings = {};
      scaleHost.querySelectorAll('tr[data-var]').forEach(r => {
        const name = r.getAttribute('data-var');
        settings[name] = {
          scale:     r.querySelector('input[data-field="scale"]').value.trim(),
          direction: r.querySelector('select[data-field="direction"]').value,
          reverse:   r.querySelector('input[data-field="reverse"]').checked,
        };
      });
      // Survey-level reverse-coding confirmation. Bundled with scales in
      // the PATCH because api/surveys/update.php replaces settings whole.
      const reverseConfirmedEl = document.getElementById('reverseCodedConfirmed');
      const reverseConfirmed   = !!(reverseConfirmedEl && reverseConfirmedEl.checked);
      const cacheBlob = Object.assign({}, settings, { __reverse_coded_confirmed: reverseConfirmed });
      try { window.localStorage.setItem('relicheck.scales.' + PROJECT_ID, JSON.stringify(cacheBlob)); } catch (e) {}

      btn.disabled = true; btn.textContent = 'Saving…';
      // Persist into the survey's settings JSON for downstream apps.
      fetch('/api/surveys/update.php', {
        method: 'PATCH', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: PROJECT_ID, settings: { scales: settings, reverse_coded_confirmed: reverseConfirmed } }),
      })
        .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
        .catch(() => ({ ok: false }))
        .finally(() => {
          window.location.href = '/strength-index.php?studio=strength-survey&project_id=' + encodeURIComponent(PROJECT_ID);
        });
    });
  }

  function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]);
  }
  function escAttr(s) { return escHtml(s); }
})();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
