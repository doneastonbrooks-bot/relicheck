// Uniform studio header plug-in — shared across all ReliCheck analysis studios.
// Renders into <div id="studioHeader"></div>.
//
// StudioHeader.init(opts)
//   logoSrc      — studio wordmark path  (default: /logo-brand.svg)
//   logoAlt      — logo alt text
//   logoHeight   — px height of logo image (default: 70)
//   projectLabel — current project name shown in the context pill
//   projectLive  — bool: green status dot when true, gray when false
//   projectsUrl  — href for the "All projects" nav link
//   initials     — two-letter user avatar string
//
// StudioHeader.setProject(label, live)   — update project pill after init
// StudioHeader.loadRssiStub(surveyPid)   — fetch + show RSSI badge (async)
// StudioHeader.setRssiStub(data)         — show RSSI badge from known data
//   data: { score, pct, band, withheld, tier, link }
//
// window.loadRssiStub(pid) is kept as an alias for platform-shell pages.

(function () {
  'use strict';

  var CSS = ''
    + '.sh-bar{display:flex;align-items:center;gap:14px;height:90px;padding:0 22px;'
    +   'background:var(--panel,#fff);border-bottom:1px solid var(--line,#e6e8ec)}'
    + '.sh-logo{display:flex;align-items:center;text-decoration:none;color:var(--ink,#15171a);flex:none}'
    + '.sh-logo img{width:auto;display:block;height:var(--sh-logo-h,70px);max-height:80px}'
    + '.sh-ctx{margin-left:8px;display:flex;align-items:center;gap:8px;font-size:13px;'
    +   'color:var(--ink-2,#5f6368)}'
    + '.sh-ctx .sh-dot{width:8px;height:8px;border-radius:50%;background:#cdd6e4;flex:none}'
    + '.sh-ctx.is-live .sh-dot{background:var(--green,#1f9e44)}'
    + '.sh-spacer{flex:1}'
    + '.sh-nav{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:10px;'
    +   'border:1px solid var(--line,#e6e8ec);background:#fff;color:var(--ink,#15171a);'
    +   'font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}'
    + '.sh-nav:hover{border-color:var(--acc,#6b7280)}'
    + '.sh-avatar{width:34px;height:34px;border-radius:50%;background:var(--acc-soft,#eef0f3);'
    +   'color:var(--acc-deep,#2a2f3a);display:flex;align-items:center;justify-content:center;'
    +   'font-weight:700;font-size:13px;flex:none}'
    /* RSSI stub */
    + '.sh-rssi{display:flex;align-items:center}'
    + '.rssi-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 13px;'
    +   'border-radius:10px;font-size:12px;font-weight:700;text-decoration:none;'
    +   'border:1.5px solid transparent;line-height:1}'
    + '.rssi-badge .rssi-score{font-size:17px;font-weight:800}'
    + '.rssi-badge .rssi-lbl{font-size:10px;font-weight:700;text-transform:uppercase;'
    +   'letter-spacing:.05em;opacity:.72;white-space:nowrap}'
    + '.rssi-confident{background:var(--green-soft,#e9f7ee);color:var(--green,#1f9e44)}'
    + '.rssi-developing{background:#fff8ee;color:#b45309}'
    + '.rssi-withheld{background:var(--bg,#f5f6f8);color:var(--ink-3,#8a8f98);'
    +   'border-color:var(--line,#e6e8ec)}';

  var cssInjected = false;
  function injectCss() {
    if (cssInjected) return; cssInjected = true;
    var el = document.createElement('style');
    el.id = 'studio-header-css';
    el.textContent = CSS;
    document.head.appendChild(el);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function render(opts) {
    var el = document.getElementById('studioHeader');
    if (!el) return;
    var h = opts.logoHeight || 70;
    var live = opts.projectLive ? ' is-live' : '';
    el.innerHTML = ''
      + '<header class="sh-bar" style="--sh-logo-h:' + h + 'px">'
      + '<a class="sh-logo" href="/app-2026v4.php">'
      + '<img src="' + esc(opts.logoSrc || '/logo-brand.svg') + '"'
      + ' alt="' + esc(opts.logoAlt || 'ReliCheck') + '">'
      + '</a>'
      + '<div class="sh-ctx' + live + '" id="shCtx">'
      + '<span class="sh-dot"></span>'
      + '<span id="shCtxLabel">' + esc(opts.projectLabel || 'No project') + '</span>'
      + '</div>'
      + '<div class="sh-spacer"></div>'
      + '<a class="sh-nav" href="' + esc(opts.projectsUrl || '#') + '">All projects</a>'
      + '<div class="sh-rssi" id="tbRssi" hidden></div>'
      + '<div class="sh-avatar">' + esc(opts.initials || '') + '</div>'
      + '</header>';
  }

  function populateBadge(d) {
    var wrap = document.getElementById('tbRssi');
    if (!wrap) return;
    if (!d || !d.has_rssi) { wrap.hidden = true; return; }
    var tier = d.withheld ? 'withheld' : (d.tier || 'withheld');
    var score = (!d.withheld && d.pct != null)
      ? '<span class="rssi-score">' + d.pct + '</span>' : '';
    wrap.innerHTML = '<a class="rssi-badge rssi-' + tier + '"'
      + ' href="' + esc(d.link || '/rssi.php') + '"'
      + ' title="' + esc(d.band || 'RSSI result') + '" target="_blank">'
      + score + '<span class="rssi-lbl">RSSI</span></a>';
    wrap.hidden = false;
  }

  window.StudioHeader = {
    init: function (opts) {
      injectCss();
      render(opts || {});
    },

    setProject: function (label, live) {
      var lbl = document.getElementById('shCtxLabel');
      var ctx = document.getElementById('shCtx');
      if (lbl) lbl.textContent = label || 'No project';
      if (ctx) ctx.classList.toggle('is-live', !!live);
    },

    // Fetch RSSI data from the server then show the badge.
    // Also notifies StudioFooter so the RSSI popup has the same data.
    loadRssiStub: function (surveyPid) {
      var wrap = document.getElementById('tbRssi');
      if (!wrap || !surveyPid) return;
      fetch('/api/dev/rssi-check.php?project_id=' + encodeURIComponent(surveyPid), {
        credentials: 'same-origin', headers: { Accept: 'application/json' }
      })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        populateBadge(d);
        if (window.StudioFooter && d && d.ok) window.StudioFooter.setRssiInfo(d);
      })
      .catch(function () { wrap.hidden = true; });
    },

    // Populate the badge from already-known data (e.g. MM Studio's BOOT.scores).
    // Also notifies StudioFooter.
    setRssiStub: function (data) {
      populateBadge(data);
      if (window.StudioFooter && data) window.StudioFooter.setRssiInfo(data);
    }
  };

  // Alias for platform-shell pages (360, TIA) until they adopt full StudioHeader.
  window.loadRssiStub = function (pid) { window.StudioHeader.loadRssiStub(pid); };

})();
