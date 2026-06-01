/* =============================================================================
   rssi-reliability.js — Interactive Cronbach analyzer

   Lives inside the Reliability detail view on /rssi-upload.php (Spec §4).
   Renders a per-item table that lets the user toggle items in/out and
   watch Cronbach's alpha + scale stats recompute in real time. Exports the
   revised scale spec as JSON, CSV, DOCX, or PDF.

   Public entry point:  window.RSSI_RELIABILITY.mount(container, options)
   ============================================================================= */
(function () {
  'use strict';

  /* ────────────────────────────────────────────────────────────
   *  MATH — Cronbach α, item-total r, complete-cases, banding all
   *  delegated to the canonical engine at
   *  /apps/strength-index/strength-index.js (window.RSSI_MATH).
   *  Local helpers below are display-only (parsing, mean/sd for the
   *  per-item table); the scoring math lives in the canonical engine.
   * ──────────────────────────────────────────────────────────── */
  function num(v) { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function mean(arr) { return arr.length ? arr.reduce(function (s, v) { return s + v; }, 0) / arr.length : 0; }
  function variance(arr) {
    if (arr.length < 2) return 0;
    const m = mean(arr);
    return arr.reduce(function (s, v) { return s + (v - m) * (v - m); }, 0) / (arr.length - 1);
  }
  function sd(arr) { return Math.sqrt(variance(arr)); }

  /* ────────────────────────────────────────────────────────────
   *  STATE
   * ──────────────────────────────────────────────────────────── */
  let allLikertItems = [];      // [{name, label, values: [num|null]}]
  let includeMap    = {};       // { itemName: true/false }
  let originalAlpha = null;     // computed once on first mount
  let originalStrength = null;  // RSSI Strength Index with ALL items (captured once)
  let adjustedStrength = null;  // RSSI Strength Index with the current included set
  let projectKey    = 'rssi-upload'; // localStorage scope

  /* Compute the full RSSI Strength Index for a given included-item set,
     reusing the same dataset-rebuild the live hero recompute uses. Returns
     a rounded score, or null when the engine/dataset is unavailable. This
     is a diagnostic simulation: it never writes back to the saved report. */
  function strengthForIncluded(includedNames) {
    if (!window.RSSI_COMPUTE || !window.RSSI_DATASET || !Array.isArray(window.RSSI_DATASET.variables)) return null;
    const newVars = window.RSSI_DATASET.variables.filter(function (v) {
      if (v.types && v.types[0] === 'likert') return !!includedNames[v.name];
      return true;
    });
    try {
      const r = window.RSSI_COMPUTE({
        source: window.RSSI_DATASET.source,
        rowCount: window.RSSI_DATASET.rowCount,
        variables: newVars,
      });
      return (r && typeof r.strength === 'number') ? Math.round(r.strength) : null;
    } catch (e) { return null; }
  }
  function currentIncludedNames() {
    const m = {};
    allLikertItems.forEach(function (it) { if (includeMap[it.name] !== false) m[it.name] = true; });
    return m;
  }

  function storageKey() { return 'rssi.reliability.include.' + projectKey; }

  function loadIncludeMap() {
    try {
      const raw = window.localStorage.getItem(storageKey());
      if (raw) return JSON.parse(raw);
    } catch (e) {}
    return null;
  }
  function saveIncludeMap() {
    try { window.localStorage.setItem(storageKey(), JSON.stringify(includeMap)); } catch (e) {}
  }

  /* ────────────────────────────────────────────────────────────
   *  COMPUTE — return live stats for currently-included items
   * ──────────────────────────────────────────────────────────── */
  function includedItems() {
    return allLikertItems.filter(function (it) { return includeMap[it.name] !== false; });
  }
  function liveStats() {
    const inc = includedItems();
    const itemArrays = inc.map(function (it) { return it.values.map(num); });
    const a = window.RSSI_MATH.cronbachAlpha(itemArrays);
    const matrix = window.RSSI_MATH.completeCases(itemArrays);
    const sums = matrix.map(function (r) { return r.reduce(function (s, v) { return s + v; }, 0); });
    return {
      k: inc.length,
      n: matrix.length,
      alpha: a,
      band: window.RSSI_MATH.bandForAlpha(a),
      scaleMean: sums.length ? mean(sums) : 0,
      scaleSd:   sums.length ? sd(sums)   : 0,
    };
  }

  /* Per-item table data — recomputed on every toggle.
     For each item: mean, SD, n, included flag, item-total r (only if
     included), and α-if-deleted (only if included). */
  function itemRows() {
    const incNames = new Set();
    allLikertItems.forEach(function (it) { if (includeMap[it.name] !== false) incNames.add(it.name); });

    const incItems = includedItems();
    const incArrays = incItems.map(function (it) { return it.values.map(num); });
    const incNameToIdx = {};
    incItems.forEach(function (it, i) { incNameToIdx[it.name] = i; });

    return allLikertItems.map(function (it) {
      const vals  = it.values.map(num);
      const nonN  = vals.filter(function (v) { return v != null; });
      const isInc = incNames.has(it.name);
      let irT = null, alphaDel = null;
      if (isInc && incItems.length >= 2) {
        const idx = incNameToIdx[it.name];
        irT = window.RSSI_MATH.itemTotal(incArrays, idx);
        // alpha-if-deleted = alpha computed on (incArrays minus this one)
        const without = incArrays.filter(function (_, j) { return j !== idx; });
        alphaDel = window.RSSI_MATH.cronbachAlpha(without);
      }
      return {
        name:    it.name,
        label:   it.label || it.name,
        n:       nonN.length,
        mean:    nonN.length ? mean(nonN) : null,
        sd:      nonN.length > 1 ? sd(nonN) : null,
        included: isInc,
        itemTotal: irT,
        alphaIfDel: alphaDel,
      };
    });
  }

  /* ────────────────────────────────────────────────────────────
   *  RENDER
   * ──────────────────────────────────────────────────────────── */
  let mountEl = null;

  function html(strings /* , ...values */) {
    // Tiny tagged-template helper (no escaping — caller controls)
    return Array.prototype.slice.call(arguments).join('');
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
    });
  }
  function fmt(x, d) { if (x == null || !isFinite(x)) return '—'; return Number(x).toFixed(d == null ? 2 : d); }

  function renderShell() {
    if (!mountEl) return;
    mountEl.innerHTML = [
      '<div class="rel-analyzer">',
        '<div class="rel-head">',
          '<div>',
            '<h3 style="margin:0 0 4px;font-size:17px;font-weight:600;letter-spacing:-0.01em;">Interactive Item Analysis</h3>',
            '<p style="margin:0;font-size:13px;color:var(--ink-4,#5f6368);">Toggle items in or out to see how Cronbach&rsquo;s &alpha; and the Strength Index react. The <strong>Signal</strong> column flags which items strengthen, weaken, or may not belong on the scale.</p>',
          '</div>',
          '<div class="rel-actions">',
            '<button type="button" class="rel-btn rel-btn-ghost" data-action="reset">Reset to original</button>',
            '<div class="rel-download-wrap">',
              '<button type="button" class="rel-btn rel-btn-primary" data-action="download-menu">',
                'Download &darr;',
              '</button>',
              '<div class="rel-download-menu" hidden>',
                '<button type="button" data-download="json">JSON &mdash; full spec</button>',
                '<button type="button" data-download="csv">CSV &mdash; included items</button>',
                '<button type="button" data-download="docx">DOCX &mdash; revised survey</button>',
                '<button type="button" data-download="pdf">PDF &mdash; reliability report</button>',
              '</div>',
            '</div>',
          '</div>',
        '</div>',

        /* Live banner */
        '<div class="rel-banner" id="relBanner"></div>',

        /* Strength Index impact (original vs adjusted simulation) */
        '<div class="rel-impact" id="relImpact"></div>',

        /* Item table */
        '<div class="rel-table-wrap">',
          '<table class="rel-table">',
            '<thead>',
              '<tr>',
                '<th class="th-inc">Use</th>',
                '<th class="th-item">Item</th>',
                '<th class="th-num">n</th>',
                '<th class="th-num">Mean</th>',
                '<th class="th-num">SD</th>',
                '<th class="th-num">Item&ndash;Total r</th>',
                '<th class="th-num">&alpha; if deleted</th>',
                '<th class="th-sig">Signal</th>',
              '</tr>',
            '</thead>',
            '<tbody id="relTbody"></tbody>',
          '</table>',
        '</div>',
      '</div>',
    ].join('');
    injectStyles();
    wireToplevel();
    refresh();
  }

  function refresh() {
    if (!mountEl) return;
    const stats = liveStats();
    const rows  = itemRows();

    /* Banner */
    const banner = mountEl.querySelector('#relBanner');
    const delta  = (originalAlpha != null && stats.alpha != null) ? (stats.alpha - originalAlpha) : null;
    const deltaStr = delta == null ? '' :
      (delta >= 0
        ? '<span class="rel-delta up">+' + delta.toFixed(3) + '</span>'
        : '<span class="rel-delta down">' + delta.toFixed(3) + '</span>');
    banner.innerHTML = [
      '<div class="rel-banner-cell">',
        '<div class="rel-banner-label">Items in scale</div>',
        '<div class="rel-banner-val">', stats.k, ' <span class="rel-banner-sub">of ', allLikertItems.length, '</span></div>',
      '</div>',
      '<div class="rel-banner-cell rel-banner-alpha rel-band-', stats.band.class, '">',
        '<div class="rel-banner-label">Cronbach&rsquo;s &alpha;</div>',
        '<div class="rel-banner-val">', (stats.alpha == null ? '—' : stats.alpha.toFixed(2)),
          ' <span class="rel-banner-sub">', esc(stats.band.label), '</span>',
        '</div>',
        deltaStr ? '<div class="rel-banner-delta">vs. original ' + deltaStr + '</div>' : '',
      '</div>',
      '<div class="rel-banner-cell">',
        '<div class="rel-banner-label">Complete responses</div>',
        '<div class="rel-banner-val">', stats.n, '</div>',
      '</div>',
      '<div class="rel-banner-cell">',
        '<div class="rel-banner-label">Scale mean (SD)</div>',
        '<div class="rel-banner-val">', fmt(stats.scaleMean, 2),
          ' <span class="rel-banner-sub">(', fmt(stats.scaleSd, 2), ')</span></div>',
      '</div>',
    ].join('');

    /* Table rows */
    const tbody = mountEl.querySelector('#relTbody');
    tbody.innerHTML = rows.map(function (r) {
      const removingHelps = (stats.alpha != null && r.alphaIfDel != null && r.alphaIfDel > stats.alpha + 0.005);
      const flagClass = !r.included ? 'rel-row-off' : (removingHelps ? 'rel-row-flag' : '');
      const adCell = r.alphaIfDel == null ? '—'
        : '<span class="' + (removingHelps ? 'rel-ad-up' : 'rel-ad-down') + '">' + r.alphaIfDel.toFixed(2) + '</span>';
      const sig = itemSignal(r, stats.alpha, removingHelps);
      const sigCell = sig
        ? '<span class="rel-sig rel-sig-' + sig.cls + '" title="' + esc(sig.tip) + '">' + esc(sig.label) + '</span>'
        : '<span class="rel-sig rel-sig-muted">—</span>';
      return [
        '<tr class="', flagClass, '" data-item="', esc(r.name), '">',
          '<td class="td-inc"><label class="rel-checkbox"><input type="checkbox" data-toggle="', esc(r.name), '"', r.included ? ' checked' : '', '><span></span></label></td>',
          '<td class="td-item">',
            '<div class="rel-item-name">', esc(r.label), '</div>',
            '<div class="rel-item-key">', esc(r.name), '</div>',
          '</td>',
          '<td class="td-num">', r.n, '</td>',
          '<td class="td-num">', fmt(r.mean, 2), '</td>',
          '<td class="td-num">', fmt(r.sd, 2), '</td>',
          '<td class="td-num">', r.itemTotal == null ? '—' : r.itemTotal.toFixed(2), '</td>',
          '<td class="td-num">', adCell, '</td>',
          '<td class="td-sig">', sigCell, '</td>',
        '</tr>',
      ].join('');
    }).join('');

    /* Strength Index impact readout (original vs adjusted simulation) */
    updateImpact();

    /* Wire row checkboxes */
    tbody.querySelectorAll('input[data-toggle]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        const name = cb.getAttribute('data-toggle');
        includeMap[name] = cb.checked;
        saveIncludeMap();
        refresh();
        recomputeRSSIAndRepaintHero(); // ← bubble up to the big RSSI hero
      });
    });
  }

  /* Plain-language signal for an item: does it strengthen, weaken, or
     possibly not belong on the scale? Derived purely from stats the table
     already shows (item-total r, α-if-deleted vs current α). Educational,
     not a scoring change. */
  function itemSignal(r, currentAlpha, removingHelps) {
    if (!r.included) return { cls: 'off', label: 'Excluded', tip: 'Currently removed from the scale. Toggle it back on to include it.' };
    const it = r.itemTotal;
    if (it != null && it < 0) {
      return { cls: 'bad', label: 'May not belong', tip: 'Negative item–total correlation: this item moves opposite the rest of the scale. Often a reverse-coding or off-construct problem.' };
    }
    if (removingHelps) {
      return { cls: 'warn', label: 'Weakens the scale', tip: 'Cronbach’s α rises when this item is removed — it is dragging the scale down. Weigh whether it still belongs conceptually before dropping it.' };
    }
    if (it != null && it < 0.30) {
      return { cls: 'warn', label: 'Needs review', tip: 'Low item–total correlation (below 0.30): this item relates weakly to the rest of the scale. Consider rewording or reviewing it.' };
    }
    if (it != null && it >= 0.30) {
      return { cls: 'good', label: 'Strengthens the scale', tip: 'Healthy item–total correlation, and removing it would not raise α — this item is pulling its weight.' };
    }
    return { cls: 'muted', label: '—', tip: '' };
  }

  /* Strength Index impact readout: original (all items) vs adjusted
     (current included set). A diagnostic simulation — it never changes the
     saved report until the user exports/saves a revised scenario. */
  function updateImpact() {
    const el = mountEl && mountEl.querySelector('#relImpact');
    if (!el) return;
    if (originalStrength == null) { el.innerHTML = ''; return; }
    const adj = (adjustedStrength == null) ? originalStrength : adjustedStrength;
    const dScore = adj - originalStrength;
    const excludedCount = allLikertItems.filter(function (it) { return includeMap[it.name] === false; }).length;
    const stats = liveStats();
    const dAlpha = (originalAlpha != null && stats.alpha != null) ? (stats.alpha - originalAlpha) : null;
    const changed = excludedCount > 0;

    const scoreDeltaStr = dScore === 0 ? ''
      : '<span class="rel-impact-delta ' + (dScore > 0 ? 'up' : 'down') + '">' + (dScore > 0 ? '+' : '') + dScore + '</span>';
    const alphaDeltaStr = (dAlpha == null || Math.abs(dAlpha) < 0.001) ? ''
      : '<span class="rel-impact-delta ' + (dAlpha > 0 ? 'up' : 'down') + '">' + (dAlpha > 0 ? '+' : '') + dAlpha.toFixed(3) + '</span>';

    el.innerHTML = [
      '<div class="rel-impact-row">',
        '<div class="rel-impact-cell">',
          '<div class="rel-impact-label">Strength Index — original</div>',
          '<div class="rel-impact-val">', originalStrength, '<span class="rel-impact-out"> / 100</span></div>',
        '</div>',
        '<div class="rel-impact-arrow">', changed ? '→' : '=', '</div>',
        '<div class="rel-impact-cell">',
          '<div class="rel-impact-label">', changed ? 'Adjusted (simulated)' : 'Adjusted', '</div>',
          '<div class="rel-impact-val">', adj, '<span class="rel-impact-out"> / 100</span> ', scoreDeltaStr, '</div>',
        '</div>',
        '<div class="rel-impact-cell">',
          '<div class="rel-impact-label">Reliability (α) change</div>',
          '<div class="rel-impact-val rel-impact-alpha">', (dAlpha == null ? '—' : (stats.alpha == null ? '—' : stats.alpha.toFixed(2))), ' ', alphaDeltaStr, '</div>',
        '</div>',
      '</div>',
      changed
        ? '<div class="rel-impact-note">This is a <strong>diagnostic simulation</strong> of how dropping ' + excludedCount + ' item' + (excludedCount === 1 ? '' : 's') + ' would change your evidence. Your saved report is unchanged — use <strong>Download</strong> above to keep this revised scenario.</div>'
        : '<div class="rel-impact-note rel-impact-note-quiet">All items included. Toggle items below to simulate how the Strength Index would change.</div>',
    ].join('');
  }

  /* ────────────────────────────────────────────────────────────
   *  RECOMPUTE the full RSSI score from the currently-included set
   *  and repaint the hero (score ring, badge, verdict, dimensions,
   *  issues). The analyzer itself stays mounted with its state intact.
   * ──────────────────────────────────────────────────────────── */
  function recomputeRSSIAndRepaintHero() {
    if (!window.RSSI_COMPUTE || !window.RSSI_UPDATE_FROM_RESULT) return;
    if (!window.RSSI_DATASET || !Array.isArray(window.RSSI_DATASET.variables)) return;

    const includedNames = currentIncludedNames();

    // Build a new dataset:
    //   - Keep every non-Likert variable (categorical / numeric / open) as-is
    //   - Keep only the Likert items the user currently has checked
    const newVars = window.RSSI_DATASET.variables.filter(function (v) {
      if (v.types && v.types[0] === 'likert') return !!includedNames[v.name];
      return true;
    });
    const newDataset = {
      source:    window.RSSI_DATASET.source,
      rowCount:  window.RSSI_DATASET.rowCount,
      variables: newVars,
    };
    try {
      const newResult = window.RSSI_COMPUTE(newDataset);
      adjustedStrength = (newResult && typeof newResult.strength === 'number') ? Math.round(newResult.strength) : adjustedStrength;
      window.RSSI_UPDATE_FROM_RESULT(newResult);
      updateImpact(); // refresh the original-vs-adjusted readout with the new score
    } catch (e) {
      console.warn('Live RSSI recompute failed:', e);
    }
  }

  function wireToplevel() {
    // Reset
    mountEl.querySelector('[data-action="reset"]').addEventListener('click', function () {
      includeMap = {};
      allLikertItems.forEach(function (it) { includeMap[it.name] = true; });
      saveIncludeMap();
      refresh();
      recomputeRSSIAndRepaintHero();
    });
    // Download menu toggle
    const dlWrap = mountEl.querySelector('.rel-download-wrap');
    const dlMenu = mountEl.querySelector('.rel-download-menu');
    const dlBtn  = mountEl.querySelector('[data-action="download-menu"]');
    dlBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      dlMenu.hidden = !dlMenu.hidden;
    });
    document.addEventListener('click', function (e) {
      if (!dlWrap.contains(e.target)) dlMenu.hidden = true;
    });
    dlMenu.querySelectorAll('[data-download]').forEach(function (b) {
      b.addEventListener('click', function () {
        const fmt = b.getAttribute('data-download');
        download(fmt);
        dlMenu.hidden = true;
      });
    });
  }

  /* ────────────────────────────────────────────────────────────
   *  DOWNLOADS
   * ──────────────────────────────────────────────────────────── */
  function trigger(filename, content, mime) {
    const blob = (content instanceof Blob) ? content : new Blob([content], { type: mime || 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 100);
  }
  function ts() {
    const d = new Date();
    const pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    return d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '-' + pad(d.getHours()) + pad(d.getMinutes());
  }

  function download(format) {
    const stats = liveStats();
    const rows  = itemRows();
    const inc   = rows.filter(function (r) { return r.included; });

    if (format === 'json') {
      const blob = {
        generated_at: new Date().toISOString(),
        app: 'ReliCheck Strength Survey Index',
        scale: {
          item_count: inc.length,
          original_item_count: allLikertItems.length,
          cronbach_alpha: stats.alpha,
          alpha_band: stats.band.label,
          original_alpha: originalAlpha,
          delta: (originalAlpha != null && stats.alpha != null) ? (stats.alpha - originalAlpha) : null,
          complete_responses: stats.n,
          scale_mean: stats.scaleMean,
          scale_sd: stats.scaleSd,
        },
        items_included: inc.map(function (r) {
          return { name: r.name, label: r.label, n: r.n, mean: r.mean, sd: r.sd,
                   item_total_r: r.itemTotal, alpha_if_deleted: r.alphaIfDel };
        }),
        items_excluded: rows.filter(function (r) { return !r.included; }).map(function (r) {
          return { name: r.name, label: r.label };
        }),
      };
      trigger('rssi-revised-scale-' + ts() + '.json',
              JSON.stringify(blob, null, 2),
              'application/json');
      return;
    }

    if (format === 'csv') {
      const head = ['name', 'label', 'n', 'mean', 'sd', 'item_total_r', 'alpha_if_deleted'];
      const lines = [head.join(',')];
      inc.forEach(function (r) {
        const row = [
          csvCell(r.name), csvCell(r.label),
          r.n,
          r.mean == null ? '' : r.mean.toFixed(3),
          r.sd   == null ? '' : r.sd.toFixed(3),
          r.itemTotal == null ? '' : r.itemTotal.toFixed(3),
          r.alphaIfDel == null ? '' : r.alphaIfDel.toFixed(3),
        ];
        lines.push(row.join(','));
      });
      trigger('rssi-included-items-' + ts() + '.csv',
              lines.join('\n'),
              'text/csv');
      return;
    }

    if (format === 'docx') {
      // Word will open an HTML file with a .doc extension natively.
      const head =
        '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">' +
        '<head><meta charset="utf-8"><title>RSSI Revised Survey</title>' +
        '<style>body{font-family:Calibri,Arial,sans-serif;font-size:11pt;color:#1d1d1f;}h1{font-size:20pt;margin:0 0 6pt;}h2{font-size:14pt;margin:18pt 0 6pt;color:#0A6FE8;}p.meta{color:#5f6368;font-size:10pt;}ol{padding-left:18pt;}li{margin-bottom:10pt;}.item-key{color:#8E8E93;font-size:9pt;font-family:Consolas,monospace;}</style>' +
        '</head><body>';
      const meta =
        '<h1>Revised Survey</h1>' +
        '<p class="meta">Generated by ReliCheck Strength Survey Index &middot; ' + esc(new Date().toLocaleString()) + '<br>' +
        'Items included: <b>' + inc.length + '</b> of ' + allLikertItems.length +
        ' &middot; Cronbach &alpha; = <b>' + (stats.alpha == null ? '—' : stats.alpha.toFixed(2)) + '</b> (' + esc(stats.band.label) + ')</p>';
      const list =
        '<h2>Included Items</h2>' +
        '<ol>' +
        inc.map(function (r) {
          return '<li>' + esc(r.label) + ' <span class="item-key">[' + esc(r.name) + ']</span></li>';
        }).join('') +
        '</ol>';
      const excluded = rows.filter(function (r) { return !r.included; });
      const excludedSec = excluded.length
        ? '<h2>Excluded Items</h2><ol>' +
          excluded.map(function (r) { return '<li>' + esc(r.label) + ' <span class="item-key">[' + esc(r.name) + ']</span></li>'; }).join('') +
          '</ol>'
        : '';
      const tail = '</body></html>';
      trigger('rssi-revised-survey-' + ts() + '.doc',
              head + meta + list + excludedSec + tail,
              'application/msword');
      return;
    }

    if (format === 'pdf') {
      // Open a clean print window with just the reliability report, then trigger print.
      // User can choose "Save as PDF" in the print dialog.
      const w = window.open('', '_blank', 'width=820,height=1024');
      if (!w) { alert('Pop-ups are blocked. Allow pop-ups for this site to download a PDF.'); return; }
      const html = buildPdfHtml(stats, rows, inc);
      w.document.open();
      w.document.write(html);
      w.document.close();
      // Give the new window a moment to render before printing
      setTimeout(function () { try { w.focus(); w.print(); } catch (e) {} }, 250);
      return;
    }
  }
  function csvCell(s) {
    s = String(s == null ? '' : s);
    if (/[",\n\r]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
    return s;
  }
  function buildPdfHtml(stats, rows, inc) {
    const deltaStr = (originalAlpha != null && stats.alpha != null)
      ? ((stats.alpha - originalAlpha) >= 0 ? '+' : '') + (stats.alpha - originalAlpha).toFixed(3)
      : '—';
    return [
      '<!doctype html><html><head><meta charset="utf-8"><title>RSSI Reliability Report</title>',
      '<style>',
      '@page { size: letter portrait; margin: 18mm 16mm; }',
      'body{font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text",Inter,Helvetica,sans-serif;color:#1d1d1f;font-size:11pt;line-height:1.5;}',
      'h1{font-size:22pt;margin:0 0 6pt;letter-spacing:-0.015em;}',
      'p.lede{color:#5f6368;margin:0 0 18pt;}',
      '.head-grid{display:flex;gap:18pt;border-top:1px solid #ddd;border-bottom:1px solid #ddd;padding:14pt 0;margin-bottom:18pt;}',
      '.cell{flex:1;}',
      '.cell .l{font-size:9pt;color:#8E8E93;text-transform:uppercase;letter-spacing:0.06em;}',
      '.cell .v{font-size:20pt;font-weight:600;letter-spacing:-0.02em;margin-top:4pt;}',
      'h2{font-size:13pt;margin:20pt 0 10pt;color:#0A6FE8;}',
      'table{width:100%;border-collapse:collapse;font-size:10pt;}',
      'th{text-align:left;background:#F6F7F9;padding:7pt 8pt;border-bottom:1px solid #ddd;font-weight:600;color:#5f6368;}',
      'td{padding:6pt 8pt;border-bottom:1px solid #eee;}',
      'td.num{text-align:right;font-variant-numeric:tabular-nums;}',
      '.excluded{color:#8E8E93;text-decoration:line-through;}',
      '.foot{margin-top:20pt;font-size:9pt;color:#8E8E93;text-align:center;}',
      '</style></head><body>',
      '<h1>RSSI Reliability Report</h1>',
      '<p class="lede">Generated by ReliCheck Strength Survey Index &middot; ', esc(new Date().toLocaleString()), '</p>',
      '<div class="head-grid">',
        '<div class="cell"><div class="l">Items in scale</div><div class="v">', stats.k, ' / ', allLikertItems.length, '</div></div>',
        '<div class="cell"><div class="l">Cronbach &alpha;</div><div class="v">', stats.alpha == null ? '—' : stats.alpha.toFixed(2), ' <span style="font-size:11pt;color:#5f6368;font-weight:500;">', esc(stats.band.label), '</span></div></div>',
        '<div class="cell"><div class="l">Δ vs. original</div><div class="v">', deltaStr, '</div></div>',
        '<div class="cell"><div class="l">Complete responses</div><div class="v">', stats.n, '</div></div>',
      '</div>',
      '<h2>Item Analysis</h2>',
      '<table><thead><tr><th>Item</th><th style="text-align:right;">n</th><th style="text-align:right;">Mean</th><th style="text-align:right;">SD</th><th style="text-align:right;">r<sub>it</sub></th><th style="text-align:right;">&alpha; if del</th></tr></thead><tbody>',
      rows.map(function (r) {
        const cls = r.included ? '' : ' class="excluded"';
        return '<tr' + cls + '>' +
          '<td>' + esc(r.label) + '</td>' +
          '<td class="num">' + r.n + '</td>' +
          '<td class="num">' + fmt(r.mean, 2) + '</td>' +
          '<td class="num">' + fmt(r.sd, 2) + '</td>' +
          '<td class="num">' + (r.itemTotal == null ? '—' : r.itemTotal.toFixed(2)) + '</td>' +
          '<td class="num">' + (r.alphaIfDel == null ? '—' : r.alphaIfDel.toFixed(2)) + '</td>' +
        '</tr>';
      }).join(''),
      '</tbody></table>',
      '<p class="foot">ReliCheck &middot; Strength Survey Index</p>',
      '</body></html>',
    ].join('');
  }

  /* ────────────────────────────────────────────────────────────
   *  STYLES (injected once)
   * ──────────────────────────────────────────────────────────── */
  let stylesInjected = false;
  function injectStyles() {
    if (stylesInjected) return;
    stylesInjected = true;
    const s = document.createElement('style');
    s.textContent = [
      '.rel-analyzer { font-family: inherit; color:#1d1d1f; margin-top: 20px; }',
      '.rel-head { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:16px; }',
      '.rel-actions { display:flex; gap:8px; align-items:center; }',
      '.rel-btn { padding:8px 14px; border-radius:9px; border:1px solid rgba(15,23,42,0.12); background:#fff; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; color:#1d1d1f; }',
      '.rel-btn:hover { background:#F7F7F9; }',
      '.rel-btn-primary { background:#007AFF; color:#fff; border-color:#007AFF; }',
      '.rel-btn-primary:hover { background:#0A6FE8; }',
      '.rel-btn-ghost { background:transparent; border-color:rgba(15,23,42,0.10); color:#5f6368; }',
      '.rel-btn-ghost:hover { background:rgba(15,23,42,0.04); color:#1d1d1f; }',
      '.rel-download-wrap { position:relative; }',
      '.rel-download-menu { position:absolute; top:calc(100% + 6px); right:0; background:#fff; border:1px solid rgba(15,23,42,0.10); border-radius:10px; box-shadow:0 8px 28px rgba(15,23,42,0.12); min-width:240px; padding:6px; z-index:30; }',
      '.rel-download-menu[hidden] { display:none; }',
      '.rel-download-menu button { display:block; width:100%; text-align:left; padding:9px 12px; border:none; background:transparent; font:inherit; font-size:13px; color:#1d1d1f; border-radius:6px; cursor:pointer; }',
      '.rel-download-menu button:hover { background:#F0F1F3; }',

      '.rel-banner { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; padding:16px 18px; background:#FAFBFC; border:1px solid rgba(15,23,42,0.06); border-radius:14px; margin-bottom:14px; }',
      '.rel-banner-cell { min-width:0; }',
      '.rel-banner-label { font-size:11px; font-weight:600; color:#8E8E93; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:3px; }',
      '.rel-banner-val { font-size:22px; font-weight:700; letter-spacing:-0.02em; line-height:1; color:#1d1d1f; }',
      '.rel-banner-sub { font-size:12px; color:#5f6368; font-weight:500; margin-left:4px; }',
      '.rel-banner-delta { font-size:11px; color:#5f6368; margin-top:4px; }',
      '.rel-band-good .rel-banner-val { color:#1A7E36; }',
      '.rel-band-warn .rel-banner-val { color:#B26A00; }',
      '.rel-band-bad  .rel-banner-val { color:#B82318; }',
      '.rel-delta.up { color:#1A7E36; font-weight:700; }',
      '.rel-delta.down { color:#B82318; font-weight:700; }',

      '.rel-table-wrap { border:1px solid rgba(15,23,42,0.08); border-radius:14px; overflow:hidden; }',
      '.rel-table { width:100%; border-collapse:collapse; font-size:13px; }',
      '.rel-table th { text-align:left; padding:10px 12px; background:#F7F8FA; color:#5f6368; font-weight:600; font-size:11.5px; text-transform:uppercase; letter-spacing:0.04em; border-bottom:1px solid rgba(15,23,42,0.08); }',
      '.rel-table th.th-num { text-align:right; }',
      '.rel-table td { padding:10px 12px; border-bottom:1px solid rgba(15,23,42,0.05); vertical-align:top; }',
      '.rel-table tr:last-child td { border-bottom:none; }',
      '.rel-table tr.rel-row-off td { color:#B0B3B8; }',
      '.rel-table tr.rel-row-flag { background:rgba(52,199,89,0.05); }',
      '.rel-table td.td-num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }',
      '.rel-item-name { font-weight:600; color:#1d1d1f; line-height:1.3; }',
      '.rel-row-off .rel-item-name { color:#8E8E93; text-decoration:line-through; }',
      '.rel-item-key { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:11px; color:#8E8E93; margin-top:2px; }',
      '.rel-ad-up { color:#1A7E36; font-weight:700; }',
      '.rel-ad-down { color:#5f6368; }',
      '.rel-checkbox { display:inline-flex; cursor:pointer; }',
      '.rel-checkbox input { position:absolute; opacity:0; pointer-events:none; }',
      '.rel-checkbox span { display:inline-block; width:18px; height:18px; border:1.5px solid rgba(15,23,42,0.25); border-radius:5px; background:#fff; position:relative; transition:background 0.12s, border-color 0.12s; }',
      '.rel-checkbox input:checked + span { background:#007AFF; border-color:#007AFF; }',
      '.rel-checkbox input:checked + span::after { content:""; position:absolute; left:5px; top:2px; width:5px; height:9px; border:solid #fff; border-width:0 2px 2px 0; transform:rotate(45deg); }',

      /* Strength Index impact readout (original vs adjusted simulation) */
      '.rel-impact { border:1px solid rgba(15,23,42,0.08); border-radius:14px; padding:14px 18px; margin-bottom:14px; background:#fff; }',
      '.rel-impact-row { display:flex; align-items:center; gap:18px; flex-wrap:wrap; }',
      '.rel-impact-cell { min-width:120px; }',
      '.rel-impact-label { font-size:11px; font-weight:600; color:#8E8E93; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:3px; }',
      '.rel-impact-val { font-size:24px; font-weight:700; letter-spacing:-0.02em; line-height:1; color:#1d1d1f; }',
      '.rel-impact-out { font-size:13px; font-weight:600; color:#8E8E93; }',
      '.rel-impact-alpha { font-size:18px; }',
      '.rel-impact-arrow { font-size:20px; color:#B0B3B8; font-weight:700; }',
      '.rel-impact-delta { font-size:13px; font-weight:700; margin-left:4px; }',
      '.rel-impact-delta.up { color:#1A7E36; }',
      '.rel-impact-delta.down { color:#B82318; }',
      '.rel-impact-note { margin-top:12px; font-size:12.5px; line-height:1.5; color:#5f6368; background:rgba(255,159,10,0.10); border-radius:9px; padding:9px 12px; }',
      '.rel-impact-note-quiet { background:#FAFBFC; color:#8E8E93; }',

      /* Per-item plain-language signal */
      '.rel-table th.th-sig { text-align:left; }',
      '.rel-table td.td-sig { white-space:nowrap; }',
      '.rel-sig { display:inline-block; font-size:11.5px; font-weight:600; padding:3px 9px; border-radius:999px; }',
      '.rel-sig-good { background:rgba(52,199,89,0.14); color:#1A7E36; }',
      '.rel-sig-warn { background:rgba(255,159,10,0.16); color:#B26A00; }',
      '.rel-sig-bad  { background:rgba(255,59,48,0.12); color:#B82318; }',
      '.rel-sig-off  { background:#F0F1F3; color:#8E8E93; }',
      '.rel-sig-muted { color:#B0B3B8; background:transparent; }',

      '@media (max-width: 760px) {',
        '.rel-banner { grid-template-columns:1fr 1fr; }',
        '.rel-impact-row { gap:12px; }',
      '}',
    ].join('\n');
    document.head.appendChild(s);
  }

  /* ────────────────────────────────────────────────────────────
   *  PUBLIC: mount(container, options)
   * ──────────────────────────────────────────────────────────── */
  /* Public accessor for the current refined-scale state, in the same
     shape the JSON-download path builds. Used by the print-report path
     in rssi.js (beforeprint handler) to populate the refined-scale
     section when the user has excluded items. Returns null when the
     analyzer hasn't mounted yet (no dataset, < 2 Likert items, etc.).
     Detection rule for "items were dropped": items_excluded.length > 0
     OR delta !== 0 (untouched scales return an empty excluded list and
     a zero/null delta). */
  function getRefinedScale() {
    if (!allLikertItems || allLikertItems.length === 0) return null;
    const stats = liveStats();
    const rows  = itemRows();
    const inc   = rows.filter(function (r) { return r.included; });
    const exc   = rows.filter(function (r) { return !r.included; });
    return {
      generated_at:        new Date().toISOString(),
      item_count:          inc.length,
      original_item_count: allLikertItems.length,
      cronbach_alpha:      stats.alpha,
      alpha_band:          stats.band ? stats.band.label : null,
      original_alpha:      originalAlpha,
      delta:               (originalAlpha != null && stats.alpha != null) ? (stats.alpha - originalAlpha) : null,
      complete_responses:  stats.n,
      scale_mean:          stats.scaleMean,
      scale_sd:            stats.scaleSd,
      items_included: inc.map(function (r) {
        return { name: r.name, label: r.label };
      }),
      items_excluded: exc.map(function (r) {
        return { name: r.name, label: r.label };
      }),
    };
  }

  window.RSSI_RELIABILITY = {
    getRefinedScale: getRefinedScale,
    mount: function (container, options) {
      options = options || {};
      mountEl = container;
      if (!mountEl) return;

      // Option-A fallback: when the analyzer has no raw response rows to work
      // with (a saved summary with the dataset detached, or the 0-response
      // demo), show a clear callout + actions instead of an empty Lab shell.
      var _dsId = window.RSSI_DATASET_ID;
      var fallbackHTML =
        '<div style="padding:24px;background:#FAFBFC;border:1px dashed #d8dde3;border-radius:14px;color:#5f6368;font-size:13px;line-height:1.55;text-align:center;">' +
          '<div style="font-weight:600;color:#15171a;margin-bottom:6px;">Interactive Reliability Lab is unavailable</div>' +
          'The raw response data is not attached to this saved report, so item toggles cannot be computed.' +
          '<div style="margin-top:14px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">' +
            (_dsId ? '<a href="/rssi-upload.php?dataset_id=' + encodeURIComponent(_dsId) + '" style="display:inline-block;padding:9px 16px;border-radius:10px;background:#2D8DFF;color:#fff;text-decoration:none;font-weight:600;">Reopen interactive analysis</a>' : '') +
            '<a href="/rssi.php" style="display:inline-block;padding:9px 16px;border-radius:10px;background:#fff;border:1px solid #d8dde3;color:#15171a;text-decoration:none;font-weight:600;">Upload data again</a>' +
          '</div>' +
        '</div>';

      // Get the Likert items from the in-memory dataset, falling back
      // to localStorage if the page was refreshed. The cache is skipped in
      // the demo/sample flow so a previously-opened dataset can't masquerade
      // as the sample (the sample has no raw rows → it shows the fallback).
      let dataset = window.RSSI_DATASET;
      if ((!dataset || !Array.isArray(dataset.variables)) && !window.RSSI_IS_DEMO) {
        try {
          const raw = window.localStorage.getItem('rssi.dataset.cache');
          if (raw) dataset = JSON.parse(raw);
        } catch (e) {}
      }
      if (!dataset || !Array.isArray(dataset.variables)) {
        mountEl.innerHTML = fallbackHTML;
        return;
      }
      allLikertItems = dataset.variables.filter(function (v) {
        return v.types && v.types.indexOf('likert') !== -1;
      });
      if (allLikertItems.length < 2) {
        mountEl.innerHTML = '<div style="padding:30px;background:#FAFBFC;border:1px dashed #d8dde3;border-radius:14px;color:#5f6368;font-size:13px;text-align:center;">Need at least 2 Likert items to run an item analysis. This dataset has ' + allLikertItems.length + '.</div>';
        return;
      }
      // No raw rows (detached summary or the 0-response demo) → fallback, never
      // an empty/dead Lab.
      var _maxAnswered = allLikertItems.reduce(function (m, it) {
        var n = (it.values || []).filter(function (x) { return x != null && x !== ''; }).length;
        return n > m ? n : m;
      }, 0);
      if (_maxAnswered < 2) {
        mountEl.innerHTML = fallbackHTML;
        return;
      }

      projectKey = options.projectKey || dataset.source || 'rssi-upload';

      // Restore prior include map (if user revised before), else include all.
      const saved = loadIncludeMap();
      includeMap = {};
      allLikertItems.forEach(function (it) {
        includeMap[it.name] = saved ? (saved[it.name] !== false) : true;
      });

      // Capture the original alpha (all items in) once
      const allArrays = allLikertItems.map(function (it) { return it.values.map(num); });
      originalAlpha = window.RSSI_MATH.cronbachAlpha(allArrays);

      // Capture the original Strength Index (all items in) once, and seed
      // the adjusted score from the current (possibly previously-revised)
      // include set. Both feed the Strength Index impact readout.
      const allIncluded = {};
      allLikertItems.forEach(function (it) { allIncluded[it.name] = true; });
      originalStrength = strengthForIncluded(allIncluded);
      adjustedStrength = strengthForIncluded(currentIncludedNames());

      renderShell();
    },
  };
})();
