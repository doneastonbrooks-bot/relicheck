<?php
// Data Upload app, render function.
// -------------------------------------------------------------------
// Emits the two-step upload UI: Step 1 (drag/paste/choose), Step 2
// (confirm each variable's role). Mounted by any studio that needs to
// ingest data. The page that mounts this app is responsible for
// loading data-upload.css and data-upload.js before render time.
//
// Outputs (in real usage): a typed dataset object the JS posts back
// to the server when the user clicks "Continue to analysis". For the
// current preview, the JS keeps the dataset in memory.
?>

<section class="upload-app" aria-labelledby="upload-title">

  <!-- ===== STEP 1: bring data in ===== -->
  <section class="upload-step is-current" data-step="1" aria-labelledby="step1-title">
    <header class="upload-step-head">
      <span class="step-num">1</span>
      <div>
        <h2 id="step1-title">Bring in your data</h2>
        <p>Drag a file, paste from a spreadsheet, or choose a file from your computer. CSV, TSV, or pasted tab-separated text.</p>
      </div>
    </header>

    <!-- Drop zone with three affordances inside -->
    <div class="upload-dropzone" id="uploadDropzone" tabindex="0">
      <svg class="upload-ico" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M32 8v32"/>
        <path d="M20 20l12-12 12 12"/>
        <path d="M10 44v6a4 4 0 0 0 4 4h36a4 4 0 0 0 4-4v-6"/>
      </svg>
      <p class="dropzone-primary">Drop your data file here</p>
      <p class="dropzone-meta">CSV, TSV, or tab-separated text  ·  up to 50 MB</p>

      <div class="dropzone-actions">
        <button type="button" class="btn btn-ghost" id="pasteToggle">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="4" width="12" height="16" rx="2"/><path d="M16 4v2a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2V4"/><path d="M4 14h8M4 10h6M4 18h5"/></svg>
          Paste data
        </button>
        <span class="action-sep">or</span>
        <label class="btn btn-primary file-pick">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg>
          Choose file
          <input type="file" id="uploadFileInput" hidden accept=".csv,.tsv,.txt">
        </label>
        <button type="button" class="upload-sample-link" id="useSampleData">Use sample data</button>
      </div>
    </div>

    <!-- Paste panel, revealed by Paste data button -->
    <div class="upload-paste" id="pastePanel" hidden>
      <label for="pasteArea" class="paste-label">Paste your data (CSV, TSV, or tab-separated from a spreadsheet)</label>
      <textarea id="pasteArea" rows="8" placeholder="respondent_id, age, gender, role_score, openended_response&#10;001, 34, Female, 4, &quot;Good support from manager.&quot;&#10;002, 28, Male, 3, &quot;Long meetings.&quot;"></textarea>
      <div class="paste-actions">
        <button type="button" class="btn btn-ghost" id="pasteCancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="pasteSubmit">Parse pasted data</button>
      </div>
    </div>

    <!-- Status line that shows after a successful upload/paste -->
    <div class="upload-status" id="uploadStatus" hidden>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
      <span id="uploadStatusText">Loaded.</span>
      <button type="button" class="upload-replace-link" id="replaceData">Replace</button>
    </div>
  </section>

  <!-- ===== STEP 2: confirm each variable's role ===== -->
  <section class="upload-step" data-step="2" aria-labelledby="step2-title" hidden>
    <header class="upload-step-head">
      <span class="step-num">2</span>
      <div>
        <h2 id="step2-title">Confirm each variable's role</h2>
        <p>Check the data type for each variable. A variable can play more than one role: an ID column can also be categorical, a Likert can be both ordinal and numeric. Defaults come from a quick auto-detect.</p>
      </div>
    </header>

    <div class="var-table" id="varTable" role="table" aria-label="Variables and their roles">
      <div class="var-row var-row-head" role="row">
        <div class="var-col var-col-name" role="columnheader">Variable</div>
        <div class="var-col var-col-sample" role="columnheader">Sample values</div>
        <div class="var-col var-col-type" role="columnheader"><abbr title="Unique identifier per respondent">ID</abbr></div>
        <div class="var-col var-col-type" role="columnheader">Categorical</div>
        <div class="var-col var-col-type" role="columnheader">Likert / Scale</div>
        <div class="var-col var-col-type" role="columnheader">Numeric</div>
        <div class="var-col var-col-type" role="columnheader">Open-ended</div>
        <div class="var-col var-col-type" role="columnheader">Date / Time</div>
      </div>
      <!-- Variable rows are rendered by JS after upload/paste -->
      <div class="var-rows" id="varRows" role="rowgroup"></div>
    </div>

    <div class="upload-step-actions">
      <button type="button" class="btn btn-ghost" id="backToStep1">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </button>
      <span class="upload-step-meta" id="varSummary"></span>
      <button type="button" class="btn btn-primary" id="continueToAnalysis">
        Continue to analysis
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
      </button>
    </div>
  </section>

</section>
