// Uniform studio footer plug-in — shared across all ReliCheck analysis studios.
// Renders into <div id="studioFooter"></div>.
//
// Layout:  [ReliCheck logo — left]  [SIRI btn]  [RSSI btn]  [N rows · N vars — right]
//
// StudioFooter.init()             — render footer; no callbacks needed
// StudioFooter.setSiriInfo(data)  — populate SIRI popup
//   data: { score, band, link }
// StudioFooter.setRssiInfo(data)  — populate RSSI popup
//   data: { score, pct, band, withheld, tier, link, has_rssi }
// StudioFooter.setDataInfo(rows, vars)  — update right-corner dataset count
// StudioFooter.showChip(text)     — legacy alias: text = "N rows · N vars"
// StudioFooter.hideChip()         — hide the data count

(function () {
  'use strict';

  var CSS = ''
    // Dock
    + '.sf-dock{position:relative;z-index:10;display:flex;align-items:center;justify-content:center;'
    +   'gap:12px;padding:10px 22px;'
    +   'background:rgba(255,255,255,.95);'
    +   'backdrop-filter:saturate(1.4) blur(12px);-webkit-backdrop-filter:saturate(1.4) blur(12px);'
    +   'border-top:1px solid var(--line,#e6e8ec);'
    +   'box-shadow:0 -4px 22px rgba(15,23,42,.07)}'
    // Logo — left absolute anchor
    + '.sf-logo{position:absolute;left:22px;top:50%;transform:translateY(-50%);'
    +   'display:inline-flex;align-items:center;text-decoration:none}'
    + '.sf-logo img{height:22px;width:auto;display:block}'
    + '@media(max-width:820px){.sf-logo{display:none}}'
    // Buttons
    + '.sf-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;'
    +   'border-radius:10px;border:1px solid var(--line,#e6e8ec);'
    +   'background:#fff;color:var(--ink,#15171a);font-family:inherit;'
    +   'font-size:13px;font-weight:600;cursor:pointer;'
    +   'transition:border-color .13s,background .13s}'
    + '.sf-btn:hover{border-color:var(--acc,#6b7280)}'
    + '.sf-btn.sf-on{background:var(--acc-soft,#eef0f3);color:var(--acc-deep,#2a2f3a);border-color:transparent}'
    // Data info — right absolute anchor
    + '.sf-data{position:absolute;right:22px;top:50%;transform:translateY(-50%);'
    +   'display:inline-flex;align-items:center;gap:7px;'
    +   'font-size:12.5px;font-weight:600;color:var(--ink-2,#5f6368)}'
    + '.sf-data .sf-gdot{width:8px;height:8px;border-radius:50%;background:var(--green,#1f9e44);flex:none}'
    + '.sf-data[hidden]{display:none}'
    + '@media(max-width:980px){.sf-data{display:none}}'
    // Popup card
    + '.sf-pop{position:absolute;bottom:calc(100% + 10px);left:50%;transform:translateX(-50%);'
    +   'width:256px;background:var(--panel,#fff);'
    +   'border:1px solid var(--line,#e6e8ec);border-radius:14px;'
    +   'box-shadow:0 4px 28px rgba(15,23,42,.14);overflow:hidden;z-index:60}'
    + '.sf-pop[hidden]{display:none}'
    + '.sfp-head{display:flex;align-items:center;padding:11px 14px;'
    +   'border-bottom:1px solid var(--line,#e6e8ec)}'
    + '.sfp-title{flex:1;font-size:13px;font-weight:700;color:var(--ink,#15171a)}'
    + '.sfp-x{border:none;background:none;color:var(--ink-3,#8a8f98);'
    +   'font-size:20px;line-height:1;cursor:pointer;padding:0 0 0 8px}'
    + '.sfp-body{padding:14px}'
    + '.sfp-score{font-size:30px;font-weight:800;color:var(--ink,#15171a);line-height:1}'
    + '.sfp-max{font-size:13px;color:var(--ink-3,#8a8f98);margin-left:3px}'
    + '.sfp-band{font-size:12px;color:var(--ink-2,#5f6368);margin-top:3px;margin-bottom:10px}'
    + '.sfp-empty{font-size:13px;color:var(--ink-2,#5f6368);margin-bottom:10px;line-height:1.5}'
    + '.sfp-link{display:inline-flex;align-items:center;font-size:13px;font-weight:700;'
    +   'color:var(--acc,#6b7280);text-decoration:none}'
    + '.sfp-link:hover{text-decoration:underline}';

  var cssInjected = false;
  function injectCss() {
    if (cssInjected) return; cssInjected = true;
    var el = document.createElement('style');
    el.id = 'studio-footer-css';
    el.textContent = CSS;
    document.head.appendChild(el);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  var _siri = null;
  var _rssi = null;

  function siriHtml() {
    var d = _siri;
    var body;
    if (!d || d.score == null) {
      body = '<p class="sfp-empty">No SIRI score yet. Assess your instrument before launch.</p>'
        + '<a class="sfp-link" href="/develop.php?db=1&start=choose">Go to SIRI &#8594;</a>';
    } else {
      body = '<div><span class="sfp-score">' + d.score.toFixed(1) + '</span>'
        + '<span class="sfp-max">/ 100</span></div>'
        + (d.band ? '<div class="sfp-band">' + esc(d.band) + '</div>' : '<div style="margin-bottom:10px"></div>')
        + '<a class="sfp-link" href="' + esc(d.link || '/develop.php?db=1&start=choose') + '">View in SIRI &#8594;</a>';
    }
    return '<div class="sfp-head"><span class="sfp-title">SIRI Readiness</span>'
      + '<button class="sfp-x" data-pop="sfSiriPop">&#215;</button></div>'
      + '<div class="sfp-body">' + body + '</div>';
  }

  function rssiHtml() {
    var d = _rssi;
    var body;
    if (!d || !d.has_rssi) {
      body = '<p class="sfp-empty">No RSSI score yet. Run RSSI after collecting responses.</p>'
        + '<a class="sfp-link" href="/rssi.php">Go to RSSI &#8594;</a>';
    } else if (d.withheld) {
      body = '<p class="sfp-empty">Insufficient data to score.</p>'
        + (d.band ? '<div class="sfp-band">' + esc(d.band) + '</div>' : '')
        + '<a class="sfp-link" href="' + esc(d.link || '/rssi.php') + '" target="_blank">View in RSSI &#8594;</a>';
    } else {
      body = '<div><span class="sfp-score">' + (d.pct != null ? d.pct : '—') + '</span>'
        + '<span class="sfp-max">/ 100</span></div>'
        + (d.band ? '<div class="sfp-band">' + esc(d.band) + '</div>' : '<div style="margin-bottom:10px"></div>')
        + '<a class="sfp-link" href="' + esc(d.link || '/rssi.php') + '" target="_blank">View in RSSI &#8594;</a>';
    }
    return '<div class="sfp-head"><span class="sfp-title">Survey Strength &middot; RSSI</span>'
      + '<button class="sfp-x" data-pop="sfRssiPop">&#215;</button></div>'
      + '<div class="sfp-body">' + body + '</div>';
  }

  function closeAll() {
    var sp = document.getElementById('sfSiriPop');
    var rp = document.getElementById('sfRssiPop');
    if (sp) sp.hidden = true;
    if (rp) rp.hidden = true;
    document.querySelectorAll('.sf-btn').forEach(function (b) { b.classList.remove('sf-on'); });
  }

  function togglePop(popId, btnId, htmlFn) {
    var pop = document.getElementById(popId);
    var btn = document.getElementById(btnId);
    if (!pop || !btn) return;
    var wasHidden = pop.hidden;
    closeAll();
    if (wasHidden) {
      pop.innerHTML = htmlFn();
      pop.hidden = false;
      btn.classList.add('sf-on');
      // Wire close ×
      pop.querySelectorAll('.sfp-x').forEach(function (x) {
        x.addEventListener('click', closeAll);
      });
    }
  }

  function render() {
    var el = document.getElementById('studioFooter');
    if (!el) return;
    el.innerHTML = ''
      + '<footer class="sf-dock" role="region" aria-label="Studio">'
      + '<a class="sf-logo" href="/app-2026v4.php" aria-label="ReliCheck home">'
      + '<img src="/logo-brand.svg" alt="ReliCheck"></a>'
      + '<button class="sf-btn" id="sfSiriBtn">&#9889; SIRI</button>'
      + '<button class="sf-btn" id="sfRssiBtn">&#9711; RSSI</button>'
      + '<div class="sf-data" id="sfData" hidden>'
      + '<span class="sf-gdot"></span><span id="sfDataTxt"></span></div>'
      + '<div class="sf-pop" id="sfSiriPop" hidden></div>'
      + '<div class="sf-pop" id="sfRssiPop" hidden></div>'
      + '</footer>';

    document.getElementById('sfSiriBtn').addEventListener('click', function () {
      togglePop('sfSiriPop', 'sfSiriBtn', siriHtml);
    });
    document.getElementById('sfRssiBtn').addEventListener('click', function () {
      togglePop('sfRssiPop', 'sfRssiBtn', rssiHtml);
    });

    // Close on outside click
    document.addEventListener('click', function (e) {
      var dock = el.querySelector('.sf-dock');
      if (dock && !dock.contains(e.target)) closeAll();
    });
  }

  window.StudioFooter = {
    init: function () {
      injectCss();
      render();
    },

    setSiriInfo: function (data) {
      _siri = data;
      var pop = document.getElementById('sfSiriPop');
      if (pop && !pop.hidden) { pop.innerHTML = siriHtml(); }
    },

    setRssiInfo: function (data) {
      _rssi = data;
      var pop = document.getElementById('sfRssiPop');
      if (pop && !pop.hidden) { pop.innerHTML = rssiHtml(); }
    },

    setDataInfo: function (rows, vars) {
      var el = document.getElementById('sfData');
      var tx = document.getElementById('sfDataTxt');
      if (el && tx) { tx.textContent = rows + ' rows · ' + vars + ' variables'; el.hidden = false; }
    },

    // Legacy alias: showChip('45 rows · 8 variables')
    showChip: function (text) {
      var el = document.getElementById('sfData');
      var tx = document.getElementById('sfDataTxt');
      if (el && tx) { tx.textContent = text; el.hidden = false; }
    },

    hideChip: function () {
      var el = document.getElementById('sfData');
      if (el) el.hidden = true;
    }
  };

})();
