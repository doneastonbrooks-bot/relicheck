<?php
// LEGACY — superseded by /rssi.php (the RSSI app's own picker).
// Redirected so old bookmarks land on the new template-based UI.
header('Location: /rssi.php', true, 301);
exit;

// ─── original code preserved below for reference, never executes ───
// Survey Studio · project picker (Step 3).

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/studio-strength-survey-projects.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) {
  $_SESSION = []; session_destroy();
  header('Location: /login.html');
  exit;
}

$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;
if ($projectId > 0) {
  $pdo = db();
  try {
    $stmt = $pdo->prepare('SELECT id FROM surveys WHERE id = :id AND owner_id = :uid AND (archived_at IS NULL)');
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    $ok = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $ok = false; }
  if ($ok) {
    header('Location: /project-snapshot.php?studio=strength-survey&project_id=' . $projectId);
    exit;
  }
}

$current_studio  = 'survey';
$current_section = '';
$current_item    = '';
$studio_project_label = 'No survey selected';
$studio_context_label = 'Pick one below to begin';

$survey_route_map = [
  'project_snapshot'       => '/project-snapshot.php',
  'sample_sources'         => '/sample-profile.php',
  'data_quality'           => '/data-quality.php',
  'strength_index'         => '/strength-index.php',
  'validity'               => '/validity.php',
  'item_quality'           => '/item-quality.php',
  'scale_structure'        => '/scale-structure.php',
  'factor_readiness'       => '/factor-readiness.php',
  'bias_review'            => '/bias-clarity.php',
  'response_scale_review'  => '/response-scale.php',
  'frequencies'            => '/frequencies.php',
  'means_distributions'    => '/distributions.php',
  'cross_tabs'             => '/cross-tabs.php',
  'group_summaries'        => '/group-summaries.php',
  'item_theme_summaries'   => '/open-ended-summary.php',
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
$qsuffix  = '?studio=strength-survey';
foreach ($_catalog as &$_section) {
  foreach ($_section['items'] as &$_item) {
    if (isset($survey_route_map[$_item['key']])) {
      $_item['route'] = $survey_route_map[$_item['key']] . $qsuffix;
    }
  }
}
unset($_section, $_item);

$shell_body_attrs    = 'data-current-studio="strength-survey" data-no-project="1"';
$shell_page_title    = 'Pick a survey · ReliCheck';
$shell_user_full     = $user['name'] ?? $user['email'] ?? 'You';
$shell_user_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $shell_user_full) ?: 'U', 0, 2));
$shell_project_label = 'No project open';

include __DIR__ . '/_platform_shell_header.php';
?>

<link rel="stylesheet" href="/studio-template.css">
<style>
  body[data-no-project="1"] .studio-tools { opacity: 0.45; pointer-events: none; }
  body[data-no-project="1"] .studio-tools a { cursor: not-allowed; }
  .sv-picker { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-top: 6px; }
  .sv-picker-empty, .sv-picker-loading, .sv-picker-error {
    grid-column: 1 / -1; padding: 24px; background: #fff;
    border: 1px dashed var(--line); border-radius: 14px;
    color: var(--ink-4); text-align: center; font-size: 14px;
  }
  .sv-picker-error { color: #c2492f; border-color: #f3d8c0; background: #fff5f3; }
  .sv-picker-card {
    display: flex; flex-direction: column; gap: 6px;
    padding: 18px 20px; background: #fff;
    border: 1px solid var(--line); border-radius: 14px;
    text-decoration: none; color: inherit;
    transition: border-color 0.15s var(--ease), transform 0.15s var(--ease), box-shadow 0.15s var(--ease);
  }
  .sv-picker-card:hover { border-color: var(--accent); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(20, 28, 48, 0.06); }
  .sv-pc-title { font-family: 'Fraunces', 'Georgia', serif; font-size: 18px; font-weight: 600; line-height: 1.3; color: var(--ink-1, #1c2238); }
  .sv-pc-meta { font-size: 12.5px; color: var(--ink-5); font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace; }
  .sv-pc-status { font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-5); }
  .sv-pc-new { border-style: dashed; color: var(--ink-3); align-items: center; justify-content: center; text-align: center; min-height: 110px; }
  .sv-pc-new .sv-pc-title { color: var(--accent); }
</style>

<?php include __DIR__ . '/_studio_template_header.php'; ?>

<div class="work-breadcrumb">
  <a href="/rssi.php" style="text-decoration:none;color:var(--ink-4);">Survey Studio</a>
  <span class="sep">/</span>
  <strong>Pick a survey</strong>
</div>

<div class="work-head">
  <h2>Pick a survey to open</h2>
  <p>Survey Studio works one survey at a time. Pick an existing survey below or start a new one. Until you pick, the rail on the left is dimmed because every analysis needs a survey's responses to run.</p>
</div>
<div class="sv-picker" id="svPicker">
  <p class="sv-picker-loading">Loading your surveys…</p>
</div>

<?php include __DIR__ . '/_studio_template_footer.php'; ?>

<script>
  (function () {
    const host = document.getElementById('svPicker');
    if (!host) return;

    const newCard =
      '<a class="sv-picker-card sv-pc-new" href="/strength-survey-wizard.php?step=1">' +
        '<div class="sv-pc-title">+ New survey / upload data</div>' +
        '<div class="sv-pc-meta">Opens the Survey Evidence Intake wizard.</div>' +
      '</a>';

    fetch('/api/surveys/list.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) throw new Error(data && data.message ? data.message : 'Bad response');
        const surveys = Array.isArray(data.surveys) ? data.surveys : [];
        if (!surveys.length) {
          host.innerHTML = newCard + '<div class="sv-picker-empty">No surveys yet. Click the tile above to start one.</div>';
          return;
        }
        host.innerHTML = surveys.map(function (s) {
          const status = s.is_published ? 'Published' : (s.archived_at ? 'Archived' : 'Draft');
          const meta = (s.response_count || 0) + ' responses · ' + (s.item_count || 0) + ' items' +
                       (s.updated_at ? ' · updated ' + s.updated_at.slice(0, 10) : '');
          return '<a class="sv-picker-card" href="/rssi-report.php?id=' + encodeURIComponent(s.id) + '">' +
            '<div class="sv-pc-status">' + esc(status) + '</div>' +
            '<div class="sv-pc-title">' + esc(s.title || 'Untitled survey') + '</div>' +
            '<div class="sv-pc-meta">' + meta + '</div>' +
          '</a>';
        }).join('') + newCard;
      })
      .catch(function (err) {
        host.innerHTML = '<p class="sv-picker-error">Could not load your surveys: ' + esc(String(err.message || err)) + '</p>';
      });
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }
  })();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
