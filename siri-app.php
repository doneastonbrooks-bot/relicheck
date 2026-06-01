<?php
// siri-app.php — Pre-response evidence-journey app (SIRI / SDSI) — step rail.
// Phase 1 mockup: cream/teal/serif shell + step rail; Orientation + SIRI Score
// built; later steps are navigable placeholders. Sample data only — NO engine
// calls, NO scoring changes. New parallel file; siri-readiness.php untouched.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) { header('Location: /login.html?return=' . urlencode('/siri-app.php')); exit; }
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }
$initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user['name'] ?? $user['email'] ?? 'U') ?: 'U', 0, 2));

$jr_page_title = 'SIRI · Pre-launch · ReliCheck';
$jr_brand_sub  = 'Pre-launch · SIRI / SDSI';
$jr_rail_label = 'Before responses';
$jr_initials   = $initials;
$jr_ctx_label  = '<b>Team Climate Pulse</b> · measurement blueprint';
$jr_chip       = 'SIRI 82 / 100';
$jr_foot       = 'SIRI / SDSI defines the intended measurement blueprint before launch.';
$jr_steps = [
  ['id'=>'orientation',   'no'=>'00','label'=>'Orientation',            'sub'=>'How this works'],
  ['id'=>'score',         'no'=>'01','label'=>'SIRI Score',             'sub'=>'Launch readiness'],
  ['id'=>'constructs',    'no'=>'02','label'=>'Constructs',             'sub'=>'What you measure'],
  ['id'=>'validity',      'no'=>'03','label'=>'Validity Readiness',     'sub'=>'Right idea?'],
  ['id'=>'reliability',   'no'=>'04','label'=>'Reliability Readiness',  'sub'=>'Will it hold?'],
  ['id'=>'administration','no'=>'05','label'=>'Administration Readiness','sub'=>'Field-ready?'],
  ['id'=>'launch',        'no'=>'06','label'=>'Launch Plan',            'sub'=>'Go decision'],
];

$CONSTRUCTS = [
  ['name'=>'Engagement',      'items'=>5],
  ['name'=>'Manager Support', 'items'=>4],
  ['name'=>'Belonging',       'items'=>4],
  ['name'=>'Workload Balance','items'=>4],
];

include __DIR__ . '/apps/journey/_journey_head.php';
?>

<!-- ═════ 00 Orientation ═════ -->
<section class="jr-panel" data-step="orientation">
  <div class="jr-eyebrow"><span class="step-no">00</span> · Orientation</div>
  <h1 class="jr-title">Build a survey <em>worth trusting</em> — before anyone answers.</h1>
  <p class="jr-lede">SIRI is the pre-response side of ReliCheck. It defines the measurement blueprint: the constructs you intend to measure, and whether your items, scales, and administration plan are ready to produce trustworthy evidence once responses arrive.</p>

  <div class="teach">
    <div class="ti"><?= jr_icon('compass') ?></div>
    <div>
      <h4>Two apps, one journey</h4>
      <p><b>SIRI / SDSI</b> (here) defines the intended blueprint <i>before</i> launch. After responses come in, <b>RSSI</b> tests whether the collected data actually supports that blueprint. Same product, two points in the evidence journey.</p>
      <p>Each step on the left is a decision point, not a report. You move from orientation, to a readiness score, to the constructs, to the three readiness lenses, and finally to a launch plan.</p>
    </div>
  </div>

  <h2 class="jr-h2">Your blueprint at a glance</h2>
  <div class="jr-grid jr-g4">
    <div class="jr-card"><div class="jr-dom"><div class="dh"><span class="dn">Constructs</span><span class="dv">4</span></div><div class="de">A normal professional survey measures several ideas, not one.</div></div></div>
    <div class="jr-card"><div class="jr-dom"><div class="dh"><span class="dn">Items</span><span class="dv">17</span></div><div class="de">Grouped into the four constructs below.</div></div></div>
    <div class="jr-card"><div class="jr-dom"><div class="dh"><span class="dn">Scale</span><span class="dv">5-pt</span></div><div class="de">Agree–disagree Likert, consistent anchors.</div></div></div>
    <div class="jr-card"><div class="jr-dom"><div class="dh"><span class="dn">Status</span><span class="dv" style="font-size:15px">Draft</span></div><div class="de">Not yet launched. Readiness is being checked.</div></div></div>
  </div>

  <h2 class="jr-h2">The four constructs you intend to measure</h2>
  <div class="construct-pills">
    <?php foreach ($CONSTRUCTS as $i => $c): ?>
      <span class="cp" data-on="<?= $i === 0 ? 1 : 0 ?>"><?= htmlspecialchars($c['name']) ?> <span class="a"><?= $c['items'] ?> items</span></span>
    <?php endforeach; ?>
  </div>

  <div class="jr-actions">
    <a class="jr-btn" data-go="score">See the SIRI Score <?= jr_icon('arrow') ?></a>
    <a class="jr-btn ghost" href="/app-2026v4.php">Back to ReliCheck</a>
  </div>
</section>

<!-- ═════ 01 SIRI Score ═════ -->
<section class="jr-panel" data-step="score">
  <div class="jr-eyebrow"><span class="step-no">01</span> · SIRI Score</div>
  <h1 class="jr-title">Launch readiness, <em>in one number</em>.</h1>
  <p class="jr-lede">The SIRI Score combines three readiness checks into a single 100-point index. It tells you whether the blueprint is ready to field — and exactly which part to strengthen first.</p>

  <div class="jr-hero">
    <?= jr_ring(82, '82', '/ 100') ?>
    <div class="jr-hero-body">
      <span class="jr-band"><?= jr_icon('check') ?> Launch-ready · minor fixes</span>
      <h3>This blueprint is close. Tighten two items and you are clear to field.</h3>
      <p>Administration is fully ready; reliability readiness is strong; a couple of construct-definition items hold back the design score.</p>
      <div class="jr-hero-meta"><span>Constructs <b>4</b></span><span>Items <b>17</b></span><span>Checked <b>today</b></span></div>
    </div>
  </div>

  <h2 class="jr-h2">What goes into the score</h2>
  <div class="jr-grid jr-g3">
    <div class="jr-dom"><div class="dh"><span class="dn">SDSI · Design Strength</span><span class="dv">41<small> / 50</small></span></div><div class="jr-meter"><span style="width:82%"></span></div><div class="de">Constructs, items, scales and flow. Two item-definition flags to fix.</div></div>
    <div class="jr-dom"><div class="dh"><span class="dn">Reliability Readiness</span><span class="dv">28<small> / 35</small></span></div><div class="jr-meter"><span style="width:80%"></span></div><div class="de">Each construct has enough clear, consistent items to hang together.</div></div>
    <div class="jr-dom"><div class="dh"><span class="dn">Administration Readiness</span><span class="dv">13<small> / 15</small></span></div><div class="jr-meter"><span style="width:87%"></span></div><div class="de">Consent, instructions, fielding plan and burden are in place.</div></div>
  </div>

  <div class="teach">
    <div class="ti"><?= jr_icon('info') ?></div>
    <div>
      <h4>What this number means — and doesn't</h4>
      <p>SIRI judges whether the survey is <i>built</i> to produce trustworthy evidence. It does not yet know how respondents will actually behave — that is what RSSI measures after launch. A high SIRI Score makes a strong RSSI result more likely, but never guarantees it.</p>
    </div>
  </div>

  <div class="jr-actions">
    <a class="jr-btn" data-go="constructs">Review the constructs <?= jr_icon('arrow') ?></a>
    <a class="jr-btn ghost" data-go="orientation">Back to orientation</a>
  </div>
</section>

<?php
// ═════ 02–06 placeholders (navigable; interactive build next pass) ═════
$pillRow = '<div class="construct-pills">';
foreach ($CONSTRUCTS as $i => $c) {
  $pillRow .= '<span class="cp" data-on="' . ($i === 0 ? 1 : 0) . '">' . htmlspecialchars($c['name']) . ' <span class="a">' . $c['items'] . ' items</span></span>';
}
$pillRow .= '</div>';

echo jr_placeholder('constructs','02','Constructs',
  'Define <em>what</em> you are measuring.', 'layers',
  'Constructs are the multidimensional core',
  'A professional survey measures three to four ideas, each with its own set of items. This step is where you name each construct, assign items to it, and confirm the map the rest of the journey relies on.',
  $pillRow);

echo jr_placeholder('validity','03','Validity Readiness',
  'Are the items measuring the <em>right idea</em>?', 'target',
  'Validity readiness, before any data',
  'Seven judgment-based lenses check construct definition, dimension coverage, item-construct alignment, response options, dignity and access — so each item points at the construct it claims to.');

echo jr_placeholder('reliability','04','Reliability Readiness',
  'Will the scales <em>hold together</em>?', 'shield',
  'Reliability readiness, per construct',
  'Five lenses check scale structure, item clarity, response-scale consistency and redundancy — construct by construct — so each scale has enough clean items to be internally consistent once responses arrive.');

echo jr_placeholder('administration','05','Administration Readiness',
  'Is it <em>ready to field</em>?', 'check',
  'Administration readiness',
  'Respondent instructions, consent and privacy, fielding plan and timing, sensitive-topic safety, and completion burden — the operational checks that protect data quality at launch.');

echo jr_placeholder('launch','06','Launch Plan',
  'Make the <em>go decision</em>.', 'rocket',
  'From readiness to a launch plan',
  'The Launch Plan turns the readiness checks into a concrete go/no-go: outstanding blockers, the fielding window, the target sample, and the hand-off to RSSI once responses start arriving.');

include __DIR__ . '/apps/journey/_journey_foot.php';
