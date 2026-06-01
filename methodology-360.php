<?php
// 360 Studio · methodology one-pager.
require_once __DIR__ . '/api/_session.php';
start_session_secure();
$user = current_user();
if (!$user) { header('Location: /login.html?return=' . urlencode('/methodology-360.php')); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['360'];

$shell_page_title    = 'How 360 Studio works — ReliCheck';
$shell_user_full     = $user['name'] ?? $user['email'] ?? 'You';
$shell_user_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $shell_user_full) ?: 'U', 0, 2));
$shell_project_label = 'No project open';
$shell_body_attrs    = 'data-current-studio="360" data-methodology="360"';
include __DIR__ . '/_platform_shell_header.php';
?>

<style>
  :root { --landing-accent: <?= htmlspecialchars($studio['accent']) ?>; --landing-accent-soft: <?= htmlspecialchars($studio['accent_soft']) ?>; }
  .meth-hero { padding: 48px 0 24px; position: relative; overflow: hidden; }
  .meth-hero::before { content: ""; position: absolute; top: -100px; right: -140px; width: 480px; height: 480px; background: radial-gradient(closest-side, var(--landing-accent-soft), transparent 70%); border-radius: 50%; z-index: 0; }
  .meth-hero > * { position: relative; z-index: 1; }
  .meth-back { font-size: 13px; color: var(--ink-4); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 16px; }
  .meth-eyebrow { display: inline-flex; gap: 8px; align-items: center; padding: 6px 12px; background: var(--landing-accent-soft); color: var(--landing-accent); border-radius: 999px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 14px; }
  .meth-eyebrow img { width: 18px; height: 18px; border-radius: 4px; }
  .meth-hero h1 { font-family: 'Fraunces', 'Georgia', serif; font-size: 42px; line-height: 1.1; font-weight: 600; margin: 0 0 12px; color: var(--ink-1); max-width: 760px; }
  .meth-hero h1 em { font-style: italic; color: var(--landing-accent); font-weight: 500; }
  .meth-hero .lede { font-size: 16.5px; line-height: 1.55; color: var(--ink-3); max-width: 640px; margin: 0; }
  .meth-section { margin-top: 44px; }
  .meth-section h2 { font-family: 'Fraunces', 'Georgia', serif; font-size: 24px; font-weight: 600; margin: 0 0 8px; color: var(--ink-1); }
  .meth-section > p { color: var(--ink-3); font-size: 15px; line-height: 1.6; max-width: 720px; margin: 0 0 18px; }
  .check-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
  .check { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 18px 20px; }
  .check h3 { font-family: 'Fraunces', serif; font-size: 17px; margin: 0 0 4px; color: var(--ink-1); }
  .check p { color: var(--ink-4); font-size: 13px; line-height: 1.5; margin: 0; }
  .meth-cta { display: flex; gap: 10px; margin-top: 26px; }
  .meth-cta a { padding: 10px 18px; border-radius: 999px; font-weight: 600; font-size: 14px; text-decoration: none; }
  .meth-cta .btn-dark { background: var(--ink-1); color: #fff; border: 1px solid var(--ink-1); }
  .meth-cta .btn-dark:hover { background: var(--landing-accent); border-color: var(--landing-accent); }
  .meth-cta .btn-line { background: #fff; color: var(--ink-2); border: 1px solid var(--line); }
  .meth-cta .btn-line:hover { border-color: var(--landing-accent); color: var(--landing-accent); }
</style>

<section class="meth-hero">
  <a class="meth-back" href="/studio-360.php">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Back to 360 Studio
  </a>
  <div class="meth-eyebrow"><img src="<?= htmlspecialchars($studio['mark']) ?>" alt="">360 Studio · Methodology</div>
  <h1>Many voices, <em>one view</em>.</h1>
  <p class="lede">360-degree feedback compares how a subject is seen by themselves, peers, manager, and direct reports. The gaps between perspectives are where the developmental insight lives.</p>
</section>

<section class="meth-section">
  <h2>What 360 Studio measures</h2>
  <div class="check-grid">
    <div class="check"><h3>Perception Gaps</h3><p>Where do self-ratings differ from peer / manager / direct-report ratings? Big self-other gaps point to blind spots.</p></div>
    <div class="check"><h3>Rater Group Agreement</h3><p>How consistently do raters within a group agree with each other? Low agreement reduces signal.</p></div>
    <div class="check"><h3>Competency Profiles</h3><p>Per competency: a profile across rater groups, with confidence intervals reflecting group size.</p></div>
    <div class="check"><h3>Confidentiality Thresholds</h3><p>Minimum raters per group before scores are shown to the subject. Protects rater anonymity in small panels.</p></div>
  </div>
</section>

<section class="meth-section">
  <h2>What you get back</h2>
  <p>Per-subject reports with perception-gap analysis, competency-by-rater-group tables, narrative-comment themes (per rater group), and an auto-drafted Development Plan grounded in the highest-gap competencies and most-frequent comment themes.</p>
</section>

<div class="meth-cta">
  <a class="btn-dark" href="/studio-360.php">Start a new 360 panel →</a>
  <a class="btn-line" href="/studio-360-projects.php">See all panels</a>
</div>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
