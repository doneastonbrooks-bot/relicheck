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
  <a href="/app-2026v4.php" class="lp-foot-logo" aria-label="ReliCheck home">
    <img src="/logo-brand.svg" alt="ReliCheck">
  </a>
  <span style="display:inline-block;width:1px;height:22px;background:var(--hairline);margin:0 18px;flex:none;"></span>
  <span style="font-family:'Fraunces',Georgia,serif;font-style:italic;font-weight:700;font-size:12px;color:#c85c3a;line-height:1;">something matters</span>
</footer>

</body>
</html>
