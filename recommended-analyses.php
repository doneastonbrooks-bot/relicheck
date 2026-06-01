<?php
// Recommended Analyses — advisory that reads your dataset shape and tells
// you which rail items to run next, in what order, with one-line reasons.
$mount_app            = 'overview';
$mount_lens           = 'project_snapshot';
$mount_section        = 'overview';
$mount_item           = 'recommended_analyses';
$mount_breadcrumb     = ['Overview', 'Recommended Analyses'];
$mount_title          = 'Recommended Analyses';
$mount_intro          = "Based on the shape of your dataset (variable types, sample size, missingness), here's the order we'd run analyses in. Click any row to jump straight to that lens.";
$mount_dataset_global = 'OVERVIEW_DATASET';
$mount_lens_global    = 'OVERVIEW_LENS';

include __DIR__ . '/_studio_mount.php';
?>

<style>
  .ra-list { display: grid; gap: 10px; margin-top: 14px; }
  .ra-row {
    display: grid; grid-template-columns: 28px 1fr auto; gap: 14px;
    background: #fff; border: 1px solid var(--line); border-radius: 12px;
    padding: 14px 18px; text-decoration: none; color: inherit; align-items: center;
  }
  .ra-row:hover { border-color: var(--accent); }
  .ra-num { width: 28px; height: 28px; border-radius: 999px; background: var(--accent); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; }
  .ra-title { font-family: 'Fraunces', serif; font-size: 16px; font-weight: 600; color: var(--ink-1); }
  .ra-why { font-size: 13px; color: var(--ink-4); margin-top: 2px; }
  .ra-arrow { color: var(--ink-5); font-size: 18px; }
  .ra-empty { padding: 22px; background: #fff; border: 1px dashed var(--line); border-radius: 12px; color: var(--ink-4); text-align: center; }
</style>

<div id="raHost"></div>

<script>
(function () {
  const PROJECT_ID = <?= json_encode((int)($_GET['project_id'] ?? 0)) ?>;
  const STUDIO    = <?= json_encode($_GET['studio'] ?? 'survey') ?>;
  const host = document.getElementById('raHost');
  let dataset = null;
  try {
    const raw = window.localStorage.getItem('relicheck.dataset.' + PROJECT_ID);
    if (raw) { const w = JSON.parse(raw); if (w && w.payload && w.payload.dataset) dataset = w.payload.dataset; }
  } catch (e) {}
  if (!dataset || !dataset.variables || !dataset.variables.length) {
    host.innerHTML = '<div class="ra-empty">No data uploaded yet.</div>';
    return;
  }
  const vars = dataset.variables;
  const tc   = { likert: 0, numeric: 0, categorical: 0, open: 0, id: 0, date: 0 };
  vars.forEach(v => { const t = (v.types && v.types[0]) || 'other'; if (tc[t] !== undefined) tc[t]++; });
  const n = dataset.rowCount || 0;

  const rec = [];
  rec.push({ to: '/data-readiness.php', name: 'Data Readiness', why: 'Always run first to surface size, missingness, and structural blockers.' });
  if (tc.likert >= 2)                   rec.push({ to: '/reliability.php', name: 'Reliability', why: 'Get Cronbach\'s α before publishing any scale-based finding.' });
  if (tc.likert >= 2)                   rec.push({ to: '/distributions.php', name: 'Means & Distributions', why: 'Eyeball your scales: are they on a sensible scale, skewed, or floored?' });
  if (tc.likert >= 3)                   rec.push({ to: '/factor-readiness.php', name: 'Factor Readiness', why: 'Confirm the data is suitable for factor analysis (KMO + Bartlett).' });
  if (tc.categorical >= 1 && tc.likert >= 1) rec.push({ to: '/group-summaries.php', name: 'Group Summaries', why: 'Spot where group means meaningfully differ before testing them.' });
  if (n >= 30 && tc.categorical >= 1 && tc.likert >= 1) rec.push({ to: '/t-test.php', name: 't-test', why: 'Test whether two groups differ on a Likert outcome.' });
  if (n >= 30 && tc.categorical >= 1 && tc.likert >= 1) rec.push({ to: '/anova.php', name: 'ANOVA', why: '3+ groups on a Likert outcome — ANOVA before post-hoc.' });
  if (tc.numeric >= 2 || tc.likert >= 2) rec.push({ to: '/correlation.php', name: 'Correlation', why: 'See pairwise correlations across numeric/Likert columns.' });
  if (tc.categorical >= 2)              rec.push({ to: '/chi-square.php', name: 'Chi-square', why: 'Test independence between two categorical variables.' });
  if (tc.open >= 1)                     rec.push({ to: '/codebook-builder.php', name: 'Codebook Builder', why: 'Define themes before tagging open-ended responses.' });
  if (tc.open >= 1)                     rec.push({ to: '/theme-analysis.php', name: 'Theme Analysis', why: 'Tag each open-ended response against your themes.' });
  rec.push({ to: '/report-builder.php', name: 'Report Builder', why: 'Stitch every saved analysis block into one shareable report.' });

  host.innerHTML = '<div class="ra-list">' + rec.map((r, i) => {
    const href = r.to + '?studio=' + encodeURIComponent(STUDIO) + '&project_id=' + encodeURIComponent(PROJECT_ID);
    return '<a class="ra-row" href="' + href + '">' +
      '<span class="ra-num">' + (i + 1) + '</span>' +
      '<div><div class="ra-title">' + esc(r.name) + '</div><div class="ra-why">' + esc(r.why) + '</div></div>' +
      '<span class="ra-arrow">→</span>' +
    '</a>';
  }).join('') + '</div>';

  window.RELICHECK_APP_STATE = {
    app_key: 'recommended_analyses', lens: 'recommended_analyses',
    summary: rec.length + ' recommended analyses based on dataset shape.',
  };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]); }
})();
</script>
