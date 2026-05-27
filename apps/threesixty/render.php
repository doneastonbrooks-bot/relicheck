<?php
// ReliCheck 360 Studio Analysis render shell.
// Seven lenses on ratee × rater × competency-score data.
?>

<section class="t6-app" aria-label="360 Analysis">

  <div class="t6-source" id="t6Source">
    <span class="t6-source-pip" aria-hidden="true"></span>
    <span class="t6-source-label" id="t6SourceLabel">Sample 360 data</span>
    <span class="t6-source-meta" id="t6SourceMeta">—</span>
  </div>

  <div class="t6-empty" id="t6Empty" hidden>
    <p>No 360 dataset is available. The dataset needs columns for ratee_id, rater_role (Self/Peer/Manager/Direct Report), and at least one competency-score variable.</p>
  </div>

  <!-- Ratee picker (used by most lenses except Cohort Summary) -->
  <div class="t6-ratee-picker" id="t6RateePicker" hidden>
    <label class="t6-label">
      <span class="t6-label-text">Ratee</span>
      <select class="t6-select" id="t6Ratee"></select>
    </label>
    <button class="btn btn-primary" type="button" id="t6Run">Update</button>
  </div>

  <!-- All lenses share this single results card -->
  <article class="t6-results" id="t6Results">
    <header class="t6-head">
      <div class="t6-eyebrow"><span class="pip" aria-hidden="true"></span><span id="t6LensName">—</span></div>
      <h3 id="t6Title">—</h3>
      <p class="t6-sub" id="t6Sub">—</p>
    </header>
    <div class="t6-stat-strip" id="t6Stats"></div>
    <div class="t6-body" id="t6Body"></div>
    <footer class="t6-foot">
      <h4 class="t6-block-h">Interpretation</h4>
      <p class="t6-interp" id="t6Interp">—</p>
    </footer>
  </article>

</section>
