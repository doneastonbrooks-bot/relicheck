<?php
// MM Studio · methodology one-pager.
// -------------------------------------------------------------------
// Platform Shell page (no Studio Template). The "How MM works" tile on
// /studio-mm.php lands here. Explains the three classic mixed-methods
// designs the wizard picks between, and how the MM Studio supports each.
// Public — does not require an open project.

require_once __DIR__ . '/api/_session.php';
start_session_secure();
$user = current_user();
if (!$user) {
  header('Location: /login.html?return=' . urlencode('/methodology-mm.php'));
  exit;
}

$studios = require __DIR__ . '/_studio_registry.php';
$studio  = $studios['mm'];

$shell_page_title    = 'How MM Studio works — ReliCheck';
$shell_user_full     = $user['name'] ?? $user['email'] ?? 'You';
$shell_user_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $shell_user_full) ?: 'U', 0, 2));
$shell_project_label = 'No project open';
$shell_body_attrs    = 'data-current-studio="mm" data-methodology="mm"';

include __DIR__ . '/_platform_shell_header.php';
?>

<style>
  :root {
    --landing-accent:      <?= htmlspecialchars($studio['accent']) ?>;
    --landing-accent-soft: <?= htmlspecialchars($studio['accent_soft']) ?>;
  }
  .meth-hero { padding: 48px 0 24px; position: relative; overflow: hidden; }
  .meth-hero::before {
    content: ""; position: absolute; top: -100px; right: -140px;
    width: 480px; height: 480px;
    background: radial-gradient(closest-side, var(--landing-accent-soft), transparent 70%);
    border-radius: 50%; z-index: 0;
  }
  .meth-hero > * { position: relative; z-index: 1; }
  .meth-back { font-size: 13px; color: var(--ink-4); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 16px; }
  .meth-back:hover { color: var(--ink-2); }
  .meth-eyebrow { display: inline-flex; gap: 8px; align-items: center; padding: 6px 12px; background: var(--landing-accent-soft); color: var(--landing-accent); border-radius: 999px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 14px; }
  .meth-eyebrow img { width: 18px; height: 18px; border-radius: 4px; }
  .meth-hero h1 { font-family: 'Fraunces', 'Georgia', serif; font-size: 42px; line-height: 1.1; font-weight: 600; margin: 0 0 12px; color: var(--ink-1); max-width: 760px; }
  .meth-hero h1 em { font-style: italic; color: var(--landing-accent); font-weight: 500; }
  .meth-hero .lede { font-size: 16.5px; line-height: 1.55; color: var(--ink-3); max-width: 640px; margin: 0; }
  .meth-section { margin-top: 44px; }
  .meth-section h2 { font-family: 'Fraunces', 'Georgia', serif; font-size: 24px; font-weight: 600; margin: 0 0 8px; color: var(--ink-1); }
  .meth-section > p { color: var(--ink-3); font-size: 15px; line-height: 1.6; max-width: 720px; margin: 0 0 18px; }
  .designs { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; }
  .design-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 22px; }
  .design-card .pill { display: inline-block; padding: 4px 10px; border-radius: 999px; background: var(--landing-accent-soft); color: var(--landing-accent); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 10px; }
  .design-card h3 { font-family: 'Fraunces', 'Georgia', serif; font-size: 19px; font-weight: 600; margin: 0 0 6px; color: var(--ink-1); }
  .design-card p { color: var(--ink-3); font-size: 13.5px; line-height: 1.55; margin: 0 0 10px; }
  .design-card .when { font-size: 12.5px; color: var(--ink-5); }
  .design-card .when strong { color: var(--ink-2); }
  .meth-flow {
    display: grid; grid-template-columns: repeat(6, 1fr); gap: 6px;
    margin-top: 18px;
  }
  .meth-flow .stop { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; font-size: 12.5px; color: var(--ink-3); }
  .meth-flow .stop strong { display: block; color: var(--ink-1); font-size: 13.5px; margin-bottom: 3px; font-family: 'Fraunces', serif; font-weight: 600; }
  .meth-cta { display: flex; gap: 10px; margin-top: 26px; }
  .meth-cta a { padding: 10px 18px; border-radius: 999px; font-weight: 600; font-size: 14px; text-decoration: none; }
  .meth-cta .btn-dark { background: var(--ink-1); color: #fff; border: 1px solid var(--ink-1); }
  .meth-cta .btn-dark:hover { background: var(--landing-accent); border-color: var(--landing-accent); }
  .meth-cta .btn-line { background: #fff; color: var(--ink-2); border: 1px solid var(--line); }
  .meth-cta .btn-line:hover { border-color: var(--landing-accent); color: var(--landing-accent); }
</style>

<section class="meth-hero">
  <a class="meth-back" href="/studio-mm.php">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Back to MM Studio
  </a>
  <div class="meth-eyebrow">
    <img src="<?= htmlspecialchars($studio['mark']) ?>" alt="">
    MM Studio · Methodology
  </div>
  <h1>One project, <em>two kinds of evidence</em>.</h1>
  <p class="lede">Mixed-methods research pairs quantitative scores with qualitative voice. MM Studio supports three classic designs and the integration moves that turn separate analyses into one coherent story.</p>
</section>

<section class="meth-section">
  <h2>The three designs</h2>
  <p>The MM Wizard asks two questions (data kind, intent) and recommends one of these. You can override at any time from the Studio's design selector.</p>
  <div class="designs">
    <div class="design-card">
      <span class="pill">A · Explanatory Sequential</span>
      <h3>Quant first, qual second</h3>
      <p>Run the survey, see what the numbers say, then use comments to explain <em>why</em> the numbers came out that way.</p>
      <div class="when"><strong>Pick when:</strong> you already have survey data and want to make it richer.</div>
    </div>
    <div class="design-card">
      <span class="pill">B · Exploratory Sequential</span>
      <h3>Qual first, quant second</h3>
      <p>Find themes in open-ended data, then turn the strongest themes into variables you can measure with a follow-up survey.</p>
      <div class="when"><strong>Pick when:</strong> you start with interviews and want to scale findings to a population.</div>
    </div>
    <div class="design-card">
      <span class="pill">C · Convergent Parallel</span>
      <h3>Both at once</h3>
      <p>Analyze quant and qual independently and compare them side by side in a joint display. Where they agree strengthens the finding. Where they diverge raises a question.</p>
      <div class="when"><strong>Pick when:</strong> you collected both at the same time and want a balanced report.</div>
    </div>
  </div>
</section>

<section class="meth-section">
  <h2>What a project looks like in MM Studio</h2>
  <p>Six stops, in this order. The wizard fills in 1–3; the rest unlock inside the project as you work.</p>
  <div class="meth-flow">
    <div class="stop"><strong>1. Set Up</strong>Title and study description.</div>
    <div class="stop"><strong>2. Upload Data</strong>CSV, Excel, or paste.</div>
    <div class="stop"><strong>3. Choose Design</strong>Data kind, intent, design.</div>
    <div class="stop"><strong>4. Analyze</strong>Themes, variables, numbers in the right order for your design.</div>
    <div class="stop"><strong>5. Integrate</strong>Joint Display, convergence, strength check.</div>
    <div class="stop"><strong>6. Defend</strong>Assemble and export the report.</div>
  </div>
</section>

<section class="meth-section">
  <h2>How the studio supports each design</h2>
  <p>Once a design is chosen, the left rail re-orders so the analyses you need come first. Explanatory leads with the quantitative rail, Exploratory leads with the qualitative one, Convergent runs them in parallel and surfaces the Joint Display.</p>
</section>

<div class="meth-cta">
  <a class="btn-dark" href="/mm-wizard.php?step=1">Start a new MM project →</a>
  <a class="btn-line" href="/studio-mm-projects.php">See all MM projects</a>
</div>

<?php include __DIR__ . '/_platform_shell_footer.php'; ?>
