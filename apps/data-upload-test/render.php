<?php
// Test Data Upload app, render function.
// -------------------------------------------------------------------
// Three-step UI for test/item data (TIA Studio):
//   1. Bring in the student-by-item table (drag, paste, choose file)
//   2. Confirm each column's role (ID, Categorical, Numeric,
//      Item Response, Total Score, Date) via checkboxes
//   3. Enter the answer key per item (correct answer + max points)
//
// Reuses most chrome from the survey upload but is a fully separate
// app per the architecture rule (apps are self-contained). Class
// names overlap by design: most styles are shared via brand tokens.
?>

<section class="upload-app upload-app-test" aria-labelledby="testupload-title">

  <!-- ===== STEP 1: bring data in ===== -->
  <section class="upload-step is-current" data-step="1" aria-labelledby="tstep1-title">
    <header class="upload-step-head">
      <span class="step-num">1</span>
      <div>
        <h2 id="tstep1-title">Bring in your test data</h2>
        <p>One row per student, one column per item (plus any demographics, total scores, or test dates). CSV, TSV, or pasted tab-separated text.</p>
      </div>
    </header>

    <div class="upload-dropzone" id="tUploadDropzone" tabindex="0">
      <svg class="upload-ico" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M32 8v32"/>
        <path d="M20 20l12-12 12 12"/>
        <path d="M10 44v6a4 4 0 0 0 4 4h36a4 4 0 0 0 4-4v-6"/>
      </svg>
      <p class="dropzone-primary">Drop your test file here</p>
      <p class="dropzone-meta">CSV, TSV, or tab-separated text  ·  up to 50 MB</p>

      <div class="dropzone-actions">
        <button type="button" class="btn btn-ghost" id="tPasteToggle">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="4" width="12" height="16" rx="2"/><path d="M16 4v2a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2V4"/><path d="M4 14h8M4 10h6M4 18h5"/></svg>
          Paste data
        </button>
        <span class="action-sep">or</span>
        <label class="btn btn-primary file-pick">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg>
          Choose file
          <input type="file" id="tUploadFileInput" hidden accept=".csv,.tsv,.txt">
        </label>
        <button type="button" class="upload-sample-link" id="tUseSampleData">Use sample test (8 items)</button>
      </div>
    </div>

    <div class="upload-paste" id="tPastePanel" hidden>
      <label for="tPasteArea" class="paste-label">Paste your test data (CSV, TSV, or tab-separated)</label>
      <textarea id="tPasteArea" rows="8" placeholder="student_id, gender, grade, item_1, item_2, item_3, ...&#10;001, F, 8, A, B, C, ...&#10;002, M, 8, B, B, A, ..."></textarea>
      <div class="paste-actions">
        <button type="button" class="btn btn-ghost" id="tPasteCancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="tPasteSubmit">Parse pasted data</button>
      </div>
    </div>

    <div class="upload-status" id="tUploadStatus" hidden>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
      <span id="tUploadStatusText">Loaded.</span>
      <button type="button" class="upload-replace-link" id="tReplaceData">Replace</button>
    </div>
  </section>

  <!-- ===== STEP 2: identify column roles ===== -->
  <section class="upload-step" data-step="2" aria-labelledby="tstep2-title" hidden>
    <header class="upload-step-head">
      <span class="step-num">2</span>
      <div>
        <h2 id="tstep2-title">Identify each column's role</h2>
        <p>Tell us which columns are student identifiers, demographics, item responses, or a precomputed total. Items get scored in step three.</p>
      </div>
    </header>

    <div class="var-table" id="tVarTable" role="table" aria-label="Columns and roles">
      <div class="var-row var-row-head var-row-test" role="row">
        <div class="var-col var-col-name" role="columnheader">Column</div>
        <div class="var-col var-col-sample" role="columnheader">Sample values</div>
        <div class="var-col var-col-type" role="columnheader"><abbr title="Unique student or test-taker identifier">ID</abbr></div>
        <div class="var-col var-col-type" role="columnheader">Categorical</div>
        <div class="var-col var-col-type" role="columnheader">Numeric</div>
        <div class="var-col var-col-type" role="columnheader">Item Response</div>
        <div class="var-col var-col-type" role="columnheader">Total Score</div>
        <div class="var-col var-col-type" role="columnheader">Date / Time</div>
      </div>
      <div class="var-rows" id="tVarRows" role="rowgroup"></div>
    </div>

    <div class="upload-step-actions">
      <button type="button" class="btn btn-ghost" id="tBackToStep1">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </button>
      <span class="upload-step-meta" id="tVarSummary"></span>
      <button type="button" class="btn btn-primary" id="tContinueToStep3">
        Continue to answer key
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
      </button>
    </div>
  </section>

  <!-- ===== STEP 3: answer key + scoring ===== -->
  <section class="upload-step" data-step="3" aria-labelledby="tstep3-title" hidden>
    <header class="upload-step-head">
      <span class="step-num">3</span>
      <div>
        <h2 id="tstep3-title">Enter the answer key</h2>
        <p>For each item, enter the correct answer and the maximum points. We pre-fill the most common student answer as a guess; you can change it. Items can be multi-point if they're scored with a rubric.</p>
      </div>
    </header>

    <div class="key-table" id="tKeyTable" role="table" aria-label="Answer key per item">
      <div class="key-row key-row-head" role="row">
        <div class="key-col key-col-name" role="columnheader">Item</div>
        <div class="key-col key-col-sample" role="columnheader">Student answers</div>
        <div class="key-col key-col-correct" role="columnheader">Correct answer</div>
        <div class="key-col key-col-max" role="columnheader">Max points</div>
        <div class="key-col key-col-type" role="columnheader">Item type</div>
      </div>
      <div class="key-rows" id="tKeyRows" role="rowgroup"></div>
    </div>

    <div class="upload-step-actions">
      <button type="button" class="btn btn-ghost" id="tBackToStep2">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </button>
      <span class="upload-step-meta" id="tKeySummary"></span>
      <button type="button" class="btn btn-primary" id="tContinueToAnalysis">
        Score and continue
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
      </button>
    </div>
  </section>

</section>
