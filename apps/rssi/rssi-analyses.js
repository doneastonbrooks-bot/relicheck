/* =============================================================================
   rssi-analyses.js — Native RSSI analysis renderers.

   Each renderer takes a list of Likert items and a target container, and
   paints a clean RSSI-styled analysis. Shares math helpers with the
   instrument-quality engine but renders in the RSSI design system instead
   of the studio template.

   Public API:
     window.RSSI_ANALYSES.renderValidity(container, likertItems)
     window.RSSI_ANALYSES.itemsFromDataset()  -> []

   To add a new analysis: write renderXxx(container, likertItems) and
   expose it on window.RSSI_ANALYSES.
   ============================================================================= */
(function () {
  'use strict';

  /* ─────────────────────────────────────────────────────────────
   *  MATH
   * ───────────────────────────────────────────────────────────── */
  function num(v) { const x = parseFloat(v); return isNaN(x) ? null : x; }
  function mean(arr) { return arr.length ? arr.reduce(function (s, v) { return s + v; }, 0) / arr.length : 0; }
  function variance(arr) {
    if (arr.length < 2) return 0;
    const m = mean(arr);
    return arr.reduce(function (s, v) { return s + (v - m) * (v - m); }, 0) / (arr.length - 1);
  }
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
  function completeCases(itemArrays) {
    if (!itemArrays.length) return { validCols: [], validRows: [] };
    const n = itemArrays[0].length;
    const validRows = [];
    for (let i = 0; i < n; i++) {
      let ok = true;
      for (let j = 0; j < itemArrays.length; j++) {
        if (itemArrays[j][i] == null || isNaN(itemArrays[j][i])) { ok = false; break; }
      }
      if (ok) validRows.push(i);
    }
    const validCols = itemArrays.map(function (col) {
      return validRows.map(function (idx) { return col[idx]; });
    });
    return { validCols: validCols, validRows: validRows };
  }
  function cronbachAlpha(itemArrays) {
    const k = itemArrays.length;
    if (k < 2) return null;
    const { validCols, validRows } = completeCases(itemArrays);
    if (validRows.length < 5) return null;
    const itemVarSum = validCols.reduce(function (s, c) { return s + variance(c); }, 0);
    const sums = validRows.map(function (_, idx) {
      return validCols.reduce(function (s, c) { return s + c[idx]; }, 0);
    });
    const totalVar = variance(sums);
    if (totalVar === 0) return 0;
    return (k / (k - 1)) * (1 - itemVarSum / totalVar);
  }
  function avgInterItemR(itemArrays) {
    const { validCols } = completeCases(itemArrays);
    const k = validCols.length;
    if (k < 2) return null;
    let sum = 0, pairs = 0;
    for (let i = 0; i < k; i++) {
      for (let j = i + 1; j < k; j++) {
        sum += pearson(validCols[i], validCols[j]);
        pairs++;
      }
    }
    return pairs ? sum / pairs : 0;
  }
  function itemRestCorrelations(itemArrays) {
    const { validCols, validRows } = completeCases(itemArrays);
    const k = validCols.length;
    if (k < 2 || validRows.length < 5) return validCols.map(function () { return null; });
    return validCols.map(function (item, i) {
      const rest = validRows.map(function (_, idx) {
        let s = 0;
        for (let j = 0; j < k; j++) if (j !== i) s += validCols[j][idx];
        return s;
      });
      return pearson(item, rest);
    });
  }

  /* ─────────────────────────────────────────────────────────────
   *  DATA SOURCE — pulls Likert items from the current RSSI dataset
   * ───────────────────────────────────────────────────────────── */
  function itemsFromDataset() {
    let dataset = window.RSSI_DATASET;
    if (!dataset || !Array.isArray(dataset.variables)) {
      try {
        const raw = window.localStorage.getItem('rssi.dataset.cache');
        if (raw) dataset = JSON.parse(raw);
      } catch (e) {}
    }
    if (!dataset || !Array.isArray(dataset.variables)) return [];
    return dataset.variables.filter(function (v) {
      return v.types && v.types.indexOf('likert') !== -1;
    });
  }

  /* ─────────────────────────────────────────────────────────────
   *  VALIDITY — extracted from instrument-quality engine,
   *  re-rendered in RSSI styling.
   * ───────────────────────────────────────────────────────────── */
  function computeValidity(likertItems) {
    if (likertItems.length < 2) {
      return { error: 'Need at least 2 Likert items.' };
    }
    const itemArrays = likertItems.map(function (it) { return it.values.map(num); });
    const { validCols, validRows } = completeCases(itemArrays);
    if (validRows.length < 5) {
      return { error: 'Need at least 5 complete responses across all items.' };
    }
    const alpha   = cronbachAlpha(itemArrays);
    const avgR    = avgInterItemR(itemArrays);
    const itemRest = itemRestCorrelations(itemArrays);
    const k = likertItems.length;
    const n = validRows.length;
    const rows = likertItems.map(function (it, i) {
      const r = itemRest[i];
      let flag = 'ok';
      if (r != null && r < 0)    flag = 'neg';
      else if (r != null && r < 0.30) flag = 'weak';
      return {
        name: it.name,
        label: it.label || it.name,
        n: validRows.length,
        itemRest: r,
        flag: flag,
      };
    });
    const negCount  = rows.filter(function (r) { return r.flag === 'neg'; }).length;
    const weakCount = rows.filter(function (r) { return r.flag === 'weak'; }).length;
    let status, statusTone, judgment, decision, nextStep;
    if (negCount >= 3 || (alpha != null && alpha < 0.60)) {
      status = 'Not Ready'; statusTone = 'alert';
      judgment = 'Significant validity concerns. The instrument is not yet ready to support strong claims, publication, or high-stakes decisions.';
      decision = 'Do not use for decisions yet. The instrument needs scoring corrections and item review before it can be trusted.';
      nextStep = 'Confirm reverse-coding on every _R item, rerun reliability, and review whether the instrument is one scale or several subscales.';
    } else if (negCount > 0 || weakCount >= Math.ceil(k * 0.2) || (avgR != null && avgR < 0.20) || (alpha != null && alpha < 0.70)) {
      status = 'Use with Caution'; statusTone = 'caution';
      judgment = 'Mixed evidence. The instrument has acceptable internal consistency, but item-level evidence suggests construct-alignment problems.';
      decision = 'Appropriate for exploratory analysis or internal review. Do not use for strong claims, publication, or high-stakes decisions until flagged items are reviewed.';
      nextStep = 'Confirm whether all _R items were reverse-scored. Then rerun reliability and factor structure checks. Consider analyzing subscales separately rather than one total score.';
    } else if ((avgR != null && avgR < 0.30) || weakCount > 0) {
      status = 'Mixed Evidence'; statusTone = 'warn';
      judgment = 'Validity evidence is mixed. The instrument is usable for exploratory analysis but should be strengthened before publication.';
      decision = 'Acceptable for internal use and exploratory analysis. Resolve the flagged items before publication or external reporting.';
      nextStep = 'Inspect the weak-item-rest items, consider revising or dropping them, and report by subscale where possible.';
    } else {
      status = 'Strong'; statusTone = 'strong';
      judgment = 'Validity evidence is strong. Items converge on the intended construct and the scale supports confident reporting.';
      decision = 'Ready to use. Standard caveats around content validity (expert wording review) still apply.';
      nextStep = 'No statistical correction needed. Confirm content and face validity with a subject-matter expert before publication.';
    }
    return {
      alpha: alpha, avgR: avgR, n: n, k: k,
      negCount: negCount, weakCount: weakCount,
      rows: rows,
      status: status, statusTone: statusTone,
      judgment: judgment, decision: decision, nextStep: nextStep,
    };
  }

  function fmt(x, d) { if (x == null || !isFinite(x)) return '—'; return Number(x).toFixed(d == null ? 2 : d); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
    });
  }

  function renderValidity(container) {
    if (!container) return;
    const items = itemsFromDataset();
    injectAnalysisStyles();
    if (items.length < 2) {
      container.innerHTML =
        '<div class="rssi-analysis-empty">Upload a Likert-scale dataset to run a validity review.</div>';
      return;
    }
    const v = computeValidity(items);
    if (v.error) {
      container.innerHTML = '<div class="rssi-analysis-empty">' + esc(v.error) + '</div>';
      return;
    }
    container.innerHTML = [
      '<div class="rssi-analysis">',
        /* Header banner */
        '<div class="rsa-header rsa-tone-', v.statusTone, '">',
          '<div class="rsa-status-pill">', esc(v.status), '</div>',
          '<h3 class="rsa-judgment">', esc(v.judgment), '</h3>',
        '</div>',

        /* 4 metric cards */
        '<div class="rsa-metric-grid">',
          metricCard('Cronbach&rsquo;s &alpha;',  fmt(v.alpha, 2),  alphaTone(v.alpha), 'Internal consistency of the scale.'),
          metricCard('Avg inter-item r',          fmt(v.avgR, 2),   avgRTone(v.avgR),   'How much items move together.'),
          metricCard('Negative items',            v.negCount,       flagTone(v.negCount, [0, 1, 3]), 'Items moving opposite the scale (likely reverse-coding errors).'),
          metricCard('Weak items',                v.weakCount,      flagTone(v.weakCount, [0, 1, 3]), 'Items with item-rest r < 0.30.'),
        '</div>',

        /* Item-rest table */
        '<div class="rsa-section-head"><h4>Item analysis</h4><p>Item-rest correlation: how strongly each item moves with the rest of the scale. Items below 0.30 are weakly aligned; negative values usually mean reverse-coding wasn\'t applied.</p></div>',
        '<div class="rsa-table-wrap">',
          '<table class="rsa-table">',
            '<thead><tr>',
              '<th class="rsa-th-item">Item</th>',
              '<th class="rsa-th-num">n</th>',
              '<th class="rsa-th-num">Item&ndash;Total r</th>',
              '<th class="rsa-th-flag">Status</th>',
            '</tr></thead>',
            '<tbody>',
              v.rows
                .slice()
                .sort(function (a, b) { return (a.itemRest ?? -2) - (b.itemRest ?? -2); })
                .map(function (r) {
                  const tone = r.flag === 'neg' ? 'alert' : (r.flag === 'weak' ? 'warn' : 'ok');
                  const lbl  = r.flag === 'neg' ? 'Negative — check reverse-coding'
                              : (r.flag === 'weak' ? 'Weak alignment' : 'Aligned');
                  return '<tr>' +
                    '<td class="rsa-td-item"><div class="rsa-item-label">' + esc(r.label) + '</div><div class="rsa-item-key">' + esc(r.name) + '</div></td>' +
                    '<td class="rsa-td-num">' + r.n + '</td>' +
                    '<td class="rsa-td-num"><span class="rsa-num rsa-num-' + tone + '">' + (r.itemRest == null ? '—' : r.itemRest.toFixed(2)) + '</span></td>' +
                    '<td class="rsa-td-flag"><span class="rsa-flag rsa-flag-' + tone + '">' + esc(lbl) + '</span></td>' +
                  '</tr>';
                }).join(''),
            '</tbody>',
          '</table>',
        '</div>',

        /* Decision guide */
        '<div class="rsa-decision-grid">',
          '<div class="rsa-decision-card">',
            '<div class="rsa-card-label">Decision guide</div>',
            '<p>', esc(v.decision), '</p>',
          '</div>',
          '<div class="rsa-decision-card rsa-decision-blue">',
            '<div class="rsa-card-label">Next step</div>',
            '<p>', esc(v.nextStep), '</p>',
          '</div>',
        '</div>',

      '</div>',
    ].join('');
  }

  function metricCard(label, value, tone, helper) {
    return '<div class="rsa-metric rsa-tone-' + tone + '">' +
             '<div class="rsa-metric-label">' + label + '</div>' +
             '<div class="rsa-metric-value">' + value + '</div>' +
             '<div class="rsa-metric-help">' + helper + '</div>' +
           '</div>';
  }
  function alphaTone(a) { if (a == null) return 'neutral'; if (a >= 0.80) return 'strong'; if (a >= 0.70) return 'ok'; if (a >= 0.60) return 'warn'; return 'alert'; }
  function avgRTone(r)  { if (r == null) return 'neutral'; if (r >= 0.30 && r <= 0.50) return 'strong'; if (r >= 0.20 || r > 0.50) return 'warn'; return 'alert'; }
  function flagTone(n, thresholds) {
    if (n <= thresholds[0]) return 'strong';
    if (n <= thresholds[1]) return 'ok';
    if (n <= thresholds[2]) return 'warn';
    return 'alert';
  }

  /* ─────────────────────────────────────────────────────────────
   *  STYLES (injected once)
   * ───────────────────────────────────────────────────────────── */
  let stylesInjected = false;
  function injectAnalysisStyles() {
    if (stylesInjected) return;
    stylesInjected = true;
    const s = document.createElement('style');
    s.textContent = [
      '.rssi-analysis { font-family: inherit; color: #15171a; margin-top: 16px; }',
      '.rssi-analysis-empty { padding: 28px; background: #FAFBFC; border: 1px dashed #d8dde3; border-radius: 14px; color: #5f6368; font-size: 13px; text-align: center; }',
      '.rsa-header { padding: 18px 22px; border-radius: 14px; margin-bottom: 16px; border: 1px solid transparent; }',
      '.rsa-header.rsa-tone-strong  { background: #F0FDF4; border-color: #BBF7D0; }',
      '.rsa-header.rsa-tone-warn    { background: #FFFBEB; border-color: #FDE68A; }',
      '.rsa-header.rsa-tone-caution { background: #FFF7ED; border-color: #FED7AA; }',
      '.rsa-header.rsa-tone-alert   { background: #FEF2F2; border-color: #FECACA; }',
      '.rsa-status-pill { display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 8px; background: rgba(15,23,42,0.06); color: #15171a; }',
      '.rsa-tone-strong  .rsa-status-pill { background: rgba(52,199,89,0.20);  color: #1A7E36; }',
      '.rsa-tone-warn    .rsa-status-pill { background: rgba(255,159,10,0.20); color: #B26A00; }',
      '.rsa-tone-caution .rsa-status-pill { background: rgba(255,127,30,0.20); color: #B45309; }',
      '.rsa-tone-alert   .rsa-status-pill { background: rgba(255,59,48,0.18);  color: #B82318; }',
      '.rsa-judgment { font-size: 15px; font-weight: 500; line-height: 1.55; margin: 0; color: #15171a; max-width: 80ch; }',

      '.rsa-metric-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 22px; }',
      '.rsa-metric { background: #fff; border: 1px solid rgba(15,23,42,0.08); border-radius: 12px; padding: 14px 16px; }',
      '.rsa-metric-label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #8E8E93; }',
      '.rsa-metric-value { font-size: 28px; font-weight: 700; letter-spacing: -0.025em; margin: 4px 0 6px; line-height: 1; }',
      '.rsa-metric-help  { font-size: 11.5px; color: #5f6368; line-height: 1.4; }',
      '.rsa-tone-strong  .rsa-metric-value { color: #1A7E36; }',
      '.rsa-tone-ok      .rsa-metric-value { color: #1A6FD9; }',
      '.rsa-tone-warn    .rsa-metric-value { color: #B26A00; }',
      '.rsa-tone-alert   .rsa-metric-value { color: #B82318; }',
      '.rsa-tone-neutral .rsa-metric-value { color: #8E8E93; }',

      '.rsa-section-head { margin: 4px 0 10px; }',
      '.rsa-section-head h4 { font-size: 15px; font-weight: 700; margin: 0 0 4px; letter-spacing: -0.01em; }',
      '.rsa-section-head p  { font-size: 12.5px; color: #5f6368; margin: 0; line-height: 1.5; }',

      '.rsa-table-wrap { border: 1px solid rgba(15,23,42,0.08); border-radius: 12px; overflow: hidden; margin-bottom: 20px; }',
      '.rsa-table { width: 100%; border-collapse: collapse; font-size: 13px; }',
      '.rsa-table th { text-align: left; padding: 10px 14px; background: #F7F8FA; color: #5f6368; font-weight: 600; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 1px solid rgba(15,23,42,0.08); }',
      '.rsa-th-num, .rsa-td-num { text-align: right; }',
      '.rsa-th-flag, .rsa-td-flag { text-align: right; white-space: nowrap; }',
      '.rsa-table td { padding: 10px 14px; border-bottom: 1px solid rgba(15,23,42,0.05); vertical-align: top; }',
      '.rsa-table tr:last-child td { border-bottom: none; }',
      '.rsa-item-label { font-weight: 600; color: #15171a; line-height: 1.3; }',
      '.rsa-item-key   { font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size: 11px; color: #8E8E93; margin-top: 2px; }',
      '.rsa-num { font-variant-numeric: tabular-nums; font-weight: 600; }',
      '.rsa-num-ok    { color: #1A6FD9; }',
      '.rsa-num-warn  { color: #B26A00; }',
      '.rsa-num-alert { color: #B82318; }',
      '.rsa-flag { font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 999px; }',
      '.rsa-flag-ok    { background: rgba(0,122,255,0.10);  color: #1A6FD9; }',
      '.rsa-flag-warn  { background: rgba(255,159,10,0.14); color: #B26A00; }',
      '.rsa-flag-alert { background: rgba(255,59,48,0.12);  color: #B82318; }',

      '.rsa-decision-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }',
      '.rsa-decision-card { background: #fff; border: 1px solid rgba(15,23,42,0.08); border-radius: 12px; padding: 16px 18px; }',
      '.rsa-decision-card.rsa-decision-blue { background: #EEF3FA; border-color: rgba(0,122,255,0.18); }',
      '.rsa-card-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #8E8E93; margin-bottom: 6px; }',
      '.rsa-decision-card p { font-size: 13.5px; line-height: 1.55; color: #15171a; margin: 0; }',

      '@media (max-width: 900px) { .rsa-metric-grid { grid-template-columns: 1fr 1fr; } .rsa-decision-grid { grid-template-columns: 1fr; } }',
    ].join('\n');
    document.head.appendChild(s);
  }

  /* ─────────────────────────────────────────────────────────────
   *  OPTION 1 — Iframe scrape demonstration (Validity).
   *  Loads /validity.php?embed=1 in a hidden iframe, polls for the
   *  engine to finish rendering, then reads specific DOM ids out of
   *  the iframe and presents them in an RSSI card.
   *
   *  Pro:  zero engine modifications. Works for any analysis.
   *  Con:  extra iframe per analysis, fragile if engine renames ids.
   * ───────────────────────────────────────────────────────────── */
  function renderValidityViaIframeScrape(container) {
    if (!container) return;
    injectAnalysisStyles();
    injectIframeStyles();
    container.innerHTML =
      '<div class="rsa-iframe-loading">' +
        '<div class="rsa-spinner" aria-hidden="true"></div>' +
        '<div>Loading Validity analysis from the engine&hellip;</div>' +
        '<div class="rsa-iframe-method-tag">Option 1 &middot; iframe scrape</div>' +
      '</div>';

    const iframe = document.createElement('iframe');
    iframe.src = '/validity.php?studio=survey&embed=1';
    iframe.style.cssText = 'position:absolute;width:1px;height:1px;left:-9999px;top:-9999px;opacity:0;';
    document.body.appendChild(iframe);

    const start = Date.now();
    const tick = setInterval(function () {
      let titleEl, subEl;
      try {
        const doc = iframe.contentDocument;
        if (!doc) return;
        titleEl = doc.getElementById('iqValTitle');
        subEl   = doc.getElementById('iqValSub');
      } catch (e) { return; }
      const haveTitle = titleEl && titleEl.textContent && titleEl.textContent.trim() && titleEl.textContent.trim() !== '—';
      const haveSub   = subEl   && subEl.textContent   && subEl.textContent.trim()   && subEl.textContent.trim()   !== '—';
      const haveBody  = (function () {
        const b = iframe.contentDocument && iframe.contentDocument.getElementById('iqValBody');
        return b && b.innerHTML.trim().length > 50;
      })();
      if (haveTitle && haveSub) {
        clearInterval(tick);
        const title  = titleEl.textContent.trim();
        const sub    = subEl.textContent.trim();
        const bodyEl = iframe.contentDocument.getElementById('iqValBody');
        const interpEl = iframe.contentDocument.getElementById('iqValInterp');
        const bodyHtml   = bodyEl   ? bodyEl.innerHTML   : '';
        const interpHtml = interpEl ? interpEl.innerHTML : '';
        // Pull the status tone out of the title (e.g., "Validity Review: Strong")
        const m = title.match(/:\s*(\w[\w\s-]*)$/);
        const statusWord = m ? m[1].toLowerCase() : 'ok';
        const tone = /strong|ready|excellent/.test(statusWord) ? 'strong'
                  : /mixed|caution|watch/.test(statusWord) ? 'warn'
                  : /not.ready|concern|alert/.test(statusWord) ? 'alert'
                  : 'ok';
        // Scrape a numeric score for the big header circle. Validity body
        // usually contains "α = 0.84" — try to pluck that, else fall back
        // to a tone-based default.
        const fullText = (title + ' ' + sub + ' ' + bodyHtml).toLowerCase();
        const alphaM = fullText.match(/[αa]lpha?\s*=?\s*(0?\.\d+)/);
        let headline, badge;
        if (alphaM) {
          headline = Math.round(parseFloat(alphaM[1]) * 100);
          badge    = 'α = ' + parseFloat(alphaM[1]).toFixed(2);
        } else {
          headline = tone === 'strong' ? 90 : tone === 'ok' ? 75 : tone === 'warn' ? 55 : 35;
          badge    = m ? m[1].trim() : 'Validity';
        }
        if (window.RSSI_SET_DETAIL_SCORE) {
          window.RSSI_SET_DETAIL_SCORE(headline, badge, tone);
        }
        container.innerHTML = [
          '<div class="rssi-analysis">',
            '<div class="rsa-method-tag rsa-method-tag-iframe">Method: <strong>Option 1 &mdash; iframe scrape</strong> &middot; narrative pulled live from the engine page</div>',
            '<div class="rsa-header rsa-tone-', tone, '">',
              '<div class="rsa-status-pill">', esc(title), '</div>',
              '<h3 class="rsa-judgment">', esc(sub), '</h3>',
            '</div>',
            bodyHtml ? '<div class="rsa-scraped-body">' + bodyHtml + '</div>' : '',
            interpHtml ? '<div class="rsa-scraped-interp">' + interpHtml + '</div>' : '',
          '</div>',
        ].join('');
        // Clean up the iframe
        iframe.remove();
      } else if (Date.now() - start > 8000) {
        clearInterval(tick);
        container.innerHTML =
          '<div class="rssi-analysis-empty">' +
            'The engine didn\'t finish rendering within 8 seconds. ' +
            'This usually means the embedded page can\'t find your dataset in <code>localStorage</code> &mdash; re-upload a Likert dataset and try again.' +
          '</div>';
        iframe.remove();
      }
    }, 250);
  }
  function injectIframeStyles() {
    if (document.getElementById('rsa-iframe-styles')) return;
    const s = document.createElement('style');
    s.id = 'rsa-iframe-styles';
    s.textContent = [
      '.rsa-iframe-loading { padding: 36px; text-align: center; color: #5f6368; font-size: 14px; }',
      '.rsa-spinner { width: 28px; height: 28px; margin: 0 auto 14px; border: 3px solid rgba(15,23,42,0.12); border-top-color: #007AFF; border-radius: 50%; animation: rsa-spin 0.7s linear infinite; }',
      '@keyframes rsa-spin { to { transform: rotate(360deg); } }',
      '.rsa-iframe-method-tag, .rsa-method-tag { margin-top: 14px; font-size: 11.5px; font-weight: 600; color: #8E8E93; }',
      '.rsa-method-tag { display: inline-block; padding: 6px 12px; border-radius: 999px; background: #F0F1F3; margin-bottom: 16px; }',
      '.rsa-method-tag strong { color: #15171a; }',
      '.rsa-method-tag-iframe  { background: #FEF3C7; color: #92400E; }',
      '.rsa-method-tag-iframe  strong { color: #92400E; }',
      '.rsa-method-tag-ported  { background: #D1FAE5; color: #065F46; }',
      '.rsa-method-tag-ported  strong { color: #065F46; }',
      '.rsa-method-tag-engine  { background: #DBEAFE; color: #1E40AF; }',
      '.rsa-method-tag-engine  strong { color: #1E40AF; }',
      '.rsa-scraped-body, .rsa-scraped-interp { padding: 14px 18px; border: 1px solid rgba(15,23,42,0.08); border-radius: 12px; margin-top: 14px; font-size: 13.5px; line-height: 1.55; color: #15171a; }',
      '.rsa-scraped-body table, .rsa-scraped-interp table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-top: 8px; }',
      '.rsa-scraped-body td, .rsa-scraped-interp td { padding: 6px 10px; border-bottom: 1px solid rgba(15,23,42,0.05); }',
    ].join('\n');
    document.head.appendChild(s);
  }

  /* ─────────────────────────────────────────────────────────────
   *  OPTION 2 — Ported narrative function (Item / Prompt Quality).
   *  The narrative-generating logic from renderItemQuality() in
   *  instrument-quality.js is copied here and runs in RSSI's window
   *  context. Pure native rendering, no iframe, no engine dependency.
   *
   *  Pro:  fully native, fast, easy to style and extend.
   *  Con:  duplicates math; if the source engine logic changes, the
   *        port needs to be updated.
   * ───────────────────────────────────────────────────────────── */
  function sdOf(arr) {
    if (arr.length < 2) return null;
    const m = arr.reduce(function (s, v) { return s + v; }, 0) / arr.length;
    return Math.sqrt(arr.reduce(function (s, v) { return s + (v - m) * (v - m); }, 0) / (arr.length - 1));
  }
  function computeItemQuality(likertItems) {
    if (likertItems.length < 1) return { error: 'Need at least 1 Likert item.' };
    const rows = likertItems.map(function (v) {
      const all  = v.values;
      const nums = all.map(num).filter(function (x) { return x != null; });
      const missing = all.length - nums.length;
      const m  = nums.length ? nums.reduce(function (s, x) { return s + x; }, 0) / nums.length : null;
      const s  = sdOf(nums);
      const lo = nums.length ? Math.min.apply(null, nums) : null;
      const hi = nums.length ? Math.max.apply(null, nums) : null;
      const range = (hi != null && lo != null) ? hi - lo : 0;
      const ceil  = (nums.length && hi != null) ? nums.filter(function (x) { return x === hi; }).length / nums.length : 0;
      const floor = (nums.length && lo != null) ? nums.filter(function (x) { return x === lo; }).length / nums.length : 0;
      const lowVar = (s != null && range > 0) ? (s < 0.15 * range) : false;
      let skew = 0, kurt = 0;
      if (nums.length >= 3 && s != null && s > 0) {
        skew = nums.reduce(function (a, x) { return a + Math.pow((x - m) / s, 3); }, 0) / nums.length;
      }
      if (nums.length >= 4 && s != null && s > 0) {
        kurt = nums.reduce(function (a, x) { return a + Math.pow((x - m) / s, 4); }, 0) / nums.length - 3;
      }
      const flags = [];
      if (ceil  >= 0.70) flags.push('ceiling');
      if (floor >= 0.70) flags.push('floor');
      if (lowVar)        flags.push('low variance');
      if (Math.abs(skew) > 2)  flags.push('extreme skew');
      if (Math.abs(kurt) > 5)  flags.push('extreme kurtosis');
      if (missing / all.length > 0.20) flags.push(Math.round(missing / all.length * 100) + '% missing');
      let tone = 'ok';
      if (flags.length >= 3) tone = 'alert';
      else if (flags.length) tone = 'warn';
      return { name: v.name, label: v.label || v.name, n: nums.length, mean: m, sd: s, missing: missing, ceil: ceil, floor: floor, skew: skew, kurt: kurt, flags: flags, tone: tone };
    });
    const ok    = rows.filter(function (r) { return r.tone === 'ok'; }).length;
    const warn  = rows.filter(function (r) { return r.tone === 'warn'; }).length;
    const alert = rows.filter(function (r) { return r.tone === 'alert'; }).length;
    const judgment = alert
      ? alert + ' item' + (alert === 1 ? '' : 's') + ' show multiple problems and should be revised or removed; ' + warn + ' need a closer look.'
      : warn
        ? warn + ' item' + (warn === 1 ? '' : 's') + ' show one issue each. Inspect; most are usable with minor adjustments.'
        : 'All items pass the item-quality screens.';
    const plain = (alert || warn)
      ? 'A handful of items are not pulling their weight. Items flagged Ceiling or Floor are too easy or too hard to discriminate; Low-variance items behave the same way for almost every respondent; extreme skew or kurtosis means a lopsided distribution.'
      : 'Each item behaves like a useful question. Means and spreads land in workable ranges, no item is stuck at the top or bottom of the scale, and missingness is low.';
    const closing = alert
      ? 'Address the ' + alert + ' Problem item' + (alert === 1 ? '' : 's') + ' first &mdash; revise wording or remove from the scale, then recompute &alpha;. The ' + warn + ' Watch item' + (warn === 1 ? '' : 's') + ' should be inspected next.'
      : warn
        ? 'Inspect the ' + warn + ' flagged item' + (warn === 1 ? '' : 's') + ' for wording problems before re-fielding.'
        : 'No revisions required. Standard pre-publication review still applies.';
    const statusTone = alert ? 'alert' : warn ? 'warn' : 'strong';
    const status     = alert ? 'Action Required' : warn ? 'Inspect' : 'All Clear';
    return { rows: rows, ok: ok, warn: warn, alert: alert, judgment: judgment, plain: plain, closing: closing, status: status, statusTone: statusTone };
  }
  function renderItemQualityPorted(container) {
    if (!container) return;
    injectAnalysisStyles();
    injectIframeStyles();
    const items = itemsFromDataset();
    if (items.length === 0) {
      container.innerHTML = '<div class="rssi-analysis-empty">Upload a Likert-scale dataset to run an item-quality review.</div>';
      return;
    }
    const r = computeItemQuality(items);
    if (r.error) {
      container.innerHTML = '<div class="rssi-analysis-empty">' + esc(r.error) + '</div>';
      return;
    }
    // Headline score for the big circle: % of items that pass clean (no flags).
    const cleanPct = items.length ? Math.round(r.ok / items.length * 100) : 0;
    if (window.RSSI_SET_DETAIL_SCORE) {
      window.RSSI_SET_DETAIL_SCORE(cleanPct, r.status, r.statusTone === 'strong' ? 'strong' : r.statusTone === 'alert' ? 'alert' : 'warn');
    }
    container.innerHTML = [
      '<div class="rssi-analysis">',
        '<div class="rsa-method-tag rsa-method-tag-ported">Method: <strong>Option 2 &mdash; ported narrative</strong> &middot; logic copied from the engine, rendered natively in RSSI</div>',
        '<div class="rsa-header rsa-tone-', r.statusTone, '">',
          '<div class="rsa-status-pill">', esc(r.status), '</div>',
          '<h3 class="rsa-judgment">', esc(r.judgment), '</h3>',
        '</div>',
        '<div class="rsa-metric-grid">',
          metricCard('Total items', items.length, 'ok',     'Likert items reviewed in this dataset.'),
          metricCard('Clean',       r.ok,         r.ok > 0 ? 'strong' : 'neutral', 'No flags &mdash; behave like good items.'),
          metricCard('Watch',       r.warn,       r.warn === 0 ? 'strong' : 'warn', 'One flag &mdash; usable with care.'),
          metricCard('Problem',     r.alert,      r.alert === 0 ? 'strong' : 'alert', 'Multiple flags &mdash; revise or remove.'),
        '</div>',
        '<div class="rsa-section-head"><h3>Plain-language read</h3></div>',
        '<div class="rsa-decision-card"><p>', esc(r.plain), '</p></div>',
        '<div class="rsa-section-head" style="margin-top:18px;"><h3>Per-item flags</h3></div>',
        '<div class="rsa-table-wrap">',
          '<table class="rsa-table">',
            '<thead><tr><th class="rsa-th-item">Item</th><th class="rsa-th-num">n</th><th class="rsa-th-num">Mean</th><th class="rsa-th-num">SD</th><th class="rsa-th-flag">Flags</th></tr></thead>',
            '<tbody>',
              r.rows.slice().sort(function (a, b) { return b.flags.length - a.flags.length; }).map(function (row) {
                const tone = row.tone;
                const flagStr = row.flags.length ? row.flags.join(', ') : '&mdash;';
                return '<tr>' +
                  '<td class="rsa-td-item"><div class="rsa-item-label">' + esc(row.label) + '</div><div class="rsa-item-key">' + esc(row.name) + '</div></td>' +
                  '<td class="rsa-td-num">' + row.n + '</td>' +
                  '<td class="rsa-td-num">' + (row.mean == null ? '—' : row.mean.toFixed(2)) + '</td>' +
                  '<td class="rsa-td-num">' + (row.sd   == null ? '—' : row.sd.toFixed(2))   + '</td>' +
                  '<td class="rsa-td-flag"><span class="rsa-flag rsa-flag-' + tone + '">' + flagStr + '</span></td>' +
                '</tr>';
              }).join(''),
            '</tbody>',
          '</table>',
        '</div>',
        '<div class="rsa-decision-grid" style="margin-top:18px;">',
          '<div class="rsa-decision-card rsa-decision-blue">',
            '<div class="rsa-card-label">Recommended action</div>',
            '<p>', r.closing, '</p>',
          '</div>',
        '</div>',
      '</div>',
    ].join('');
  }

  /* ─────────────────────────────────────────────────────────────
   *  OPTION 3 — Engine-side narrative API (Construct Alignment).
   *  The engine (instrument-quality.js) exposes a pure-compute
   *  function on window.IQ_ENGINE. RSSI calls it, gets a plain
   *  object back, and renders the result in RSSI styling.
   *
   *  Pro:  source of truth lives in the engine; RSSI never has to
   *        re-implement math, and updates flow through automatically.
   *  Con:  requires touching the engine code (add the public API).
   * ───────────────────────────────────────────────────────────── */
  function renderConstructAlignmentViaEngineAPI(container) {
    if (!container) return;
    injectAnalysisStyles();
    injectIframeStyles();
    if (!window.IQ_ENGINE || typeof window.IQ_ENGINE.constructAlignmentNarrative !== 'function') {
      container.innerHTML =
        '<div class="rssi-analysis-empty">' +
          'The instrument-quality engine isn\'t loaded on this page (<code>window.IQ_ENGINE</code> is missing). ' +
          'Make sure <code>/apps/instrument-quality/instrument-quality.js</code> is included.' +
        '</div>';
      return;
    }
    const items = itemsFromDataset();
    if (items.length < 2) {
      container.innerHTML = '<div class="rssi-analysis-empty">Need at least 2 Likert items to run construct alignment.</div>';
      return;
    }
    const r = window.IQ_ENGINE.constructAlignmentNarrative(items);
    if (r.error) {
      container.innerHTML = '<div class="rssi-analysis-empty">' + esc(r.error) + '</div>';
      return;
    }
    // Headline score for the big circle
    let alignScore;
    if (r.methodEffect) alignScore = 35;
    else if (r.status === 'Strong alignment') alignScore = 95;
    else if (r.status === 'Mostly aligned')   alignScore = 80;
    else if (r.status === 'Mixed alignment')  alignScore = 60;
    else                                       alignScore = 45;
    const tone = r.statusTone === 'strong' ? 'strong' : r.statusTone === 'ok' ? 'ok' : r.statusTone === 'warn' ? 'warn' : 'alert';
    if (window.RSSI_SET_DETAIL_SCORE) {
      window.RSSI_SET_DETAIL_SCORE(alignScore, r.status, tone);
    }
    injectConstructAlignmentStyles();

    // Build candidate cluster cards
    const clusterCardsHtml = (r.candidateClusters || []).map(function (c) {
      const meta = c.itemCount + ' item' + (c.itemCount === 1 ? '' : 's') +
        (c.alpha != null ? ' &middot; α = ' + c.alpha.toFixed(2) : '') +
        (c.avgR  != null ? ' &middot; avg r = ' + c.avgR.toFixed(2) : '');
      const itemsHtml = c.items.map(function (it) {
        return '<span class="rsa-ca-item">' + esc(it.name) +
               (it.construct ? ' <span class="rsa-ca-item-construct">(' + esc(it.construct) + ')</span>' : '') +
               '</span>';
      }).join(' ');
      const spansHtml = (c.spans && c.spans.length)
        ? '<div class="rsa-ca-spans"><strong>Spans:</strong> ' + esc(c.spans.join(', ')) + '</div>'
        : '';
      return [
        '<div class="rsa-ca-card rsa-ca-tone-', c.badge.tone, '">',
          '<div class="rsa-ca-card-head">',
            '<span class="rsa-ca-card-num">Candidate Scale ', c.number, '</span>',
            '<span class="rsa-ca-card-badge rsa-ca-tone-', c.badge.tone, '">', esc(c.badge.label), '</span>',
          '</div>',
          '<div class="rsa-ca-card-meta">', meta, '</div>',
          '<div class="rsa-ca-card-items">', itemsHtml, '</div>',
          spansHtml,
        '</div>',
      ].join('');
    }).join('');

    // Build evidence table
    const evidenceRowsHtml = (r.evidenceRows || []).map(function (row) {
      return [
        '<tr>',
          '<td class="rsa-ev-check"><strong>', esc(row.check), '</strong></td>',
          '<td class="rsa-ev-evidence">', esc(row.evidence), '</td>',
          '<td class="rsa-ev-status"><span class="rsa-flag rsa-flag-', row.tone, '">', esc(row.status), '</span></td>',
          '<td>', esc(row.plain), '</td>',
          '<td>', esc(row.research), '</td>',
          '<td class="rsa-ev-action">', esc(row.action), '</td>',
        '</tr>',
      ].join('');
    }).join('');

    container.innerHTML = [
      '<div class="rssi-analysis">',
        '<div class="rsa-method-tag rsa-method-tag-engine">Method: <strong>Option 3 &mdash; engine API</strong> &middot; <code>window.IQ_ENGINE.constructAlignmentNarrative()</code> returns a plain object, RSSI renders it</div>',

        // Headline banner
        '<div class="rsa-header rsa-tone-', r.statusTone, '">',
          '<div class="rsa-status-pill">Construct Alignment Review: ', esc(r.status), '</div>',
          '<h3 class="rsa-judgment">', esc(r.headlineInterp), '</h3>',
        '</div>',

        // Top metric cards
        '<div class="rsa-metric-grid">',
          metricCard('Detected clusters',  r.detectedClusters, r.detectedClusters === r.intendedConstructsCount ? 'strong' : 'warn', 'Empirically discovered groupings.'),
          metricCard('Intended constructs', r.intendedConstructsCount || '—', r.intendedConstructsCount ? 'ok' : 'warn', 'Inferred from item-name prefixes.'),
          metricCard('Reverse-coded cluster', r.methodEffect ? 'Yes' : (r.cluster2Count ? 'Partial' : 'No'), r.methodEffect ? 'alert' : (r.cluster2Count ? 'warn' : 'strong'), 'Items moving opposite the scale total.'),
          metricCard('Complete responses',  r.completeResponses, 'ok', 'Rows with no missing data across all items.'),
        '</div>',

        // Construct alignment summary
        '<div class="rsa-section-head"><h3>Construct alignment summary</h3></div>',
        '<div class="rsa-summary-box">',
          '<p class="rsa-summary-headline"><strong>', esc(r.headlineInterp), '</strong></p>',
          '<p><span class="rsa-para-label">Plain-language explanation.</span> ', esc(r.plain), '</p>',
          '<p><span class="rsa-para-label">Research interpretation.</span> ', esc(r.research), '</p>',
        '</div>',

        // Candidate clusters
        clusterCardsHtml ? [
          '<div class="rsa-section-head"><h3>Candidate clusters</h3></div>',
          '<div class="rsa-ca-cards">', clusterCardsHtml, '</div>',
        ].join('') : '',

        // Evidence table
        '<div class="rsa-section-head"><h3>Construct alignment evidence</h3></div>',
        '<div class="rsa-ev-wrap">',
          '<table class="rsa-ev-table">',
            '<thead><tr>',
              '<th>Alignment check</th>',
              '<th>Evidence found</th>',
              '<th>Status</th>',
              '<th>Plain-language meaning</th>',
              '<th>Research interpretation</th>',
              '<th>Recommended action</th>',
            '</tr></thead>',
            '<tbody>', evidenceRowsHtml, '</tbody>',
          '</table>',
        '</div>',

        // Interpretation
        '<div class="rsa-section-head"><h3>Interpretation</h3></div>',
        '<div class="rsa-decision-card">',
          '<p>', esc(r.closingPlain), ' ', esc(r.closingResearch), '</p>',
        '</div>',

        // J/E/M/A footer
        '<div class="rsa-section-head"><h3>Summary</h3></div>',
        '<div class="rsa-jema">',
          '<div class="rsa-jema-row"><strong>Judgment.</strong> ', esc(r.jema.judgment), '</div>',
          '<div class="rsa-jema-row"><strong>Evidence.</strong> ', esc(r.jema.evidence), '</div>',
          '<div class="rsa-jema-row"><strong>Meaning.</strong> ', esc(r.jema.meaning), '</div>',
          '<div class="rsa-jema-row"><strong>Action.</strong> ', esc(r.jema.action), '</div>',
        '</div>',

      '</div>',
    ].join('');
  }

  /* Construct-Alignment specific styles (loaded once) */
  let caStylesInjected = false;
  function injectConstructAlignmentStyles() {
    if (caStylesInjected) return;
    caStylesInjected = true;
    const s = document.createElement('style');
    s.textContent = [
      '.rsa-summary-box { background:#FAFBFC; border:1px solid rgba(15,23,42,0.06); border-radius:12px; padding:16px 18px; }',
      '.rsa-summary-box p { margin:0 0 10px; font-size:14px; line-height:1.6; }',
      '.rsa-summary-box p:last-child { margin-bottom:0; }',
      '.rsa-summary-headline { color:#15171a; font-size:15px !important; }',
      '.rsa-para-label { display:inline-block; font-weight:700; color:#15171a; margin-right:4px; }',
      '.rsa-ca-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(360px,1fr)); gap:14px; }',
      '.rsa-ca-card { background:#fff; border:1px solid rgba(15,23,42,0.08); border-radius:12px; padding:16px 18px; border-left:4px solid #007AFF; }',
      '.rsa-ca-card.rsa-ca-tone-strong { border-left-color:#34C759; }',
      '.rsa-ca-card.rsa-ca-tone-ok     { border-left-color:#007AFF; }',
      '.rsa-ca-card.rsa-ca-tone-warn   { border-left-color:#FF9F0A; }',
      '.rsa-ca-card.rsa-ca-tone-alert  { border-left-color:#FF3B30; }',
      '.rsa-ca-card-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:6px; }',
      '.rsa-ca-card-num { font-size:13px; font-weight:700; color:#5f6368; text-transform:uppercase; letter-spacing:0.05em; }',
      '.rsa-ca-card-badge { font-size:11px; font-weight:700; padding:3px 9px; border-radius:999px; }',
      '.rsa-ca-card-badge.rsa-ca-tone-strong { background:rgba(52,199,89,0.14); color:#1A7E36; }',
      '.rsa-ca-card-badge.rsa-ca-tone-ok     { background:rgba(0,122,255,0.10); color:#1A6FD9; }',
      '.rsa-ca-card-badge.rsa-ca-tone-warn   { background:rgba(255,159,10,0.14); color:#B26A00; }',
      '.rsa-ca-card-badge.rsa-ca-tone-alert  { background:rgba(255,59,48,0.12); color:#B82318; }',
      '.rsa-ca-card-meta { font-size:13px; color:#5f6368; margin-bottom:10px; font-variant-numeric:tabular-nums; }',
      '.rsa-ca-card-items { font-size:13px; line-height:1.7; color:#15171a; }',
      '.rsa-ca-item { display:inline-block; padding:2px 0; }',
      '.rsa-ca-item-construct { color:#5f6368; font-size:12px; }',
      '.rsa-ca-spans { font-size:12px; color:#5f6368; margin-top:10px; padding-top:10px; border-top:1px solid rgba(15,23,42,0.05); }',
      '.rsa-ev-wrap { border:1px solid rgba(15,23,42,0.08); border-radius:12px; overflow:auto; }',
      '.rsa-ev-table { width:100%; border-collapse:collapse; font-size:12.5px; min-width:880px; }',
      '.rsa-ev-table th { text-align:left; padding:10px 12px; background:#F7F8FA; color:#5f6368; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; border-bottom:1px solid rgba(15,23,42,0.08); white-space:nowrap; }',
      '.rsa-ev-table td { padding:12px; border-bottom:1px solid rgba(15,23,42,0.05); vertical-align:top; line-height:1.5; }',
      '.rsa-ev-table tr:last-child td { border-bottom:none; }',
      '.rsa-ev-check { white-space:nowrap; width:180px; }',
      '.rsa-ev-evidence { width:220px; color:#15171a; }',
      '.rsa-ev-status { white-space:nowrap; width:90px; text-align:center; }',
      '.rsa-ev-action { color:#15171a; }',
      '.rsa-jema { background:#FAFBFC; border:1px solid rgba(15,23,42,0.06); border-radius:12px; padding:16px 18px; }',
      '.rsa-jema-row { font-size:14px; line-height:1.6; padding:6px 0; border-bottom:1px solid rgba(15,23,42,0.05); }',
      '.rsa-jema-row:last-child { border-bottom:none; }',
      '.rsa-jema-row strong { color:#0A6FE8; font-weight:700; }',
    ].join('\n');
    document.head.appendChild(s);
  }

  /* ─────────────────────────────────────────────────────────────
   *  GENERIC iframe-scrape renderer — Option 1 packaged for reuse.
   *  Given a config (url + DOM ids to scrape), loads the analysis
   *  page in a hidden iframe, polls until the engine has populated
   *  the title and sub elements, then yanks the narrative HTML into
   *  an RSSI-styled card. Removes the iframe once done.
   *
   *  config = {
   *    url:      '/bias-clarity.php?studio=survey&embed=1',
   *    label:    'Bias & Clarity Review',
   *    titleId:  'iqBcTitle',
   *    subId:    'iqBcSub',
   *    bodyId:   'iqBcBody',     // optional — main analysis HTML
   *    interpId: 'iqBcInterp',   // optional — interpretation HTML
   *  }
   * ───────────────────────────────────────────────────────────── */
  function renderIframeNarrative(container, config) {
    if (!container) return;
    injectAnalysisStyles();
    injectIframeStyles();

    container.innerHTML =
      '<div class="rsa-iframe-loading">' +
        '<div class="rsa-spinner" aria-hidden="true"></div>' +
        '<div>Loading ' + esc(config.label) + ' from the engine&hellip;</div>' +
        '<div class="rsa-iframe-method-tag">Option 1 &middot; iframe scrape</div>' +
      '</div>';

    const iframe = document.createElement('iframe');
    iframe.src = config.url;
    iframe.style.cssText = 'position:absolute;width:1px;height:1px;left:-9999px;top:-9999px;opacity:0;';
    document.body.appendChild(iframe);

    const start = Date.now();
    const tick = setInterval(function () {
      let titleEl, subEl;
      try {
        const doc = iframe.contentDocument;
        if (!doc) return;
        titleEl = doc.getElementById(config.titleId);
        subEl   = doc.getElementById(config.subId);
      } catch (e) { return; }
      const haveTitle = titleEl && titleEl.textContent && titleEl.textContent.trim() && titleEl.textContent.trim() !== '—';
      const haveSub   = subEl   && subEl.textContent   && subEl.textContent.trim()   && subEl.textContent.trim()   !== '—';
      if (haveTitle && haveSub) {
        clearInterval(tick);
        const title  = titleEl.textContent.trim();
        const sub    = subEl.textContent.trim();
        const bodyEl   = config.bodyId   ? iframe.contentDocument.getElementById(config.bodyId)   : null;
        const interpEl = config.interpId ? iframe.contentDocument.getElementById(config.interpId) : null;
        const bodyHtml   = bodyEl   ? bodyEl.innerHTML   : '';
        const interpHtml = interpEl ? interpEl.innerHTML : '';
        // Tone heuristic from the title (e.g., "Bias & Clarity: 3 items flagged")
        const lower = title.toLowerCase();
        const tone = /strong|excellent|ready|all clear|no issue|no flag/.test(lower) ? 'strong'
                   : /mixed|caution|watch|warn|inspect|review/.test(lower) ? 'warn'
                   : /not.ready|problem|alert|critical|fail|concern/.test(lower) ? 'alert'
                   : 'ok';
        // Headline score: try to scrape a number from the title or sub
        // (e.g., "α = 0.78", "KMO = 0.65", "3 items flagged"). Fall back
        // to a tone-based score so the ring isn't blank.
        let headline = null, badge = '';
        const alphaM = (title + ' ' + sub).match(/[αa]lpha?\s*=?\s*(0?\.\d+)/i);
        const kmoM   = (title + ' ' + sub).match(/kmo\s*=?\s*(0?\.\d+)/i);
        const ratioM = title.match(/(\d+)\s*\/\s*(\d+)/);
        if (alphaM)      { headline = Math.round(parseFloat(alphaM[1]) * 100); badge = 'α = ' + parseFloat(alphaM[1]).toFixed(2); }
        else if (kmoM)   { headline = Math.round(parseFloat(kmoM[1]) * 100);   badge = 'KMO = ' + parseFloat(kmoM[1]).toFixed(2); }
        else if (ratioM) { headline = Math.round((parseInt(ratioM[1]) / parseInt(ratioM[2])) * 100); badge = ratioM[1] + ' of ' + ratioM[2]; }
        else {
          // Tone-based default
          headline = tone === 'strong' ? 90 : tone === 'ok' ? 75 : tone === 'warn' ? 55 : 35;
          // Use the part after the colon as the badge if present
          const colonIdx = title.indexOf(':');
          badge = colonIdx >= 0 ? title.slice(colonIdx + 1).trim() : title;
        }
        if (window.RSSI_SET_DETAIL_SCORE) {
          window.RSSI_SET_DETAIL_SCORE(headline, badge, tone);
        }
        container.innerHTML = [
          '<div class="rssi-analysis">',
            '<div class="rsa-method-tag rsa-method-tag-iframe">Method: <strong>Option 1 &mdash; iframe scrape</strong> &middot; narrative pulled live from the engine page</div>',
            '<div class="rsa-header rsa-tone-', tone, '">',
              '<div class="rsa-status-pill">', esc(title), '</div>',
              '<h3 class="rsa-judgment">', esc(sub), '</h3>',
            '</div>',
            bodyHtml   ? '<div class="rsa-scraped-body">'   + bodyHtml   + '</div>' : '',
            interpHtml ? '<div class="rsa-scraped-interp">' + interpHtml + '</div>' : '',
          '</div>',
        ].join('');
        iframe.remove();
      } else if (Date.now() - start > 10000) {
        clearInterval(tick);
        container.innerHTML =
          '<div class="rssi-analysis-empty">' +
            'The ' + esc(config.label) + ' engine didn\'t finish rendering within 10 seconds. ' +
            'This usually means the embedded page can\'t find your dataset &mdash; re-upload a Likert dataset and try again.' +
          '</div>';
        iframe.remove();
      }
    }, 250);
  }

  /* Bound renderers for each remaining sidebar analysis. */
  function renderBiasClarityIframe(container) {
    renderIframeNarrative(container, {
      url: '/bias-clarity.php?studio=survey&embed=1',
      label: 'Bias & Clarity Review',
      titleId: 'iqBcTitle', subId: 'iqBcSub', bodyId: 'iqBcBody', interpId: 'iqBcInterp',
    });
  }
  function renderScaleStructureIframe(container) {
    renderIframeNarrative(container, {
      url: '/scale-structure.php?studio=survey&embed=1',
      label: 'Scale Structure',
      titleId: 'iqSsTitle', subId: 'iqSsSub', bodyId: 'iqSsBody', interpId: 'iqSsInterp',
    });
  }
  function renderFactorReadinessIframe(container) {
    renderIframeNarrative(container, {
      url: '/factor-readiness.php?studio=survey&embed=1',
      label: 'Factor Readiness',
      titleId: 'iqFrTitle', subId: 'iqFrSub', bodyId: 'iqFrBody', interpId: 'iqFrInterp',
    });
  }
  function renderResponseScaleIframe(container) {
    renderIframeNarrative(container, {
      url: '/response-scale.php?studio=survey&embed=1',
      label: 'Response Scale Review',
      titleId: 'iqRsTitle', subId: 'iqRsSub', bodyId: 'iqRsBody', interpId: 'iqRsInterp',
    });
  }

  /* Public API */
  window.RSSI_ANALYSES = {
    renderValidity:                  renderValidity,                 // existing native
    renderValidityViaIframeScrape:   renderValidityViaIframeScrape,  // Option 1 (validity demo)
    renderItemQualityPorted:         renderItemQualityPorted,        // Option 2
    renderConstructAlignmentViaEngineAPI: renderConstructAlignmentViaEngineAPI, // Option 3
    // Option 1 bound renderers for the remaining 4 instrument-quality lenses
    renderBiasClarityIframe:         renderBiasClarityIframe,
    renderScaleStructureIframe:      renderScaleStructureIframe,
    renderFactorReadinessIframe:     renderFactorReadinessIframe,
    renderResponseScaleIframe:       renderResponseScaleIframe,
    // Generic helper exposed so future engines can be wired in one line
    renderIframeNarrative:           renderIframeNarrative,
    itemsFromDataset:                itemsFromDataset,
  };
})();
