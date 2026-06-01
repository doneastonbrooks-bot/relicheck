<?php
// Demo page that proves the Platform Shell partials work end-to-end.
// The chrome (top nav, app-shell, page-frame, footer) is rendered by
// _platform_shell_header.php + _platform_shell_footer.php. Everything
// between those two includes is page-specific content.

$shell_page_title          = 'Welcome - ReliCheck';
$shell_user_initials       = 'DE';
$shell_user_full           = 'Don Easton-Brooks';
$shell_project_label       = 'Workplace Equity Survey, 2026';
$shell_show_preview_strip  = true;
$shell_preview_strip_label = 'Platform Shell, demo via partials';
$shell_preview_strip_sub   = 'Studio tiles render from _studio_registry.php. Edit the registry to change any studio everywhere it appears.';
$shell_footer_note         = 'Platform Shell, demo build.';

// Studio picker reads from the registry. Edit _studio_registry.php
// to add, rename, restatus, or recolor any studio.
$studios = require __DIR__ . '/_studio_registry.php';

// Page-specific values: greeting + today's date in the hero.
$hour = (int)date('G');
if ($hour < 12) {
  $greeting = 'Good morning';
} else if ($hour < 17) {
  $greeting = 'Good afternoon';
} else {
  $greeting = 'Good evening';
}
$today_label = date('l, F j');
$user_first  = 'Don';

include __DIR__ . '/_platform_shell_header.php';
?>

<!-- Hero greeting -->
<section class="hero" aria-labelledby="hero-title">
  <div class="hero-eyebrow"><span class="pip" aria-hidden="true"></span><?= htmlspecialchars($today_label) ?></div>
  <h1 id="hero-title"><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($user_first) ?>. <em>Pick a studio, or pick up where you left off.</em></h1>
  <p class="lede">Four studios, each built for a different kind of research question. Pick the one that fits, or pick up a project you have already started.</p>
</section>

<!-- Studios -->
<section class="section" aria-labelledby="studios-title">
  <div class="section-head">
    <h2 id="studios-title">Studios</h2>
    <div class="head-meta">Four studios. One platform.</div>
  </div>

  <div class="studio-grid">
    <?php foreach ($studios as $slug => $studio): ?>
      <a class="studio-tile" data-studio="<?= htmlspecialchars($slug) ?>" href="<?= htmlspecialchars($studio['route'] ?? '#') ?>" aria-label="Open <?= htmlspecialchars($studio['name']) ?>">
        <span class="glyph" aria-hidden="true"><img src="<?= htmlspecialchars($studio['mark']) ?>" alt=""></span>
        <h3 class="name"><?= htmlspecialchars($studio['name']) ?></h3>
        <p class="desc"><?= htmlspecialchars($studio['description']) ?></p>
        <div class="footer">
          <span class="pill" data-status="<?= htmlspecialchars($studio['status']) ?>"><span class="pip" aria-hidden="true"></span><?= htmlspecialchars($studio['status_label']) ?></span>
          <span class="open-link with-arrow">Open studio
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
          </span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- Recent projects -->
<section class="section" aria-labelledby="recent-title">
  <div class="section-head">
    <h2 id="recent-title">Recent</h2>
    <div class="head-meta">Jump back in.</div>
  </div>
  <div class="recent-grid">
    <a class="recent-card" data-studio="survey" href="#" aria-label="Open Workplace Equity Survey">
      <span class="stripe" aria-hidden="true"></span>
      <div class="title">Workplace Equity Survey, 2026</div>
      <div class="meta">
        <span>Survey Studio</span>
        <span class="sep" aria-hidden="true"></span>
        <span>Opened 2 hours ago</span>
      </div>
    </a>
    <a class="recent-card" data-studio="mm" href="#" aria-label="Open Belonging and Retention Study">
      <span class="stripe" aria-hidden="true"></span>
      <div class="title">Belonging and Retention Study</div>
      <div class="meta">
        <span>MM Studio, convergent design</span>
        <span class="sep" aria-hidden="true"></span>
        <span>Yesterday</span>
      </div>
    </a>
    <a class="recent-card" data-studio="360" href="#" aria-label="Open Leadership 360, Cohort 4">
      <span class="stripe" aria-hidden="true"></span>
      <div class="title">Leadership 360, Cohort 4</div>
      <div class="meta">
        <span>360 Studio</span>
        <span class="sep" aria-hidden="true"></span>
        <span>Last week</span>
      </div>
    </a>
  </div>
</section>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
