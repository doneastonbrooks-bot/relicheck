<?php
// Shared APP-SIDE footer for the ReliCheck Platform Shell.
// Include this at the bottom of any authenticated app page that opened with
// _platform_shell_header.php:
//
//   include __DIR__ . '/_platform_shell_footer.php';
//
// Subpages in subdirectories include via the parent path, e.g.:
//
//   include __DIR__ . '/../_platform_shell_footer.php';
//
// This partial closes the .relicheck-page-frame and .relicheck-app-shell
// wrappers opened by the header, then renders the minimal Platform Shell
// footer (logo, optional note, secondary links).
//
// Configurable variables (set BEFORE the include):
//   $shell_footer_note string  Small line between the brand and the links.

$shell_footer_note = $shell_footer_note ?? '';
?>
  </div>
</main>

<footer class="shell-footer" role="contentinfo">
  <div class="shell-footer-inner">
    <a class="brand" href="/app-2026v4.php" aria-label="ReliCheck home">
      <img src="/logo-brand.svg" alt="ReliCheck" class="brand-logo">
    </a>
    <?php if ($shell_footer_note !== ''): ?>
    <span><?= htmlspecialchars($shell_footer_note) ?></span>
    <?php endif; ?>
    <nav class="links" aria-label="Footer">
      <a href="/methodology.html">Methodology</a>
      <a href="/help.html">Help</a>
      <a href="/status.html">Status</a>
      <a href="/privacy.html">Privacy</a>
    </nav>
  </div>
</footer>

</body>
</html>
