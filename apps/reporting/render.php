<?php
// ReliCheck Reporting render shell.
// -------------------------------------------------------------------
// One markup shell, eight lenses. The mount page sets
//   window.REPORTING_LENS = '<lens_key>';
// before loading the engine. The engine then shows/hides the section
// for that lens and fills it with content drawn from saved blocks.
?>

<section class="rep-app" aria-label="Reporting">

  <!-- ===== Saved-blocks status ribbon ===== -->
  <div class="rep-source" id="repSource" data-source="empty">
    <span class="rep-source-pip" aria-hidden="true"></span>
    <span class="rep-source-label" id="repSourceLabel">No saved results yet</span>
    <span class="rep-source-meta" id="repSourceMeta">—</span>
    <span class="rep-source-cta" id="repTitleEcho"></span>
  </div>

  <!-- ===== Empty state (no saved blocks) ===== -->
  <div class="rep-empty" id="repEmpty" hidden>
    <h4>Nothing to report yet</h4>
    <p>The Reporting suite assembles your saved analyses into a deliverable. Run any analysis app and click <strong>Save to Report</strong> in the studio chrome to add a block. Come back here and the report sections will fill in.</p>
  </div>

  <!-- ===== LENS: report_builder (interactive) ===== -->
  <div class="rep-lens" data-lens="report_builder" hidden>
    <div class="rep-builder-head">
      <div class="rep-title-edit">
        <label class="rep-block-h" for="repTitleInput">Report title</label>
        <input type="text" id="repTitleInput" class="rep-title-input" placeholder="Untitled report" />
      </div>
      <div class="rep-builder-meta" id="repBuilderMeta">—</div>
    </div>

    <div class="rep-builder-actions">
      <button class="btn btn-ghost" type="button" id="repAddNote">+ Add note</button>
      <button class="btn btn-ghost" type="button" id="repSelectAll">Include all</button>
      <button class="btn btn-ghost" type="button" id="repClearAll">Exclude all</button>
      <button class="btn btn-ghost" type="button" id="repClearReport">Clear report</button>
      <a class="btn btn-primary rep-preview-btn" href="/findings.php" id="repPreviewBtn">Preview findings →</a>
    </div>

    <div class="rep-blocks" id="repBlocks" aria-live="polite"></div>
  </div>

  <!-- ===== LENS: executive_summary ===== -->
  <div class="rep-lens" data-lens="executive_summary" hidden>
    <article class="rep-section" id="repExecArticle">
      <header class="rep-section-head">
        <div class="rep-eyebrow"><span class="pip" aria-hidden="true"></span><span>Executive Summary</span></div>
        <h3 id="repExecTitle">—</h3>
      </header>
      <div class="rep-prose" id="repExecBody"></div>
      <footer class="rep-section-foot">
        <button class="btn btn-ghost" type="button" data-copy-target="repExecBody">Copy to clipboard</button>
      </footer>
    </article>
  </div>

  <!-- ===== LENS: methodology ===== -->
  <div class="rep-lens" data-lens="methodology" hidden>
    <article class="rep-section">
      <header class="rep-section-head">
        <div class="rep-eyebrow"><span class="pip" aria-hidden="true"></span><span>Methodology</span></div>
        <h3>How this analysis was carried out</h3>
      </header>
      <div class="rep-prose" id="repMethodologyBody"></div>
      <footer class="rep-section-foot">
        <button class="btn btn-ghost" type="button" data-copy-target="repMethodologyBody">Copy to clipboard</button>
      </footer>
    </article>
  </div>

  <!-- ===== LENS: findings ===== -->
  <div class="rep-lens" data-lens="findings" hidden>
    <article class="rep-section">
      <header class="rep-section-head">
        <div class="rep-eyebrow"><span class="pip" aria-hidden="true"></span><span>Findings</span></div>
        <h3 id="repFindingsTitle">Results</h3>
        <p class="rep-section-sub" id="repFindingsSub">—</p>
      </header>
      <div class="rep-findings-list" id="repFindingsBody"></div>
      <footer class="rep-section-foot">
        <button class="btn btn-ghost" type="button" data-copy-target="repFindingsBody">Copy section</button>
      </footer>
    </article>
  </div>

  <!-- ===== LENS: tables_figures ===== -->
  <div class="rep-lens" data-lens="tables_figures" hidden>
    <article class="rep-section">
      <header class="rep-section-head">
        <div class="rep-eyebrow"><span class="pip" aria-hidden="true"></span><span>Tables &amp; Figures</span></div>
        <h3>Detail tables</h3>
        <p class="rep-section-sub">Appendix-ready tables drawn from every saved analysis. Each table is numbered for citation in the body of the report.</p>
      </header>
      <div class="rep-tables" id="repTablesBody"></div>
    </article>
  </div>

  <!-- ===== LENS: recommendations ===== -->
  <div class="rep-lens" data-lens="recommendations" hidden>
    <article class="rep-section">
      <header class="rep-section-head">
        <div class="rep-eyebrow"><span class="pip" aria-hidden="true"></span><span>Recommendations</span></div>
        <h3>What to do next</h3>
        <p class="rep-section-sub">Formal version of the Recommended Actions lens, formatted for inclusion in the report.</p>
      </header>
      <ol class="rep-recommendations" id="repRecommendationsBody"></ol>
      <footer class="rep-section-foot">
        <button class="btn btn-ghost" type="button" data-copy-target="repRecommendationsBody">Copy section</button>
      </footer>
    </article>
  </div>

  <!-- ===== LENS: export ===== -->
  <div class="rep-lens" data-lens="export" hidden>
    <article class="rep-section">
      <header class="rep-section-head">
        <div class="rep-eyebrow"><span class="pip" aria-hidden="true"></span><span>Export</span></div>
        <h3>Download the report</h3>
        <p class="rep-section-sub">Browser-side exports. PDF uses print-to-PDF (your browser's "Save as PDF" produces a clean document from the Findings layout). The others download as files.</p>
      </header>
      <div class="rep-export-grid" id="repExportGrid">
        <button class="rep-export-card" data-fmt="pdf"      type="button">
          <span class="rep-export-name">PDF</span>
          <span class="rep-export-sub">Print → Save as PDF (clean Findings layout)</span>
        </button>
        <button class="rep-export-card" data-fmt="html"     type="button">
          <span class="rep-export-name">HTML</span>
          <span class="rep-export-sub">Self-contained .html file (open anywhere)</span>
        </button>
        <button class="rep-export-card" data-fmt="markdown" type="button">
          <span class="rep-export-name">Markdown</span>
          <span class="rep-export-sub">.md plaintext — good for AI / docs</span>
        </button>
        <button class="rep-export-card" data-fmt="json"     type="button">
          <span class="rep-export-name">JSON</span>
          <span class="rep-export-sub">Raw saved blocks (backup / reload)</span>
        </button>
        <button class="rep-export-card" data-fmt="csv"      type="button">
          <span class="rep-export-name">CSV</span>
          <span class="rep-export-sub">One row per block (summary table)</span>
        </button>
        <button class="rep-export-card" data-fmt="clipboard" type="button">
          <span class="rep-export-name">Clipboard</span>
          <span class="rep-export-sub">Full report as plain text</span>
        </button>
      </div>
      <p class="rep-export-foot" id="repExportFoot">Word, Excel, and PowerPoint exports need server-side rendering and will land in a later pass; for now, paste the HTML or Markdown into your editor of choice.</p>
    </article>
  </div>

  <!-- ===== LENS: appendix ===== -->
  <div class="rep-lens" data-lens="appendix" hidden>
    <article class="rep-section">
      <header class="rep-section-head">
        <div class="rep-eyebrow"><span class="pip" aria-hidden="true"></span><span>Appendix</span></div>
        <h3>Technical detail</h3>
        <p class="rep-section-sub">Full payload for every saved analysis. The math, the assumptions, the per-item diagnostics. Where reviewers go when they want to know exactly what was done.</p>
      </header>
      <div class="rep-appendix" id="repAppendixBody"></div>
    </article>
  </div>

</section>
