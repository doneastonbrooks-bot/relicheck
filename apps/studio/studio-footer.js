// Uniform studio footer plug-in — shared across all ReliCheck analysis studios.
// Renders into <div id="studioFooter"></div>.
//
// StudioFooter.init(opts)
//   onSiri   — click handler for "Open from SIRI"
//   onUpload — click handler for "Upload data"
//   onSaved  — click handler for "Open saved project"
//
// StudioFooter.showChip(text) — show the loaded-dataset chip (right side)
// StudioFooter.hideChip()     — hide the chip

(function () {
  'use strict';

  var CSS = ''
    + '.sf-dock{position:relative;display:flex;align-items:center;'
    +   'padding:12px 22px;'
    +   'background:rgba(255,255,255,.92);'
    +   'backdrop-filter:saturate(1.4) blur(12px);'
    +   '-webkit-backdrop-filter:saturate(1.4) blur(12px);'
    +   'border-top:1px solid var(--line,#e6e8ec);'
    +   'box-shadow:0 -4px 22px rgba(15,23,42,.07)}'
    + '.sf-logo{position:absolute;left:22px;top:50%;transform:translateY(-50%);'
    +   'display:inline-flex;align-items:center;text-decoration:none}'
    + '.sf-logo img{height:24px;width:auto;display:block}'
    + '@media(max-width:820px){.sf-logo{display:none}}'
    + '.sf-inner{display:flex;align-items:center;justify-content:center;'
    +   'gap:12px;flex-wrap:wrap;min-height:34px;width:100%}'
    + '.sf-lbl{font-size:12px;font-weight:700;text-transform:uppercase;'
    +   'letter-spacing:.04em;color:var(--ink-3,#8a8f98)}'
    + '.sf-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;'
    +   'border-radius:10px;border:1px solid var(--line,#e6e8ec);'
    +   'background:#fff;color:var(--ink,#15171a);font-family:inherit;'
    +   'font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;'
    +   'transition:border-color .13s}'
    + '.sf-btn:hover{border-color:var(--acc,#6b7280)}'
    + '.sf-chip{position:absolute;right:22px;top:50%;transform:translateY(-50%);'
    +   'display:inline-flex;align-items:center;gap:8px;'
    +   'font-size:13px;color:var(--ink-2,#5f6368)}'
    + '.sf-chip[hidden]{display:none}'
    + '@media(max-width:980px){.sf-chip{position:static;transform:none;margin-left:auto}}'
    + '.sf-gdot{width:8px;height:8px;border-radius:50%;'
    +   'background:var(--green,#1f9e44);flex:none}';

  var cssInjected = false;
  function injectCss() {
    if (cssInjected) return; cssInjected = true;
    var el = document.createElement('style');
    el.id = 'studio-footer-css';
    el.textContent = CSS;
    document.head.appendChild(el);
  }

  function render(opts) {
    var el = document.getElementById('studioFooter');
    if (!el) return;
    el.innerHTML = ''
      + '<footer class="sf-dock" role="region" aria-label="Data">'
      + '<a class="sf-logo" href="/app-2026v4.php" aria-label="ReliCheck home">'
      + '<img src="/logo-brand.svg" alt="ReliCheck"></a>'
      + '<div class="sf-inner">'
      + '<span class="sf-lbl">Data</span>'
      + '<button class="sf-btn" id="sfSiri">&#9889; Open from SIRI</button>'
      + '<button class="sf-btn" id="sfUpload">&#8681; Upload data</button>'
      + '<button class="sf-btn" id="sfSaved">&#9638; Open saved project</button>'
      + '</div>'
      + '<span class="sf-chip" id="sfChip" hidden>'
      + '<span class="sf-gdot"></span><span id="sfChipText"></span></span>'
      + '</footer>';

    var si = document.getElementById('sfSiri');
    var up = document.getElementById('sfUpload');
    var sv = document.getElementById('sfSaved');
    if (si && opts.onSiri)   si.addEventListener('click', opts.onSiri);
    if (up && opts.onUpload) up.addEventListener('click', opts.onUpload);
    if (sv && opts.onSaved)  sv.addEventListener('click', opts.onSaved);
  }

  window.StudioFooter = {
    init: function (opts) {
      injectCss();
      render(opts || {});
    },
    showChip: function (text) {
      var c = document.getElementById('sfChip');
      var t = document.getElementById('sfChipText');
      if (c && t) { t.textContent = text; c.hidden = false; }
    },
    hideChip: function () {
      var c = document.getElementById('sfChip');
      if (c) c.hidden = true;
    }
  };

})();
