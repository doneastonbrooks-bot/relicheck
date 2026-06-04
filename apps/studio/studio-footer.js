// Uniform studio footer plug-in — shared across all ReliCheck analysis studios.
// Renders into <div id="studioFooter"></div>.
//
// Layout:  [ReliCheck logo — left]  [N rows · N vars — right]
//
// StudioFooter.init()                   — render footer; no callbacks needed
// StudioFooter.setDataInfo(rows, vars)  — update right-corner dataset count
// StudioFooter.showChip(text)           — legacy alias: text = "N rows · N vars"
// StudioFooter.hideChip()               — hide the data count
// StudioFooter.setSiriInfo(data)        — delegate to StudioHeader
// StudioFooter.setRssiInfo(data)        — delegate to StudioHeader

(function () {
  'use strict';

  var CSS = ''
    // Dock
    + '.sf-dock{position:relative;z-index:10;display:flex;align-items:center;justify-content:center;'
    +   'gap:12px;padding:14px 22px;'
    +   'background:rgba(255,255,255,.95);'
    +   'backdrop-filter:saturate(1.4) blur(12px);-webkit-backdrop-filter:saturate(1.4) blur(12px);'
    +   'border-top:1px solid var(--line,#e6e8ec);'
    +   'box-shadow:0 -4px 22px rgba(15,23,42,.07)}'
    // Left group — logo + divider + tagline
    + '.sf-left{display:inline-flex;align-items:center;gap:0}'
    + '.sf-logo{display:inline-flex;align-items:center;text-decoration:none}'
    + '.sf-logo img{height:28px;width:auto;display:block}'
    + '.sf-divider{display:inline-block;width:1px;height:18px;background:var(--line,#e6e8ec);margin:0 14px;flex:none}'
    + '.sf-tagline{font-family:"Fraunces",Georgia,serif;font-style:italic;font-weight:700;'
    +   'font-size:12px;color:#c85c3a;line-height:1;white-space:nowrap}'
    + '@media(max-width:820px){.sf-left{display:none}}'
    // Data info — right absolute anchor
    + '.sf-data{position:absolute;right:22px;top:50%;transform:translateY(-50%);'
    +   'display:inline-flex;align-items:center;gap:7px;'
    +   'font-size:12.5px;font-weight:600;color:var(--ink-2,#5f6368)}'
    + '.sf-data .sf-gdot{width:8px;height:8px;border-radius:50%;background:var(--green,#1f9e44);flex:none}'
    + '.sf-data[hidden]{display:none}'
    + '@media(max-width:980px){.sf-data{display:none}}';

  var cssInjected = false;
  function injectCss() {
    if (cssInjected) return; cssInjected = true;
    var el = document.createElement('style');
    el.id = 'studio-footer-css';
    el.textContent = CSS;
    document.head.appendChild(el);
    if (!document.querySelector('link[href*="Fraunces"]')) {
      var f = document.createElement('link');
      f.rel = 'stylesheet';
      f.href = 'https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@1,700&display=swap';
      document.head.appendChild(f);
    }
  }

  function render() {
    var el = document.getElementById('studioFooter');
    if (!el) return;
    el.innerHTML = ''
      + '<footer class="sf-dock" role="region" aria-label="Studio">'
      + '<div class="sf-left">'
      + '<a class="sf-logo" href="/app-2026v4.php" aria-label="ReliCheck home">'
      + '<img src="/logo-brand.svg" alt="ReliCheck"></a>'
      + '<span class="sf-divider"></span>'
      + '<span class="sf-tagline">something matters</span>'
      + '</div>'
      + '<div class="sf-data" id="sfData" hidden>'
      + '<span class="sf-gdot"></span><span id="sfDataTxt"></span></div>'
      + '</footer>';
  }

  window.StudioFooter = {
    init: function () {
      injectCss();
      render();
    },

    setSiriInfo: function (data) {
      if (window.StudioHeader && window.StudioHeader.setSiriInfo) window.StudioHeader.setSiriInfo(data);
    },

    setRssiInfo: function (data) {
      if (window.StudioHeader && window.StudioHeader.setRssiInfo) window.StudioHeader.setRssiInfo(data);
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
