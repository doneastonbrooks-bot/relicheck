<?php
// _journey_head.php — shared shell (head + left STEP rail + topbar open) for the
// ReliCheck evidence-journey apps. Set the $jr_* variables before including.
// Pair with _journey_foot.php. Helper fns jr_ring()/jr_icon() are defined here.

$jr_page_title = $jr_page_title ?? 'ReliCheck';
$jr_accent     = $jr_accent     ?? '';   // '' = teal (SIRI default), 'blue' = RSSI
$jr_brand_logo = $jr_brand_logo ?? '';   // when set, render this logo image in the rail
$jr_brand_sub  = $jr_brand_sub  ?? '';
$jr_rail_label = $jr_rail_label ?? 'Steps';
$jr_steps      = $jr_steps      ?? [];   // each: ['id'=>,'no'=>,'label'=>,'sub'=>]
$jr_ctx_label  = $jr_ctx_label  ?? '';
$jr_chip       = $jr_chip       ?? '';
$jr_initials   = $jr_initials   ?? '';
$jr_foot       = $jr_foot       ?? 'ReliCheck · evidence journey';
$jr_exit_links = $jr_exit_links ?? [];   // each: ['label'=>, 'href'=>, 'icon'=>] — rendered under the step menu

if (!function_exists('jr_ring')) {
  // SVG progress ring (teal). $pct 0-100, big serif value + small label.
  function jr_ring($pct, $value, $label) {
    $r = 64; $c = 2 * M_PI * $r; $off = $c * (1 - max(0, min(100, $pct)) / 100);
    return '<div class="jr-ring"><svg viewBox="0 0 148 148">'
      . '<circle cx="74" cy="74" r="' . $r . '" fill="none" stroke="#efe9df" stroke-width="11"/>'
      . '<circle cx="74" cy="74" r="' . $r . '" fill="none" stroke="#0F9E7B" stroke-width="11" stroke-linecap="round"'
      . ' stroke-dasharray="' . round($c, 2) . '" stroke-dashoffset="' . round($off, 2) . '"/>'
      . '</svg><div class="val"><div><b>' . htmlspecialchars($value) . '</b><small>' . htmlspecialchars($label) . '</small></div></div></div>';
  }
}
if (!function_exists('jr_icon')) {
  function jr_icon($name) {
    $p = [
      'compass' => '<circle cx="12" cy="12" r="9"/><polygon points="16 8 11 13 8 16 13 11"/>',
      'gauge'   => '<path d="M12 13a3 3 0 1 0 0-.01"/><path d="M5 19a9 9 0 1 1 14 0"/>',
      'layers'  => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
      'check'   => '<circle cx="12" cy="12" r="9"/><polyline points="8 12 11 15 16 9"/>',
      'flask'   => '<path d="M9 3h6"/><path d="M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3"/>',
      'target'  => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/>',
      'rocket'  => '<path d="M5 13c-1.5 1.5-2 5-2 5s3.5-.5 5-2"/><path d="M14 4c3 0 6 3 6 6 0 4-7 9-7 9l-4-4s5-11 5-11z"/><circle cx="15" cy="9" r="1.5"/>',
      'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
      'filter'  => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
      'shield'  => '<path d="M12 2.5 4 5.5v6c0 4.5 3.5 8 8 9 4.5-1 8-4.5 8-9v-6z"/>',
      'doc'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
      'arrow'   => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
      'info'    => '<circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="7.5" r=".6" fill="currentColor"/>',
    ];
    $d = $p[$name] ?? $p['info'];
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
  }
}
if (!function_exists('jr_placeholder')) {
  // A navigable step panel whose interactive build comes in a later pass. Still
  // teaches what the step decides; $preview is optional static HTML.
  function jr_placeholder($id, $no, $name, $title, $icon, $teach_title, $teach_body, $preview = '') {
    $h  = '<section class="jr-panel" data-step="' . htmlspecialchars($id) . '">';
    $h .= '<div class="jr-eyebrow"><span class="step-no">' . htmlspecialchars($no) . '</span> · ' . htmlspecialchars($name) . '</div>';
    $h .= '<h1 class="jr-title">' . $title . '</h1>';
    $h .= '<div class="jr-soon">' . jr_icon('info') . ' Interactive build in the next pass</div>';
    $h .= '<div class="teach"><div class="ti">' . jr_icon($icon) . '</div><div><h4>' . htmlspecialchars($teach_title) . '</h4><p>' . $teach_body . '</p></div></div>';
    if ($preview !== '') $h .= $preview;
    $h .= '<p class="jr-preview-note">This step becomes an interactive decision point once the labs are wired to the engine.</p>';
    $h .= '</section>';
    return $h;
  }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= htmlspecialchars($jr_page_title) ?></title>
  <link rel="icon" type="image/png" href="/RSSI-logo.png">
  <link rel="stylesheet" href="/apps/journey/journey.css?v=<?= @filemtime(__DIR__ . '/journey.css') ?: time() ?>">
</head>
<body<?= $jr_accent !== '' ? ' data-accent="' . htmlspecialchars($jr_accent) . '"' : '' ?>>
<div class="jr-shell">

  <!-- ───── Left STEP rail ───── -->
  <nav class="jr-rail" aria-label="Steps">
    <a class="jr-brand" href="/app-2026v4.php" title="Back to ReliCheck">
      <?php if ($jr_brand_logo !== ''): ?>
        <img class="logo" src="<?= htmlspecialchars($jr_brand_logo) ?>" alt="ReliCheck <?= htmlspecialchars($jr_brand_sub) ?>">
      <?php else: ?>
        <span class="mark">R</span>
        <span class="wm">ReliCheck<small><?= htmlspecialchars($jr_brand_sub) ?></small></span>
      <?php endif; ?>
    </a>
    <div class="jr-rail-label"><?= htmlspecialchars($jr_rail_label) ?></div>
    <?php foreach ($jr_steps as $s): ?>
      <button class="jr-step" data-step="<?= htmlspecialchars($s['id']) ?>">
        <span class="num"><?= htmlspecialchars($s['no']) ?></span>
        <span><span class="lab"><?= htmlspecialchars($s['label']) ?></span><span class="sub"><?= htmlspecialchars($s['sub']) ?></span></span>
      </button>
    <?php endforeach; ?>
    <?php if (!empty($jr_exit_links)): ?>
      <div class="jr-rail-exits">
        <?php foreach ($jr_exit_links as $x): ?>
          <a class="jr-rail-exit" href="<?= htmlspecialchars($x['href']) ?>"><span class="ei"><?= jr_icon($x['icon'] ?? 'arrow') ?></span><span class="el"><?= htmlspecialchars($x['label']) ?></span></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="jr-rail-foot"><?= htmlspecialchars($jr_foot) ?></div>
  </nav>

  <!-- ───── Main ───── -->
  <main class="jr-main">
    <div class="jr-topbar">
      <div class="ctx" id="jrCtx"><?= $jr_ctx_label ?></div>
      <div class="jr-head-right">
        <span class="jr-chip" id="jrChip"<?= $jr_chip === '' ? ' style="display:none"' : '' ?>><span class="dot"></span><span id="jrChipText"><?= htmlspecialchars($jr_chip) ?></span></span>
        <span class="jr-avatar"><?= htmlspecialchars($jr_initials) ?></span>
      </div>
    </div>
    <div class="jr-page">
