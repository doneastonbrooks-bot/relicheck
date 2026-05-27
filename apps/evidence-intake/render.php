<?php
// Evidence Intake Wizard render shell.
// -------------------------------------------------------------------
// Iterates $intake_config['steps'] and emits the appropriate HTML for
// each step type. Step types currently supported:
//   - 'upload'      Drop / paste / choose file (always step 1)
//   - 'map'         Variable role checkboxes table
//   - 'answer_key'  Answer key per item (TIA only, step 3)
// Future passes add: 'context' (step 1), 'preview', 'validate',
// 'readiness'. The engine JS reads window.INTAKE_CONFIG and binds to
// the elements rendered here.
//
// The mounting page is responsible for setting $intake_config before
// including this file, and for emitting <script>window.INTAKE_CONFIG = ...</script>
// + the engine's JS file after this render.

$cfg = $intake_config ?? null;
if (!$cfg) {
  echo '<div class="upload-app"><p>Evidence Intake: no config loaded.</p></div>';
  return;
}
?>

<section class="upload-app" data-studio="<?= htmlspecialchars($cfg['slug']) ?>" data-detector="<?= htmlspecialchars($cfg['detector_kind']) ?>">

<?php foreach ($cfg['steps'] as $i => $step):
  $num   = $step['num'];
  $is_first = ($i === 0);
?>

<?php if ($step['key'] === 'upload'): ?>
  <!-- ===== STEP 1: bring data in ===== -->
  <section class="upload-step is-current" data-step="<?= $num ?>" aria-labelledby="step<?= $num ?>-title">
    <header class="upload-step-head">
      <span class="step-num"><?= $num ?></span>
      <div>
        <h2 id="step<?= $num ?>-title"><?= htmlspecialchars($step['title']) ?></h2>
        <p><?= htmlspecialchars($step['subtitle']) ?></p>
      </div>
    </header>

    <div class="upload-dropzone" id="uploadDropzone" tabindex="0">
      <svg class="upload-ico" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M32 8v32"/>
        <path d="M20 20l12-12 12 12"/>
        <path d="M10 44v6a4 4 0 0 0 4 4h36a4 4 0 0 0 4-4v-6"/>
      </svg>
      <p class="dropzone-primary"><?= htmlspecialchars($step['dropzone_primary']) ?></p>
      <p class="dropzone-meta"><?= htmlspecialchars($step['dropzone_meta']) ?></p>

      <div class="dropzone-actions">
        <button type="button" class="btn btn-ghost" id="pasteToggle">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="4" width="12" height="16" rx="2"/><path d="M16 4v2a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2V4"/><path d="M4 14h8M4 10h6M4 18h5"/></svg>
          Paste data
        </button>
        <span class="action-sep">or</span>
        <label class="btn btn-primary file-pick">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg>
          Choose file
          <input type="file" id="uploadFileInput" hidden accept=".csv,.tsv,.txt,.xlsx,.xls,.xlsm,.xlsb,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel">
        </label>
        <button type="button" class="upload-sample-link" id="useSampleData"><?= htmlspecialchars($cfg['sample_label']) ?></button>
      </div>
    </div>

    <div class="upload-paste" id="pastePanel" hidden>
      <label for="pasteArea" class="paste-label">Paste your data (CSV, TSV, or tab-separated from a spreadsheet)</label>
      <textarea id="pasteArea" rows="8" placeholder="<?= htmlspecialchars($cfg['paste_placeholder']) ?>"></textarea>
      <div class="paste-actions">
        <button type="button" class="btn btn-ghost" id="pasteCancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="pasteSubmit">Parse pasted data</button>
      </div>
    </div>

    <div class="upload-status" id="uploadStatus" hidden>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
      <span id="uploadStatusText">Loaded.</span>
      <button type="button" class="upload-replace-link" id="replaceData">Replace</button>
    </div>
  </section>

<?php elseif ($step['key'] === 'map'): ?>
  <!-- ===== STEP 2: confirm each variable's role ===== -->
  <section class="upload-step" data-step="<?= $num ?>" aria-labelledby="step<?= $num ?>-title" hidden>
    <header class="upload-step-head">
      <span class="step-num"><?= $num ?></span>
      <div>
        <h2 id="step<?= $num ?>-title"><?= htmlspecialchars($step['title']) ?></h2>
        <p><?= htmlspecialchars($step['subtitle']) ?></p>
      </div>
    </header>

    <div class="var-table" id="varTable" role="table" aria-label="Variables and their roles">
      <div class="var-row var-row-head" role="row">
        <div class="var-col var-col-name" role="columnheader">Variable</div>
        <div class="var-col var-col-sample" role="columnheader"><?= htmlspecialchars($step['sample_header']) ?></div>
        <?php foreach ($step['column_labels'] as $colLabel): ?>
          <div class="var-col var-col-type" role="columnheader"><?= htmlspecialchars($colLabel) ?></div>
        <?php endforeach; ?>
      </div>
      <div class="var-rows" id="varRows" role="rowgroup"></div>
    </div>

    <div class="upload-step-actions">
      <button type="button" class="btn btn-ghost" id="backToStep1">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </button>
      <span class="upload-step-meta" id="varSummary"></span>
      <button type="button" class="btn btn-primary" id="continueFromMap">
        <?= htmlspecialchars($step['continue_label']) ?>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
      </button>
    </div>
  </section>

<?php elseif ($step['key'] === 'answer_key'): ?>
  <!-- ===== STEP 3: answer key + scoring (TIA only) ===== -->
  <section class="upload-step" data-step="<?= $num ?>" aria-labelledby="step<?= $num ?>-title" hidden>
    <header class="upload-step-head">
      <span class="step-num"><?= $num ?></span>
      <div>
        <h2 id="step<?= $num ?>-title"><?= htmlspecialchars($step['title']) ?></h2>
        <p><?= htmlspecialchars($step['subtitle']) ?></p>
      </div>
    </header>

    <div class="key-table" id="keyTable" role="table" aria-label="Answer key per item">
      <div class="key-row key-row-head" role="row">
        <div class="key-col key-col-name" role="columnheader">Item</div>
        <div class="key-col key-col-sample" role="columnheader"><?= htmlspecialchars($step['sample_header']) ?></div>
        <div class="key-col key-col-correct" role="columnheader">Correct answer</div>
        <div class="key-col key-col-max" role="columnheader">Max points</div>
        <div class="key-col key-col-type" role="columnheader">Item type</div>
      </div>
      <div class="key-rows" id="keyRows" role="rowgroup"></div>
    </div>

    <div class="upload-step-actions">
      <button type="button" class="btn btn-ghost" id="backToStep2">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </button>
      <span class="upload-step-meta" id="keySummary"></span>
      <button type="button" class="btn btn-primary" id="continueFromKey">
        <?= htmlspecialchars($step['continue_label']) ?>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>
      </button>
    </div>
  </section>

<?php endif; endforeach; ?>

</section>
