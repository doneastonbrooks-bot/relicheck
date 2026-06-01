<?php
// Data Readiness — a checklist that says "is this dataset ready to analyze?"
// Reads localStorage + flags completeness, structural balance, sample size,
// and missingness. Lives alongside Data Quality but is more decision-focused.
$mount_app            = 'overview';
$mount_lens           = 'data_quality'; // fallback lens; custom render below
$mount_section        = 'overview';
$mount_item           = 'data_readiness';
$mount_breadcrumb     = ['Overview', 'Data Readiness'];
$mount_title          = 'Data Readiness';
$mount_intro          = "Five checks that say whether your dataset is ready to analyze. Anything red blocks downstream work; anything yellow is worth a second look.";
$mount_dataset_global = 'OVERVIEW_DATASET';
$mount_lens_global    = 'OVERVIEW_LENS';

include __DIR__ . '/_studio_mount.php';
?>

<style>
  .dr-checks { display: grid; gap: 12px; margin-top: 14px; }
  .dr-check { display: grid; grid-template-columns: 32px 1fr auto; gap: 14px; padding: 14px 18px; background: #fff; border: 1px solid var(--line); border-radius: 12px; align-items: center; }
  .dr-pip { width: 28px; height: 28px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; }
  .dr-pip.is-good { background: #1f7a3a; }
  .dr-pip.is-mid  { background: #b35a00; }
  .dr-pip.is-bad  { background: #c2492f; }
  .dr-check .title { font-family: 'Fraunces', serif; font-size: 15px; font-weight: 600; color: var(--ink-1); }
  .dr-check .detail { font-size: 12.5px; color: var(--ink-4); margin-top: 2px; }
  .dr-check .stat { font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace; font-size: 13px; color: var(--ink-2); white-space: nowrap; }
  .dr-empty { padding: 22px; background: #fff; border: 1px dashed var(--line); border-radius: 12px; color: var(--ink-4); text-align: center; }
</style>

<div id="drHost"></div>

<script>
(function () {
  const PROJECT_ID = <?= json_encode((int)($_GET['project_id'] ?? 0)) ?>;
  const STUDIO    = <?= json_encode($_GET['studio'] ?? 'survey') ?>;
  const host = document.getElementById('drHost');
  let dataset = null;
  try {
    const raw = window.localStorage.getItem('relicheck.dataset.' + PROJECT_ID);
    if (raw) { const w = JSON.parse(raw); if (w && w.payload && w.payload.dataset) dataset = w.payload.dataset; }
  } catch (e) {}
  if (!dataset || !dataset.variables || !dataset.variables.length) {
    host.innerHTML = '<div class="dr-empty">No data uploaded yet.</div>';
    return;
  }
  const vars = dataset.variables;
  const n    = dataset.rowCount || (vars[0].values ? vars[0].values.length : 0);
  const isMissing = v => v === '' || v == null;
  const totalCells = vars.length * n;
  let missingCells = 0;
  vars.forEach(v => (v.values || []).slice(0, n).forEach(x => { if (isMissing(x)) missingCells++; }));
  const missingPct = totalCells ? (missingCells / totalCells) * 100 : 0;

  const likertCount = vars.filter(v => v.types && v.types.indexOf('likert') !== -1).length;
  const catCount    = vars.filter(v => v.types && v.types.indexOf('categorical') !== -1).length;
  const idCount     = vars.filter(v => v.types && v.types.indexOf('id') !== -1).length;

  // Sample size verdict (informed by ~30 / ~100 / ~200 rules of thumb)
  const sampleLevel  = n >= 200 ? 'good' : (n >= 100 ? 'mid' : (n >= 30 ? 'mid' : 'bad'));
  const sampleNote   = n >= 200 ? 'Comfortable for most analyses, including factor analysis.' :
                       n >= 100 ? 'Adequate for t-tests, correlation, and reliability.' :
                       n >= 30  ? 'Tight; effect sizes will be noisy. OK for descriptive work.' :
                                  'Too small for inferential tests. Use for pilot only.';

  const missingLevel = missingPct < 5 ? 'good' : (missingPct < 15 ? 'mid' : 'bad');
  const structureLevel = (likertCount >= 2 || catCount >= 1) ? 'good' : 'mid';
  const idLevel = idCount >= 1 ? 'good' : 'mid';
  const typedLevel = (vars.every(v => v.types && v.types.length)) ? 'good' : 'mid';

  const checks = [
    { lvl: sampleLevel,    title: 'Sample size',             detail: sampleNote, stat: n + ' rows' },
    { lvl: missingLevel,   title: 'Missing data',            detail: missingPct < 5 ? 'Low missingness — safe for most procedures.' : (missingPct < 15 ? 'Moderate missingness — consider listwise vs. pairwise carefully.' : 'High missingness — clean before analyzing.'), stat: missingPct.toFixed(1) + '%' },
    { lvl: structureLevel, title: 'Structural balance',      detail: 'Need at least 2 Likert items or 1 categorical grouper for most analyses.', stat: likertCount + ' Likert · ' + catCount + ' categorical' },
    { lvl: idLevel,        title: 'Respondent identifier',   detail: idCount ? 'An ID column is set — paired analyses are possible.' : 'No ID column flagged — paired analyses are not available.', stat: idCount ? 'present' : 'missing' },
    { lvl: typedLevel,     title: 'All variables typed',     detail: 'Every column should have a type set in Evidence Intake.', stat: vars.length + ' vars typed' },
  ];

  const pipChar = { good: '✓', mid: '!', bad: '×' };
  host.innerHTML = '<div class="dr-checks">' + checks.map(c =>
    '<div class="dr-check">' +
      '<span class="dr-pip is-' + c.lvl + '">' + pipChar[c.lvl] + '</span>' +
      '<div><div class="title">' + c.title + '</div><div class="detail">' + c.detail + '</div></div>' +
      '<span class="stat">' + c.stat + '</span>' +
    '</div>'
  ).join('') + '</div>';

  window.RELICHECK_APP_STATE = {
    app_key: 'data_readiness', lens: 'data_readiness',
    summary: 'Data readiness: ' + n + ' rows, ' + missingPct.toFixed(1) + '% missing, ' + likertCount + ' Likert + ' + catCount + ' categorical.',
  };
})();
</script>
