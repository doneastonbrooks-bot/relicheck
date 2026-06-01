<?php
// Theme Co-occurrence — for each pair of codebook themes, count how often
// they co-occur in the same open-ended response.
$mount_app            = 'mm_analysis';
$mount_lens           = 'theme_analysis';
$mount_section        = 'descriptive';
$mount_item           = 'theme_cooccurrence';
$mount_breadcrumb     = ['Descriptive', 'Theme Co-occurrence'];
$mount_title          = 'Theme Co-occurrence';
$mount_intro          = "For every pair of themes in your codebook, how often do they show up in the same response? High co-occurrence pairs are candidates for merging or for a higher-order theme.";
$mount_dataset_global = 'MM_DATASET';
$mount_lens_global    = 'MM_LENS';

include __DIR__ . '/_studio_mount.php';
?>

<style>
  .tco-empty { padding: 22px; background: #fff; border: 1px dashed var(--line); border-radius: 12px; color: var(--ink-4); text-align: center; }
  .tco-matrix { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--line); border-radius: 12px; overflow: hidden; font-size: 13px; margin-top: 14px; }
  .tco-matrix th, .tco-matrix td { padding: 8px 10px; border-bottom: 1px solid var(--line); border-right: 1px solid var(--line); }
  .tco-matrix th { background: var(--bg-tint, #f6f8fb); font-weight: 600; color: var(--ink-2); font-size: 12.5px; text-align: left; }
  .tco-cell { text-align: center; font-family: ui-monospace, monospace; }
  .tco-cell[data-lvl="0"] { color: var(--ink-5); }
  .tco-cell[data-lvl="1"] { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
  .tco-cell[data-lvl="2"] { background: var(--accent); color: #fff; font-weight: 700; }
  .tco-cell.is-diag { background: #f0f3f8; color: var(--ink-4); }
</style>

<div id="tcoHost"></div>

<script>
(function () {
  const PROJECT_ID = <?= json_encode((int)($_GET['project_id'] ?? 0)) ?>;
  const STUDIO    = <?= json_encode($_GET['studio'] ?? 'mm') ?>;
  const host = document.getElementById('tcoHost');
  let codebook = [], dataset = null;
  try {
    codebook = JSON.parse(window.localStorage.getItem('relicheck.codebook.' + PROJECT_ID) || '[]') || [];
    const raw = window.localStorage.getItem('relicheck.dataset.' + PROJECT_ID);
    if (raw) { const w = JSON.parse(raw); if (w && w.payload && w.payload.dataset) dataset = w.payload.dataset; }
  } catch (e) {}
  if (!codebook.length) { host.innerHTML = '<div class="tco-empty">No codebook yet. <a href="/codebook-builder.php?studio=' + STUDIO + '&project_id=' + encodeURIComponent(PROJECT_ID) + '" style="color:var(--accent);font-weight:600;">Define themes</a> to enable co-occurrence.</div>'; return; }
  if (!dataset) { host.innerHTML = '<div class="tco-empty">No dataset.</div>'; return; }
  const openVar = dataset.variables.find(v => v.types && v.types.indexOf('open') !== -1);
  if (!openVar) { host.innerHTML = '<div class="tco-empty">No open-ended column flagged in your data.</div>'; return; }
  const responses = (openVar.values || []).filter(r => r && r.trim());
  if (!responses.length) { host.innerHTML = '<div class="tco-empty">Open-ended column is empty.</div>'; return; }

  function matches(text, theme) {
    if (!theme.keywords || !theme.keywords.length) return false;
    const lower = String(text).toLowerCase();
    return theme.keywords.some(kw => kw && lower.indexOf(String(kw).toLowerCase()) !== -1);
  }
  const k = codebook.length;
  const co = Array.from({length: k}, () => new Array(k).fill(0));
  responses.forEach(r => {
    const hits = codebook.map(t => matches(r, t));
    for (let i = 0; i < k; i++) {
      if (!hits[i]) continue;
      for (let j = i; j < k; j++) {
        if (hits[j]) { co[i][j]++; if (i !== j) co[j][i]++; }
      }
    }
  });

  // Header row
  let html = '<table class="tco-matrix"><thead><tr><th></th>';
  codebook.forEach(t => { html += '<th title="' + esc(t.name) + '" style="text-align:center;max-width:100px;font-size:11.5px;">' + esc(t.name) + '</th>'; });
  html += '</tr></thead><tbody>';
  // Find max for level bucketing
  let maxOff = 0;
  for (let i = 0; i < k; i++) for (let j = 0; j < k; j++) if (i !== j && co[i][j] > maxOff) maxOff = co[i][j];
  codebook.forEach((t, i) => {
    html += '<tr><th title="' + esc(t.name) + '" style="font-size:11.5px;max-width:140px;">' + esc(t.name) + '</th>';
    for (let j = 0; j < k; j++) {
      let lvl = 0;
      if (i !== j && maxOff > 0) {
        if (co[i][j] >= maxOff * 0.7) lvl = 2;
        else if (co[i][j] >= maxOff * 0.3) lvl = 1;
      }
      const cls = i === j ? 'tco-cell is-diag' : 'tco-cell';
      html += '<td class="' + cls + '" data-lvl="' + lvl + '">' + co[i][j] + '</td>';
    }
    html += '</tr>';
  });
  html += '</tbody></table>';
  html += '<p style="color:var(--ink-5);font-size:12.5px;margin-top:8px;">Diagonal cells show solo theme counts; off-diagonals show co-occurrences. Highlighting reflects the strongest pairs (≥70% / ≥30% of max off-diagonal).</p>';
  host.innerHTML = html;
  window.RELICHECK_APP_STATE = {
    app_key: 'theme_cooccurrence', lens: 'theme_cooccurrence',
    summary: 'Theme co-occurrence matrix across ' + k + ' themes and ' + responses.length + ' responses.',
  };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]); }
})();
</script>
