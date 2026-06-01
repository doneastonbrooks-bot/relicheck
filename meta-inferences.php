<?php
// Meta-inferences — synthesized claim that integrates quant and qual.
$mount_app            = 'mm_analysis';
$mount_lens           = 'integration_quality';
$mount_section        = 'interpretation';
$mount_item           = 'meta_inferences';
$mount_breadcrumb     = ['Interpretation', 'Meta-inferences'];
$mount_title          = 'Meta-inferences';
$mount_intro          = "A meta-inference is the integrated claim you can make ONLY because you have both quant and qual results. The whole-greater-than-the-sum-of-parts read.";
$mount_dataset_global = 'MM_DATASET';
$mount_lens_global    = 'MM_LENS';

include __DIR__ . '/_studio_mount.php';
?>

<style>
  .mi-list { display: grid; gap: 10px; margin-top: 14px; }
  .mi-card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; }
  .mi-card .ix { display: inline-block; padding: 3px 9px; border-radius: 999px; background: var(--accent-soft); color: var(--accent); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
  .mi-card h3 { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; margin: 0 0 4px; color: var(--ink-1); }
  .mi-card p { color: var(--ink-3); font-size: 13.5px; line-height: 1.55; margin: 0 0 8px; }
  .mi-card .ev { font-size: 12.5px; color: var(--ink-5); }
  .mi-empty { padding: 22px; background: #fff; border: 1px dashed var(--line); border-radius: 12px; color: var(--ink-4); text-align: center; }
  .mi-tips { padding: 14px 18px; border-radius: 12px; background: var(--accent-soft); color: var(--ink-2); margin: 18px 0; font-size: 14px; line-height: 1.55; }
  .mi-tips strong { color: var(--ink-1); }
</style>

<div id="miHost"></div>

<script>
(function () {
  const PROJECT_ID = <?= json_encode((int)($_GET['project_id'] ?? 0)) ?>;
  const host = document.getElementById('miHost');
  let blocks = [];
  try {
    const raw = window.localStorage.getItem('relicheck.report.' + PROJECT_ID + '.default');
    if (raw) blocks = JSON.parse(raw) || [];
  } catch (e) {}

  let intro =
    '<div class="mi-tips"><strong>A strong meta-inference…</strong><br>' +
      'states a single integrated claim, names the quant evidence that supports it, names the qual evidence that supports it, and acknowledges any divergence. It is NOT just a list of findings — it is the synthesis.</div>';

  if (!blocks.length) {
    host.innerHTML = intro + '<div class="mi-empty">No analyses saved yet. Save at least one quant + one qual block before drafting meta-inferences.</div>';
    return;
  }

  // Group blocks into rough buckets
  const qualKeys  = ['theme_analysis','quote_extractor','theme_by_group','qual_to_quant','codebook_builder','open_ended_summary','trustworthiness','theme_cooccurrence','comment_theme'];
  const quantBlocks = [], qualBlocks = [];
  blocks.forEach(b => {
    const k = (b.app_key || b.lens || '').toLowerCase();
    if (qualKeys.some(q => k.indexOf(q) !== -1)) qualBlocks.push(b); else quantBlocks.push(b);
  });

  // Heuristic draft meta-inferences (templates the user can edit later)
  const drafts = [];
  if (quantBlocks.length && qualBlocks.length) {
    drafts.push({
      ix: 'Draft 1', title: 'Convergent meta-inference',
      body: 'The quant findings and the qual themes both point to the same pattern. ' + quantBlocks.length + ' quant block(s) and ' + qualBlocks.length + ' qual block(s) are aligned.',
      ev: 'Edit this card to specify which findings agree and why that strengthens your claim.',
    });
    drafts.push({
      ix: 'Draft 2', title: 'Complementary meta-inference',
      body: 'The qual results explain why the quant results came out the way they did. Use comments to illustrate mechanism.',
      ev: 'Edit this card to name the quant outcome and the qual mechanism that explains it.',
    });
  }
  if (qualBlocks.length && !quantBlocks.length) {
    drafts.push({
      ix: 'Draft', title: 'Qual-led meta-inference',
      body: 'You have qualitative findings but no quantitative anchors yet. The strongest meta-inference is going to need numeric support.',
      ev: 'Run the Reliability, t-test, or correlation lens, save a result, then return here.',
    });
  }
  if (quantBlocks.length && !qualBlocks.length) {
    drafts.push({
      ix: 'Draft', title: 'Quant-led meta-inference',
      body: 'You have quantitative findings but no qualitative anchors yet. A meta-inference needs both sides.',
      ev: 'Run Theme Analysis or save a qual block, then return here.',
    });
  }

  let html = intro;
  if (drafts.length) {
    html += '<div class="mi-list">' + drafts.map(d =>
      '<div class="mi-card"><span class="ix">' + esc(d.ix) + '</span><h3>' + esc(d.title) + '</h3><p>' + esc(d.body) + '</p><div class="ev">' + esc(d.ev) + '</div></div>'
    ).join('') + '</div>';
  }

  host.innerHTML = html;
  window.RELICHECK_APP_STATE = {
    app_key: 'meta_inferences', lens: 'meta_inferences',
    summary: drafts.length + ' draft meta-inference(s) based on ' + quantBlocks.length + ' quant + ' + qualBlocks.length + ' qual blocks.',
  };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]); }
})();
</script>
