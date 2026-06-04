// ReliCheck — Quick Analyze core engine.
// ONE descriptive-analysis engine, shared by:
//   • the standalone output page (descriptive-quick-analysis.php / quick-analyze.js)
//   • the in-context popup opened from the upload widget (Auto analyze / Auto report)
//
// Public API (window.QuickAnalyze):
//   renderAll(container, projectId, opts)  — fetch dataset, compute, render; if
//                                            opts.mode==='report', also fetch + render
//                                            the ReliCheck Intelligence written report.
//   openPopup(projectId, opts)             — same, inside a printable modal overlay.
//
// opts: { mode:'auto'|'report', projectTitle, workspaceUrl, compact:bool }
(function () {
  'use strict';

  // ── Styles (self-contained so the popup works on any page) ───────────────────
  var CONTENT_CSS = [
    '.qa-wrap{font-family:-apple-system,BlinkMacSystemFont,"Inter","Segoe UI",sans-serif;color:#1a1d23}',
    '.qa-hero{background:linear-gradient(135deg,#c0392b 0%,#7b1a12 100%);color:#fff;padding:26px 28px;border-radius:14px;margin-bottom:26px}',
    '.qa-hero-eye{font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;opacity:.65;margin-bottom:6px}',
    '.qa-hero-title{font-size:23px;font-weight:800;margin:0 0 5px;line-height:1.2}',
    '.qa-hero-sub{font-size:13px;opacity:.75;margin:0 0 16px}',
    '.qa-hero-pills{display:flex;flex-wrap:wrap;gap:8px}',
    '.qa-pill{background:rgba(255,255,255,.18);border-radius:20px;padding:4px 11px;font-size:12px;font-weight:600}',
    '.qa-section{margin:0 0 30px}',
    '.qa-section-label{font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#5a6070;margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid #e5e8ef}',
    '.qa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}',
    '.qa-grid-1{display:grid;grid-template-columns:1fr;gap:14px}',
    '.qa-card{background:#fff;border-radius:12px;border:1px solid #e5e8ef;padding:16px 18px 18px}',
    '.qa-card-title{font-size:13.5px;font-weight:700;color:#1a1d23;margin:0 0 3px}',
    '.qa-card-sub{font-size:12px;color:#5a6070;margin:0 0 13px}',
    '.qa-freq-row{display:flex;align-items:center;gap:8px;margin-bottom:7px}',
    '.qa-freq-label{font-size:12px;color:#1a1d23;flex:0 0 120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.qa-freq-track{flex:1;background:#f0f2f5;border-radius:4px;height:7px;overflow:hidden}',
    '.qa-freq-fill{height:100%;background:#c0392b;border-radius:4px}',
    '.qa-freq-pct{font-size:11px;color:#5a6070;flex:0 0 36px;text-align:right;font-variant-numeric:tabular-nums}',
    '.qa-freq-n{font-size:11px;color:#9ba8be;flex:0 0 30px;text-align:right;font-variant-numeric:tabular-nums}',
    '.qa-tbl{width:100%;border-collapse:collapse;font-size:12.5px}',
    '.qa-tbl th{text-align:left;font-size:11px;font-weight:700;letter-spacing:.04em;color:#5a6070;padding:5px 10px 8px;border-bottom:1px solid #e5e8ef}',
    '.qa-tbl td{padding:8px 10px;border-bottom:1px solid #f0f2f5;color:#1a1d23}',
    '.qa-tbl tr:last-child td{border-bottom:none}',
    '.qa-tbl .num{font-variant-numeric:tabular-nums;color:#5a6070}',
    '.qa-tbl .varname{font-weight:600;color:#1a1d23;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.qa-ranked-pair{display:grid;grid-template-columns:1fr 1fr;gap:20px}',
    '.qa-ranked-head{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px}',
    '.qa-ranked-head.hi{color:#166534}', '.qa-ranked-head.lo{color:#9a1e0f}',
    '.qa-ranked-row{display:flex;align-items:center;gap:6px;margin-bottom:7px}',
    '.qa-ranked-name{font-size:12px;color:#1a1d23;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.qa-ranked-score{font-size:12px;font-weight:700;color:#5a6070}',
    '.qa-ranked-bar-wrap{flex:0 0 60px;background:#f0f2f5;border-radius:4px;height:5px}',
    '.qa-ranked-bar{height:100%;border-radius:4px}', '.qa-ranked-bar.hi{background:#16a34a}', '.qa-ranked-bar.lo{background:#c0392b}',
    '.qa-group-scroll{overflow-x:auto}', '.qa-group-tbl{white-space:nowrap;min-width:100%}',
    '.qa-group-tbl .pos{color:#166534;font-weight:600}', '.qa-group-tbl .neg{color:#9a1e0f;font-weight:600}',
    '.qa-group-tbl .group-name{font-weight:600;color:#1a1d23}',
    /* Written report */
    '.qa-report{background:#fff;border:1px solid #e5e8ef;border-left:4px solid #c0392b;border-radius:12px;padding:22px 24px;margin-bottom:26px}',
    '.qa-report-eye{font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#c0392b;margin-bottom:8px}',
    '.qa-report h2{font-size:19px;font-weight:800;color:#1a1d23;margin:0 0 10px;line-height:1.25}',
    '.qa-report h3{font-size:14px;font-weight:700;color:#1a1d23;margin:18px 0 6px}',
    '.qa-report p{font-size:13.5px;line-height:1.6;color:#3a4050;margin:0 0 10px}',
    '.qa-report-cautions{background:#fdf6ec;border:1px solid #f3e2c7;border-radius:9px;padding:12px 15px;margin-top:14px}',
    '.qa-report-cautions h4{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9a6b1e;margin:0 0 7px}',
    '.qa-report-cautions ul{margin:0;padding-left:18px}', '.qa-report-cautions li{font-size:12.5px;line-height:1.5;color:#6b5320;margin-bottom:4px}',
    '.qa-report-loading{display:flex;align-items:center;gap:10px;color:#5a6070;font-size:13.5px}',
    '.qa-report-pending{color:#9ba8be;font-style:italic}',
    '.qa-cta{background:#fff;border:1px solid #e5e8ef;border-radius:12px;padding:24px;text-align:center;margin-bottom:10px}',
    '.qa-cta h2{font-size:17px;font-weight:800;color:#1a1d23;margin:0 0 6px}',
    '.qa-cta p{font-size:13px;color:#5a6070;margin:0 0 16px}',
    '.qa-cta-btn{display:inline-flex;align-items:center;gap:8px;background:#c0392b;color:#fff;padding:10px 20px;border-radius:9px;font-size:13.5px;font-weight:700;text-decoration:none}',
    '.qa-spin{width:18px;height:18px;border:2.5px solid #e5e8ef;border-top-color:#c0392b;border-radius:50%;animation:qa-spin .8s linear infinite;flex:0 0 auto}',
    '@keyframes qa-spin{to{transform:rotate(360deg)}}',
  ].join('');

  var OVERLAY_CSS = [
    '.qac-overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:24px}',
    '.qac-panel{background:#f4f5f7;border-radius:16px;width:100%;max-width:940px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 24px 70px rgba(15,23,42,.34);overflow:hidden}',
    '.qac-head{display:flex;align-items:center;gap:12px;padding:15px 20px;background:#fff;border-bottom:1px solid #e5e8ef;flex-shrink:0}',
    '.qac-head-title{font-size:14.5px;font-weight:700;color:#1a1d23;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.qac-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 15px;border-radius:9px;border:1px solid #e5e8ef;background:#fff;color:#1a1d23;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer}',
    '.qac-btn.primary{background:#c0392b;border-color:#c0392b;color:#fff}',
    '.qac-btn:disabled{opacity:.55;cursor:default}',
    '.qac-close{background:none;border:none;font-size:24px;line-height:1;color:#8a8f98;cursor:pointer;padding:0 4px}',
    '.qac-body{padding:24px 26px;overflow-y:auto;flex:1}',
    '.qac-loading{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:80px 20px;color:#5a6070}',
    '.qac-loading .qa-spin{width:34px;height:34px;border-width:3px;margin-bottom:14px}',
    '@media print{.qac-overlay{position:static;background:#fff;padding:0}.qac-panel{max-width:none;max-height:none;box-shadow:none;border-radius:0;background:#fff}.qac-head{display:none}.qac-body{overflow:visible;padding:0}}',
  ].join('');

  function injectStyles() {
    if (!document.getElementById('qac-content-styles')) {
      var s = document.createElement('style');
      s.id = 'qac-content-styles';
      s.textContent = CONTENT_CSS + OVERLAY_CSS;
      document.head.appendChild(s);
    }
  }

  // ── Utilities ────────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function round(x, d) { var m = Math.pow(10, d); return Math.round(x * m) / m; }

  // ── Engine ───────────────────────────────────────────────────────────────────
  function classify(vars) {
    var cat = [], num = [], likert = [];
    vars.forEach(function (v) {
      var t = (v.types && v.types[0]) || 'open';
      var vals = v.values.filter(function (x) { return x !== '' && x != null; });
      var nums = vals.map(parseFloat).filter(isFinite);
      var numFrac = vals.length ? nums.length / vals.length : 0;
      var isNum = numFrac >= 0.85;
      var distinct = new Set(vals.map(String));
      var lo = nums.length ? Math.min.apply(null, nums) : 0;
      var hi = nums.length ? Math.max.apply(null, nums) : 0;
      var isLikert = isNum && distinct.size <= 10 && lo >= 0 && hi <= 10 &&
                     nums.every(function (x) { return Number.isInteger(x); });
      if (t === 'identifier' || t === 'open') return;
      var entry = { name: v.name, type: t, values: v.values, distinct: distinct.size };
      if (t === 'likert' || isLikert) { entry.role = 'likert'; likert.push(entry); }
      else if (t === 'numeric' || (t === 'demographic' && isNum)) { entry.role = 'numeric'; num.push(entry); }
      else { entry.role = 'categorical'; cat.push(entry); }
    });
    return { cat: cat, num: num, likert: likert };
  }

  function freqs(v) {
    var counts = {};
    v.values.forEach(function (x) {
      var k = (x == null || x === '') ? '(missing)' : String(x).trim();
      counts[k] = (counts[k] || 0) + 1;
    });
    var total = v.values.length;
    return Object.keys(counts)
      .map(function (k) { return { label: k, n: counts[k], pct: round(counts[k] / total * 100, 1) }; })
      .sort(function (a, b) { return b.n - a.n; });
  }

  function stats(v) {
    var nums = v.values.map(parseFloat).filter(isFinite);
    if (!nums.length) return null;
    var n = nums.length;
    var mean = nums.reduce(function (s, x) { return s + x; }, 0) / n;
    var sd = Math.sqrt(nums.reduce(function (s, x) { return s + Math.pow(x - mean, 2); }, 0) / n);
    var sorted = nums.slice().sort(function (a, b) { return a - b; });
    var mid = Math.floor(n / 2);
    var median = n % 2 === 0 ? (sorted[mid - 1] + sorted[mid]) / 2 : sorted[mid];
    return { n: n, mean: round(mean, 2), sd: round(sd, 2), median: round(median, 2), min: sorted[0], max: sorted[n - 1] };
  }

  function groupSummary(groupVar, numVars) {
    var groups = {};
    groupVar.values.forEach(function (g, i) {
      var key = String(g == null || g === '' ? '(missing)' : g).trim();
      if (!groups[key]) groups[key] = { n: 0 };
      groups[key].n++;
      numVars.forEach(function (v) {
        var val = parseFloat(v.values[i]);
        if (isFinite(val)) {
          if (!groups[key][v.name]) groups[key][v.name] = { sum: 0, n: 0 };
          groups[key][v.name].sum += val;
          groups[key][v.name].n++;
        }
      });
    });
    var overallMeans = {};
    numVars.forEach(function (v) { var s = stats(v); overallMeans[v.name] = s ? s.mean : null; });
    return { groups: groups, overallMeans: overallMeans };
  }

  function computeModel(dataset) {
    var vars = dataset.variables || [];
    var c = classify(vars);
    return {
      vars: vars,
      n: vars.length ? vars[0].values.length : 0,
      source: dataset.source || 'Uploaded data',
      cat: c.cat, num: c.num, likert: c.likert,
      allNum: c.likert.concat(c.num),
      openCount: vars.filter(function (v) { return (v.types && v.types[0]) === 'open'; }).length,
    };
  }

  // ── Compact summary for the AI report ────────────────────────────────────────
  function buildSummary(model) {
    return {
      source: model.source, rows: model.n, variables: model.vars.length,
      frequencies: model.cat.slice(0, 6).map(function (v) {
        return { name: v.name, top: freqs(v).slice(0, 5).map(function (r) { return { value: r.label, pct: r.pct, n: r.n }; }) };
      }),
      numerics: model.allNum.map(function (v) {
        var s = stats(v); return s ? { name: v.name, mean: s.mean, sd: s.sd, median: s.median, min: s.min, max: s.max } : null;
      }).filter(Boolean),
      groups: (function () {
        var g = model.cat[0], nums = model.allNum.slice(0, 6);
        if (!g || !nums.length) return null;
        var gs = groupSummary(g, nums);
        return {
          by: g.name,
          rows: Object.keys(gs.groups).sort().map(function (k) {
            var grp = gs.groups[k], means = {};
            nums.forEach(function (v) { var cell = grp[v.name]; if (cell) means[v.name] = round(cell.sum / cell.n, 2); });
            return { group: k, n: grp.n, means: means };
          }),
        };
      })(),
    };
  }

  // ── HTML builders ────────────────────────────────────────────────────────────
  function buildContentHtml(model, opts) {
    opts = opts || {};
    var html = '<div class="qa-wrap">';

    if (opts.mode === 'report') {
      html += '<div class="qa-report" id="qa-report-slot">'
        + '<div class="qa-report-eye">ReliCheck Intelligence Report</div>'
        + '<div class="qa-report-loading"><span class="qa-spin"></span> Writing your report from the numbers below...</div>'
        + '</div>';
    }

    if (!opts.compact) {
      var pills = [model.n + ' rows', model.vars.length + ' variables'];
      if (model.cat.length) pills.push(model.cat.length + ' categorical');
      if (model.allNum.length) pills.push(model.allNum.length + ' numeric');
      if (model.likert.length) pills.push(model.likert.length + ' Likert');
      if (model.openCount) pills.push(model.openCount + ' open-ended');
      html += '<div class="qa-hero">'
        + '<div class="qa-hero-eye">Descriptive Analysis — Quick View</div>'
        + '<div class="qa-hero-title">' + esc(opts.projectTitle || 'Quick Analysis') + '</div>'
        + '<div class="qa-hero-sub">' + esc(model.source) + '</div>'
        + '<div class="qa-hero-pills">' + pills.map(function (p) { return '<span class="qa-pill">' + esc(p) + '</span>'; }).join('') + '</div>'
        + '</div>';
    }

    // Frequencies
    var catSlice = model.cat.slice(0, 6);
    if (catSlice.length) {
      html += '<div class="qa-section"><div class="qa-section-label">Frequencies</div><div class="qa-grid">';
      catSlice.forEach(function (v) {
        var rows = freqs(v), shown = rows.slice(0, 8), maxPct = rows.length ? rows[0].pct : 1;
        html += '<div class="qa-card"><div class="qa-card-title">' + esc(v.name) + '</div>'
          + '<div class="qa-card-sub">' + v.distinct + ' values &middot; ' + model.n + ' responses</div>';
        shown.forEach(function (row) {
          var w = maxPct > 0 ? Math.round(row.pct / maxPct * 100) : 0;
          html += '<div class="qa-freq-row"><div class="qa-freq-label" title="' + esc(row.label) + '">' + esc(row.label) + '</div>'
            + '<div class="qa-freq-track"><div class="qa-freq-fill" style="width:' + w + '%"></div></div>'
            + '<div class="qa-freq-pct">' + row.pct + '%</div><div class="qa-freq-n">' + row.n + '</div></div>';
        });
        if (rows.length > 8) html += '<div style="font-size:11px;color:#9ba8be;margin-top:6px">+ ' + (rows.length - 8) + ' more values</div>';
        html += '</div>';
      });
      html += '</div></div>';
    }

    // Means & Distributions
    if (model.allNum.length) {
      html += '<div class="qa-section"><div class="qa-section-label">Means &amp; Distributions</div><div class="qa-grid-1"><div class="qa-card">'
        + '<table class="qa-tbl"><thead><tr><th>Variable</th><th>N</th><th>Mean</th><th>SD</th><th>Median</th><th>Min</th><th>Max</th></tr></thead><tbody>';
      model.allNum.forEach(function (v) {
        var s = stats(v); if (!s) return;
        html += '<tr><td class="varname" title="' + esc(v.name) + '">' + esc(v.name) + '</td>'
          + '<td class="num">' + s.n + '</td><td class="num">' + s.mean + '</td><td class="num">' + s.sd + '</td>'
          + '<td class="num">' + s.median + '</td><td class="num">' + s.min + '</td><td class="num">' + s.max + '</td></tr>';
      });
      html += '</tbody></table></div></div></div>';
    }

    // Top & Bottom Items
    var lws = model.likert.map(function (v) { var s = stats(v); return s ? { name: v.name, mean: s.mean, max: s.max } : null; })
      .filter(Boolean).sort(function (a, b) { return b.mean - a.mean; });
    if (lws.length >= 3) {
      var gmax = Math.max.apply(null, lws.map(function (x) { return x.max; })) || 5;
      var top5 = lws.slice(0, 5), bot5 = lws.slice(-5).reverse();
      html += '<div class="qa-section"><div class="qa-section-label">Top &amp; Bottom Items</div><div class="qa-grid-1"><div class="qa-card"><div class="qa-ranked-pair">';
      html += '<div><div class="qa-ranked-head hi">&uarr; Highest rated</div>';
      top5.forEach(function (it) { var w = Math.round(it.mean / gmax * 100); html += '<div class="qa-ranked-row"><div class="qa-ranked-name" title="' + esc(it.name) + '">' + esc(it.name) + '</div><div class="qa-ranked-bar-wrap"><div class="qa-ranked-bar hi" style="width:' + w + '%"></div></div><div class="qa-ranked-score">' + it.mean + '</div></div>'; });
      html += '</div><div><div class="qa-ranked-head lo">&darr; Lowest rated</div>';
      bot5.forEach(function (it) { var w = Math.round(it.mean / gmax * 100); html += '<div class="qa-ranked-row"><div class="qa-ranked-name" title="' + esc(it.name) + '">' + esc(it.name) + '</div><div class="qa-ranked-bar-wrap"><div class="qa-ranked-bar lo" style="width:' + w + '%"></div></div><div class="qa-ranked-score">' + it.mean + '</div></div>'; });
      html += '</div></div></div></div></div>';
    }

    // Group Profile
    var grouper = model.cat[0], numSlice = model.allNum.slice(0, 6);
    if (grouper && numSlice.length) {
      var gs = groupSummary(grouper, numSlice), names = Object.keys(gs.groups).sort();
      html += '<div class="qa-section"><div class="qa-section-label">Group Profile &mdash; by ' + esc(grouper.name) + '</div><div class="qa-grid-1"><div class="qa-card"><div class="qa-group-scroll"><table class="qa-tbl qa-group-tbl"><thead><tr><th>' + esc(grouper.name) + '</th><th>N</th>';
      numSlice.forEach(function (v) { html += '<th>' + esc(v.name) + '</th>'; });
      html += '</tr></thead><tbody>';
      names.forEach(function (g) {
        var grp = gs.groups[g];
        html += '<tr><td class="group-name">' + esc(g) + '</td><td class="num">' + grp.n + '</td>';
        numSlice.forEach(function (v) {
          var cell = grp[v.name], mean = cell ? round(cell.sum / cell.n, 2) : null, overall = gs.overallMeans[v.name], cls = '';
          if (mean !== null && overall !== null) { var diff = mean - overall; cls = diff > 0.15 ? ' pos' : (diff < -0.15 ? ' neg' : ''); }
          html += '<td class="num' + cls + '">' + (mean !== null ? mean : '—') + '</td>';
        });
        html += '</tr>';
      });
      html += '</tbody></table></div></div></div></div>';
    }

    if (opts.workspaceUrl && !opts.compact) {
      html += '<div class="qa-cta"><h2>Ready to go deeper?</h2><p>Cross-tabs, scale scores, and saved reports are in the full studio.</p>'
        + '<a href="' + esc(opts.workspaceUrl) + '" class="qa-cta-btn">Open Descriptive Studio →</a></div>';
    }

    html += '</div>';
    return html;
  }

  function renderReport(slot, report) {
    if (!slot) return;
    var html = '<div class="qa-report-eye">ReliCheck Intelligence Report</div>';
    if (report.headline) html += '<h2>' + esc(report.headline) + '</h2>';
    if (report.overview) html += paras(report.overview);
    (report.sections || []).forEach(function (s) {
      if (s.heading) html += '<h3>' + esc(s.heading) + '</h3>';
      if (s.body) html += paras(s.body);
    });
    if (report.cautions && report.cautions.length) {
      html += '<div class="qa-report-cautions"><h4>Read with care</h4><ul>'
        + report.cautions.map(function (c) { return '<li>' + esc(c) + '</li>'; }).join('') + '</ul></div>';
    }
    slot.innerHTML = html;
  }
  function paras(text) {
    return String(text).split(/\n\s*\n/).map(function (p) { return p.trim() ? '<p>' + esc(p.trim()) + '</p>' : ''; }).join('');
  }

  // ── Render orchestration ─────────────────────────────────────────────────────
  function renderAll(container, projectId, opts) {
    opts = opts || {};
    injectStyles();
    container.innerHTML = '<div class="qac-loading"><span class="qa-spin"></span><div>Analyzing your data...</div></div>';
    return fetch('/api/analysis/dataset.php?project_id=' + projectId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok || !d.has_data || !d.dataset) throw new Error('No dataset linked to this project.');
        var model = computeModel(d.dataset);
        container.innerHTML = buildContentHtml(model, opts);
        if (opts.mode === 'report') {
          var slot = container.querySelector('#qa-report-slot');
          fetchReport(projectId, buildSummary(model))
            .then(function (report) { renderReport(slot, report); })
            .catch(function (err) {
              if (slot) slot.innerHTML = '<div class="qa-report-eye">ReliCheck Intelligence Report</div>'
                + '<p class="qa-report-pending">' + esc(err.message || 'The written report could not be generated.') + ' The analysis below is complete.</p>';
            });
        }
        return model;
      })
      .catch(function (err) {
        container.innerHTML = '<div class="qac-loading"><div style="color:#c0392b;font-weight:700;margin-bottom:6px">Could not load analysis</div><div>' + esc(err.message || '') + '</div></div>';
        throw err;
      });
  }

  function fetchReport(projectId, summary) {
    return fetch('/api/analysis/ai-report.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ project_id: projectId, summary: summary }),
    }).then(function (r) {
      return r.json().then(function (d) {
        if (!r.ok || !d.ok || !d.report) throw new Error((d && d.message) ? d.message : 'Report generation failed.');
        return d.report;
      });
    });
  }

  // ── Popup ────────────────────────────────────────────────────────────────────
  function openPopup(projectId, opts) {
    opts = Object.assign({ compact: true }, opts || {});
    injectStyles();
    var overlay = document.createElement('div');
    overlay.className = 'qac-overlay';
    var titleText = (opts.mode === 'report' ? 'Auto report' : 'Auto analysis') + (opts.projectTitle ? ' — ' + opts.projectTitle : '');
    overlay.innerHTML =
      '<div class="qac-panel" role="dialog" aria-label="Analysis results">'
      + '<div class="qac-head">'
      + '<div class="qac-head-title">' + esc(titleText) + '</div>'
      + '<button class="qac-btn primary" id="qacPrint">&#128424; Print / Save as PDF</button>'
      + '<button class="qac-close" aria-label="Close">&times;</button>'
      + '</div>'
      + '<div class="qac-body" id="qacBody"></div>'
      + '</div>';
    document.body.appendChild(overlay);
    var close = function () { overlay.remove(); };
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    overlay.querySelector('.qac-close').addEventListener('click', close);
    overlay.querySelector('#qacPrint').addEventListener('click', function () {
      printDoc(titleText, overlay.querySelector('#qacBody').innerHTML);
    });
    renderAll(overlay.querySelector('#qacBody'), projectId, opts);
    return overlay;
  }

  function printDoc(title, contentHtml) {
    var w = window.open('', '_blank');
    if (!w) { alert('Please allow popups to print.'); return; }
    w.document.write(
      '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title>'
      + '<style>body{margin:24px;background:#fff}' + CONTENT_CSS + '</style></head><body>'
      + contentHtml + '</body></html>'
    );
    w.document.close();
    w.focus();
    setTimeout(function () { w.print(); }, 350);
  }

  window.QuickAnalyze = { renderAll: renderAll, openPopup: openPopup };
})();
