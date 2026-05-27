<?php
// ReliCheck Open-Ended Summary render shell.
// -------------------------------------------------------------------
// Emits the aggregate card, per-question cards (cloned by the engine
// from the #oeQuestionTemplate stub), and the closing interpretation
// panel. The mounting page is responsible for the breadcrumb and the
// section h2 above this (those are studio-shell chrome, not app
// content). After this render, the page provides:
//   <script>window.OPEN_ENDED_DATASET = {...}</script>
//   <script>window.OPEN_ENDED_CONFIG  = {...}</script>  (optional)
//   <script src="/apps/open-ended-summary/open-ended-summary.js"></script>
// and the engine fills the DOM from the dataset.
//
// Element IDs / data attributes are stable so the engine can target them.
?>

<section class="oe-app" aria-label="Open-Ended Summary">

  <!-- ===== Aggregate card ===== -->
  <div class="oe-aggregate">
    <div class="oe-aggregate-head">
      <div class="oe-eyebrow"><span class="pip" aria-hidden="true"></span><span id="oeKindLabel">Open-ended responses</span></div>
      <h3 id="oeAggregateTitle">Computing the qualitative picture…</h3>
      <p class="oe-aggregate-sub" id="oeAggregateSub">Reading every open-ended field in the dataset.</p>
    </div>
    <div class="oe-aggregate-stats">
      <div class="oe-stat">
        <label>Open fields</label>
        <span class="v" id="oeStatFields">—</span>
      </div>
      <div class="oe-stat">
        <label>Total answers</label>
        <span class="v" id="oeStatAnswers">—</span>
      </div>
      <div class="oe-stat">
        <label>Response rate</label>
        <span class="v" id="oeStatResponseRate">—</span>
      </div>
      <div class="oe-stat">
        <label>Avg words per answer</label>
        <span class="v" id="oeStatAvgWords">—</span>
      </div>
      <div class="oe-stat">
        <label>Substantive answers</label>
        <span class="v" id="oeStatSubstantive">—</span>
        <span class="oe-stat-foot">≥ 10 characters</span>
      </div>
    </div>
  </div>

  <!-- ===== Per-question cards (engine clones the template below) ===== -->
  <div class="oe-questions" id="oeQuestions" aria-live="polite">
    <div class="oe-empty" id="oeEmpty" hidden>
      <p>No open-ended fields detected in this dataset.</p>
      <p class="oe-empty-sub">Open-ended fields are columns tagged as <strong>Open-ended</strong> during Evidence Intake. If your survey has free-text questions, return to <a href="/evidence-intake.php">Evidence Intake</a> and check the <strong>Open-ended</strong> box for each text column.</p>
    </div>
  </div>

  <!-- Per-question card template. The engine clones this once per open
       variable. Hidden via [hidden] until cloned. -->
  <template id="oeQuestionTemplate">
    <article class="oe-question" data-question-id="">
      <header class="oe-question-head">
        <div class="oe-question-eyebrow"><span class="pip" aria-hidden="true"></span><span data-fill="kindShort">Open-ended</span></div>
        <h3 class="oe-question-title" data-fill="title">Question</h3>
        <div class="oe-question-meta" data-fill="meta">— answers</div>
      </header>

      <div class="oe-question-grid">

        <!-- Stats column -->
        <div class="oe-block oe-block-stats">
          <h4 class="oe-block-h">Response profile</h4>
          <div class="oe-mini-stats">
            <div class="oe-mini"><label>Answered</label><span data-fill="answered">—</span></div>
            <div class="oe-mini"><label>Response rate</label><span data-fill="rate">—</span></div>
            <div class="oe-mini"><label>Mean words</label><span data-fill="meanWords">—</span></div>
            <div class="oe-mini"><label>Median words</label><span data-fill="medianWords">—</span></div>
            <div class="oe-mini"><label>Shortest</label><span data-fill="minWords">—</span></div>
            <div class="oe-mini"><label>Longest</label><span data-fill="maxWords">—</span></div>
          </div>

          <h4 class="oe-block-h oe-block-h-tight">Length distribution</h4>
          <div class="oe-buckets" data-fill="buckets">
            <div class="oe-bucket" data-bucket="single"><div class="oe-bucket-bar"><span></span></div><label>One word<span data-fill="bucketSingle">0</span></label></div>
            <div class="oe-bucket" data-bucket="short"><div class="oe-bucket-bar"><span></span></div><label>Short (2-10)<span data-fill="bucketShort">0</span></label></div>
            <div class="oe-bucket" data-bucket="medium"><div class="oe-bucket-bar"><span></span></div><label>Medium (11-25)<span data-fill="bucketMedium">0</span></label></div>
            <div class="oe-bucket" data-bucket="long"><div class="oe-bucket-bar"><span></span></div><label>Long (26+)<span data-fill="bucketLong">0</span></label></div>
          </div>
        </div>

        <!-- Top words + bigrams column -->
        <div class="oe-block oe-block-words">
          <h4 class="oe-block-h">Top words</h4>
          <div class="oe-pills" data-fill="unigrams">
            <span class="oe-pill-empty">No words to show yet.</span>
          </div>

          <h4 class="oe-block-h oe-block-h-tight">Top phrases</h4>
          <div class="oe-pills" data-fill="bigrams">
            <span class="oe-pill-empty">No phrases to show yet.</span>
          </div>
        </div>

        <!-- Sample quotes column -->
        <div class="oe-block oe-block-quotes">
          <h4 class="oe-block-h">Sample responses</h4>
          <ul class="oe-quotes" data-fill="quotes">
            <li class="oe-quote-empty">No sample responses available.</li>
          </ul>
        </div>

      </div>

      <footer class="oe-question-foot">
        <p class="oe-question-interp" data-fill="interp">Computing…</p>
      </footer>
    </article>
  </template>

  <!-- ===== Closing interpretation panel ===== -->
  <div class="interp-card oe-interp-card">
    <h3>What this means</h3>
    <p id="oeInterpLead">Computing your interpretation…</p>
    <p id="oeInterpFollow"></p>
    <div class="interp-actions">
      <button class="btn btn-ghost" type="button" id="oeRefreshBtn">Refresh from latest dataset</button>
      <button class="btn btn-ghost" type="button">View methodology note</button>
    </div>
  </div>

</section>
