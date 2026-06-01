<?php
// Overall Findings — top-line summary of what the user has and where to go.
// Reads dataset + saved blocks from localStorage and computes a smart
// "what to do next" list based on the variable type mix.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/overall-findings.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$valid_studios = ['survey', 'mm', 'tia', '360'];
$studio_slug   = $_GET['studio'] ?? 'survey';
if (!in_array($studio_slug, $valid_studios, true)) $studio_slug = 'survey';
$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;

$mount_project = null;
if ($projectId > 0) {
  $pdo = db();
  try {
    if ($studio_slug === 'survey')   $stmt = $pdo->prepare('SELECT id, title FROM surveys WHERE id = :id AND owner_id = :uid AND (archived_at IS NULL)');
    elseif ($studio_slug === 'mm')   $stmt = $pdo->prepare('SELECT id, title FROM mm_projects WHERE id = :id AND user_id = :uid AND status <> "archived"');
    elseif ($studio_slug === '360')  $stmt = $pdo->prepare('SELECT id, name AS title FROM survey_360_panels WHERE id = :id AND user_id = :uid');
    else                             $stmt = $pdo->prepare('SELECT id, title FROM tia_projects WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    $mount_project = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {}
}
if ($projectId > 0 && !$mount_project) { header('Location: /studio-' . $studio_slug . '-projects.php'); exit; }

$mount_app            = 'overview';
$mount_lens           = 'project_snapshot'; // fallback lens
$mount_section        = 'overview';
$mount_item           = 'overall_findings';
$mount_breadcrumb     = ['Overview', 'Overall Findings'];
$mount_title          = 'Overall Findings';
$mount_intro          = "The top-line read of your project: what you have, what's been computed, and where to focus next.";
$mount_dataset_global = 'OVERVIEW_DATASET';
$mount_lens_global    = 'OVERVIEW_LENS';

// Render via the shared mount, then inject our custom block after.
include __DIR__ . '/_studio_mount.php';
?>

<style>
  .of-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin: 18px 0; }
  .of-card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; }
  .of-card .lbl { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-5); margin-bottom: 4px; }
  .of-card .val { font-family: 'Fraunces', serif; font-size: 26px; font-weight: 600; color: var(--ink-1); line-height: 1; }
  .of-card .sub { font-size: 12px; color: var(--ink-4); margin-top: 4px; }
  .of-blocks { display: grid; gap: 10px; }
  .of-block { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px 18px; text-decoration: none; color: inherit; display: block; }
  .of-block:hover { border-color: var(--accent); }
  .of-block .block-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 4px; }
  .of-block .block-title { font-family: 'Fraunces', serif; font-size: 16px; font-weight: 600; color: var(--ink-1); }
  .of-block .block-source { font-size: 11.5px; color: var(--ink-5); font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace; }
  .of-block .block-sum { font-size: 13.5px; color: var(--ink-3); margin: 0; }
  .of-section-head { font-family: 'Fraunces', serif; font-size: 18px; font-weight: 600; margin: 22px 0 10px; color: var(--ink-1); }
  .of-empty { padding: 20px; background: #fff; border: 1px dashed var(--line); border-radius: 12px; color: var(--ink-4); text-align: center; }
</style>

<div id="ofSummary"></div>

<script>
(function () {
  const PROJECT_ID = <?= json_encode($projectId) ?>;
  const STUDIO    = <?= json_encode($studio_slug) ?>;
  const host = document.getElementById('ofSummary');
  let dataset = null;
  try {
    const raw = window.localStorage.getItem('relicheck.dataset.' + PROJECT_ID);
    if (raw) {
      const wrap = JSON.parse(raw);
      if (wrap && wrap.payload && wrap.payload.dataset) dataset = wrap.payload.dataset;
    }
  } catch (e) {}

  let blocks = [];
  try {
    const raw = window.localStorage.getItem('relicheck.report.' + PROJECT_ID + '.default');
    if (raw) blocks = JSON.parse(raw) || [];
  } catch (e) {}

  if (!dataset || !dataset.variables || !dataset.variables.length) {
    host.innerHTML = '<div class="of-empty">No data uploaded yet. <a href="/' + STUDIO + '-wizard.php?step=2&project_id=' + encodeURIComponent(PROJECT_ID) + '" style="color:var(--accent);font-weight:600;">Upload data</a> to see your overall findings.</div>';
    return;
  }

  const typeCount = { likert: 0, numeric: 0, categorical: 0, open: 0, id: 0, date: 0, other: 0 };
  dataset.variables.forEach(v => {
    const t = (v.types && v.types[0]) || 'other';
    if (typeCount[t] !== undefined) typeCount[t]++; else typeCount.other++;
  });

  const cards =
    '<div class="of-grid">' +
      '<div class="of-card"><div class="lbl">Responses</div><div class="val">' + (dataset.rowCount || 0) + '</div><div class="sub">complete rows</div></div>' +
      '<div class="of-card"><div class="lbl">Variables</div><div class="val">' + dataset.variables.length + '</div><div class="sub">' + typeCount.likert + ' Likert · ' + typeCount.numeric + ' numeric · ' + typeCount.categorical + ' categorical · ' + typeCount.open + ' open</div></div>' +
      '<div class="of-card"><div class="lbl">Saved analyses</div><div class="val">' + blocks.length + '</div><div class="sub">blocks in this project\'s report</div></div>' +
    '</div>';

  let blocksHtml = '<h3 class="of-section-head">Saved analyses</h3>';
  if (!blocks.length) {
    blocksHtml += '<div class="of-empty">No analyses saved yet. Run an analysis from the left rail and click <strong>Save to report</strong>.</div>';
  } else {
    blocksHtml += '<div class="of-blocks">' + blocks.slice().reverse().map(b => (
      '<div class="of-block">' +
        '<div class="block-head">' +
          '<div class="block-title">' + esc(b.app_key || b.lens || 'Analysis') + (b.lens && b.app_key !== b.lens ? ' · ' + esc(b.lens) : '') + '</div>' +
          '<div class="block-source">' + esc((b.savedAt && new Date(b.savedAt).toLocaleString()) || '') + '</div>' +
        '</div>' +
        '<p class="block-sum">' + esc(b.summary || b.title || 'Saved.') + '</p>' +
      '</div>'
    )).join('') + '</div>';
  }

  const suggestions = [];
  if (typeCount.likert >= 2) suggestions.push({ to: '/reliability.php', name: 'Reliability', why: 'You have ' + typeCount.likert + ' Likert items — compute Cronbach\'s α.' });
  if (typeCount.likert >= 3) suggestions.push({ to: '/factor-readiness.php', name: 'Factor Readiness', why: 'Test KMO + Bartlett before running factor analysis.' });
  if (typeCount.categorical >= 1 && typeCount.likert >= 1) suggestions.push({ to: '/group-summaries.php', name: 'Group Summaries', why: 'Compare Likert means across your categorical groupers.' });
  if (typeCount.categorical >= 2) suggestions.push({ to: '/chi-square.php', name: 'Chi-square', why: 'Test independence between categorical variables.' });
  if (typeCount.numeric >= 2 || typeCount.likert >= 2) suggestions.push({ to: '/correlation.php', name: 'Correlation matrix', why: 'See pairwise correlations.' });
  if (typeCount.open >= 1) suggestions.push({ to: '/theme-analysis.php', name: 'Theme Analysis', why: 'Auto-tag open-ended responses against codebook themes.' });
  if (!suggestions.length) suggestions.push({ to: '/data-quality.php', name: 'Data Quality', why: 'Run quality diagnostics before deeper analyses.' });

  const nextHtml = '<h3 class="of-section-head">Suggested next analyses</h3><div class="of-blocks">' + suggestions.map(s => {
    const href = s.to + '?studio=' + encodeURIComponent(STUDIO) + '&project_id=' + encodeURIComponent(PROJECT_ID);
    return '<a class="of-block" href="' + href + '">' +
      '<div class="block-head"><div class="block-title">' + esc(s.name) + ' →</div></div>' +
      '<p class="block-sum">' + esc(s.why) + '</p></a>';
  }).join('') + '</div>';

  host.innerHTML = cards + blocksHtml + nextHtml;

  window.RELICHECK_APP_STATE = {
    app_key: 'overall_findings', lens: 'overall_findings',
    summary: (dataset.rowCount || 0) + ' responses, ' + dataset.variables.length + ' variables, ' + blocks.length + ' saved analyses.',
  };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]); }
})();
</script>
