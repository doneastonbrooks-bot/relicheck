<?php
// Pattern Matching — MM analytic technique. Compares the pattern in quant
// findings against the pattern in qual themes. Shows the saved blocks and
// highlights agreement vs. divergence.
$mount_app            = 'mm_analysis';
$mount_lens           = 'integration_quality';
$mount_section        = 'inferential';
$mount_item           = 'pattern_matching';
$mount_breadcrumb     = ['Inferential', 'Pattern Matching'];
$mount_title          = 'Pattern Matching';
$mount_intro          = "Mixed-methods pattern matching: does the qualitative story line up with the quantitative one? Lists your saved analyses side by side and surfaces convergence vs. divergence.";
$mount_dataset_global = 'MM_DATASET';
$mount_lens_global    = 'MM_LENS';

include __DIR__ . '/_studio_mount.php';
?>

<style>
  .pm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 14px; }
  @media (max-width: 800px) { .pm-grid { grid-template-columns: 1fr; } }
  .pm-col { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; }
  .pm-col h3 { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; margin: 0 0 10px; color: var(--ink-1); }
  .pm-block { background: var(--bg-tint, #f6f8fb); border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; font-size: 13px; }
  .pm-block strong { color: var(--ink-1); }
  .pm-empty { padding: 22px; background: #fff; border: 1px dashed var(--line); border-radius: 12px; color: var(--ink-4); text-align: center; }
  .pm-verdict { padding: 14px 18px; border-radius: 12px; background: var(--accent-soft); color: var(--ink-2); margin-top: 14px; font-size: 14px; line-height: 1.55; }
</style>

<div id="pmHost"></div>

<script>
(function () {
  const PROJECT_ID = <?= json_encode((int)($_GET['project_id'] ?? 0)) ?>;
  const host = document.getElementById('pmHost');
  let blocks = [];
  try {
    const raw = window.localStorage.getItem('relicheck.report.' + PROJECT_ID + '.default');
    if (raw) blocks = JSON.parse(raw) || [];
  } catch (e) {}
  if (!blocks.length) { host.innerHTML = '<div class="pm-empty">No analyses saved yet. Save quant and qual analyses to the report, then return here.</div>'; return; }

  // Bucket blocks into quant vs qual based on app_key/lens
  const qualKeys  = ['theme_analysis','quote_extractor','theme_by_group','qual_to_quant','codebook_builder','open_ended_summary','trustworthiness','theme_cooccurrence','comment_theme'];
  const quantBlocks = [], qualBlocks = [];
  blocks.forEach(b => {
    const k = (b.app_key || b.lens || '').toLowerCase();
    if (qualKeys.some(q => k.indexOf(q) !== -1)) qualBlocks.push(b); else quantBlocks.push(b);
  });

  function renderCol(title, list) {
    if (!list.length) return '<div class="pm-col"><h3>' + esc(title) + '</h3><div class="pm-block" style="color:var(--ink-5);font-style:italic;">No ' + esc(title.toLowerCase()) + ' analyses saved yet.</div></div>';
    return '<div class="pm-col"><h3>' + esc(title) + '</h3>' +
      list.map(b => '<div class="pm-block"><strong>' + esc(b.app_key || b.lens || 'Analysis') + '</strong><br>' + esc(b.summary || 'Saved.') + '</div>').join('') +
    '</div>';
  }

  let verdict;
  if (!qualBlocks.length || !quantBlocks.length) {
    verdict = 'Pattern matching needs both sides. Save at least one quantitative and one qualitative analysis to surface convergence or divergence.';
  } else {
    verdict = 'You have ' + quantBlocks.length + ' quant and ' + qualBlocks.length + ' qual block(s). Read the two columns side by side: where do they tell the same story, and where do they pull apart? Convergence strengthens a finding; divergence is itself a finding worth explaining.';
  }

  host.innerHTML = '<div class="pm-grid">' + renderCol('Quantitative', quantBlocks) + renderCol('Qualitative', qualBlocks) + '</div>' +
                   '<div class="pm-verdict">' + esc(verdict) + '</div>';

  window.RELICHECK_APP_STATE = {
    app_key: 'pattern_matching', lens: 'pattern_matching',
    summary: 'Pattern matching: ' + quantBlocks.length + ' quant + ' + qualBlocks.length + ' qual blocks compared.',
  };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]); }
})();
</script>
