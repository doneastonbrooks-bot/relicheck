<?php
// Shared studio mount partial.
// -------------------------------------------------------------------
// Every analysis mount page (project-snapshot.php, t-test.php, etc.)
// is the same boilerplate: auth-gate, validate ?studio=&project_id=,
// verify ownership, set shell + studio template variables, attach
// rail routes that carry the project_id through, and finally load
// the project's dataset from localStorage on the client.
//
// Usage (from the mount page):
//
//   $mount_app        = 'overview';            // _app_registry.php key
//   $mount_lens       = 'project_snapshot';    // engine-plus-lenses key
//   $mount_section    = 'overview';            // rail section to highlight
//   $mount_item       = 'project_snapshot';    // rail item to mark active
//   $mount_breadcrumb = ['Overview', 'Project Snapshot'];
//   $mount_title      = 'Project Snapshot';
//   $mount_intro      = 'One-page overview…';
//   $mount_dataset_global = 'OVERVIEW_DATASET'; // window.<NAME>
//   $mount_lens_global    = 'OVERVIEW_LENS';
//   include __DIR__ . '/_studio_mount.php';
//
// The partial handles the rest. After it returns, the page is fully
// rendered.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

// ---------- Auth ----------
start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode($_SERVER['SCRIPT_NAME'] . $qs));
  exit;
}
$mount_user = current_user();
if (!$mount_user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }

// ---------- Validate studio + project ----------
$valid_studios = ['survey', 'mm', 'tia', '360', 'strength-survey', 'descriptive', 'inferential'];
$studio_slug   = $_GET['studio'] ?? 'survey';
if (!in_array($studio_slug, $valid_studios, true)) $studio_slug = 'survey';
$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;

// Verify ownership per studio's project table.
// 'strength-survey' shares the surveys table with 'survey' (it's a parallel
// UI on the same data shape, not a separate project type).
// Descriptive & Inferential are dataset-based analysis studios: they read the
// dataset from localStorage (by project_id) and have no server project table,
// so skip the ownership lookup for them.
$dataset_studios = ['descriptive', 'inferential'];
$mount_project = null;
if ($projectId > 0 && !in_array($studio_slug, $dataset_studios, true)) {
  $pdo = db();
  try {
    if ($studio_slug === 'survey' || $studio_slug === 'strength-survey') {
      $stmt = $pdo->prepare('SELECT id, title FROM surveys WHERE id = :id AND owner_id = :uid AND (archived_at IS NULL)');
    } elseif ($studio_slug === 'mm') {
      $stmt = $pdo->prepare('SELECT id, title FROM mm_projects WHERE id = :id AND user_id = :uid AND status <> "archived"');
    } elseif ($studio_slug === '360') {
      $stmt = $pdo->prepare('SELECT id, name AS title FROM survey_360_panels WHERE id = :id AND user_id = :uid');
    } else { // tia
      $stmt = $pdo->prepare('SELECT id, title FROM tia_projects WHERE id = :id AND user_id = :uid');
    }
    $stmt->execute([':id' => $projectId, ':uid' => $uid]);
    $mount_project = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $mount_project = null; // table may not exist for tia yet
  }
}
// If a project_id was supplied but ownership failed, bounce to the picker.
// (Dataset studios never reach here — they skip the lookup above.)
if ($projectId > 0 && !$mount_project && !in_array($studio_slug, $dataset_studios, true)) {
  header('Location: /studio-' . $studio_slug . '-projects.php');
  exit;
}

// ---------- Studio + app from registries ----------
$_studios = require __DIR__ . '/_studio_registry.php';
$_apps    = require __DIR__ . '/_app_registry.php';
$_studio  = $_studios[$studio_slug];

$mount_app_key = $mount_app ?? null;
if (!$mount_app_key || !isset($_apps[$mount_app_key])) {
  http_response_code(500);
  echo 'Mount error: unknown app key.';
  exit;
}
$_app = $_apps[$mount_app_key];

// ---------- Rail routes for this studio ----------
// One ground-truth route map per studio. Centralized here so we don't
// duplicate it across studio-mm-projects.php, studio-survey-projects.php, etc.
$_route_maps = [
  'mm' => [
    'project_snapshot'=>'/project-snapshot.php','sample_sources'=>'/sample-profile.php','data_quality'=>'/data-quality.php','overall_findings'=>'/overall-findings.php','data_readiness'=>'/data-readiness.php','recommended_analyses'=>'/recommended-analyses.php','purpose'=>'/purpose.php','missing_data'=>'/missing-data.php',
    'strength_index'=>'/strength-index.php','reliability'=>'/reliability.php','inter_rater_agreement'=>'/inter-rater-agreement.php','coding_agreement'=>'/inter-rater-agreement.php','trustworthiness'=>'/trustworthiness.php','construct_alignment'=>'/construct-alignment.php','validity'=>'/validity.php','item_quality'=>'/item-quality.php','scale_structure'=>'/scale-structure.php',
    'factor_readiness'=>'/factor-readiness.php','bias_review'=>'/bias-clarity.php','response_scale_review'=>'/response-scale.php',
    'frequencies'=>'/frequencies.php','means_distributions'=>'/distributions.php','cross_tabs'=>'/cross-tabs.php',
    'group_summaries'=>'/group-summaries.php','item_theme_summaries'=>'/open-ended-summary.php',
    'top_bottom_items'=>'/top-bottom-items.php','scale_scores'=>'/scale-scores.php',
    'themes_codes'=>'/theme-analysis.php','exemplar_quotes'=>'/quote-extractor.php','codebook_builder'=>'/codebook-builder.php',
    'theme_by_group'=>'/theme-by-group.php','qual_to_quant'=>'/qual-to-quant.php','theme_cooccurrence'=>'/theme-cooccurrence.php',
    't_test'=>'/t-test.php','anova'=>'/anova.php','chi_square'=>'/chi-square.php','correlation'=>'/correlation.php',
    'effect_sizes'=>'/effect-size.php','paired_t_test'=>'/paired-t-test.php','welch_anova'=>'/welch-anova.php',
    'post_hoc'=>'/post-hoc.php','regression'=>'/regression.php','confidence_interval'=>'/confidence-interval.php',
    'assumption_checks'=>'/assumption-checks.php','joint_displays'=>'/joint-display.php',
    'convergence_divergence'=>'/integration-quality.php','pattern_matching'=>'/pattern-matching.php',
    'key_findings'=>'/key-findings.php','evidence_notes'=>'/evidence-alignment.php',
    'practical_meaning'=>'/practical-significance.php','limitations'=>'/limitations.php',
    'recommended_actions'=>'/recommended-actions.php','teaching_moments'=>'/teaching-moments.php',
    'ai_interpretation'=>'/ai-interpretation.php','decision_readiness'=>'/decision-readiness.php','meta_inferences'=>'/meta-inferences.php',
    'report_builder'=>'/report-builder.php','executive_summary'=>'/executive-summary.php',
    'methodology'=>'/methodology.php','findings'=>'/findings.php','tables_figures'=>'/tables-figures.php',
    'recommendations'=>'/recommendations.php','appendix'=>'/appendix.php','exports'=>'/export.php',
  ],
  'survey' => [
    'themes_codes'=>'/theme-analysis.php','exemplar_quotes'=>'/quote-extractor.php',
    'project_snapshot'=>'/project-snapshot.php','sample_sources'=>'/sample-profile.php','data_quality'=>'/data-quality.php','overall_findings'=>'/overall-findings.php','data_readiness'=>'/data-readiness.php','recommended_analyses'=>'/recommended-analyses.php','purpose'=>'/purpose.php','missing_data'=>'/missing-data.php',
    'strength_index'=>'/strength-index.php','reliability'=>'/reliability.php','inter_rater_agreement'=>'/inter-rater-agreement.php','coding_agreement'=>'/inter-rater-agreement.php','trustworthiness'=>'/trustworthiness.php','construct_alignment'=>'/construct-alignment.php','validity'=>'/validity.php','item_quality'=>'/item-quality.php',
    'dignity_framing'=>'/dignity-framing.php','access'=>'/access.php',
    'construct_definition'=>'/construct-definition.php','purpose_alignment'=>'/purpose-alignment.php','dimension_coverage'=>'/dimension-coverage.php','item_construct_alignment'=>'/item-construct-alignment.php','response_option_validity'=>'/response-option-validity.php',
    'scale_structure_readiness'=>'/scale-structure-readiness.php','item_clarity'=>'/item-clarity-readiness.php','response_scale_consistency'=>'/response-scale-consistency.php','redundancy_balance'=>'/redundancy-balance.php','administration_consistency'=>'/administration-consistency-reliability.php',
    'respondent_instructions'=>'/respondent-instructions-readiness.php','consent_privacy'=>'/consent-privacy-readiness.php','fielding_plan'=>'/fielding-plan-readiness.php','sensitive_safety'=>'/sensitive-safety-readiness.php','completion_burden'=>'/completion-burden-readiness.php','administration_readiness'=>'/administration-readiness.php',
    'validity_readiness'=>'/validity-readiness.php','reliability_readiness'=>'/reliability-readiness.php','siri_dashboard'=>'/siri-readiness.php',
    'scale_structure'=>'/scale-structure.php','factor_readiness'=>'/factor-readiness.php','bias_review'=>'/bias-clarity.php',
    'response_scale_review'=>'/response-scale.php','frequencies'=>'/frequencies.php','means_distributions'=>'/distributions.php',
    'cross_tabs'=>'/cross-tabs.php','group_summaries'=>'/group-summaries.php','item_theme_summaries'=>'/open-ended-summary.php',
    'top_bottom_items'=>'/top-bottom-items.php','scale_scores'=>'/scale-scores.php',
    't_test'=>'/t-test.php','anova'=>'/anova.php','chi_square'=>'/chi-square.php','correlation'=>'/correlation.php',
    'effect_sizes'=>'/effect-size.php','paired_t_test'=>'/paired-t-test.php','welch_anova'=>'/welch-anova.php',
    'post_hoc'=>'/post-hoc.php','regression'=>'/regression.php','confidence_interval'=>'/confidence-interval.php',
    'assumption_checks'=>'/assumption-checks.php',
    'key_findings'=>'/key-findings.php','evidence_notes'=>'/evidence-alignment.php',
    'practical_meaning'=>'/practical-significance.php','limitations'=>'/limitations.php',
    'recommended_actions'=>'/recommended-actions.php','teaching_moments'=>'/teaching-moments.php',
    'ai_interpretation'=>'/ai-interpretation.php','decision_readiness'=>'/decision-readiness.php','meta_inferences'=>'/meta-inferences.php',
    'report_builder'=>'/report-builder.php','executive_summary'=>'/executive-summary.php',
    'methodology'=>'/methodology.php','findings'=>'/findings.php','tables_figures'=>'/tables-figures.php',
    'recommendations'=>'/recommendations.php','appendix'=>'/appendix.php','exports'=>'/export.php',
  ],
  'tia' => [
    'project_snapshot'=>'/project-snapshot.php','sample_sources'=>'/sample-profile.php','data_quality'=>'/data-quality.php','overall_findings'=>'/overall-findings.php','data_readiness'=>'/data-readiness.php','recommended_analyses'=>'/recommended-analyses.php','purpose'=>'/purpose.php','missing_data'=>'/missing-data.php',
    'strength_index'=>'/strength-index.php','reliability'=>'/reliability.php','inter_rater_agreement'=>'/inter-rater-agreement.php','coding_agreement'=>'/inter-rater-agreement.php','trustworthiness'=>'/trustworthiness.php','construct_alignment'=>'/construct-alignment.php','validity'=>'/validity.php','item_quality'=>'/item-quality.php','scale_structure'=>'/scale-structure.php',
    'response_scale_review'=>'/response-scale.php','answer_key_validation'=>'/answer-key.php',
    'frequencies'=>'/frequencies.php','means_distributions'=>'/distributions.php','cross_tabs'=>'/cross-tabs.php',
    'group_summaries'=>'/group-summaries.php','top_bottom_items'=>'/top-bottom-items.php','scale_scores'=>'/scale-scores.php',
    't_test'=>'/t-test.php','anova'=>'/anova.php','chi_square'=>'/chi-square.php','correlation'=>'/correlation.php',
    'effect_sizes'=>'/effect-size.php','paired_t_test'=>'/paired-t-test.php','welch_anova'=>'/welch-anova.php',
    'post_hoc'=>'/post-hoc.php','regression'=>'/regression.php','confidence_interval'=>'/confidence-interval.php',
    'assumption_checks'=>'/assumption-checks.php',
    'key_findings'=>'/key-findings.php','evidence_notes'=>'/evidence-alignment.php',
    'practical_meaning'=>'/practical-significance.php','limitations'=>'/limitations.php',
    'recommended_actions'=>'/recommended-actions.php','teaching_moments'=>'/teaching-moments.php',
    'ai_interpretation'=>'/ai-interpretation.php','decision_readiness'=>'/decision-readiness.php','meta_inferences'=>'/meta-inferences.php',
    'report_builder'=>'/report-builder.php','executive_summary'=>'/executive-summary.php',
    'methodology'=>'/methodology.php','findings'=>'/findings.php','tables_figures'=>'/tables-figures.php',
    'recommendations'=>'/recommendations.php','appendix'=>'/appendix.php','exports'=>'/export.php',
  ],
  '360' => [
    'project_snapshot'=>'/project-snapshot.php','sample_sources'=>'/sample-profile.php','data_quality'=>'/data-quality.php','overall_findings'=>'/overall-findings.php','data_readiness'=>'/data-readiness.php','recommended_analyses'=>'/recommended-analyses.php','purpose'=>'/purpose.php','missing_data'=>'/missing-data.php',
    'cohort_summary'=>'/cohort-summary.php','strength_index'=>'/strength-index.php','reliability'=>'/reliability.php','inter_rater_agreement'=>'/inter-rater-agreement.php','coding_agreement'=>'/inter-rater-agreement.php','trustworthiness'=>'/trustworthiness.php','construct_alignment'=>'/construct-alignment.php','validity'=>'/validity.php','item_quality'=>'/item-quality.php',
    'scale_structure'=>'/scale-structure.php','response_scale_review'=>'/response-scale.php',
    'confidentiality_threshold'=>'/confidentiality-threshold.php','frequencies'=>'/frequencies.php',
    'means_distributions'=>'/distributions.php','cross_tabs'=>'/cross-tabs.php','group_summaries'=>'/group-summaries.php',
    'rater_group_comparison'=>'/rater-group-comparison.php','comment_theme'=>'/comment-theme.php',
    't_test'=>'/t-test.php','anova'=>'/anova.php','chi_square'=>'/chi-square.php','correlation'=>'/correlation.php',
    'effect_sizes'=>'/effect-size.php','paired_t_test'=>'/paired-t-test.php','welch_anova'=>'/welch-anova.php',
    'post_hoc'=>'/post-hoc.php','regression'=>'/regression.php','confidence_interval'=>'/confidence-interval.php',
    'assumption_checks'=>'/assumption-checks.php',
    'key_findings'=>'/key-findings.php','evidence_notes'=>'/evidence-alignment.php',
    'practical_meaning'=>'/practical-significance.php','limitations'=>'/limitations.php',
    'recommended_actions'=>'/recommended-actions.php','teaching_moments'=>'/teaching-moments.php',
    'ai_interpretation'=>'/ai-interpretation.php','decision_readiness'=>'/decision-readiness.php','meta_inferences'=>'/meta-inferences.php',
    'development_plan'=>'/development-plan.php','report_builder'=>'/report-builder.php',
    'executive_summary'=>'/executive-summary.php','methodology'=>'/methodology.php','findings'=>'/findings.php',
    'tables_figures'=>'/tables-figures.php','recommendations'=>'/recommendations.php',
    'appendix'=>'/appendix.php','exports'=>'/export.php',
  ],
];
// 'strength-survey' is a parallel UI on the same data as 'survey' — share its route map.
if ($studio_slug === 'strength-survey') $_route_maps['strength-survey'] = $_route_maps['survey'];

// Descriptive Analysis Studio — the six descriptive lenses on the current dataset.
$_route_maps['descriptive'] = [
  'frequencies'=>'/frequencies.php','means_distributions'=>'/distributions.php','cross_tabs'=>'/cross-tabs.php',
  'group_summaries'=>'/group-summaries.php','item_theme_summaries'=>'/open-ended-summary.php',
  'top_bottom_items'=>'/top-bottom-items.php','scale_scores'=>'/scale-scores.php','missing_data'=>'/missing-data.php',
];
// Inferential Statistics Studio — the test/estimation lenses on the current dataset.
$_route_maps['inferential'] = [
  'recommended_analyses'=>'/recommended-analyses.php','t_test'=>'/t-test.php','anova'=>'/anova.php',
  'chi_square'=>'/chi-square.php','correlation'=>'/correlation.php','effect_sizes'=>'/effect-size.php',
  'paired_t_test'=>'/paired-t-test.php','welch_anova'=>'/welch-anova.php','post_hoc'=>'/post-hoc.php',
  'regression'=>'/regression.php','confidence_interval'=>'/confidence-interval.php','assumption_checks'=>'/assumption-checks.php',
];
$route_map = $_route_maps[$studio_slug] ?? [];
$qsuffix   = '?studio=' . $studio_slug . ($projectId ? '&project_id=' . $projectId : '');

$_catalog = require __DIR__ . '/_studio_items_catalog.php';
foreach ($_catalog as &$_section) {
  foreach ($_section['items'] as &$_item) {
    if (isset($route_map[$_item['key']])) {
      $_item['route'] = $route_map[$_item['key']] . $qsuffix;
    } else {
      // No mapped destination yet. Route to the friendly Coming Soon
      // stub instead of "#" so the user never hits a dead-end click.
      $_item['route'] = '/coming-soon.php' . $qsuffix . '&item=' . urlencode($_item['key']);
    }
  }
}
unset($_section, $_item);

// ---------- Studio template variables ----------
$current_studio  = $studio_slug;
$current_section = $mount_section ?? '';
$current_item    = $mount_item    ?? '';
$studio_project_label = $mount_project ? (string)$mount_project['title'] : 'No project selected';
$studio_context_label = $mount_project ? ('Project ID ' . (int)$projectId) : 'Pick a project to begin';

// ---------- Shell variables ----------
$shell_body_attrs    = 'data-current-studio="' . $studio_slug . '"'
                     . ($projectId ? ' data-project-id="' . $projectId . '"' : '')
                     . (!empty($mount_item)    ? ' data-current-item="'    . htmlspecialchars($mount_item)    . '"' : '')
                     . (!empty($mount_section) ? ' data-current-section="' . htmlspecialchars($mount_section) . '"' : '');
$shell_page_title    = ($mount_title ?? ucfirst($mount_item ?? 'Analysis')) . ' · ' . $_studio['name'];
$shell_user_full     = $mount_user['name'] ?? $mount_user['email'] ?? 'You';
$shell_user_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $shell_user_full) ?: 'U', 0, 2));
$shell_project_label = $mount_project ? (string)$mount_project['title'] : 'No project open';
$shell_project_id    = $projectId ? (string)$projectId : '';

// ---------- Embed mode detection ----------
// When ?embed=1 (or ?embed=rssi) is in the URL, render JUST the engine
// content with a minimal HTML shell — no platform shell, no studio
// template sidebar, no topbar. Used to embed analyses inside the RSSI
// app via iframe so users can access them without leaving RSSI.
$mount_embed = isset($_GET['embed']) && $_GET['embed'] !== '' && $_GET['embed'] !== '0';

// ---------- Render ----------
if (!$mount_embed):
  include __DIR__ . '/_platform_shell_header.php';
?>

<?php
  // studio-template.css is now loaded by _studio_template_header.php
  // with its own mtime cache-bust. App-specific CSS gets cache-busted
  // here so engine restyles land in browsers on the next page load.
  if (!empty($_app['css'])) {
    $_css_url  = $_app['css'];
    $_css_path = __DIR__ . $_app['css'];
    $_css_ver  = is_file($_css_path) ? filemtime($_css_path) : time();
    echo '<link rel="stylesheet" href="' . htmlspecialchars($_css_url . '?v=' . $_css_ver) . '">';
  }
?>

<?php include __DIR__ . '/_studio_template_header.php'; ?>

<?php else: // embed mode — minimal HTML shell ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($shell_page_title) ?></title>
  <link rel="stylesheet" href="/studio-template.css">
  <?php
    if (!empty($_app['css'])) {
      $_css_url  = $_app['css'];
      $_css_path = __DIR__ . $_app['css'];
      $_css_ver  = is_file($_css_path) ? filemtime($_css_path) : time();
      echo '<link rel="stylesheet" href="' . htmlspecialchars($_css_url . '?v=' . $_css_ver) . '">' . "\n";
    }
  ?>
  <style>
    /* Embed mode: kill platform/studio chrome and reset spacing so the
       analysis fills the iframe. */
    html, body { background: #fff; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", Inter, Helvetica, Arial, sans-serif; color: #15171a; }
    .platform-shell-header, .platform-shell-footer, .ps-topbar, .ps-userbar, .ps-preview-strip,
    .sidebar, .topbar, .work-breadcrumb, .mount-context, .rc-stub-actions, .rc-stub, #rcResultHost-stub-only,
    .ps-footer-note { display: none !important; }
    .app, .main, .page, .studio-work { display: block !important; padding: 0 !important; margin: 0 !important; min-height: auto !important; }
    .work-head { padding: 18px 24px 4px; margin: 0; }
    /* Provide a tight container for the engine output */
    .rssi-embed-wrap { padding: 20px 24px 40px; max-width: 1200px; margin: 0 auto; }
  </style>
</head>
<body data-embed="1">
<div class="rssi-embed-wrap">
<?php endif; ?>

<?php if (!empty($mount_breadcrumb)): ?>
  <div class="work-breadcrumb">
    <?php $last = count($mount_breadcrumb) - 1; foreach ($mount_breadcrumb as $i => $crumb): ?>
      <?php if ($i === $last): ?>
        <strong><?= htmlspecialchars($crumb) ?></strong>
      <?php else: ?>
        <span><?= htmlspecialchars($crumb) ?></span>
        <span class="sep">/</span>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($mount_title)): ?>
  <div class="work-head">
    <?php
      // Eyebrow: "SECTION · QUESTION", e.g. "OVERVIEW · WHAT DO WE HAVE?"
      // Look up the active section in the catalog to build the line.
      $_eyebrow = '';
      if (!empty($mount_section) && !empty($_catalog)) {
        foreach ($_catalog as $_sec) {
          if (($_sec['key'] ?? '') === $mount_section) {
            $_eyebrow = strtoupper((string)($_sec['label'] ?? ''))
                      . ($_sec['question'] ? ' · ' . strtoupper((string)$_sec['question']) : '');
            break;
          }
        }
      }
    ?>
    <?php if ($_eyebrow !== ''): ?>
      <div class="page-eyebrow"><?= htmlspecialchars($_eyebrow) ?></div>
    <?php endif; ?>
    <h2><?= htmlspecialchars($mount_title) ?></h2>
    <?php if (!empty($mount_intro)): ?>
      <p><?= htmlspecialchars($mount_intro) ?></p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
// If a project is loaded, surface a quiet strip pointing at the wizard
// (so the user can re-upload / change framing) and re-confirm the
// project context. Skip when no project is selected.
if ($mount_project):
?>
  <div class="mount-context" style="display:flex;align-items:center;gap:12px;padding:8px 14px;background:var(--bg-tint, #f5f7fb);border:1px solid var(--line);border-radius:999px;margin-bottom:18px;font-size:13px;color:var(--ink-4, #5a657a);">
    <span style="width:8px;height:8px;border-radius:50%;background:#1f7a3a;flex-shrink:0;"></span>
    <span style="color:var(--ink-2, #1c2238);font-weight:600;"><?= htmlspecialchars((string)$mount_project['title']) ?></span>
    <span class="sep" style="color:var(--ink-5);">·</span>
    <span id="mount-dataset-summary">Dataset loading…</span>
    <a href="<?= htmlspecialchars('/' . $studio_slug . '-wizard.php?step=2&project_id=' . (int)$projectId) ?>" style="margin-left:auto;color:var(--ink-3);font-weight:600;text-decoration:none;font-size:12.5px;">Replace data →</a>
  </div>
<?php endif; ?>

<?php
// Preview stub — friendly orientation before the engine renders.
// DEFAULT: every analysis page gets the Run / Configure / Learn intro card,
// using $mount_title and $mount_intro for the copy. Pages can override with
//   $mount_stub = ['title' => '...', 'body' => '<html>'];
// To opt out entirely (rare), set $mount_stub = false.
$mount_stub_resolved = null;
if (isset($mount_stub) && $mount_stub === false) {
    $mount_stub_resolved = false; // explicit opt-out
} else if ($mount_embed) {
    // In embed mode, never show the Run / Configure / Learn stub. The
    // engine should render directly so RSSI's iframe shows the analysis
    // immediately (no "Run" button to click — the stub wrapper would
    // otherwise leave the engine output hidden inside #rcResultHost).
    $mount_stub_resolved = false;
} else {
    $_stub_title = (is_array($mount_stub ?? null) && !empty($mount_stub['title']))
        ? $mount_stub['title']
        : ($mount_title ?? 'Analysis');
    $_stub_body  = (is_array($mount_stub ?? null) && isset($mount_stub['body']))
        ? $mount_stub['body']
        : ($mount_intro ?? '');
    $mount_stub_resolved = ['title' => $_stub_title, 'body' => $_stub_body];
}
if ($mount_stub_resolved !== false):
?>
  <div class="rc-stub" data-rc-stub>
    <h3 class="rc-stub-title"><?= htmlspecialchars((string)$mount_stub_resolved['title']) ?></h3>
    <p class="rc-stub-body"><?= $mount_stub_resolved['body'] /* HTML allowed — strong, a, etc. */ ?></p>
    <div class="rc-stub-actions">
      <button class="rc-button-primary"   type="button" data-rc-action="run">&#9655; Run</button>
      <button class="rc-button-secondary" type="button" data-rc-action="configure">Configure</button>
      <button class="rc-button-secondary" type="button" data-rc-action="learn">Learn</button>
    </div>
  </div>
  <div id="rcResultHost" hidden>
<?php endif; ?>

<!-- Template-level two-card workspace (per [[relicheck-template-only-layout]]).
     Page sets $mount_workspace = ['main' => '<html>', 'side' => '<html>']
     and the partial renders .rc-workspace-2col HERE, suppressing the
     engine's default render below. Pages that need a workspace layout
     should use this and NOT inline <style> / <script> blocks. -->
<?php if (!empty($mount_workspace) && is_array($mount_workspace)): ?>
  <div class="rc-workspace-2col">
    <div class="rc-workspace-main"><?= $mount_workspace['main'] ?? '' ?></div>
    <div class="rc-workspace-side"><?= $mount_workspace['side'] ?? '' ?></div>
  </div>
<?php elseif (!empty($mount_workspace_empty)): ?>
  <div class="rc-workspace-empty"><?= $mount_workspace_empty ?></div>
<?php else: ?>
  <!-- App-specific render -->
  <?php include $_app['render']; ?>
<?php endif; ?>

<?php if ($mount_stub_resolved !== false): ?>
  </div><!-- /#rcResultHost -->
<?php endif; ?>

<?php
// ─────────────────────────────────────────────────────────────────────────
// Universal "Save to Report" bar.
// Shows after the engine has rendered (i.e., after Run was clicked).
// Captures window.RELICHECK_APP_STATE → appends to the saved-blocks corpus
// at localStorage['relicheck.report.<project_id>.default']. Every analysis
// engine that publishes APP_STATE gets save-to-report for free.
// To opt out on a specific page: set $mount_no_save_to_report = true.
if (empty($mount_no_save_to_report) && $mount_stub_resolved !== false && !empty($projectId)):
?>
<div id="rcSaveBar" class="rc-save-bar" hidden>
  <div class="rc-save-bar-inner">
    <span class="rc-save-bar-meta" id="rcSaveBarMeta">Add this analysis to your report.</span>
    <div class="rc-save-bar-actions">
      <a class="rc-save-bar-link" id="rcSaveBarOpen" href="/report-builder.php?studio=<?= htmlspecialchars($studio_slug) ?>&project_id=<?= (int)$projectId ?>">Open Report Builder →</a>
      <button class="rc-save-bar-btn" id="rcSaveBarBtn" type="button">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
        Save to Report
      </button>
    </div>
  </div>
</div>

<style>
.rc-save-bar {
  margin: 24px 0 0;
  border: 1px solid rgba(15,23,42,0.08);
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 2px 8px rgba(15,23,42,0.06);
  animation: rcSaveBarIn 0.22s ease;
}
@keyframes rcSaveBarIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
.rc-save-bar-inner {
  display: flex; align-items: center; gap: 16px;
  padding: 14px 18px;
}
.rc-save-bar-meta { flex: 1; font-size: 13.5px; color: #5f6368; font-weight: 500; min-width: 0; }
.rc-save-bar-actions { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.rc-save-bar-link { font-size: 13px; color: #5f6368; font-weight: 600; text-decoration: none; }
.rc-save-bar-link:hover { color: #e85d3a; text-decoration: underline; }
.rc-save-bar-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 16px; border-radius: 10px; border: none;
  background: #e85d3a; color: #fff;
  font-family: inherit; font-size: 13.5px; font-weight: 700; cursor: pointer;
  transition: background 0.14s, opacity 0.14s, transform 0.14s;
}
.rc-save-bar-btn:hover:not(:disabled) { background: #d34e2d; transform: translateY(-1px); }
.rc-save-bar-btn:disabled { opacity: 0.55; cursor: default; }
.rc-save-bar.saved .rc-save-bar-btn { background: #0e8a6f; }
@media (max-width: 600px) {
  .rc-save-bar-inner { flex-direction: column; align-items: stretch; }
  .rc-save-bar-actions { justify-content: space-between; }
}
</style>

<script>
(function () {
  const projectId = <?= json_encode((int)$projectId) ?>;
  const studio    = <?= json_encode($studio_slug) ?>;
  const appKey    = <?= json_encode($mount_app_key) ?>;
  const appName   = <?= json_encode($_app['name'] ?? 'Analysis') ?>;
  const lensKey   = <?= json_encode($mount_lens ?? '') ?>;
  const pageTitle = <?= json_encode($mount_title ?? '') ?>;
  const projectName = <?= json_encode($mount_project['title'] ?? 'Untitled project') ?>;
  const storageKey = 'relicheck.report.' + projectId + '.default';

  const bar  = document.getElementById('rcSaveBar');
  const btn  = document.getElementById('rcSaveBarBtn');
  const meta = document.getElementById('rcSaveBarMeta');
  if (!bar || !btn) return;

  function readReport() {
    try {
      const raw = window.localStorage.getItem(storageKey);
      if (raw) {
        const parsed = JSON.parse(raw);
        if (parsed && Array.isArray(parsed.blocks)) return parsed;
      }
    } catch (e) {}
    return { project: projectName, projectId: projectId, reportId: 'default', studio: studio, blocks: [] };
  }
  function writeReport(r) {
    try { window.localStorage.setItem(storageKey, JSON.stringify(r)); } catch (e) {}
  }
  function blockIdFor() {
    return appKey + ':' + (lensKey || 'default') + ':' + projectId;
  }
  function refreshMeta() {
    const r = readReport();
    const existing = r.blocks.find(function (b) { return b.id === blockIdFor(); });
    if (existing) {
      bar.classList.add('saved');
      btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Saved · Update';
      const when = new Date(existing.addedAt).toLocaleString();
      meta.textContent = 'Saved to report on ' + when + '. Click to update with the latest run.';
    } else {
      bar.classList.remove('saved');
      btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg> Save to Report';
      meta.textContent = 'Add this analysis to your report.';
    }
  }
  function showBar() {
    bar.hidden = false;
    refreshMeta();
  }

  // Reveal the bar when the engine has actually rendered something. We poll
  // briefly for window.RELICHECK_APP_STATE — engines set it as the last step
  // of their render.
  function pollForAppState() {
    let attempts = 0;
    const tick = setInterval(function () {
      attempts++;
      const s = window.RELICHECK_APP_STATE;
      if (s && typeof s === 'object') { clearInterval(tick); showBar(); return; }
      if (attempts > 30) { clearInterval(tick); } // ~6 s, give up silently
    }, 200);
  }

  // Engines render after Run is clicked (stub flow) OR immediately (auto-run).
  // Listen for the Run action AND also start polling on load in case auto-run.
  document.addEventListener('rc:stub-action', function (e) {
    if (e.detail && e.detail.action === 'run') {
      setTimeout(pollForAppState, 50);
    }
  });
  pollForAppState();

  // Save handler
  btn.addEventListener('click', function () {
    const state = window.RELICHECK_APP_STATE;
    if (!state) { meta.textContent = 'Nothing to save yet — run the analysis first.'; return; }
    const r = readReport();
    const id = blockIdFor();
    const summary = state.summary || state.headline || state.title || pageTitle || appName;
    const block = {
      id: id,
      addedAt: Date.now(),
      studio: studio,
      project: projectName,
      app: appKey,
      appName: appName,
      lens: lensKey,
      pageTitle: pageTitle,
      summary: summary,
      payload: state,
    };
    // Upsert: replace any existing block with the same id, otherwise append
    const idx = r.blocks.findIndex(function (b) { return b.id === id; });
    if (idx >= 0) r.blocks[idx] = block; else r.blocks.push(block);
    r.studio = studio; r.project = projectName; r.projectId = projectId;
    writeReport(r);
    refreshMeta();
  });
})();
</script>

<?php endif; ?>

<?php if (!$mount_embed): ?>
<?php include __DIR__ . '/_studio_template_footer.php'; ?>
<?php else: ?>
</div><!-- /.rssi-embed-wrap -->
<?php endif; ?>

<?php
// ── Survey studio: server-side response → dataset injection ──────────────
// For survey projects, we build the analysis dataset directly from the
// responses table (no CSV upload required) and inject it inline so all
// analysis engines have real data on the first page load without any async
// fetch. The same transform is available via /api/surveys/responses-dataset.php
// for client-side refresh.
if ($studio_slug === 'survey' && $projectId > 0 && !empty($mount_dataset_global)):
    // Load the shared transform function if not already loaded
    if (!function_exists('relicheck_survey_build_dataset')) {
        require_once __DIR__ . '/api/surveys/_build_dataset.php';
    }
    // Fetch questions
    try {
        $__sq = $pdo->prepare('SELECT title, questions, settings FROM surveys WHERE id = :id');
        $__sq->execute([':id' => $projectId]);
        $__sr = $__sq->fetch(PDO::FETCH_ASSOC);
        $__questions = $__sr ? (json_decode((string)$__sr['questions'], true) ?: []) : [];
        $__title     = $__sr ? (string)$__sr['title'] : '';
        $__settings  = $__sr ? (json_decode((string)($__sr['settings'] ?? ''), true) ?: []) : [];

        // Detect arm_id column
        $__hasArm = false;
        try { $__c = $pdo->query("SHOW COLUMNS FROM responses LIKE 'arm_id'"); if ($__c && $__c->fetch()) $__hasArm = true; } catch (Throwable $__e) {}
        $__sql = $__hasArm
            ? 'SELECT id, submitted_at, answers, arm_id FROM responses WHERE survey_id = :sid ORDER BY submitted_at ASC'
            : 'SELECT id, submitted_at, answers FROM responses WHERE survey_id = :sid ORDER BY submitted_at ASC';
        $__rs = $pdo->prepare($__sql);
        $__rs->execute([':sid' => $projectId]);
        $__responses = [];
        while ($__row = $__rs->fetch(PDO::FETCH_ASSOC)) {
            $__ans = json_decode((string)$__row['answers'], true);
            $__responses[] = ['id' => (int)$__row['id'], 'submitted_at' => $__row['submitted_at'], 'answers' => is_array($__ans) ? $__ans : []];
        }
        $__dataset = relicheck_survey_build_dataset($__title, $__questions, $__responses, $__settings);
    } catch (Throwable $__ex) {
        $__dataset = ['source' => 'Error loading responses', 'variables' => [], 'rowCount' => 0];
        error_log('[relicheck] survey dataset build failed: ' . $__ex->getMessage());
    }
    $__savedAt = time();
    echo '<script>window.RELICHECK_SURVEY_INLINE='.json_encode([
        'savedAt' => $__savedAt,
        'studio'  => 'survey',
        'payload' => ['dataset' => $__dataset],
    ], JSON_UNESCAPED_UNICODE).';</script>' . "\n";
endif;
?>

<!-- Project-scoped dataset loader (client-side). Reads localStorage written -->
<!-- by Evidence Intake at key relicheck.dataset.<project_id> and exposes -->
<!-- it via the engine-specific window globals declared by the mount page. -->
<script>
  (function () {
    const PROJECT_ID = <?= json_encode($projectId) ?>;
    const DATASET_GLOBAL = <?= json_encode($mount_dataset_global ?? '') ?>;
    const LENS_GLOBAL    = <?= json_encode($mount_lens_global    ?? '') ?>;
    const LENS_VALUE     = <?= json_encode($mount_lens ?? '') ?>;
    const STUDIO         = <?= json_encode($studio_slug) ?>;

    if (LENS_GLOBAL) window[LENS_GLOBAL] = LENS_VALUE;
    window.RELICHECK_STUDIO     = STUDIO;
    window.RELICHECK_PROJECT_ID = PROJECT_ID;

    // Load the user's dataset from localStorage. Strategy:
    //   1. Try the exact key relicheck.dataset.<PROJECT_ID>.
    //   2. If empty, scan ALL relicheck.dataset.* keys. If exactly one
    //      exists, use it (common: data was saved under 'untitled-project'
    //      because the user uploaded before the wizard created the project,
    //      or via the standalone /evidence-intake.php). If more than one
    //      exists, use the most recently saved.
    //   3. If still nothing, fall back to an empty dataset and tell the
    //      user where to upload.
    // The fallback writes the dataset to the EXPECTED key so the next page
    // load uses it directly without scanning again.
    let dataset = null;
    let datasetSourceKey = null;
    let datasetMigrated = false;
    function readDatasetWrap(raw) {
      try {
        const w = JSON.parse(raw);
        return (w && w.payload && w.payload.dataset) ? w : null;
      } catch (e) { return null; }
    }

    // ── Survey studio: use server-injected inline dataset ──────────────────
    // window.RELICHECK_SURVEY_INLINE is set by a PHP block above this script
    // (only for studio=survey). It contains fresh response data from the DB.
    // ONLY use (and persist to localStorage) when the survey actually has
    // responses. Otherwise we'd clobber whatever the user uploaded via
    // Evidence Intake. Critical: a 0-row inline dataset must never overwrite
    // a user-uploaded CSV sitting in localStorage.
    if (STUDIO === 'survey' && window.RELICHECK_SURVEY_INLINE && PROJECT_ID) {
      const inline = window.RELICHECK_SURVEY_INLINE;
      const inlineRows = (inline && inline.payload && inline.payload.dataset)
        ? (inline.payload.dataset.rowCount || 0) : 0;
      if (inlineRows > 0) {
        dataset = inline.payload.dataset;
        datasetSourceKey = 'inline:survey:' + PROJECT_ID;
        try {
          window.localStorage.setItem(
            'relicheck.dataset.' + PROJECT_ID,
            JSON.stringify(inline)
          );
        } catch (e) { /* storage full or private mode */ }
      }
      // If inlineRows === 0, fall through to the localStorage scan below
      // so any CSV the user uploaded via Evidence Intake is respected.
    }

    // ── Other studios (or survey fallback): read from localStorage ─────────
    if (!dataset && PROJECT_ID) {
      const exactKey = 'relicheck.dataset.' + PROJECT_ID;
      const rawExact = window.localStorage.getItem(exactKey);
      if (rawExact) {
        const wrap = readDatasetWrap(rawExact);
        if (wrap) { dataset = wrap.payload.dataset; datasetSourceKey = exactKey; }
      }
    }
    if (!dataset) {
      // Scan all relicheck.dataset.* keys, pick the most recently saved.
      const candidates = [];
      for (let i = 0; i < window.localStorage.length; i++) {
        const k = window.localStorage.key(i);
        if (!k || k.indexOf('relicheck.dataset.') !== 0) continue;
        const raw = window.localStorage.getItem(k);
        const wrap = readDatasetWrap(raw);
        if (wrap) candidates.push({ key: k, savedAt: wrap.savedAt || 0, ds: wrap.payload.dataset, studio: wrap.studio });
      }
      candidates.sort((a, b) => b.savedAt - a.savedAt);
      if (candidates.length) {
        dataset = candidates[0].ds;
        datasetSourceKey = candidates[0].key;
        if (PROJECT_ID) {
          try {
            window.localStorage.setItem(
              'relicheck.dataset.' + PROJECT_ID,
              window.localStorage.getItem(candidates[0].key)
            );
            datasetMigrated = true;
          } catch (e) {}
        }
        console.info('[mount] Dataset found under "' + candidates[0].key + '" (expected "relicheck.dataset.' + PROJECT_ID + '")' + (datasetMigrated ? ' — migrated to expected key.' : ''));
      }
    }
    if (!dataset) {
      dataset = { source: 'No data yet', variables: [], rowCount: 0 };
    }
    if (DATASET_GLOBAL) window[DATASET_GLOBAL] = dataset;

    // Mount-page summary chip
    const sum = document.getElementById('mount-dataset-summary');
    if (sum) {
      if (dataset.rowCount > 0) {
        const migratedNote = datasetMigrated
          ? ' <span style="color:var(--ink-5);font-size:11.5px;">(recovered from previous upload)</span>'
          : '';
        const varCount = dataset.variables ? dataset.variables.length : 0;
        if (STUDIO === 'survey') {
          sum.innerHTML = dataset.rowCount + ' response' + (dataset.rowCount !== 1 ? 's' : '') + ' · ' + varCount + ' variables';
        } else {
          sum.innerHTML = dataset.rowCount + ' rows · ' + varCount + ' variables' + migratedNote;
        }
      } else {
        if (STUDIO === 'survey') {
          sum.innerHTML = 'No responses yet. <a href="/studio-survey-projects.php" style="color:var(--landing-accent, #e85d3a);font-weight:600;text-decoration:none;">Share your survey to collect responses →</a>';
        } else {
          sum.innerHTML = 'No data yet. <a href="/' + STUDIO + '-wizard.php?step=2&project_id=' + encodeURIComponent(PROJECT_ID) + '" style="color:var(--landing-accent, #6d4ad8);font-weight:600;text-decoration:none;">Upload now →</a>';
        }
      }
    }
  })();
</script>

<?php
  // Cache-bust the engine JS by appending the file's mtime as ?v=. Without
  // this, Apache's default heuristic caching means browsers can hold onto
  // old JS for hours after a deploy. The mtime version changes the URL on
  // every save, forcing browsers to refetch.
  //
  // Only emit the engine JS when the engine's DOM is actually on the page.
  // Pages that opt into the template-level workspace layout
  // ($mount_workspace / $mount_workspace_empty) suppress the engine's
  // render.php include above (see lines 366-376) — loading the engine JS
  // in that case crashes on the engine's first getElementById call against
  // a DOM that was never emitted.
  $_engine_dom_rendered = empty($mount_workspace) && empty($mount_workspace_empty);
  if (!empty($_app['js']) && $_engine_dom_rendered) {
    $_js_url = $_app['js'];
    $_js_path = __DIR__ . $_app['js'];
    $_js_ver = is_file($_js_path) ? filemtime($_js_path) : time();
    echo '<script src="' . htmlspecialchars($_js_url . '?v=' . $_js_ver) . '" defer></script>';
  }
?>

<?php if ($mount_embed): ?>
</body></html>
<?php else: ?>
<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
<?php endif; ?>
