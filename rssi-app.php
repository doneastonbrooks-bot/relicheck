<?php
// rssi-app.php — Post-response evidence-journey app (RSSI) — step rail.
// Apple-like light shell, system font, RSSI = blue accent (data-accent="blue").
// All eight steps (00 Orientation → 07 Report) are wired to the real production
// RSSI calculation via journey-rssi.js (RSSI_TAG_CORE + RSSI_MATH). rssi-upload.php
// untouched. Official RSSI requires construct-tagged data; untagged datasets are
// scored as a whole-survey composite.

require_once __DIR__ . '/api/_db.php';
require_once __DIR__ . '/api/_session.php';

start_session_secure();
$uid = current_user_id();
if (!$uid) { header('Location: /login.html?return=' . urlencode('/rssi-app.php')); exit; }
$user = current_user();
if (!$user) { $_SESSION = []; session_destroy(); header('Location: /login.html'); exit; }
$initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $user['name'] ?? $user['email'] ?? 'U') ?: 'U', 0, 2));

$jr_page_title = 'RSSI · Post-response · ReliCheck';
$jr_accent     = 'blue';                 // RSSI brand = blue (SIRI stays teal)
$jr_brand_logo = '/RSSI-logo.png';       // ReliCheck · Survey Strength Index logo
$jr_brand_sub  = 'Survey Strength Index';
$jr_rail_label = 'After responses';
$jr_initials   = $initials;
$jr_ctx_label  = 'No dataset loaded';   // render() / sample mode overrides this
$jr_chip       = '';                     // hidden until a dataset is scored
$jr_foot       = 'RSSI tests whether the collected data supports the blueprint.';
$jr_exit_links = [
  ['label' => 'Go to Studios & Apps', 'href' => '/app-2026v4.php', 'icon' => 'layers'],
  ['label' => 'Go to RSSI Homepage',  'href' => '/rssi.php',       'icon' => 'gauge'],
];
$jr_steps = [
  ['id'=>'orientation','no'=>'00','label'=>'Orientation',     'sub'=>'How this works'],
  ['id'=>'score',      'no'=>'01','label'=>'Strength Score',  'sub'=>'Evidence strength'],
  ['id'=>'reliability','no'=>'02','label'=>'Reliability Lab', 'sub'=>'Items × α'],
  ['id'=>'validity',   'no'=>'03','label'=>'Validity Lab',    'sub'=>'Load as expected?'],
  ['id'=>'items',      'no'=>'04','label'=>'Item Analysis',   'sub'=>'Per-item health'],
  ['id'=>'quality',    'no'=>'05','label'=>'Data Quality',    'sub'=>'Who to keep'],
  ['id'=>'scenario',   'no'=>'06','label'=>'Scenario Builder','sub'=>'What-if'],
  ['id'=>'report',     'no'=>'07','label'=>'Report',          'sub'=>'What you can say'],
];

// Wire Strength Score (and later steps) to the locked four-domain engine.
// Loaded after journey.js by _journey_foot.php. rssi-engine.js = RSSIEngine.score()
// (the four-domain v1; do not change). journey-rssi.js loads a saved dataset via the
// read-only adapter and renders the real result; falls back to the mock when no
// ?dataset_id is present.
// The new app reuses the ORIGINAL production RSSI calculation, unchanged:
// RSSI_MATH.computeLensesFromDataset (strength-index.js) over a dataset built by
// RSSI_TAG_CORE.materializeDataset — byte-for-byte the same score rssi-upload.php
// produces. No formula change; this is a UI re-skin of the existing score.
$journey_scripts = [
  '/apps/strength-index/strength-index.js?v=' . (@filemtime(__DIR__ . '/apps/strength-index/strength-index.js') ?: time()),
  '/apps/rssi/rssi-tag-core.js?v='   . (@filemtime(__DIR__ . '/apps/rssi/rssi-tag-core.js')   ?: time()),
  '/apps/journey/journey-rssi.js?v=' . (@filemtime(__DIR__ . '/apps/journey/journey-rssi.js') ?: time()),
];

// Sample per-construct item rows for the Reliability Lab preview (static).
$LAB = [
  'Engagement' => [
    ['EN1 — I look forward to my work most days', .62, .85, 'good','Strengthens'],
    ['EN2 — I feel energized by what I do',        .68, .84, 'good','Strengthens'],
    ['EN3 — Time passes quickly when I work',      .59, .86, 'good','Strengthens'],
    ['EN4 — I would recommend this team',          .71, .83, 'good','Strengthens'],
    ['EN5 — I rarely think about quitting (R)',    .28, .90, 'warn','Weak item-total'],
  ],
];
$CONSTRUCTS = [
  ['name'=>'Engagement','items'=>5,'alpha'=>.88],
  ['name'=>'Manager Support','items'=>4,'alpha'=>.90],
  ['name'=>'Belonging','items'=>4,'alpha'=>.83],
  ['name'=>'Workload Balance','items'=>4,'alpha'=>.71],
];

include __DIR__ . '/apps/journey/_journey_head.php';
?>

<!-- ═════ 00 Orientation ═════ -->
<section class="jr-panel" data-step="orientation">
  <div class="jr-eyebrow"><span class="step-no">00</span> · Orientation</div>
  <h1 class="jr-title">Is this survey <em>strong enough to trust?</em></h1>
  <p class="jr-lede">RSSI reads a Likert survey and answers two questions in plain language, then shows the working underneath for anyone who wants it. You do not need a statistics background to start.</p>

  <!-- What's in this survey (wired to the loaded dataset; sample values until one loads) -->
  <div class="jr-grid jr-g4 rs-stats" id="rsOriStats">
    <div class="rs-stat"><div class="sv" id="rsOriResp">0</div><div class="sl">Responses</div></div>
    <div class="rs-stat"><div class="sv" id="rsOriItems">0</div><div class="sl">Likert items</div></div>
    <div class="rs-stat"><div class="sv" id="rsOriScales">0</div><div class="sl">Scales / constructs</div></div>
    <div class="rs-stat"><div class="sv" id="rsOriComplete">0<small>%</small></div><div class="sl">Completion</div></div>
  </div>

  <!-- Upload entry (shown when there is no ?dataset_id; hidden once a dataset loads) -->
  <div class="jr-card" id="rsUploadCard" style="margin-bottom:26px">
    <h2 class="jr-h2" style="margin-top:0;margin-bottom:6px">Start with your data</h2>
    <p style="font-size:14px;color:var(--ink-3);margin-bottom:16px">Upload a CSV of survey responses to score it in the four-domain RSSI. Your data is saved to your account and opens straight into this app, with no separate uploader.</p>
    <label id="rsDrop" class="rs-drop">
      <input type="file" id="rsFile" accept=".csv,text/csv" hidden>
      <span class="rs-drop-ic"><?= jr_icon('doc') ?></span>
      <span class="rs-drop-t">Drag &amp; drop a CSV, or click to choose</span>
      <span class="rs-drop-s">CSV &middot; up to 50,000 rows &middot; XLSX coming soon</span>
    </label>
    <div id="rsUploadStatus" class="rs-status" style="display:none"></div>
    <div style="margin-top:14px;font-size:13px"><a href="#" id="rsViewSample" style="color:var(--teal-deep);font-weight:600;text-decoration:none">View sample data instead &rarr;</a></div>
  </div>

  <div class="jr-grid jr-g2" style="margin-bottom:16px">
    <div class="jr-card q-card">
      <div class="q-eye">Question One</div>
      <h3 class="q-h">Reliability: is it consistent?</h3>
      <p>A reliable scale gives steady answers. If several questions are meant to measure the same idea, people who feel one way should answer all of them in a similar direction. When they do, you can add the items into one trustworthy score.</p>
      <p>Think of a bathroom scale that shows a different weight each time you step on. The readings are useless, not because the number is wrong, but because it will not hold still.</p>
      <details class="q-more">
        <summary><span class="pm"></span> For researchers</summary>
        <div class="more-body">Reliability here is internal consistency, estimated with Cronbach&rsquo;s <code>&alpha;</code>. The Reliability Lab also reports corrected item-total correlations and <code>&alpha;-if-deleted</code> so you can see each item&rsquo;s contribution, the same diagnostics SPSS produces under Scale &rsaquo; Reliability Analysis.</div>
      </details>
    </div>
    <div class="jr-card q-card">
      <div class="q-eye">Question Two</div>
      <h3 class="q-h">Validity: does it measure the right thing?</h3>
      <p>A valid survey measures what it claims to. A consistent set of questions can still measure the wrong idea, or quietly blend two ideas together. Validity checks whether your items group into the constructs you intended.</p>
      <p>A scale can be perfectly consistent and still weigh the wrong thing. Consistency without validity is precise nonsense.</p>
      <details class="q-more">
        <summary><span class="pm"></span> For researchers</summary>
        <div class="more-body">The Validity Lab runs an exploratory factor analysis (correlation matrix, eigenvalues, Kaiser criterion, varimax-rotated loadings) and a confirmatory check on constructs you define: average variance extracted, composite reliability, and the Fornell-Larcker discriminant test.</div>
      </details>
    </div>
  </div>

  <div class="jr-card q-card" style="margin-bottom:18px">
    <div class="q-eye">Why it matters</div>
    <h3 class="q-h">A weak instrument quietly corrupts every decision downstream</h3>
    <p style="margin-bottom:0">Budgets, policies, and program changes get justified with survey numbers. If the instrument is shaky, the conclusions inherit that shakiness, and nobody sees it in the final report. RSSI surfaces the problem before the results get used, so a fixable flaw stays fixable.</p>
  </div>

  <div class="jr-callout">
    <div class="ci"><?= jr_icon('info') ?></div>
    <div><p>Head to the <b>Strength Score</b> for the verdict, then open a <b>Lab</b> to see how it was reached.</p></div>
  </div>

  <div class="jr-actions">
    <a class="jr-btn" data-go="score">See the Strength Score <?= jr_icon('arrow') ?></a>
    <a class="jr-btn ghost" href="/app-2026v4.php">Back to ReliCheck</a>
  </div>
</section>

<!-- ═════ 01 Strength Score ═════ -->
<section class="jr-panel" data-step="score">
  <div class="jr-eyebrow"><span class="step-no">01</span> · Strength Score</div>
  <h1 class="jr-title">How strong is <em>this evidence?</em></h1>
  <p class="jr-lede">The ReliCheck Survey Strength Index scores your survey across eight dimensions and reads them through three weighted lenses, so you can see whether it holds up as a measurement tool.</p>
  <details class="jr-more-d">
    <summary>More details</summary>
    <p class="jr-more-body">RSSI works in two tiers. At the base, eight diagnostic dimensions each assess one facet of survey quality: Reliability, Validity, Construct Alignment, Item and Prompt Quality, Bias and Clarity Review, Scale Structure, Factor Readiness, and Response Scale Review. Above that base sit three interpretive lenses, each of which reweights the same eight scores for a different reader. The Psychometric Core lens emphasizes statistical stability and structure for the methodologist, the Respondent-Centered lens emphasizes clarity and answerability for the survey designer, and the Validity-Forward lens emphasizes validity and construct alignment for the reviewer who needs to confirm the instrument measures what it claims. Because all three lenses draw on one shared body of evidence, RSSI reports a single survey through three priorities rather than running three separate analyses.</p>
  </details>

  <div class="jr-hero rs-hero3" id="rsHero">
    <div class="rs-rings" id="rsLenses">
      <div class="rs-ringtile rs-flip" data-lk="psychometric_core">
        <div class="rs-flip-inner">
          <div class="rs-flip-face rs-flip-front">
            <div class="rs-ring"><svg viewBox="0 0 120 120"><circle class="bg" cx="60" cy="60" r="52"/><circle class="fg" data-f="ring" cx="60" cy="60" r="52" stroke-dasharray="326.73" stroke-dashoffset="326.73"/></svg><div class="val"><b data-f="num">0</b></div></div>
            <div class="rl">Psychometric Core</div>
            <div class="rd">Measurement-quality weighting</div>
            <span class="rs-flip-hint">&#8635; For researchers</span>
          </div>
          <div class="rs-flip-face rs-flip-back">
            <div class="rs-back-title">Psychometric Core</div>
            <p>The Psychometric Core lens scores the instrument as a measurement tool, weighting most heavily the dimensions that establish whether scores are stable and structurally sound. It draws primarily on reliability (internal consistency, or whether the items track the same underlying construct), factor readiness (whether the data support the intended factor structure and are suitable for factor analysis), and scale structure (how cleanly the items group into coherent scales). This lens matters because researchers who intend to publish, replicate, or defend their findings need evidence that the instrument produces consistent and structurally interpretable scores. When that foundation is weak, every downstream analysis inherits the instability.</p>
            <span class="rs-flip-hint">&#8635; Back</span>
          </div>
        </div>
      </div>
      <div class="rs-ringtile is-head rs-flip" data-lk="respondent_centered">
        <div class="rs-flip-inner">
          <div class="rs-flip-face rs-flip-front">
            <span class="rs-headbadge">Headline</span>
            <div class="rs-ring"><svg viewBox="0 0 120 120"><circle class="bg" cx="60" cy="60" r="52"/><circle class="fg" data-f="ring" cx="60" cy="60" r="52" stroke-dasharray="326.73" stroke-dashoffset="326.73"/></svg><div class="val"><b data-f="num">0</b></div></div>
            <span class="jr-band" id="rsBand"><?= jr_icon('check') ?> <span id="rsBandText">&mdash;</span></span>
            <div class="rl">Respondent-Centered</div>
            <div class="rd">What respondents experienced</div>
            <span class="rs-flip-hint">&#8635; For researchers</span>
          </div>
          <div class="rs-flip-face rs-flip-back">
            <div class="rs-back-title">Respondent-Centered</div>
            <p>The Respondent-Centered lens scores the survey from the position of the person answering it, weighting the dimensions that shape comprehension and response quality. It draws primarily on item and prompt quality (whether questions are clear, single-barreled, and answerable), response scale review (whether the answer options are balanced, exhaustive, and easy to use), and bias and clarity review (whether the wording introduces leading or confusing language). This lens matters because data quality begins at the point of response. When items are ambiguous or scales are awkward, respondents guess, satisfice, or abandon the survey, and the resulting measurement error cannot be fully corrected after the fact.</p>
            <span class="rs-flip-hint">&#8635; Back</span>
          </div>
        </div>
      </div>
      <div class="rs-ringtile rs-flip" data-lk="validity_forward">
        <div class="rs-flip-inner">
          <div class="rs-flip-face rs-flip-front">
            <div class="rs-ring"><svg viewBox="0 0 120 120"><circle class="bg" cx="60" cy="60" r="52"/><circle class="fg" data-f="ring" cx="60" cy="60" r="52" stroke-dasharray="326.73" stroke-dashoffset="326.73"/></svg><div class="val"><b data-f="num">0</b></div></div>
            <div class="rl">Validity-Forward</div>
            <div class="rd">Whether items measure the intended idea</div>
            <span class="rs-flip-hint">&#8635; For researchers</span>
          </div>
          <div class="rs-flip-face rs-flip-back">
            <div class="rs-back-title">Validity-Forward</div>
            <p>The Validity-Forward lens scores whether the instrument measures what it claims to measure, weighting the dimensions that link items to their intended constructs. It draws primarily on validity (whether the available evidence supports the intended interpretation of the scores) and construct alignment (whether each item maps clearly onto the construct it is meant to represent). This lens matters because reliability and clean response data are necessary but not sufficient. An instrument can be highly consistent and still measure the wrong thing, so validity is what justifies the inferences a researcher ultimately wants to draw.</p>
            <span class="rs-flip-hint">&#8635; Back</span>
          </div>
        </div>
      </div>
    </div>
    <div class="jr-hero-body">
      <h3 id="rsHeroHead">&mdash;</h3>
      <p id="rsHeroSub">Open a saved dataset to score it.</p>
      <div class="jr-hero-meta"><span>Responses <b id="rsMetaResp">0</b></span><span>Constructs <b id="rsMetaCon">0</b></span><span>Scored <b id="rsMetaWhen">&mdash;</b></span></div>
    </div>
  </div>

  <div class="jr-callout" id="rsFence" style="display:none"><div class="ci"><?= jr_icon('info') ?></div><div><h4>Sample-size fence</h4><p id="rsFenceText"></p></div></div>

  <h2 class="jr-h2">The eight diagnostic domains</h2>
  <div class="jr-grid jr-g4" id="rsDomains">
    <div class="jr-dom" data-dk="reliability"><div class="dh"><span class="dn">Reliability</span><span class="dv"><span data-f="pts">0</span><small> / <span data-f="max">100</span></small></span></div><div class="jr-meter"><span data-f="bar" style="width:0%"></span></div><div class="de">Items within each scale agree (Cronbach&rsquo;s &alpha;).</div></div>
    <div class="jr-dom" data-dk="item_prompt_quality"><div class="dh"><span class="dn">Item / Prompt Quality</span><span class="dv"><span data-f="pts">0</span><small> / <span data-f="max">100</span></small></span></div><div class="jr-meter"><span data-f="bar" style="width:0%"></span></div><div class="de">Questions are clear, single-barreled, well phrased.</div></div>
    <div class="jr-dom" data-dk="bias_clarity"><div class="dh"><span class="dn">Bias &amp; Clarity Review</span><span class="dv"><span data-f="pts">0</span><small> / <span data-f="max">100</span></small></span></div><div class="jr-meter"><span data-f="bar" style="width:0%"></span></div><div class="de">Few leading, loaded, or ambiguous wordings.</div></div>
    <div class="jr-dom" data-dk="factor_readiness"><div class="dh"><span class="dn">Factor Readiness</span><span class="dv"><span data-f="pts">0</span><small> / <span data-f="max">100</span></small></span></div><div class="jr-meter"><span data-f="bar" style="width:0%"></span></div><div class="de">Data is ready to support a factor structure.</div></div>
    <div class="jr-dom" data-dk="response_scale_review"><div class="dh"><span class="dn">Response Scale Review</span><span class="dv"><span data-f="pts">0</span><small> / <span data-f="max">100</span></small></span></div><div class="jr-meter"><span data-f="bar" style="width:0%"></span></div><div class="de">Scales are balanced with sensible point counts.</div></div>
    <div class="jr-dom is-na" data-dk="validity"><div class="dh"><span class="dn">Validity</span><span class="dv"><span data-f="pts">&mdash;</span><small style="display:none"> / <span data-f="max">100</span></small></span></div><div class="jr-meter"><span data-f="bar" style="width:0%"></span></div><div class="de">Needs construct tags or criteria to evaluate.</div></div>
    <div class="jr-dom is-na" data-dk="construct_alignment"><div class="dh"><span class="dn">Construct Alignment</span><span class="dv"><span data-f="pts">&mdash;</span><small style="display:none"> / <span data-f="max">100</span></small></span></div><div class="jr-meter"><span data-f="bar" style="width:0%"></span></div><div class="de">Do items group into the constructs you intended? (EFA)</div></div>
    <div class="jr-dom is-na" data-dk="scale_structure"><div class="dh"><span class="dn">Scale Structure</span><span class="dv"><span data-f="pts">&mdash;</span><small style="display:none"> / <span data-f="max">100</span></small></span></div><div class="jr-meter"><span data-f="bar" style="width:0%"></span></div><div class="de">Needs tagged scales to assess structure.</div></div>
  </div>

  <details class="q-more" style="margin-top:18px">
    <summary><span class="pm"></span> How these combine</summary>
    <div class="more-body">The eight domains never collapse to one weighted formula. They roll up three ways: <b>Psychometric Core</b>, <b>Respondent-Centered</b> (the headline), and <b>Validity-Forward</b>, each a different weighting of the same domains. Every sub-score is computed from the live data, so the numbers move when the instrument changes or flagged respondents are excluded. Each figure traces back to something you can toggle in a Lab and watch update.</div>
  </details>

  <p class="rs-infer-note"><?= jr_icon('info') ?> <span>A strong score means the items work well together as a measurement tool. It does not prove that every conclusion drawn from the survey is correct, and a consistent survey can still measure the wrong thing. Read this score alongside the Validity Lab and Item Analysis, not on its own.</span></p>

  <div class="jr-actions">
    <a class="jr-btn" data-go="reliability">Open the Reliability Lab <?= jr_icon('arrow') ?></a>
    <a class="jr-btn ghost" data-go="validity">Validity Lab</a>
  </div>
</section>

<?php
// Three-zone lab steps (controls / workspace / effect). Mockup — sample data.
$check = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
$LOAD = [ // [label, [EN,MS,BE,WL], assignedIdx, crossLoadIdx|null]
  ['EN2 — I feel energized by what I do', [.79,.12,.18,.05], 0, null],
  ['MS1 — My manager backs me up',        [.10,.82,.14,.08], 1, null],
  ['BE1 — I fit in on this team',         [.15,.11,.76,.09], 2, null],
  ['BE3 — My ideas get heard here',       [.41,.09,.55,.07], 2, 0],
  ['WL2 — My workload is manageable',     [.06,.10,.12,.68], 3, null],
];
$RESP = [ // [id, pattern, flag, status]
  ['R-0142','Varied answers','—','keep'],
  ['R-0188','All 5s · straight-line','Straight-liner','drop'],
  ['R-0203','Varied answers','—','keep'],
  ['R-0219','Finished in 11s','Speeder','drop'],
  ['R-0244','Self-contradictory','Inconsistent','drop'],
  ['R-0251','Varied answers','—','keep'],
];
?>

<!-- ═════ 02 Reliability Lab — live Cronbach's α ═════ -->
<section class="jr-panel" data-step="reliability">
  <div class="jr-eyebrow"><span class="step-no">02</span> · Reliability Lab</div>
  <h1 class="jr-title">How consistent is <em>this scale?</em></h1>
  <p class="jr-lede">Check whether the items in a scale are consistent enough to be read together as one score, and see how each item affects that.</p>

  <details class="q-more" style="margin-bottom:20px">
    <summary>More details: what Cronbach&rsquo;s &alpha; is and how to use it</summary>
    <div class="more-body">
      <p class="more-h">What is Cronbach&rsquo;s &alpha;?</p>
      <p>Cronbach&rsquo;s &alpha;, or alpha, is a reliability estimate. It helps you understand whether a group of survey items are working together as one scale.</p>
      <p>In plain language, Cronbach&rsquo;s &alpha; asks: are these items consistent enough to be interpreted together?</p>
      <p>For example, if six items are supposed to measure <b>Self-Awareness</b>, Cronbach&rsquo;s &alpha; checks whether responses to those six items tend to move together. If people who score high on one item also tend to score high on the others, the scale may have stronger internal consistency.</p>
      <p>Cronbach&rsquo;s &alpha; does not prove that the scale is valid. It does not prove that the items measure the right construct. It only tells you whether the items are statistically consistent with one another.</p>
      <p>A high alpha can be useful, but it is not enough by itself. A scale can be internally consistent and still measure the wrong thing, repeat the same idea too many times, or miss important parts of the construct.</p>
      <p class="more-h">How to use Cronbach&rsquo;s &alpha;</p>
      <p>Start by looking at the alpha value for the selected scale. Higher values generally suggest stronger internal consistency, but the number should always be interpreted with the purpose of the scale in mind.</p>
      <p>Use Cronbach&rsquo;s &alpha; to ask whether the items are working together well enough to support interpretation. General guidance:</p>
      <ul class="val-guide">
        <li><b>Below .60</b> may indicate weak internal consistency.</li>
        <li><b>.60 to .69</b> may be questionable and should be reviewed.</li>
        <li><b>.70 to .79</b> is often considered acceptable.</li>
        <li><b>.80 to .89</b> is usually strong.</li>
        <li><b>.90 or higher</b> may be strong, but it can also mean some items are repetitive.</li>
      </ul>
      <p>Next, review the item-level evidence. Look at <b>Item-Total r</b> and <b>Alpha if Removed</b>. If an item has a weak or negative item-total relationship, or if alpha increases when the item is removed, the item may need review.</p>
      <p>Do not remove items only to raise alpha. Before removing an item, ask whether it is important to the meaning of the construct. Sometimes removing an item makes the scale look more reliable while making it less complete.</p>
      <p>Cronbach&rsquo;s &alpha; should be used as one piece of evidence inside RSSI, alongside validity, item analysis, data quality, and interpretability.</p>
    </div>
  </details>

  <div class="rel-grid">
    <div class="rel-scale" id="relScaleZone">
      <div class="rel-zone-head"><span>Items in the scale</span><span class="rel-count" id="relInCount">0</span></div>
      <details class="q-more" style="margin:0 0 16px">
        <summary>More details: what Items in the Scale is and how to use it</summary>
        <div class="more-body">
          <p class="more-h">What are Items in the Scale?</p>
          <p>Items in the Scale shows the survey questions that are currently grouped together under one construct or scale.</p>
          <p>A <b>scale</b> is a set of items that are interpreted together. For example, if five questions are designed to measure <b>Belonging</b>, those five questions form the Belonging scale.</p>
          <p>This section helps you see exactly which items are being used to calculate evidence for the selected construct. It is important because reliability, validity, item analysis, and RSSI scoring all depend on which items are included in the scale.</p>
          <p>In plain language, this section asks: are these the right items to interpret together?</p>
          <p>If the wrong items are included, the scale may look stronger or weaker than it really is. If important items are missing, the scale may not fully represent the construct.</p>
          <p class="more-h">How to use Items in the Scale</p>
          <p>Start by reviewing the list of items assigned to the selected scale. Make sure each item belongs conceptually with the construct you are trying to measure. Ask these questions:</p>
          <ul class="val-guide">
            <li>Does each item match the meaning of the construct?</li>
            <li>Are any items measuring a different idea?</li>
            <li>Are any items worded in the opposite direction and needing reverse-coding?</li>
            <li>Are any items duplicated or too similar?</li>
            <li>Are any important parts of the construct missing?</li>
            <li>Would removing an item make the scale narrower or less meaningful?</li>
          </ul>
          <p>Use this section before interpreting reliability or validity results. A strong Cronbach&rsquo;s alpha is only useful if the right items are grouped together. A factor loading is only meaningful if the item assignment makes conceptual sense.</p>
          <p>Do not remove or reassign items only to improve the numbers. The scale should remain meaningful, defensible, and aligned with the purpose of the survey.</p>
          <p class="more-h">How to use this table</p>
          <p>Use this table to review the items that are currently included in the selected scale or construct.</p>
          <p>Start by reading each item carefully. Ask whether the item actually belongs with the construct you are trying to measure. For example, if the selected scale is <b>Belonging</b>, each item should clearly connect to belonging, not a different idea such as satisfaction, confidence, or support.</p>
          <p>Next, review the item evidence shown in the table. Look for items that may be weakening the scale, behaving differently from the other items, or creating interpretation problems. Pay attention to:</p>
          <ul class="val-guide">
            <li><b>Item wording:</b> does the item clearly match the construct?</li>
            <li><b>Item-total relationship:</b> does the item move with the rest of the scale?</li>
            <li><b>Reliability impact:</b> would reliability improve or decline if the item were removed?</li>
            <li><b>Response pattern:</b> did respondents answer the item in a useful way?</li>
            <li><b>Missing data:</b> did too many people skip the item?</li>
            <li><b>Reverse coding:</b> is the item worded in the opposite direction and needing correction?</li>
            <li><b>Construct fit:</b> does the item still represent the meaning of the scale?</li>
          </ul>
          <p>Use the table to make careful decisions:</p>
          <ul class="val-guide">
            <li><b>Keep</b> the item if it fits the construct and supports interpretation.</li>
            <li><b>Review</b> the item if the evidence is weak, unclear, or inconsistent.</li>
            <li><b>Flag</b> the item if it may need attention but should remain in the current analysis.</li>
            <li><b>Remove in scenario</b> only if there is a clear reason and the scale still represents the construct without it.</li>
            <li><b>Revise for future use</b> if the item wording should be improved before the next survey.</li>
          </ul>
          <p>Do not remove items only to improve the score or raise reliability. A scale can look statistically cleaner but become less meaningful if important items are removed. The goal is to keep the scale trustworthy, explainable, and defensible.</p>
        </div>
      </details>
      <table class="jr-tbl rel-tbl"><thead><tr><th>Item</th><th>Item-total r</th><th>&alpha; if removed</th><th>Signal</th><th aria-label="action"></th></tr></thead>
        <tbody id="relTableBody">
          <tr><td colspan="5" class="rel-placeholder">Open a saved dataset to run the Reliability Lab on its items.</td></tr>
        </tbody>
      </table>
    </div>

    <div class="rel-side">
      <!-- Reliability-only summary (sticky). No RSSI / Strength Score numbers. -->
      <div class="rel-summary">
        <div class="rel-alpha-num" id="relAlpha">&mdash;</div>
        <div class="rel-alpha-lab">Cronbach&rsquo;s &alpha; &middot; <span id="relBandText">&mdash;</span></div>
        <span class="jr-band" id="relBand" style="margin:10px auto 0">&mdash;</span>
        <div class="rel-sum-rows">
          <div class="rs-row"><span>Original &alpha;</span><b id="relOrig">&mdash;</b></div>
          <div class="rs-row"><span>Change</span><b id="relDelta" class="neutral">&mdash;</b></div>
          <div class="rs-row"><span>Items kept</span><b id="relKept">0</b></div>
          <div class="rs-row"><span>Removed</span><b id="relRemCount">0</b></div>
          <div class="rs-row"><span>Avg inter-item r</span><b id="relAvgR">&mdash;</b></div>
        </div>
        <p class="rel-note-sm"><?= jr_icon('info') ?> Reliability only. Your RSSI score is unaffected.</p>
        <button type="button" class="rel-act-btn rel-save-btn" id="relSaveDs" disabled>Save trimmed dataset</button>
        <p class="rel-save-hint">Saves the items still in the scale, with every other column kept, as a new dataset. Your original stays untouched.</p>
        <div class="rel-save-status" id="relSaveStatus" style="display:none"></div>
      </div>

      <div class="rel-removed">
        <div class="rel-zone-head"><span>Removed from scale</span><span class="rel-count" id="relOutCount">0</span></div>
        <div class="rel-drop" id="relRemovedZone">
          <div class="rel-drop-empty" id="relEmpty">Items you remove from the scale appear here. Press Return to put one back.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="jr-actions"><a class="jr-btn" data-go="validity">Open the Validity Lab <?= jr_icon('arrow') ?></a><a class="jr-btn ghost" data-go="score">Back to score</a></div>

  <!-- Save-as-new-dataset modal: collects a title + description before saving the trimmed scale. -->
  <div class="jr-modal-overlay" id="relSaveModal" style="display:none" aria-hidden="true">
    <div class="jr-modal" role="dialog" aria-modal="true" aria-labelledby="relModalTitle">
      <h3 id="relModalTitle">Save trimmed dataset</h3>
      <p class="jr-modal-sub">Saves the items still in the scale, with every other column kept, as a new dataset. Your original stays untouched.</p>
      <label class="jr-modal-label" for="relDsTitle">Dataset title</label>
      <input type="text" id="relDsTitle" class="jr-modal-input" maxlength="255" placeholder="e.g. Team Climate Pulse (trimmed)">
      <label class="jr-modal-label" for="relDsDesc">Description <span class="jr-modal-opt">(optional)</span></label>
      <textarea id="relDsDesc" class="jr-modal-input jr-modal-textarea" maxlength="1000" rows="3" placeholder="What changed and why, e.g. removed 3 reverse-coded items that lowered alpha."></textarea>
      <div class="jr-modal-status" id="relModalStatus" style="display:none"></div>
      <div class="jr-modal-actions">
        <button type="button" class="jr-btn ghost" id="relSaveCancel">Cancel</button>
        <button type="button" class="jr-btn" id="relSaveConfirm">Save dataset</button>
      </div>
    </div>
  </div>
</section>

<!-- ═════ 03 Validity Lab — three-zone ═════ -->
<section class="jr-panel" data-step="validity">
  <div class="jr-eyebrow"><span class="step-no">03</span> · Validity Lab</div>
  <h1 class="jr-title">Do the items group <em>as intended?</em></h1>
  <p class="jr-lede">When you open this lab, RSSI runs an exploratory factor analysis on your data. It studies how the answers move together to find the natural groupings of items, before you tell it what you expected. Then you can name your own constructs and confirm whether the data backs them.</p>

  <details class="q-more" style="margin-bottom:20px">
    <summary>More details: what validity is and how to use it</summary>
    <div class="more-body">
      <p class="more-h">What is validity?</p>
      <p>Validity asks whether the survey evidence supports the meaning you want to give the results.</p>
      <p>In plain language, validity asks: are these items measuring what they are supposed to measure?</p>
      <p>For example, if a group of items is supposed to measure <b>Belonging</b>, the validity section helps you examine whether those items actually behave like they belong together and whether they are distinct from other constructs in the survey.</p>
      <p>Validity is different from reliability. A scale can be reliable but still not valid. That means the items may be consistent with each other, but they may not measure the construct you think they measure.</p>
      <p>In RSSI, validity focuses on whether items are connected to the right construct, whether the factor pattern makes sense, and whether any items appear to cross over into more than one construct.</p>
      <p>Validity does not prove that a survey is perfect. It gives you evidence to decide whether the structure of the survey is strong enough to interpret, explain, and defend.</p>
      <p class="more-h">How to use validity</p>
      <p>Start by reviewing the item-to-construct structure. Make sure each item is assigned to the construct it was intended to measure.</p>
      <p>Next, review the factor loading table. Factor loadings show how strongly each item connects to each factor or construct. Stronger loadings usually mean the item fits that construct better. Weak loadings may suggest the item is unclear, poorly aligned, or not measuring the intended idea.</p>
      <p>Then look for cross-loadings. A cross-loading happens when an item appears to connect with more than one construct. This does not automatically mean the item is bad, but it does mean the item needs review. The item may be too broad, double-barreled, or connected to more than one idea.</p>
      <p>Use the validity section to make careful decisions:</p>
      <ul class="val-guide">
        <li><b>Keep</b> the item if it clearly supports the intended construct.</li>
        <li><b>Review</b> the item if the loading is weak or unclear.</li>
        <li><b>Reassign</b> the item if the evidence suggests it fits a different construct.</li>
        <li><b>Flag</b> the item if it may cross-load or confuse the meaning of the scale.</li>
        <li><b>Revise for future use</b> if the item should be improved before the next survey.</li>
      </ul>
      <p>Do not move or remove an item only because one number looks better. Ask whether the decision makes conceptual sense. A stronger validity structure should improve the evidence without changing the meaning of the survey.</p>
    </div>
  </details>

  <!-- ───── Two-column workspace: stacked evidence cards (left) | loadings table (right) ───── -->
  <div class="rssi-validity-workspace">
    <aside class="rssi-validity-left">

      <!-- Exploratory Factor Analysis (EFA) -->
      <section class="rssi-card rssi-efa-card">
        <div class="val-efa-eyebrow">Exploratory Factor Analysis (EFA)</div>
        <div class="val-efa-stat"><b id="valFactors">&mdash;</b><span>factors found<br><small>eigenvalue &gt; 1 (Kaiser)</small></span></div>
        <div class="val-efa-rows">
          <div class="vr"><span>Sampling adequacy (KMO)</span><b id="valKMO">&mdash;</b></div>
          <div class="vr"><span>Bartlett&rsquo;s test</span><b id="valBartlett">&mdash;</b></div>
          <div class="vr"><span>Items analyzed</span><b id="valNItems">&mdash;</b></div>
        </div>
        <details class="q-more" style="margin-top:14px">
          <summary>More details: what EFA is and how to use it</summary>
          <div class="more-body">
            <p class="more-h">What is Exploratory Factor Analysis?</p>
            <p>Exploratory Factor Analysis, or EFA, helps you discover how survey items naturally group together.</p>
            <p>In plain language, EFA asks: do the items in this survey appear to form meaningful groups?</p>
            <p>For example, you may have written items to measure <b>Belonging</b>, <b>Trust</b>, and <b>Support</b>. EFA helps examine whether the response patterns actually organize that way, or whether the items group differently than expected.</p>
            <p>EFA is useful when you are still exploring the structure of a survey. It can help you see whether items belong together, whether some items do not fit well, and whether the survey may have more or fewer constructs than originally planned.</p>
            <p>EFA does not prove the final structure of the survey. It gives you evidence to review. The results should be interpreted alongside the meaning of the items, the purpose of the survey, and the constructs you intended to measure.</p>
            <p class="more-h">How to use EFA</p>
            <p>Start by reviewing how many factors ReliCheck identifies or suggests. A factor is a group of items that appear to move together in the data.</p>
            <p>Next, look at which items load onto each factor. Items with stronger loadings on the same factor may be measuring a similar idea. Items with weak loadings, unclear patterns, or cross-loadings may need review.</p>
            <p>Use EFA to ask:</p>
            <ul class="val-guide">
              <li>Do the item groups make conceptual sense?</li>
              <li>Do the factors match the constructs I expected?</li>
              <li>Are any items loading on the wrong factor?</li>
              <li>Are any items weakly connected to all factors?</li>
              <li>Are any items connected to more than one factor?</li>
              <li>Do I need to rename, revise, split, combine, or review constructs?</li>
            </ul>
            <p>Do not accept the EFA structure automatically. EFA is a discovery tool, not a final decision-maker. The strongest factor solution should make both statistical sense and conceptual sense.</p>
            <p>Use the EFA evidence to guide item assignment, construct mapping, and validity review before calculating or interpreting the official RSSI.</p>
          </div>
        </details>
      </section>

      <!-- Build Your Constructs -->
      <section class="rssi-card rssi-construct-card" id="valBuilder">
        <div class="rel-zone-head"><span>Build your constructs</span><span class="rel-count" id="valConCount">0</span></div>
        <p class="val-builder-hint">You decide the scales. Add a construct, name it, and assign its items with the “Assign to” column on the right. The CFA below tests exactly what you build.</p>
        <div class="val-builder-btns">
          <button type="button" class="rel-act-btn val-btn-primary" id="valAddCon">+ Add construct</button>
        </div>
        <div id="valConList"></div>
      </section>

      <!-- Confirm Your Model (CFA) -->
      <section class="rssi-card rssi-cfa-card">
        <div class="val-efa-eyebrow">Confirmatory Factor Analysis (CFA) <span class="rel-count" id="valCfaFit">&mdash;</span></div>
        <p class="val-builder-hint">A confirmatory factor analysis of the constructs you built. It tests whether the data backs your groupings; nothing here is suggested.</p>
        <details class="q-more" style="margin-bottom:14px">
          <summary>More details: what CFA is and how to use it</summary>
          <div class="more-body">
            <p class="more-h">What is Confirmatory Factor Analysis?</p>
            <p>Confirmatory Factor Analysis, or CFA, helps you test whether your survey items fit the construct structure you expected.</p>
            <p>In plain language, CFA asks: does the survey structure match the model I intended?</p>
            <p>For example, if you designed a survey with three constructs &mdash; <b>Belonging</b>, <b>Trust</b>, and <b>Support</b> &mdash; CFA helps examine whether the items assigned to each construct actually support that structure.</p>
            <p>CFA is different from EFA. EFA helps you explore possible item groupings. CFA helps you confirm whether a specific structure fits the data well enough to use.</p>
            <p>CFA does not prove that the survey is perfect. It gives you evidence about whether the proposed construct model is strong enough to interpret, explain, and defend.</p>
            <p class="more-h">How to use CFA</p>
            <p>Start by reviewing the construct model. Make sure each item is assigned to the construct it was intended to measure.</p>
            <p>Next, review the CFA results or model-fit indicators. These results help you judge whether the proposed structure fits the response data. Stronger fit means the item assignments and construct structure are more defensible. Weak fit means the model may need review.</p>
            <p>Use CFA to ask:</p>
            <ul class="val-guide">
              <li>Do the items fit the constructs I assigned them to?</li>
              <li>Does the overall model fit the data well enough?</li>
              <li>Are any items weakening the model?</li>
              <li>Are any constructs too similar to each other?</li>
              <li>Are any items better explained by another construct?</li>
              <li>Does the model still match the purpose of the survey?</li>
            </ul>
            <p>Do not use CFA as a mechanical pass/fail test. A model can look statistically acceptable but still be conceptually weak. A model can also have imperfect fit but still be useful if the construct decisions are clear and defensible.</p>
            <p>Use CFA alongside EFA, factor loadings, item analysis, reliability, and data quality before interpreting the official RSSI.</p>
            <p class="more-h">What each number means</p>
            <ul class="val-guide">
              <li><b>&alpha; (internal consistency)</b> &mdash; do the items in a construct agree? Aim for <b>.80+</b>. Low or negative means they do not belong together (often a reverse-coded item).</li>
              <li><b>AVE</b> &mdash; how much of each item its construct explains. Aim for <b>.50+</b>.</li>
              <li><b>CR (composite reliability)</b> &mdash; the construct&rsquo;s model reliability. Aim for <b>.70+</b>.</li>
              <li><b>CFI (fit)</b> &mdash; how well your grouping matches the data. Aim for <b>.95+</b>.</li>
              <li><b>RMSEA (error)</b> &mdash; lower is better; aim for <b>.06 or less</b>. Needs 3+ items.</li>
              <li><b>HTMT (discriminant)</b> &mdash; are two constructs distinct? <b>Below .85</b> means yes.</li>
            </ul>
            <p>If a construct scores poorly, move or drop its weak items in the table and watch these update.</p>
          </div>
        </details>
        <div id="valCfa" class="val-cfa">
          <div id="valCfaEmpty" class="val-con-empty">Build at least one construct with 2 or more items to run the CFA.</div>
          <div id="valCfaResults" style="display:none">
            <p class="val-cross-summary" id="valCfaNote"></p>
            <div class="val-tbl-wrap">
              <table class="jr-tbl val-cfa-tbl">
                <thead><tr><th>Construct</th><th>Items</th><th>&alpha;</th><th>AVE</th><th>CR</th><th>CFI</th><th>RMSEA</th></tr></thead>
                <tbody id="valCfaBody"></tbody>
              </table>
            </div>
            <div class="val-htmt-head">Discriminant validity (HTMT) &mdash; distinct below 0.85</div>
            <div id="valHtmt"></div>
          </div>
        </div>
        <button type="button" class="rel-act-btn val-btn-primary val-export-btn" id="valExport" style="margin-top:14px">Export report (PDF)</button>
        <p class="val-builder-hint">Saves your factor loadings, constructs, and CFA results as a printable report you can save as a PDF.</p>
      </section>

      <!-- ReliCheck Intelligence -->
      <section class="rssi-card rssi-intelligence-card">
        <div class="rel-zone-head"><span>&#10022; ReliCheck Intelligence</span></div>
        <p class="val-builder-hint">A second opinion: it reads your item wording and proposes named constructs. Review them, then apply to your model or keep your own.</p>
        <button type="button" class="rel-act-btn val-btn-ai" id="valAI">&#10022; Suggest clusters</button>
        <div id="valAIStatus" class="val-ai-status" style="display:none;margin-top:12px"></div>
        <div id="valAIResults" style="display:none;margin-top:16px">
          <div id="valAICards" class="val-ai-cards"></div>
          <button type="button" class="rel-act-btn val-btn-primary" id="valAIApply" style="margin-top:6px">Apply this to my model</button>
        </div>
      </section>

    </aside>

    <main class="rssi-validity-main">
      <section class="rssi-card rssi-loading-table-card">
        <div class="rel-zone-head rssi-table-head"><span>Factor loadings &amp; item assignment</span><span class="rel-count" id="valLoadCount">&mdash;</span></div>
        <p class="val-cross-summary rssi-table-sub" id="valCrossSummary">The EFA runs as soon as a dataset loads. Use the “Assign to” column to put each item into one of your constructs.</p>
        <div class="val-legend rssi-table-legend">
          <span class="lg"><i class="sw sw-hi"></i> Item&rsquo;s main factor</span>
          <span class="lg"><i class="sw sw-cross"></i> Also loads 0.40 or higher (cross-loader)</span>
        </div>
        <details class="q-more" style="margin:0 22px 16px">
          <summary>More details: what Factor Loadings &amp; Item Assignment is and how to use it</summary>
          <div class="more-body">
            <p class="more-h">What is this table?</p>
            <p>The <b>Factor Loadings &amp; Item Assignment</b> table helps you decide which construct each survey item belongs to.</p>
            <p>Each row is one survey item. Each factor column shows how strongly that item connects to a possible construct. The stronger the loading, the more closely the item appears to relate to that factor.</p>
            <p>The <b>Assign to</b> column is where you connect the item to the construct it should represent in the RSSI calculation.</p>
            <p>This table matters because RSSI is construct-first. ReliCheck cannot calculate the official Survey Strength Index until items are assigned to constructs. Without item-to-construct assignments, ReliCheck can only show a whole-survey diagnostic, not the official RSSI.</p>
            <p>Factor loadings provide evidence, but they do not make the decision for you. The best assignment should make statistical sense and conceptual sense.</p>
            <p class="more-h">How to use this table</p>
            <p>Start by reading one item row at a time. Look across the factor loading columns and identify where the item has its strongest loading.</p>
            <p>A stronger loading usually means the item is more closely connected to that factor. For example, if an item loads highest on Factor 2, the item may belong with the construct represented by Factor 2.</p>
            <p>Next, compare the loading pattern to the meaning of the item. Do not assign an item based only on the highest number. Ask whether the item wording actually fits the construct.</p>
            <p>Then look for warning signs:</p>
            <ul class="val-guide">
              <li>A <b>weak loading</b> may mean the item does not clearly fit any construct.</li>
              <li>A <b>cross-loading</b> may mean the item connects to more than one construct.</li>
              <li>A <b>mismatched loading</b> may mean the item was intended for one construct but behaves like it belongs somewhere else.</li>
              <li>An <b>unclear item</b> may need to be flagged instead of assigned too quickly.</li>
            </ul>
            <p>Use the <b>Assign to</b> dropdown to choose the construct that the item should belong to. Assign the item when the loading pattern and the item meaning agree.</p>
            <p>If the evidence is unclear, flag the item for review. Leaving an item unassigned may be better than forcing it into the wrong construct.</p>
            <p>After the items are assigned, ReliCheck can evaluate construct-level reliability, validity, item performance, response quality, and score interpretability.</p>
          </div>
        </details>
        <div class="rssi-loading-table-wrap">
          <table class="jr-tbl val-tbl" id="valLoadTbl">
            <thead id="valLoadHead"><tr><th>Item</th><th>F1</th></tr></thead>
            <tbody id="valLoadBody"><tr><td colspan="2" class="rel-placeholder">Open a saved dataset to run the EFA on its items.</td></tr></tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <div class="jr-actions"><a class="jr-btn" data-go="items">Open Item Analysis <?= jr_icon('arrow') ?></a><a class="jr-btn ghost" data-go="reliability">Back to Reliability Lab</a></div>
</section>

<?php
// [label, construct, mean, sd, missPct, dist[5], sigClass, sigText, selected]
$ITEMS = [
  ['EN2 — I feel energized by what I do','Engagement',3.9,0.92,1,[3,8,22,40,27],'good','Healthy',0],
  ['EN5_R — I rarely think about quitting','Engagement',2.4,1.31,2,[28,24,20,16,12],'warn','Reverse · weak',0],
  ['MS1 — My manager backs me up','Manager Support',4.1,0.80,0,[2,5,15,38,40],'good','Healthy',0],
  ['BE3 — My ideas get heard here','Belonging',3.6,1.04,1,[6,12,26,34,22],'warn','Cross-loads',1],
  ['WL2 — My workload is manageable','Workload Balance',2.9,1.12,3,[14,24,28,22,12],'good','Healthy',0],
  ['WL4 — I can usually finish on time','Workload Balance',4.6,0.52,1,[1,2,6,18,73],'warn','Ceiling',0],
  ['BE2 — People here have my back','Belonging',3.8,0.88,1,[3,7,21,40,29],'good','Healthy',0],
];
?>

<!-- ═════ 04 Item Analysis — three-zone ═════ -->
<section class="jr-panel" data-step="items">
  <div class="jr-eyebrow"><span class="step-no">04</span> · Item Analysis</div>
  <h1 class="jr-title">Inspect <em>every item</em>.</h1>
  <p class="jr-lede">Review each survey question on its own to see which items are working and which need a closer look.</p>

  <details class="q-more" style="margin-bottom:20px">
    <summary>More details: what item analysis is and how to use it</summary>
    <div class="more-body">
      <p class="more-h">What is item analysis?</p>
      <p>Item analysis helps you review each survey question individually. It shows whether an item is working well, confusing respondents, producing useful variation, or creating problems in the data.</p>
      <p>A survey can have a strong overall score and still contain weak items. Item analysis helps you find those weak spots before you interpret the results too confidently.</p>
      <p>In RSSI, item analysis focuses on practical questions:</p>
      <ul class="val-guide">
        <li>Did people answer this item consistently?</li>
        <li>Did the item produce enough variation to be useful?</li>
        <li>Did too many people skip it?</li>
        <li>Did most people choose the same answer?</li>
        <li>Does the item appear to behave differently from the rest of the scale?</li>
        <li>Should the item be kept, revised, flagged, or removed from a scenario?</li>
      </ul>
      <p>Item analysis does not automatically decide whether an item is good or bad. It gives you evidence so you can make a defensible decision.</p>
      <p class="more-h">How to use item analysis</p>
      <p>Start by scanning the item table for warning signals. Look for items with high missing data, very low variation, unusual response patterns, weak item-total relationships, or signals that suggest the item may not fit the construct.</p>
      <p>Next, click an item to review its details. Ask whether the item is clear, aligned with the construct, and useful for interpretation. A flagged item is not automatically wrong. It may be reverse-coded, poorly worded, too easy, too obvious, or measuring a slightly different idea.</p>
      <p>Use item analysis to make careful decisions:</p>
      <ul class="val-guide">
        <li><b>Keep</b> the item if it appears healthy and supports the construct.</li>
        <li><b>Flag</b> the item if it needs review but should remain in the official analysis.</li>
        <li><b>Remove in scenario</b> if you want to test how the results change without it.</li>
        <li><b>Revise for future use</b> if the item should not be changed in the current dataset but should be improved before the next survey.</li>
      </ul>
      <p>Do not remove an item only because one number looks weak. Consider the full meaning of the construct. Removing too many items can make a scale look cleaner while making it less meaningful.</p>
    </div>
  </details>

  <div class="jr-lab3">
    <div class="jr-zone">
      <div class="jr-zone-lab"><span class="zi"><?= jr_icon('filter') ?></span>Filters</div>
      <div class="jr-ctrl">
        <div class="ch">Construct</div>
        <div id="iaConstructFilters"><div class="jr-read-empty" style="font-size:12px;color:var(--ink-5);padding:6px 2px">Open a saved dataset.</div></div>
        <div class="ch">Flag type</div>
        <div id="iaFlagFilters">
          <div class="jr-row" data-on="0" data-flag="weak"><span class="cb"><?= $check ?></span><span class="lbl">Weak item-total</span><span class="meta" data-flag-count>0</span></div>
          <div class="jr-row" data-on="0" data-flag="reverse"><span class="cb"><?= $check ?></span><span class="lbl">Reverse-worded</span><span class="meta" data-flag-count>0</span></div>
          <div class="jr-row" data-on="0" data-flag="lowvar"><span class="cb"><?= $check ?></span><span class="lbl">Low variance</span><span class="meta" data-flag-count>0</span></div>
          <div class="jr-row" data-on="0" data-flag="missing"><span class="cb"><?= $check ?></span><span class="lbl">High missingness</span><span class="meta" data-flag-count>0</span></div>
          <div class="jr-row" data-on="0" data-flag="extreme"><span class="cb"><?= $check ?></span><span class="lbl">Floor / ceiling</span><span class="meta" data-flag-count>0</span></div>
        </div>
      </div>
    </div>
    <div class="jr-zone flush">
      <div class="jr-zone-lab"><span class="zi"><?= jr_icon('sliders') ?></span><span id="iaCount">Items</span></div>
      <table class="jr-tbl"><thead><tr><th>Item</th><th>Mean</th><th>SD</th><th>Distribution</th><th>Miss</th><th>Signal</th></tr></thead><tbody id="iaItemsBody">
        <tr><td colspan="6" class="rel-placeholder">Open a saved dataset to inspect its items.</td></tr>
      </tbody></table>
    </div>
    <div class="jr-zone jr-read">
      <div class="jr-zone-lab"><span class="zi"><?= jr_icon('info') ?></span>Selected item</div>
      <div id="iaReadout"><p class="jr-read-empty" style="font-size:13px;color:var(--ink-5)">Select an item in the table to see what its statistics mean and what you can do about it.</p></div>
    </div>
  </div>
  <div class="jr-actions"><a class="jr-btn" data-go="quality">Open Data Quality <?= jr_icon('arrow') ?></a><a class="jr-btn ghost" data-go="validity">Back to Validity Lab</a></div>
</section>

<!-- ═════ 05 Data Quality — three-zone ═════ -->
<section class="jr-panel" data-step="quality">
  <div class="jr-eyebrow"><span class="step-no">05</span> · Data Quality</div>
  <h1 class="jr-title">Decide <em>who to keep</em>.</h1>
  <p class="jr-lede">Check whether your responses are trustworthy enough to interpret, and see what excluding weak ones does to your Strength Score.</p>

  <details class="q-more" style="margin-bottom:20px">
    <summary>More details: what data quality is and how to use it</summary>
    <div class="more-body">
      <p class="more-h">What is data quality?</p>
      <p>Data quality checks whether the responses in your dataset are strong enough to support interpretation. Even a well-designed survey can produce weak results if too many responses are missing, rushed, patterned, inconsistent, or unusable.</p>
      <p>In RSSI, data quality focuses on the condition of the response data. It helps you see whether the dataset contains enough trustworthy responses to support the conclusions you want to make.</p>
      <p>Data quality asks practical questions:</p>
      <ul class="val-guide">
        <li>Did enough people complete the survey?</li>
        <li>Are too many responses missing?</li>
        <li>Did some respondents rush through the survey?</li>
        <li>Are there straight-line or patterned responses?</li>
        <li>Are there duplicate or suspicious responses?</li>
        <li>Are some respondents answering in ways that weaken the evidence?</li>
        <li>Would excluding low-quality responses change the interpretation?</li>
      </ul>
      <p>Data quality does not automatically accuse a respondent of being careless. It flags patterns that should be reviewed before results are trusted, explained, or defended.</p>
      <p class="more-h">How to use data quality</p>
      <p>Start by reviewing the respondent table and the quality flags. Look for responses with high missingness, unusually fast completion times, straight-lining, duplicate patterns, or other signs that the data may not be reliable enough to interpret.</p>
      <p>Next, review each flagged response carefully. A flag does not always mean the response should be removed. For example, a fast completion time may be reasonable for a short survey, and repeated answers may be legitimate if the respondent truly felt the same across several items.</p>
      <p>Use the data quality tools to make careful decisions:</p>
      <ul class="val-guide">
        <li><b>Keep</b> responses that appear usable and defensible.</li>
        <li><b>Flag</b> responses that need review but should remain in the dataset.</li>
        <li><b>Exclude in scenario</b> if you want to test how results change without questionable responses.</li>
        <li><b>Document the reason</b> whenever a response is excluded from a revised dataset or report.</li>
      </ul>
      <p>After making changes, review the impact panel. It should show how many responses remain, whether sample size is still adequate, and whether the RSSI evidence changed. Excluding responses can improve data quality, but it can also reduce sample size or change the meaning of the results.</p>
      <p>Do not remove responses just to improve the score. Remove or flag responses only when there is a clear evidence-based reason.</p>
      <p>In this dataset, RSSI screens for straight-lining, inconsistency, and missing data. Response speed and duplicate detection need data this dataset does not include, so those flags are not shown.</p>
    </div>
  </details>

  <div class="jr-lab3">
    <div class="jr-zone">
      <div class="jr-zone-lab"><span class="zi"><?= jr_icon('filter') ?></span>Exclude</div>
      <div class="jr-ctrl" id="dqFlagFilters">
        <div class="jr-row" data-on="0" data-flag="straight"><span class="cb"><?= $check ?></span><span class="lbl">Straight-liners</span><span class="meta" data-flag-count>0</span></div>
        <div class="jr-row" data-on="0" data-flag="inconsistent"><span class="cb"><?= $check ?></span><span class="lbl">Inconsistent</span><span class="meta" data-flag-count>0</span></div>
        <div class="jr-row" data-on="0" data-flag="incomplete"><span class="cb"><?= $check ?></span><span class="lbl">Incomplete</span><span class="meta" data-flag-count>0</span></div>
      </div>
    </div>
    <div class="jr-zone flush">
      <div class="jr-zone-lab"><span class="zi"><?= jr_icon('sliders') ?></span><span id="dqCount">Respondents</span></div>
      <table class="jr-tbl"><thead><tr><th>ID</th><th>Pattern</th><th>Flag</th><th>Status</th></tr></thead><tbody id="dqRespBody">
        <tr><td colspan="4" class="rel-placeholder">Open a saved dataset to screen respondents.</td></tr>
      </tbody></table>
    </div>
    <div class="jr-zone jr-effect">
      <div class="jr-zone-lab"><span class="zi"><?= jr_icon('shield') ?></span>RSSI impact</div>
      <div class="big"><span id="dqBig">&mdash;</span><small> / 100</small></div>
      <div class="blab">Strength Score after exclusions</div>
      <div class="delta" id="dqDelta">&mdash;</div>
      <div class="ovr">
        <div class="lr"><span>All responses</span><b id="dqAll">&mdash;</b></div>
        <div class="lr"><span>Attentive only</span><b id="dqKept">&mdash;</b></div>
        <div class="lr"><span>Excluded</span><b id="dqExcl">0</b></div>
      </div>
      <div class="sim">Exclusions show the effect here; they do not change your saved data.</div>
    </div>
  </div>
  <div class="jr-actions"><a class="jr-btn" data-go="scenario">Open the Scenario Builder <?= jr_icon('arrow') ?></a><a class="jr-btn ghost" data-go="items">Back to Item Analysis</a></div>
</section>

<!-- ═════ 06 Scenario Builder — decision collector ═════ -->
<section class="jr-panel" data-step="scenario">
  <div class="jr-eyebrow"><span class="step-no">06</span> · Scenario Builder</div>
  <h1 class="jr-title">Compare <em>original vs revised</em>.</h1>
  <p class="jr-lede">Test how the evidence changes when you make defensible decisions about the dataset, and compare the original against your revised scenario.</p>

  <details class="q-more" style="margin-bottom:20px">
    <summary>More details: what Scenario Builder is and how to use it</summary>
    <div class="more-body">
      <p class="more-h">What is Scenario Builder?</p>
      <p>Scenario Builder lets you test how survey evidence changes when you make defensible decisions about the dataset.</p>
      <p>In RSSI, some decisions should not be made blindly. You may need to test what happens if you remove a weak item, exclude questionable responses, adjust construct assignments, or compare the original dataset to a revised version.</p>
      <p>Scenario Builder helps you answer: what changes if I make this decision?</p>
      <p>It does not automatically change the official RSSI score. Instead, it creates a clear comparison between the original evidence and the revised scenario. This helps you understand whether a decision strengthens the survey, weakens it, or changes the meaning of the results.</p>
      <p>Scenario Builder is especially useful when you need to document why a decision was made before producing a final report.</p>
      <p class="more-h">How to use Scenario Builder</p>
      <p>Start by reviewing the decisions you have made in the Reliability Lab, Validity Lab, Item Analysis, and Data Quality sections. Scenario Builder collects those decisions in one place so you can see their combined effect.</p>
      <p>Use it to compare the <b>Original Dataset</b> with the <b>Revised Scenario</b>. Look for changes in:</p>
      <ul class="val-guide">
        <li>the RSSI score</li>
        <li>construct-level strength</li>
        <li>reliability</li>
        <li>item performance</li>
        <li>response quality</li>
        <li>score interpretability</li>
        <li>number of items kept or removed</li>
        <li>number of responses kept or excluded</li>
      </ul>
      <p>Then review whether the revised scenario is actually better. A higher score is not always a better survey. If removing items makes the construct narrower, or excluding responses changes the sample too much, the revised scenario may be less defensible even if some numbers improve.</p>
      <p>Use Scenario Builder to make careful decisions:</p>
      <ul class="val-guide">
        <li><b>Keep original</b> if the changes do not improve the evidence enough.</li>
        <li><b>Save scenario</b> if the revised version is stronger and still meaningful.</li>
        <li><b>Reset scenario</b> if the changes are not defensible.</li>
        <li><b>Generate report</b> when you are ready to explain the original or revised evidence.</li>
      </ul>
      <p>Every saved scenario should include a clear reason for the changes. The goal is not to make the score look better. The goal is to make the evidence more trustworthy, explainable, and defensible.</p>
    </div>
  </details>

  <div class="jr-compare">
    <div class="side original"><div class="lab">Original dataset</div><div class="num"><span id="sbOrigNum">&mdash;</span><small> / 100</small></div><div class="bd" id="sbOrigBd">&mdash;</div></div>
    <div class="arrow"><?= jr_icon('arrow') ?></div>
    <div class="side revised"><div class="lab">Revised scenario</div><div class="num"><span id="sbRevNum">&mdash;</span><small> / 100</small></div><div class="bd" id="sbRevBd">&mdash;</div></div>
  </div>

  <div class="jr-decisions">
    <div class="jr-dec"><h4><span class="di"><?= jr_icon('flask') ?></span>Items removed (<span id="sbItemsRemovedCount">0</span>)</h4><ul id="sbItemsRemoved"><li class="none">None removed in the Reliability Lab.</li></ul></div>
    <div class="jr-dec"><h4><span class="di"><?= jr_icon('check') ?></span>Items kept</h4><ul id="sbItemsKept"><li class="none">&mdash;</li></ul></div>
    <div class="jr-dec"><h4><span class="di"><?= jr_icon('filter') ?></span>Respondents excluded (<span id="sbRespCount">0</span>)</h4><ul id="sbRespExcl"><li class="none">None excluded in Data Quality.</li></ul></div>
    <div class="jr-dec"><h4><span class="di"><?= jr_icon('target') ?></span>Constructs</h4><ul id="sbConstructs"><li class="none">No model built in the Validity Lab.</li></ul></div>
  </div>

  <div class="jr-callout amber" id="sbCallout" style="display:none"><div class="ci"><?= jr_icon('info') ?></div><div><h4 id="sbCalloutH">Watch what you narrow</h4><p id="sbCalloutP"></p></div></div>

  <div class="jr-actions"><a class="jr-btn" id="sbSave"><?= jr_icon('check') ?> Save scenario as new dataset</a><a class="jr-btn ghost" id="sbReset">Reset to original</a><a class="jr-btn ghost" data-go="report">Generate report <?= jr_icon('arrow') ?></a></div>
  <div class="rel-save-status" id="sbStatus" style="display:none;margin-top:14px"></div>
</section>

<!-- ═════ 07 Report — output chooser ═════ -->
<section class="jr-panel" data-step="report">
  <div class="jr-eyebrow"><span class="step-no">07</span> · Report</div>
  <h1 class="jr-title">Say what you <em>can defend</em>.</h1>
  <p class="jr-lede">Produce a report that states what the evidence supports, what not to overclaim, and the decisions you made. Pick a format; it opens ready to print or save as a PDF.</p>

  <div class="jr-reports">
    <a class="jr-rep" data-report="official" data-sel="1"><span class="ri"><?= jr_icon('doc') ?></span><div><h4>Official RSSI report <span class="badge-rec">Recommended</span></h4><p>The headline Strength Score and band on all responses, exactly as scored, with the three lenses and eight domains.</p></div></a>
    <a class="jr-rep" data-report="revised"><span class="ri"><?= jr_icon('layers') ?></span><div><h4>Revised scenario report</h4><p>Your revised scenario with original-versus-revised side by side and every decision logged.</p></div></a>
    <a class="jr-rep" data-report="construct"><span class="ri"><?= jr_icon('shield') ?></span><div><h4>Construct-level report</h4><p>One section per construct you built: items and Cronbach&rsquo;s &alpha; for each.</p></div></a>
    <a class="jr-rep" data-report="item"><span class="ri"><?= jr_icon('sliders') ?></span><div><h4>Item diagnostic report</h4><p>Every item&rsquo;s statistics, signal, and recommended action.</p></div></a>
    <a class="jr-rep" data-report="appendix"><span class="ri"><?= jr_icon('gauge') ?></span><div><h4>Technical appendix</h4><p>Methods, formulas, complete-case counts, and assumptions for reviewers.</p></div></a>
    <a class="jr-rep" data-report="summary"><span class="ri"><?= jr_icon('info') ?></span><div><h4>Plain-language summary</h4><p>A one-page, jargon-free summary for stakeholders.</p></div></a>
  </div>

  <div class="jr-actions"><a class="jr-btn" id="rptGenerate"><?= jr_icon('doc') ?> Generate selected report</a><a class="jr-btn ghost" id="rptAll">Combined report (all sections)</a><a class="jr-btn ghost" data-go="orientation">Back to start</a></div>
  <div class="rel-save-status" id="rptStatus" style="display:none;margin-top:14px"></div>
</section>

<?php

include __DIR__ . '/apps/journey/_journey_foot.php';
