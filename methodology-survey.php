<?php
// Survey Studio · methodology one-pager.
require_once __DIR__ . '/api/_session.php';
start_session_secure();
$user = current_user();
if (!$user) { header('Location: /login.html?return=' . urlencode('/methodology-survey.php')); exit; }

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['survey'];

$shell_page_title    = 'How Survey Studio works — ReliCheck';
$shell_user_full     = $user['name'] ?? $user['email'] ?? 'You';
$shell_user_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $shell_user_full) ?: 'U', 0, 2));
$shell_project_label = 'No project open';
$shell_body_attrs    = 'data-current-studio="survey" data-methodology="survey"';
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
  .check .pill { display: inline-block; padding: 3px 8px; background: var(--landing-accent-soft); color: var(--landing-accent); border-radius: 999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 8px; }
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
  <a class="meth-back" href="/studio-survey.php">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Back to Survey Studio
  </a>
  <div class="meth-eyebrow"><img src="<?= htmlspecialchars($studio['mark']) ?>" alt="">Survey Studio · Methodology</div>
  <h1>One score, <em>five checks</em>.</h1>
  <p class="lede">Survey Studio judges any instrument on five independent dimensions and rolls them into one headline number: the ReliCheck Strength Index. The lower components tell you exactly what to fix.</p>
</section>

<section class="meth-section">
  <h2>The five checks behind the Strength Index</h2>
  <p>Weights: Reliability 25 · Factor Structure 20 · Item Quality 20 · Response Quality 15 · Open-Ended Alignment 10 · Actionability 10. Reliability sub-breakdown: α 8, ω 10, agreement 3, item-rest 3, redundancy 1.</p>
  <div class="check-grid">
    <div class="check"><span class="pill">25%</span><h3>Reliability</h3><p>Cronbach's α, McDonald's ω, inter-rater agreement, item-rest correlations, redundancy.</p></div>
    <div class="check"><span class="pill">20%</span><h3>Factor Structure</h3><p>KMO sampling adequacy, Bartlett's test, factor readiness, cross-loadings.</p></div>
    <div class="check"><span class="pill">20%</span><h3>Item Quality</h3><p>Item-total correlations, "if item deleted" alpha, item-rest, endpoint use.</p></div>
    <div class="check"><span class="pill">15%</span><h3>Response Quality</h3><p>Completion rate, straight-lining, missingness, response time, duplicates.</p></div>
    <div class="check"><span class="pill">10%</span><h3>Open-Ended Alignment</h3><p>Do the comments tell the same story as the numbers? Theme-to-score alignment.</p></div>
    <div class="check"><span class="pill">10%</span><h3>Actionability</h3><p>Are the findings usable? Effect sizes, group differences worth acting on.</p></div>
  </div>
</section>

<section class="meth-section">
  <h2>What you get back</h2>
  <p>One headline score (0–100) with five sub-scores, plain-language interpretations, and a prioritized list of items or questions that are pulling the score down. The whole report can be exported to PDF, DOCX, or a one-page executive summary.</p>
</section>

<div class="meth-cta">
  <a class="btn-dark" href="/survey-wizard.php?step=1">Start a new survey →</a>
  <a class="btn-line" href="/studio-survey-projects.php">See all surveys</a>
</div>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
