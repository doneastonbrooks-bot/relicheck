<?php
// TIA Studio · methodology one-pager.
require_once __DIR__ . '/api/_session.php';
start_session_secure();
$user = current_user();
if (!$user) { header('Location: /login.html?return=' . urlencode('/methodology-tia.php')); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['tia'];

$shell_page_title    = 'How TIA Studio works — ReliCheck';
$shell_user_full     = $user['name'] ?? $user['email'] ?? 'You';
$shell_user_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $shell_user_full) ?: 'U', 0, 2));
$shell_project_label = 'No project open';
$shell_body_attrs    = 'data-current-studio="tia" data-methodology="tia"';
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
  <a class="meth-back" href="/studio-tia.php">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Back to TIA Studio
  </a>
  <div class="meth-eyebrow"><img src="<?= htmlspecialchars($studio['mark']) ?>" alt="">TIA Studio · Methodology</div>
  <h1>Every item, <em>examined</em>.</h1>
  <p class="lede">Test and Item Analysis treats each item as a measurement instrument in its own right. Difficulty, discrimination, distractor performance, and cognitive demand — for classroom and standardized tests alike.</p>
</section>

<section class="meth-section">
  <h2>The four item-level checks</h2>
  <p>Classical test theory, applied per item.</p>
  <div class="check-grid">
    <div class="check"><h3>Difficulty (p)</h3><p>Proportion of students who got the item right. Sweet spot: 0.30–0.85 for most tests.</p></div>
    <div class="check"><h3>Discrimination (D)</h3><p>Does the item separate high scorers from low scorers? Aim for D ≥ 0.30.</p></div>
    <div class="check"><h3>Distractor Analysis</h3><p>For each wrong-answer choice: how many picked it, and how did those who picked it score overall? Catch distractors that are too obvious or too tempting.</p></div>
    <div class="check"><h3>Item-Total Correlation</h3><p>Point-biserial correlation between item score and total score. Negative or low values flag bad items.</p></div>
  </div>
</section>

<section class="meth-section">
  <h2>What you get back</h2>
  <p>Per-item flags (difficulty, discrimination, distractor issues), a "drop these items" list, a "revise these items" list, and a revised total-score distribution. Plus a defensible methodology section for your test report.</p>
</section>

<div class="meth-cta">
  <a class="btn-dark" href="/studio-tia.php">Start a new test analysis →</a>
  <a class="btn-line" href="/studio-tia-projects.php">See all TIA projects</a>
</div>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
