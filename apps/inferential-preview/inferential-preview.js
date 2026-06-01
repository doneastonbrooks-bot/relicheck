/* =============================================================================
   inferential-preview.js — "Variables & Fit" panel for Inferential Studio.

   A persistent, read-only pre-analysis diagnostic layer that helps the user
   answer: "Which analysis can I responsibly run with these variables?"

   It reads the SAME dataset shape the inferential/descriptive engines use:
     { source, variables: [{ name, types:[], values:[] }], rowCount }
   and the SAME type conventions:
     numeric-ish = type 'numeric' or 'likert'
     categorical = type 'categorical'
     missing     = '' or null

   BOUNDARY: this panel is decision support, NOT the Descriptive Studio. It
   computes only lightweight summaries (n, missingness, mean/SD, group sizes,
   a mini distribution, and a small cross-tab preview). It does NOT compute or
   display Cronbach's alpha, item-total r, alpha-if-removed, item diagnostics,
   reliability interpretation, or RSSI strength scoring. Those live in RSSI.
   For full descriptive analysis it links out to the Descriptive Analysis
   Studio; for reliability it points to RSSI.
   ============================================================================= */
(function () {
  'use strict';

  var cfg = window.INFPREVIEW_CONFIG || {};
  var PROJECT_ID = cfg.projectId || 0;
  var ACTIVE_TOOL = cfg.activeTool || '';
  var mount = document.getElementById('ivfBody');
  if (!mount) return;

  // ---- type + value helpers (match the engines) ----
  function hasType(v, t) { return v.types && v.types.indexOf(t) !== -1; }
  function isNumericish(v) { return hasType(v, 'numeric') || hasType(v, 'likert'); }
  function isCategorical(v) { return hasType(v, 'categorical'); }
  function isOpen(v) { return hasType(v, 'open'); }
  function isMissing(x) { return x === '' || x == null; }
  function num(x) { var n = parseFloat(x); return isNaN(n) ? null : n; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function fmt(x, d) { return (x == null || isNaN(x)) ? '—' : Number(x).toFixed(d == null ? 2 : d); }

  function validValues(v) { return v.values.filter(function (x) { return !isMissing(x); }); }
  function levelsOf(v) {
    var m = new Map();
    v.values.forEach(function (val) { if (isMissing(val)) return; var k = String(val); m.set(k, (m.get(k) || 0) + 1); });
    return Array.from(m.entries()).map(function (e) { return { level: e[0], count: e[1] }; }).sort(function (a, b) { return b.count - a.count; });
  }
  function numStats(v) {
    var xs = validValues(v).map(num).filter(function (x) { return x != null; });
    var n = xs.length;
    if (!n) return { n: 0 };
    var mean = xs.reduce(function (s, x) { return s + x; }, 0) / n;
    var variance = n > 1 ? xs.reduce(function (s, x) { return s + (x - mean) * (x - mean); }, 0) / (n - 1) : 0;
    var sd = Math.sqrt(variance);
    var sorted = xs.slice().sort(function (a, b) { return a - b; });
    var median = n % 2 ? sorted[(n - 1) / 2] : (sorted[n / 2 - 1] + sorted[n / 2]) / 2;
    return { n: n, mean: mean, sd: sd, min: sorted[0], max: sorted[n - 1], median: median, xs: xs };
  }
  function miniHist(xs, bins) {
    bins = bins || 10;
    if (!xs.length) return '';
    var lo = Math.min.apply(null, xs), hi = Math.max.apply(null, xs);
    var size = (hi - lo) / bins || 1;
    var counts = new Array(bins).fill(0);
    xs.forEach(function (x) { var b = Math.floor((x - lo) / size); if (b >= bins) b = bins - 1; if (b < 0) b = 0; counts[b]++; });
    var mx = Math.max.apply(null, counts) || 1;
    return '<div class="ivf-mini">' + counts.map(function (c) { return '<i style="height:' + Math.round((c / mx) * 100) + '%"></i>'; }).join('') + '</div>';
  }

  // ---- type label ----
  function typeOf(v) {
    if (hasType(v, 'likert')) return { key: 'likert', label: 'Likert' };
    if (hasType(v, 'numeric')) return { key: 'numeric', label: 'Numeric' };
    if (isCategorical(v)) return { key: 'categorical', label: 'Categorical' };
    if (isOpen(v)) return { key: 'open', label: 'Open text' };
    return { key: 'other', label: (v.types && v.types[0]) ? v.types[0] : '—' };
  }

  // ---- fit specs per inferential tool (dataset-level: CAN this run here?) ----
  // Mirrors apps/inferential field shapes; extended for the extension tools.
  var FIT = {
    t_test:              { label: 't-test',               need: 'one numeric/Likert outcome and one categorical grouping variable with exactly 2 levels', test: function (inv) { return inv.numeric.length >= 1 && inv.cat2.length >= 1; } },
    anova:               { label: 'one-way ANOVA',        need: 'one numeric/Likert outcome and one categorical grouping variable with 3 or more levels', test: function (inv) { return inv.numeric.length >= 1 && inv.cat3.length >= 1; } },
    welch_anova:         { label: 'Welch ANOVA',          need: 'one numeric/Likert outcome and one categorical grouping variable with 3 or more levels', test: function (inv) { return inv.numeric.length >= 1 && inv.cat3.length >= 1; } },
    post_hoc:            { label: 'post-hoc comparisons', need: 'one numeric/Likert outcome and one categorical grouping variable with 3 or more levels', test: function (inv) { return inv.numeric.length >= 1 && inv.cat3.length >= 1; } },
    chi_square:          { label: 'chi-square',           need: 'two categorical variables', test: function (inv) { return inv.cat.length >= 2; } },
    correlation:         { label: 'correlation',          need: 'two numeric/Likert variables', test: function (inv) { return inv.numeric.length >= 2; } },
    paired_t_test:       { label: 'paired t-test',        need: 'two numeric/Likert variables measured on the same respondents', test: function (inv) { return inv.numeric.length >= 2; } },
    regression:          { label: 'regression',           need: 'one numeric/Likert outcome and at least one predictor (numeric, Likert, or categorical)', test: function (inv) { return inv.numeric.length >= 1 && (inv.numeric.length + inv.cat.length) >= 2; } },
    confidence_interval: { label: 'a confidence interval',need: 'at least one numeric/Likert variable (mean) or one categorical variable (proportion)', test: function (inv) { return inv.numeric.length >= 1 || inv.cat.length >= 1; } },
    effect_sizes:        { label: 'effect sizes',         need: 'variables matching the comparison you want to size (groups, counts, or two numeric variables)', test: function (inv) { return inv.vars.length >= 1; } },
    assumption_checks:   { label: 'assumption checks',    need: 'at least one numeric/Likert variable', test: function (inv) { return inv.numeric.length >= 1; } }
  };

  // role hint per tool, used to annotate the variable rows
  function roleFor(v, inv) {
    var t = ACTIVE_TOOL;
    var nis = isNumericish(v), cat = isCategorical(v), lv = cat ? levelsOf(v).length : 0;
    if (t === 't_test') { if (nis) return 'candidate outcome'; if (cat && lv === 2) return 'candidate grouping'; }
    if (t === 'anova' || t === 'welch_anova' || t === 'post_hoc') { if (nis) return 'candidate outcome'; if (cat && lv >= 3) return 'candidate grouping'; }
    if (t === 'correlation' || t === 'paired_t_test') { if (nis) return 'candidate variable'; }
    if (t === 'chi_square') { if (cat) return 'candidate variable'; }
    if (t === 'regression') { if (nis) return 'candidate outcome / predictor'; if (cat) return 'candidate predictor'; }
    if (t === 'confidence_interval' || t === 'assumption_checks') { if (nis) return 'candidate variable'; }
    return '';
  }

  // ---- load dataset, then render ----
  function withDataset(cb) {
    if (PROJECT_ID > 0) {
      // Authoritative SIRI / survey-dev identity (survey_projects.id).
      fetch('/api/dev/responses-dataset.php?project_id=' + encodeURIComponent(PROJECT_ID), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) { cb(d && d.payload && d.payload.dataset ? d.payload.dataset : null); })
        .catch(function () { cb(null); });
      return;
    }
    // upload flow: most-recent localStorage dataset
    var best = null;
    try {
      for (var i = 0; i < window.localStorage.length; i++) {
        var k = window.localStorage.key(i);
        if (!k || k.indexOf('relicheck.dataset.') !== 0) continue;
        var w = JSON.parse(window.localStorage.getItem(k));
        if (w && w.payload && w.payload.dataset && (w.payload.dataset.rowCount || 0) > 0) {
          if (!best || (w.savedAt || 0) > best.savedAt) best = { savedAt: w.savedAt || 0, ds: w.payload.dataset };
        }
      }
    } catch (e) {}
    cb(best ? best.ds : null);
  }

  function render(dataset) {
    if (!dataset || !Array.isArray(dataset.variables) || !dataset.variables.length || !(dataset.rowCount > 0)) {
      mount.innerHTML = '<div class="ivf-empty">Load data above to preview your variables. This panel shows variable types, valid n, missingness, distributions, and group sizes so you can judge which analysis fits.</div>';
      return;
    }
    var rowCount = dataset.rowCount;
    var vars = dataset.variables;
    var inv = {
      vars: vars,
      numeric: vars.filter(isNumericish),
      cat: vars.filter(isCategorical),
      cat2: vars.filter(function (v) { return isCategorical(v) && levelsOf(v).length === 2; }),
      cat3: vars.filter(function (v) { return isCategorical(v) && levelsOf(v).length >= 3; }),
      open: vars.filter(isOpen)
    };

    var html = '';

    // ---- fit banner ----
    if (ACTIVE_TOOL && FIT[ACTIVE_TOOL]) {
      var spec = FIT[ACTIVE_TOOL];
      var fits = spec.test(inv);
      html += '<div class="ivf-fit ' + (fits ? 'ok' : 'bad') + '">'
        + '<span class="fi">' + (fits ? '✓' : '⚠') + '</span>'
        + '<span>' + (fits
            ? 'Your dataset can support <strong>' + esc(spec.label) + '</strong>. It needs ' + esc(spec.need) + ', and your variables include the required types. Confirm the exact variables in the test workspace below.'
            : 'Your dataset may <strong>not</strong> fit <strong>' + esc(spec.label) + '</strong>. It needs ' + esc(spec.need) + '. Review the inventory below before running, or pick a different analysis.')
        + '</span></div>';
    } else {
      html += '<div class="ivf-fit info"><span class="fi">ℹ</span><span>Pick a tool on the left and this panel will check whether your variables can responsibly support it.</span></div>';
    }

    // ---- inventory chips ----
    html += '<div class="ivf-chips">'
      + '<span class="ivf-chip"><strong>' + rowCount + '</strong> rows</span>'
      + '<span class="ivf-chip"><strong>' + vars.length + '</strong> variables</span>'
      + '<span class="ivf-chip"><strong>' + inv.numeric.length + '</strong> numeric / Likert</span>'
      + '<span class="ivf-chip"><strong>' + inv.cat.length + '</strong> categorical</span>'
      + (inv.open.length ? '<span class="ivf-chip"><strong>' + inv.open.length + '</strong> open text</span>' : '')
      + '</div>';

    // ---- variable table ----
    html += '<table class="ivf-table"><thead><tr>'
      + '<th>Variable</th><th>Type</th><th>Valid n</th><th>Missing</th><th>Summary &amp; distribution</th>'
      + '</tr></thead><tbody>';

    vars.forEach(function (v) {
      var t = typeOf(v);
      var validN = validValues(v).length;
      var miss = rowCount - validN;
      var missPct = rowCount ? Math.round((miss / rowCount) * 100) : 0;
      var role = roleFor(v, inv);
      var summary = '';

      if (isNumericish(v)) {
        var s = numStats(v);
        if (s.n) {
          var skew = (s.sd > 0 && Math.abs(s.mean - s.median) > 0.5 * s.sd) ? '<span class="ivf-flag">skew</span>' : '';
          var smallN = s.n < 30 ? '<span class="ivf-flag">small n</span>' : '';
          summary = '<div class="ivf-summary">mean ' + fmt(s.mean) + ' · SD ' + fmt(s.sd) + ' · range ' + fmt(s.min) + '–' + fmt(s.max) + skew + smallN + '</div>' + miniHist(s.xs);
        } else { summary = '<div class="ivf-summary">no numeric values</div>'; }
      } else if (isCategorical(v)) {
        var lvls = levelsOf(v);
        var minG = lvls.length ? lvls[lvls.length - 1].count : 0;
        var sparse = minG < 5 ? '<span class="ivf-flag">sparse cell</span>' : '';
        var mxc = lvls.length ? lvls[0].count : 1;
        summary = '<div class="ivf-summary">' + lvls.length + ' levels · smallest group ' + minG + sparse + '</div>'
          + '<div class="ivf-lvls">' + lvls.slice(0, 6).map(function (l) {
              return '<div class="lv"><b title="' + esc(l.level) + '">' + esc(l.level) + '</b><span><i style="width:' + Math.round((l.count / mxc) * 100) + '%"></i></span><em>' + l.count + '</em></div>';
            }).join('') + (lvls.length > 6 ? '<div class="lv"><em>+' + (lvls.length - 6) + ' more</em></div>' : '') + '</div>';
      } else if (isOpen(v)) {
        summary = '<div class="ivf-summary">open-ended text · ' + validN + ' responses. Text summaries live in the Descriptive Studio / MM Studio.</div>';
      } else {
        summary = '<div class="ivf-summary">—</div>';
      }

      html += '<tr>'
        + '<td><span class="ivf-vname">' + esc(v.name) + '</span>' + (role ? '<span class="ivf-role">' + esc(role) + '</span>' : '') + '</td>'
        + '<td><span class="ivf-type ' + t.key + '">' + esc(t.label) + '</span></td>'
        + '<td>' + validN + '</td>'
        + '<td class="' + (missPct >= 20 ? 'ivf-miss-warn' : 'ivf-miss-ok') + '">' + miss + ' (' + missPct + '%)</td>'
        + '<td>' + summary + '</td>'
        + '</tr>';
    });
    html += '</tbody></table>';

    // ---- cross-tab preview (only when relevant: chi-square + 2 categoricals) ----
    if (ACTIVE_TOOL === 'chi_square' && inv.cat.length >= 2) {
      // pick the two categoricals with the fewest levels for a compact preview
      var byLevels = inv.cat.slice().sort(function (a, b) { return levelsOf(a).length - levelsOf(b).length; });
      var rv = byLevels[0], cv = byLevels[1];
      var rLevels = levelsOf(rv).slice(0, 6), cLevels = levelsOf(cv).slice(0, 6);
      var counts = {};
      for (var i = 0; i < rowCount; i++) {
        var a = rv.values[i], b = cv.values[i];
        if (isMissing(a) || isMissing(b)) continue;
        var key = String(a) + '' + String(b);
        counts[key] = (counts[key] || 0) + 1;
      }
      var xt = '<div class="ivf-xtab"><h3>Cross-tab preview: ' + esc(rv.name) + ' × ' + esc(cv.name) + '</h3><table><thead><tr><th></th>';
      cLevels.forEach(function (c) { xt += '<th>' + esc(c.level) + '</th>'; });
      xt += '</tr></thead><tbody>';
      rLevels.forEach(function (r) {
        xt += '<tr><th>' + esc(r.level) + '</th>';
        cLevels.forEach(function (c) { xt += '<td>' + (counts[r.level + '' + c.level] || 0) + '</td>'; });
        xt += '</tr>';
      });
      xt += '</tbody></table><div class="ivf-summary" style="margin-top:6px;">Counts only. Full cross-tab with row/column percentages and the chi-square test run in the workspace below.</div></div>';
      html += xt;
    }

    // ---- footer: pointers, NOT computation ----
    var descHref = '/descriptive-analysis-studio.php' + (PROJECT_ID > 0 ? '?project_id=' + PROJECT_ID + '&source=project' : '');
    html += '<div class="ivf-foot">'
      + '<span>This is a pre-analysis preview, not full descriptive analysis. <a href="' + descHref + '">Open the Descriptive Analysis Studio →</a></span>'
      + '<span>Reliability, Cronbach&rsquo;s &alpha;, and item analysis are computed in <a href="/rssi.php">RSSI</a>, not here.</span>'
      + '</div>';

    mount.innerHTML = html;
  }

  // collapse toggle
  var head = document.getElementById('ivfHead');
  var panel = document.getElementById('ivfPanel');
  var toggle = document.getElementById('ivfToggle');
  if (head && panel && toggle) {
    head.addEventListener('click', function () {
      panel.classList.toggle('is-collapsed');
      toggle.textContent = panel.classList.contains('is-collapsed') ? 'Show' : 'Hide';
    });
  }

  withDataset(render);
})();
