<?php
// _journey_foot.php — closes the journey shell + loads journey.js.
?>
    </div><!-- /.jr-page -->
  </main>
</div><!-- /.jr-shell -->
<script src="/apps/journey/journey.js?v=<?= @filemtime(__DIR__ . '/journey.js') ?: time() ?>" defer></script>
<?php foreach (($journey_scripts ?? []) as $src): ?>
<script src="<?= htmlspecialchars($src) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
