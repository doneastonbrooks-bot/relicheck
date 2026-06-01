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
  <link rel="stylesheet" href="/landing.css">
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
  <div style="display:flex;align-items:center;gap:18px;">
    <?php if ($landing_logo !== ''): ?>
      <a class="lp-brand" href="/app-2026v4.php" title="Back to ReliCheck">
        <img src="<?= htmlspecialchars($landing_logo) ?>" alt="<?= htmlspecialchars($landing_logo_name) ?>" class="lp-brand-mark">
        <?php if ($landing_logo_name !== ''): ?><span class="lp-brand-name"><?= htmlspecialchars($landing_logo_name) ?></span><?php endif; ?>
      </a>
    <?php else: ?>
      <a class="lp-brand" href="/app-2026v4.php" title="ReliCheck home">
        <img src="/logo-brand.svg" alt="ReliCheck" class="lp-brand-logo">
      </a>
    <?php endif; ?>
    <?php if ($landing_show_back): ?>
      <a class="lp-back-link" href="/app-2026v4.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        All apps
      </a>
    <?php endif; ?>
  </div>
  <div class="lp-head-right">
    <?php if ($landing_pill_label !== ''): ?>
      <span class="lp-app-pill">
        <?php if ($landing_pill_mark !== ''): ?><img src="<?= htmlspecialchars($landing_pill_mark) ?>" alt=""><?php endif; ?>
        <?= htmlspecialchars($landing_pill_label) ?>
      </span>
    <?php endif; ?>
    <a class="lp-user" href="#" title="<?= htmlspecialchars($landing_user_full) ?>"><?= htmlspecialchars($landing_user_initials) ?></a>
  </div>
</header>

<main class="lp-page<?= !empty($landing_page_narrow) ? ' narrow' : '' ?>">
