<?php
// Evidence Intake Wizard route.
// -------------------------------------------------------------------
// One PHP page serves all four studio wizards. Studio is selected via
// the ?studio= query (survey | mm | tia | 360). Each studio's config
// lives at /apps/evidence-intake/configs/<slug>.config.php.

$valid_studios = ['survey', 'mm', 'tia', '360'];
$studio_slug   = $_GET['studio'] ?? 'survey';
if (!in_array($studio_slug, $valid_studios, true)) {
  $studio_slug = 'survey';
}

$current_studio  = $studio_slug;
$current_section = 'overview';
$current_item    = 'sample_sources';

$shell_body_attrs = 'data-current-studio="' . $current_studio . '"';

$_studios = require __DIR__ . '/_studio_registry.php';
$_apps    = require __DIR__ . '/_app_registry.php';
$_studio  = $_studios[$current_studio];
$_app     = $_apps['evidence_intake'];

$intake_config = require __DIR__ . '/apps/evidence-intake/configs/' . $studio_slug . '.config.php';

$shell_page_title    = $intake_config['wizard_name'] . ', ' . $_studio['name'];
$shell_user_initials = 'DE';
$shell_user_full     = 'Don Easton-Brooks';
$shell_project_label = $_studio['sample']['project'];

$teaching_cards = $intake_config['teaching_cards'] ?? [];

include __DIR__ . '/_platform_shell_header.php';
?>

<link rel="stylesheet" href="/studio-template.css">
<link rel="stylesheet" href="<?= htmlspecialchars($_app['css']) ?>">

<?php include __DIR__ . '/_studio_template_header.php'; ?>

<!-- ===== Center work area: Evidence Intake Wizard ===== -->

<div class="work-breadcrumb">
  <span>Overview</span>
  <span class="sep">/</span>
  <strong>Sample &amp; Data Sources</strong>
</div>

<div class="work-head">
  <h2><?= htmlspecialchars($intake_config['wizard_name']) ?></h2>
  <p><?= htmlspecialchars($intake_config['description']) ?></p>
</div>

<?php include $_app['render']; ?>

<?php include __DIR__ . '/_studio_template_footer.php'; ?>

<!-- Pass the studio's config to the engine before loading the engine JS -->
<script>
  window.INTAKE_CONFIG = <?= json_encode($intake_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= htmlspecialchars($_app['js']) ?>" defer></script>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
