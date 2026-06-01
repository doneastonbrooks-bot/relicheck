<?php
// Inter-rater Agreement — Cohen's kappa across any two categorical raters.
// User picks two columns; we compute observed agreement, chance agreement,
// and Cohen's κ. For Likert items, we also offer percent-agreement-within-1.
$mount_app            = 'overview';
$mount_lens           = 'data_quality';
$mount_section        = 'instrument_quality';
$mount_item           = 'inter_rater_agreement';
$mount_breadcrumb     = ['Instrument Quality', 'Inter-rater Agreement'];
$mount_title          = 'Inter-rater Agreement';
$mount_intro          = "Pick two rater columns to compute Cohen's κ. Observed agreement, agreement expected by chance, and the corrected score. For Likert raters, also percent-agreement-within-one.";
$mount_dataset_global = 'OVERVIEW_DATASET';
$mount_lens_global    = 'OVERVIEW_LENS';

include __DIR__ . '/_studio_mount.php';
?>

<style>
  .ira-pickers { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 14px 0; }
  .ira-pickers label { display: block; font-weight: 600; font-size: 13px; color: var(--ink-2); margin-bottom: 4px; }
  .ira-pickers select { width: 100%; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; font-size: 13px; }
  .ira-results { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-top: 8px; }
  .ira-card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; }
  .ira-card .lbl { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-5); }
  .ira-card .val { font-family: 'Fraunces', serif; font-size: 24px; font-weight: 600; color: var(--ink-1); margin-top: 4px; }
  .ira-card .sub { font-size: 12.5px; color: var(--ink-4); margin-top: 4px; }
  .ira-verdict { padding: 12px 16px; border-radius: 999px; display: inline-block; font-weight: 600; margin-top: 6px; }
  .ira-verdict.is-good { background: #e3f5ee; color: #0e8a6f; }
  .ira-verdict.is-mid  { background: #fef3c7; color: #92400e; }
  .ira-verdict.is-bad  { background: #fdeae6; color: #c2492f; }
  .ira-empty { padding: 22px; background: #fff; border: 1px dashed var(--line); border-radius: 12px; color: var(--ink-4); text-align: center; }
</style>

<div id="iraPickers"></div>
<div id="iraOut"></div>

<script>
(function () {
  const PROJECT_ID = <?= json_encode((int)($_GET['project_id'] ?? 0)) ?>;
  const pickHost = document.getElementById('iraPickers');
  const outHost  = document.getElementById('iraOut');
  let dataset = null;
  try {
    const raw = window.localStorage.getItem('relicheck.dataset.' + PROJECT_ID);
    if (raw) { const w = JSON.parse(raw); if (w && w.payload && w.payload.dataset) dataset = w.payload.dataset; }
  } catch (e) {}
  if (!dataset || !dataset.variables || dataset.variables.length === 0) {
    outHost.innerHTML = '<div class="ira-empty">' +
      '<strong style="color:var(--ink-1);display:block;font-size:15px;margin-bottom:6px;">No data to analyze yet.</strong>' +
      'Inter-rater Agreement compares two columns of judgments to see how consistently raters agreed. Upload your data first, ' +
      'then come back — we need at least two rating columns from the same set of subjects to compute Cohen\'s κ.' +
      '</div>';
    return;
  }
  const candidates = dataset.variables.filter(v => v.types && (v.types.indexOf('categorical') !== -1 || v.types.indexOf('likert') !== -1));
  if (candidates.length < 2) {
    outHost.innerHTML = '<div class="ira-empty">' +
      '<strong style="color:var(--ink-1);display:block;font-size:15px;margin-bottom:6px;">Not enough rating columns to compare.</strong>' +
      'Inter-rater Agreement needs at least <strong>two categorical or Likert columns</strong> from raters scoring the same subjects ' +
      '(e.g., Rater A and Rater B both rating each item on a 1–5 scale). ' +
      'Your dataset currently has <strong>' + candidates.length + '</strong> rating column' + (candidates.length === 1 ? '' : 's') + '. ' +
      'If you have two raters, mark both columns as Likert or categorical in Evidence Intake.' +
      '</div>';
    return;
  }
  const opts = candidates.map(v => '<option value="' + esc(v.name) + '">' + esc(v.name) + '</option>').join('');
  pickHost.innerHTML =
    '<div class="ira-pickers">' +
      '<div><label>Rater A column</label><select id="iraA">' + opts + '</select></div>' +
      '<div><label>Rater B column</label><select id="iraB">' + opts + '</select></div>' +
    '</div>';
  const aSel = document.getElementById('iraA');
  const bSel = document.getElementById('iraB');
  if (candidates.length >= 2) bSel.selectedIndex = 1;

  function calc() {
    const aName = aSel.value, bName = bSel.value;
    if (aName === bName) { outHost.innerHTML = '<div class="ira-empty">Pick two different columns.</div>'; return; }
    const a = dataset.variables.find(v => v.name === aName).values || [];
    const b = dataset.variables.find(v => v.name === bName).values || [];
    const n = Math.min(a.length, b.length);
    const xs = [], ys = [];
    for (let i = 0; i < n; i++) {
      if (a[i] !== '' && a[i] != null && b[i] !== '' && b[i] != null) { xs.push(String(a[i])); ys.push(String(b[i])); }
    }
    if (xs.length < 5) { outHost.innerHTML = '<div class="ira-empty">Need at least 5 paired observations.</div>'; return; }
    const cats = Array.from(new Set(xs.concat(ys)));
    const idx  = {}; cats.forEach((c, i) => idx[c] = i);
    const k = cats.length;
    const matrix = Array.from({length: k}, () => new Array(k).fill(0));
    for (let i = 0; i < xs.length; i++) matrix[idx[xs[i]]][idx[ys[i]]]++;
    const total = xs.length;
    let observed = 0;
    for (let i = 0; i < k; i++) observed += matrix[i][i];
    const po = observed / total;
    const rowTotals = matrix.map(r => r.reduce((a,b)=>a+b, 0));
    const colTotals = cats.map((_, j) => matrix.reduce((a, r) => a + r[j], 0));
    let pe = 0;
    for (let i = 0; i < k; i++) pe += (rowTotals[i] / total) * (colTotals[i] / total);
    const kappa = (pe === 1) ? 1 : (po - pe) / (1 - pe);

    // Likert within-1 agreement
    let pct1 = null;
    if (xs.every(s => !isNaN(parseFloat(s))) && ys.every(s => !isNaN(parseFloat(s)))) {
      let within1 = 0;
      for (let i = 0; i < xs.length; i++) {
        if (Math.abs(parseFloat(xs[i]) - parseFloat(ys[i])) <= 1) within1++;
      }
      pct1 = within1 / xs.length;
    }

    const verdictClass = kappa >= 0.75 ? 'is-good' : (kappa >= 0.4 ? 'is-mid' : 'is-bad');
    const verdictLabel = kappa >= 0.81 ? 'Almost perfect agreement' :
                         kappa >= 0.61 ? 'Substantial agreement' :
                         kappa >= 0.41 ? 'Moderate agreement' :
                         kappa >= 0.21 ? 'Fair agreement' :
                         kappa >= 0    ? 'Slight agreement' : 'Worse than chance';

    outHost.innerHTML =
      '<div class="ira-results">' +
        '<div class="ira-card"><div class="lbl">Observed agreement</div><div class="val">' + (po*100).toFixed(1) + '%</div><div class="sub">' + observed + ' / ' + total + ' paired</div></div>' +
        '<div class="ira-card"><div class="lbl">Expected by chance</div><div class="val">' + (pe*100).toFixed(1) + '%</div></div>' +
        '<div class="ira-card"><div class="lbl">Cohen\'s κ</div><div class="val">' + kappa.toFixed(3) + '</div></div>' +
        (pct1 !== null ? ('<div class="ira-card"><div class="lbl">Within ±1</div><div class="val">' + (pct1*100).toFixed(1) + '%</div><div class="sub">numeric/Likert tolerance</div></div>') : '') +
      '</div>' +
      '<div class="ira-verdict ' + verdictClass + '">' + verdictLabel + '</div>';

    window.RELICHECK_APP_STATE = {
      app_key: 'inter_rater_agreement', lens: 'inter_rater_agreement',
      summary: 'Cohen κ = ' + kappa.toFixed(3) + ' (' + verdictLabel + ') · ' + xs.length + ' paired',
    };
  }
  aSel.addEventListener('change', calc);
  bSel.addEventListener('change', calc);
  calc();
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]); }
})();
</script>
