/* =============================================================================
   rssi-reliability.js — Interactive Cronbach analyzer

   Lives inside the Reliability Readiness detail view on /rssi-upload.php.
   Renders a per-item table that lets the user toggle items in/out and
   watch Cronbach's alpha + scale stats recompute in real time. Exports the
   revised scale spec as JSON, CSV, DOCX, or PDF.

   Public entry point:  window.RSSI_RELIABILITY.mount(container, options)
   ============================================================================= */
(function () {
  'use strict';

  /* ────────────────────────────────────────────────────────────
   *  MATH (Cronbach α, item-total r, alpha-if-deleted)
   * ──────────────────────────────────────────────────────────── */
  function num(v) { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function mean(arr) { return arr.length ? arr.reduce(function (s, v) { return s + v; }, 0) / arr.length : 0; }
  function variance(arr) {
    if (arr.length < 2) return 0;
    const m = mean(arr);
    return arr.reduce(function (s, v) { return s + (v - m) * (v - m); }, 0) / (arr.length - 1);
  }
  function sd(arr) { return Math.sqrt(variance(arr)); }
  function pearson(a, b) {
    const n = Math.min(a.length, b.length);
    if (n < 2) return 0;
    let ma = 0, mb = 0;
    for (let i = 0; i < n; i++) { ma += a[i]; mb += b[i]; }
    ma /= n; mb /= n;
    let cov = 0, va = 0, vb = 0;
    for (let i = 0; i < n; i++) {
      cov += (a[i] - ma) * (b[i] - mb);
      va  += (a[i] - ma) * (a[i] - ma);
      vb  += (b[i] - mb) * (b[i] - mb);
    }
    const denom = Math.sqrt(va * vb);
    return denom === 0 ? 0 : cov / denom;
  }

  /* Build complete-case matrix across a set of items (each item is an
     array of numbers, same length, with nulls where missing). Returns
     a 2D array [respondent][item] with no nulls. */
  function completeCases(items) {
    if (!items.length) return [];
    const n = items[0].length;
    const matrix = [];
    for (let i = 0; i < n; i++) {
      let ok = true;
      for (let j = 0; j < items.length; j++) {
        if (items[j][i] == null || isNaN(items[j][i])) { ok = false; break; }
      }
      if (ok) {
        const row = new Array(items.length);
        for (let j = 0; j < items.length; j++) row[j] = items[j][i];
        matrix.push(row);
      }
    }
    return matrix;
  }

  /* Cronbach α across k items (each item = array of values, may contain nulls).
     Uses complete-case data. Returns null if k < 2 or fewer than 5 cases. */
  function cronbachAlpha(items) {
    const k = items.length;
    if (k < 2) return null;
    const matrix = completeCases(items);
    if (matrix.length < 5) return null;
    const itemVars = [];
    for (let j = 0; j < k; j++) {
      const col = matrix.map(function (r) { return r[j]; });
      itemVars.push(variance(col));
    }
    const sums = matrix.map(function (r) { return r.reduce(function (a, b) { return a + b; }, 0); });
    const totalVar = variance(sums);
    if (totalVar === 0) return 0;
    const itemVarSum = itemVars.reduce(function (a, b) { return a + b; }, 0);
    return (k / (k - 1)) * (1 - itemVarSum / totalVar);
  }

  /* Item-total correlation: for item `idx`, the correlation between
     item[idx]'s values and the sum of all OTHER items' values, on
     complete cases. */
  function itemTotal(items, idx) {
    if (items.length < 2) return null;
    const matrix = completeCases(items);
    if (matrix.length < 5) return null;
    const itemVals = matrix.map(function (r) { return r[idx]; });
    const restSums = matrix.map(function (r) {
      let s = 0;
      for (let j = 0; j < r.length; j++) if (j !== idx) s += r[j];
      return s;
    });
    return pearson(itemVals, restSums);
  }

  function bandForAlpha(a) {
    if (a == null) return { label: '—', class: 'neutral' };
    if (a >= 0.85) return { label: 'Excellent', class: 'good' };
    if (a >= 0.70) return { label: 'Good',      class: 'good' };
    if (a >= 0.60) return { label: 'Marginal',  class: 'warn' };
    return              { label: 'Low',       class: 'bad' };
  }

  /* ────────────────────────────────────────────────────────────
   *  STATE
   * ──────────────────────────────────────────────────────────── */
  let allLikertItems = [];      // [{name, label, values: [num|null]}]
  let includeMap    = {};       // { itemName: true/false }
  let originalAlpha = null;     // computed once on first mount
  let projectKey    = 'rssi-upload'; // localStorage scope

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
    const a = cronbachAlpha(itemArrays);
    const matrix = completeCases(itemArrays);
    const sums = matrix.map(function (r) { return r.reduce(function (s, v) { return s + v; }, 0); });
    return {
      k: inc.length,
      n: matrix.length,
      alpha: a,
      band: bandForAlpha(a),
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
        irT = itemTotal(incArrays, idx);
        // alpha-if-deleted = alpha computed on (incArrays minus this one)
        const without = incArrays.filter(function (_, j) { return j !== idx; });
        alphaDel = cronbachAlpha(without);
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
            '<p style="margin:0;font-size:13px;color:var(--ink-4,#5f6368);">Toggle items in or out to see how Cronbach&rsquo;s &alpha; reacts. Items flagged in green are dragging the scale down &mdash; removing them lifts &alpha;.</p>',
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
        '</tr>',
      ].join('');
    }).join('');

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

  /* ────────────────────────────────────────────────────────────
   *  RECOMPUTE the full RSSI score from the currently-included set
   *  and repaint the hero (score ring, badge, verdict, dimensions,
   *  issues). The analyzer itself stays mounted with its state intact.
   * ──────────────────────────────────────────────────────────── */
  function recomputeRSSIAndRepaintHero() {
    if (!window.RSSI_COMPUTE || !window.RSSI_UPDATE_FROM_RESULT) return;
    if (!window.RSSI_DATASET || !Array.isArray(window.RSSI_DATASET.variables)) return;

    const includedNames = {};
    allLikertItems.forEach(function (it) {
      if (includeMap[it.name] !== false) includedNames[it.name] = true;
    });

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
      window.RSSI_UPDATE_FROM_RESULT(newResult);
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

      '@media (max-width: 760px) {',
        '.rel-banner { grid-template-columns:1fr 1fr; }',
      '}',
    ].join('\n');
    document.head.appendChild(s);
  }

  /* ────────────────────────────────────────────────────────────
   *  PUBLIC: mount(container, options)
   * ──────────────────────────────────────────────────────────── */
  window.RSSI_RELIABILITY = {
    mount: function (container, options) {
      options = options || {};
      mountEl = container;
      if (!mountEl) return;

      // Get the Likert items from the in-memory dataset, falling back
      // to localStorage if the page was refreshed.
      let dataset = window.RSSI_DATASET;
      if (!dataset || !Array.isArray(dataset.variables)) {
        try {
          const raw = window.localStorage.getItem('rssi.dataset.cache');
          if (raw) dataset = JSON.parse(raw);
        } catch (e) {}
      }
      if (!dataset || !Array.isArray(dataset.variables)) {
        mountEl.innerHTML = '<div style="padding:30px;background:#FAFBFC;border:1px dashed #d8dde3;border-radius:14px;color:#5f6368;font-size:13px;text-align:center;">Upload a survey from the RSSI landing to use the interactive analyzer.</div>';
        return;
      }
      allLikertItems = dataset.variables.filter(function (v) {
        return v.types && v.types.indexOf('likert') !== -1;
      });
      if (allLikertItems.length < 2) {
        mountEl.innerHTML = '<div style="padding:30px;background:#FAFBFC;border:1px dashed #d8dde3;border-radius:14px;color:#5f6368;font-size:13px;text-align:center;">Need at least 2 Likert items to run an item analysis. This dataset has ' + allLikertItems.length + '.</div>';
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
      originalAlpha = cronbachAlpha(allArrays);

      renderShell();
    },
  };
})();
