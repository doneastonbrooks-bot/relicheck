<?php
// TIA Studio · project picker (Step 3).
// -------------------------------------------------------------------
// The picker leg of the TIA entry. /api/tia/projects.php may not exist
// yet (TIA is `dev`), so the fetch handles 404 gracefully and falls
// back to the empty state + Upload tile.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/studio-tia-projects.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
if ($projectId > 0) {
  $pdo = db();
  try {
    $stmt = $pdo->prepare('SELECT id FROM tia_projects WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    $ok = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $ok = false; }
  if ($ok) { header('Location: /project-snapshot.php?studio=tia&project_id=' . $projectId); exit; }
}

$current_studio  = 'tia';
$current_section = '';
$current_item    = '';
$studio_project_label = 'No test selected';
$studio_context_label = 'Pick one below to begin';

$tia_route_map = [
  'project_snapshot'       => '/project-snapshot.php',
  'sample_sources'         => '/sample-profile.php',
  'data_quality'           => '/data-quality.php',
  'validity'               => '/validity.php',
  'item_quality'           => '/item-quality.php',
  'scale_structure'        => '/scale-structure.php',
  'response_scale_review'  => '/response-scale.php',
  'answer_key_validation'  => '/answer-key.php',
  'frequencies'            => '/frequencies.php',
  'means_distributions'    => '/distributions.php',
  'cross_tabs'             => '/cross-tabs.php',
  'group_summaries'        => '/group-summaries.php',
  'top_bottom_items'       => '/top-bottom-items.php',
  'scale_scores'           => '/scale-scores.php',
  't_test'                 => '/t-test.php',
  'anova'                  => '/anova.php',
  'chi_square'             => '/chi-square.php',
  'correlation'            => '/correlation.php',
  'effect_sizes'           => '/effect-size.php',
  'paired_t_test'          => '/paired-t-test.php',
  'welch_anova'            => '/welch-anova.php',
  'post_hoc'               => '/post-hoc.php',
  'regression'             => '/regression.php',
  'confidence_interval'    => '/confidence-interval.php',
  'assumption_checks'      => '/assumption-checks.php',
  'key_findings'           => '/key-findings.php',
  'evidence_notes'         => '/evidence-alignment.php',
  'practical_meaning'      => '/practical-significance.php',
  'limitations'            => '/limitations.php',
  'recommended_actions'    => '/recommended-actions.php',
  'teaching_moments'       => '/teaching-moments.php',
  'ai_interpretation'      => '/ai-interpretation.php',
  'decision_readiness'     => '/decision-readiness.php',
  'report_builder'         => '/report-builder.php',
  'executive_summary'      => '/executive-summary.php',
  'methodology'            => '/methodology.php',
  'findings'               => '/findings.php',
  'tables_figures'         => '/tables-figures.php',
  'recommendations'        => '/recommendations.php',
  'appendix'               => '/appendix.php',
  'exports'                => '/export.php',
];

$_catalog = require __DIR__ . '/_studio_items_catalog.php';
$qsuffix  = '?studio=tia';
foreach ($_catalog as &$_section) {
  foreach ($_section['items'] as &$_item) {
    if (isset($tia_route_map[$_item['key']])) {
      $_item['route'] = $tia_route_map[$_item['key']] . $qsuffix;
    }
  }
}
unset($_section, $_item);

$shell_body_attrs    = 'data-current-studio="tia" data-no-project="1"';
$shell_page_title    = 'Pick a test · ReliCheck';
$shell_user_full     = $user['name'] ?? $user['email'] ?? 'You';
$shell_user_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $shell_user_full) ?: 'U', 0, 2));
$shell_project_label = 'No project open';

include __DIR__ . '/_platform_shell_header.php';
?>

<link rel="stylesheet" href="/studio-template.css">
<style>
  body[data-no-project="1"] .studio-tools { opacity: 0.45; pointer-events: none; }
  body[data-no-project="1"] .studio-tools a { cursor: not-allowed; }
  .tia-picker { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-top: 6px; }
  .tia-picker-empty, .tia-picker-loading, .tia-picker-error {
    grid-column: 1 / -1; padding: 24px; background: #fff;
    border: 1px dashed var(--line); border-radius: 14px;
    color: var(--ink-4); text-align: center; font-size: 14px;
  }
  .tia-picker-error { color: #c2492f; border-color: #f3d8c0; background: #fff5f3; }
  .tia-picker-card {
    display: flex; flex-direction: column; gap: 6px;
    padding: 18px 20px; background: #fff;
    border: 1px solid var(--line); border-radius: 14px;
    text-decoration: none; color: inherit;
    transition: border-color 0.15s var(--ease), transform 0.15s var(--ease), box-shadow 0.15s var(--ease);
  }
  .tia-picker-card:hover { border-color: var(--accent); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(20, 28, 48, 0.06); }
  .tia-pc-title { font-family: 'Fraunces', 'Georgia', serif; font-size: 18px; font-weight: 600; line-height: 1.3; color: var(--ink-1, #1c2238); }
  .tia-pc-meta { font-size: 12.5px; color: var(--ink-5); font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace; }
  .tia-pc-new { border-style: dashed; color: var(--ink-3); align-items: center; justify-content: center; text-align: center; min-height: 110px; }
  .tia-pc-new .tia-pc-title { color: var(--accent); }
</style>

<?php include __DIR__ . '/_studio_template_header.php'; ?>

<div class="work-breadcrumb">
  <a href="/studio-tia.php" style="text-decoration:none;color:var(--ink-4);">TIA Studio</a>
  <span class="sep">/</span>
  <strong>Pick a test</strong>
</div>

<div class="work-head">
  <h2>Pick a test to open</h2>
  <p>Test and Item Analysis works on one test at a time. Pick an existing project or upload new data. Until you pick, the rail on the left is dimmed.</p>
</div>
<div class="tia-picker" id="tiaPicker">
  <p class="tia-picker-loading">Loading your TIA projects…</p>
</div>

<?php include __DIR__ . '/_studio_template_footer.php'; ?>

<script>
  (function () {
    const host = document.getElementById('tiaPicker');
    if (!host) return;
    const newCard =
      '<a class="tia-picker-card tia-pc-new" href="/evidence-intake.php?studio=tia">' +
        '<div class="tia-pc-title">+ Upload test data / new project</div>' +
        '<div class="tia-pc-meta">Opens the TIA Evidence Intake wizard.</div>' +
      '</a>';
    fetch('/api/tia/projects.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        const projects = (data && data.ok && Array.isArray(data.projects)) ? data.projects : [];
        if (!projects.length) {
          host.innerHTML = newCard + '<div class="tia-picker-empty">No TIA projects yet. Click the tile above to start one.</div>';
          return;
        }
        host.innerHTML = projects.map(function (p) {
          return '<a class="tia-picker-card" href="/studio-tia-projects.php?project_id=' + encodeURIComponent(p.id) + '">' +
            '<div class="tia-pc-title">' + esc(p.title || 'Untitled test') + '</div>' +
            '<div class="tia-pc-meta">Updated ' + esc((p.updated_at || '').slice(0,10) || '—') + '</div>' +
          '</a>';
        }).join('') + newCard;
      })
      .catch(function () {
        host.innerHTML = newCard + '<div class="tia-picker-empty">No TIA projects yet. Click the tile above to start one.</div>';
      });
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }
  })();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
