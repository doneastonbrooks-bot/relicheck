<?php
// _landing_foot.php — Shared RSSI-style landing footer.
// Pair with _landing_head.php. Set $landing_tagline before including to
// show a centered tagline above the footer ('' hides it).
$landing_tagline = $landing_tagline ?? '';
?>
  <?php if ($landing_tagline !== ''): ?>
    <div class="lp-tagline"><?= htmlspecialchars($landing_tagline) ?></div>
  <?php endif; ?>
</main>

<footer class="lp-foot">
  <div>&copy; <?= date('Y') ?> ReliCheck. All rights reserved.</div>
  <nav>
    <a href="/help/">Help Center</a>
    <a href="/privacy.html">Privacy Policy</a>
    <a href="/terms.html">Terms of Use</a>
  </nav>
</footer>

<!-- Universal sticky studio dock: ReliCheck logo bottom-left, studio name centered. -->
<style>
  .studio-dock { position:fixed; left:0; right:0; bottom:0; z-index:60; padding:12px 22px; box-sizing:border-box;
    background:rgba(255,255,255,0.92); -webkit-backdrop-filter:saturate(1.4) blur(12px); backdrop-filter:saturate(1.4) blur(12px);
    border-top:1px solid var(--line,#e6e9f0); box-shadow:0 -4px 22px rgba(15,23,42,0.07); }
  .studio-dock-logo { position:absolute; left:22px; top:50%; transform:translateY(-50%); display:inline-flex; align-items:center; }
  .studio-dock-logo img { height:36px; width:auto; display:block; }
  .studio-dock-inner { display:flex; align-items:center; justify-content:center; gap:12px; flex-wrap:wrap; min-height:42px;
    font-size:13px; font-weight:600; color:var(--text-2,#5a657a); }
  body { padding-bottom:96px; }
  @media (max-width:760px) { .studio-dock-logo { display:none; } }
</style>
<div class="studio-dock" role="contentinfo">
  <a class="studio-dock-logo" href="/app-2026v4.php" aria-label="ReliCheck home"><img src="/logo-brand.svg" alt="ReliCheck"></a>
  <div class="studio-dock-inner"><?= htmlspecialchars($landing_logo_name ?? 'ReliCheck') ?></div>
</div>

</body>
</html>
