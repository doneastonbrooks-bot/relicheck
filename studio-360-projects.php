<?php
// 360 Studio · panel picker (Step 3).
// -------------------------------------------------------------------
// 360's unit of work is a "panel" (one launch with N subjects, M raters
// each). Picker fetches /api/panels/list.php; on click, hand off to
// /project-snapshot.php?studio=360&project_id=<panel_id>.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/studio-360-projects.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
if ($projectId > 0) {
  $pdo = db();
  try {
    $stmt = $pdo->prepare('SELECT id FROM survey_360_panels WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    $ok = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $ok = false; }
  if ($ok) { header('Location: /project-snapshot.php?studio=360&project_id=' . $projectId); exit; }
}

$current_studio  = '360';
$current_section = '';
$current_item    = '';
$studio_project_label = 'No panel selected';
$studio_context_label = 'Pick one below to begin';

$t6_route_map = [
  'project_snapshot'             => '/project-snapshot.php',
  'sample_sources'               => '/sample-profile.php',
  'data_quality'                 => '/data-quality.php',
  'cohort_summary'               => '/cohort-summary.php',
  'validity'                     => '/validity.php',
  'item_quality'                 => '/item-quality.php',
  'scale_structure'              => '/scale-structure.php',
  'response_scale_review'        => '/response-scale.php',
  'confidentiality_threshold'    => '/confidentiality-threshold.php',
  'frequencies'                  => '/frequencies.php',
  'means_distributions'          => '/distributions.php',
  'cross_tabs'                   => '/cross-tabs.php',
  'group_summaries'              => '/group-summaries.php',
  'rater_group_comparison'       => '/rater-group-comparison.php',
  'comment_theme'                => '/comment-theme.php',
  't_test'                       => '/t-test.php',
  'anova'                        => '/anova.php',
  'chi_square'                   => '/chi-square.php',
  'correlation'                  => '/correlation.php',
  'effect_sizes'                 => '/effect-size.php',
  'paired_t_test'                => '/paired-t-test.php',
  'welch_anova'                  => '/welch-anova.php',
  'post_hoc'                     => '/post-hoc.php',
  'regression'                   => '/regression.php',
  'confidence_interval'          => '/confidence-interval.php',
  'assumption_checks'            => '/assumption-checks.php',
  'key_findings'                 => '/key-findings.php',
  'evidence_notes'               => '/evidence-alignment.php',
  'practical_meaning'            => '/practical-significance.php',
  'limitations'                  => '/limitations.php',
  'recommended_actions'          => '/recommended-actions.php',
  'teaching_moments'             => '/teaching-moments.php',
  'ai_interpretation'            => '/ai-interpretation.php',
  'decision_readiness'           => '/decision-readiness.php',
  'development_plan'             => '/development-plan.php',
  'report_builder'               => '/report-builder.php',
  'executive_summary'            => '/executive-summary.php',
  'methodology'                  => '/methodology.php',
  'findings'                     => '/findings.php',
  'tables_figures'               => '/tables-figures.php',
  'recommendations'              => '/recommendations.php',
  'appendix'                     => '/appendix.php',
  'exports'                      => '/export.php',
];

$_catalog = require __DIR__ . '/_studio_items_catalog.php';
$qsuffix  = '?studio=360';
foreach ($_catalog as &$_section) {
  foreach ($_section['items'] as &$_item) {
    if (isset($t6_route_map[$_item['key']])) {
      $_item['route'] = $t6_route_map[$_item['key']] . $qsuffix;
    }
  }
}
unset($_section, $_item);

$shell_body_attrs    = 'data-current-studio="360" data-no-project="1"';
$shell_page_title    = 'Pick a panel · ReliCheck';
$shell_user_full     = $user['name'] ?? $user['email'] ?? 'You';
$shell_user_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $shell_user_full) ?: 'U', 0, 2));
$shell_project_label = 'No project open';

include __DIR__ . '/_platform_shell_header.php';
?>

<link rel="stylesheet" href="/studio-template.css">
<style>
  body[data-no-project="1"] .studio-tools { opacity: 0.45; pointer-events: none; }
  body[data-no-project="1"] .studio-tools a { cursor: not-allowed; }
  .t6-picker { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-top: 6px; }
  .t6-picker-empty, .t6-picker-loading, .t6-picker-error {
    grid-column: 1 / -1; padding: 24px; background: #fff;
    border: 1px dashed var(--line); border-radius: 14px;
    color: var(--ink-4); text-align: center; font-size: 14px;
  }
  .t6-picker-error { color: #c2492f; border-color: #f3d8c0; background: #fff5f3; }
  .t6-picker-card {
    display: flex; flex-direction: column; gap: 6px;
    padding: 18px 20px; background: #fff;
    border: 1px solid var(--line); border-radius: 14px;
    text-decoration: none; color: inherit;
    transition: border-color 0.15s var(--ease), transform 0.15s var(--ease), box-shadow 0.15s var(--ease);
  }
  .t6-picker-card:hover { border-color: var(--accent); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(20, 28, 48, 0.06); }
  .t6-pc-title { font-family: 'Fraunces', 'Georgia', serif; font-size: 18px; font-weight: 600; line-height: 1.3; color: var(--ink-1, #1c2238); }
  .t6-pc-meta { font-size: 12.5px; color: var(--ink-5); font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace; }
  .t6-pc-status { font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-5); }
  .t6-pc-new { border-style: dashed; color: var(--ink-3); align-items: center; justify-content: center; text-align: center; min-height: 110px; }
  .t6-pc-new .t6-pc-title { color: var(--accent); }
</style>

<?php include __DIR__ . '/_studio_template_header.php'; ?>

<div class="work-breadcrumb">
  <a href="/studio-360.php" style="text-decoration:none;color:var(--ink-4);">360 Studio</a>
  <span class="sep">/</span>
  <strong>Pick a panel</strong>
</div>

<div class="work-head">
  <h2>Pick a panel to open</h2>
  <p>360 Studio works one panel at a time. A panel covers one or more subjects, each rated by self, peers, manager, and direct reports.</p>
</div>
<div class="t6-picker" id="t6Picker">
  <p class="t6-picker-loading">Loading your panels…</p>
</div>

<?php include __DIR__ . '/_studio_template_footer.php'; ?>

<script>
  (function () {
    const host = document.getElementById('t6Picker');
    if (!host) return;
    const newCard =
      '<a class="t6-picker-card t6-pc-new" href="/evidence-intake.php?studio=360">' +
        '<div class="t6-pc-title">+ New 360 panel</div>' +
        '<div class="t6-pc-meta">Opens the 360 Evidence Intake wizard.</div>' +
      '</a>';
    fetch('/api/panels/list.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) throw new Error(data && data.message ? data.message : 'Bad response');
        const panels = Array.isArray(data.panels) ? data.panels : [];
        if (!panels.length) {
          host.innerHTML = newCard + '<div class="t6-picker-empty">No panels yet. Click the tile above to start one.</div>';
          return;
        }
        host.innerHTML = panels.map(function (p) {
          const c = p.counts || {};
          const meta = (c.subjects || 0) + ' subjects · ' + (c.evaluators || 0) + ' raters · ' + (c.completion_percent || 0) + '% complete';
          return '<a class="t6-picker-card" href="/studio-360-projects.php?project_id=' + encodeURIComponent(p.id) + '">' +
            '<div class="t6-pc-status">' + esc(p.status || 'draft') + '</div>' +
            '<div class="t6-pc-title">' + esc(p.name || p.survey_title || 'Untitled panel') + '</div>' +
            '<div class="t6-pc-meta">' + meta + '</div>' +
          '</a>';
        }).join('') + newCard;
      })
      .catch(function (err) {
        host.innerHTML = '<p class="t6-picker-error">Could not load your panels: ' + esc(String(err.message || err)) + '</p>';
      });
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }
  })();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
