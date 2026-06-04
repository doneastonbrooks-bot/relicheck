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
// StudioHeader.setSiriInfo(data)         — populate SIRI popup
//   data: { score, band, link }
// StudioHeader.setRssiInfo(data)         — populate RSSI popup
//   data: { score, pct, band, withheld, tier, link, has_rssi }
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
    + '.sh-logo img{width:auto;display:block}'
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
    /* User menu */
    + '.sh-aw{position:relative;display:inline-flex}'
    + 'button.sh-avatar{cursor:pointer;appearance:none;-webkit-appearance:none;padding:0;font-family:inherit;border:none;line-height:1}'
    + '.sh-menu{position:absolute;top:calc(100% + 10px);right:0;min-width:200px;background:#fff;border:1px solid rgba(15,23,42,.10);border-radius:14px;box-shadow:0 8px 28px rgba(15,23,42,.12);padding:4px 0;opacity:0;pointer-events:none;transform:translateY(-6px) scale(.97);transition:opacity .13s,transform .13s;transform-origin:top right;z-index:300}'
    + '.sh-menu.open{opacity:1;pointer-events:auto;transform:none}'
    + '.sh-menu-profile{display:flex;align-items:center;gap:10px;padding:12px 14px 10px}'
    + '.sh-menu-av{width:32px;height:32px;border-radius:50%;background:#e8edf5;color:#2a2f3a;font-size:12px;font-weight:700;display:grid;place-items:center;flex:none}'
    + '.sh-menu-name{font-size:13px;font-weight:600;color:#15171a;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:140px}'
    + '.sh-menu-div{height:1px;background:rgba(15,23,42,.08);margin:3px 0}'
    + '.sh-menu-item{display:block;width:100%;padding:9px 14px;font-size:14px;color:#15171a;font-weight:500;text-decoration:none;background:none;border:none;text-align:left;cursor:pointer;transition:background .1s;box-sizing:border-box}'
    + '.sh-menu-item:hover{background:#f5f6f8}'
    /* RSSI badge */
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
    +   'border-color:var(--line,#e6e8ec)}'
    /* Index popup buttons (SIRI / RSSI) */
    + '.sh-ibw{position:relative;display:inline-flex}'
    + '.sh-ibtn{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;'
    +   'border-radius:10px;border:1px solid var(--line,#e6e8ec);'
    +   'background:#fff;color:var(--ink,#15171a);font-family:inherit;'
    +   'font-size:13px;font-weight:600;cursor:pointer;'
    +   'transition:border-color .13s,background .13s}'
    + '.sh-ibtn:hover{border-color:var(--acc,#6b7280)}'
    + '.sh-ibtn.sh-on{background:var(--acc-soft,#eef0f3);color:var(--acc-deep,#2a2f3a);border-color:transparent}'
    + '.sh-pop{position:absolute;top:calc(100% + 10px);right:0;width:256px;'
    +   'background:var(--panel,#fff);border:1px solid var(--line,#e6e8ec);'
    +   'border-radius:14px;box-shadow:0 4px 28px rgba(15,23,42,.14);'
    +   'overflow:hidden;z-index:60}'
    + '.sh-pop[hidden]{display:none}'
    + '.shp-head{display:flex;align-items:center;padding:11px 14px;'
    +   'border-bottom:1px solid var(--line,#e6e8ec)}'
    + '.shp-title{flex:1;font-size:13px;font-weight:700;color:var(--ink,#15171a)}'
    + '.shp-x{border:none;background:none;color:var(--ink-3,#8a8f98);'
    +   'font-size:20px;line-height:1;cursor:pointer;padding:0 0 0 8px}'
    + '.shp-body{padding:14px}'
    + '.shp-score{font-size:30px;font-weight:800;color:var(--ink,#15171a);line-height:1}'
    + '.shp-max{font-size:13px;color:var(--ink-3,#8a8f98);margin-left:3px}'
    + '.shp-band{font-size:12px;color:var(--ink-2,#5f6368);margin-top:3px;margin-bottom:10px}'
    + '.shp-empty{font-size:13px;color:var(--ink-2,#5f6368);margin-bottom:10px;line-height:1.5}'
    + '.shp-link{display:inline-flex;align-items:center;font-size:13px;font-weight:700;'
    +   'color:var(--acc,#6b7280);text-decoration:none}'
    + '.shp-link:hover{text-decoration:underline}';

  var _siri = null;
  var _rssi = null;

  function initDropdown() {
    var btn  = document.getElementById('shUserBtn');
    var menu = document.getElementById('shUserMenu');
    if (!btn || !menu) return;
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      var open = menu.classList.toggle('open');
      btn.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', function() {
      menu.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
    });
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && menu.classList.contains('open')) {
        menu.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
      }
    });
    var so = document.getElementById('shSignOut');
    if (so) so.addEventListener('click', function(e) {
      e.preventDefault();
      fetch('/api/auth/logout.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).finally(function() { window.location.href = '/login.html'; });
    });
  }

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

  function siriHtml() {
    var d = _siri;
    var body;
    if (!d || d.score == null) {
      body = '<p class="shp-empty">No SIRI score yet. Assess your instrument before launch.</p>'
        + '<a class="shp-link" href="/develop.php?db=1&start=choose">Go to SIRI &#8594;</a>';
    } else {
      body = '<div><span class="shp-score">' + d.score.toFixed(1) + '</span>'
        + '<span class="shp-max">/ 100</span></div>'
        + (d.band ? '<div class="shp-band">' + esc(d.band) + '</div>' : '<div style="margin-bottom:10px"></div>')
        + '<a class="shp-link" href="' + esc(d.link || '/develop.php?db=1&start=choose') + '">View in SIRI &#8594;</a>';
    }
    return '<div class="shp-head"><span class="shp-title">SIRI Readiness</span>'
      + '<button class="shp-x" data-pop="shSiriPop">&#215;</button></div>'
      + '<div class="shp-body">' + body + '</div>';
  }

  function rssiHtml() {
    var d = _rssi;
    var body;
    if (!d || !d.has_rssi) {
      body = '<p class="shp-empty">No RSSI score yet. Run RSSI after collecting responses.</p>'
        + '<a class="shp-link" href="/rssi-app.php">Go to RSSI &#8594;</a>';
    } else if (d.withheld) {
      body = '<p class="shp-empty">Insufficient data to score.</p>'
        + (d.band ? '<div class="shp-band">' + esc(d.band) + '</div>' : '')
        + '<a class="shp-link" href="' + esc(d.link || '/rssi-app.php') + '" target="_blank">View in RSSI &#8594;</a>';
    } else {
      body = '<div><span class="shp-score">' + (d.pct != null ? d.pct : '&mdash;') + '</span>'
        + '<span class="shp-max">/ 100</span></div>'
        + (d.band ? '<div class="shp-band">' + esc(d.band) + '</div>' : '<div style="margin-bottom:10px"></div>')
        + '<a class="shp-link" href="' + esc(d.link || '/rssi-app.php') + '" target="_blank">View in RSSI &#8594;</a>';
    }
    return '<div class="shp-head"><span class="shp-title">Survey Strength &middot; RSSI</span>'
      + '<button class="shp-x" data-pop="shRssiPop">&#215;</button></div>'
      + '<div class="shp-body">' + body + '</div>';
  }

  function shCloseAll() {
    var sp = document.getElementById('shSiriPop');
    var rp = document.getElementById('shRssiPop');
    if (sp) sp.hidden = true;
    if (rp) rp.hidden = true;
    document.querySelectorAll('.sh-ibtn').forEach(function (b) { b.classList.remove('sh-on'); });
  }

  function shTogglePop(popId, btnId, htmlFn) {
    var pop = document.getElementById(popId);
    var btn = document.getElementById(btnId);
    if (!pop || !btn) return;
    var wasHidden = pop.hidden;
    shCloseAll();
    if (wasHidden) {
      pop.innerHTML = htmlFn();
      pop.hidden = false;
      btn.classList.add('sh-on');
      pop.querySelectorAll('.shp-x').forEach(function (x) {
        x.addEventListener('click', shCloseAll);
      });
    }
  }

  function initIndexBtns() {
    var sb = document.getElementById('shSiriBtn');
    var rb = document.getElementById('shRssiBtn');
    if (sb) sb.addEventListener('click', function (e) {
      e.stopPropagation();
      shTogglePop('shSiriPop', 'shSiriBtn', siriHtml);
    });
    if (rb) rb.addEventListener('click', function (e) {
      e.stopPropagation();
      shTogglePop('shRssiPop', 'shRssiBtn', rssiHtml);
    });
    document.addEventListener('click', function (e) {
      var inside = false;
      document.querySelectorAll('.sh-ibw').forEach(function (w) {
        if (w.contains(e.target)) inside = true;
      });
      if (!inside) shCloseAll();
    });
  }

  function render(opts) {
    var el = document.getElementById('studioHeader');
    if (!el) return;
    var h = opts.logoHeight || 70;
    var live = opts.projectLive ? ' is-live' : '';
    el.innerHTML = ''
      + '<header class="sh-bar">'
      + '<a class="sh-logo" href="/app-2026v4.php">'
      + '<img src="' + esc(opts.logoSrc || '/logo-brand.svg') + '"'
      + ' alt="' + esc(opts.logoAlt || 'ReliCheck') + '"'
      + ' style="height:' + h + 'px;width:auto">'
      + '</a>'
      + '<div class="sh-ctx' + live + '" id="shCtx">'
      + '<span class="sh-dot"></span>'
      + '<span id="shCtxLabel">' + esc(opts.projectLabel || 'No project') + '</span>'
      + '</div>'
      + '<div class="sh-spacer"></div>'
      + '<a class="sh-nav" href="' + esc(opts.projectsUrl || '#') + '">All projects</a>'
      + '<div class="sh-ibw"><button class="sh-ibtn" id="shSiriBtn">&#9889; SIRI</button>'
      +   '<div class="sh-pop" id="shSiriPop" hidden></div></div>'
      + '<div class="sh-ibw"><button class="sh-ibtn" id="shRssiBtn">&#9711; RSSI</button>'
      +   '<div class="sh-pop" id="shRssiPop" hidden></div></div>'
      + '<div class="sh-rssi" id="tbRssi" hidden></div>'
      + '<div class="sh-aw">'
      +   '<button class="sh-avatar" id="shUserBtn" aria-haspopup="menu" aria-expanded="false" title="' + esc(opts.userFull || '') + '">' + esc(opts.initials || '') + '</button>'
      +   '<div class="sh-menu" id="shUserMenu" role="menu">'
      +     (opts.userFull || opts.initials
            ? '<div class="sh-menu-profile"><div class="sh-menu-av">' + esc(opts.initials || '') + '</div><span class="sh-menu-name">' + esc(opts.userFull || opts.initials || '') + '</span></div><div class="sh-menu-div"></div>'
            : '')
      +     '<a class="sh-menu-item" href="/account.php" role="menuitem">My account</a>'
      +     '<a class="sh-menu-item" href="/projects.php" role="menuitem">Projects</a>'
      +     '<div class="sh-menu-div"></div>'
      +     '<a class="sh-menu-item" href="#" role="menuitem" id="shSignOut">Sign out</a>'
      +   '</div>'
      + '</div>'
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
      initDropdown();
      initIndexBtns();
    },

    setProject: function (label, live) {
      var lbl = document.getElementById('shCtxLabel');
      var ctx = document.getElementById('shCtx');
      if (lbl) lbl.textContent = label || 'No project';
      if (ctx) ctx.classList.toggle('is-live', !!live);
    },

    setSiriInfo: function (data) {
      _siri = data;
      var pop = document.getElementById('shSiriPop');
      if (pop && !pop.hidden) { pop.innerHTML = siriHtml(); }
    },

    setRssiInfo: function (data) {
      _rssi = data;
      var pop = document.getElementById('shRssiPop');
      if (pop && !pop.hidden) { pop.innerHTML = rssiHtml(); }
    },

    // Fetch RSSI data from the server then show the badge.
    loadRssiStub: function (surveyPid) {
      var wrap = document.getElementById('tbRssi');
      var self = this;
      if (!wrap || !surveyPid) return;
      fetch('/api/dev/rssi-check.php?project_id=' + encodeURIComponent(surveyPid), {
        credentials: 'same-origin', headers: { Accept: 'application/json' }
      })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        populateBadge(d);
        if (d && d.ok) self.setRssiInfo(d);
      })
      .catch(function () { wrap.hidden = true; });
    },

    // Populate the badge from already-known data (e.g. MM Studio's BOOT.scores).
    setRssiStub: function (data) {
      populateBadge(data);
      if (data) this.setRssiInfo(data);
    }
  };

  // Alias for platform-shell pages (360, TIA) until they adopt full StudioHeader.
  window.loadRssiStub = function (pid) { window.StudioHeader.loadRssiStub(pid); };

})();
