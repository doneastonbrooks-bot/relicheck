<?php
// MM Studio · project picker (Step 3).
// -------------------------------------------------------------------
// This page is the second leg of the MM Studio entry: the user clicks
// "Pick a project" on the Step-2 hero (studio-mm.php) and lands here.
// It mounts the Studio Template (left rail dimmed because no project
// is loaded yet) and lists the user's MM projects in the work area.
// On click, hands off to /project-snapshot.php?studio=mm&project_id=N.
//
// Why a separate URL from studio-mm.php:
//   - studio-mm.php is the Platform-Shell-only studio landing (hero +
//     tiles per [[relicheck-studio-landing-pattern]]).
//   - studio-mm-projects.php is the Studio-Template-loaded picker
//     surface. Different chrome, different job.
//
// Auth: required.
// Project ownership: when project_id is in the URL, verify against
// mm_projects then redirect to /project-snapshot.php.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) {
  $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
  header('Location: /login.html?return=' . urlencode('/studio-mm-projects.php' . $qs));
  exit;
}
$user = current_user();
if (!$user) {
  $_SESSION = []; session_destroy();
  header('Location: /login.html');
  exit;
}

// ---------- Read URL params ----------
$projectId = isset($_GET['project_id']) ? max(0, (int)$_GET['project_id']) : 0;

// If project_id set, verify ownership, then hand off to Overview.
if ($projectId > 0) {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id FROM mm_projects WHERE id = :id AND user_id = :uid AND status <> "archived"');
  $stmt->execute([':id' => $projectId, ':uid' => $uid]);
  if ($stmt->fetch(PDO::FETCH_ASSOC)) {
    header('Location: /project-snapshot.php?studio=mm&project_id=' . $projectId);
    exit;
  }
  // bad project — fall through to picker
}

// ---------- Studio identity ----------
$current_studio  = 'mm';
$current_section = '';
$current_item    = '';

// Override the Studio Template's identity-strip labels so they don't
// show the registry's sample project text when no project is loaded.
$studio_project_label = 'No project selected';
$studio_context_label = 'Pick one below to begin';

// ---------- MM rail: attach `route` per item ----------
$mm_route_map = [
  // Overview
  'project_snapshot'       => '/project-snapshot.php',
  'sample_sources'         => '/sample-profile.php',
  'data_quality'           => '/data-quality.php',
  // Instrument Quality
  'validity'               => '/validity.php',
  'item_quality'           => '/item-quality.php',
  'scale_structure'        => '/scale-structure.php',
  'factor_readiness'       => '/factor-readiness.php',
  'bias_review'            => '/bias-clarity.php',
  'response_scale_review'  => '/response-scale.php',
  // Descriptive
  'frequencies'            => '/frequencies.php',
  'means_distributions'    => '/distributions.php',
  'cross_tabs'             => '/cross-tabs.php',
  'group_summaries'        => '/group-summaries.php',
  'item_theme_summaries'   => '/open-ended-summary.php',
  'top_bottom_items'       => '/top-bottom-items.php',
  'scale_scores'           => '/scale-scores.php',
  // MM qualitative
  'themes_codes'           => '/theme-analysis.php',
  'exemplar_quotes'        => '/quote-extractor.php',
  'codebook_builder'       => '/codebook-builder.php',
  'theme_by_group'         => '/theme-by-group.php',
  'qual_to_quant'          => '/qual-to-quant.php',
  // Inferential
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
  'joint_displays'         => '/joint-display.php',
  'convergence_divergence' => '/integration-quality.php',
  // Interpretation
  'key_findings'           => '/key-findings.php',
  'evidence_notes'         => '/evidence-alignment.php',
  'practical_meaning'      => '/practical-significance.php',
  'limitations'            => '/limitations.php',
  'recommended_actions'    => '/recommended-actions.php',
  'teaching_moments'       => '/teaching-moments.php',
  'ai_interpretation'      => '/ai-interpretation.php',
  'decision_readiness'     => '/decision-readiness.php',
  // Reporting
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
$qsuffix  = '?studio=mm';
foreach ($_catalog as &$_section) {
  foreach ($_section['items'] as &$_item) {
    if (isset($mm_route_map[$_item['key']])) {
      $_item['route'] = $mm_route_map[$_item['key']] . $qsuffix;
    }
  }
}
unset($_section, $_item);

// ---------- Studio shell variables ----------
$shell_body_attrs = 'data-current-studio="mm" data-no-project="1"';

$shell_page_title    = 'Pick an MM project · ReliCheck';
$shell_user_full     = $user['name'] ?? $user['email'] ?? 'You';
$shell_user_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $shell_user_full) ?: 'U', 0, 2));
$shell_project_label = 'No project open';

include __DIR__ . '/_platform_shell_header.php';
?>

<link rel="stylesheet" href="/studio-template.css">
<style>
  body[data-no-project="1"] .studio-tools { opacity: 0.45; pointer-events: none; }
  body[data-no-project="1"] .studio-tools a { cursor: not-allowed; }

  .mm-picker {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 14px;
    margin-top: 6px;
  }
  .mm-picker-empty,
  .mm-picker-loading,
  .mm-picker-error {
    grid-column: 1 / -1;
    padding: 24px;
    background: #fff;
    border: 1px dashed var(--line);
    border-radius: 14px;
    color: var(--ink-4);
    text-align: center;
    font-size: 14px;
  }
  .mm-picker-error { color: #c2492f; border-color: #f3d8c0; background: #fff5f3; }
  .mm-picker-card {
    display: flex; flex-direction: column; gap: 6px;
    padding: 18px 20px; background: #fff;
    border: 1px solid var(--line); border-radius: 14px;
    text-decoration: none; color: inherit;
    transition: border-color 0.15s var(--ease), transform 0.15s var(--ease), box-shadow 0.15s var(--ease);
  }
  .mm-picker-card:hover {
    border-color: var(--accent); transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(20, 28, 48, 0.06);
  }
  .mm-pc-title {
    font-family: 'Fraunces', 'Georgia', serif;
    font-size: 18px; font-weight: 600; line-height: 1.3;
    color: var(--ink-1, #1c2238);
  }
  .mm-pc-meta {
    font-size: 12.5px; color: var(--ink-5);
    font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace;
  }
  .mm-pc-pathway {
    font-size: 11.5px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.04em;
    color: var(--ink-5);
  }
  .mm-pc-new {
    border-style: dashed; color: var(--ink-3);
    align-items: center; justify-content: center; text-align: center;
    min-height: 110px;
  }
  .mm-pc-new .mm-pc-title { color: var(--accent); }
</style>

<?php include __DIR__ . '/_studio_template_header.php'; ?>

<div class="work-breadcrumb">
  <a href="/studio-mm.php" style="text-decoration:none;color:var(--ink-4);">MM Studio</a>
  <span class="sep">/</span>
  <strong>Pick a project</strong>
</div>

<div class="work-head">
  <h2>Pick a project to open</h2>
  <p>Mixed-Methods Studio works on one project at a time. Pick an existing project below or start a new one. Until you pick, the rail on the left is dimmed because every analysis needs a project's data to run.</p>
</div>
<div class="mm-picker" id="mmPicker">
  <p class="mm-picker-loading">Loading your projects…</p>
</div>

<?php include __DIR__ . '/_studio_template_footer.php'; ?>

<script>
  (function () {
    const host = document.getElementById('mmPicker');
    if (!host) return;

    fetch('/api/mm/projects.php', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) throw new Error(data && data.message ? data.message : 'Bad response');
        const projects = Array.isArray(data.projects) ? data.projects : [];
        const newCard =
          '<a class="mm-picker-card mm-pc-new" href="/mm-wizard.php?step=1">' +
            '<div class="mm-pc-title">+ Upload data / new project</div>' +
            '<div class="mm-pc-meta">Opens the MM Evidence Intake wizard.</div>' +
          '</a>';
        if (!projects.length) {
          host.innerHTML = newCard +
            '<div class="mm-picker-empty">No MM projects yet. Click the tile above to start one.</div>';
          return;
        }
        host.innerHTML = projects.map(function (p) {
          return '<a class="mm-picker-card" href="/studio-mm-projects.php?project_id=' + encodeURIComponent(p.id) + '">' +
            '<div class="mm-pc-pathway">' + esc(p.pathway || 'mm') + '</div>' +
            '<div class="mm-pc-title">' + esc(p.title || 'Untitled project') + '</div>' +
            '<div class="mm-pc-meta">Updated ' + esc((p.updated_at || '').slice(0,10) || '—') + '</div>' +
          '</a>';
        }).join('') + newCard;
      })
      .catch(function (err) {
        host.innerHTML = '<p class="mm-picker-error">Could not load your MM projects: ' + esc(String(err.message || err)) + '</p>';
      });

    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
      });
    }
  })();
</script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
