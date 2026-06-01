<?php
// Trustworthiness — qualitative rigor checklist (Lincoln & Guba). MM-specific.
// Shows the 4 trustworthiness criteria plus, when codebook data is present,
// a quick read of what's covered.
$mount_app            = 'mm_analysis';
$mount_lens           = 'theme_analysis';
$mount_section        = 'instrument_quality';
$mount_item           = 'trustworthiness';
$mount_breadcrumb     = ['Instrument Quality', 'Trustworthiness'];
$mount_title          = 'Trustworthiness';
$mount_intro          = "Qualitative rigor against Lincoln & Guba's four criteria: credibility, transferability, dependability, and confirmability. Your codebook and saved blocks feed the auto-checks.";
$mount_dataset_global = 'MM_DATASET';
$mount_lens_global    = 'MM_LENS';

include __DIR__ . '/_studio_mount.php';
?>

<style>
  .tw-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; margin-top: 14px; }
  .tw-card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; }
  .tw-card h3 { font-family: 'Fraunces', serif; font-size: 17px; font-weight: 600; margin: 0 0 4px; color: var(--ink-1); }
  .tw-card .pill { display: inline-block; padding: 3px 9px; background: var(--accent-soft); color: var(--accent); border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
  .tw-card .what { color: var(--ink-3); font-size: 13.5px; line-height: 1.5; margin: 0 0 8px; }
  .tw-card .check { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: var(--bg-tint, #f6f8fb); border-radius: 8px; font-size: 13px; margin-top: 6px; }
  .tw-card .check .pip { width: 18px; height: 18px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #fff; flex-shrink: 0; }
  .tw-card .check .pip.is-good { background: #1f7a3a; }
  .tw-card .check .pip.is-mid  { background: #b35a00; }
  .tw-card .check .pip.is-bad  { background: #c2492f; }
</style>

<div id="twHost"></div>

<script>
(function () {
  const PROJECT_ID = <?= json_encode((int)($_GET['project_id'] ?? 0)) ?>;
  const host = document.getElementById('twHost');
  let codebook = [];
  try { codebook = JSON.parse(window.localStorage.getItem('relicheck.codebook.' + PROJECT_ID) || '[]') || []; } catch (e) {}
  let blocks = [];
  try { blocks = JSON.parse(window.localStorage.getItem('relicheck.report.' + PROJECT_ID + '.default') || '[]') || []; } catch (e) {}
  let dataset = null;
  try {
    const raw = window.localStorage.getItem('relicheck.dataset.' + PROJECT_ID);
    if (raw) { const w = JSON.parse(raw); if (w && w.payload && w.payload.dataset) dataset = w.payload.dataset; }
  } catch (e) {}

  const hasCodebook = codebook.length >= 3;
  const hasQuotes   = blocks.some(b => b.app_key === 'quote_extractor' || (b.lens || '').indexOf('quote') !== -1);
  const hasGroupCompare = blocks.some(b => b.app_key === 'theme_by_group' || (b.lens || '').indexOf('group') !== -1);
  const openCol = dataset && dataset.variables ? dataset.variables.find(v => v.types && v.types.indexOf('open') !== -1) : null;

  const tier = (good, mid) => good ? 'good' : (mid ? 'mid' : 'bad');
  const pipChar = { good: '✓', mid: '!', bad: '×' };
  const c = [
    {
      pill: 'Credibility',
      title: 'Are the findings believable?',
      what: 'Triangulation (multiple sources), member checks, peer debriefing, and prolonged engagement with the data.',
      checks: [
        { lvl: tier(hasCodebook, codebook.length >= 1), label: hasCodebook ? 'Codebook has ' + codebook.length + ' themes' : (codebook.length ? 'Codebook is sparse (' + codebook.length + ' theme' + (codebook.length === 1 ? '' : 's') + ')' : 'No codebook yet') },
        { lvl: tier(hasQuotes, false), label: hasQuotes ? 'Exemplar quotes saved' : 'No exemplar quotes saved' },
      ],
    },
    {
      pill: 'Transferability',
      title: 'Can the findings travel to other contexts?',
      what: 'Thick description of the setting, participants, and analytic decisions — enough for a reader to judge fit.',
      checks: [
        { lvl: tier(!!openCol, false), label: openCol ? 'Open-ended column present: ' + openCol.name : 'No open-ended column flagged' },
      ],
    },
    {
      pill: 'Dependability',
      title: 'Are the procedures consistent and traceable?',
      what: 'An audit trail of coding decisions, theme revisions, and analytic choices.',
      checks: [
        { lvl: tier(blocks.length >= 3, blocks.length >= 1), label: blocks.length + ' saved analysis block' + (blocks.length === 1 ? '' : 's') },
      ],
    },
    {
      pill: 'Confirmability',
      title: 'Are findings grounded in the data?',
      what: 'Quotes and counts that link directly back to participants. Reflexivity on analyst influence.',
      checks: [
        { lvl: tier(hasGroupCompare, false), label: hasGroupCompare ? 'Theme-by-group cross-tab saved' : 'No theme-by-group cross-tab saved yet' },
      ],
    },
  ];

  host.innerHTML = '<div class="tw-grid">' + c.map(card =>
    '<div class="tw-card">' +
      '<span class="pill">' + card.pill + '</span>' +
      '<h3>' + card.title + '</h3>' +
      '<p class="what">' + card.what + '</p>' +
      card.checks.map(ch => '<div class="check"><span class="pip is-' + ch.lvl + '">' + pipChar[ch.lvl] + '</span><span>' + ch.label + '</span></div>').join('') +
    '</div>'
  ).join('') + '</div>';

  const goodCount = c.reduce((a, card) => a + card.checks.filter(ch => ch.lvl === 'good').length, 0);
  const totalCount = c.reduce((a, card) => a + card.checks.length, 0);
  window.RELICHECK_APP_STATE = {
    app_key: 'trustworthiness', lens: 'trustworthiness',
    summary: 'Trustworthiness: ' + goodCount + '/' + totalCount + ' rigor checks satisfied.',
  };
})();
</script>
