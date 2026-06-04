<?php
// Shared APP-SIDE header for the ReliCheck Platform Shell.
// Include this at the top of any authenticated app page:
//
//   include __DIR__ . '/_platform_shell_header.php';
//
// Pair with _platform_shell_footer.php at the bottom of the same page.
// Subpages in subdirectories include via the parent path, e.g.:
//
//   include __DIR__ . '/../_platform_shell_header.php';
//
// All hrefs are root-relative (start with /) so this file works from any
// directory depth without further adjustment.
//
// This partial is separate from the marketing-site _nav.php. App-side
// pages use this; marketing pages keep using _nav.php.
//
// Configurable variables (set BEFORE the include):
//   $shell_page_title          string  Browser tab title.
//   $shell_user_initials       string  Two-letter avatar text (e.g. "DE").
//   $shell_user_full           string  Full name for the avatar aria-label.
//   $shell_project_label       string  Current project shown in the switcher.
//                                       Pass empty/null to hide the switcher.
//   $shell_show_preview_strip  bool    Show the "preview" banner at the top.
//   $shell_preview_strip_label string  Bold text in the preview banner.
//   $shell_preview_strip_sub   string  Light text in the preview banner.
//   $shell_body_attrs          string  Extra attributes on <body>, e.g.
//                                       data-current-studio="survey".
//                                       Studio pages use this to flip the
//                                       shell into studio mode.

$shell_page_title          = $shell_page_title          ?? 'ReliCheck';
$shell_user_initials       = $shell_user_initials       ?? '';
$shell_user_full           = $shell_user_full           ?? 'Account';
$shell_project_label       = $shell_project_label       ?? '';
$shell_show_preview_strip  = $shell_show_preview_strip  ?? false;
$shell_preview_strip_label = $shell_preview_strip_label ?? '';
$shell_preview_strip_sub   = $shell_preview_strip_sub   ?? '';
$shell_body_attrs          = $shell_body_attrs          ?? '';

// Derive a stable per-project identifier. v1 slugifies the project label
// because the data layer has no project_id column yet. When the schema
// gains a real id, set $shell_project_id directly from the page (it stays
// the same shape from JS's perspective). The id is used for:
//   - localStorage namespacing: relicheck.dataset.<project_id>,
//     relicheck.report.<project_id>.<report_id>
//   - the data-project-id attribute on <body> (and window.RELICHECK_PROJECT_ID)
//     so apps + the studio chrome agree on which project they're working in.
$shell_project_id = $shell_project_id ?? null;
if ($shell_project_id === null) {
  $_slug = strtolower(trim((string)$shell_project_label));
  $_slug = preg_replace('/[^a-z0-9]+/', '-', $_slug);
  $_slug = trim($_slug, '-');
  $shell_project_id = $_slug !== '' ? $_slug : 'untitled-project';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= htmlspecialchars($shell_page_title) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="/platform-shell.css">
</head>
<body data-project-id="<?= htmlspecialchars($shell_project_id) ?>"<?= $shell_body_attrs !== '' ? ' ' . $shell_body_attrs : '' ?>>
<script>window.RELICHECK_PROJECT_ID = <?= json_encode($shell_project_id) ?>;</script>

<?php if ($shell_show_preview_strip): ?>
<div class="preview-strip" role="status">
  <span class="dot" aria-hidden="true"></span>
  <strong><?= htmlspecialchars($shell_preview_strip_label) ?></strong>
  <span class="dim"><?= htmlspecialchars($shell_preview_strip_sub) ?></span>
</div>
<?php endif; ?>

<header class="shell-nav" role="banner">
  <div class="shell-nav-inner">
    <a class="brand" href="/app-2026v4.php" aria-label="ReliCheck home">
      <img src="/logo-brand.svg" alt="ReliCheck" class="brand-logo">
    </a>

    <?php if ($shell_project_label !== ''): ?>
    <button class="project-switcher" type="button" aria-haspopup="menu">
      <svg class="ico" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4"/><path d="M3 17l9 4 9-4"/></svg>
      <?= htmlspecialchars($shell_project_label) ?>
      <span class="caret" aria-hidden="true">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
      </span>
    </button>
    <?php endif; ?>

    <span class="nav-spacer" aria-hidden="true"></span>

    <button class="nav-search" type="button" aria-label="Search anything in ReliCheck (press cmd K)">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      Search projects, items, analyses
      <span class="kbd">⌘K</span>
    </button>

    <button class="icon-btn" type="button" aria-label="Notifications" style="position:relative;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>
      <span class="badge-dot" aria-hidden="true"></span>
    </button>

    <button class="icon-btn" type="button" aria-label="Help">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 4 2c-1 .7-1.5 1.3-1.5 2.5"/><circle cx="12" cy="17" r=".6" fill="currentColor"/></svg>
    </button>

    <div class="tb-rssi" id="tbRssi" hidden></div>

    <div class="psh-aw">
      <button class="avatar psh-avatar-btn" id="pshUserBtn" aria-haspopup="menu" aria-expanded="false" title="<?= htmlspecialchars($shell_user_full) ?>"><?= htmlspecialchars($shell_user_initials) ?></button>
      <div class="psh-menu" id="pshUserMenu" role="menu">
        <?php if ($shell_user_initials !== ''): ?>
        <div class="psh-menu-profile">
          <div class="psh-menu-avatar"><?= htmlspecialchars($shell_user_initials) ?></div>
          <span class="psh-menu-name"><?= htmlspecialchars($shell_user_full) ?></span>
        </div>
        <div class="psh-menu-div"></div>
        <?php endif; ?>
        <a class="psh-menu-item" href="/account.php" role="menuitem">My account</a>
        <a class="psh-menu-item" href="/projects.php" role="menuitem">Projects</a>
        <div class="psh-menu-div"></div>
        <a class="psh-menu-item" href="#" role="menuitem" id="pshSignOut">Sign out</a>
      </div>
    </div>
  </div>
</header>
<style>
.psh-aw { position: relative; display: inline-flex; }
button.avatar, button.psh-avatar-btn { cursor: pointer; appearance: none; -webkit-appearance: none; padding: 0; font-family: inherit; border: 1px solid rgba(15,19,34,.08); line-height: 1; }
.psh-menu {
  position: absolute; top: calc(100% + 10px); right: 0;
  min-width: 200px; background: #fff;
  border: 1px solid rgba(15,23,42,.10); border-radius: 14px;
  box-shadow: 0 8px 28px rgba(15,23,42,.12); padding: 4px 0;
  opacity: 0; pointer-events: none;
  transform: translateY(-6px) scale(.97);
  transition: opacity .13s, transform .13s;
  transform-origin: top right; z-index: 300;
}
.psh-menu.open { opacity: 1; pointer-events: auto; transform: none; }
.psh-menu-profile { display: flex; align-items: center; gap: 10px; padding: 12px 14px 10px; }
.psh-menu-avatar { width: 32px; height: 32px; border-radius: 50%; background: #e8edf5; color: #2a2f3a; font-size: 12px; font-weight: 700; display: grid; place-items: center; flex: none; }
.psh-menu-name { font-size: 13px; font-weight: 600; color: #15171a; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 140px; }
.psh-menu-div { height: 1px; background: rgba(15,23,42,.08); margin: 3px 0; }
.psh-menu-item { display: block; width: 100%; padding: 9px 14px; font-size: 14px; color: #15171a; font-weight: 500; text-decoration: none; background: none; border: none; text-align: left; cursor: pointer; transition: background .1s; box-sizing: border-box; }
.psh-menu-item:hover { background: #f5f6f8; }
</style>
<script>
(function(){
  var btn  = document.getElementById('pshUserBtn');
  var menu = document.getElementById('pshUserMenu');
  if (!btn || !menu) return;
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    var open = menu.classList.toggle('open');
    btn.setAttribute('aria-expanded', String(open));
  });
  document.addEventListener('click', function(){
    menu.classList.remove('open');
    btn.setAttribute('aria-expanded','false');
  });
  document.addEventListener('keydown', function(e){
    if (e.key==='Escape' && menu.classList.contains('open')){
      menu.classList.remove('open');
      btn.setAttribute('aria-expanded','false');
      btn.focus();
    }
  });
  var so = document.getElementById('pshSignOut');
  if (so) so.addEventListener('click', function(e){
    e.preventDefault();
    fetch('/api/auth/logout.php',{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .finally(function(){ window.location.href='/login.html'; });
  });
})();
</script>
<script>
// Shared RSSI topbar stub — call loadRssiStub(surveyProjectId) from any studio
// page to fetch and display the RSSI badge for that survey project.
window.loadRssiStub = function(pid){
  var wrap = document.getElementById('tbRssi');
  if (!wrap || !pid) return;
  fetch('/api/dev/rssi-check.php?project_id=' + encodeURIComponent(pid), {
    credentials: 'same-origin', headers: { Accept: 'application/json' }
  })
  .then(function(r){ return r.ok ? r.json() : null; })
  .then(function(d){
    if (!d || !d.ok || !d.has_rssi) { wrap.hidden = true; return; }
    var tier = d.withheld ? 'withheld' : (d.tier || 'withheld');
    var score = (!d.withheld && d.pct != null)
      ? '<span class="rssi-score">' + d.pct + '</span>' : '';
    wrap.innerHTML = '<a class="rssi-badge rssi-' + tier + '" href="' + d.link
      + '" title="' + (d.band || 'RSSI result') + '" target="_blank">'
      + score + '<span class="rssi-lbl">RSSI</span></a>';
    wrap.hidden = false;
  })
  .catch(function(){ wrap.hidden = true; });
};
</script>

<main class="relicheck-app-shell">
  <div class="hero-blob" aria-hidden="true"></div>
  <div class="relicheck-page-frame">
