<?php
// Studio Template HEADER for ReliCheck (v5 — iPadOS-inspired chrome).
// -------------------------------------------------------------------
// Opens the two-column app shell (sidebar + main) and the topbar.
// The mount partial / page slots its content into .studio-work (kept
// for backwards compatibility — same selector closes in the footer).
//
// Per [[relicheck-studio-shell]] the sidebar still follows the
// six-section methodology arc; the right rail moves to a slide-over
// triggered from the topbar's Ask ReliCheck button.
//
// Pair with _studio_template_footer.php.
// Must be included AFTER _platform_shell_header.php.
//
// Required variables (set by the page or by _studio_mount.php):
//   $current_studio       string  Studio slug (survey|mm|tia|360).
//   $current_section      string  Section key of the active item.
//   $current_item         string  Item key of the active item.
//   $_studio              array   Studio registry row.
//   $_catalog             array   Methodology-arc catalog. _studio_mount
//                                 sets this with `route` fields filled in
//                                 from the rail route map.
//
// Optional:
//   $studio_project_label string  Defaults to $_studio['sample']['project'].
//   $studio_context_label string  Defaults to $_studio['sample']['context'].
//   $shell_project_label  string  Set by _platform_shell_header; used in crumbs.
//   $mount_breadcrumb     array   Set by mount partial. Used in topbar crumbs.
//   $mount_title          string  Set by mount partial. Used in topbar crumbs (the "now" segment).

$current_studio  = $current_studio  ?? 'survey';
$current_section = $current_section ?? '';
$current_item    = $current_item    ?? '';

$_studios = require __DIR__ . '/_studio_registry.php';
if (!isset($_catalog)) {
  $_catalog = require __DIR__ . '/_studio_items_catalog.php';
}
if (!isset($_studios[$current_studio])) {
  $current_studio = 'survey';
}
$_studio = $_studios[$current_studio];

$studio_project_label = $studio_project_label ?? $_studio['sample']['project'];
$studio_context_label = $studio_context_label ?? $_studio['sample']['context'];

if (!function_exists('relicheck_item_visible')) {
  function relicheck_item_visible($item, $studio_key) {
    if (!isset($item['studios'])) return true; // universal
    return in_array($studio_key, $item['studios'], true);
  }
}

// Section icons (SVG paths from /Relicheck Studio Web-App/components.jsx).
// Keyed by section 'key' so no catalog change is needed.
$_section_icons = [
  'overview' =>
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="sb-section-icon">'
    . '<circle cx="7" cy="7" r="3"/><circle cx="17" cy="7" r="3"/>'
    . '<circle cx="7" cy="17" r="3"/><circle cx="17" cy="17" r="3"/></svg>',
  'instrument_quality' =>
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="sb-section-icon">'
    . '<path d="M12 2l2.4 1.6L17 3l1 2.7 2.5 1L20 9.4l.6 2.6-2 2 .4 2.7-2.6.7-1.3 2.4-2.6-.6L12 21l-2.5-1.2-2.6.6L5.6 18l-2.6-.7.4-2.7-2-2L2 9.4l-.5-2.7 2.5-1L5 3l2.6.6L10 2z"/>'
    . '<path d="M9 12l2 2 4-4"/></svg>',
  'descriptive' =>
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="sb-section-icon">'
    . '<line x1="4" y1="20" x2="20" y2="20"/>'
    . '<rect x="5" y="11" width="3" height="9" rx="1"/>'
    . '<rect x="10.5" y="7" width="3" height="13" rx="1"/>'
    . '<rect x="16" y="14" width="3" height="6" rx="1"/></svg>',
  'inferential' =>
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="sb-section-icon">'
    . '<path d="M9 4c-2 0-3 1.4-3 3v3H4M6 10h3M6 10c0 8 0 10-2 10"/>'
    . '<path d="M13 9l3 6m0-6l-3 6"/></svg>',
  'interpretation' =>
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="sb-section-icon">'
    . '<path d="M9 18h6"/><path d="M10 21h4"/>'
    . '<path d="M12 3a6 6 0 00-3.8 10.7c.5.4.8 1 .8 1.7V16h6v-.6c0-.7.3-1.3.8-1.7A6 6 0 0012 3z"/></svg>',
  'reporting' =>
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="sb-section-icon">'
    . '<path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5"/>'
    . '<line x1="9" y1="13" x2="16" y2="13"/><line x1="9" y1="16" x2="16" y2="16"/>'
    . '<line x1="9" y1="10" x2="12" y2="10"/></svg>',
];

// Open the app shell.
?>

<?php
  // Cache-bust studio-template.css so design tweaks land in browsers on
  // the next page load (no manual hard refresh).
  $_tpl_path = __DIR__ . '/studio-template.css';
  $_tpl_ver  = is_file($_tpl_path) ? filemtime($_tpl_path) : time();
  echo '<link rel="stylesheet" href="' . htmlspecialchars('/studio-template.css?v=' . $_tpl_ver) . '">';
?>

<div class="app">

  <aside class="sidebar" aria-label="Studio navigation">

    <!-- Studio strip — studio mark icon + studio name (top of the sidebar). -->
    <div class="sb-studio">
      <a class="sb-studio-link" href="/app-2026v4.php" title="All studios" aria-label="All studios">
        <?php if (!empty($_studio['mark'])): ?>
          <img src="<?= htmlspecialchars($_studio['mark']) ?>" alt="" class="sb-studio-mark">
        <?php endif; ?>
        <div class="sb-studio-name"><?= htmlspecialchars($_studio['name']) ?></div>
      </a>
    </div>

    <!-- Project strip — project name + meta. -->
    <div class="sb-project">
      <div class="sb-project-name"><?= htmlspecialchars($studio_project_label) ?></div>
      <div class="sb-project-meta"><?= htmlspecialchars($studio_context_label) ?></div>
    </div>

    <!-- Methodology arc -->
    <nav class="sb-nav" aria-label="Methodology arc">
      <?php foreach ($_catalog as $_section): ?>
        <?php
          $_visible_items = array_values(array_filter($_section['items'], function ($it) use ($current_studio) {
            return relicheck_item_visible($it, $current_studio);
          }));
          if (empty($_visible_items)) continue;
          $_sec_key      = $_section['key'];
          $_is_current   = ($_sec_key === $current_section);
          $_section_icon = $_section_icons[$_sec_key] ?? '';
        ?>
        <div class="sb-section<?= $_is_current ? ' open' : '' ?>" data-section="<?= htmlspecialchars($_sec_key) ?>">
          <div class="sb-section-header<?= $_is_current ? ' active' : '' ?>" data-toggle="<?= htmlspecialchars($_sec_key) ?>">
            <?= $_section_icon ?>
            <div style="flex: 1; min-width: 0;">
              <div class="sb-section-title"><?= htmlspecialchars($_section['label']) ?></div>
              <div class="sb-section-question"><?= htmlspecialchars($_section['question']) ?></div>
            </div>
            <svg class="sb-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
          </div>
          <div class="sb-items">
            <?php foreach ($_visible_items as $_item):
              $_is_active = ($_item['key'] === $current_item);
              $_hero      = !empty($_item['hero']) || ($_item['key'] === 'strength_index');
              $_badge     = $_item['badge'] ?? '';
              $_badge_cls = strtolower(preg_replace('/[^a-zA-Z]/', '', (string)$_badge));
            ?>
              <a class="sb-item<?= $_is_active ? ' active' : '' ?><?= $_hero ? ' hero' : '' ?>"
                 href="<?= htmlspecialchars($_item['route'] ?? '#') ?>"
                 <?= $_is_active ? ' aria-current="page"' : '' ?>>
                <span class="sb-item-title"><?= $_item['label'] /* may contain entities like &kappa; */ ?></span>
                <?php if ($_badge !== ''): ?>
                  <span class="sb-item-tag <?= htmlspecialchars($_badge_cls) ?>"><?= htmlspecialchars(strtoupper((string)$_badge)) ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>

    <!-- User strip -->
    <div class="sb-footer">
      <span class="avatar" aria-label="<?= htmlspecialchars($shell_user_full ?? 'You') ?>"><?= htmlspecialchars($shell_user_initials ?? '·') ?></span>
      <div style="flex: 1; min-width: 0;">
        <div class="sb-footer-name"><?= htmlspecialchars($shell_user_full ?? 'You') ?></div>
        <div class="sb-footer-role">Lead researcher</div>
      </div>
    </div>
  </aside>

  <main class="main">

    <!-- Topbar: crumbs + actions -->
    <div class="topbar">
      <div class="crumbs">
        <?php
          $_crumb_project = $shell_project_label ?? $studio_project_label;
          $_crumb_section = $_catalog[array_search($current_section, array_column($_catalog, 'key'))]['label'] ?? '';
          if ($_crumb_section === '') {
            // Fall back to the first section in the catalog (e.g. when mount page hasn't set $current_section).
            $_crumb_section = $_catalog[0]['label'] ?? '';
          }
          $_crumb_now = $mount_title ?? '';
          if ($_crumb_now === '' && !empty($mount_breadcrumb)) {
            $_crumb_now = end($mount_breadcrumb);
          }
        ?>
        <?php if (!empty($_crumb_project)): ?>
          <span><?= htmlspecialchars($_crumb_project) ?></span>
          <span class="sep">›</span>
        <?php endif; ?>
        <?php if (!empty($_crumb_section)): ?>
          <span><?= htmlspecialchars($_crumb_section) ?></span>
          <span class="sep">›</span>
        <?php endif; ?>
        <span class="now"><?= htmlspecialchars($_crumb_now !== '' ? $_crumb_now : $_studio['name']) ?></span>
      </div>
      <div class="topbar-spacer"></div>

      <button class="topbar-btn" type="button" data-studio-action="save" title="Save the current view to the project report">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Save
      </button>
      <button class="topbar-btn" type="button" data-studio-action="print" title="Print this view">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print
      </button>
      <button class="topbar-btn" type="button" data-studio-action="export" title="Export the underlying data">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export
      </button>
      <button class="topbar-btn primary" type="button" data-toggle-assist title="Ask ReliCheck">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l1.8 5.4L19 9l-5.2 1.6L12 16l-1.8-5.4L5 9l5.2-1.6z"/></svg>
        Ask ReliCheck
      </button>
    </div>

    <div class="page">
      <section class="studio-work" aria-label="Work area">
<?php
// Sidebar accordion toggle script. Kept inline so the partial is
// self-contained — no separate JS file to chase.
?>
        <script>
        (function () {
          document.addEventListener('click', function (e) {
            const h = e.target.closest('.sb-section-header');
            if (!h) return;
            const sec = h.closest('.sb-section');
            if (sec) sec.classList.toggle('open');
          });
        })();
        </script>
