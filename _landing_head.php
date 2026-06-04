<?php
// _landing_head.php — Shared RSSI-style landing header/chrome.
// Include AFTER auth + AFTER setting the $landing_* variables below.
// Pair with _landing_foot.php at the bottom of the same page.
//
// Configurable variables (set BEFORE the include):
//   $landing_title         string  Browser tab title.
//   $landing_accent        string  Accent color (default RSSI blue #2D8DFF).
//   $landing_accent_deep   string  Darker accent for gradients (default #0A6FE8).
//   $landing_accent_soft   string  Soft accent tint for chips/icons (default #EEF3FA).
//   $landing_pill_label    string  Right-side header pill text. '' hides it.
//   $landing_pill_mark     string  Optional small icon URL inside the pill.
//   $landing_show_back     bool    Show a "All apps" back link to the hub.
//   $landing_user_initials string  Avatar initials.
//   $landing_user_full     string  Avatar aria/title.
//   $landing_favicon       string  Favicon href (default /logo-brand.svg).

$landing_title         = $landing_title         ?? 'ReliCheck';
$landing_accent        = $landing_accent        ?? '#2D8DFF';
$landing_accent_deep   = $landing_accent_deep   ?? '#0A6FE8';
$landing_accent_soft   = $landing_accent_soft   ?? '#EEF3FA';
$landing_pill_label    = $landing_pill_label    ?? '';
$landing_pill_mark     = $landing_pill_mark     ?? '';
$landing_logo          = $landing_logo          ?? '';  // product logo (square icon); replaces the ReliCheck wordmark on this page's header
$landing_logo_name     = $landing_logo_name     ?? '';
$landing_show_back     = $landing_show_back     ?? false;
$landing_user_initials = $landing_user_initials ?? '';
$landing_user_full     = $landing_user_full     ?? 'Account';
$landing_favicon       = $landing_favicon       ?? '/logo-brand.svg';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= htmlspecialchars($landing_title) ?></title>
  <link rel="icon" href="<?= htmlspecialchars($landing_favicon) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@1,700&display=swap">
  <link rel="stylesheet" href="/landing.css?v=<?= filemtime(__DIR__ . '/landing.css') ?>">
  <style>
    :root {
      --accent:      <?= htmlspecialchars($landing_accent) ?>;
      --accent-deep: <?= htmlspecialchars($landing_accent_deep) ?>;
      --accent-soft: <?= htmlspecialchars($landing_accent_soft) ?>;
    }
  </style>
</head>
<body>

<header class="lp-head">
  <div class="lp-head-left">
    <?php if ($landing_show_back): ?>
      <a class="lp-back-link" href="/app-2026v4.php">
        <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 1L3 7l6 6"/></svg>
        All apps
      </a>
    <?php endif; ?>
    <?php if (!empty($landing_logo)): ?>
      <a class="lp-brand" href="#">
        <img class="lp-brand-mark" src="<?= htmlspecialchars($landing_logo) ?>" alt="">
        <span class="lp-brand-name"><?= htmlspecialchars($landing_logo_name) ?></span>
      </a>
    <?php else: ?>
      <a class="lp-brand" href="/app-2026v4.php">
        <img class="lp-brand-logo" src="/logo-brand.svg" alt="ReliCheck">
      </a>
    <?php endif; ?>
  </div>
  <div class="lp-head-right">
    <?php if ($landing_pill_label !== ''): ?>
      <span class="lp-app-pill">
        <?php if (!empty($landing_pill_mark)): ?><img src="<?= htmlspecialchars($landing_pill_mark) ?>" alt=""><?php endif; ?>
        <?= htmlspecialchars($landing_pill_label) ?>
      </span>
    <?php endif; ?>
    <div class="lp-user-wrap">
      <button class="lp-user" id="lpUserBtn" aria-haspopup="menu" aria-expanded="false" title="<?= htmlspecialchars($landing_user_full) ?>">
        <?= htmlspecialchars($landing_user_initials ?: 'U') ?>
      </button>
      <div class="lp-user-menu" id="lpUserMenu" role="menu">
        <div class="lp-menu-profile">
          <div class="lp-menu-avatar"><?= htmlspecialchars($landing_user_initials ?: 'U') ?></div>
          <span class="lp-menu-name"><?= htmlspecialchars($landing_user_full) ?></span>
        </div>
        <div class="lp-menu-divider"></div>
        <a class="lp-menu-item" href="/account.php" role="menuitem">My account</a>
        <a class="lp-menu-item" href="/projects.php" role="menuitem">Projects</a>
        <div class="lp-menu-divider"></div>
        <a class="lp-menu-item" href="#" role="menuitem" id="lpSignOut">Sign out</a>
      </div>
    </div>
  </div>
</header>

<script>
(function(){
  var btn = document.getElementById('lpUserBtn');
  var menu = document.getElementById('lpUserMenu');
  if (!btn || !menu) return;
  btn.addEventListener('click', function(e) {
    e.stopPropagation();
    var open = menu.classList.toggle('lp-open');
    btn.setAttribute('aria-expanded', String(open));
  });
  document.addEventListener('click', function() {
    menu.classList.remove('lp-open');
    btn.setAttribute('aria-expanded', 'false');
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && menu.classList.contains('lp-open')) {
      menu.classList.remove('lp-open');
      btn.setAttribute('aria-expanded', 'false');
      btn.focus();
    }
  });
  var so = document.getElementById('lpSignOut');
  if (so) so.addEventListener('click', function(e) {
    e.preventDefault();
    fetch('/api/auth/logout.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).finally(function() { window.location.href = '/login.html'; });
  });
})();
</script>

<main class="lp-page<?= !empty($landing_page_narrow) ? ' narrow' : '' ?>">
