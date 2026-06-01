<?php
// Studio Template FOOTER for ReliCheck (v5 — slide-over assist panel).
// -------------------------------------------------------------------
// Closes the .studio-work / .page / .main / .app wrappers opened by
// _studio_template_header.php, then renders the slide-over panel
// (What This Means + Ask ReliCheck) and the universal studio actions JS.
//
// Pair with _studio_template_header.php.
//
// Optional variables read by this partial:
//
//   $teaching_cards array of associative arrays for the "What This Means"
//                   section. Each card supports:
//                     tag    string  Small pill text (e.g. "Reliability")
//                     fam    string  Family key for accent color
//                                    (reliability|validity|reporting)
//                     title  string  Card title
//                     sub    string  Small note ("3 minute read")
//                     route  string  Card href (optional)

$teaching_cards = $teaching_cards ?? [];
?>
      </section>
      <!-- /studio-work -->
    </div>
    <!-- /page -->
  </main>
  <!-- /main -->
</div>
<!-- /app -->

<!-- Universal sticky studio dock: ReliCheck logo bottom-left, centered content
     (per-studio). Shared template; the analysis studios render their own dock
     with data controls via _analysis_studio_shell.php. -->
<style>
  .studio-dock { position:fixed; left:0; right:0; bottom:0; z-index:60; padding:12px 22px; box-sizing:border-box;
    background:rgba(255,255,255,0.92); -webkit-backdrop-filter:saturate(1.4) blur(12px); backdrop-filter:saturate(1.4) blur(12px);
    border-top:1px solid var(--line,#e6e9f0); box-shadow:0 -4px 22px rgba(15,23,42,0.07); }
  .studio-dock-logo { position:absolute; left:22px; top:50%; transform:translateY(-50%); display:inline-flex; align-items:center; }
  .studio-dock-logo img { height:36px; width:auto; display:block; }
  .studio-dock-inner { display:flex; align-items:center; justify-content:center; gap:12px; flex-wrap:wrap; min-height:42px;
    font-size:13px; font-weight:600; color:var(--ink-4,#5a657a); }
  /* The studio template is a fixed-height app shell (.app is 100vh; .main is the
     scroller, content in .page). Clear the scroll content so nothing hides behind
     the fixed dock. */
  .app .main .page { padding-bottom:120px; }
  @media (max-width:760px) { .studio-dock-logo { display:none; } }
</style>
<div class="studio-dock" id="studioDock" role="contentinfo">
  <a class="studio-dock-logo" href="/app-2026v4.php" aria-label="ReliCheck home"><img src="/logo-brand.svg" alt="ReliCheck"></a>
  <div class="studio-dock-inner"><?= htmlspecialchars($_studio['name'] ?? 'ReliCheck') ?></div>
</div>
<script>
  // The dock must anchor to the viewport, but the studio template nests it inside
  // a 100vh app-shell. Re-parent it to <body> so position:fixed is reliable.
  (function () { var d = document.getElementById('studioDock'); if (d && d.parentNode !== document.body) document.body.appendChild(d); })();
</script>

<!-- Slide-over: triggered by [data-toggle-assist] in the topbar.
     Contains "What This Means" cards (if the page set $teaching_cards)
     plus the Ask ReliCheck input. -->
<div class="assist-overlay" id="assistOverlay" aria-hidden="true"></div>
<aside class="assist-panel" id="assistPanel" aria-hidden="true" aria-label="Ask ReliCheck and What This Means">
  <div class="assist-panel-head">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent);"><path d="M12 2l1.8 5.4L19 9l-5.2 1.6L12 16l-1.8-5.4L5 9l5.2-1.6z"/></svg>
    <h2 class="assist-panel-title">Ask ReliCheck</h2>
    <button type="button" class="assist-panel-close" aria-label="Close" data-assist-close>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>

  <div class="assist-panel-body">

    <?php if (!empty($teaching_cards)): ?>
      <div class="assist-section-h">What This Means</div>
      <div class="teach-cards">
        <?php foreach ($teaching_cards as $_card): ?>
          <a class="teach-card" href="<?= htmlspecialchars($_card['route'] ?? '#') ?>" data-fam="<?= htmlspecialchars($_card['fam'] ?? '') ?>">
            <span class="teach-tag"><?= htmlspecialchars($_card['tag'] ?? '') ?></span>
            <div class="teach-title"><?= htmlspecialchars($_card['title'] ?? '') ?></div>
            <div class="teach-sub"><?= htmlspecialchars($_card['sub'] ?? '') ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="assist-section-h">Conversation</div>
    <div class="assist-chat">
      <div class="assist-chat-bubble">
        Ready when you need a second look. Ask about your data, a finding, or what a number means.
      </div>
    </div>
  </div>

  <div class="assist-chat-input">
    <input type="text" placeholder="Ask about your data, methodology, or this view..." aria-label="Ask ReliCheck"/>
    <button type="button" aria-label="Send">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="4"/><polyline points="6 10 12 4 18 10"/></svg>
    </button>
  </div>
</aside>

<!-- Universal studio actions: Save to report, Print, Export, Ask ReliCheck.
     Every analysis app gets these for free via the studio template. Apps
     expose their computed state on window.RELICHECK_APP_STATE so Save can
     snapshot it. -->
<script>
(function () {
  function showToast(msg, tone) {
    const t = document.createElement('div');
    t.className = 'studio-toast' + (tone === 'warn' ? ' is-warn' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('is-on'));
    setTimeout(() => {
      t.classList.remove('is-on');
      setTimeout(() => t.remove(), 300);
    }, 2500);
  }

  function saveCurrentView() {
    // Per [[relicheck-reports-model]]: storage shape is three-layer:
    //   Project (project_id) → Reports (report_id) → Blocks (saved snapshots)
    // v1 uses a single 'default' report per project. Multi-report UI lands
    // when the Reporting section's pages are built; the key already
    // encodes report_id so no data migration is needed then.
    const studioSlug   = document.body.getAttribute('data-current-studio') || 'unknown';
    const projectId    = document.body.getAttribute('data-project-id')
                       || (window.RELICHECK_PROJECT_ID && String(window.RELICHECK_PROJECT_ID))
                       || 'untitled-project';
    const reportId     = 'default';
    const projectLabel = document.querySelector('.sb-project-name')
      ? document.querySelector('.sb-project-name').textContent.trim().replace(/\s+/g, ' ')
      : '';
    const appState = window.RELICHECK_APP_STATE;
    if (!appState) {
      showToast('Nothing to save yet, this view is still computing.', 'warn');
      return;
    }
    const block = {
      id:        'b_' + Date.now().toString(36),
      addedAt:   new Date().toISOString(),
      studio:    studioSlug,
      project:   projectLabel,
      app:       appState.app_key || 'unknown',
      appName:   appState.app_name || 'Analysis',
      summary:   appState.summary || '',
      payload:   appState,
    };
    const key = 'relicheck.report.' + projectId + '.' + reportId;
    let report;
    try { report = JSON.parse(window.localStorage.getItem(key) || '{}'); }
    catch (e) { report = {}; }
    if (!report.blocks)    report.blocks    = [];
    if (!report.project)   report.project   = projectLabel;
    if (!report.projectId) report.projectId = projectId;
    if (!report.reportId)  report.reportId  = reportId;
    if (!report.studio)    report.studio    = studioSlug;
    report.blocks.push(block);
    try {
      window.localStorage.setItem(key, JSON.stringify(report));
    } catch (e) {
      showToast('Could not save (storage error).', 'warn');
      return;
    }
    const n = report.blocks.length;
    showToast('Saved to report (' + n + ' block' + (n === 1 ? '' : 's') + ').');

    // Durable write: POST to /api/saved_blocks.php. localStorage is the
    // synchronous source of truth for the legacy Reporting + Interpretation
    // engines; the DB copy is what survives a browser wipe and (later) makes
    // multi-device, share-this-report, and team workflows possible.
    // Failure here is silent on purpose.
    const projectIdInt = parseInt(projectId, 10);
    if (projectIdInt > 0 && ['survey','mm','tia','360','strength-survey'].indexOf(studioSlug) !== -1) {
      fetch('/api/saved_blocks.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          studio:     studioSlug,
          project_id: projectIdInt,
          report_id:  reportId,
          block:      block,
        }),
      }).catch(function () { /* silent — localStorage holds the block */ });
    }
  }

  function printCurrentView() { window.print(); }

  function exportProjectDataset() {
    const projectId = (document.body.getAttribute('data-project-id') || '').trim();
    if (!projectId) { showToast('Open a project before exporting.'); return; }
    let dataset = null;
    try {
      const raw = window.localStorage.getItem('relicheck.dataset.' + projectId);
      if (raw) {
        const wrap = JSON.parse(raw);
        if (wrap && wrap.payload && wrap.payload.dataset) dataset = wrap.payload.dataset;
      }
    } catch (e) {}
    if (!dataset || !dataset.variables || !dataset.variables.length) {
      showToast('No dataset to export yet. Upload data first.');
      return;
    }
    const heads = dataset.variables.map(v => '"' + String(v.name).replace(/"/g, '""') + '"').join(',');
    const n     = dataset.rowCount || (dataset.variables[0].values ? dataset.variables[0].values.length : 0);
    const lines = [heads];
    for (let i = 0; i < n; i++) {
      lines.push(dataset.variables.map(v => {
        const cell = (v.values && v.values[i] != null) ? String(v.values[i]) : '';
        return /[",\n]/.test(cell) ? '"' + cell.replace(/"/g, '""') + '"' : cell;
      }).join(','));
    }
    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = 'relicheck-dataset-' + projectId + '.csv';
    document.body.appendChild(a); a.click();
    setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 100);
    showToast('Dataset exported.');
  }

  // ---------- Slide-over: Ask ReliCheck + What This Means ----------
  function openAssist() {
    document.getElementById('assistOverlay').classList.add('is-open');
    document.getElementById('assistPanel').classList.add('is-open');
    document.getElementById('assistPanel').setAttribute('aria-hidden', 'false');
    setTimeout(() => {
      const input = document.querySelector('#assistPanel input');
      if (input) input.focus();
    }, 250);
  }
  function closeAssist() {
    document.getElementById('assistOverlay').classList.remove('is-open');
    document.getElementById('assistPanel').classList.remove('is-open');
    document.getElementById('assistPanel').setAttribute('aria-hidden', 'true');
  }

  document.addEventListener('click', function (e) {
    // Studio actions
    const btn = e.target.closest('[data-studio-action]');
    if (btn) {
      const action = btn.getAttribute('data-studio-action');
      if (action === 'save')    saveCurrentView();
      if (action === 'print')   printCurrentView();
      if (action === 'export')  exportProjectDataset();
      return;
    }
    // Assist toggle
    if (e.target.closest('[data-toggle-assist]')) { openAssist(); return; }
    if (e.target.closest('[data-assist-close]'))   { closeAssist(); return; }
    if (e.target.closest('#assistOverlay'))         { closeAssist(); return; }

    // .rc-stub action buttons — fire a custom event so engines / pages
    // can listen for Run / Configure / Learn and react however they want.
    // Default behavior on "run": hide the stub and reveal #rcResultHost.
    const stubBtn = e.target.closest('[data-rc-action]');
    if (stubBtn) {
      const stub   = stubBtn.closest('[data-rc-stub]');
      const action = stubBtn.getAttribute('data-rc-action');
      document.dispatchEvent(new CustomEvent('rc:stub-action', {
        detail: { action: action, stub: stub, button: stubBtn }
      }));
      if (action === 'run') {
        if (stub) stub.hidden = true;
        const host = document.getElementById('rcResultHost');
        if (host) host.hidden = false;
      } else if (action === 'learn') {
        // Default: open the Ask ReliCheck slide-over so the user can ask
        // about what this analysis means before running it.
        openAssist();
      }
      // 'configure' has no default — page-specific listeners handle it
      // via the rc:stub-action event above.
      return;
    }
  });

  // Escape closes the slide-over
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('assistPanel').classList.contains('is-open')) {
      closeAssist();
    }
  });
})();
</script>
